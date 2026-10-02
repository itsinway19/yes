<?php

namespace App\Providers\Ads\X;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RedisStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

/** Bounded account campaign picker. Browser continuation is correlation, never native authority. */
final readonly class XCampaignChoices
{
    public function __construct(private Factory $http, private ProviderAccessStore $custody, private ProviderRequestGuard $requests) {}

    public function page(Request $request, ProviderScope $scope, int $version, ?string $handle): array
    {
        if ($scope->provider !== Provider::X || $scope->operation !== 'report.read'
            || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $scope->accountId) || DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Campaign choices require the exact signed X account transaction.');
        }
        $settings = XReadSettings::configured();
        $this->requests->requireCurrent($request, $scope, 'provider.read', $version);
        (new XAccessStatus)->requireReport($request, $scope);
        $credentials = $this->custody->credentials($request, $scope);
        $cache = app()->runningUnitTests() ? Cache::store() : Cache::store('redis');
        if (! app()->runningUnitTests() && ! $cache->getStore() instanceof RedisStore) {
            throw new LogicException('Campaign correlation requires shared Redis.');
        }
        $binding = [$scope->jsonSerialize(), $version, $request->user()->getAuthIdentifier(),
            (int) $request->session()->get('a001_generation', 0), SessionAuthority::sessionKey($request), $settings->binding()];
        $cursor = null;
        $seen = [];
        if ($handle !== null) {
            if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle)) {
                throw new AuthorizationException('Invalid campaign continuation.');
            }
            try {
                $cipher = $cache->get('bb2.x.campaigns.'.hash('sha256', $handle));
                $saved = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, 32, JSON_THROW_ON_ERROR) : null;
                if (($saved['binding'] ?? null) !== $binding || ($saved['until'] ?? 0) <= now()->timestamp
                    || ! is_string($saved['cursor'] ?? null) || ! is_array($saved['seen'] ?? null)) {
                    throw new AuthorizationException;
                }
                $cursor = $saved['cursor'];
                $seen = $saved['seen'];
            } catch (\Throwable) {
                throw new AuthorizationException('Campaign continuation changed or expired.');
            }
        }
        $params = ['count' => '200', 'with_deleted' => 'true', 'with_draft' => 'true'];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }
        $url = 'https://ads-api.x.com/12/accounts/'.$scope->accountId.'/campaigns';
        $cooldown = new XReadCooldown($cache, $scope, $credentials);
        $cooldown->before('campaigns');
        $this->requests->requireCurrent($request, $scope, 'provider.read', $version);
        try {
            $response = $this->http->acceptJson()->withHeaders(['Authorization' => $settings->signer()->header('GET', $url, $params, $credentials)])
                ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->get($url, $params);
            $cooldown->observe($response, 'campaigns');
            if ($response->status() !== 200) {
                throw new XRequestFailed('x_campaign_discovery_failed');
            }
            if (strlen($response->body()) > 4194304) {
                throw new XRequestFailed('x_campaign_discovery_unverified');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (ConnectionException) {
            throw new XRequestFailed('x_transport_failed');
        } catch (JsonException) {
            throw new XRequestFailed('x_campaign_discovery_unverified');
        }
        $this->requests->requireCurrent($request, $scope, 'provider.read', $version);
        if (! is_array($body) || ! empty($body['errors']) || ($body['request']['params']['account_id'] ?? null) !== $scope->accountId
            || ($body['request']['params']['cursor'] ?? null) !== $cursor || ! is_array($body['data'] ?? null)
            || ! array_is_list($body['data']) || count($body['data']) > 200) {
            throw new XRequestFailed('x_campaign_discovery_unverified');
        }
        $choices = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $row['id'])
                || isset($choices[$row['id']]) || ! is_string($row['name'] ?? null)) {
                throw new XRequestFailed('x_campaign_discovery_unverified');
            }
            $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $row['name']);
            $choices[$row['id']] = ['id' => $row['id'], 'label' => mb_substr($name ?? '', 0, 200)];
        }
        $next = $body['next_cursor'] ?? null;
        $continuation = null;
        if ($next !== null) {
            if (! is_string($next) || $next === '' || strlen($next) > 8192 || preg_match('/[\x00-\x20\x7f]/', $next)
                || $next === $cursor || in_array(hash('sha256', $next), $seen, true) || count($seen) >= 100) {
                throw new XRequestFailed('x_campaign_discovery_unverified');
            }
            $seen[] = hash('sha256', $next);
            $continuation = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $key = 'bb2.x.campaigns.'.hash('sha256', $continuation);
            $cipher = Crypt::encryptString(json_encode(['binding' => $binding, 'cursor' => $next, 'seen' => $seen, 'until' => now()->timestamp + 300], JSON_THROW_ON_ERROR));
            DB::afterCommit(function () use ($cache, $key, $cipher): void {
                if (! $cache->add($key, $cipher, 300)) {
                    throw new LogicException('Campaign continuation could not be stored.');
                }
            });
        }

        return ['campaigns' => array_values($choices), 'continuation' => $continuation];
    }
}
