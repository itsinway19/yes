<?php

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\CacheBasedSessionHandler;

// Used only by the loopback PHP development server, never by public/index.php.
$root = dirname(__DIR__);
$public = $root.DIRECTORY_SEPARATOR.'public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri === '/__builderad_local_preview_status') {
    $private = $root.'/storage/app/retirement-preview/private';
    $token = is_file($private.'/probe-token') ? file_get_contents($private.'/probe-token') : '';
    if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || $token === '' || ! hash_equals($token, $_SERVER['HTTP_X_BUILDERAD_PREVIEW_PROBE'] ?? '')) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        define('LARAVEL_START', microtime(true));
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $expected = json_decode(file_get_contents($private.'/environment.json'), true, flags: JSON_THROW_ON_ERROR);
        $mismatch = [];
        foreach ($expected as $key => $value) {
            if ((string) getenv($key) !== (string) $value) {
                $mismatch[] = $key;
            }
        }
        $actual = ['APP_KEY' => config('app.key'), 'APP_URL' => config('app.url'), 'APP_ENV' => config('app.env'),
            'PASSKEYS_USER_HANDLE_SECRET' => config('passkeys.user_handle_secret'),
            'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'DB_AUTHORITY_PASSWORD' => config('database.connections.pgsql_authority.password'), 'DB_OWNER_PASSWORD' => config('database.connections.pgsql_migrations.password'),
            'SESSION_DRIVER' => config('session.driver'), 'SESSION_STORE' => config('session.store'), 'SESSION_CONNECTION' => config('session.connection'),
            'SESSION_COOKIE' => config('session.cookie'), 'INTERNAL_SESSION_COOKIE' => config('session.internal_cookie'),
            'REDIS_HOST' => config('database.redis.default.host'), 'REDIS_PORT' => config('database.redis.default.port'),
            'REDIS_PREFIX' => config('database.redis.options.prefix'), 'CACHE_STORE' => config('cache.default'), 'QUEUE_CONNECTION' => config('queue.default')];
        foreach ($actual as $key => $value) {
            if ((string) $value !== (string) ($expected[$key] ?? '')) {
                $mismatch[] = $key;
            }
        }
        $roles = [];
        foreach (['pgsql' => 'builderad_app', 'pgsql_authority' => 'builderad_authority', 'pgsql_migrations' => 'builderad_owner'] as $name => $role) {
            $connection = $app['db']->connection($name);
            if ($connection->getConfig('database') !== 'builderad_dev' || (string) $connection->getConfig('port') !== '5432'
                || $connection->getConfig('host') !== '127.0.0.1') {
                throw new RuntimeException('Unexpected database target');
            }
            $roles[$name] = $connection->transaction(function () use ($connection) {
                $connection->statement('SET TRANSACTION READ ONLY');

                return $connection->selectOne('SELECT current_user AS role,current_database() AS database,inet_server_addr()::text AS host,inet_server_port() AS port');
            });
            if ($roles[$name]->role !== $role || $roles[$name]->database !== 'builderad_dev' || (int) $roles[$name]->port !== 5432) {
                throw new RuntimeException('Unexpected database identity');
            }
        }
        $redis = $app['redis']->connection();
        $redis->ping();
        $app['redis']->connection('cache')->ping();
        $info = $redis->client()->executeRaw(['INFO', 'server']);
        preg_match('/^process_id:(\d+)/m', $info, $redisPid);
        $session = $app['session']->driver()->getHandler();
        $stores = $session instanceof CacheBasedSessionHandler
            && $session->getCache()->getStore() instanceof RedisStore
            && $app['cache']->store()->getStore() instanceof RedisStore;
        $rp = config('passkeys.relying_party_id');
        $origins = config('passkeys.allowed_origins');
        $valid = $mismatch === [] && $stores && $rp === 'localhost' && $origins === ['http://localhost:8038'];
        http_response_code($valid ? 200 : 409);
        // Only safe operational facts; no session creation, payload writes, credentials or key digests.
        echo json_encode(['protocol' => 'stable-preview-v1', 'verified' => $valid, 'pid' => getmypid(),
            'root' => $app->basePath(), 'public' => $public, 'mismatch_keys' => array_values(array_unique($mismatch)),
            'database' => $roles, 'redis' => ['host' => config('database.redis.default.host'), 'port' => (int) config('database.redis.default.port'), 'pid' => (int) ($redisPid[1] ?? 0), 'ping' => true],
            'redis_stores' => $stores, 'queue' => config('queue.default'), 'rp_id' => $rp, 'origins' => $origins], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo '{"protocol":"stable-preview-v1","verified":false,"error":"runtime_verification_failed"}';
    }
    exit;
}
$file = realpath($public.$uri);
if ($uri !== '/' && $file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $public.DIRECTORY_SEPARATOR.'index.php';
