<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use App\Security\SessionAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        DB::connection('pgsql_authority')->transaction(function () use ($request, $validated, $sessions): void {
            $user = $request->user()->setConnection('pgsql_authority')->newQuery()->whereKey($request->user()->id)
                ->lockForUpdate()->firstOrFail();
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('auth.password'),
                ]);
            }
            $user->update(['password' => Hash::make($validated['password'])]);
            $sessions->revokeOthers($request, $user);
            SecurityMail::afterCommit('password_changed',
                fn () => $user->notify(new SecurityNotice('password_changed')));
        });

        return back();
    }
}
