<?php

namespace App\Providers\Ads\Meta;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Meta callback CSRF correlation only. A state token never grants source or provider authority. */
final readonly class MetaOAuthState
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function issue(Request $request, ProviderScope $scope, int $accessVersion, string $configurationBinding): string
    {
        $binding = $this->binding($request, $scope, $accessVersion, $configurationBinding);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $accessVersion);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = $this->key($state);
        $workspace = DB::table('workspaces')->where('id', $scope->workspaceId)->value('public_id');
        $envelope = Crypt::encryptString(json_encode(['binding' => $binding, 'workspace' => $workspace, 'expires_at' => now()->timestamp + 300], JSON_THROW_ON_ERROR));
        // Do not publish correlation for a source operation that later rolls back.
        DB::afterCommit(function () use ($key, $envelope): void {
            if (! $this->cache->add($key, $envelope, 300)) {
                throw new LogicException('Meta authorization state could not be stored.');
            }
        });

        return $state;
    }

    /** Locate the fixed callback's Workspace without consuming state or granting access. */
    public function locate(Request $request, string $configuration, #[\SensitiveParameter] string $state): array
    {
        $cipher = $this->cache->get($this->key($state));
        try {
            $record = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR) : null;
            $s = $record['binding']['scope'] ?? [];
            if (($s['provider'] ?? null) !== 'meta' || ! Str::isUuid($record['workspace'] ?? '')) {
                throw new AuthorizationException;
            }
            $scope = new ProviderScope($s['workspace_id'], $s['source_id'], Provider::Meta,
                $s['account_id'], $s['operation'], $s['purpose']);
            $binding = $this->binding($request, $scope, $record['binding']['access_version'], $configuration);
            if ($record['binding'] !== $binding || ! is_int($record['expires_at'] ?? null) || $record['expires_at'] <= now()->timestamp) {
                throw new AuthorizationException;
            }
        } catch (\Throwable) {
            throw new AuthorizationException('Meta callback routing state expired or changed.');
        }

        return ['workspace' => $record['workspace'], 'scope' => $scope, 'access_version' => $record['binding']['access_version']];
    }

    /** Consume before exchanging the code; cache consumption survives a later database/provider rollback. */
    public function consume(Request $request, ProviderScope $scope, int $accessVersion,
        string $configurationBinding, #[\SensitiveParameter] string $state): void
    {
        $binding = $this->binding($request, $scope, $accessVersion, $configurationBinding);
        $key = $this->key($state);
        $store = $this->cache->getStore();
        $store->lock($key.'.consume', 10)->block(2, function () use ($request, $scope, $accessVersion, $binding, $key): void {
            $ciphertext = $this->cache->get($key);
            if (! is_string($ciphertext)) {
                throw new AuthorizationException('Meta authorization state is unavailable.');
            }
            try {
                $envelope = json_decode(Crypt::decryptString($ciphertext), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Exception) {
                throw new AuthorizationException('Meta authorization state is invalid.');
            }
            if (! is_array($envelope) || ($envelope['binding'] ?? null) !== $binding
                || ! is_int($envelope['expires_at'] ?? null) || $envelope['expires_at'] <= now()->timestamp) {
                throw new AuthorizationException('Meta authorization state binding expired or changed.');
            }
            $this->requests->requireCurrent($request, $scope, 'provider.connect', $accessVersion);
            if (! $this->cache->forget($key)) {
                throw new AuthorizationException('Meta authorization state could not be consumed.');
            }
        });
    }

    private function binding(Request $request, ProviderScope $scope, int $accessVersion, string $configuration): array
    {
        return MetaCacheBinding::context($this->cache, $request, $scope, $accessVersion, $configuration);
    }

    private function key(#[\SensitiveParameter] string $state): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)) {
            throw new AuthorizationException('Meta authorization state is invalid.');
        }

        return 'bb2.meta.oauth.'.hash('sha256', $state);
    }
}
