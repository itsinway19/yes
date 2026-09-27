<?php

namespace App\Security;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkspaceAuthority
{
    public function __construct(
        private readonly PrincipalContext $principals,
        private readonly GrantAuthority $grants,
        private readonly SessionAuthority $sessions,
    ) {}

    /** @return array<int, array{public_id: string, name: string, slug: string}> */
    public function visibleTo(User $user, int $generation): array
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Workspace listing requires the guarded outer transaction.');
        }

        $this->principals->activate($user);

        return Workspace::query()
            ->whereExists(function ($query) use ($user, $generation): void {
                $query->selectRaw('1')->from('security_action_grants as grant_record')
                    ->whereColumn('grant_record.workspace_id', 'workspaces.id')
                    ->where('grant_record.subject_user_id', $user->id)
                    ->where('grant_record.subject_generation', $generation)
                    ->where('grant_record.action', 'workspace.view')
                    ->where('grant_record.scope_kind', 'workspace')
                    ->where('grant_record.status', 'active')
                    ->whereNull('grant_record.revoked_at')
                    ->where(fn ($q) => $q->whereNull('grant_record.expires_at')
                        ->orWhere('grant_record.expires_at', '>', now()));
            })
            ->orderBy('name')->get(['public_id', 'name', 'slug'])
            ->map(fn (Workspace $workspace): array => [
                'public_id' => $workspace->public_id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ])->all();
    }

    /** @return array{id: int, public_id: string} */
    public function create(Request $request, string $name, string $slug): array
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Workspace creation requires the guarded outer transaction.');
        }

        $this->principals->activate($request->user());
        $result = DB::selectOne(
            'SELECT builderad_create_workspace(?, ?, ?, ?) AS public_id',
            [$request->user()->id, SessionAuthority::sessionKey($request), $name, $slug],
        );

        $workspace = Workspace::query()->where('public_id', $result->public_id)->firstOrFail();

        return ['id' => $workspace->id, 'public_id' => $workspace->public_id];
    }

    public function authorizeServiceScope(Request $request, Workspace $workspace, string $servicePublicId, string $purpose): void
    {
        $this->assertResponsibleOwner($request, $workspace);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $servicePublicId)
            ->where('status', 'enabled')->sharedLock()->first();
        if (! $service) {
            throw new AuthorizationException('An enabled service identity is required.');
        }
        $scope = DB::connection('pgsql_authority')->table('service_workspace_scopes')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()->first();
        if ($scope?->status === 'active') {
            return;
        }
        $version = $scope ? (int) $scope->version + 1 : 1;
        if ($scope) {
            DB::connection('pgsql_authority')->table('service_workspace_scopes')->where('service_identity_id', $service->id)
                ->where('workspace_id', $workspace->id)->update([
                    'status' => 'active', 'purpose' => $purpose,
                    'authorized_by' => $request->user()->id,
                    'revoked_at' => null, 'version' => $version, 'updated_at' => now(),
                ]);
        } else {
            DB::connection('pgsql_authority')->table('service_workspace_scopes')->insert([
                'service_identity_id' => $service->id,
                'workspace_id' => $workspace->id,
                'authorized_by' => $request->user()->id,
                'purpose' => $purpose,
                'status' => 'active', 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->auditServiceScope($request, $workspace, $service->id,
            'service_scope_authorized', $purpose, $scope?->status, 'active', $scope?->version, $version);
    }

    public function revokeServiceScope(Request $request, Workspace $workspace, string $servicePublicId): void
    {
        $this->assertResponsibleOwner($request, $workspace);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $servicePublicId)->first();
        if (! $service) {
            abort(404);
        }
        $scope = DB::connection('pgsql_authority')->table('service_workspace_scopes')
            ->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()->first();
        if (! $scope) {
            abort(404);
        }
        if ($scope->status === 'revoked') {
            return;
        }
        $version = (int) $scope->version + 1;
        DB::connection('pgsql_authority')->table('service_workspace_scopes')->where('service_identity_id', $service->id)
            ->where('workspace_id', $workspace->id)->update([
                'status' => 'revoked', 'revoked_at' => now(),
                'version' => $version, 'updated_at' => now(),
            ]);
        $this->auditServiceScope($request, $workspace, $service->id,
            'service_scope_revoked', 'responsible_owner_revocation', 'active', 'revoked', (int) $scope->version, $version);
    }

    private function assertResponsibleOwner(Request $request, Workspace $workspace): void
    {
        if (DB::connection()->transactionLevel() !== 1 ||
            ! $this->sessions->hasFreshAuthentication($request, $request->user()) ||
            ! DB::table('workspace_ownerships')->where('workspace_id', $workspace->id)
                ->where('responsible_owner_user_id', $request->user()->id)->exists() ||
            ! $this->grants->has($request->user(),
                (int) $request->session()->get('a001_generation', 0),
                'service.scope', 'workspace', $workspace->id)) {
            throw new AuthorizationException('Fresh responsible Owner and explicit service scope authority required.');
        }
    }

    private function auditServiceScope(Request $request, Workspace $workspace, int $serviceId,
        string $event, string $reason, ?string $before, string $after, ?int $priorVersion, int $newVersion): void
    {
        DB::connection('pgsql_authority')->table('service_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'service_identity_id' => $serviceId,
            'workspace_id' => $workspace->id,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $before,
            'new_status' => $after,
            'prior_version' => $priorVersion,
            'new_version' => $newVersion,
            'occurred_at' => now(),
        ]);
    }
}
