<?php

namespace App\Security;

use App\Models\User;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LifecycleAuthority
{
    public function __construct(private readonly GrantAuthority $grants) {}

    public function registerPending(User $user): void
    {
        DB::connection('pgsql_authority')->table('principal_lifecycles')->insert([
            'user_id' => $user->getKey(),
            'status' => 'pending',
            'generation' => 0,
            'cause' => 'primary_email_unverified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($user->getKey(), 'registered_pending', 'primary_email_unverified', null, 'pending', null, 0);
    }

    public function registerPendingStaff(User $user, int $actorId): void
    {
        DB::connection('pgsql_authority')->table('principal_lifecycles')->insert([
            'user_id' => $user->getKey(),
            'status' => 'pending',
            'generation' => 0,
            'principal_class' => 'staff',
            'cause' => 'staff_enrollment_pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($user->getKey(), 'staff_invited', 'staff_enrollment_pending', null, 'pending', null, 0, $actorId);
    }

    public function activateStaff(User $user, int $actorId): int
    {
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)
            ->lockForUpdate()->first();
        if (! $lifecycle || $lifecycle->principal_class !== 'staff' ||
            $lifecycle->status !== 'pending' || ! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('Staff activation prerequisites are incomplete.');
        }
        $nextGeneration = (int) $lifecycle->generation + 1;
        DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)->update([
            'status' => 'current', 'generation' => $nextGeneration,
            'cause' => null, 'updated_at' => now(),
        ]);
        $this->audit($user->id, 'staff_activated', 'attested_eligibility_and_passkey',
            'pending', 'current', (int) $lifecycle->generation, $nextGeneration, $actorId);

        return $nextGeneration;
    }

    public function bootstrapFounder(User $user): int
    {
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)
            ->lockForUpdate()->first();
        if (! $lifecycle || $lifecycle->status !== 'current' ||
            $lifecycle->principal_class !== 'advertiser' || ! $user->hasVerifiedEmail()) {
            throw new AuthorizationException('A verified, current advertiser account is required for founder bootstrap.');
        }
        $generation = (int) $lifecycle->generation + 1;
        DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)->update([
            'principal_class' => 'staff', 'generation' => $generation,
            'cause' => null, 'updated_at' => now(),
        ]);
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => null,
            'subject_user_id' => $user->id,
            'event' => 'founder_bootstrapped',
            'reason' => 'trusted_local_operator_attestation',
            'prior_status' => 'current',
            'new_status' => 'current',
            'prior_generation' => $lifecycle->generation,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);

        return $generation;
    }

    public function disable(User $user, int $actorId, string $reason): void
    {
        DB::connection('pgsql_authority')->transaction(function () use ($user, $actorId, $reason): void {
            $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)
                ->lockForUpdate()->first();
            if (! $lifecycle || $lifecycle->status === 'disabled') {
                throw new AuthorizationException('Current lifecycle authority is required.');
            }
            $nextGeneration = (int) $lifecycle->generation + 1;
            DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->id)->update([
                'status' => 'disabled', 'generation' => $nextGeneration,
                'cause' => Str::limit($reason, 64, ''), 'updated_at' => now(),
            ]);
            DB::connection('pgsql_authority')->table('security_audit_events')->insert([
                'actor_user_id' => $actorId,
                'subject_user_id' => $user->id,
                'event' => 'principal_disabled',
                'reason' => $reason,
                'prior_status' => $lifecycle->status,
                'new_status' => 'disabled',
                'prior_generation' => $lifecycle->generation,
                'new_generation' => $nextGeneration,
                'occurred_at' => now(),
            ]);
            SecurityMail::afterCommit('account_disabled',
                fn () => $user->notify(new SecurityNotice('account_disabled')));
        });
    }

    /**
     * @return array{generation: int, newly_verified: bool}
     */
    public function verifyPrimaryEmail(User $user): array
    {
        return DB::connection('pgsql_authority')->transaction(function () use ($user): array {
            $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lifecycle || $lifecycle->status === 'disabled') {
                throw new AuthorizationException('This account cannot be activated by email verification.');
            }

            $freshUser = User::on('pgsql_authority')->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $newlyVerified = ! $freshUser->hasVerifiedEmail() && $freshUser->markEmailAsVerified();
            if (! $freshUser->hasVerifiedEmail()) {
                throw new AuthorizationException('Verified primary email is required.');
            }

            if ($lifecycle->principal_class === 'staff') {
                if ($newlyVerified) {
                    $this->audit($user->getKey(), 'staff_primary_email_verified', 'primary_email_verified',
                        $lifecycle->status, $lifecycle->status, (int) $lifecycle->generation, (int) $lifecycle->generation);
                }

                return ['generation' => (int) $lifecycle->generation, 'newly_verified' => $newlyVerified];
            }

            if ($lifecycle->status === 'current') {
                return ['generation' => (int) $lifecycle->generation, 'newly_verified' => $newlyVerified];
            }

            $nextGeneration = (int) $lifecycle->generation + 1;
            DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->getKey())->update([
                'status' => 'current',
                'generation' => $nextGeneration,
                'cause' => null,
                'updated_at' => now(),
            ]);
            $this->audit($user->getKey(), 'advertiser_activated', 'primary_email_verified', $lifecycle->status, 'current', (int) $lifecycle->generation, $nextGeneration);
            $this->grants->issueAdvertiserWorkspaceCreation($freshUser, $nextGeneration);

            return ['generation' => $nextGeneration, 'newly_verified' => $newlyVerified];
        });
    }

    public function changeProfile(Request $request, string $name, string $email): bool
    {
        $user = $request->user();

        return DB::connection('pgsql_authority')->transaction(function () use ($request, $user, $name, $email): bool {
            $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();
            $session = DB::connection('pgsql_authority')->table('security_sessions')
                ->where('user_id', $user->getKey())
                ->where('session_key_hash', SessionAuthority::sessionKey($request))
                ->sharedLock()->first();
            if (! $lifecycle || $lifecycle->status !== 'current' ||
                (int) $lifecycle->generation !== (int) $request->session()->get('a001_generation', 0) ||
                ! $session || $session->revoked_at ||
                (int) $session->lifecycle_generation !== (int) $lifecycle->generation ||
                Carbon::parse($session->idle_expires_at)->lessThanOrEqualTo(now()) ||
                Carbon::parse($session->absolute_expires_at)->lessThanOrEqualTo(now())) {
                throw new AuthorizationException('Current account and session are required.');
            }

            $freshUser = User::on('pgsql_authority')->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $freshUser->fill(['name' => $name, 'email' => $email]);
            $emailChanged = $freshUser->isDirty('email');
            if ($emailChanged && $lifecycle->principal_class !== 'advertiser') {
                throw new AuthorizationException('Internal primary email changes require separate authority.');
            }
            if ($emailChanged) {
                $freshUser->email_verified_at = null;
            }
            $freshUser->save();

            if ($emailChanged && $lifecycle->status === 'current') {
                DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $user->getKey())->update([
                    'status' => 'pending',
                    'cause' => 'primary_email_unverified',
                    'updated_at' => now(),
                ]);
                $this->audit($user->getKey(), 'primary_email_changed', 'primary_email_unverified', 'current', 'pending', (int) $lifecycle->generation, (int) $lifecycle->generation);
            }

            return $emailChanged;
        });
    }

    public function isCurrent(User $user, int $sessionGeneration): bool
    {
        if (! $user->hasVerifiedEmail() || $sessionGeneration < 1) {
            return false;
        }

        $lifecycle = DB::table('principal_lifecycles')
            ->where('user_id', $user->getKey())
            ->where('status', 'current')
            ->where('generation', $sessionGeneration)
            ->sharedLock()
            ->first();
        if (! $lifecycle) {
            return false;
        }

        if ($lifecycle->principal_class === 'staff') {
            return (bool) DB::table('internal_personnel')
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->sharedLock()
                ->first();
        }

        return $lifecycle->principal_class === 'advertiser';
    }

    private function audit(int $subjectId, string $event, string $reason, ?string $priorStatus, string $newStatus, ?int $priorGeneration, int $newGeneration, ?int $actorId = null): void
    {
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $actorId ?? $subjectId,
            'subject_user_id' => $subjectId,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $priorStatus,
            'new_status' => $newStatus,
            'prior_generation' => $priorGeneration,
            'new_generation' => $newGeneration,
            'occurred_at' => now(),
        ]);
    }
}
