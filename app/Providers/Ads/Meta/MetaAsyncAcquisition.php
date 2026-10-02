<?php

namespace App\Providers\Ads\Meta;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/** Native report creation fence survives SQL rollback; it never grants access or accepts progress. */
final readonly class MetaAsyncAcquisition
{
    public function __construct(private Repository $cache) {}

    public function acquire(array $binding, DateTimeImmutable $deadline, \Closure $create): MetaReportTask
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider || (! $store instanceof RedisStore && ! ($store instanceof ArrayStore && app()->runningUnitTests()))) {
            throw new LogicException('Meta report creation requires an atomic shared Redis fence.');
        }
        $lease = $deadline->getTimestamp() - CarbonImmutable::now()->timestamp + 60;
        if ($lease <= 60 || $lease > 360) {
            throw new LogicException('Current bounded Meta creation deadline required.');
        }
        $key = 'bb2.meta.async.acquire.'.hash_hmac('sha256', json_encode($binding, JSON_THROW_ON_ERROR), (string) config('app.key'));

        return $store->lock($key.'.lock', 60)->block(2, function () use ($binding, $key, $lease, $create): MetaReportTask {
            $cipher = $this->cache->get($key);
            if ($cipher !== null) {
                try {
                    $record = json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR);
                    if (($record['binding'] ?? null) !== $binding || ! array_key_exists('task', $record)) {
                        throw new LogicException;
                    }
                    $task = $record['task'];
                    if ($task !== null) {
                        return new MetaReportTask($task['id'], $task['fingerprint'], new DateTimeImmutable($task['created_at']));
                    }
                } catch (\Throwable) {
                    throw new LogicException('Meta report acquisition integrity failed.');
                }
                throw new MetaRequestFailed('meta_async_creation_uncertain');
            }
            // Persist uncertainty before the POST: a lost response must not cause another remote job.
            $this->save($key, $binding, null, $lease);
            try {
                $task = $create();
            } catch (MetaRequestFailed $failure) {
                if ($failure->reason === 'meta_rate_limited') {
                    // Explicit native throttling or local cooldown did not create a report.
                    $this->cache->forget($key);
                }
                throw $failure;
            }
            if (! $task instanceof MetaReportTask) {
                throw new LogicException('Verified native Meta report identity required.');
            }
            $this->save($key, $binding, $task, $lease);

            return $task;
        });
    }

    private function save(string $key, array $binding, ?MetaReportTask $task, int $lease): void
    {
        $payload = ['binding' => $binding, 'task' => $task === null ? null : ['id' => $task->id,
            'fingerprint' => $task->requestFingerprint, 'created_at' => $task->createdAt->format(DATE_ATOM)]];
        if (! $this->cache->put($key, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), $lease)) {
            throw new LogicException('Meta native creation fence could not be saved.');
        }
    }
}
