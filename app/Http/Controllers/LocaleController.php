<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $destination = $this->safeDestination($request);
        try {
            $locale = $request->validate(['locale' => ['required', Rule::in(['ar', 'en'])]])['locale'];
        } catch (ValidationException $exception) {
            $exception->redirectTo = $destination;
            throw $exception;
        }

        Cookie::queue(cookie(
            ResolveLocale::COOKIE,
            $locale,
            60 * 24 * 365,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return redirect()->to($destination);
    }

    private function safeDestination(Request $request): string
    {
        $internal = $request->is('internal/*');
        $destination = $internal ? route('internal.login', absolute: false) : route('login', absolute: false);
        $referer = $request->headers->get('referer');
        if ($referer && filter_var($referer, FILTER_VALIDATE_URL)) {
            $parts = parse_url($referer);
            $path = $parts['path'] ?? '/';
            $origin = isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                : '';
            $sameOrigin = strtolower($origin) === strtolower($request->getSchemeAndHttpHost());
            $internalPath = $path === '/internal' || str_starts_with($path, '/internal/');
            $sameContext = $internal ? $internalPath : ! $internalPath;
            if ($sameOrigin && $sameContext && ! str_starts_with($path, '//') && ! str_contains($path, '\\')) {
                $destination = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return $destination;
    }
}
