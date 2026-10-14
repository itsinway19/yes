<?php

namespace App\Security;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Transport ordering only. Not registered on production authentication routes in P2-A. */
final class IdentityTransition
{
    public function create(string $boundary): string
    {
        $this->validateBoundary($boundary);
        $lineage = (string) Str::uuid();
        DB::connection('pgsql_authority')->table('browser_identity_transitions')->insert([
            'lineage_id' => $lineage, 'boundary' => $boundary,
        ]);

        return $lineage;
    }

    /** Authentication runs only after row ownership and the precondition are established. */
    public function login(Request $request, string $lineage, string $boundary, int $baseEpoch, string $nonce, Closure $authenticate, string|Closure $method = 'password', ?Closure $lockedPrecondition = null, ?Closure $afterIssue = null): array
    {
        return $this->publish($request, $lineage, $boundary, $baseEpoch, $nonce, 'login', function ($previous) use ($request, $authenticate, $method, $afterIssue): void {
            $oldId = $request->session()->getId();
            $authenticate();
            $user = $request->user();
            if (! $user instanceof User || hash_equals($oldId, $request->session()->getId())) {
                throw new AuthorizationException('A verified, regenerated authentication session is required.');
            }
            app(SessionAuthority::class)->issue($request, $user, $method instanceof Closure ? $method() : $method,
                DB::connection('pgsql_authority'));
            $this->revokePrevious($previous);
            if ($afterIssue) {
                $afterIssue();
            }
        }, $lockedPrecondition);
    }

    /** Rebind existing authority without issuing authentication or extending any deadline. */
    public function rebind(Request $request, string $lineage, string $boundary, int $baseEpoch, string $nonce, ?Closure $lockedPrecondition = null): array
    {
        return $this->publish($request, $lineage, $boundary, $baseEpoch, $nonce, 'rebind', function ($previous) use ($request): void {
            $this->assertBinding($request, $previous);
            $authority = DB::connection('pgsql_authority');
            $session = $authority->table('security_sessions')->where('session_key_hash', $previous->current_session_key_hash)
                ->where('user_id', $previous->principal_id)->lockForUpdate()->first();
            $this->validateSecurity($request, $session, $previous->boundary);
            $request->session()->regenerate(true);
            $authority->table('security_sessions')->where('id', $session->id)
                ->update(['session_key_hash' => SessionAuthority::sessionKey($request)]);
        }, $lockedPrecondition);
    }

