<?php

namespace App\Security;

use Illuminate\Cache\RedisStore;
use Illuminate\Http\Request;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Request-local publication, using the session handler's actual connection and prefix. */
final class IdentityRenewal
{
    /** A pre-upgrade cookie has only its previously published conservative bound. */
    public static function cookieDeadline(Request $request): int
    {
        $deadline = $request->session()->get('builderad.identity_cookie_deadline',
            $request->session()->get('builderad.identity_cookie_lease', 0));

        return is_int($deadline) ? $deadline : 0;
    }

    /** Observe availability, never touch the store or grant canonical authority. */
    public function remainingMilliseconds(Request $request): int
    {
        $remaining = self::cookieDeadline($request) - time();
        if (self::compatible($request)) {
            $store = $request->session()->getHandler()->getCache()->getStore();
            $remaining = min($remaining, (int) $store->connection()->ttl($store->getPrefix().$request->session()->getId()));
        }
        if ($remaining <= 0) {
            throw new IdentityTransitionConflict;
        }

        return $remaining * 1000;
    }

    public static function compatible(Request $request): bool
    {
        $handler = $request->session()->getHandler();

        return $request->attributes->get('builderad.renewal_backend') === 1
            && $handler instanceof CacheBasedSessionHandler
            && $handler->getCache()->getStore() instanceof RedisStore;
    }

    public function refresh(Request $request): int
    {
        if (! self::compatible($request) || ! $request->attributes->get('builderad.renewal_readonly')) {
            throw new HttpException(503, 'The configured session backend cannot renew without snapshot writes.');
        }
        $documents = app(IdentityDocument::class);
        $receipt = (string) $request->header('X-Builderad-Document');
        $documents->admit($request, $receipt);

        // Re-read through Laravel, including its configured serialization and
        // encryption, without saving or replacing any newer attributes.
        $stored = clone $request->session();
        $stored->flush();
        $stored->start();
        $guard = Auth::guard($documents->boundary($request) === 'internal' ? 'internal' : 'web');
        foreach (['_token', 'builderad.identity_lineage', 'builderad.identity_epoch', 'a001_generation',
            'builderad.identity_cookie_deadline', 'builderad.identity_cookie_lease', $guard->getName()] as $key) {
            if ($stored->get($key) !== $request->session()->get($key)) {
                throw new IdentityTransitionConflict;
            }
        }
        $configuredSeconds = (int) config('session.lifetime') * 60;
        if ($configuredSeconds <= 0) {
            throw new HttpException(503, 'A positive session lifetime is required.');
        }
        $seconds = min($configuredSeconds, self::cookieDeadline($request) - time());
        if ($seconds <= 0) {
            throw new IdentityTransitionConflict;
        }
        $cache = $request->session()->getHandler()->getCache();
        if (! $cache->touch($request->session()->getId(), $seconds)) {
            throw new IdentityTransitionConflict;
        }
        // Redis completion is not canonical authority. Recheck after the touch
        // and deny publication if ownership changed while the store was busy.
        $documents->admit($request, $receipt);
        if (! $cache->has($request->session()->getId())) {
            throw new IdentityTransitionConflict;
        }
        $request->attributes->set('builderad.identity_lease_milliseconds', $this->remainingMilliseconds($request));

        return $seconds;
    }
}
