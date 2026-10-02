<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Server-only discovery cursor custody. Opaque handles neither select accounts nor grant access. */
final readonly class XDiscoveryContinuation
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function stage(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $pending, NativeProgress $progress): string
    {
        $binding = $this->binding($request, $scope, $version, $configuration, $pending);
        $this->requireNative($progress);
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($handle);
        // Local correlation lease only; does not extend pending credentials or imply native token expiry.
        $cipher = Crypt::encryptString(json_encode(['binding' => $binding, 'expires_at' => now()->timestamp + 300,
            'native' => $progress->envelope()], JSON_THROW_ON_ERROR));
        DB::afterCommit(function () use ($key, $cipher): void {
            if (! $this->cache->add($key, $cipher, 300)) {
                throw new LogicException('X discovery continuation could not be staged.');
            }
        });

        return $handle;
    }

    public function restore(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $pending, #[\SensitiveParameter] string $handle): NativeProgress
    {
        $binding = $this->binding($request, $scope, $version, $configuration, $pending);
        $cipher = $this->cache->get($this->key($handle));
        try {
            if (! is_string($cipher) || strlen($cipher) > 65536) {
                throw new AuthorizationException;
            }
            $record = json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($record) || ($record['binding'] ?? null) !== $binding
                || ! is_int($record['expires_at'] ?? null) || $record['expires_at'] <= now()->timestamp
                || ! is_array($record['native'] ?? null)) {
                throw new AuthorizationException;
            }
            $native = $record['native'];
            $progress = new NativeProgress($native['kind'], $native['basis'], $native['payload']);
            $this->requireNative($progress);

            return $progress;
        } catch (\Throwable) {
            throw new AuthorizationException('X discovery continuation expired or changed.');
        }
    }

    private function binding(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $pending): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $pending)) {
            throw new AuthorizationException('Invalid X pending handle.');
        }
        $context = XCacheBinding::context($this->cache, $request, $scope, $version, $configuration);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $version);

        return [...$context, 'pending' => hash('sha256', $pending)];
    }

    private function requireNative(NativeProgress $progress): void
    {
        if ($progress->kind !== 'x.accounts.cursor' || $progress->basis !== XAccountDiscovery::BASIS) {
            throw new AuthorizationException('Exact X discovery continuation required.');
        }
        // The native adapter validates cursor syntax, cycle history and token-bound HMAC before HTTP.
    }

    private function key(#[\SensitiveParameter] string $handle): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
            throw new AuthorizationException('Invalid X continuation handle.');
        }

        return 'bb2.x.continuation.'.hash('sha256', $handle);
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'continuation' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X discovery continuation belongs to server custody.');
    }
}
