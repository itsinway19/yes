<?php

namespace App\Http\Middleware;

use App\Security\IdentityCookies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SelectSessionContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $internal = $request->is('internal/*');
        $cookie = $internal
            ? config('session.internal_cookie')
            : config('session.advertiser_cookie');
        $path = $internal ? '/internal' : '/';

        if (config('session.cookie') !== $cookie) {
            config()->set('session.cookie', $cookie);
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            Auth::forgetGuards();
        }

        config()->set('session.path', $path);

        Auth::shouldUse($internal ? 'internal' : 'web');

        $domain = config('session.domain');
        $response = $next($request);

        if ($request->attributes->has('builderad.identity_cookies')) {
            return app(IdentityCookies::class)->finish($request, $response);
        }

        // The logout request may belong to an older browser session. Do not let
        // its response replace a newer login's cookie. Server revocation and
        // session persistence still run; unrelated cookies keep their owners.
        if ($request->isMethod('POST') && $request->is('logout')) {
            $response->headers->removeCookie($cookie, $path, $domain);
        }

        return $response;
    }
}
