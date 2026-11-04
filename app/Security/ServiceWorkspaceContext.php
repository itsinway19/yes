<?php

namespace App\Security;

use App\Models\Workspace;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ServiceWorkspaceContext
{
    public function run(string $servicePublicId, string $credential, string $workspacePublicId, Closure $effect): mixed
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Service context must own the outer database transaction.');
        }
        if (! Str::isUuid($servicePublicId) || ! Str::isUuid($workspacePublicId) ||
            strlen($credential) < 40 || strlen($credential) > 128) {
            throw new AuthorizationException('Current service authority is required.');
        }

        try {
            return DB::transaction(function () use ($servicePublicId, $credential, $workspacePublicId, $effect): mixed {
                $workspaceId = DB::scalar(
                    'SELECT builderad_activate_service_context(?::uuid, ?, ?::uuid)',
                    [$servicePublicId, $credential, $workspacePublicId],
                );

                $workspace = Workspace::organizationalQuery()->whereKey($workspaceId)->first();
                if (! $workspace) {
                    throw new AuthorizationException('Service Workspace authority is unavailable.');
                }
                DB::selectOne('SELECT builderad_record_service_workspace_access()');

                return $effect($workspace);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42501') {
                throw new AuthorizationException('Current service authority is required.', previous: $exception);
            }
            throw $exception;
        }
    }
}
