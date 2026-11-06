<?php

namespace App\Providers\Ads;

use App\Security\SessionAuthority;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal verifier/custody boundary; no browser endpoint accepts credential or evidence assertions. */
final class ProviderAccessStore
{
    public function store(Request $request, ProviderScope $scope, string $command, int $expectedVersion,
        #[\SensitiveParameter] ProviderCredentials $credentials, ?DateTimeImmutable $expiresAt,
        string $nativeState, array $verification, bool $reconnect = false): array
    {
        $this->requireTransaction();
        $verification = app(ProviderReportingEvidence::class)->apply($scope, $verification);
        $envelope = ['scope' => $scope->jsonSerialize(), 'credentials' => $credentials->custodyPayload()];
        $encoded = json_encode($envelope, JSON_THROW_ON_ERROR);

        return $this->command($request, $scope, $command, $expectedVersion, $reconnect ? 'reconnect' : 'connect', [
            'encrypted_credentials' => Crypt::encryptString($encoded),
            'credential_fingerprint' => hash_hmac('sha256', $encoded, (string) config('app.key')),
            'expires_at' => $expiresAt?->format(DATE_ATOM), 'native_state' => $nativeState, 'verification' => (object) $verification,
        ]);
    }

    public function revoke(Request $request, ProviderScope $scope, string $command, int $expectedVersion): array
    {
        $this->requireTransaction();

        return $this->command($request, $scope, $command, $expectedVersion, 'revoke', (object) []);
    }

    public function credentials(Request $request, ProviderScope $scope): ProviderCredentials
    {
        $this->requireTransaction();
        app(ProviderRequestGuard::class)->requireCustody($request, $scope, 'provider.read');
        $cipher = DB::scalar('SELECT builderad_provider_credential(?, ?, ?::uuid, ?, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->provider->value,
            $scope->accountId, $scope->operation, $scope->purpose,
        ]);

        $verification = DB::table('provider_access')->where('workspace_id', $scope->workspaceId)->where('source_id', $scope->sourceId)
            ->where('provider', $scope->provider->value)->where('resource', $scope->accountId)->where('operation', $scope->operation)->where('purpose', $scope->purpose)->value('verification');
        app(ProviderReportingEvidence::class)->requireApplicable($scope, json_decode($verification, true, flags: JSON_THROW_ON_ERROR));

        return $this->decode($cipher, $scope);
    }

    /** Google-only refresh access under connect authority, including expired access tokens. */
    public function googleConnectionCredentials(Request $request, ProviderScope $scope, int $version): ProviderCredentials
    {
        $this->requireTransaction();
        if ($scope->provider !== Provider::Google) {
            throw new AuthorizationException('Google connection custody requires a Google source.');
        }
        app(ProviderRequestGuard::class)->requireCustody($request, $scope, 'provider.connect');
        $cipher = DB::scalar('SELECT builderad_google_connection_credential(?, ?, ?::uuid, ?, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId,
            $scope->accountId, $scope->operation, $scope->purpose, $version,
        ]);

        return $this->decode($cipher, $scope);
    }

    /** Snapchat-only refresh under current connect authority; report fetch gates remain unchanged. */
    public function snapchatConnectionCredentials(Request $request, ProviderScope $scope, int $version): ProviderCredentials
    {
        $this->requireTransaction();
        if ($scope->provider !== Provider::Snapchat) {
            throw new AuthorizationException('Snapchat connection custody requires a Snapchat source.');
        }
        app(ProviderRequestGuard::class)->requireCustody($request, $scope, 'provider.connect');
        $cipher = DB::scalar('SELECT builderad_snapchat_connection_credential(?, ?, ?::uuid, ?, ?, ?, ?)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId,
            $scope->accountId, $scope->operation, $scope->purpose, $version,
        ]);

        return $this->decode($cipher, $scope);
    }

    private function decode(string $cipher, ProviderScope $scope): ProviderCredentials
    {
        try {
            $envelope = json_decode(Crypt::decryptString($cipher), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ($envelope['scope'] ?? null) !== $scope->jsonSerialize()
                || ! is_array($envelope['credentials'] ?? null)) {
                throw new AuthorizationException('Provider credential scope mismatch.');
            }

            return new ProviderCredentials($envelope['credentials']);
        } catch (\Throwable) {
            // Do not attach an exception containing raw ciphertext/decoded credentials.
            throw new AuthorizationException('Provider credential integrity could not be established.');
        }
    }

    private function command(Request $request, ProviderScope $scope, string $command, int $version, string $effect, array|object $payload): array
    {
        app(ProviderRequestGuard::class)->requireCustody($request, $scope, $effect === 'revoke' ? 'provider.revoke' : 'provider.connect');
        $result = DB::scalar('SELECT builderad_provider_access_command(?, ?, ?::uuid, ?, ?, ?, ?, ?::uuid, ?, ?, ?::jsonb)', [
            $scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->provider->value,
            $scope->accountId, $scope->operation, $scope->purpose, $command, $version, $effect,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Provider access requires its own Workspace transaction.');
        }
    }
}
