<?php

namespace App\Http\Middleware;

use App\Security\IdentityLifecycle;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFreshAuthentication
{
    public function __construct(private readonly SessionAuthority $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sessions->hasFreshAuthentication($request, $request->user())) {
            if ($request->expectsJson()) {
                // Return through document admission so the rejection carries the
                // current server binding. A bare 403 cannot prove recoverability.
                return response()->json([
                    'message' => 'Fresh authentication is required.',
                    'code' => 'fresh_authentication_required',
                    'reauthenticate' => $request->is('internal/*') ? '/internal/identity/switch' : '/confirm-password',
                ], 403)->header('Cache-Control', 'no-store');
            }

            $staff = DB::table('principal_lifecycles')
                ->where('user_id', $request->user()->id)
                ->where('principal_class', 'staff')->exists();
            if ($staff) {
                DB::transaction(fn () => $this->sessions->revokeCurrent($request));
                app(IdentityLifecycle::class)->expire($request);

                return redirect()->route($request->is('internal/*') ? 'internal.login' : 'login')
                    ->with('status', __('security.session.reauthenticate'));
            }

            return redirect()->guest(route('password.confirm'));
        }

        return $next($request);
    }
}
