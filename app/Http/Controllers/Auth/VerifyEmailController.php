<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request, LifecycleAuthority $lifecycles, SessionAuthority $sessions): RedirectResponse
    {
        $result = $lifecycles->verifyPrimaryEmail($request->user());
        $request->user()->refresh();
        $sessions->rebindGeneration($request, $request->user(), $result['generation']);

        if ($result['newly_verified']) {
            event(new Verified($request->user()));
        }

        $pendingStaff = DB::table('principal_lifecycles')
            ->where('user_id', $request->user()->id)
            ->where('principal_class', 'staff')->where('status', 'pending')->exists();

        return $pendingStaff
            ? redirect()->route('staff.onboarding')
            : redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
