<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'canChangeEmail' => DB::table('principal_lifecycles')
                ->where('user_id', $request->user()->id)
                ->where('principal_class', 'advertiser')->exists(),
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, LifecycleAuthority $lifecycles, SessionAuthority $sessions): RedirectResponse
    {
        $changed = DB::connection('pgsql_authority')->transaction(function () use ($request, $lifecycles, $sessions): bool {
            $oldEmail = $request->user()->email;
            $emailChanged = $lifecycles->changeProfile(
                $request,
                $request->validated('name'),
                $request->validated('email'),
            );
            if ($emailChanged) {
                $sessions->revokeAll($request->user(), 'primary_email_changed');
                $request->user()->refresh();
                SecurityMail::afterCommit('primary_email_changed',
                    fn () => Notification::route('mail', $oldEmail)
                        ->notify(new SecurityNotice('primary_email_changed')));
                SecurityMail::afterCommit('verify_email',
                    fn () => $request->user()->sendEmailVerificationNotification());
            }

            return $emailChanged;
        });
        if ($changed) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::clearHistory();

            return Redirect::route('login')->with('status', __('security.email.reauthenticate'));
        }

        return Redirect::route('profile.edit');
    }
}
