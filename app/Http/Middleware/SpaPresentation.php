<?php

namespace App\Http\Middleware;

use App\Security\ClientVisibility;
use App\Security\ExpiredSecuritySession;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SpaPresentation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->header('X-Builderad-Visibility') === '1') {
            $context = app(ClientVisibility::class)->describe($request);
            if (! $context['protected'] || ! hash_equals($context['context'], (string) $request->header('X-Builderad-Visibility-Context'))) {
                return response()->json(['message' => 'Presentation context changed.'], 409)->header('Cache-Control', 'no-store');
            }
            $request->attributes->set('builderad.visibility_probe', true);
            DB::transaction(function () use ($request): void {
                $session = DB::table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->first();
                if ($session?->auth_method === 'staff_enrollment') {
                    app(SessionAuthority::class)->assertStaffEnrollment($request, $request->user());
                } else {
                    $generation = (int) $request->session()->get('a001_generation', 0);
                    if (! DB::table('principal_lifecycles')->where('user_id', $request->user()->id)
                        ->where('generation', $generation)->where('status', 'current')->exists()) {
                        throw new ExpiredSecuritySession;
                    }
                    app(SessionAuthority::class)->assertCurrent($request, $request->user(), $generation);
                }
            });
        }
        if ($request->isMethod('GET') && $request->expectsJson()
            && ($request->header('X-Builderad-Passive') === '1' || $request->header('X-Builderad-Witness') === '1')) {
            $request->attributes->set('builderad.visibility_probe', true);
        }

        return $next($request);
    }
}
