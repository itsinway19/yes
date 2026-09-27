<?php

namespace App\Http\Middleware;

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

        return $next($request);
    }
}
