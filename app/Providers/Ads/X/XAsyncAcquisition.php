<?php

namespace App\Providers\Ads\X;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Technical side-effect fence survives SQL rollback; it is never accepted observation progress. */
final readonly class XAsyncAcquisition
{
    public function __construct(private Repository $cache) {}

    public function run(array $binding, DateTimeImmutable $deadline, \Closure $advance): array
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore && ! ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new LogicException('X native creation requires an atomic shared side-effect fence.');
        }
        $key = 'bb2.x.async.acquire.'.hash_hmac('sha256', json_encode($binding, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $lease = $deadline->getTimestamp() - CarbonImmutable::now()->timestamp + 60;
        if ($lease <= 60 || $lease > 360) {
            throw new LogicException('Current bounded X creation lease required.');
        }

        return $store->lock($key.'.lock', 60)->block(2, function () use ($key, $binding, $lease, $advance): array {
            $cipher = $this->cache->get($key);
            if ($cipher !== null) {
                try {
                    $record = json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR);
                    if (($record['binding'] ?? null) !== $binding || ! array_key_exists('ticket', $record)) {
                        throw new LogicException;
                    }
                } catch (\Throwable) {
                    throw new LogicException('X native acquisition integrity failed.');
                }
                if ($record['ticket'] === null) {
                    // Native POST may have happened even when the client never received its response.
                    throw new XRequestFailed('x_async_creation_uncertain');
                }

                return $advance($record['ticket'], null);
            }
            $this->save($key, $binding, null, $lease);
            $published = false;
            try {
                return $advance(null, function (array $ticket) use ($key, $binding, $lease, &$published): void {
                    $this->save($key, $binding, $ticket, $lease);
                    $published = true;
                });
            } catch (XRequestFailed $failure) {
                if (! $published && in_array($failure->reason, ['x_rate_limited', 'x_native_cooldown'], true)) {
                    // A verified throttle did not create a job; its native wait may be retried.
                    $this->cache->forget($key);
                }
                throw $failure;
            }
        });
    }

    private function save(string $key, array $binding, ?array $ticket, int $lease): void
    {
        if (! $this->cache->put($key, Crypt::encryptString(json_encode(['binding' => $binding, 'ticket' => $ticket], JSON_THROW_ON_ERROR)), $lease)) {
            throw new LogicException('X native side-effect fence could not be recorded.');
        }
    }
}
