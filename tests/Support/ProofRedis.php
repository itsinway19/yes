<?php

namespace Tests\Support;

final class ProofRedis
{
    public static function matchesConfiguration(): bool
    {
        $port = (int) getenv('REDIS_PORT');

        return ($port === 6397 || ($port >= 16397 && $port <= 16404))
            && (int) config('database.redis.default.port') === $port
            && config('database.redis.default.host') === '127.0.0.1'
            && ! config('database.redis.default.url');
    }
}
