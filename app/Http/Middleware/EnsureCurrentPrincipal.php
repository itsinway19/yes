<?php

namespace App\Http\Middleware;

use App\Security\ExpiredSecuritySession;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentPrincipal
{
    public function __construct(
        private readonly LifecycleAuthority $lifecycles,
        private readonly SessionAuthority $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $user->hasVerifiedEmail()) {
            if ($request->expectsJson() || $request->is('internal/*')) {
                abort(403);
            }

            return redirect()->route('verification.notice');
        }

        $authorize = function () use ($request, $user): void {
            $generation = (int) $request->session()->get('a001_generation', 0);
            if (! $this->lifecycles->isCurrent($user, $generation)) {
                $currentGeneration = DB::table('principal_lifecycles')
                    ->where('user_id', $user->getKey())->value('generation');
                if ($currentGeneration !== null && (int) $currentGeneration !== $generation) {
                    throw new ExpiredSecuritySession;
                }
                abort(403);
            }
            $this->sessions->assertCurrent($request, $user, $generation);
        };

        // WorkspaceContext checks lifecycle and session inside its own outer RLS transaction.
        if ($request->routeIs('workspaces.foundation', 'workspaces.show', 'workspaces.business.*', 'workspaces.object-purposes.*', 'workspaces.services.*', 'workspaces.sources.*',
            'workspaces.providers.meta.*', 'providers.meta.callback', 'workspaces.providers.google.*', 'providers.google.callback',
            'workspaces.providers.tiktok.*', 'providers.tiktok.callback', 'workspaces.providers.snapchat.*', 'providers.snapchat.callback',
            'workspaces.providers.x.*', 'providers.x.callback')) {
            return $next($request);
        }

        // Business configuration owns its signed runtime transaction and locks
        // current staff/session/grant evidence in its SQL writer. Do not hold an
        // authority session UPDATE lock while that writer takes its SHARE lock.
        if ($request->routeIs('profile.update') || (! $request->isMethod('GET') && $request->routeIs('internal.business-types.*', 'internal.customers.*'))) {
            DB::transaction($authorize);

            return $next($request);
        }

        if (! $request->isMethod('GET') && $request->routeIs('internal.*', 'security.*')) {
            return DB::connection('pgsql_authority')->transaction(function () use ($request, $next, $user): Response {
                $this->sessions->assertCurrentAuthority(
                    $request, $user, (int) $request->session()->get('a001_generation', 0),
                );

                return $next($request);
            });
        }

        return DB::transaction(function () use ($request, $next, $authorize): Response {
            $authorize();

            return $next($request);
        });
    }
}
