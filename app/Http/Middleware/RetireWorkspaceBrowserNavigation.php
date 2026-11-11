<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Business owns advertiser navigation; resource JSON readers keep their authority checks. */
final class RetireWorkspaceBrowserNavigation
{
    public function handle(Request $request, Closure $next): Response
    {
        // A qualified CSV download is a resource read, not a retired navigation page.
        if ($request->isMethod('GET') && ! $request->expectsJson() && $request->is('workspaces', 'workspaces/*')
            && ! $request->routeIs('workspaces.providers.google.report.download')) {
            return redirect('/businesses')->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
