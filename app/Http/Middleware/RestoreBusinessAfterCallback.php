<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One-use presentation restoration. Workspace authorization still runs on the restored GET. */
final class RestoreBusinessAfterCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $location = $response->headers->get('Location');
        if ($response->isRedirection() && is_string($location)) {
            $path = parse_url($location, PHP_URL_PATH);
            $host = parse_url($location, PHP_URL_HOST);
            if (($host === null || $host === $request->getHost()) && is_string($path) &&
                preg_match('#^/workspaces/([a-f0-9-]{36})$#D', $path, $matches)) {
                $request->session()->put('business.callback_resume', ['workspace' => $matches[1], 'expires_at' => time() + 60]);
            }
        }
        return $response;
    }
}
