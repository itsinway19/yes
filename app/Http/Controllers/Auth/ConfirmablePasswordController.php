<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\AuthEntryPage;
use App\Security\SessionAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(Request $request): AuthEntryPage
    {
        $this->denyStaff($request);

        return new AuthEntryPage('confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $this->denyStaff($request);
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $sessions->markFreshPasswordAuthentication($request, $request->user());
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function denyStaff(Request $request): void
    {
        if (DB::table('principal_lifecycles')->where('user_id', $request->user()->id)
            ->where('principal_class', 'staff')->exists()) {
            abort(403);
        }
    }
}
