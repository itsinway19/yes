<?php

namespace App\Providers\Ads\Meta;

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

/** Facebook Login for Business configurations; native system and user tokens retain separate lifecycles. */
final readonly class MetaAccessAdapter implements ProviderAccessAdapter
{
    private MetaGraphClient $client;

    private const TOKEN_BASIS = 'https://developers.facebook.com/docs/graph-api/reference/debug_token/';

    private const DISCOVERY_BASIS = 'https://github.com/facebook/facebook-php-business-sdk/blob/138fdf82d1d9f38ac250ac040f32b5f2df9bf2eb/src/FacebookAds/Object/User.php';

    public function __construct(Factory $http, private string $version, private string $appId,
        #[\SensitiveParameter] private string $appSecret, private string $redirectUri, private string $configurationId,
        \Closure $currentAuthority, private MetaTokenKind $tokenKind = MetaTokenKind::BusinessIntegration)
    {
        if (! preg_match('/^[1-9][0-9]{0,39}$/D', $appId) || ! preg_match('/^[1-9][0-9]{0,39}$/D', $configurationId)
            || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https' || parse_url($redirectUri, PHP_URL_FRAGMENT) !== null
            || parse_url($redirectUri, PHP_URL_USER) !== null) {
            throw new InvalidArgumentException('Explicit Meta app and exact HTTPS callback required.');
        }
        $this->client = new MetaGraphClient($http, $version, $appSecret, $currentAuthority, 'provider.connect');
    }

    public function provider(): Provider
    {
        return Provider::Meta;
    }

    public function configurationBinding(): string
    {
        return hash('sha256', json_encode([$this->version, $this->appId, $this->redirectUri,
            $this->configurationId, $this->tokenKind->value], JSON_THROW_ON_ERROR));
    }

    public function configurationId(): string
    {
        return $this->configurationId;
    }

    /** Construct a scoped native client from server configuration, always replacing the request guard. */
    public function forAuthority(Factory $http, \Closure $currentAuthority): self
    {
        return new self($http, $this->version, $this->appId, $this->appSecret, $this->redirectUri,
            $this->configurationId, $currentAuthority, $this->tokenKind);
    }

    /** Caller generates and binds one-use state to current session, Workspace, source and command. */
    public function authorizationUrl(string $state): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $state)) {
            throw new InvalidArgumentException('Bounded server-generated OAuth state required.');
        }

        return 'https://www.facebook.com/'.$this->version.'/dialog/oauth?'.http_build_query([
            'client_id' => $this->appId, 'redirect_uri' => $this->redirectUri, 'response_type' => 'code',
            // Owner-created configuration must request only the required ads_read business permission.
            'config_id' => $this->configurationId, 'state' => $state,
            'override_default_response_type' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Internal only: authorization code must already pass the bound one-use callback-state guard. */
    public function acquire(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $authorization,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        $budget = $this->budget($scope, 'acquire');
        $app = $this->appCredentials();
        $short = $this->client->request('GET', 'oauth/access_token', [
            'client_id' => $this->appId, 'client_secret' => $this->appSecret, 'redirect_uri' => $this->redirectUri,
            'code' => $authorization->value('code'),
        ], $app, $budget);
        $shortToken = $this->token($short);
        if ($this->tokenKind === MetaTokenKind::BusinessIntegration) {
            return $this->inspect($scope, new ProviderCredentials(['access_token' => $shortToken]), $budget);
        }
        $long = $this->client->request('GET', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $this->appId, 'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortToken,
        ], $app, $budget);
        $credentials = new ProviderCredentials(['access_token' => $this->token($long)]);

        return $this->inspect($scope, $credentials, $budget);
    }

    public function renew(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $current,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        if ($this->tokenKind === MetaTokenKind::BusinessIntegration) {
            return $this->inspect($scope, $current, $this->budget($scope, 'reverify'));
        }
        // Server user tokens have no assumed refresh token. Reconnect repeats advertiser authorization.
        throw new MetaRequestFailed('meta_reconnect_required');
    }

    public function discover(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeContext): ProviderAccountDiscovery
    {
        $this->context($scope, '{}');
        $context = json_decode($nativeContext, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($context) || array_diff(array_keys($context), ['scope', 'user_id', 'after', 'business_id', 'targets_fingerprint', 'offset'])) {
            throw new InvalidArgumentException('Unsupported Meta discovery context.');
        }
        $budget = $this->budget($scope, 'discovery');
        $verified = $this->inspect($scope, $credentials, $budget);
        if ($this->tokenKind === MetaTokenKind::BusinessIntegration) {
            return $this->businessAccounts($scope, $verified->credentials, $budget, $context);
        }
        $user = $verified->credentials->value('user_id');
        $parameters = ['fields' => 'account_id,name', 'limit' => 100];
        if ($context !== []) {
            if (($context['scope'] ?? null) !== $scope->jsonSerialize() || ($context['user_id'] ?? null) !== $user
                || ! is_string($context['after'] ?? null) || $context['after'] === '' || strlen($context['after']) > 4096) {
                throw new InvalidArgumentException('Meta discovery continuation scope mismatch.');
            }
            $parameters['after'] = $context['after'];
        }
        $page = $this->client->request('GET', $user.'/adaccounts',
            $parameters, $verified->credentials, $budget);
        if (! is_array($page['data'] ?? null) || ! array_is_list($page['data']) || count($page['data']) > 1000) {
            throw new MetaRequestFailed('meta_malformed_account_discovery');
        }
        $budget->acceptPage(count($page['data']), new DateTimeImmutable);
        $accounts = [];
        foreach ($page['data'] as $account) {
            if (! is_array($account) || ! is_string($account['account_id'] ?? null)
                || ! preg_match('/^[1-9][0-9]{0,39}$/D', $account['account_id']) || ! is_string($account['name'] ?? null)) {
                throw new MetaRequestFailed('meta_malformed_account_discovery');
            }
            $accounts[] = ['id' => $account['account_id'], 'label' => $account['name'], 'native_role' => null];
        }
        $continuation = null;
        if (isset($page['paging']['next'])) {
            $after = $page['paging']['cursors']['after'] ?? null;
            if (! is_string($after) || $after === '' || strlen($after) > 4096) {
                throw new MetaRequestFailed('meta_malformed_discovery_cursor');
            }
            $continuation = new NativeProgress('meta.discovery_cursor', self::DISCOVERY_BASIS,
                json_encode(['scope' => $scope->jsonSerialize(), 'user_id' => $user, 'after' => $after], JSON_THROW_ON_ERROR));
        }

        return new ProviderAccountDiscovery($scope, $accounts, self::DISCOVERY_BASIS,
            $continuation === null ? 'COMPLETE' : 'PARTIAL', $continuation);
    }

    public function verifySelection(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeSelection): ProviderAccessMaterial
    {
        $this->context($scope, '{}');
        if ($nativeSelection !== $scope->accountId) {
            throw new InvalidArgumentException('Selection cannot silently change the declared source account.');
        }
        $budget = $this->budget($scope, 'selection');
        $verified = $this->inspect($scope, $credentials, $budget);
        $account = $this->client->request('GET', 'act_'.$scope->accountId,
            ['fields' => 'id,account_id,currency,timezone_name'], $verified->credentials, $budget);
        if (($account['id'] ?? null) !== 'act_'.$scope->accountId || ($account['account_id'] ?? null) !== $scope->accountId
            || ! is_string($account['currency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $account['currency'])
            || ! in_array($account['timezone_name'] ?? null, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new MetaRequestFailed('meta_account_selection_unverified');
        }
        $probe = $this->client->request('GET', 'act_'.$scope->accountId.'/insights',
            ['level' => 'campaign', 'fields' => 'account_id,campaign_id', 'limit' => 1], $verified->credentials, $budget);
        if (! is_array($probe['data'] ?? null) || ! array_is_list($probe['data'])) {
            throw new MetaRequestFailed('meta_report_permission_unverified');
        }
        foreach ($probe['data'] as $row) {
            if (! is_array($row) || ($row['account_id'] ?? null) !== $scope->accountId) {
                throw new MetaRequestFailed('meta_report_permission_unverified');
            }
        }
        $selected = new ProviderCredentials(array_replace($verified->credentials->custodyPayload(), [
            'account_id' => $scope->accountId, 'account_currency' => $account['currency'], 'account_timezone' => $account['timezone_name'],
        ]));

        return new ProviderAccessMaterial($scope, $selected, $verified->expiresAt, 'CONNECTED', $verified->verification);
    }

    private function inspect(ProviderScope $scope, ProviderCredentials $credentials, ProviderReadBudget $budget): ProviderAccessMaterial
    {
        $response = $this->client->request('GET', 'debug_token', ['input_token' => $credentials->value('access_token')],
            $this->appCredentials(), $budget);
        $data = $response['data'] ?? null;
        if (! is_array($data) || ($data['is_valid'] ?? null) !== true
            || ($this->tokenKind === MetaTokenKind::User && ($data['type'] ?? null) !== 'USER')
            || (string) ($data['app_id'] ?? '') !== $this->appId || ! is_string($data['user_id'] ?? null)
            || ! preg_match('/^[1-9][0-9]{0,39}$/D', $data['user_id']) || ! is_array($data['scopes'] ?? null)
            || ! in_array('ads_read', $data['scopes'], true) || ! is_int($data['expires_at'] ?? null)
            || $data['expires_at'] < 0 || ($data['expires_at'] !== 0 && $data['expires_at'] <= time())) {
            throw new MetaRequestFailed('meta_token_or_permission_unverified');
        }
        $values = $credentials->custodyPayload();
        if ($this->tokenKind === MetaTokenKind::BusinessIntegration) {
            $business = $this->client->request('GET', 'me', ['fields' => 'client_business_id'], $credentials, $budget);
            if (! is_string($business['client_business_id'] ?? null)
                || ! preg_match('/^[1-9][0-9]{0,39}$/D', $business['client_business_id'])
                || ($business['id'] ?? null) !== $data['user_id']
                || (isset($values['client_business_id']) && $values['client_business_id'] !== $business['client_business_id'])) {
                throw new MetaRequestFailed('meta_client_business_identity_unverified');
            }
            $values['client_business_id'] = $business['client_business_id'];
            // Current introspection replaces prior asset proof; absence is UNKNOWN, not all assets.
            $targets = null;
            if (isset($data['granular_scopes'])) {
                if (! is_array($data['granular_scopes']) || ! array_is_list($data['granular_scopes'])) {
                    throw new MetaRequestFailed('meta_granular_permission_unverified');
                }
                foreach ($data['granular_scopes'] as $permission) {
                    if (($permission['scope'] ?? null) !== 'ads_read') {
                        continue;
                    }
                    if (! array_key_exists('target_ids', $permission)) {
                        continue;
                    }
                    if ($targets !== null || ! is_array($permission['target_ids']) || ! array_is_list($permission['target_ids'])) {
                        throw new MetaRequestFailed('meta_granular_permission_unverified');
                    }
                    $targets = [];
                    foreach ($permission['target_ids'] as $id) {
                        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]{0,39}$/D', (string) $id)
                            || in_array((string) $id, $targets, true)) {
                            throw new MetaRequestFailed('meta_granular_permission_unverified');
                        }
                        $targets[] = (string) $id;
                    }
                    sort($targets, SORT_STRING);
                }
            }
            $encodedTargets = json_encode($targets, JSON_THROW_ON_ERROR);
            if (strlen($encodedTargets) > 8192) {
                throw new MetaRequestFailed('meta_local_asset_discovery_budget_exhausted');
            }
            $values['ads_read_targets'] = $encodedTargets;
        }
        if (isset($values['user_id']) && $values['user_id'] !== $data['user_id']) {
            throw new MetaRequestFailed('meta_user_identity_mismatch');
        }
        // Zero records no time-based expiry; nullable custody never supplies a guessed lifetime.
        $expiry = $data['expires_at'] === 0 ? null : $data['expires_at'];
        if (isset($data['data_access_expires_at'])) {
            if (! is_int($data['data_access_expires_at']) || $data['data_access_expires_at'] <= time()) {
                throw new MetaRequestFailed('meta_data_access_unverified');
            }
            $expiry = $expiry === null ? $data['data_access_expires_at'] : min($expiry, $data['data_access_expires_at']);
        }
        $values['user_id'] = $data['user_id'];
        $at = new DateTimeImmutable;
        $expires = $expiry === null ? null : new DateTimeImmutable('@'.$expiry);
        // Five minutes bounds local proof staleness, not Meta's token lifetime or official quota.
        $valid = $expires !== null && $expires < $at->modify('+5 minutes') ? $expires : $at->modify('+5 minutes');
        $verification = [];
        foreach (EffectiveCapability::AXES as $axis) {
            $verification[$axis] = ['state' => in_array($axis, ['credential', 'provider_permission'], true) ? 'VERIFIED' : 'UNKNOWN',
                'provenance' => self::TOKEN_BASIS, 'verified_at' => $at->format(DATE_ATOM), 'valid_until' => $valid->format(DATE_ATOM)];
        }

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expires, 'CONNECTED', $verification);
    }

    /** Enumerate current ads_read targets and read each exact AdAccount, not a guessed system-user edge. */
    private function businessAccounts(ProviderScope $scope, ProviderCredentials $credentials,
        ProviderReadBudget $budget, array $context): ProviderAccountDiscovery
    {
        $targets = json_decode($credentials->value('ads_read_targets'), true, flags: JSON_THROW_ON_ERROR);
        if ($targets === null) {
            return new ProviderAccountDiscovery($scope, [], self::TOKEN_BASIS, 'UNKNOWN', null);
        }
        $business = $credentials->value('client_business_id');
        $fingerprint = hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR));
        $offset = 0;
        if ($context !== []) {
            if (array_diff(array_keys($context), ['scope', 'business_id', 'targets_fingerprint', 'offset'])
                || ($context['scope'] ?? null) !== $scope->jsonSerialize() || ($context['business_id'] ?? null) !== $business
                || ($context['targets_fingerprint'] ?? null) !== $fingerprint || ! is_int($context['offset'] ?? null)
                || $context['offset'] < 0 || $context['offset'] >= count($targets)) {
                throw new InvalidArgumentException('Business discovery changed scope, asset grant or continuation.');
            }
            $offset = $context['offset'];
        }
        $accounts = [];
        foreach (array_slice($targets, $offset, 5) as $id) {
            $account = $this->client->request('GET', 'act_'.$id, ['fields' => 'id,account_id,name'], $credentials, $budget);
            if (($account['id'] ?? null) !== 'act_'.$id || ($account['account_id'] ?? null) !== $id
                || ! is_string($account['name'] ?? null)) {
                throw new MetaRequestFailed('meta_business_asset_not_verified_ad_account');
            }
            $accounts[] = ['id' => $id, 'label' => $account['name'], 'native_role' => null];
        }
        $budget->acceptPage(count($accounts), new DateTimeImmutable);
        $offset += count($accounts);
        $continuation = $offset < count($targets) ? new NativeProgress('meta.granted_asset_enumeration', self::TOKEN_BASIS,
            json_encode(['scope' => $scope->jsonSerialize(), 'business_id' => $business,
                'targets_fingerprint' => $fingerprint, 'offset' => $offset], JSON_THROW_ON_ERROR)) : null;

        return new ProviderAccountDiscovery($scope, $accounts, self::TOKEN_BASIS,
            $continuation === null ? 'COMPLETE' : 'PARTIAL', $continuation);
    }

    private function token(array $payload): string
    {
        if (! is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
            throw new MetaRequestFailed('meta_malformed_token_exchange');
        }

        return $payload['access_token'];
    }

    private function appCredentials(): ProviderCredentials
    {
        return new ProviderCredentials(['access_token' => $this->appId.'|'.$this->appSecret]);
    }

    private function context(ProviderScope $scope, string $context): void
    {
        if ($scope->provider !== Provider::Meta || ! preg_match('/^[1-9][0-9]{0,39}$/D', $scope->accountId) || $context !== '{}') {
            throw new InvalidArgumentException('Exact Meta scope and supported native lifecycle context required.');
        }
    }

    private function budget(ProviderScope $scope, string $operation): ProviderReadBudget
    {
        return new ProviderReadBudget($scope, 'meta.access.'.$operation, 8, 0, 2, 1000,
            new DateTimeImmutable('+30 seconds'), 1, 5);
    }

    public function __debugInfo(): array
    {
        return ['version' => $this->version, 'app_id' => $this->appId, 'app_secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Meta access adapter contains server credentials.');
    }
}
