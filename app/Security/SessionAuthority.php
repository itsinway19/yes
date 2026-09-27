<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SessionAuthority
{
    public function issue(Request $request, User $user, string $method = 'password'): void
    {
        $lifecycle = DB::table('principal_lifecycles')->where('user_id', $user->id)->first();
        if (! $lifecycle || ! in_array($lifecycle->status, ['pending', 'current'], true)) {
            throw new AuthorizationException('Account lifecycle is unavailable.');
        }

        $staff = $lifecycle->principal_class === 'staff';
        if ($staff) {
            $personnel = DB::table('internal_personnel')->where('user_id', $user->id)->first();
            if ($method === 'staff_enrollment' && $lifecycle->status === 'pending' &&
                $personnel?->status === 'pending') {
                DB::connection('pgsql_authority')->transaction(fn () => $this->insertSession(
                    $request, $user, (int) $lifecycle->generation, $method, 15, 1,
                ));

                return;
            }
            $minimumFactors = $personnel?->kind === 'founder' ? 2 : 1;
            if ($method !== 'internal_passkey' || ! $personnel || $personnel->status !== 'active' ||
                ($personnel->kind === 'founder' && ! $personnel->factor_independence_attested_at) ||
                $lifecycle->status !== 'current' || $user->passkeys()->count() < $minimumFactors) {
                throw new AuthorizationException('Staff passkey eligibility is required.');
            }
        } elseif ($method === 'internal_passkey') {
            throw new AuthorizationException('Internal authentication requires active personnel.');
        }

        DB::connection('pgsql_authority')->transaction(fn () => $this->insertSession(
            $request, $user, (int) $lifecycle->generation, $method, $staff ? 15 : 60, $staff ? 8 : 24,
        ));
    }

    private function insertSession(Request $request, User $user, int $generation, string $method, int $idleMinutes, int $absoluteHours): void
    {
        $now = now();
        DB::connection('pgsql_authority')->table('security_sessions')->insert([
            'user_id' => $user->id,
            'session_key_hash' => self::sessionKey($request),
            'lifecycle_generation' => $generation,
            'auth_method' => $method,
            'client_description' => mb_substr((string) $request->userAgent(), 0, 255),
            'authenticated_at' => $now,
            'last_seen_at' => $now,
            'idle_expires_at' => $now->copy()->addMinutes($idleMinutes),
            'absolute_expires_at' => $now->copy()->addHours($absoluteHours),
        ]);
        $request->session()->put('a001_generation', $generation);
        $this->audit($user, $user->id, 'session_issued', $method, null, 'active');
    }

    public function assertStaffEnrollment(Request $request, User $user): void
    {
        $connection = DB::connection('pgsql_authority')->transactionLevel() > 0
            ? DB::connection('pgsql_authority') : DB::connection();
        $lifecycle = $connection->table('principal_lifecycles')->where('user_id', $user->id)
            ->where('principal_class', 'staff')->where('status', 'pending')->sharedLock()->first();
        $personnel = $connection->table('internal_personnel')->where('user_id', $user->id)
            ->where('status', 'pending')->sharedLock()->first();
        if (! $lifecycle || ! $personnel ||
            (int) $request->session()->get('a001_generation', -1) !== (int) $lifecycle->generation) {
            throw new AuthorizationException('Pending staff enrollment is required.');
        }
        $session = $connection->table('security_sessions')
            ->where('session_key_hash', self::sessionKey($request))
            ->where('user_id', $user->id)
            ->where('auth_method', 'staff_enrollment')
            ->where('lifecycle_generation', $lifecycle->generation)
            ->sharedLock()->first();
        if (! $session || $session->revoked_at ||
            Carbon::parse($session->idle_expires_at)->lessThanOrEqualTo(now()) ||
            Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo(now())) {
            throw new AuthorizationException('Staff enrollment session expired.');
        }
        $this->touchAfterCommit((int) $session->id, (int) $lifecycle->generation, 15, now());
    }

    public function assertCurrent(Request $request, User $user, int $generation): void
    {
        $session = DB::table('security_sessions')
            ->where('session_key_hash', self::sessionKey($request))
            ->where('user_id', $user->id)
            ->sharedLock()
            ->first();
        $now = now();
        if (! $session || $session->revoked_at ||
            (int) $session->lifecycle_generation !== $generation ||
            Carbon::parse($session->idle_expires_at)->lessThanOrEqualTo($now) ||
            Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo($now)) {
            throw new ExpiredSecuritySession;
        }
        $lifecycle = DB::table('principal_lifecycles')->where('user_id', $user->id)->first();
        $internal = $request->is('internal/*');
        if ($internal ? ($lifecycle?->principal_class !== 'staff' || $session->auth_method !== 'internal_passkey' ||
            ! DB::table('internal_personnel')->where('user_id', $user->id)->where('status', 'active')->exists())
            : ($lifecycle?->principal_class !== 'advertiser' || $session->auth_method === 'internal_passkey')) {
            throw new ExpiredSecuritySession;
        }

        $this->touchAfterCommit((int) $session->id, $generation, $internal ? 15 : 60, $now);
    }

    public function assertCurrentAuthority(Request $request, User $user, int $generation): void
    {
        $authority = DB::connection('pgsql_authority');
        if ($authority->transactionLevel() < 1) {
            throw new \LogicException('Current authority check requires its mutation transaction.');
        }
        $authority->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'builderad.security.session.'.$user->id,
        ]);
        $lifecycle = $authority->table('principal_lifecycles')->where('user_id', $user->id)
            ->sharedLock()->first();
        $internal = $request->is('internal/*');
        if (! $lifecycle || $lifecycle->status !== 'current' ||
            (int) $lifecycle->generation !== $generation ||
            $lifecycle->principal_class !== ($internal ? 'staff' : 'advertiser')) {
            throw new AuthorizationException('Current lifecycle authority is required.');
        }
        if ($internal &&
            ! $authority->table('internal_personnel')->where('user_id', $user->id)
                ->where('status', 'active')->sharedLock()->first()) {
            throw new AuthorizationException('Current staff eligibility is required.');
        }
        $session = $authority->table('security_sessions')
            ->where('session_key_hash', self::sessionKey($request))
            ->where('user_id', $user->id)->lockForUpdate()->first();
        $now = now();
        if (! $session || $session->revoked_at ||
            (int) $session->lifecycle_generation !== $generation ||
            Carbon::parse($session->idle_expires_at)->lessThanOrEqualTo($now) ||
            Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo($now) ||
            ($internal ? $session->auth_method !== 'internal_passkey'
                : $session->auth_method === 'internal_passkey')) {
            throw new ExpiredSecuritySession;
        }
        $idleMinutes = $internal ? 15 : 60;
        $authority->update(<<<'SQL'
            UPDATE security_sessions
            SET last_seen_at = ?, idle_expires_at = LEAST(absolute_expires_at, ?)
            WHERE id = ? AND lifecycle_generation = ? AND revoked_at IS NULL
            SQL, [$now, $now->copy()->addMinutes($idleMinutes), $session->id, $generation]);
    }

    private function touchAfterCommit(int $sessionId, int $generation, int $idleMinutes, Carbon $checkedAt): void
    {
        DB::connection()->afterCommit(static function () use ($sessionId, $generation, $idleMinutes, $checkedAt): void {
            $connection = DB::connection('pgsql_authority');
            $idleDeadline = $checkedAt->copy()->addMinutes($idleMinutes);
            $connection->update(<<<'SQL'
                UPDATE security_sessions
                SET last_seen_at = ?, idle_expires_at = LEAST(absolute_expires_at, ?)
                WHERE id = ? AND lifecycle_generation = ? AND revoked_at IS NULL
                  AND idle_expires_at > clock_timestamp()
                  AND absolute_expires_at > clock_timestamp()
                SQL, [$checkedAt, $idleDeadline, $sessionId, $generation]);
        });
    }

    public function rebindGeneration(Request $request, User $user, int $generation): void
    {
        $changed = DB::connection('pgsql_authority')->table('security_sessions')
            ->where('session_key_hash', self::sessionKey($request))
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now())
            ->update(['lifecycle_generation' => $generation]);
        if ($changed !== 1) {
            throw new ExpiredSecuritySession;
        }
        $request->session()->put('a001_generation', $generation);
    }

    public function revokeCurrent(Request $request): void
    {
        $sessionKey = self::sessionKey($request);
        $user = $request->user();
        $revoke = function () use ($sessionKey, $user): void {
            DB::connection('pgsql_authority')->transaction(function () use ($sessionKey, $user): void {
                $changed = DB::connection('pgsql_authority')->table('security_sessions')
                    ->where('session_key_hash', $sessionKey)
                    ->whereNull('revoked_at')->update(['revoked_at' => now()]);
                if ($changed && $user) {
                    $this->audit($user, $user->id, 'session_revoked', 'current_session', 'active', 'revoked');
                }
            });
        };
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->afterCommit($revoke);
        } else {
            $revoke();
        }
    }

    public function revokeOthers(Request $request, User $user): int
    {
        return DB::connection('pgsql_authority')->transaction(function () use ($request, $user): int {
            $changed = DB::connection('pgsql_authority')->table('security_sessions')->where('user_id', $user->id)
                ->when($request->is('internal/*'),
                    fn ($query) => $query->where('auth_method', 'internal_passkey'),
                    fn ($query) => $query->where('auth_method', '!=', 'internal_passkey'))
                ->where('session_key_hash', '<>', self::sessionKey($request))
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            if ($changed) {
                $this->audit($user, $user->id, 'sessions_revoked', 'other_sessions', 'active', 'revoked');
            }

            return $changed;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function activeFor(Request $request, User $user): array
    {
        return DB::table('security_sessions')
            ->where('user_id', $user->id)
            ->when($request->is('internal/*'),
                fn ($query) => $query->where('auth_method', 'internal_passkey'),
                fn ($query) => $query->where('auth_method', '!=', 'internal_passkey'))
            ->whereNull('revoked_at')
            ->where('idle_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now())
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(function ($session) use ($request): array {
                $agent = (string) $session->client_description;
                $browser = match (true) {
                    str_contains($agent, 'Edg/') => 'Microsoft Edge',
                    str_contains($agent, 'Firefox/') => 'Firefox',
                    str_contains($agent, 'Chrome/') => 'Chrome',
                    str_contains($agent, 'Safari/') => 'Safari',
                    default => 'متصفح غير معروف',
                };
                $platform = match (true) {
                    str_contains($agent, 'Windows') => 'Windows',
                    str_contains($agent, 'Android') => 'Android',
                    str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
                    str_contains($agent, 'Macintosh') => 'macOS',
                    str_contains($agent, 'Linux') => 'Linux',
                    default => 'نظام غير معروف',
                };

                return [
                    'id' => $session->id,
                    'current' => hash_equals($session->session_key_hash, self::sessionKey($request)),
                    'description' => $browser.' · '.$platform,
                    'last_seen_at' => $session->last_seen_at,
                    'authenticated_at' => $session->authenticated_at,
                ];
            })->all();
    }

    public function revokeSelected(Request $request, User $user, int $id): void
    {
        DB::connection('pgsql_authority')->transaction(function () use ($request, $user, $id): void {
            $session = DB::connection('pgsql_authority')->table('security_sessions')->where('id', $id)->where('user_id', $user->id)
                ->when($request->is('internal/*'),
                    fn ($query) => $query->where('auth_method', 'internal_passkey'),
                    fn ($query) => $query->where('auth_method', '!=', 'internal_passkey'))
                ->lockForUpdate()->first();
            if (! $session) {
                abort(404);
            }
            if (hash_equals($session->session_key_hash, self::sessionKey($request))) {
                abort(422, 'Use sign out on this device for the current session.');
            }
            if (! $session->revoked_at) {
                DB::connection('pgsql_authority')->table('security_sessions')->where('id', $id)->update(['revoked_at' => now()]);
                $this->audit($user, $user->id, 'session_revoked', 'selected_session', 'active', 'revoked');
            }
        });
    }

    public function revokeAll(User $user, string $reason = 'credential_recovery', ?int $actorId = null): int
    {
        $changed = DB::connection('pgsql_authority')->table('security_sessions')->where('user_id', $user->id)
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($changed) {
            $this->audit($user, $actorId, 'sessions_revoked', $reason, 'active', 'revoked');
        }

        return $changed;
    }

    public function hasFreshAuthentication(Request $request, User $user): bool
    {
        $authenticatedAt = DB::table('security_sessions')
            ->where('user_id', $user->id)
            ->where('session_key_hash', self::sessionKey($request))
            ->whereNull('revoked_at')
            ->value('authenticated_at');
        if (! $authenticatedAt) {
            return false;
        }

        $staff = DB::table('principal_lifecycles')->where('user_id', $user->id)
            ->where('principal_class', 'staff')->exists();
        $confirmedAt = $staff ? 0 : (int) $request->session()->get('auth.password_confirmed_at', 0);
        $freshAt = max(Carbon::parse($authenticatedAt)->timestamp, $confirmedAt);

        return $freshAt <= time() && $freshAt >= time() - ($staff ? 300 : 900);
    }

    public function markFreshPasswordAuthentication(Request $request, User $user): void
    {
        DB::connection('pgsql_authority')->transaction(function () use ($request, $user): void {
            $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)
                ->where('principal_class', 'advertiser')->where('status', 'current')
                ->sharedLock()->first();
            $session = DB::connection('pgsql_authority')->table('security_sessions')
                ->where('user_id', $user->id)
                ->where('session_key_hash', self::sessionKey($request))
                ->lockForUpdate()->first();
            if (! $lifecycle || ! $session || $session->revoked_at ||
                (int) $session->lifecycle_generation !== (int) $lifecycle->generation ||
                Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo(now())) {
                throw new AuthorizationException('Current advertiser session required.');
            }
            DB::connection('pgsql_authority')->table('security_sessions')->where('id', $session->id)
                ->update(['authenticated_at' => now()]);
            $this->audit($user, $user->id, 'session_reauthenticated',
                'password_confirmation', 'active', 'active');
        });
    }

    public static function sessionKey(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    private function audit(User $user, ?int $actorId, string $event, string $reason, ?string $before, string $after): void
    {
        $generation = (int) DB::connection('pgsql_authority')->table('principal_lifecycles')
            ->where('user_id', $user->id)->value('generation');
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $actorId,
            'subject_user_id' => $user->id,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $before,
            'new_status' => $after,
            'prior_generation' => $generation,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }
}
