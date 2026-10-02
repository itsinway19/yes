<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\ProviderScope;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Native scheduling hint only. It cannot establish authority, quota capacity or accepted progress. */
final readonly class TikTokReadCooldown
{
    private string $key;

    public function __construct(private Repository $cache, Request $request, ProviderScope $scope,
        int $accessVersion, string $version)
    {
        TikTokCacheBinding::context($cache, $request, $scope, $accessVersion, hash('sha256', $version));
        $this->key = $scope->key('tiktok.cooldown:'.$accessVersion.':'.$version);
    }

    public function remaining(): ?int
    {
        try {
            $cipher = $this->cache->get($this->key);
            if ($cipher === null) {
                return null;
            }
            $fact = json_decode(Crypt::decryptString($cipher), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($fact) || ($fact['key'] ?? null) !== $this->key
                || ! is_int($fact['until'] ?? null)) {
                throw new LogicException('TikTok native cooldown integrity failed.');
            }

            return max(0, $fact['until'] - now()->timestamp);
        } catch (\Throwable $error) {
            throw new LogicException('TikTok native cooldown is unavailable.', previous: $error);
        }
    }

    public function observe(int $seconds): void
    {
        if ($seconds < 1 || $seconds > 31536000) {
            throw new LogicException('Bounded positive native delay required.');
        }
        try {
            $this->cache->getStore()->lock($this->key.'.observe', 10)->block(2, function () use ($seconds): void {
                $remaining = $this->remaining();
                if ($remaining !== null && $remaining >= $seconds) {
                    return;
                }
                $cipher = Crypt::encryptString(json_encode(['key' => $this->key, 'until' => now()->timestamp + $seconds], JSON_THROW_ON_ERROR));
                if (! $this->cache->put($this->key, $cipher, $seconds)) {
                    throw new LogicException('TikTok native cooldown could not be recorded.');
                }
            });
        } catch (\Throwable $error) {
            throw new LogicException('TikTok native cooldown could not be recorded.', previous: $error);
        }
    }

    public function __debugInfo(): array
    {
        return ['native_scheduling_hint' => true, 'cache' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('TikTok shared cooldown belongs to the current server transport.');
    }
}
