<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\AuthEntryPage;
use App\Security\IdentityAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): AuthEntryPage
    {
        return new AuthEntryPage('login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, IdentityAuthentication $identity): JsonResponse
    {
        $staffPending = false;
        try {
            return $identity->login($request, function () use ($request): void {
                $request->authenticate();
                $request->session()->regenerate();
            }, function () use ($request, &$staffPending): string {
                $staffPending = DB::connection('pgsql_authority')->table('principal_lifecycles')
                    ->where('user_id', $request->user()->id)->where('principal_class', 'staff')->where('status', 'pending')->exists();

                return $staffPending ? 'staff_enrollment' : 'password';
            }, function () use ($request, &$staffPending): string {
                return $staffPending ? route('staff.onboarding', absolute: false)
                    : (string) $request->session()->pull('url.intended', route('dashboard', absolute: false));
            });

        } catch (AuthorizationException) {
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, IdentityAuthentication $identity): JsonResponse
    {
        return $identity->close($request);
    }
}
