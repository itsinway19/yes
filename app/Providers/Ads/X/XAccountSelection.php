<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessMaterial;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/** Selected-account read verification only. No invented account currency, approval or privacy proof. */
final readonly class XAccountSelection
{
    public const BASIS = 'https://docs.x.com/x-ads-api/campaign-management/reference#authenticated-user-access';

    public function __construct(private Factory $http, private XOAuthSigner $signer, private \Closure $currentAuthority) {}

    public function verify(ProviderReadBudget $budget, #[\SensitiveParameter] ProviderCredentials $credentials): ProviderAccessMaterial
    {
        $scope = $budget->scope;
        if ($scope->provider !== Provider::X || $scope->operation !== 'report.read' || $scope->purpose !== 'advertising_analysis'
            || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $scope->accountId)
            || (isset($credentials->custodyPayload()['account_id']) && $credentials->value('account_id') !== $scope->accountId)) {
            throw new InvalidArgumentException('Exact selected X report account required.');
        }
        $path = 'accounts/'.$scope->accountId;
        $account = $this->get($path, $budget, $credentials);
        if (($account['id'] ?? null) !== $scope->accountId || ($account['deleted'] ?? null) !== false
            || ! in_array($account['timezone'] ?? null, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new RuntimeException('x_account_metadata_unverified');
        }
        $access = $this->get($path.'/authenticated_user_access', $budget, $credentials);
        $permissions = $access['permissions'] ?? null;
        if (! is_array($permissions) || ! array_is_list($permissions) || count($permissions) > 32) {
            throw new RuntimeException('x_report_permission_unverified');
        }
        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! preg_match('/^[A-Z][A-Z_]{0,79}$/D', $permission)) {
                throw new RuntimeException('x_report_permission_unverified');
            }
        }
        $roles = array_values(array_intersect(['CAMPAIGN_ANALYST', 'AD_MANAGER', 'ACCOUNT_ADMIN'], $permissions));
        if ($roles === []) {
            throw new RuntimeException('x_report_permission_unverified');
        }
        $at = new DateTimeImmutable;
        $proof = [];
        foreach (EffectiveCapability::AXES as $axis) {
            $proof[$axis] = ['state' => in_array($axis, ['credential', 'provider_permission'], true) ? 'VERIFIED' : 'UNKNOWN',
                'provenance' => self::BASIS, 'verified_at' => $at->format(DATE_ATOM), 'valid_until' => $at->modify('+5 minutes')->format(DATE_ATOM)];
        }

        return new ProviderAccessMaterial($scope, new ProviderCredentials([
            'oauth_token' => $credentials->value('oauth_token'), 'oauth_token_secret' => $credentials->value('oauth_token_secret'),
            'account_id' => $scope->accountId, 'account_timezone' => $account['timezone'], 'native_role' => $roles[0],
            'timezone_switch_at' => array_key_exists('timezone_switch_at', $account)
                && is_string($account['timezone_switch_at']) ? $account['timezone_switch_at'] : 'UNKNOWN',
        ]), null, 'CONNECTED', $proof);
    }

    private function get(string $path, ProviderReadBudget $budget, ProviderCredentials $credentials): array
    {
        $url = 'https://ads-api.x.com/12/'.$path;
        $header = $this->signer->header('GET', $url, [], $credentials);
        ($this->currentAuthority)($budget->scope, 'provider.connect');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->withHeaders(['Authorization' => $header])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false])->get($url);
            if ($response->status() !== 200) {
                throw new RuntimeException(match ($response->status()) {
                    401 => 'x_credential_denied', 403 => 'x_access_denied', 429 => 'x_rate_limited',
                    500, 502, 503, 504 => 'x_service_unavailable', default => 'x_account_verification_failed',
                });
            }
            if (strlen($response->body()) > 65536) {
                throw new RuntimeException('x_account_response_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || isset($body['errors']) || ($body['request']['params']['account_id'] ?? null) !== $budget->scope->accountId
                || ! is_array($body['data'] ?? null) || array_is_list($body['data'])) {
                throw new RuntimeException('x_account_response_unverified');
            }

            return $body['data'];
        } catch (ConnectionException) {
            throw new RuntimeException('x_transport_failed');
        } catch (JsonException) {
            throw new RuntimeException('x_account_response_unverified');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X selection signing context cannot be serialized.');
    }
}
