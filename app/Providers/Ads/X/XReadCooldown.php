<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Encrypted Workspace-local native quota hints, shared across streams; never grants authority. */
final readonly class XReadCooldown
{
    private string $tokenBinding;

    public function __construct(private Repository $cache, private ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials)
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore && ! ($store instanceof ArrayStore && app()->runningUnitTests()))
            || $scope->provider !== Provider::X || $scope->operation !== 'report.read' || $credentials->value('account_id') !== $scope->accountId) {
            throw new LogicException('X cooldown requires the exact selected account and atomic shared cache.');
        }
        $this->tokenBinding = hash_hmac('sha256', json_encode([$scope->workspaceId, $credentials->value('oauth_token'), $credentials->value('oauth_token_secret')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function key(string $endpoint, string $level): string
    {
        if (! in_array($endpoint, ['campaigns', 'sync_stats', 'async_stats'], true) || ! in_array($level, ['account', 'user'], true)) {
            throw new LogicException('Exact native X rate bucket required.');
        }

        return 'bb2.x.cooldown.'.hash('sha256', json_encode([$this->tokenBinding, $endpoint, $level, $level === 'account' ? $this->scope->accountId : null], JSON_THROW_ON_ERROR));
    }

    public function before(string $endpoint): void
    {
        $delay = 0;
        foreach (['account', 'user'] as $level) {
            $delay = max($delay, $this->remaining($this->key($endpoint, $level)));
        }
        if ($delay > 0) {
            throw new XRequestFailed('x_native_cooldown', $delay);
        }
    }

    private function remaining(string $key): int
    {
        $cipher = $this->cache->get($key);
        if ($cipher === null) {
            return 0;
        }
        try {
            $fact = json_decode(Crypt::decryptString($cipher), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($fact) || ($fact['key'] ?? null) !== $key || ! is_int($fact['until'] ?? null)) {
                throw new LogicException;
            }

            return max(0, $fact['until'] - CarbonImmutable::now()->timestamp);
        } catch (\Throwable) {
            throw new LogicException('X cooldown integrity could not be established.');
        }
    }

    public function observe(Response $response, string $endpoint): void
    {
        $fact = XRateLimit::exhausted($response, CarbonImmutable::now()->toDateTimeImmutable());
        if ($fact === null) {
            return;
        }
        $key = $this->key($endpoint, $fact['level']);
        $this->cache->getStore()->lock($key.'.observe', 10)->block(2, function () use ($key, $fact): void {
            if ($this->remaining($key) >= $fact['seconds']) {
                return;
            }
            if (! $this->cache->put($key, Crypt::encryptString(json_encode(['key' => $key, 'until' => $fact['until']], JSON_THROW_ON_ERROR)), $fact['seconds'])) {
                throw new LogicException('X native cooldown could not be recorded.');
            }
        });
    }

    public function __debugInfo(): array
    {
        return ['native_scheduling_hint' => true, 'cache' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X cooldown belongs to the server transport.');
    }
}
