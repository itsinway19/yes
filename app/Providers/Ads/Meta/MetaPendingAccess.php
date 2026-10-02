<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\ProviderAccessMaterial;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Encrypted acquisition staging only; no browser token and no durable readiness/custody claim. */
final readonly class MetaPendingAccess
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function stage(Request $request, ProviderAccessMaterial $material, int $accessVersion, string $configuration): string
    {
        $binding = MetaCacheBinding::context($this->cache, $request, $material->scope, $accessVersion, $configuration);
        $this->requests->requireCurrent($request, $material->scope, 'provider.connect', $accessVersion);
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($handle);
        $payload = ['binding' => $binding, 'expires_at' => now()->timestamp + 300,
            'credentials' => $material->credentials->custodyPayload(), 'credential_expires_at' => $material->expiresAt?->format(DATE_ATOM),
            'native_state' => $material->nativeState, 'verification' => $material->verification];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new LogicException('Meta pending acquisition exceeds the local envelope limit.');
        }
        $ciphertext = Crypt::encryptString($encoded);
        DB::afterCommit(function () use ($key, $ciphertext): void {
            if (! $this->cache->add($key, $ciphertext, 300)) {
                throw new LogicException('Meta pending acquisition could not be staged.');
            }
        });

        return $handle;
    }

    /** Internal server use for discovery/explicit selection; every retrieval rechecks current connect authority. */
    public function material(Request $request, ProviderScope $scope, int $accessVersion,
        string $configuration, #[\SensitiveParameter] string $handle): ProviderAccessMaterial
    {
        $binding = MetaCacheBinding::context($this->cache, $request, $scope, $accessVersion, $configuration);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $accessVersion);
        $ciphertext = $this->cache->get($this->key($handle));
        if (! is_string($ciphertext)) {
            throw new AuthorizationException('Meta pending acquisition is unavailable.');
        }
        try {
            $payload = json_decode(Crypt::decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            throw new AuthorizationException('Meta pending acquisition is invalid.');
        }
        if (! is_array($payload) || ($payload['binding'] ?? null) !== $binding
            || ! is_int($payload['expires_at'] ?? null) || $payload['expires_at'] <= now()->timestamp) {
            throw new AuthorizationException('Meta pending acquisition binding expired or changed.');
        }
        $expiresAt = $payload['credential_expires_at'] === null ? null : new DateTimeImmutable($payload['credential_expires_at']);
        if ($expiresAt !== null && $expiresAt <= new DateTimeImmutable) {
            throw new AuthorizationException('Meta pending native credential expired.');
        }

        return new ProviderAccessMaterial($scope, new ProviderCredentials($payload['credentials']), $expiresAt,
            $payload['native_state'], $payload['verification']);
    }

    public function discard(Request $request, ProviderScope $scope, int $accessVersion,
        string $configuration, #[\SensitiveParameter] string $handle): void
    {
        $this->material($request, $scope, $accessVersion, $configuration, $handle);
        $key = $this->key($handle);
        DB::afterCommit(fn () => $this->cache->forget($key));
    }

    private function key(#[\SensitiveParameter] string $handle): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
            throw new AuthorizationException('Meta pending acquisition is invalid.');
        }

        return 'bb2.meta.pending.'.hash('sha256', $handle);
    }
}
