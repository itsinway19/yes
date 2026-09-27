<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SecurityMail;
use App\Notifications\SecurityNotice;
use App\Security\PasskeyCeremonyTrace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;

final class PasskeyManagementController extends Controller
{
    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        PasskeyCeremonyTrace::record($request, 'registration_options_requested');
        $options = $generate($request->user());
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));
        $request->session()->put('builderad.passkey_registration_issued_at', time());

        PasskeyCeremonyTrace::record($request, 'registration_options_sent');

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        PasskeyCeremonyTrace::record($request, 'registration_attestation_submitted');
        $issuedAt = (int) $request->session()->pull('builderad.passkey_registration_issued_at', 0);
        if ($issuedAt < time() - 60 || $issuedAt > time()) {
            PasskeyCeremonyTrace::record($request, 'registration_challenge_expired');
            throw ValidationException::withMessages(['credential' => 'انتهت صلاحية التسجيل. ابدأ من جديد.']);
        }

        $passkey = DB::connection('pgsql_authority')->transaction(function () use ($request, $store) {
            $user = User::on('pgsql_authority')->whereKey($request->user()->id)->sharedLock()->firstOrFail();
            $passkey = $store(
                $user,
                $request->string('name')->toString(),
                $request->credential(),
                $request->registrationOptions(),
            );
            $this->audit($request, 'passkey_registered');
            SecurityMail::afterCommit('passkey_added',
                fn () => $request->user()->notify(new SecurityNotice('passkey_added')));

            return $passkey;
        });

        PasskeyCeremonyTrace::record($request, 'registration_attestation_accepted');

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name], 201);
    }

    public function destroy(Request $request, Passkey $passkey, DeletePasskey $delete): RedirectResponse
    {
        DB::connection('pgsql_authority')->transaction(function () use ($request, $passkey, $delete): void {
            $user = User::on('pgsql_authority')->whereKey($request->user()->id)->sharedLock()->firstOrFail();
            $keys = $user->passkeys()->orderBy('id')->lockForUpdate()->get();
            $owned = $keys->firstWhere('id', $passkey->id);
            if (! $owned) {
                abort(404);
            }
            $personnel = DB::connection('pgsql_authority')->table('internal_personnel')
                ->where('user_id', $request->user()->id)->first();
            if ($personnel && $personnel->status === 'active' &&
                $keys->count() <= ($personnel->kind === 'founder' ? 2 : 1)) {
                throw ValidationException::withMessages(['passkey' => 'احتفظ بوسائل التوثيق المطلوبة للحساب الداخلي.']);
            }
            $delete($user, $owned);
            $this->audit($request, 'passkey_removed');
            SecurityMail::afterCommit('passkey_removed',
                fn () => $request->user()->notify(new SecurityNotice('passkey_removed')));
        });

        return back();
    }

    private function audit(Request $request, string $event): void
    {
        $generation = (int) $request->session()->get('a001_generation', 0);
        DB::connection('pgsql_authority')->table('security_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'subject_user_id' => $request->user()->id,
            'event' => $event,
            'reason' => 'authenticated_self_service',
            'prior_status' => 'current',
            'new_status' => 'current',
            'prior_generation' => $generation,
            'new_generation' => $generation,
            'occurred_at' => now(),
        ]);
    }
}
