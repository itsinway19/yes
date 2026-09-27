<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use App\Security\SessionAuthority;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, SessionAuthority $sessions): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = DB::connection('pgsql_authority')->transaction(function () use ($request, $sessions): string {
            // The broker validates and deletes this token. Serialize both steps with
            // the credential change so concurrent submissions cannot both succeed.
            DB::connection('pgsql_authority')->table('password_reset_tokens')
                ->where('email', Str::lower($request->string('email')->toString()))
                ->lockForUpdate()->first();

            return Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user) use ($request, $sessions): void {
                    $activeStaff = DB::table('principal_lifecycles')
                        ->where('user_id', $user->id)
                        ->where('principal_class', 'staff')
                        ->where('status', 'current')->sharedLock()->exists();
                    if ($activeStaff) {
                        throw ValidationException::withMessages([
                            'email' => __('auth.failed'),
                        ]);
                    }
                    $user->setConnection('pgsql_authority')->forceFill([
                        'password' => Hash::make($request->password),
                        'remember_token' => Str::random(60),
                    ])->save();
                    $sessions->revokeAll($user);
                    DB::connection('pgsql_authority')->afterCommit(fn () => event(new PasswordReset($user)));
                    SecurityMail::afterCommit('password_changed',
                        fn () => $user->notify(new SecurityNotice('password_changed')));
                }
            );
        });

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
