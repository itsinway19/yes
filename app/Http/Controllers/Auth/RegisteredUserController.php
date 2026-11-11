<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\AuthEntryPage;
use App\Security\IdentityAuthentication;
use App\Security\LifecycleAuthority;
use App\Security\RegistrationEmailProof;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): AuthEntryPage
    {
        $passwordRule = Rules\Password::defaults();
        $requirements = $passwordRule->appliedRules();

        return new AuthEntryPage('register', [
            'passwordPolicy' => [
                'min' => $requirements['min'],
                'max' => $requirements['max'],
                'browserRules' => $passwordRule->toPasswordRulesString(),
            ],
        ]);
    }

    public function emailState(Request $request, RegistrationEmailProof $proof): JsonResponse
    {
        return $this->proofResponse($proof->state($request));
    }

    public function sendCode(Request $request, RegistrationEmailProof $proof): JsonResponse
    {
        $this->normalizeEmail($request);
        $input = $request->validate(['email' => 'required|string|email|max:255'], [
            'email.*' => __('registration.invalid_email'),
        ]);

        return $this->proofResponse($proof->send($request, $input['email']));
    }

    public function verifyCode(Request $request, RegistrationEmailProof $proof): JsonResponse
    {
        $input = $request->validate([
            'attempt_id' => 'required|uuid',
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ], [
            'attempt_id.*' => __('registration.proof_required'),
            'code.*' => __('registration.invalid_code'),
        ]);

        return $this->proofResponse($proof->verify($request, $input['attempt_id'], $input['code']));
    }

    public function cancelProof(Request $request, RegistrationEmailProof $proof): JsonResponse
    {
        $input = $request->validate(['attempt_id' => 'required|uuid'], [
            'attempt_id.*' => __('registration.proof_required'),
        ]);

        return $this->proofResponse($proof->cancel($request, $input['attempt_id']));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, LifecycleAuthority $lifecycles, IdentityAuthentication $identity, RegistrationEmailProof $proof): JsonResponse
    {
        $passwordRule = Rules\Password::defaults();
        $requirements = $passwordRule->appliedRules();
        $this->normalizeEmail($request);
        $input = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => ['required', 'confirmed', $passwordRule],
            'attempt_id' => 'required|uuid',
        ], [
            'name.*' => __('registration.invalid_name'),
            'email.*' => __('registration.invalid_email'),
            'attempt_id.*' => __('registration.proof_required'),
            'password.required' => __('registration.password_required'),
            'password.min' => __('registration.password_min', ['min' => $requirements['min']]),
            'password.max' => __('registration.password_max', ['max' => $requirements['max']]),
            'password.confirmed' => __('registration.password_confirmation'),
            'password.password.uncompromised' => __('registration.password_breached'),
        ]);

        $user = null;
        $result = $identity->login($request, function () use ($request, &$user): void {
            Auth::login($user);
            $request->session()->regenerate();
        }, 'password', fn () => '/businesses/new', beforeAuthenticate: function (object $lineage) use ($request, $input, $lifecycles, $proof, &$user): void {
            $user = $proof->createAdvertiser($request, $lineage, $input, $lifecycles);
        });

        try {
            event(new Registered($user));
            event(new Verified($user));
        } catch (Throwable $exception) {
            Log::error('security_registration_notification_failed', [
                'error_class' => $exception::class,
                'error_code' => (string) $exception->getCode(),
            ]);
        }

        return $result;
    }

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
    }

    private function proofResponse(array $state): JsonResponse
    {
        return response()->json($state)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
