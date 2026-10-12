<?php

namespace App\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ExpiredSecuritySession extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('Session expired or revoked.');
    }

    public function render(Request $request): Response
    {
        if ($request->attributes->has('builderad.identity_cookies')) {
            try {
                app(IdentityLifecycle::class)->expire($request);
            } catch (IdentityTransitionConflict) {
                // Another committed identity owns the lineage: retire no other input.
            }
            IdentityCookies::own($request, 'terminal');
            Inertia::clearHistory();
            $destination = $request->is('internal/*') ? '/internal/login' : '/login';
            if ($request->expectsJson()) {
                return response()->json(['message' => $this->getMessage(), 'identityConflict' => true,
                    'context' => $request->is('internal/*') ? '/internal/identity/context' : '/identity/context'], 409)
                    ->header('Cache-Control', 'no-store');
            }

            return redirect($destination)->header('Cache-Control', 'no-store');
        }
        // Clear this browser only, after the rejected transaction has unwound.
        $internal = $request->is('internal/*');
        Auth::guard($internal ? 'internal' : 'web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        $message = 'انتهت صلاحية جلستك أو تم تسجيل خروجها. سجّل الدخول مجددًا.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route($internal ? 'internal.login' : 'login')->with('status', $message);
    }
}
