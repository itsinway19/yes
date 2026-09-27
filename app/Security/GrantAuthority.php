<?php

namespace App\Security;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GrantAuthority
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public const STAFF_ACTIONS = [
        'staff.view', 'staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke',
        'grant.manage', 'service.manage',
    ];

    private const SENSITIVE_STAFF_ACTIONS = ['grant.manage', 'service.manage'];

    /** @return array<int, array{public_id: string, name: string, slug: string}> */
    public function reassessableOwnerWorkspaces(Request $request): array
    {
        return array_map(
            static fn (object $workspace): array => [
                'public_id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            DB::select('SELECT * FROM builderad_owner_reassessment_candidates(?, ?)', [
                $request->user()->id, SessionAuthority::sessionKey($request),
            ]),
        );
    }

    public function reassessOwnerWorkspaceGrants(Request $request, string $workspacePublicId): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Owner grant reassessment requires the guarded outer transaction.');
        }
        $this->principals->activate($request->user());
        $evidence = DB::selectOne('SELECT * FROM builderad_owner_reassessment_evidence(?, ?, ?::uuid)', [
            $request->user()->id,
            SessionAuthority::sessionKey($request),
            $workspacePublicId,
        ]);
        if (! $evidence) {
            throw new AuthorizationException('Current Owner reassessment evidence is required.');
        }

        DB::connection('pgsql_authority')->transaction(function () use ($request, $evidence): void {
            $generation = (int) $request->session()->get('a001_generation', 0);
            $this->issueReassessedOwnerGrant($request, (int) $evidence->workspace_id,
                $generation, (int) $evidence->prior_generation,
                'workspace.view', $evidence->view_expires_at, true);
            if (in_array($evidence->restore_service_scope, [true, 1, '1', 't'], true)) {
                $this->issueReassessedOwnerGrant($request, (int) $evidence->workspace_id,
                    $generation, (int) $evidence->prior_generation,
                    'service.scope', $evidence->scope_expires_at, false);
            }
        });
    }

    private function issueReassessedOwnerGrant(Request $request, int $workspaceId, int $generation,
        int $priorGeneration, string $action, ?string $expiresAt, bool $required): void
    {
        $query = DB::connection('pgsql_authority')->table('security_action_grants')
            ->where('subject_user_id', $request->user()->id)
            ->where('workspace_id', $workspaceId)
            ->where('subject_generation', $generation)
            ->where('action', $action);
        $existing = (clone $query)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->status !== 'active' || $existing->revoked_at !== null ||
                ($existing->expires_at !== null && $existing->expires_at <= now())) {
                if ($required) {
                    throw new AuthorizationException('Revoked Workspace grant cannot be restored.');
                }

                return;
            }

            return;
        }

        $inserted = DB::connection('pgsql_authority')->table('security_action_grants')->insertOrIgnore([
            'subject_user_id' => $request->user()->id,
            'granted_by' => null,
            'source' => 'system_owner_reassessment',
            'action' => $action,
            'scope_kind' => 'workspace',
            'workspace_id' => $workspaceId,
            'subject_generation' => $generation,
            'reason' => 'verified_owner_continuity_after_email_change',
            'status' => 'active',
            'version' => 1,
            'expires_at' => $expiresAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            $current = (clone $query)->lockForUpdate()->first();
            if (! $current || $current->status !== 'active' || $current->revoked_at !== null) {
                throw new AuthorizationException('Workspace grant conflict denied.');
            }

            return;
        }

        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'subject_user_id' => $request->user()->id,
            'workspace_id' => $workspaceId,
            'event' => 'workspace_owner_grant_reassessed',
            'reason' => $action,
            'prior_status' => 'active',
            'new_status' => 'active',
            'prior_generation' => $priorGeneration,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }

    public function issueFounderBootstrapGrants(User $founder, int $generation): void
    {
        foreach (self::STAFF_ACTIONS as $action) {
            DB::connection('pgsql_authority')->table('security_action_grants')->insert([
                'subject_user_id' => $founder->id,
                'granted_by' => null,
                'source' => 'founder_bootstrap',
                'action' => $action,
                'scope_kind' => 'platform',
                'workspace_id' => null,
                'subject_generation' => $generation,
                'reason' => 'trusted_local_founder_bootstrap',
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function issueStaffGrant(Request $request, User $target, string $action, string $reason): void
    {
        $manager = $request->user();
        $this->assertGrantManager($request);
        if (! in_array($action, self::STAFF_ACTIONS, true) || $manager->id === $target->id) {
            throw new AuthorizationException('The requested staff grant is not permitted.');
        }
        $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')->where('user_id', $target->id)
            ->sharedLock()->first();
        $personnel = DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $target->id)
            ->lockForUpdate()->first();
        if (! $personnel || $personnel->kind !== 'employee' || $personnel->status !== 'active' ||
            ! $lifecycle || $lifecycle->principal_class !== 'staff' || $lifecycle->status !== 'current') {
            throw new AuthorizationException('An active ordinary employee is required.');
        }
        if (in_array($action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ! $this->isFounder($manager)) {
            throw new AuthorizationException('Only founder authority may issue sensitive staff grants.');
        }
        if (in_array($action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ($target->passkeys()->count() < 2 || ! $personnel->factor_independence_attested_at)) {
            throw new AuthorizationException('Independent primary and backup authenticators are required.');
        }
        $existing = DB::connection('pgsql_authority')->table('security_action_grants')->where('subject_user_id', $target->id)
            ->where('action', $action)->where('scope_kind', 'platform')
            ->where('subject_generation', $lifecycle->generation)
            ->where('status', 'active')->lockForUpdate()->first();
        if ($existing) {
            return;
        }
        DB::connection('pgsql_authority')->table('security_action_grants')->insert([
            'subject_user_id' => $target->id,
            'granted_by' => $manager->id,
            'source' => 'human',
            'action' => $action,
            'scope_kind' => 'platform',
            'workspace_id' => null,
            'subject_generation' => $lifecycle->generation,
            'reason' => $reason,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->auditStaffGrant($manager->id, $target->id, $action, 'active', (int) $lifecycle->generation);
    }

    public function revokeStaffGrant(Request $request, int $grantId): void
    {
        $this->assertGrantManager($request);
        $grant = DB::connection('pgsql_authority')->table('security_action_grants')->where('id', $grantId)
            ->where('scope_kind', 'platform')->where('source', 'human')
            ->lockForUpdate()->first();
        if (! $grant) {
            abort(404);
        }
        if (in_array($grant->action, self::SENSITIVE_STAFF_ACTIONS, true) &&
            ! $this->isFounder($request->user())) {
            throw new AuthorizationException('Only founder authority may revoke sensitive staff grants.');
        }
        if ($grant->status === 'revoked') {
            return;
        }
        DB::connection('pgsql_authority')->table('security_action_grants')->where('id', $grantId)->update([
            'status' => 'revoked', 'revoked_at' => now(),
            'version' => (int) $grant->version + 1, 'updated_at' => now(),
        ]);
        $this->auditStaffGrant($request->user()->id, $grant->subject_user_id,
            $grant->action, 'revoked', (int) $grant->subject_generation);
    }

    private function assertGrantManager(Request $request): void
    {
        if (! $this->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), 'grant.manage')) {
            throw new AuthorizationException('Explicit grant administration authority is required.');
        }
    }

    private function isFounder(User $user): bool
    {
        return DB::connection('pgsql_authority')->table('internal_personnel')->where('user_id', $user->id)
            ->where('kind', 'founder')->where('status', 'active')
            ->sharedLock()->exists();
    }

    private function auditStaffGrant(int $actorId, int $subjectId, string $action, string $status, int $generation): void
    {
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $actorId,
            'subject_user_id' => $subjectId,
            'event' => 'staff_grant_'.$status,
            'reason' => $action,
            'prior_status' => $status === 'active' ? null : 'active',
            'new_status' => $status,
            'prior_generation' => $generation,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }

    public function issueServiceWorkspaceView(Request $request, string $servicePublicId, string $workspacePublicId, string $reason): void
    {
        $this->assertServiceManager($request);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $servicePublicId)
            ->where('status', 'enabled')->sharedLock()->first();
        $workspaceId = DB::scalar('SELECT builderad_workspace_id_by_public(?)', [$workspacePublicId]);
        if (! $service || ! $workspaceId) {
            throw new AuthorizationException('An enabled service and active Workspace are required.');
        }
        $scope = DB::connection('pgsql_authority')->table('service_workspace_scopes')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspaceId)->where('status', 'active')
            ->sharedLock()->first();
        if (! $scope) {
            throw new AuthorizationException('Responsible Owner approval is required before a service grant.');
        }
        $existing = DB::connection('pgsql_authority')->table('service_action_grants')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspaceId)
            ->where('action', 'workspace.view')->where('status', 'active')
            ->lockForUpdate()->first();
        if ($existing) {
            return;
        }
        DB::connection('pgsql_authority')->table('service_action_grants')->insert([
            'service_identity_id' => $service->id,
            'workspace_id' => $workspaceId,
            'action' => 'workspace.view',
            'granted_by' => $request->user()->id,
            'reason' => $reason,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditServiceGrant($request, $service->id, (int) $workspaceId,
            'service_grant_issued', $reason, null, 'active', null, 1);
    }

    public function revokeServiceGrant(Request $request, int $grantId): void
    {
        $this->assertServiceManager($request);
        $grant = DB::connection('pgsql_authority')->table('service_action_grants')->where('id', $grantId)
            ->lockForUpdate()->first();
        if (! $grant) {
            abort(404);
        }
        if ($grant->status === 'revoked') {
            return;
        }
        $version = (int) $grant->version + 1;
        DB::connection('pgsql_authority')->table('service_action_grants')->where('id', $grantId)->update([
            'status' => 'revoked', 'revoked_at' => now(),
            'version' => $version, 'updated_at' => now(),
        ]);
        $this->auditServiceGrant($request, $grant->service_identity_id, $grant->workspace_id,
            'service_grant_revoked', 'explicit_service_grant_revocation',
            'active', 'revoked', (int) $grant->version, $version);
    }

    private function assertServiceManager(Request $request): void
    {
        if (! $this->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), 'service.manage')) {
            throw new AuthorizationException('Explicit service grant management authority required.');
        }
    }

    private function auditServiceGrant(Request $request, int $serviceId, int $workspaceId,
        string $event, string $reason, ?string $before, string $after,
        ?int $priorVersion, int $newVersion): void
    {
        DB::connection('pgsql_authority')->table('service_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'service_identity_id' => $serviceId,
            'workspace_id' => $workspaceId,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $before,
            'new_status' => $after,
            'prior_version' => $priorVersion,
            'new_version' => $newVersion,
            'occurred_at' => now(),
        ]);
    }

    public function issueAdvertiserWorkspaceCreation(User $user, int $generation): void
    {
        DB::connection('pgsql_authority')->table('security_action_grants')->insert([
            'subject_user_id' => $user->id,
            'granted_by' => null,
            'source' => 'system_registration',
            'action' => 'workspace.create',
            'scope_kind' => 'platform',
            'workspace_id' => null,
            'subject_generation' => $generation,
            'reason' => 'verified_advertiser_registration',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function has(User $actor, int $generation, string $action, string $scope = 'platform', ?int $workspaceId = null): bool
    {
        $connection = DB::connection('pgsql_authority')->transactionLevel() > 0
            ? DB::connection('pgsql_authority') : DB::connection();
        $lifecycle = $connection->table('principal_lifecycles')->where('user_id', $actor->id)
            ->where('status', 'current')->where('generation', $generation)->sharedLock()->first();
        if (! $lifecycle || ! $actor->hasVerifiedEmail()) {
            return false;
        }
        if ($lifecycle->principal_class === 'advertiser') {
            $allowedAdvertiserAction = ($action === 'workspace.create' && $scope === 'platform' && $workspaceId === null) ||
                (in_array($action, ['workspace.view', 'service.scope'], true) && $scope === 'workspace' && $workspaceId !== null);
            if (! $allowedAdvertiserAction) {
                return false;
            }
        } elseif ($lifecycle->principal_class !== 'staff' ||
            ! $connection->table('internal_personnel')->where('user_id', $actor->id)
                ->where('status', 'active')->sharedLock()->first()) {
            return false;
        }

        $query = $connection->table('security_action_grants')
            ->where('subject_user_id', $actor->id)
            ->where('subject_generation', $generation)
            ->where('action', $action)
            ->where('scope_kind', $scope)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($scope === 'workspace') {
            $query->where('workspace_id', $workspaceId);
        } else {
            $query->whereNull('workspace_id');
        }

        return (bool) $query->sharedLock()->first();
    }
}
