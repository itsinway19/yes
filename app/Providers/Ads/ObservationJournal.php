<?php

namespace App\Providers\Ads;

use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Internal durable acceptance boundary. Call inside exactly one signed Workspace transaction. */
final class ObservationJournal
{
    public function accept(Request $request, ObservationBatch $batch, string $stream, string $command,
        int $expectedGeneration, int $accessVersion, array $readProvenance, ?NativeProgress $progress = null): array
    {
        $this->requireTransaction();
        $this->validateStream($stream);
        if ($expectedGeneration < 0 || $accessVersion < 1 || count($readProvenance) !== 3
            || array_diff(array_keys($readProvenance), ['api_version', 'report', 'documentation_basis'])
            || ($progress !== null && $batch->coverage !== 'COMPLETE')) {
            throw new InvalidArgumentException('Invalid acceptance generation, provenance or unsafe progress.');
        }
        foreach ($readProvenance as $value) {
            if (! is_string($value) || $value === '' || strlen($value) > 512) {
                throw new InvalidArgumentException('Read provenance is required even for an empty report.');
            }
        }
        $envelope = $progress === null ? null : json_encode(['scope' => $batch->scope->jsonSerialize(),
            'stream' => $stream, 'access_version' => $accessVersion, 'native' => $progress->envelope()], JSON_THROW_ON_ERROR);
        $payload = ['coverage' => $batch->coverage, 'failure' => $batch->failure, 'provenance' => $readProvenance,
            'observations' => array_map(fn (AdObservation $o) => ['identity' => $o->identity(),
                'content_hash' => $o->contentHash(), 'observation' => $o->jsonSerialize()], $batch->observations),
            'encrypted_progress' => $envelope === null ? null : Crypt::encryptString($envelope),
            'progress_fingerprint' => $envelope === null ? null : hash_hmac('sha256', $envelope, (string) config('app.key'))];
        $result = DB::scalar('SELECT builderad_observation_accept(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?::uuid, ?, ?, ?::jsonb)', [
            $batch->scope->workspaceId, SessionAuthority::sessionKey($request), $batch->scope->sourceId,
            $batch->scope->provider->value, $batch->scope->accountId, $batch->scope->operation, $batch->scope->purpose,
            $stream, $command, $expectedGeneration, $accessVersion, json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }

    public function progress(Request $request, ProviderScope $scope, string $stream, int $accessVersion): ?NativeProgress
    {
        $this->requireTransaction();
        $this->validateStream($stream);
        $cipher = DB::scalar('SELECT builderad_observation_progress(?, ?, ?::uuid, ?, ?, ?, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->provider->value,
            $scope->accountId, $scope->operation, $scope->purpose, $stream, $accessVersion]);
        if ($cipher === null) {
            return null;
        }
        try {
            $envelope = json_decode(Crypt::decryptString($cipher), true, flags: JSON_THROW_ON_ERROR);
            if (($envelope['scope'] ?? null) !== $scope->jsonSerialize() || ($envelope['stream'] ?? null) !== $stream
                || ($envelope['access_version'] ?? null) !== $accessVersion) {
                throw new LogicException('Scope mismatch.');
            }

            return new NativeProgress($envelope['native']['kind'], $envelope['native']['basis'], $envelope['native']['payload']);
        } catch (\Throwable) {
            throw new AuthorizationException('Provider progress integrity could not be established.');
        }
    }

    /** Historical knowledge is guarded by current source.read; it never confers live provider authority. */
    public function history(Request $request, ProviderScope $scope, string $stream, int $limit = 50): array
    {
        $this->requireTransaction();
        $this->validateStream($stream);
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Bounded history projection is required.');
        }
        DB::selectOne('SELECT builderad_source_read_scope(?, ?, ?::uuid, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId,
            $scope->accountId, $scope->operation, $scope->purpose]);
        $namespace = DB::table('provider_observation_streams')->where('workspace_id', $scope->workspaceId)
            ->where('source_id', $scope->sourceId)->where('provider', $scope->provider->value)
            ->where('resource', $scope->accountId)->where('operation', $scope->operation)
            ->where('purpose', $scope->purpose)->where('stream', $stream)->value('namespace');
        if ($namespace === null) {
            return [];
        }

        return DB::table('provider_observation_entries as e')->join('provider_observation_runs as r', function ($join): void {
            $join->on('r.namespace', '=', 'e.namespace')->on('r.generation', '=', 'e.generation');
        })->where('e.namespace', $namespace)->orderByDesc('e.generation')->orderBy('e.identity')->limit($limit)
            ->get(['e.generation', 'e.identity', 'e.content_hash', 'e.observation', 'e.prior_generation',
                'e.relation', 'r.recorded_at', 'r.coverage', 'r.provenance'])->map(fn ($row) => [
                    'generation' => $row->generation, 'identity' => $row->identity, 'content_hash' => $row->content_hash,
                    'observation' => json_decode($row->observation, true, flags: JSON_THROW_ON_ERROR),
                    'prior_generation' => $row->prior_generation, 'relation' => $row->relation,
                    'recorded_at' => $row->recorded_at, 'coverage' => $row->coverage,
                    'read_provenance' => json_decode($row->provenance, true, flags: JSON_THROW_ON_ERROR),
                ])->all();
    }

    private function validateStream(string $stream): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $stream)) {
            throw new InvalidArgumentException('A stable adapter-owned stream definition identity is required.');
        }
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Observation acceptance requires its own Workspace transaction.');
        }
    }
}
