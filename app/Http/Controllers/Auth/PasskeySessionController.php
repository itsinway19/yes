<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\AuthorityVerifyPasskey;
use App\Security\GrantAuthority;
use App\Security\IdentityAuthentication;
use App\Security\PasskeyCeremonyTrace;
use App\Security\SessionAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

final class PasskeySessionController extends Controller
{
    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        PasskeyCeremonyTrace::record($request, 'login_options_requested');
        $options = $generate();
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));
        $request->session()->put('builderad.passkey_verification_issued_at', time());

        PasskeyCeremonyTrace::record($request, 'login_options_sent');

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyVerificationRequest $request, AuthorityVerifyPasskey $verify, IdentityAuthentication $identity): JsonResponse
    {
        $internal = $request->is('internal/*');
        PasskeyCeremonyTrace::record($request, 'login_assertion_submitted');
        $verificationOptions = null;
        $passkey = null;
        $lifecycle = null;

        try {
            $result = $identity->login($request, function () use ($request, $verify, &$verificationOptions, &$passkey, &$lifecycle, $internal): void {
                $issuedAt = (int) $request->session()->pull('builderad.passkey_verification_issued_at', 0);
                if ($issuedAt < time() - 60 || $issuedAt > time()) {
                    PasskeyCeremonyTrace::record($request, 'login_challenge_expired');
                    throw ValidationException::withMessages(['credential' => __('login.passkey_expired')]);
                }
                $verificationOptions = $request->verificationOptions();
                PasskeyCeremonyTrace::record($request, 'login_verification_started');
                $passkey = $verify($request->credential(), $verificationOptions);
                PasskeyCeremonyTrace::record($request, 'login_verification_completed');
                $user = $passkey->user;
                $lifecycle = DB::connection('pgsql_authority')->table('principal_lifecycles')
                    ->where('user_id', $user->id)->sharedLock()->first();
                if (! $lifecycle || $lifecycle->status !== 'current' || ! $user->hasVerifiedEmail() ||
                    $lifecycle->principal_class !== ($internal ? 'staff' : 'advertiser')) {
                    throw ValidationException::withMessages(['credential' => __('login.passkey_ineligible')]);
                }

                Auth::guard($internal ? 'internal' : 'web')->login($user);
                $request->session()->regenerate();
            }, $internal ? 'internal_passkey' : 'passkey', function () use ($request, $internal): string {
                $destination = $internal && ! app(GrantAuthority::class)->has(
                    $request->user(), (int) $request->session()->get('a001_generation', 0), 'staff.view',
                ) ? 'internal.security.devices' : ($internal ? 'internal.administration' : 'dashboard');

                return route($destination, absolute: false);
            }, function () use ($request, &$passkey, &$lifecycle): void {
                $user = $passkey->user;
                $authority = DB::connection('pgsql_authority');
                $sessionId = $authority->table('security_sessions')
                    ->where('session_key_hash', SessionAuthority::sessionKey($request))->value('id');
                $authority->table('security_audit_events')->insert([
                    'actor_user_id' => $user->id,
                    'subject_user_id' => $user->id,
                    'event' => 'passkey_login_verified',
                    'reason' => 'key:'.$passkey->id.';session:'.$sessionId.';uv:1',
                    'prior_status' => 'current', 'new_status' => 'current',
                    'prior_generation' => $lifecycle->generation,
                    'new_generation' => $lifecycle->generation,
                    'occurred_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($exception instanceof AuthenticatorResponseVerificationException) {
                PasskeyCeremonyTrace::record($request, 'login_assertion_rejected');
                $response = $request->credential()->response;
                $missingUv = $response instanceof AuthenticatorAssertionResponse
                    && ! $response->authenticatorData->isUserVerified();
                if (app()->environment('testing') && $response instanceof AuthenticatorAssertionResponse) {
                    Log::notice('passkey_assertion_rejected', [
                        'up_reported' => $response->authenticatorData->isUserPresent(),
                        'uv_reported' => $response->authenticatorData->isUserVerified(),
                        'rp_hash_matches' => hash_equals(
                            hash('sha256', Passkeys::relyingPartyId(), true),
                            $response->authenticatorData->rpIdHash,
                        ),
                        'origin_allowed' => in_array($response->clientDataJSON->origin, Passkeys::allowedOrigins(), true),
                        'origin_kind' => match ($response->clientDataJSON->origin) {
                            'http://localhost:8879' => 'localhost',
                            'http://127.0.0.1:8879' => 'loopback_ip',
                            default => 'other',
                        },
                        'challenge_matches' => hash_equals($verificationOptions->challenge, $response->clientDataJSON->challenge),
                        'library_rejected_uv' => $exception->getMessage() === 'User authentication required.',
                    ]);
                }

                return response()->json([
                    'message' => $missingUv && $exception->getMessage() === 'User authentication required.'
                        ? __('login.passkey_user_verification')
                        : __('login.passkey_invalid'),
                ], 422);
            }
            throw $exception;
        }

        PasskeyCeremonyTrace::record($request, 'login_assertion_accepted');

        return $result;
    }
}
