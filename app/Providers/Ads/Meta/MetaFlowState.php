<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Meta web-flow correlation. Server-issued identities and cursors stay encrypted; handles confer no authority. */
final readonly class MetaFlowState
{
    public function __construct(private Repository $cache) {}

    public function issue(Request $request, ProviderScope $scope, int $accessVersion, string $configuration,
        string $kind, array $data): string
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Meta web state requires the signed Workspace transaction.');
        }
        $binding = MetaCacheBinding::context($this->cache, $request, $scope, $accessVersion, $configuration);
        $handle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($kind, $handle);
        $encoded = json_encode(['binding' => $binding, 'data' => $data, 'expires_at' => now()->timestamp + 300], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new LogicException('Meta web correlation exceeds the local limit.');
        }
        $cipher = Crypt::encryptString($encoded);
        DB::afterCommit(function () use ($key, $cipher): void {
            if (! $this->cache->add($key, $cipher, 300)) {
                throw new LogicException('Meta web correlation could not be stored.');
            }
        });

        return $handle;
    }

    public function load(Request $request, string $configuration, string $kind, #[\SensitiveParameter] string $handle): array
    {
        $cipher = $this->cache->get($this->key($kind, $handle));
        try {
            $record = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR) : null;
            $s = $record['binding']['scope'] ?? [];
            if (($s['provider'] ?? null) !== 'meta') {
                throw new AuthorizationException;
            }
            $scope = ProviderScope::fromArray($s);
            $expected = MetaCacheBinding::context($this->cache, $request, $scope, $record['binding']['access_version'], $configuration);
            if ($record['binding'] !== $expected || ! is_int($record['expires_at'] ?? null)
                || $record['expires_at'] <= now()->timestamp || ! is_array($record['data'] ?? null)) {
                throw new AuthorizationException;
            }
        } catch (\Throwable) {
            throw new AuthorizationException('Meta web correlation expired or changed.');
        }

        return ['scope' => $scope, 'access_version' => $record['binding']['access_version'], 'data' => $record['data']];
    }

    private function key(string $kind, #[\SensitiveParameter] string $handle): string
    {
        if (! in_array($kind, ['connect', 'selection', 'discovery'], true) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
            throw new AuthorizationException('Invalid Meta web correlation.');
        }

        return 'bb2.meta.web.'.$kind.'.'.hash('sha256', $handle);
    }
}
