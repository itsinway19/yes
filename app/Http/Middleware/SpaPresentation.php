<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SpaPresentation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->expectsJson()
            && ($request->header('X-Builderad-Passive') === '1' || $request->header('X-Builderad-Witness') === '1')) {
            $request->attributes->set('builderad.visibility_probe', true);
        }

        return $next($request);
    }
}
