<?php

namespace Tests\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Real Laravel guard/store fixture, never registered as a production flow. */
final class IdentityTransitionFixture
{
    public static function request(?string $sessionId = null, string $boundary = 'advertiser', string $connection = 'default'): Request
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        config(['session.driver' => 'redis', 'session.connection' => $connection]);
        $store = app('session')->driver();
        $store->setId($sessionId);
        $store->start();
        $request = Request::create($boundary === 'internal' ? '/internal/p2a' : '/p2a', 'POST');
        $request->setLaravelSession($store);
        app()->instance('request', $request);
        $guard = $boundary === 'internal' ? 'internal' : 'web';
        Auth::shouldUse($guard);
        $request->setUserResolver(fn () => Auth::guard($guard)->user());

        return $request;
    }

    public static function authenticate(Request $request, string $email, string $boundary = 'advertiser'): void
    {
        if (! Auth::guard($boundary === 'internal' ? 'internal' : 'web')->attempt(['email' => $email, 'password' => 'password'])) {
            throw new AuthorizationException('Synthetic fixture credentials failed.');
        }
        $request->session()->regenerate(); // Same explicit second regeneration as the real controller.
    }
}
