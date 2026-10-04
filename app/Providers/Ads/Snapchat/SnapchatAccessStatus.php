<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderEvidenceValidity;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderReportPrerequisites;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Current exact-scope metadata, including native denials newer than the current credential epoch. */
final class SnapchatAccessStatus
{
    public function snapshot(Request $request, ProviderScope $scope): array
    {
        if (DB::connection()->transactionLevel() !== 1 || $scope->provider !== Provider::Snapchat) {
            throw new LogicException('Snapchat status requires a signed current Workspace transaction.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$scope->workspaceId,
            SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId, $scope->operation, $scope->purpose]);
        $access = DB::table('provider_access')->where('workspace_id', $scope->workspaceId)->where('source_id', $scope->sourceId)
            ->where('provider', 'snapchat')->where('resource', $scope->accountId)->where('operation', $scope->operation)->where('purpose', $scope->purpose)
            ->first(['version', 'local_state', 'native_state', 'credential_expires_at', 'verification', 'recorded_at']);
        $axes = array_fill_keys(EffectiveCapability::AXES, 'UNKNOWN');
        if ($access === null) {
            return ['version' => 0, 'connection' => 'NOT_CONNECTED', 'native_observation' => null, 'axes' => $axes,
                'reporting' => (new ProviderReportPrerequisites)->derive('NOT_CONNECTED', $axes),
                'effective' => ['state' => 'UNKNOWN', 'reason' => 'connection_missing', 'last_verified_at' => null]];
        }
        $at = new DateTimeImmutable;
        $evidence = json_decode($access->verification, true, flags: JSON_THROW_ON_ERROR);
        $evidence = app(ProviderReportingEvidence::class)->applicable($scope, $evidence);
        $axes = (new ProviderEvidenceValidity)->axes($evidence, $at, EffectiveCapability::AXES);
        $connection = 'CONNECTED';
        $effective = (new EffectiveCapability)->derive('UNVERIFIED', true, $evidence, $at);
        $renewal = DB::table('snapchat_renewal_failures')->where('workspace_id', $scope->workspaceId)
            ->where('source_id', $scope->sourceId)->where('resource', $scope->accountId)
            ->where('operation', $scope->operation)->where('purpose', $scope->purpose)
            ->where('access_version', $access->version)->orderByDesc('recorded_at')->first(['failure', 'recorded_at']);
        if ($access->local_state === 'REVOKED') {
            $connection = 'LOCALLY_DISCONNECTED';
            $axes['credential'] = 'REVOKED';
            $effective = ['state' => 'REVOKED', 'reason' => 'local_access_revoked', 'last_verified_at' => null];
        } elseif ($renewal !== null) {
            $reauthorize = in_array($renewal->failure, ['snapchat_reconnect_required', 'snapchat_credential_denied'], true);
            $connection = $reauthorize ? 'REAUTHORIZATION_REQUIRED' : 'VERIFICATION_NEEDED';
            $axes['credential'] = $reauthorize ? 'FAILED' : 'VERIFICATION_NEEDED';
            if ($renewal->failure === 'snapchat_access_denied') {
                $axes['provider_permission'] = 'FAILED';
            }
            $effective = ['state' => $connection, 'reason' => $renewal->failure, 'last_verified_at' => $renewal->recorded_at];
        } elseif ($access->credential_expires_at !== null && new DateTimeImmutable($access->credential_expires_at) <= $at) {
            $connection = 'REAUTHORIZATION_REQUIRED';
            $axes['credential'] = 'STALE';
            $effective = ['state' => 'STALE', 'reason' => 'native_credential_expired', 'last_verified_at' => null];
        } else {
            // Immutable denial facts survive failed/partial reads. Reconnect's new recorded_at excludes older epochs.
            $denial = DB::table('provider_observation_runs as r')->join('provider_observation_streams as s', 's.namespace', '=', 'r.namespace')
                ->where('s.workspace_id', $scope->workspaceId)->where('s.source_id', $scope->sourceId)->where('s.provider', 'snapchat')
                ->where('s.resource', $scope->accountId)->where('s.operation', $scope->operation)->where('s.purpose', $scope->purpose)
                ->where('r.recorded_at', '>=', $access->recorded_at)
                ->whereIn('r.failure', ['snapchat_credential_denied', 'snapchat_access_denied', 'snapchat_reconnect_required'])->orderByDesc('r.recorded_at')->first(['r.failure', 'r.recorded_at']);
            if ($denial !== null) {
                $connection = 'VERIFICATION_NEEDED';
                $axis = $denial->failure === 'snapchat_access_denied' ? 'provider_permission' : 'credential';
                $axes[$axis] = 'FAILED';
                $effective = ['state' => 'VERIFICATION_NEEDED', 'reason' => $axis.'_native_denial', 'last_verified_at' => $denial->recorded_at];
            } elseif ($axes['credential'] !== 'VERIFIED' || $axes['provider_permission'] !== 'VERIFIED') {
                $connection = 'VERIFICATION_NEEDED';
            }
        }

        return ['version' => $access->version, 'connection' => $connection,
            'native_observation' => ['state' => $access->native_state, 'observed_at' => $access->recorded_at],
            'axes' => $axes, 'effective' => $effective,
            'reporting' => (new ProviderReportPrerequisites)->derive($connection, $axes)];
    }

    public function requireReport(Request $request, ProviderScope $scope): void
    {
        $status = $this->snapshot($request, $scope);
        if ($status['connection'] !== 'CONNECTED') {
            throw new AuthorizationException('Snapchat access must be reverified before another report request.');
        }
    }
}
