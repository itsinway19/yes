<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** TikTok web correlation only. Native cursors remain server-side; handles confer no authority. */
final readonly class TikTokFlowState
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function issue(Request $request, ProviderScope $scope, int $accessVersion, string $configuration,
        string $kind, array $data): string
    {
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $accessVersion);

        return $this->publish($request, $scope, $accessVersion, $configuration, $kind, $data);
    }

    /** Pre-source button correlation only; the fresh-owner POST must establish actual authority. */
    public function issueConnectIntent(Request $request, ProviderScope $scope, string $configuration, array $data): string
    {
        if (DB::connection()->transactionLevel() !== 1 || $scope->operation !== 'discovery.read') {
            throw new LogicException('TikTok intent requires the signed Workspace transaction.');
        }

        return $this->publish($request, $scope, 0, $configuration, 'connect', $data);
    }

    private function publish(Request $request, ProviderScope $scope, int $accessVersion, string $configuration,
        string $kind, array $data): string
    {
        $binding = TikTokCacheBinding::context($this->cache, $request, $scope, $accessVersion, $configuration);
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($kind, $handle);
        $encoded = json_encode(['binding' => $binding, 'data' => $data, 'expires_at' => now()->timestamp + 300], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new LogicException('TikTok web correlation exceeds the local limit.');
        }
        $cipher = Crypt::encryptString($encoded);
        DB::afterCommit(function () use ($key, $cipher): void {
            if (! $this->cache->add($key, $cipher, 300)) {
                throw new LogicException('TikTok web correlation could not be stored.');
            }
        });

        return $handle;
    }

    public function load(Request $request, string $configuration, string $kind, #[\SensitiveParameter] string $handle): array
    {
        $record = $this->restore($request, $configuration, $kind, $handle);
        $this->requests->requireCurrent($request, $record['scope'], 'provider.connect', $record['access_version']);

        return $record;
    }

    public function loadConnectIntent(Request $request, string $configuration, #[\SensitiveParameter] string $handle): array
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('TikTok intent requires the signed Workspace transaction.');
        }

        return $this->restore($request, $configuration, 'connect', $handle);
    }

    private function restore(Request $request, string $configuration, string $kind, #[\SensitiveParameter] string $handle): array
    {
        $cipher = $this->cache->get($this->key($kind, $handle));
        try {
            $record = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR) : null;
            $s = $record['binding']['scope'] ?? [];
            if (($s['provider'] ?? null) !== 'tiktok') {
                throw new AuthorizationException;
            }
            $scope = ProviderScope::fromArray($s);
            $expected = TikTokCacheBinding::context($this->cache, $request, $scope, $record['binding']['access_version'], $configuration);
            if ($record['binding'] !== $expected || ! is_int($record['expires_at'] ?? null)
                || $record['expires_at'] <= now()->timestamp || ! is_array($record['data'] ?? null)) {
                throw new AuthorizationException;
            }
        } catch (\Throwable) {
            throw new AuthorizationException('TikTok web correlation expired or changed.');
        }

        return ['scope' => $scope, 'access_version' => $record['binding']['access_version'], 'data' => $record['data']];
    }

    private function key(string $kind, #[\SensitiveParameter] string $handle): string
    {
        if (! in_array($kind, ['connect', 'selection', 'discovery'], true)
            || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
            throw new AuthorizationException('Invalid TikTok web correlation.');
        }

        return 'bb2.tiktok.web.'.$kind.'.'.hash('sha256', $handle);
    }
}
