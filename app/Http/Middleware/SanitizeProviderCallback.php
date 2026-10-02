<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response;

/** Request-local callback fields only; auth guest redirects must never see the raw query. */
final class SanitizeProviderCallback
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        $path = rtrim($request->getPathInfo(), '/');
        if (! in_array($path, ['/providers/meta/callback', '/providers/google/callback',
            '/providers/tiktok/callback', '/providers/snapchat/callback', '/providers/x/callback'], true)) {
            return $next($request);
        }
        $fields = $request->query->all();
        $capture = [];
        foreach (['code', 'auth_code', 'state', 'oauth_token', 'oauth_verifier', 'denied'] as $name) {
            if (array_key_exists($name, $fields)) {
                $capture[$name] = is_string($fields[$name]) && strlen($fields[$name]) <= 4096 ? $fields[$name] : null;
            }
        }
        if (array_key_exists('error', $fields)) {
            $capture['error'] = true;
        }
        unset($fields);
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', $request->getPathInfo());
        $request->attributes->set('bb2.callback_input', new ParameterBag($capture));
        unset($capture);
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } finally {
            $request->attributes->remove('bb2.callback_input');
        }
    }
}
