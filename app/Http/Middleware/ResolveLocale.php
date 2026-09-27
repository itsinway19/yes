<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveLocale
{
    public const COOKIE = 'builderad_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $preference = $request->cookie(self::COOKIE);
        app()->setLocale(in_array($preference, ['ar', 'en'], true) ? $preference : 'ar');

        return $next($request);
    }
}
