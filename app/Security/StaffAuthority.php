<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class StaffAuthority
{
    public function __construct(
        private readonly LifecycleAuthority $lifecycles,
        private readonly SessionAuthority $sessions,
        private readonly GrantAuthority $grants,
    ) {}

    public function inviteEmployee(Request $request, string $name, string $email): User
    {
        $this->requireAction($request, 'staff.invite');
        $user = User::on('pgsql_authority')->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
        ]);
        $this->lifecycles->registerPendingStaff($user, $request->user()->id);
        DB::connection('pgsql_authority')->table('internal_personnel')->insert([
            'user_id' => $user->id,
            'kind' => 'employee',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function attest(Request $request, User $target): void
    {
        $this->requireAction($request, 'staff.attest');
        $this->assertOrdinaryTarget($request, $target);
        $this->lockTargetLifecycle($target);
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $personnel || $personnel->status !== 'pending' || ! $target->hasVerifiedEmail()) {
            throw new AuthorizationException('Verified pending employee eligibility is required.');
        }
        if ($personnel->attested_at !== null) {
            return;
        }
        DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)->update([
            'attested_by' => $request->user()->id,
            'attested_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request->user()->id, $target->id, 'staff_attested', 'ordinary_employee_eligibility', 'pending', 'pending');
    }

    public function activate(Request $request, User $target): void
    {
        $this->requireAction($request, 'staff.activate');
        $this->assertOrdinaryTarget($request, $target);
        $this->lockTargetLifecycle($target);
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $personnel || $personnel->status !== 'pending' || ! $personnel->attested_at ||
            ! $target->hasVerifiedEmail() || $target->passkeys()->count() < 1) {
            throw new AuthorizationException('Staff activation prerequisites are incomplete.');
        }
        DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)->update([
            'status' => 'active', 'updated_at' => now(),
        ]);
        $this->lifecycles->activateStaff($target, $request->user()->id);
        $this->sessions->revokeAll($target, 'staff_activation', $request->user()->id);
    }

    public function revoke(Request $request, User $target): void
    {
        $this->requireAction($request, 'staff.revoke');
        $this->assertOrdinaryTarget($request, $target);
        $this->lockTargetLifecycle($target);
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $personnel || $personnel->status !== 'active') {
            throw new AuthorizationException('An active employee is required.');
        }
        $this->lifecycles->disable($target, $request->user()->id, 'employment_eligibility_revoked');
        DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)->update([
            'status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now(),
        ]);
        $this->sessions->revokeAll($target, 'staff_eligibility_revocation', $request->user()->id);
    }

    public function attestIndependentFactors(Request $request, User $target): void
    {
        $this->requireAction($request, 'grant.manage');
        $this->assertOrdinaryTarget($request, $target);
        $founder = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $request->user()->id)
            ->where('kind', 'founder')->where('status', 'active')->sharedLock()->exists();
        if (! $founder) {
            throw new AuthorizationException('Founder authority is required for sensitive authenticator attestation.');
        }
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $target->id)
            ->where('principal_class', 'staff')->where('status', 'current')->sharedLock()->first();
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $lifecycle || ! $personnel || $personnel->status !== 'active' ||
            ! $target->hasVerifiedEmail() || $target->passkeys()->count() < 2) {
            throw new AuthorizationException('Two enrolled employee authenticators are required.');
        }
        if ($personnel->factor_independence_attested_at) {
            return;
        }
        DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)->update([
            'factor_independence_attested_at' => now(),
            'factor_independence_attested_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
        $this->audit($request->user()->id, $target->id, 'staff_factors_attested',
            'independent_primary_and_backup', 'active', 'active');
    }

    private function requireAction(Request $request, string $action): void
    {
        if (! $this->grants->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), $action)) {
            throw new AuthorizationException('An explicit staff action grant is required.');
        }
    }

    private function assertOrdinaryTarget(Request $request, User $target): void
    {
        if ($target->id === $request->user()->id ||
            ! DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
                ->where('kind', 'employee')->exists()) {
            throw new AuthorizationException('Ordinary staff actions cannot target self or founder.');
        }
    }

    private function lockTargetLifecycle(User $target): void
    {
        DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $target->id)
            ->lockForUpdate()->firstOrFail();
    }

    private function audit(int $actorId, int $subjectId, string $event, string $reason, string $before, string $after): void
    {
        $generation = (int) DB::connection('pgsql_authority')->table('principal_lifecycles')
            ->where('user_id', $subjectId)->value('generation');
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectId,
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
