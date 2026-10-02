<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Server-only acquired token staging. An opaque handle proves neither native account access nor readiness. */
final readonly class XPendingAccess
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function stage(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] ProviderCredentials $credentials): string
    {
        $binding = XCacheBinding::context($this->cache, $request, $scope, $version, $configuration);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $version);
        $this->requirePair($credentials);
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($handle);
        // Technical acquisition lease only. X user tokens have no native expiry/refresh field.
        $cipher = Crypt::encryptString(json_encode(['binding' => $binding, 'expires_at' => now()->timestamp + 300,
            'credentials' => $credentials->custodyPayload()], JSON_THROW_ON_ERROR));
        DB::afterCommit(function () use ($key, $cipher): void {
            if (! $this->cache->add($key, $cipher, 300)) {
                throw new LogicException('X pending credentials could not be staged.');
            }
        });

        return $handle;
    }

    public function credentials(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $handle): ProviderCredentials
    {
        $expected = XCacheBinding::context($this->cache, $request, $scope, $version, $configuration);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $version);
        $cipher = $this->cache->get($this->key($handle));
        try {
            $record = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR) : null;
            if (($record['binding'] ?? null) !== $expected || ! is_int($record['expires_at'] ?? null) || $record['expires_at'] <= now()->timestamp) {
                throw new AuthorizationException;
            }
            $credentials = new ProviderCredentials($record['credentials']);
            $this->requirePair($credentials);
        } catch (\Throwable) {
            throw new AuthorizationException('X pending credentials expired or changed.');
        }

        return $credentials;
    }

    /** Discard after successful selection/cancellation commit; failed selection keeps the original staging intact. */
    public function discard(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $handle): void
    {
        $this->credentials($request, $scope, $version, $configuration, $handle);
        $key = $this->key($handle);
        DB::afterCommit(fn () => $this->cache->forget($key));
    }

    private function requirePair(ProviderCredentials $credentials): void
    {
        if (array_keys($credentials->custodyPayload()) !== ['oauth_token', 'oauth_token_secret']) {
            throw new LogicException('Exact X user token pair required.');
        }
        foreach (['oauth_token', 'oauth_token_secret'] as $key) {
            if (preg_match('/[\x00-\x20\x7f]/', $credentials->value($key))) {
                throw new LogicException('Invalid X user token pair.');
            }
        }
    }

    private function key(#[\SensitiveParameter] string $handle): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
            throw new AuthorizationException('Invalid X pending handle.');
        }

        return 'bb2.x.pending.'.hash('sha256', $handle);
    }
}
