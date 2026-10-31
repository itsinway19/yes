<?php

namespace App\Http\Middleware;

use App\Platform\PlatformMaintenance;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforcePlatformMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        // These are recovery/identity operations, not permission to use any
        // advertiser data. Existing route authority and CSRF still apply.
        if ($request->is('internal/*', 'up', 'locale', 'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*',
            'confirm-password', 'verify-email', 'verify-email/*', 'email/verification-notification',
            'identity/*', 'auth/passkey', 'auth/passkey/options', 'security/*', 'password')) {
            return $next($request);
        }
        try {
            $state = app(PlatformMaintenance::class)->current();
        } catch (QueryException|RecordsNotFoundException) {
            $state = ['active' => true, 'revision' => null, 'message_ar' => 'تعذر التحقق من إتاحة الخدمة. يرجى المحاولة بعد قليل.',
                'message_en' => 'Service availability could not be verified. Please try again shortly.', 'retry_after' => 60];
        }
        $request->attributes->set('platform.maintenance', $state);
        if (! $state['active'] || $request->is('/')) {
            return $next($request);
        }
        $locale = app()->getLocale();
        $message = $state['message_'.$locale];
        $response = $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => $message, 'code' => 'platform_maintenance', 'retry_after' => $state['retry_after']], 503)
            : response()->view('maintenance', compact('message', 'locale'), 503);

        return $response->header('Retry-After', (string) $state['retry_after'])->header('Cache-Control', 'no-store');
    }
}
