<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, SessionAuthority $sessions): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $staffPending = DB::table('principal_lifecycles')->where('user_id', $request->user()->id)
            ->where('principal_class', 'staff')->where('status', 'pending')->exists();
        try {
            DB::transaction(fn () => $sessions->issue(
                $request, $request->user(), $staffPending ? 'staff_enrollment' : 'password',
            ));
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::clearHistory();
            if ($exception instanceof AuthorizationException) {
                throw ValidationException::withMessages(['email' => trans('auth.failed')]);
            }
            throw $exception;
        }

        Inertia::clearHistory();

        return $staffPending
            ? redirect()->route('staff.onboarding')
            : redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $sessions->revokeCurrent($request);
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return redirect('/');
    }
}
