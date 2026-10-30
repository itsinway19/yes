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
        return $this->execute($principal, $workspacePublicId, $callback, $request, false);
    }

    public function runBusinessMutation(User $principal, string $workspacePublicId, Closure $callback, Request $request): mixed
    {
        return $this->execute($principal, $workspacePublicId, $callback, $request, true);
    }

    public function runNativeSourceMutation(User $principal, string $workspacePublicId, string $source, Closure $callback, Request $request): mixed
    {
        if (! Str::isUuid($source)) {
            throw new AuthorizationException('An exact native source is required.');
        }

        return $this->execute($principal, $workspacePublicId, $callback, $request, false, $source);
    }

    private function execute(User $principal, string $workspacePublicId, Closure $callback, Request $request, bool $businessMutation, ?string $nativeSource = null): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Workspace context must own the outer database transaction.');
        }

        if (! $principal->exists || ! is_int($principal->getKey()) || ! Str::isUuid($workspacePublicId) ||
            $request->user()?->getAuthIdentifier() !== $principal->getKey()) {
            throw new AuthorizationException('An active workspace membership is required.');
        }

        return $connection->transaction(function () use ($connection, $principal, $workspacePublicId, $callback, $request, $businessMutation, $nativeSource) {
            $this->principals->activate($principal);
            try {
                if ($nativeSource !== null) {
                    $connection->selectOne('SELECT builderad_v2_native_source_enter(?::uuid,?::uuid)', [$workspacePublicId, $nativeSource]);
                }
                $connection->selectOne('SELECT builderad_v2_legacy_enter(?::uuid)', [$workspacePublicId]);
            } catch (QueryException $error) {
                if ($error->getCode() !== '42501') {
                    throw $error;
                }
                throw new AuthorizationException('Explicit scope upgrade required or collaboration unavailable.', previous: $error);
            }
            $generation = (int) $request->session()->get('a001_generation', 0);
            if (! $this->lifecycles->isCurrent($principal, $generation)) {
                throw new AuthorizationException('A current principal is required.');
            }
            $this->sessions->assertCurrent($request, $principal, $generation);

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
                if ($businessMutation) {
                    $connection->selectOne('SELECT builderad_business_context_begin(?,?)', [$workspace->getKey(), SessionAuthority::sessionKey($request)]);
                } else {
                    $connection->selectOne('SELECT builderad_select_workspace_context(?)', [$workspace->getKey()]);
                }
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
