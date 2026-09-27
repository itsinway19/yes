<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        $passwordRule = Rules\Password::defaults();
        $requirements = $passwordRule->appliedRules();

        return Inertia::render('Auth/Register', [
            'passwordPolicy' => [
                'min' => $requirements['min'],
                'max' => $requirements['max'],
                'browserRules' => $passwordRule->toPasswordRulesString(),
            ],
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, LifecycleAuthority $lifecycles, SessionAuthority $sessions): RedirectResponse
    {
        $passwordRule = Rules\Password::defaults();
        $requirements = $passwordRule->appliedRules();
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', $passwordRule],
        ], [
            'password.required' => 'أدخل كلمة مرور.',
            'password.min' => 'استخدم كلمة مرور من '.$requirements['min'].' حرفًا على الأقل.',
            'password.max' => 'يجب ألا تتجاوز كلمة المرور '.$requirements['max'].' حرفًا.',
            'password.confirmed' => 'كلمة المرور وتأكيدها غير متطابقين.',
            'password.password.uncompromised' => 'كلمة المرور معروفة في تسريبات البيانات. اختر كلمة مرور مختلفة.',
        ]);

        $user = DB::connection('pgsql_authority')->transaction(function () use ($request, $lifecycles): User {
            $user = User::on('pgsql_authority')->create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $lifecycles->registerPending($user);

            return $user;
        });

        try {
            event(new Registered($user));
        } catch (Throwable $exception) {
            Log::error('security_mail_enqueue_failed', [
                'type' => 'verify_email', 'error_class' => $exception::class,
                'error_code' => (string) $exception->getCode(),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $sessions->issue($request, $user);

        return redirect(route('verification.notice', absolute: false));
    }
}
