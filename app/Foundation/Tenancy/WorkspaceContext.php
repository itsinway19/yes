<?php

namespace App\Foundation\Tenancy;

use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class WorkspaceContext
{
    public function __construct(
        private readonly PrincipalContext $principals,
        private readonly LifecycleAuthority $lifecycles,
        private readonly SessionAuthority $sessions,
        private readonly GrantAuthority $grants,
    ) {}

    public function run(User $principal, string $workspacePublicId, Closure $callback, Request $request): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Workspace context must own the outer database transaction.');
        }

        if (! $principal->exists || ! is_int($principal->getKey()) || ! Str::isUuid($workspacePublicId) ||
            $request->user()?->getAuthIdentifier() !== $principal->getKey()) {
            throw new AuthorizationException('An active workspace membership is required.');
        }

        return $connection->transaction(function () use ($connection, $principal, $workspacePublicId, $callback, $request) {
            $generation = (int) $request->session()->get('a001_generation', 0);
            if (! $this->lifecycles->isCurrent($principal, $generation)) {
                throw new AuthorizationException('A current principal is required.');
            }
            $this->sessions->assertCurrent($request, $principal, $generation);
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

            try {
                $connection->selectOne('SELECT builderad_select_workspace_context(?)', [$workspace->getKey()]);
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '42501') {
                    throw $exception;
                }

                throw new AuthorizationException('Current Workspace authority is required.', previous: $exception);
            }

            // The signed selector above verifies and locks the current responsible Owner,
            // their identity/lifecycle and active owner membership until this transaction ends.
            // Runtime RLS deliberately hides another user's membership; repeating that
            // privileged proof here incorrectly denies an otherwise authorized member.
            if (! $connection->scalar('SELECT builderad_has_valid_workspace_owner(?)', [$workspace->getKey()])) {
                throw new AuthorizationException('A current responsible Workspace Owner is required.');
            }
            if (! $this->grants->has(
                $principal,
                (int) $request->session()->get('a001_generation', 0),
                'workspace.view',
                'workspace',
                $workspace->getKey(),
            )) {
                throw new AuthorizationException('An explicit Workspace view grant is required.');
            }

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
