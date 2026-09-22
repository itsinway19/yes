<?php

namespace App\Foundation\Tenancy;

use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class WorkspaceContext
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public function run(User $principal, string $workspacePublicId, Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Workspace context must own the outer database transaction.');
        }

        if (! $principal->exists || ! is_int($principal->getKey()) || ! Str::isUuid($workspacePublicId)) {
            throw new AuthorizationException('An active workspace membership is required.');
        }

        return $connection->transaction(function () use ($connection, $principal, $workspacePublicId, $callback) {
            $this->principals->activate($principal);

            $workspace = Workspace::query()
                ->where('public_id', $workspacePublicId)
                ->where('status', 'active')
                ->first();

            if (! $workspace) {
                throw new AuthorizationException('An active workspace membership is required.');
            }

            $authorized = $connection->table('workspace_memberships')
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $principal->getKey())
                ->where('status', 'active')
                ->exists();

            if (! $authorized) {
                throw new AuthorizationException('An active workspace membership is required.');
            }

            $connection->selectOne(
                "select set_config('app.workspace_id', ?, true)",
                [(string) $workspace->getKey()],
            );
            $connection->selectOne(
                "select set_config('app.authorization_phase', 'workspace', true)",
            );

            $workspace = Workspace::query()
                ->whereKey($workspace->getKey())
                ->where('status', 'active')
                ->first();

            if (! $workspace) {
                throw new AuthorizationException('The workspace is unavailable.');
            }

            return $callback($workspace);
        });
    }
}
