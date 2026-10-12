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
        foreach (['_token', 'builderad.identity_lineage', 'builderad.identity_epoch', 'a001_generation', $guard->getName()] as $key) {
            if ($stored->get($key) !== $request->session()->get($key)) {
                throw new IdentityTransitionConflict;
            }
        }
        $seconds = (int) config('session.lifetime') * 60;
        if ($seconds <= 0) {
            throw new HttpException(503, 'A positive session lifetime is required.');
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
        $request->attributes->set('builderad.identity_lease_milliseconds', $seconds * 1000);

        return $seconds;
    }
}
