<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;

/** Test-only controller boundary; never registered by the application. */
final class ProofRequestTiming
{
    public function handle(Request $request, Closure $next): mixed
    {
        $mark = $GLOBALS['proofRequestMark'];
        $mark('controller_enter');
        try {
            return $next($request);
        } finally {
            $mark('controller_exit');
        }
    }
}
