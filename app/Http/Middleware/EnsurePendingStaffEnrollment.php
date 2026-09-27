<?php

namespace App\Http\Middleware;

use App\Security\SessionAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePendingStaffEnrollment
{
    public function __construct(private readonly SessionAuthority $sessions) {}

    public function handle(Request $request, Closure $next, string $verified = 'false'): Response
    {
        $connection = $request->isMethod('GET') ? DB::connection() : DB::connection('pgsql_authority');

        return $connection->transaction(function () use ($request, $next, $verified): Response {
            $this->sessions->assertStaffEnrollment($request, $request->user());
            if ($verified === 'true' && ! $request->user()->hasVerifiedEmail()) {
                abort(403, 'Primary email verification is required before passkey enrollment.');
            }

            return $next($request);
        });
    }
}
