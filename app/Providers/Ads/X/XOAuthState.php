<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Ephemeral three-legged token correlation, not durable access or provider authority. */
final readonly class XOAuthState
{
    public function __construct(private Repository $cache, private ProviderRequestGuard $requests) {}

    public function issue(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] ProviderCredentials $temporary): void
    {
        $binding = $this->binding($request, $scope, $version, $configuration);
        $this->requests->requireCurrent($request, $scope, 'provider.connect', $version);
        $key = $this->key($temporary->value('oauth_token'));
        $credentials = $temporary->custodyPayload();
        if (array_keys($credentials) !== ['oauth_token', 'oauth_token_secret']
            || preg_match('/[\x00-\x20\x7f]/', $temporary->value('oauth_token_secret'))) {
            throw new LogicException('Exact X temporary token pair required.');
        }
        $workspace = DB::table('workspaces')->where('id', $scope->workspaceId)->value('public_id');
        $cipher = Crypt::encryptString(json_encode(['binding' => $binding, 'workspace' => $workspace,
            'credentials' => $credentials, 'expires_at' => now()->timestamp + 300], JSON_THROW_ON_ERROR));
        // A local five-minute correlation lease is not a native token expiry or legal retention policy.
        DB::afterCommit(function () use ($key, $cipher): void {
            if (! $this->cache->add($key, $cipher, 300)) {
                throw new LogicException('X authorization state could not be stored.');
            }
        });
    }

    /** Fixed callback routing only; no authority is granted and no secret is returned. */
    public function locate(Request $request, string $configuration, #[\SensitiveParameter] string $token): array
    {
        $record = $this->restore($request, $configuration, $token);

        return ['workspace' => $record['workspace'], 'scope' => $record['scope'], 'access_version' => $record['binding']['access_version']];
    }

    /** Consumption survives a later provider/database failure. Caller must exchange only after this returns. */
    public function consume(Request $request, ProviderScope $scope, int $version, string $configuration,
        #[\SensitiveParameter] string $token): ProviderCredentials
    {
        $expected = $this->binding($request, $scope, $version, $configuration);
        $key = $this->key($token);

        return $this->cache->getStore()->lock($key.'.consume', 10)->block(2, function () use ($request, $scope, $version, $configuration, $token, $expected, $key): ProviderCredentials {
            $record = $this->restore($request, $configuration, $token);
            if ($record['binding'] !== $expected) {
                throw new AuthorizationException('X callback scope changed.');
            }
            $this->requests->requireCurrent($request, $scope, 'provider.connect', $version);
            if (! $this->cache->forget($key)) {
                throw new AuthorizationException('X authorization state could not be consumed.');
            }

            return $record['credentials'];
        });
    }

    private function restore(Request $request, string $configuration, #[\SensitiveParameter] string $token): array
    {
        $cipher = $this->cache->get($this->key($token));
        try {
            $record = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR) : null;
            $s = $record['binding']['scope'] ?? [];
            if (($s['provider'] ?? null) !== 'x' || ! Str::isUuid($record['workspace'] ?? '')) {
                throw new AuthorizationException;
            }
            $scope = new ProviderScope($s['workspace_id'], $s['source_id'], Provider::X, $s['account_id'], $s['operation'], $s['purpose']);
            $binding = $this->binding($request, $scope, $record['binding']['access_version'], $configuration);
            $credentials = new ProviderCredentials($record['credentials']);
            if ($record['binding'] !== $binding || ! is_int($record['expires_at'] ?? null) || $record['expires_at'] <= now()->timestamp
                || array_keys($credentials->custodyPayload()) !== ['oauth_token', 'oauth_token_secret']
                || ! hash_equals($token, $credentials->value('oauth_token'))) {
                throw new AuthorizationException;
            }
        } catch (\Throwable) {
            throw new AuthorizationException('X callback token expired or changed.');
        }

        return ['workspace' => $record['workspace'], 'scope' => $scope, 'binding' => $binding, 'credentials' => $credentials];
    }

    private function binding(Request $request, ProviderScope $scope, int $version, string $configuration): array
    {
        return XCacheBinding::context($this->cache, $request, $scope, $version, $configuration);
    }

    private function key(#[\SensitiveParameter] string $token): string
    {
        if ($token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new AuthorizationException('Invalid X callback token.');
        }

        return 'bb2.x.oauth.'.hash('sha256', $token);
    }
}
