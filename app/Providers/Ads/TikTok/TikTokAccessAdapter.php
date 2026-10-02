<?php

namespace App\Providers\Ads\TikTok;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessAdapter;
use App\Providers\Ads\ProviderAccessMaterial;
use App\Providers\Ads\ProviderAccountDiscovery;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Sources\EffectiveCapability;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;

/** Advertiser authorization only; creator TTLs/scopes are not advertiser token facts. */
final readonly class TikTokAccessAdapter implements ProviderAccessAdapter
{
    private TikTokClient $client;

    private const BASIS = 'https://business-api.tiktok.com/portal/docs?id=1738373141733378';

    public function __construct(private Factory $http, private string $version, private string $appId,
        #[\SensitiveParameter] private string $secret, private string $redirectUri, private string $authorizationEndpoint,
        private \Closure $authority)
    {
        if (! TikTokClient::advertiserId($appId) || $secret === '' || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https' || parse_url($redirectUri, PHP_URL_USER) !== null
            || parse_url($redirectUri, PHP_URL_PASS) !== null || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null
            || $authorizationEndpoint !== 'https://business-api.tiktok.com/portal/auth') {
            throw new InvalidArgumentException('Explicit TikTok advertiser configuration required.');
        }
        $this->client = new TikTokClient($http, $version, $authority, 'provider.connect');
    }

    public function provider(): Provider
    {
        return Provider::TikTok;
    }

    public function clientId(): string
    {
        return $this->appId;
    }

    public function configurationBinding(): string
    {
        return hash_hmac('sha256', json_encode([$this->version, $this->appId, $this->secret, $this->redirectUri,
            $this->authorizationEndpoint], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function forAuthority(Factory $http, \Closure $authority): self
    {
        return new self($http, $this->version, $this->appId, $this->secret, $this->redirectUri, $this->authorizationEndpoint, $authority);
    }

    public function authorizationUrl(string $state): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $state)) {
            throw new InvalidArgumentException('Exact TikTok state required.');
        }

        return $this->authorizationEndpoint.'?'.http_build_query(['app_id' => $this->appId, 'state' => $state,
            'redirect_uri' => $this->redirectUri], '', '&', PHP_QUERY_RFC3986);
    }

    public function acquire(ProviderScope $scope, ProviderCredentials $authorization, string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        $data = $this->client->request('oauth2/access_token/', ['app_id' => $this->appId, 'secret' => $this->secret,
            'auth_code' => $authorization->value('code')], null, $this->budget($scope));
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '' || strlen($data['access_token']) > 8192) {
            throw new TikTokRequestFailed('tiktok_token_unverified');
        }
        // Null expiry means not established, never proof of unlimited validity.
        $expiry = null;
        $values = ['access_token' => $data['access_token'], 'app_id' => $this->appId, 'token_kind' => 'ADVERTISER'];
        if (isset($data['expires_in'])) {
            if (! is_int($data['expires_in']) || $data['expires_in'] < 1 || $data['expires_in'] > 31536000) {
                throw new TikTokRequestFailed('tiktok_token_expiry_unverified');
            }
            $expiry = (new DateTimeImmutable)->modify('+'.$data['expires_in'].' seconds');
            $values['expires_at'] = (string) $expiry->getTimestamp();
        }

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expiry, 'TOKEN_ACQUIRED', $this->proof(false, $expiry));
    }

    public function renew(ProviderScope $scope, ProviderCredentials $current, string $nativeContext): ProviderAccessMaterial
    {
        // No undocumented refresh endpoint/creator token model is used for advertiser tokens.
        $this->context($scope, $nativeContext);
        throw new TikTokRequestFailed('tiktok_reauthorization_required');
    }

    public function discover(ProviderScope $scope, ProviderCredentials $credentials, string $nativeContext): ProviderAccountDiscovery
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $data = $this->client->request('oauth2/advertiser/get/', ['app_id' => $this->appId, 'secret' => $this->secret], $credentials, $this->budget($scope));
        $rows = $data['list'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
            throw new TikTokRequestFailed('tiktok_discovery_unverified');
        }
        $accounts = [];
        foreach ($rows as $row) {
            $id = $row['advertiser_id'] ?? null;
            if (! TikTokClient::advertiserId($id) || isset($accounts[$id]) || ! is_string($row['advertiser_name'] ?? null)
                || strlen($row['advertiser_name']) > 200) {
                throw new TikTokRequestFailed('tiktok_discovery_unverified');
            }
            $accounts[$id] = ['id' => $id, 'label' => $row['advertiser_name'], 'native_role' => null];
        }
        ksort($accounts, SORT_STRING);
        $accounts = array_values($accounts);
        $fingerprint = hash('sha256', json_encode([$scope->jsonSerialize(), $accounts, hash('sha256', $credentials->value('access_token'))], JSON_THROW_ON_ERROR));
        $context = json_decode($nativeContext, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($context) || array_diff(array_keys($context), ['offset', 'fingerprint'])) {
            throw new InvalidArgumentException('Invalid TikTok discovery cursor.');
        }
        $offset = $context['offset'] ?? 0;
        if (! is_int($offset) || $offset < 0 || ($context !== [] && (($context['fingerprint'] ?? null) !== $fingerprint || $offset >= count($accounts)))) {
            throw new InvalidArgumentException('TikTok discovery changed.');
        }
        $slice = array_slice($accounts, $offset, 100);
        $nextOffset = $offset + count($slice);
        $next = $nextOffset < count($accounts) ? new NativeProgress('tiktok.direct_accounts', self::BASIS,
            json_encode(['offset' => $nextOffset, 'fingerprint' => $fingerprint], JSON_THROW_ON_ERROR)) : null;

        return new ProviderAccountDiscovery($scope, $slice, self::BASIS, $next === null ? 'COMPLETE' : 'PARTIAL', $next);
    }

    public function verifySelection(ProviderScope $scope, ProviderCredentials $credentials, string $nativeSelection): ProviderAccessMaterial
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $selection = json_decode($nativeSelection, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($selection) || array_diff(array_keys($selection), ['account_id']) || ($selection['account_id'] ?? null) !== $scope->accountId
            || ! TikTokClient::advertiserId($scope->accountId)) {
            throw new InvalidArgumentException('Exact TikTok advertiser required.');
        }
        $budget = $this->budget($scope);
        $data = $this->client->request('advertiser/info/', ['advertiser_ids' => json_encode([$scope->accountId]),
            'fields' => json_encode(['advertiser_id', 'advertiser_name', 'currency', 'timezone'])], $credentials, $budget);
        $rows = $data['list'] ?? null;
        $account = is_array($rows) ? ($rows[0] ?? null) : null;
        if (! is_array($rows) || count($rows) !== 1 || ! is_array($account) || ($account['advertiser_id'] ?? null) !== $scope->accountId
            || ! is_string($account['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $account['currency'])
            || ! in_array($account['timezone'] ?? null, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new TikTokRequestFailed('tiktok_account_metadata_unverified');
        }
        $day = (new DateTimeImmutable('now', new DateTimeZone($account['timezone'])))->modify('-1 day')->format('Y-m-d');
        $probe = $this->client->request('report/integrated/get/', ['advertiser_id' => $scope->accountId, 'report_type' => 'BASIC',
            'data_level' => 'AUCTION_CAMPAIGN', 'dimensions' => '["campaign_id"]', 'metrics' => '["spend","impressions","clicks"]',
            'start_date' => $day, 'end_date' => $day, 'page' => 1, 'page_size' => 1], $credentials, $budget);
        if (! is_array($probe['list'] ?? null) || ! array_is_list($probe['list']) || count($probe['list']) > 1) {
            throw new TikTokRequestFailed('tiktok_report_permission_unverified');
        }
        $values = $credentials->custodyPayload();
        $values['account_id'] = $scope->accountId;
        $values['account_currency'] = $account['currency'];
        $values['account_timezone'] = $account['timezone'];
        $expiry = isset($values['expires_at']) ? new DateTimeImmutable('@'.$values['expires_at']) : null;

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expiry, 'CONNECTED', $this->proof(true, $expiry));
    }

    private function usable(ProviderCredentials $credentials): void
    {
        $v = $credentials->custodyPayload();
        if (($v['app_id'] ?? null) !== $this->appId || ($v['token_kind'] ?? null) !== 'ADVERTISER'
            || (isset($v['expires_at']) && (int) $v['expires_at'] <= time())) {
            throw new TikTokRequestFailed('tiktok_reauthorization_required');
        }
    }

    private function proof(bool $selected, ?DateTimeImmutable $expiry): array
    {
        $at = new DateTimeImmutable;
        $until = $at->modify('+5 minutes');
        if ($expiry !== null && $expiry < $until) {
            $until = $expiry;
        }
        $proof = [];
        foreach (EffectiveCapability::AXES as $axis) {
            $proof[$axis] = ['state' => $axis === 'credential' || ($selected && $axis === 'provider_permission') ? 'VERIFIED' : 'UNKNOWN',
                'provenance' => self::BASIS, 'verified_at' => $at->format(DATE_ATOM), 'valid_until' => $until->format(DATE_ATOM)];
        }

        return $proof;
    }

    private function context(ProviderScope $scope, string $context): void
    {
        if ($scope->provider !== Provider::TikTok || $scope->purpose !== 'advertising_analysis'
            || ! in_array($scope->operation, ['discovery.read', 'report.read'], true) || $context !== '{}') {
            throw new InvalidArgumentException('Exact TikTok lifecycle scope required.');
        }
    }

    private function budget(ProviderScope $scope): ProviderReadBudget
    {
        return new ProviderReadBudget($scope, 'tiktok.access', 8, 0, 8, 10000, new DateTimeImmutable('+45 seconds'), 1, 5);
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'tiktok', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('TikTok configuration contains secrets.');
    }
}