    /** The caller must already have established the intended terminal operation. */
    public function close(Request $request, string $lineage, string $boundary, int $baseEpoch, string $nonce, string $kind = 'logout', ?Closure $lockedPrecondition = null): array
    {
        if (! in_array($kind, ['logout', 'expired'], true)) {
            throw new LogicException('Unsupported terminal transition.');
        }

        return $this->publish($request, $lineage, $boundary, $baseEpoch, $nonce, $kind, function ($previous) use ($request, $boundary): void {
            $this->assertBinding($request, $previous);
            $this->revokePrevious($previous);
            Auth::guard($boundary === 'internal' ? 'internal' : 'web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }, $lockedPrecondition);
    }

    /** Last committed nonce only. Null is unresolved/superseded, never permission to replay. */
    public function resolve(string $lineage, string $boundary, string $nonce): ?array
    {
        $this->validateInput($lineage, $boundary, 0, $nonce);
        $row = DB::connection('pgsql')->table('browser_identity_transitions')
            ->where('lineage_id', $lineage)->where('boundary', $boundary)->first();

        return $row && $row->last_nonce === $nonce ? $this->receipt($row) : null;
    }

    /** Admission deliberately uses the committed runtime connection, not the publishing transaction. */
    public function admit(Request $request, string $lineage, string $boundary): void
    {
        $this->validateInput($lineage, $boundary, 0, str_repeat('0', 64));
        $row = DB::connection('pgsql')->table('browser_identity_transitions')
            ->where('lineage_id', $lineage)->where('boundary', $boundary)->first();
        $this->assertBinding($request, $row);
        DB::connection('pgsql')->transaction(function () use ($request, $boundary): void {
            $user = $request->user();
            $session = DB::table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->first();
            $this->validateSecurity($request, $session, $boundary);
            $originalProbe = $request->attributes->get('builderad.visibility_probe');
            $request->attributes->set('builderad.visibility_probe', true);
            try {
                if ($session->auth_method === 'staff_enrollment') {
                    app(SessionAuthority::class)->assertStaffEnrollment($request, $user);
                } else {
                    app(SessionAuthority::class)->assertCurrent($request, $user, (int) $session->lifecycle_generation);
                }
            } finally {
                if ($originalProbe === null) {
                    $request->attributes->remove('builderad.visibility_probe');
                } else {
                    $request->attributes->set('builderad.visibility_probe', $originalProbe);
                }
            }
        });
    }

    private function publish(Request $request, string $lineage, string $boundary, int $baseEpoch, string $nonce, string $kind, Closure $prepare, ?Closure $lockedPrecondition = null): array
    {
        $this->validateInput($lineage, $boundary, $baseEpoch, $nonce);
        if (($request->is('internal/*') ? 'internal' : 'advertiser') !== $boundary) {
            throw new IdentityTransitionConflict;
        }
        $handler = $request->session()->getHandler();
        if (! $handler instanceof CacheBasedSessionHandler || ! $handler->getCache()->getStore() instanceof RedisStore) {
            throw new LogicException('Identity publication requires a durable Redis session store.');
        }
        $authority = DB::connection('pgsql_authority');
        if ($authority->transactionLevel() !== 0 || DB::connection('pgsql')->transactionLevel() !== 0) {
            throw new LogicException('Identity publication owns its outer authority transaction.');
        }
        $committing = false;
        $authority->beginTransaction();
        try {
            $row = $authority->table('browser_identity_transitions')->where('lineage_id', $lineage)->lockForUpdate()->first();
            if (! $row || $row->boundary !== $boundary) {
                throw new IdentityTransitionConflict;
            }
            if ($row->last_nonce === $nonce && (int) $row->epoch === $baseEpoch + 1 && $row->kind === $kind) {
                $authority->rollBack();
                $this->mark($request, 'replayed', true);

                return $this->receipt($row);
            }
            if ((int) $row->epoch !== $baseEpoch || $row->last_nonce === $nonce || $baseEpoch === PHP_INT_MAX) {
                throw new IdentityTransitionConflict;
            }
            if ($authority->table('browser_identity_transitions')->where('last_nonce', $nonce)->exists()) {
                throw new IdentityTransitionConflict;
            }
            if ($row->status === 'active' && ! $authority->table('security_sessions')
                ->where('session_key_hash', $row->current_session_key_hash)->where('user_id', $row->principal_id)->exists()) {
                throw new IdentityTransitionConflict;
            }
            // Production integration must revalidate its signed captured input under
            // this actual row lock, before guard events/rehashing/session mutations.
            if ($lockedPrecondition) {
                $lockedPrecondition($row);
            }
            $prepare($row);
            $active = in_array($kind, ['login', 'rebind'], true);
            if ($active) {
                $session = $authority->table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->first();
                $this->validateSecurity($request, $session, $boundary);
            }
            $epoch = $baseEpoch + 1;
            $request->session()->put(['builderad.identity_lineage' => $lineage, 'builderad.identity_epoch' => $epoch]);
            if ($active) {
                $request->session()->put('builderad.identity_cookie_lease', time() + (int) config('session.lifetime') * 60);
            }
            $request->session()->save(); // S1; still no committed transport/SQL authority.
            if ($request->session()->getHandler()->read($request->session()->getId()) === '') {
                throw new LogicException('Prepared session was not persisted.');
            }
            event(new IdentitySessionPrepared($lineage, $boundary, $epoch, $nonce));
            $changes = [
                'epoch' => $epoch, 'current_session_key_hash' => $active ? SessionAuthority::sessionKey($request) : null,
                'principal_id' => $active ? $request->user()->getAuthIdentifier() : null,
                'status' => $active ? 'active' : 'closed', 'kind' => $kind, 'last_nonce' => $nonce,
                'updated_at' => now(),
            ];
            $authority->table('browser_identity_transitions')->where('lineage_id', $lineage)->update($changes);
            $committing = true;
            $authority->commit(); // S2. Cookie delivery (S3) is owned by later integration.
            $receipt = $this->receipt((object) array_merge((array) $row, $changes));
            $this->mark($request, 'committed', $receipt);
            $this->mark($request, 'output', [
                'credential' => SessionAuthority::sessionKey($request), 'epoch' => $epoch,
                'csrf' => $request->session()->token(), 'lineage' => $lineage,
            ]);

            return $receipt;
        } catch (Throwable $error) {
            if (! $committing) {
                $authority->rollBack();
                $this->mark($request, 'failed', true);
                throw $error;
            }
            // Never retry authentication or compensate a possibly committed epoch.
            $this->mark($request, 'uncertain', true);
            DB::purge('pgsql_authority');
            throw new IdentityTransitionUnknown($lineage, $nonce, $error);
        }
    }

    private function mark(Request $request, string $name, mixed $value): void
    {
        $request->attributes->set('builderad.identity_'.$name, $value);
        if ($state = $request->attributes->get('builderad.identity_lifecycle')) {
            $state->{$name} = $value;
        }
    }

    private function revokePrevious(object $previous): void
    {
        if ($previous->status !== 'active') {
            return;
        }
        $authority = DB::connection('pgsql_authority');
        // Same conditional revocation and transactional row-update semantics as revokeCurrent.
        $changed = $authority->table('security_sessions')->where('session_key_hash', $previous->current_session_key_hash)
            ->where('user_id', $previous->principal_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($changed) {
            $generation = $authority->table('principal_lifecycles')->where('user_id', $previous->principal_id)->value('generation');
            $authority->table('security_audit_events')->insert([
                'actor_user_id' => $previous->principal_id, 'subject_user_id' => $previous->principal_id,
                'event' => 'session_revoked', 'reason' => 'identity_transition', 'prior_status' => 'active',
                'new_status' => 'revoked', 'prior_generation' => $generation, 'new_generation' => $generation,
                'occurred_at' => now(),
            ]);
        }
    }

    private function assertBinding(Request $request, ?object $row): void
    {
        if (! $row || $row->status !== 'active' || ! $request->user()
            || ($request->is('internal/*') ? 'internal' : 'advertiser') !== $row->boundary
            || (int) $row->principal_id !== (int) $request->user()->getAuthIdentifier()
            || ! hash_equals($row->current_session_key_hash, SessionAuthority::sessionKey($request))
            || $request->session()->get('builderad.identity_lineage') !== $row->lineage_id
            || $request->session()->get('builderad.identity_epoch') !== (int) $row->epoch) {
            throw new IdentityTransitionConflict;
        }
    }

    private function validateSecurity(Request $request, ?object $session, string $boundary): void
    {
        $user = $request->user();
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user?->getAuthIdentifier())->first();
        $enrollment = $boundary === 'advertiser' && $session?->auth_method === 'staff_enrollment';
        // Registration already owns a canonical pending advertiser session for
        // verification. Admission of that session grants no current-principal
        // business authority: existing route/lifecycle guards remain mandatory.
        $verification = $boundary === 'advertiser' && $lifecycle?->principal_class === 'advertiser'
            && $lifecycle->status === 'pending' && $session?->auth_method === 'password'
            && $user && ! $user->hasVerifiedEmail();
        // Preserve the canonical staff eligibility denial before the existing
        // current-session proof can classify it as an expired credential.
        if ($boundary === 'internal' && $user && ! DB::connection('pgsql_authority')
            ->table('internal_personnel')->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')->exists()) {
            throw new AuthorizationException('Current staff eligibility is required.');
        }
        if (! $user || ! $session || $session->revoked_at || ! $lifecycle
            || (int) $session->user_id !== (int) $user->getAuthIdentifier()
            || (int) $session->lifecycle_generation !== (int) $lifecycle->generation
            || (int) $request->session()->get('a001_generation', -1) !== (int) $lifecycle->generation
            || $lifecycle->status !== ($enrollment || $verification ? 'pending' : 'current')
            || $lifecycle->principal_class !== ($boundary === 'internal' || $enrollment ? 'staff' : 'advertiser')
            || ($boundary === 'internal' ? $session->auth_method !== 'internal_passkey' : $session->auth_method === 'internal_passkey')
            || now()->greaterThanOrEqualTo($session->idle_expires_at) || now()->greaterThanOrEqualTo($session->absolute_expires_at)) {
            throw new AuthorizationException('Current canonical session authority is required.');
        }
    }

    private function receipt(object $row): array
    {
        return ['lineage' => $row->lineage_id, 'boundary' => $row->boundary, 'epoch' => (int) $row->epoch,
            'nonce' => $row->last_nonce, 'kind' => $row->kind, 'status' => $row->status];
    }

    private function validateBoundary(string $boundary): void
    {
        if (! in_array($boundary, ['advertiser', 'internal'], true)) {
            throw new IdentityTransitionConflict;
        }
    }

    private function validateInput(string $lineage, string $boundary, int $epoch, string $nonce): void
    {
        $this->validateBoundary($boundary);
        if (! Str::isUuid($lineage) || $epoch < 0 || ! preg_match('/^[0-9a-f]{64}$/D', $nonce)) {
            throw new IdentityTransitionConflict;
        }
    }
}
