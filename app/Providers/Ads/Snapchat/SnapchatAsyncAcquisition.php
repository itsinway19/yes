<?php

namespace App\Providers\Ads\Snapchat;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Encrypted native job correlation survives SQL rollback; it never represents accepted report data. */
final readonly class SnapchatAsyncAcquisition
{
    public function __construct(private Repository $cache) {}

    public function advance(array $binding, DateTimeImmutable $deadline, \Closure $advance): array
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore && ! ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new LogicException('Snapchat async creation requires an atomic shared Redis fence.');
        }
        $lease = $deadline->getTimestamp() - CarbonImmutable::now()->timestamp + 60;
        if ($lease <= 60 || $lease > 360) {
            throw new LogicException('Current bounded Snapchat acquisition deadline required.');
        }
        $key = 'bb2.snapchat.async.acquire.'.hash_hmac('sha256', json_encode($binding, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $store->lock($key.'.lock', 60)->block(2, function () use ($binding, $key, $lease, $advance): array {
            $cipher = $this->cache->get($key);
            $ticket = null;
            if ($cipher !== null) {
                try {
                    $record = json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR);
                    if (($record['binding'] ?? null) !== $binding || ! array_key_exists('ticket', $record)
                        || ($record['ticket'] !== null && ! is_array($record['ticket']))) {
                        throw new LogicException;
                    }
                    $ticket = $record['ticket'];
                } catch (\Throwable) {
                    throw new LogicException('Snapchat async acquisition integrity failed.');
                }
                if ($ticket === null) {
                    throw new SnapchatRequestFailed('snapchat_async_creation_uncertain');
                }
            } else {
                // Snapchat's async=true GET creates a remote job; ambiguous creation cannot be repeated.
                $this->save($key, $binding, null, $lease);
            }
            try {
                $next = $advance($ticket);
            } catch (SnapchatRequestFailed $failure) {
                if ($ticket === null && in_array($failure->reason, ['snapchat_rate_limited', 'snapchat_native_cooldown'], true)) {
                    $this->cache->forget($key);
                }
                throw $failure;
            }
            if (! is_array($next)) {
                throw new LogicException('Verified Snapchat async ticket required.');
            }
            $this->save($key, $binding, $next, $lease);

            return $next;
        });
    }

    private function save(string $key, array $binding, ?array $ticket, int $lease): void
    {
        if (! $this->cache->put($key, Crypt::encryptString(json_encode(['binding' => $binding, 'ticket' => $ticket], JSON_THROW_ON_ERROR)), $lease)) {
            throw new LogicException('Snapchat native ticket custody could not be saved.');
        }
    }
}
