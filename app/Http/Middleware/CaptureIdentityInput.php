<?php

namespace App\Http\Middleware;

use App\Security\IdentityCookies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Capture after session loading, before CSRF rejection or any guard lookup. */
final class CaptureIdentityInput
{
    public function __construct(private IdentityCookies $cookies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard($request->is('internal/*') ? 'internal' : 'web');
        $recaller = $guard->getRecallerName();
        $request->cookies->remove($recaller);
        $this->cookies->capture($request, $recaller);

        return $next($request);
    }
}
