<?php

namespace App\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Throwable;

/** Orchestration of the existing guard/ceremony, not credential verification. */
final class IdentityAuthentication
{
    public function __construct(private IdentityTransition $transitions, private IdentityDocument $documents) {}

    public function login(Request $request, Closure $authenticate, string|Closure $method, Closure $destination, ?Closure $afterIssue = null): JsonResponse
    {
        [$captured, $locked] = $this->documents->transition($request,
            (string) ($request->header('X-Builderad-Transition') ?? $request->input('_identity_transition', '')));
        $mutating = false;
        try {
            $result = $this->transitions->login($request, $captured['lineage'], $captured['boundary'],
                $captured['epoch'], $captured['nonce'], function () use ($authenticate, &$mutating): void {
                    $mutating = true;
                    $authenticate();
                }, $method, $locked, $afterIssue);
        } catch (Throwable $error) {
            // A rejected/consumed precondition never reaches any guard mutation.
            if ($mutating && ! $request->attributes->get('builderad.identity_uncertain')) {
                Auth::guard($captured['boundary'] === 'internal' ? 'internal' : 'web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            throw $error;
        }
        if ($request->attributes->get('builderad.identity_replayed')) {
            throw new IdentityTransitionConflict;
        }
        IdentityCookies::own($request, 'transition');
        Inertia::clearHistory();

        return response()->json(['transition' => $result, 'identity' => $this->documents->fresh($request),
            'redirect' => $this->target($request, $destination())])->header('Cache-Control', 'no-store');
    }

    public function close(Request $request): JsonResponse
    {
        [$captured, $locked] = $this->documents->transition($request,
            (string) ($request->header('X-Builderad-Transition') ?? $request->input('_identity_transition', '')));
        $result = $this->transitions->close($request, $captured['lineage'], $captured['boundary'],
            $captured['epoch'], $captured['nonce'], 'logout', $locked);
        if ($request->attributes->get('builderad.identity_replayed')) {
            throw new IdentityTransitionConflict;
        }
        IdentityCookies::own($request, 'terminal');
        Inertia::clearHistory();

        return response()->json(['transition' => $result,
            'redirect' => $captured['boundary'] === 'internal' ? '/internal/login' : '/'])
            ->header('Cache-Control', 'no-store');
    }

    private function target(Request $request, string $target): string
    {
        if (str_contains($target, '\\') || preg_match('/[\x00-\x20\x7f]/', $target)) {
            throw new IdentityTransitionConflict;
        }
        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            $this->targetBoundary($request, $target);

            return $target;
        }
        $parsed = parse_url($target);
        if (! is_array($parsed) || isset($parsed['user']) || isset($parsed['pass'])
            || ($parsed['scheme'] ?? '') !== $request->getScheme()
            || ($parsed['host'] ?? '') !== $request->getHost()
            || ($parsed['port'] ?? ($request->isSecure() ? 443 : 80)) !== $request->getPort()) {
            throw new IdentityTransitionConflict;
        }

        $this->targetBoundary($request, $parsed['path'] ?? '/');

        return ($parsed['path'] ?? '/').(isset($parsed['query']) ? '?'.$parsed['query'] : '')
            .(isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '');
    }

    private function targetBoundary(Request $request, string $target): void
    {
        $internal = str_starts_with((string) parse_url($target, PHP_URL_PATH), '/internal/');
        if ($internal !== ($this->documents->boundary($request) === 'internal')) {
            throw new IdentityTransitionConflict;
        }
    }
}
