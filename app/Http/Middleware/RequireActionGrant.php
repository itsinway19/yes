<?php

namespace App\Http\Middleware;

use App\Security\GrantAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActionGrant
{
    public function __construct(private readonly GrantAuthority $grants) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        if (! $this->grants->has(
            $request->user(),
            (int) $request->session()->get('a001_generation', 0),
            $action,
        )) {
            abort(403);
        }

        return $next($request);
    }
}
