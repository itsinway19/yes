<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\AuthEntryPage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): AuthEntryPage
    {
        return new AuthEntryPage('forgot-password', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, Timebox $timebox): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $timebox->call(function () use ($request): void {
            $email = Str::lower($request->string('email')->toString());
            $user = User::query()->where('email', $email)->first();
            $activeStaff = $user && DB::table('principal_lifecycles')
                ->where('user_id', $user->id)->where('principal_class', 'staff')
                ->where('status', 'current')->exists();
            if ($user && ! $activeStaff) {
                try {
                    Password::sendResetLink(['email' => $email]);
                } catch (\Throwable $exception) {
                    Log::error('security_mail_enqueue_failed', [
                        'type' => 'password_reset', 'error_class' => $exception::class,
                        'error_code' => (string) $exception->getCode(),
                    ]);
                }
            }
        }, 200000);

        return back()->with('status', __('security.reset.requested'));
    }
}
