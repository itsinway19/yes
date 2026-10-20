<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final class AuthorityVerifyPasskey extends VerifyPasskey
{
    public function __invoke(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        ?PasskeyUser $user = null,
    ): Passkey {
        return DB::connection('pgsql_authority')->transaction(
            fn (): Passkey => parent::__invoke($credential, $options, $user),
        );
    }

    protected function validate(
        AuthenticatorAssertionResponse $response,
        Passkey $passkey,
        PublicKeyCredentialRequestOptions $options,
    ): CredentialRecord {
        try {
            return parent::validate($response, $passkey, $options);
        } catch (InvalidArgumentException $error) {
            // COSE rejects malformed signature encoding with an argument exception.
            // It is a failed assertion, never a successful or uncertain authentication.
            throw InvalidPasskeyException::make(__('login.passkey_invalid'));
        }
    }

    public function getPasskey(PublicKeyCredential $credential, bool $lock = false): Passkey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);
        $query = Passkeys::passkeyModel()::on('pgsql_authority')->where('credential_id', $credentialId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');
    }
}
