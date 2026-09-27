<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SelectSessionContext;
use App\Security\BreachCheckUnavailable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [SelectSessionContext::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('internal/*')
            ? route('internal.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('internal/*')
            ? route('internal.security.devices') : route('dashboard'));
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (BreachCheckUnavailable $exception, Request $request) {
            $message = 'تعذّر التحقق من أمان كلمة المرور مؤقتًا. احتفظ بكلمة المرور وحاول مجددًا بعد قليل.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            return back()->withErrors(['service' => $message]);
        });
    })->create();
