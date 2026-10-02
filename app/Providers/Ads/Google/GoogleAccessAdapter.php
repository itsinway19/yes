<?php

namespace App\Providers\Ads\Google;

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

/** Native delegated web OAuth and manager routing. No automatic selection, grant or policy approval. */
final readonly class GoogleAccessAdapter implements ProviderAccessAdapter
{
    private GoogleOAuthClient $oauth;

    private GoogleAdsClient $ads;

    private const OAUTH_BASIS = 'https://developers.google.com/identity/protocols/oauth2/web-server';

    private const DISCOVERY_BASIS = 'https://developers.google.com/google-ads/api/docs/account-management/listing-accounts';

    private const HIERARCHY_BASIS = 'https://developers.google.com/google-ads/api/docs/account-management/get-account-hierarchy';

    public function __construct(Factory $http, private string $version, private string $clientId,
        #[\SensitiveParameter] private string $clientSecret, #[\SensitiveParameter] private string $developerToken,
        private string $redirectUri, \Closure $currentAuthority)
    {
        $this->oauth = new GoogleOAuthClient($http, $clientId, $clientSecret, $redirectUri, $currentAuthority);
        $this->ads = new GoogleAdsClient($http, $version, $developerToken, $currentAuthority, 'provider.connect');
    }

    public function provider(): Provider
    {
        return Provider::Google;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function configurationBinding(): string
    {
        return hash_hmac('sha256', json_encode([$this->version, $this->clientId, $this->clientSecret,
            $this->developerToken, $this->redirectUri], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function forAuthority(Factory $http, \Closure $currentAuthority): self
    {
        return new self($http, $this->version, $this->clientId, $this->clientSecret, $this->developerToken,
            $this->redirectUri, $currentAuthority);
    }

    public function authorizationUrl(string $state, bool $requestNewOfflineGrant = false): string
    {
        return $this->oauth->authorizationUrl($state, $requestNewOfflineGrant);
    }

    public function acquire(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $authorization,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        $at = new DateTimeImmutable;
        $payload = $this->oauth->exchange($authorization->value('code'), $this->budget($scope, 'acquire'));

        return $this->tokenMaterial($scope, $payload, $at, null);
    }

    public function renew(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $current,
        #[\SensitiveParameter] string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        $values = $current->custodyPayload();
        if (($values['oauth_client_id'] ?? null) !== $this->oauth->clientId
            || ($values['granted_scope'] ?? null) !== GoogleOAuthClient::SCOPE
            || (isset($values['account_id']) && $values['account_id'] !== $scope->accountId)
            || (isset($values['refresh_expires_at']) && (int) $values['refresh_expires_at'] <= time())) {
            throw new GoogleRequestFailed('google_reconnect_required');
        }
        $at = new DateTimeImmutable;
        $payload = $this->oauth->refresh($current->value('refresh_token'), $this->budget($scope, 'refresh'));

        // Custody must atomically replace this material. This adapter makes no database effect.
        return $this->tokenMaterial($scope, $payload, $at, $current);
    }

    public function discover(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeContext): ProviderAccountDiscovery
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $context = $this->decode($nativeContext, ['scope', 'fingerprint', 'offset']);
        $budget = $this->budget($scope, 'direct-discovery');
        $ids = $this->directIds($credentials, $budget);
        sort($ids, SORT_STRING);
        $fingerprint = hash('sha256', json_encode([$scope->jsonSerialize(), $ids,
            hash('sha256', $credentials->value('access_token'))], JSON_THROW_ON_ERROR));
        $offset = 0;
        if ($context !== []) {
            if (($context['scope'] ?? null) !== $scope->jsonSerialize() || ($context['fingerprint'] ?? null) !== $fingerprint
                || ! is_int($context['offset'] ?? null) || $context['offset'] < 0 || $context['offset'] >= count($ids)) {
                throw new InvalidArgumentException('Google discovery identity or current access changed.');
            }
            $offset = $context['offset'];
        }
        $slice = array_slice($ids, $offset, 100);
        $budget->acceptPage(count($slice), new DateTimeImmutable);
        $offset += count($slice);
        $next = $offset < count($ids) ? new NativeProgress('google.direct_accounts', self::DISCOVERY_BASIS,
            json_encode(['scope' => $scope->jsonSerialize(), 'fingerprint' => $fingerprint, 'offset' => $offset], JSON_THROW_ON_ERROR)) : null;

        return new ProviderAccountDiscovery($scope, array_map(static fn ($id) => [
            'id' => $id, 'label' => 'Google Ads '.$id, 'native_role' => null,
        ], $slice), self::DISCOVERY_BASIS, $next === null ? 'COMPLETE' : 'PARTIAL', $next);
    }

    /** One manager's immediate children. Caller traverses sub-managers explicitly with the same root context. */
    public function hierarchy(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        string $loginCustomer, string $manager, ?NativeProgress $continuation = null): GoogleHierarchyPage
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        if (! GoogleAdsClient::customerId($loginCustomer) || ! GoogleAdsClient::customerId($manager)) {
            throw new InvalidArgumentException('Exact Google manager identities required.');
        }
        $query = 'SELECT customer_client.id, customer_client.client_customer, customer_client.descriptive_name, customer_client.level, customer_client.manager FROM customer_client WHERE customer_client.level <= 1';
        $fingerprint = hash('sha256', json_encode([$scope->jsonSerialize(), $loginCustomer, $manager, $query, $this->version,
            hash('sha256', $credentials->value('access_token'))], JSON_THROW_ON_ERROR));
        $token = null;
        $seenTokens = [];
        if ($continuation !== null) {
            $c = $this->decode($continuation->envelope()['payload'], ['fingerprint', 'page_token', 'seen_tokens']);
            if ($continuation->kind !== 'google.manager_page' || ($c['fingerprint'] ?? null) !== $fingerprint
                || ! is_string($c['page_token'] ?? null) || $c['page_token'] === '' || strlen($c['page_token']) > 8192
                || ! is_array($c['seen_tokens'] ?? null) || ! array_is_list($c['seen_tokens']) || count($c['seen_tokens']) > 32) {
                throw new InvalidArgumentException('Google manager continuation scope mismatch.');
            }
            foreach ($c['seen_tokens'] as $hash) {
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new InvalidArgumentException('Invalid Google manager continuation history.');
                }
            }
            $seenTokens = $c['seen_tokens'];
            $token = $c['page_token'];
        }
        $budget = $this->budget($scope, 'hierarchy');
        $this->verifyManagerRoot($credentials, $budget, $loginCustomer);
        $page = $this->ads->search($manager, $query, $credentials, $budget, $loginCustomer, $token);
        $rows = GoogleAdsClient::rows($page, ['customerClient.id', 'customerClient.clientCustomer',
            'customerClient.descriptiveName', 'customerClient.level', 'customerClient.manager']);
        $budget->acceptPage(count($rows), new DateTimeImmutable);
        $customers = [];
        $seen = [];
        foreach ($rows as $row) {
            $c = $row['customerClient'] ?? null;
            $id = is_array($c) ? ($c['id'] ?? null) : null;
            if (! GoogleAdsClient::customerId($id) || ($c['clientCustomer'] ?? null) !== 'customers/'.$id
                || ! is_bool($c['manager'] ?? null) || ! in_array($c['level'] ?? null, ['0', '1'], true)
                || ! is_string($c['descriptiveName'] ?? null) || strlen($c['descriptiveName']) > 200
                || (($c['level'] === '0') !== ($id === $manager)) || isset($seen[$id])) {
                throw new GoogleRequestFailed('google_malformed_hierarchy');
            }
            $seen[$id] = true;
            $customers[] = ['id' => $id, 'label' => $c['descriptiveName'], 'manager' => $c['manager'], 'level' => (int) $c['level']];
        }
        $nextToken = $this->nextToken($page);
        if ($nextToken !== null && in_array(hash('sha256', $nextToken), $seenTokens, true)) {
            throw new GoogleRequestFailed('google_repeated_page_token');
        }
        if ($nextToken !== null) {
            if (count($seenTokens) >= 32) {
                throw new GoogleRequestFailed('google_hierarchy_page_limit');
            }
            $seenTokens[] = hash('sha256', $nextToken);
        }
        $next = $nextToken === null ? null : new NativeProgress('google.manager_page', self::HIERARCHY_BASIS,
            json_encode(['fingerprint' => $fingerprint, 'page_token' => $nextToken, 'seen_tokens' => $seenTokens], JSON_THROW_ON_ERROR));

        return new GoogleHierarchyPage($scope, $loginCustomer, $manager, $customers, $next);
    }

    public function verifySelection(ProviderScope $scope, #[\SensitiveParameter] ProviderCredentials $credentials,
        #[\SensitiveParameter] string $nativeSelection): ProviderAccessMaterial
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $selection = $this->decode($nativeSelection, ['account_id', 'login_customer_id']);
        $login = $selection['login_customer_id'] ?? null;
        if (! GoogleAdsClient::customerId($scope->accountId) || ($selection['account_id'] ?? null) !== $scope->accountId
            || ($login !== null && ! GoogleAdsClient::customerId($login))) {
            throw new InvalidArgumentException('Explicit Google selection cannot change the declared source account.');
        }
        $budget = $this->budget($scope, 'selection');
        if ($login !== null) {
            $this->verifyManagerRoot($credentials, $budget, $login);
        }
        $account = $this->metadata($scope->accountId, $credentials, $budget, $login);
        if ($account['manager'] !== false) {
            throw new GoogleRequestFailed('google_manager_is_not_report_account');
        }
        $probe = $this->ads->search($scope->accountId, 'SELECT campaign.id FROM campaign LIMIT 1', $credentials, $budget, $login);
        $rows = GoogleAdsClient::rows($probe, ['campaign.id']);
        $budget->acceptPage(count($rows), new DateTimeImmutable);
        if (count($rows) > 1 || $this->nextToken($probe) !== null) {
            throw new GoogleRequestFailed('google_selection_read_unverified');
        }
        foreach ($rows as $row) {
            if (! is_string($row['campaign']['id'] ?? null) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $row['campaign']['id'])) {
                throw new GoogleRequestFailed('google_selection_read_unverified');
            }
        }
        $values = $credentials->custodyPayload();
        unset($values['login_customer_id']);
        if ($login !== null) {
            $values['login_customer_id'] = $login;
        }
        $values['account_id'] = $scope->accountId;
        $values['account_currency'] = $account['currencyCode'];
        $values['account_timezone'] = $account['timeZone'];
        $at = new DateTimeImmutable;
        $expiry = new DateTimeImmutable('@'.$values['expires_at']);

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expiry, 'CONNECTED',
            $this->proof($at, $expiry, true));
    }

    private function tokenMaterial(ProviderScope $scope, array $payload, DateTimeImmutable $at, ?ProviderCredentials $current): ProviderAccessMaterial
    {
        $old = $current?->custodyPayload() ?? [];
        $grant = $payload['scope'] ?? ($old['granted_scope'] ?? null);
        if (! is_string($payload['access_token'] ?? null) || $payload['access_token'] === ''
            || ($payload['token_type'] ?? null) !== 'Bearer' || ! is_int($payload['expires_in'] ?? null)
            || $payload['expires_in'] < 1 || $payload['expires_in'] > 31536000
            || ! is_string($grant) || ! in_array(GoogleOAuthClient::SCOPE, explode(' ', $grant), true)) {
            throw new GoogleRequestFailed('google_token_or_scope_unverified');
        }
        $refresh = $payload['refresh_token'] ?? ($old['refresh_token'] ?? null);
        if (! is_string($refresh) || $refresh === '') {
            throw new GoogleRequestFailed('google_offline_access_missing');
        }
        $expiry = $at->modify('+'.$payload['expires_in'].' seconds');
        $values = $old;
        $values['access_token'] = $payload['access_token'];
        $values['refresh_token'] = $refresh;
        $values['oauth_client_id'] = $this->oauth->clientId;
        $values['granted_scope'] = GoogleOAuthClient::SCOPE;
        $values['expires_at'] = (string) $expiry->getTimestamp();
        if (isset($payload['refresh_token'])) {
            unset($values['refresh_expires_at']);
        }
        if (isset($payload['refresh_token_expires_in'])) {
            if (! is_int($payload['refresh_token_expires_in']) || $payload['refresh_token_expires_in'] < 1
                || $payload['refresh_token_expires_in'] > 31536000) {
                throw new GoogleRequestFailed('google_refresh_expiry_unverified');
            }
            $values['refresh_expires_at'] = (string) $at->modify('+'.$payload['refresh_token_expires_in'].' seconds')->getTimestamp();
        }

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expiry, 'TOKEN_ACQUIRED', $this->proof($at, $expiry, false));
    }

    private function proof(DateTimeImmutable $at, DateTimeImmutable $expiry, bool $selected): array
    {
        $valid = $expiry < $at->modify('+5 minutes') ? $expiry : $at->modify('+5 minutes');
        $proof = [];
        foreach (EffectiveCapability::AXES as $axis) {
            $proof[$axis] = ['state' => $axis === 'credential' || ($selected && $axis === 'provider_permission') ? 'VERIFIED' : 'UNKNOWN',
                'provenance' => $selected ? 'https://developers.google.com/google-ads/api/rest/common/search' : self::OAUTH_BASIS,
                'verified_at' => $at->format(DATE_ATOM), 'valid_until' => $valid->format(DATE_ATOM)];
        }

        return $proof;
    }

    private function usable(ProviderCredentials $credentials): void
    {
        $values = $credentials->custodyPayload();
        if (($values['oauth_client_id'] ?? null) !== $this->oauth->clientId
            || ($values['granted_scope'] ?? null) !== GoogleOAuthClient::SCOPE
            || ! preg_match('/^[0-9]{1,12}$/D', $values['expires_at'] ?? '') || (int) $values['expires_at'] <= time()) {
            throw new GoogleRequestFailed('google_credential_reverification_required');
        }
    }

    private function directIds(ProviderCredentials $credentials, ProviderReadBudget $budget): array
    {
        $page = $this->ads->accessible($credentials, $budget);
        $resources = $page['resourceNames'] ?? [];
        if (! is_array($resources) || ! array_is_list($resources) || count($resources) > 10000) {
            throw new GoogleRequestFailed('google_malformed_accessible_accounts');
        }
        $ids = [];
        foreach ($resources as $resource) {
            if (! is_string($resource) || ! preg_match('#^customers/([1-9][0-9]{9})$#D', $resource, $match)
                || isset($ids[$match[1]])) {
                throw new GoogleRequestFailed('google_malformed_accessible_accounts');
            }
            $ids[$match[1]] = $match[1];
        }

        return array_values($ids);
    }

    private function verifyManagerRoot(ProviderCredentials $credentials, ProviderReadBudget $budget, string $login): void
    {
        if (! in_array($login, $this->directIds($credentials, $budget), true)
            || $this->metadata($login, $credentials, $budget, null)['manager'] !== true) {
            throw new GoogleRequestFailed('google_manager_context_unverified');
        }
    }

    private function metadata(string $id, ProviderCredentials $credentials, ProviderReadBudget $budget, ?string $login): array
    {
        $page = $this->ads->search($id, 'SELECT customer.id, customer.manager, customer.currency_code, customer.time_zone FROM customer LIMIT 1',
            $credentials, $budget, $login);
        $rows = GoogleAdsClient::rows($page, ['customer.id', 'customer.manager', 'customer.currencyCode', 'customer.timeZone']);
        $budget->acceptPage(count($rows), new DateTimeImmutable);
        $c = $rows[0]['customer'] ?? null;
        if (count($rows) !== 1 || $this->nextToken($page) !== null || ! is_array($c) || ($c['id'] ?? null) !== $id
            || ! is_bool($c['manager'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $c['currencyCode'] ?? '')
            || ! in_array($c['timeZone'] ?? null, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new GoogleRequestFailed('google_account_metadata_unverified');
        }

        return $c;
    }

    private function nextToken(array $page): ?string
    {
        $token = $page['nextPageToken'] ?? null;
        if ($token === '') {
            return null;
        }
        if ($token !== null && (! is_string($token) || strlen($token) > 8192)) {
            throw new GoogleRequestFailed('google_invalid_page_token');
        }

        return $token;
    }

    private function decode(string $json, array $keys): array
    {
        if (strlen($json) > 32768 || ! str_starts_with(ltrim($json), '{')) {
            throw new InvalidArgumentException('Bounded Google context object required.');
        }
        $context = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($context) || array_diff(array_keys($context), $keys)) {
            throw new InvalidArgumentException('Unsupported Google native lifecycle context.');
        }

        return $context;
    }

    private function context(ProviderScope $scope, string $context): void
    {
        if ($scope->provider !== Provider::Google || $scope->purpose !== 'advertising_analysis'
            || ! in_array($scope->operation, ['discovery.read', 'report.read'], true) || $context !== '{}') {
            throw new InvalidArgumentException('Exact Google lifecycle scope required.');
        }
    }

    private function budget(ProviderScope $scope, string $operation): ProviderReadBudget
    {
        return new ProviderReadBudget($scope, 'google.access.'.$operation, 8, 0, 6, 10000,
            new DateTimeImmutable('+45 seconds'), 1, 5);
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'google', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Google access adapter contains server secrets.');
    }
}
