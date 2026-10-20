<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Responses\SpaPage;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use App\Security\IdentityLifecycle;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redirect;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): SpaPage
    {
        if (! $request->expectsJson()) {
            $request->session()->keep(['errors', '_old_input', 'status']);
        }

        if (! SpaPage::needsData($request)) {
            return new SpaPage([]);
        }

        return new SpaPage([
            'user' => $request->user()->only(['name', 'email', 'email_verified_at']),
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'canChangeEmail' => DB::table('principal_lifecycles')
                ->where('user_id', $request->user()->id)
                ->where('principal_class', 'advertiser')->exists(),
            'status' => session('status'),
            'errors' => $request->session()->get('errors')?->getBag('default')->messages() ?? [],
            'old' => ['name' => $request->old('name'), 'email' => $request->old('email')],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, LifecycleAuthority $lifecycles, SessionAuthority $sessions): RedirectResponse|JsonResponse
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
            app(IdentityLifecycle::class)->expire($request);

            return Redirect::route('login')->with('status', __('security.email.reauthenticate'));
        }

        return $request->expectsJson()
            ? response()->json(['saved' => true])->header('Cache-Control', 'no-store')
            : Redirect::route('profile.edit');
    }
}
