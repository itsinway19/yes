<?php

namespace App\Providers\Ads;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Per-native-request guard. Neither possession of credentials nor a previous check is authority. */
final class ProviderRequestGuard
{
    public function requireCurrent(Request $request, ProviderScope $scope, string $action, int $accessVersion): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('A native request requires one owned signed Workspace transaction.');
        }
        DB::selectOne('SELECT builderad_provider_request_gate(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->provider->value,
            $scope->accountId, $scope->operation, $scope->purpose, $action, $accessVersion,
        ]);
        if ($action === 'provider.read') {
            $verification = DB::table('provider_access')->where('workspace_id', $scope->workspaceId)->where('source_id', $scope->sourceId)
                ->where('provider', $scope->provider->value)->where('resource', $scope->accountId)->where('operation', $scope->operation)->where('purpose', $scope->purpose)->value('verification');
            app(ProviderReportingEvidence::class)->requireApplicable($scope, json_decode($verification, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function bind(Request $request, ProviderScope $scope, string $action, int $accessVersion): \Closure
    {
        return function (ProviderScope $requested, string $requestedAction) use ($request, $scope, $action, $accessVersion): void {
            if ($requested->jsonSerialize() !== $scope->jsonSerialize() || $requestedAction !== $action) {
                throw new LogicException('Native request cannot change its authorized scope or action.');
            }
            $this->requireCurrent($request, $scope, $action, $accessVersion);
        };
    }
}
