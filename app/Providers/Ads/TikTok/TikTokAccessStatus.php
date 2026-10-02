<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Current exact-scope metadata, including native denials newer than the current credential epoch. */
final class TikTokAccessStatus
{
    public function snapshot(Request $request, ProviderScope $scope): array
    {
        if (DB::connection()->transactionLevel() !== 1 || $scope->provider !== Provider::TikTok) {
            throw new LogicException('TikTok status requires a signed current Workspace transaction.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [$scope->workspaceId,
            SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId, $scope->operation, $scope->purpose]);
        $access = DB::table('provider_access')->where('workspace_id', $scope->workspaceId)->where('source_id', $scope->sourceId)
            ->where('provider', 'tiktok')->where('resource', $scope->accountId)->where('operation', $scope->operation)->where('purpose', $scope->purpose)
            ->first(['version', 'local_state', 'native_state', 'credential_expires_at', 'verification', 'recorded_at']);
        $axes = array_fill_keys(EffectiveCapability::AXES, 'UNKNOWN');
        if ($access === null) {
            return ['version' => 0, 'connection' => 'NOT_CONNECTED', 'native_observation' => null, 'axes' => $axes,
                'effective' => ['state' => 'UNKNOWN', 'reason' => 'connection_missing', 'last_verified_at' => null]];
        }
        $at = new DateTimeImmutable;
        $evidence = json_decode($access->verification, true, flags: JSON_THROW_ON_ERROR);
        $evidence = app(ProviderReportingEvidence::class)->applicable($scope, $evidence);
        foreach (EffectiveCapability::AXES as $axis) {
            $fact = $evidence[$axis] ?? null;
            try {
                if (! is_array($fact) || empty($fact['provenance']) || empty($fact['verified_at']) || empty($fact['valid_until'])) {
                    continue;
                }
                $observed = new DateTimeImmutable($fact['verified_at']);
                $until = new DateTimeImmutable($fact['valid_until']);
                if ($observed > $at || $until <= $observed) {
                    continue;
                }
                $state = $fact['state'] ?? 'UNKNOWN';
                $axes[$axis] = $until <= $at ? 'STALE'
                    : (in_array($state, ['VERIFIED', 'REVOKED', 'PARTIAL', 'FAILED', 'UNSUPPORTED', 'VERIFICATION_NEEDED'], true) ? $state : 'UNKNOWN');
            } catch (\Exception) {
                // Malformed proof cannot become usable permission or a healthy UI state.
            }
        }
        $connection = 'CONNECTED';
        $effective = (new EffectiveCapability)->derive('UNVERIFIED', true, $evidence, $at);
        if ($access->local_state === 'REVOKED') {
            $connection = 'LOCALLY_DISCONNECTED';
            $axes['credential'] = 'REVOKED';
            $effective = ['state' => 'REVOKED', 'reason' => 'local_access_revoked', 'last_verified_at' => null];
        } elseif ($access->credential_expires_at !== null && new DateTimeImmutable($access->credential_expires_at) <= $at) {
            $connection = 'REAUTHORIZATION_REQUIRED';
            $axes['credential'] = 'STALE';
            $effective = ['state' => 'STALE', 'reason' => 'native_credential_expired', 'last_verified_at' => null];
        } else {
            // Immutable denial facts survive failed/partial reads. Reconnect's new recorded_at excludes older epochs.
            $denial = DB::table('provider_observation_runs as r')->join('provider_observation_streams as s', 's.namespace', '=', 'r.namespace')
                ->where('s.workspace_id', $scope->workspaceId)->where('s.source_id', $scope->sourceId)->where('s.provider', 'tiktok')
                ->where('s.resource', $scope->accountId)->where('s.operation', $scope->operation)->where('s.purpose', $scope->purpose)
                ->where('r.recorded_at', '>=', $access->recorded_at)
                ->whereIn('r.failure', ['tiktok_credential_denied', 'tiktok_access_denied', 'tiktok_reauthorization_required'])->orderByDesc('r.recorded_at')->first(['r.failure', 'r.recorded_at']);
            if ($denial !== null) {
                $connection = 'VERIFICATION_NEEDED';
                $axis = $denial->failure === 'tiktok_access_denied' ? 'provider_permission' : 'credential';
                $axes[$axis] = 'FAILED';
                $effective = ['state' => 'VERIFICATION_NEEDED', 'reason' => $axis.'_native_denial', 'last_verified_at' => $denial->recorded_at];
            } elseif ($axes['credential'] !== 'VERIFIED' || $axes['provider_permission'] !== 'VERIFIED') {
                $connection = 'VERIFICATION_NEEDED';
            }
        }

        return ['version' => $access->version, 'connection' => $connection,
            'native_observation' => ['state' => $access->native_state, 'observed_at' => $access->recorded_at],
            'axes' => $axes, 'effective' => $effective];
    }

    public function requireReport(Request $request, ProviderScope $scope): void
    {
        $status = $this->snapshot($request, $scope);
        if ($status['connection'] !== 'CONNECTED') {
            throw new AuthorizationException('TikTok access must be reverified before another report request.');
        }
    }
}
