<?php

use App\Http\Middleware\AdmitIdentityDocument;
use App\Http\Middleware\CaptureIdentityInput;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\SanitizeProviderCallback;
use App\Http\Middleware\SelectSessionContext;
use App\Http\Middleware\SpaPresentation;
use App\Http\Middleware\StartIdentitySession;
use App\Security\BreachCheckUnavailable;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(replace: [StartSession::class => StartIdentitySession::class]);
        $middleware->prependToPriorityList(StartSession::class, StartIdentitySession::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AdmitIdentityDocument::class);
        $middleware->prependToPriorityList(AdmitIdentityDocument::class, PreventRequestForgery::class);
        $middleware->prependToPriorityList(PreventRequestForgery::class, CaptureIdentityInput::class);
        $middleware->web(prepend: [SanitizeProviderCallback::class, SelectSessionContext::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('internal/*')
            ? route('internal.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('internal/*')
            ? route('internal.security.devices') : route('dashboard'));
        $middleware->web(append: [
            CaptureIdentityInput::class,
            AdmitIdentityDocument::class,
            ResolveLocale::class,
            SpaPresentation::class,
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
