<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\NativeProgress;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccountDiscovery;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderReadBudget;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/** Native discovery page only. Continuations require encrypted server custody before cross-request use. */
final readonly class XAccountDiscovery
{
    public const BASIS = 'https://docs.x.com/x-ads-api/campaign-management/reference#accounts';

    public function __construct(private Factory $http, private XOAuthSigner $signer, private string $consumerKey,
        private \Closure $currentAuthority) {}

    public function page(ProviderReadBudget $budget, #[\SensitiveParameter] ProviderCredentials $credentials,
        ?NativeProgress $continuation = null): ProviderAccountDiscovery
    {
        $scope = $budget->scope;
        if ($scope->provider !== Provider::X || $scope->accountId !== $this->consumerKey
            || $scope->operation !== 'discovery.read' || $scope->purpose !== 'advertising_analysis') {
            throw new InvalidArgumentException('Exact X application discovery scope required.');
        }
        $query = ['count' => '200'];
        $seen = [];
        if ($continuation !== null) {
            try {
                $record = json_decode($continuation->envelope()['payload'], true, 16, JSON_THROW_ON_ERROR);
                if ($continuation->kind !== 'x.accounts.cursor' || $continuation->basis !== self::BASIS
                    || ! is_array($record) || array_keys($record) !== ['cursor', 'seen', 'binding'] || ! $this->cursor($record['cursor'])
                    || ! is_array($record['seen']) || ! array_is_list($record['seen'])
                    || ! is_string($record['binding']) || ! hash_equals($this->binding($budget, $credentials, $record['cursor'], $record['seen']), $record['binding'])) {
                    throw new InvalidArgumentException;
                }
                foreach ($record['seen'] as $hash) {
                    if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                        throw new InvalidArgumentException;
                    }
                }
                $query['cursor'] = $record['cursor'];
                $seen = $record['seen'];
            } catch (\Exception) {
                throw new InvalidArgumentException('X discovery continuation changed or is invalid.');
            }
        }
        $url = 'https://ads-api.x.com/12/accounts';
        $header = $this->signer->header('GET', $url, $query, $credentials);
        ($this->currentAuthority)($scope, 'provider.connect');
        $at = new DateTimeImmutable;
        $budget->claimRequest($at);
        $seconds = max(1, min(15, $budget->deadline->getTimestamp() - $at->getTimestamp()));
        try {
            $response = $this->http->acceptJson()->withHeaders(['Authorization' => $header])->connectTimeout(min(5, $seconds))
                ->timeout($seconds)->withOptions(['allow_redirects' => false])->get($url, $query);
            if ($response->status() !== 200) {
                throw new RuntimeException(match ($response->status()) {
                    401 => 'x_credential_denied', 403 => 'x_access_denied', 429 => 'x_rate_limited',
                    500, 502, 503, 504 => 'x_service_unavailable', default => 'x_discovery_failed',
                });
            }
            if (strlen($response->body()) > 4194304) {
                throw new RuntimeException('x_discovery_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($body) || isset($body['errors']) || ! is_array($body['request']['params'] ?? null)
                || ! is_array($body['data'] ?? null) || ! array_is_list($body['data']) || count($body['data']) > 200
                || ! array_key_exists('next_cursor', $body) || ($body['next_cursor'] !== null && ! $this->cursor($body['next_cursor']))) {
                throw new RuntimeException('x_discovery_unverified');
            }
            $params = $body['request']['params'];
            if ((isset($params['count']) && ! in_array($params['count'], [200, '200'], true))
                || (array_key_exists('cursor', $params) && $params['cursor'] !== ($query['cursor'] ?? null))
                || ! empty($params['account_ids']) || ! empty($params['q'])) {
                throw new RuntimeException('x_discovery_unverified');
            }
            $accounts = [];
            foreach ($body['data'] as $row) {
                $id = $row['id'] ?? null;
                if (! is_array($row) || ! is_string($id) || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $id) || isset($accounts[$id])
                    || ! is_string($row['name'] ?? null) || strlen($row['name']) > 8192 || ! mb_check_encoding($row['name'], 'UTF-8')
                    || ! is_bool($row['deleted'] ?? null)) {
                    throw new RuntimeException('x_discovery_unverified');
                }
                // Do not infer permissions, account currency or approval from membership in the list.
                $accounts[$id] = ['id' => $id, 'label' => mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', ' ', $row['name']), 0, 200, 'UTF-8'), 'native_role' => null];
                if ($row['deleted']) {
                    throw new RuntimeException('x_discovery_unverified');
                }
            }
            $next = $body['next_cursor'];
            $progress = null;
            if ($next !== null) {
                $hash = hash('sha256', $next);
                if (in_array($hash, $seen, true)) {
                    throw new RuntimeException('x_discovery_unverified');
                }
                $seen[] = $hash;
                $payload = json_encode(['cursor' => $next, 'seen' => $seen, 'binding' => $this->binding($budget, $credentials, $next, $seen)], JSON_THROW_ON_ERROR);
                if (strlen($payload) > 32768) {
                    throw new RuntimeException('x_discovery_continuation_limit');
                }
                $progress = new NativeProgress('x.accounts.cursor', self::BASIS, $payload);
            }
            $budget->acceptPage(count($accounts), new DateTimeImmutable);

            return new ProviderAccountDiscovery($scope, array_values($accounts), self::BASIS, $progress === null ? 'COMPLETE' : 'PARTIAL', $progress);
        } catch (ConnectionException) {
            throw new RuntimeException('x_transport_failed');
        } catch (JsonException) {
            throw new RuntimeException('x_discovery_unverified');
        }
    }

    private function cursor(mixed $cursor): bool
    {
        return is_string($cursor) && $cursor !== '' && strlen($cursor) <= 8192 && ! preg_match('/[\x00-\x1f\x7f]/', $cursor);
    }

    private function binding(ProviderReadBudget $budget, ProviderCredentials $credentials, string $cursor, array $seen): string
    {
        return hash_hmac('sha256', json_encode([$budget->scope->jsonSerialize(), $credentials->value('oauth_token'), $cursor, $seen], JSON_THROW_ON_ERROR),
            $credentials->value('oauth_token_secret'));
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'x', 'credentials' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('X discovery signing context cannot be serialized.');
    }
}
