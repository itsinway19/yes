<?php

namespace App\Providers\Ads\Snapchat;

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

/** Native account evidence only. Custody and explicit source authority remain coordinator responsibilities. */
final readonly class SnapchatAccessAdapter implements ProviderAccessAdapter
{
    private const BASIS = 'https://developers.snap.com/marketing-api/Ads-API/organizations';

    public function __construct(private SnapchatOAuthClient $oauth, private SnapchatClient $client) {}

    public function provider(): Provider
    {
        return Provider::Snapchat;
    }

    public function clientId(): string
    {
        return $this->oauth->clientId;
    }

    public function authorizationUrl(string $state): string
    {
        return $this->oauth->authorizationUrl($state);
    }

    public function configurationBinding(): string
    {
        return $this->oauth->configurationBinding();
    }

    public function forAuthority(Factory $http, \Closure $authority): self
    {
        return new self($this->oauth->forAuthority($http, $authority), new SnapchatClient($http, $authority, 'provider.connect'));
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'snapchat', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Snapchat lifecycle configuration contains server secrets.');
    }

    public function acquire(ProviderScope $scope, ProviderCredentials $authorization, string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);

        return $this->tokens($scope, $this->oauth->exchange($authorization->value('code'), $this->budget($scope)));
    }

    public function renew(ProviderScope $scope, ProviderCredentials $current, string $nativeContext): ProviderAccessMaterial
    {
        $this->context($scope, $nativeContext);
        $values = $current->custodyPayload();
        if (($values['client_id'] ?? null) !== $this->oauth->clientId
            || (isset($values['account_id']) && $values['account_id'] !== $scope->accountId)) {
            throw new InvalidArgumentException('Snapchat refresh must retain the exact client and selected account.');
        }
        $replacement = $this->tokens($scope, $this->oauth->refresh($current->value('refresh_token'), $this->budget($scope)));

        return isset($values['account_id']) ? $this->verifySelection($scope, $replacement->credentials,
            json_encode(['account_id' => $scope->accountId], JSON_THROW_ON_ERROR)) : $replacement;
    }

    public function discover(ProviderScope $scope, ProviderCredentials $credentials, string $nativeContext): ProviderAccountDiscovery
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $context = json_decode($nativeContext, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($context) || array_diff(array_keys($context), ['offset', 'fingerprint'])) {
            throw new InvalidArgumentException('Invalid Snapchat discovery continuation.');
        }
        $body = $this->client->get('me/organizations', ['with_ad_accounts' => 'true'], $credentials, $this->budget($scope));
        $organizations = $body['organizations'] ?? null;
        if (! is_array($organizations) || ! array_is_list($organizations) || count($organizations) > 1000) {
            throw new SnapchatRequestFailed('snapchat_discovery_unverified');
        }
        $accounts = [];
        // Unimplemented native paging is explicit partial coverage; arbitrary next URLs are never followed.
        $partial = ! empty($body['paging']);
        foreach ($organizations as $wrapper) {
            if (! is_array($wrapper)) {
                throw new SnapchatRequestFailed('snapchat_discovery_unverified');
            }
            if (! SnapchatClient::success($wrapper['sub_request_status'] ?? null)) {
                $partial = true;

                continue;
            }
            $rows = $wrapper['organization']['ad_accounts'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
                throw new SnapchatRequestFailed('snapchat_discovery_unverified');
            }
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                $roles = $row['roles'] ?? null;
                if (! SnapchatClient::accountId($id) || isset($accounts[$id]) || ! is_string($row['name'] ?? null)
                    || strlen($row['name']) > 200 || ! is_array($roles) || ! array_is_list($roles) || count($roles) > 20
                    || count($accounts) >= 10000) {
                    throw new SnapchatRequestFailed('snapchat_discovery_unverified');
                }
                foreach ($roles as $role) {
                    if (! is_string($role) || ! preg_match('/^[a-z_]{1,30}$/D', $role)) {
                        throw new SnapchatRequestFailed('snapchat_discovery_unverified');
                    }
                }
                sort($roles, SORT_STRING);
                $roleText = implode(',', array_unique($roles));
                if (strlen($roleText) > 200) {
                    throw new SnapchatRequestFailed('snapchat_discovery_unverified');
                }
                $accounts[$id] = ['id' => $id, 'label' => $row['name'], 'native_role' => $roleText === '' ? null : $roleText];
            }
        }
        ksort($accounts, SORT_STRING);
        $accounts = array_values($accounts);
        $fingerprint = hash('sha256', json_encode([$scope->jsonSerialize(), $accounts, $partial,
            hash('sha256', $credentials->value('access_token'))], JSON_THROW_ON_ERROR));
        $offset = $context['offset'] ?? 0;
        if (! is_int($offset) || $offset < 0 || ($context !== [] && (($context['fingerprint'] ?? null) !== $fingerprint
            || $offset >= count($accounts)))) {
            throw new InvalidArgumentException('Snapchat discovery snapshot changed.');
        }
        $slice = array_slice($accounts, $offset, 100);
        $nextOffset = $offset + count($slice);
        $next = $nextOffset < count($accounts) ? new NativeProgress('snapchat.embedded_accounts', self::BASIS,
            json_encode(['offset' => $nextOffset, 'fingerprint' => $fingerprint], JSON_THROW_ON_ERROR)) : null;

        return new ProviderAccountDiscovery($scope, $slice, self::BASIS, $partial || $next !== null ? 'PARTIAL' : 'COMPLETE', $next);
    }

    public function verifySelection(ProviderScope $scope, ProviderCredentials $credentials, string $nativeSelection): ProviderAccessMaterial
    {
        $this->context($scope, '{}');
        $this->usable($credentials);
        $selection = json_decode($nativeSelection, true, 16, JSON_THROW_ON_ERROR);
        $boundAccount = $credentials->custodyPayload()['account_id'] ?? null;
        if (! is_array($selection) || array_keys($selection) !== ['account_id']
            || ($selection['account_id'] ?? null) !== $scope->accountId || ! SnapchatClient::accountId($scope->accountId)
            || ($boundAccount !== null && $boundAccount !== $scope->accountId)) {
            throw new InvalidArgumentException('Exact Snapchat account selection required.');
        }
        $budget = $this->budget($scope);
        $body = $this->client->get('adaccounts/'.$scope->accountId, [], $credentials, $budget);
        $rows = $body['adaccounts'] ?? null;
        $wrapper = is_array($rows) ? ($rows[0] ?? null) : null;
        $account = $wrapper['adaccount'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($wrapper)
            || ! SnapchatClient::success($wrapper['sub_request_status'] ?? null) || ! is_array($account)
            || ($account['id'] ?? null) !== $scope->accountId || ! is_string($account['currency'] ?? null)
            || ! preg_match('/^[A-Z]{3}$/D', $account['currency'])
            || ! in_array($account['timezone'] ?? null, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new SnapchatRequestFailed('snapchat_account_metadata_unverified');
        }
        $end = new DateTimeImmutable('today', new DateTimeZone($account['timezone']));
        $start = $end->modify('-1 day');
        $probe = $this->client->get('adaccounts/'.$scope->accountId.'/stats', ['granularity' => 'TOTAL', 'fields' => 'impressions',
            'start_time' => $start->format(DATE_ATOM), 'end_time' => $end->format(DATE_ATOM)], $credentials, $budget);
        $stats = $probe['total_stats'] ?? null;
        $entry = is_array($stats) ? ($stats[0] ?? null) : null;
        $stat = $entry['total_stat'] ?? null;
        if (! is_array($stats) || ! array_is_list($stats) || count($stats) !== 1 || ! is_array($entry)
            || ! SnapchatClient::success($entry['sub_request_status'] ?? null) || ! is_array($stat)
            || ($stat['id'] ?? null) !== $scope->accountId || ($stat['type'] ?? null) !== 'AD_ACCOUNT'
            || ($stat['granularity'] ?? null) !== 'TOTAL' || ! is_array($stat['stats'] ?? null)) {
            throw new SnapchatRequestFailed('snapchat_report_permission_unverified');
        }
        $values = $credentials->custodyPayload();
        $this->usable($credentials);
        $values['account_id'] = $scope->accountId;
        $values['account_currency'] = $account['currency'];
        $values['account_timezone'] = $account['timezone'];
        $expiry = new DateTimeImmutable('@'.$values['expires_at']);

        return new ProviderAccessMaterial($scope, new ProviderCredentials($values), $expiry, 'CONNECTED', $this->proof(true, $expiry));
    }

    private function tokens(ProviderScope $scope, array $native): ProviderAccessMaterial
    {
        $expiry = (new DateTimeImmutable)->modify('+'.$native['expires_in'].' seconds');
        $credentials = new ProviderCredentials(['access_token' => $native['access_token'], 'refresh_token' => $native['refresh_token'],
            'client_id' => $this->oauth->clientId, 'expires_at' => (string) $expiry->getTimestamp()]);

        return new ProviderAccessMaterial($scope, $credentials, $expiry, 'TOKEN_ACQUIRED', $this->proof(false, $expiry));
    }

    private function usable(ProviderCredentials $credentials): void
    {
        $values = $credentials->custodyPayload();
        if (($values['client_id'] ?? null) !== $this->oauth->clientId || ! is_string($values['expires_at'] ?? null)
            || ! preg_match('/^[1-9][0-9]{0,10}$/D', $values['expires_at']) || (int) $values['expires_at'] <= time()) {
            throw new SnapchatRequestFailed('snapchat_reconnect_required');
        }
    }

    private function context(ProviderScope $scope, string $context): void
    {
        if ($scope->provider !== Provider::Snapchat || $scope->purpose !== 'advertising_analysis'
            || ! in_array($scope->operation, ['discovery.read', 'report.read'], true) || $context !== '{}') {
            throw new InvalidArgumentException('Exact Snapchat lifecycle scope required.');
        }
    }

    private function budget(ProviderScope $scope): ProviderReadBudget
    {
        return new ProviderReadBudget($scope, 'snapchat.access', 4, 0, 4, 10000, new DateTimeImmutable('+45 seconds'), 1, 5);
    }

    private function proof(bool $selected, DateTimeImmutable $expiry): array
    {
        $at = new DateTimeImmutable;
        // A local evidence lease, never an assertion of native token lifetime or legal retention.
        $until = min($expiry, $at->modify('+5 minutes'));
        $proof = [];
        foreach (EffectiveCapability::AXES as $axis) {
            $proof[$axis] = ['state' => $axis === 'credential' || ($selected && $axis === 'provider_permission') ? 'VERIFIED' : 'UNKNOWN',
                'provenance' => $axis === 'provider_permission' ? 'https://developers.snap.com/api/marketing-api/Ads-API/measurement'
                    : 'https://developers.snap.com/api/marketing-api/Ads-API/authentication',
                'verified_at' => $at->format(DATE_ATOM), 'valid_until' => $until->format(DATE_ATOM)];
        }

        return $proof;
    }
}
