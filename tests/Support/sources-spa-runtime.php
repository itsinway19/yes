<?php

use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;

if (getenv('GOALS_SPA_PROOF') !== 'enabled' || getenv('GOALS_SPA_ONLY') !== 'sources') {
    throw new RuntimeException('Explicit isolated Source proof required');
}
app(DisposableTestDatabaseGuard::class)->assertSafe();
config()->set('app.url', 'https://builderad.example');
config()->set('cache.default', 'redis');
config()->set('services.meta_ads', ['version' => 'v26.0', 'app_id' => '777', 'app_secret' => 'synthetic-app-secret', 'redirect_uri' => 'https://builderad.example/providers/meta/callback', 'configuration_id' => '999', 'token_kind' => 'BUSINESS_INTEGRATION_SYSTEM_USER', 'privacy_url' => 'https://builderad.example/privacy', 'deletion_instructions_url' => 'https://builderad.example/data-deletion', 'notice_revision' => 'synthetic-notice-v1']);
config()->set('services.google_ads', ['version' => 'v25', 'client_id' => 'synthetic.apps.googleusercontent.com', 'client_secret' => 'synthetic-client-secret', 'developer_token' => 'synthetic-developer-token', 'redirect_uri' => 'https://builderad.example/providers/google/callback', 'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => '1']);
config()->set('services.tiktok_ads', ['version' => 'v1.3', 'app_id' => '5555555555555555555', 'app_secret' => 'synthetic-tiktok-secret', 'authorization_url' => 'https://business-api.tiktok.com/portal/auth', 'redirect_uri' => 'https://builderad.example/providers/tiktok/callback', 'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => 'synthetic-notice-v1']);
config()->set('services.snapchat_ads', ['client_id' => 'synthetic-snap-client', 'client_secret' => 'synthetic-snap-secret', 'redirect_uri' => 'https://builderad.example/providers/snapchat/callback', 'privacy_url' => 'https://builderad.example/synthetic-privacy', 'notice_revision' => 'synthetic:1']);
config()->set('services.x_ads', ['consumer_key' => 'synthetic-x-key', 'consumer_secret' => 'synthetic-app-secret', 'redirect_uri' => 'https://builderad.example/providers/x/callback', 'privacy_url' => 'https://builderad.example/synthetic-privacy', 'notice_revision' => 'synthetic:1']);
error_log('Source fixture context: '.json_encode(['storage' => storage_path(), 'key_hash' => hash('sha256', (string) config('app.key')),
    'meta_config_hash' => hash('sha256', json_encode(config('services.meta_ads')))], JSON_THROW_ON_ERROR));
$http = new Factory;
$http->preventStrayRequests();
$http->fake(function (ClientRequest $request) use ($http) {
    $url = $request->url();
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    $fields = $request->data();
    error_log('Synthetic Source request: '.$host.$path.' '.($fields['query'] ?? ''));
    if ($host === 'graph.facebook.com') {
        if (str_ends_with($path, '/oauth/access_token')) {
            return $http->response(['access_token' => 'synthetic-web-token']);
        }
        if (str_ends_with($path, '/debug_token')) {
            return $http->response(['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'app_id' => '777', 'user_id' => '888', 'scopes' => ['ads_read'], 'expires_at' => 0, 'granular_scopes' => [['scope' => 'ads_read', 'target_ids' => ['123']]]]]);
        }
        if (str_ends_with($path, '/me')) {
            return $http->response(['id' => '888', 'client_business_id' => '555']);
        }
        if (str_ends_with($path, '/act_123')) {
            return $http->response(['id' => 'act_123', 'account_id' => '123', 'name' => 'Synthetic Meta advertiser', 'currency' => 'USD', 'timezone_name' => 'UTC']);
        }
        if (str_ends_with($path, '/insights')) {
            return $http->response(['data' => []]);
        }
    }
    if ($host === 'oauth2.googleapis.com') {
        if (Cache::get('source-proof:google-offline-missing')) {
            return $http->response(['access_token' => 'synthetic-new-access', 'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }

        return $http->response(['access_token' => 'synthetic-new-access', 'refresh_token' => 'synthetic-new-refresh', 'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
    if ($host === 'googleads.googleapis.com') {
        if (str_contains($fields['query'] ?? '', 'FROM campaign WHERE')) {
            return $http->response(['fieldMask' => 'customer.id,customer.currencyCode,customer.timeZone,campaign.id,campaign.resourceName,campaign.status,metrics.costMicros,metrics.impressions,metrics.clicks,metrics.conversions,metrics.allConversions,metrics.conversionsValue,segments.date', 'results' => []]);
        }
        if (str_contains($path, 'listAccessibleCustomers')) {
            return $http->response(['resourceNames' => ['customers/9999999999', 'customers/1234567890']]);
        }
        if (str_contains($fields['query'] ?? '', 'customer_client')) {
            return $http->response(['fieldMask' => 'customerClient.id,customerClient.clientCustomer,customerClient.descriptiveName,customerClient.level,customerClient.manager', 'results' => [['customerClient' => ['id' => '1234567890', 'clientCustomer' => 'customers/1234567890', 'descriptiveName' => 'Synthetic Google child', 'level' => '1', 'manager' => false]]]]);
        }
        if (str_contains($fields['query'] ?? '', 'FROM customer')) {
            $manager = str_contains($path, '/9999999999/');

            return $http->response(['fieldMask' => 'customer.id,customer.manager,customer.currencyCode,customer.timeZone', 'results' => [['customer' => ['id' => $manager ? '9999999999' : '1234567890', 'manager' => $manager, 'currencyCode' => 'JOD', 'timeZone' => 'Asia/Amman']]]]);
        }

        return $http->response(['fieldMask' => 'campaign.id', 'results' => []]);
    }
    if ($host === 'business-api.tiktok.com') {
        if (str_contains($path, 'oauth2/access_token')) {
            return $http->response(['code' => 0, 'data' => ['access_token' => 'synthetic-tiktok-token']]);
        }
        if (str_contains($path, 'oauth2/advertiser/get')) {
            return $http->response(['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'advertiser_name' => 'Synthetic TikTok']]]]);
        }
        if (str_contains($path, 'advertiser/info')) {
            return $http->response(['code' => 0, 'data' => ['list' => [['advertiser_id' => '1234567890123456789', 'currency' => 'JOD', 'timezone' => 'Asia/Amman']]]]);
        }

        return $http->response(['code' => 0, 'data' => ['list' => [], 'page_info' => ['page' => 1, 'total_page' => 1]]]);
    }
    if ($host === 'accounts.snapchat.com') {
        if (Cache::get('source-proof:snapchat-deny')) {
            return $http->response(['error' => 'invalid_grant'], 400);
        }

        return $http->response(['access_token' => 'synthetic-snap-access', 'refresh_token' => 'synthetic-snap-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }
    if ($host === 'adsapi.snapchat.com') {
        $account = ['id' => '8b8e40af-fc64-455d-925b-ca80f7af6914', 'name' => 'Synthetic Snapchat advertiser', 'currency' => 'USD', 'timezone' => 'America/Los_Angeles', 'roles' => ['reports']];
        if (str_contains($path, 'me/organizations')) {
            return $http->response(['request_status' => 'SUCCESS', 'organizations' => [['sub_request_status' => 'SUCCESS', 'organization' => ['ad_accounts' => [$account]]]]]);
        }
        if (str_ends_with($path, '/stats')) {
            return $http->response(['request_status' => 'SUCCESS', 'total_stats' => [['sub_request_status' => 'SUCCESS', 'total_stat' => ['id' => $account['id'], 'type' => 'AD_ACCOUNT', 'granularity' => 'TOTAL', 'stats' => ['impressions' => 0], 'start_time' => $fields['start_time'] ?? null, 'end_time' => $fields['end_time'] ?? null, 'breakdown_stats' => ['campaign' => []]]]]]);
        }

        return $http->response(['request_status' => 'SUCCESS', 'adaccounts' => [['sub_request_status' => 'success', 'adaccount' => $account]]]);
    }
    if (in_array($host, ['api.x.com', 'api.twitter.com'], true)) {
        if (str_ends_with($path, '/request_token')) {
            return $http->response('oauth_token=synthetic-request-'.bin2hex(random_bytes(16)).'&oauth_token_secret=synthetic-temporary-secret&oauth_callback_confirmed=true');
        }
        if (str_ends_with($path, '/access_token')) {
            return $http->response('oauth_token=synthetic-user-token&oauth_token_secret=synthetic-user-secret');
        }
    }
    if (in_array($host, ['ads-api.x.com', 'ads-api.twitter.com'], true)) {
        if (str_ends_with($path, '/accounts')) {
            return $http->response(['request' => ['params' => ['count' => 200]], 'next_cursor' => null, 'data' => [['id' => 'abc123', 'name' => 'Synthetic X advertiser', 'deleted' => false]]]);
        }
        if (str_ends_with($path, '/authenticated_user_access')) {
            return $http->response(['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['permissions' => ['CAMPAIGN_ANALYST']]]);
        }
        if (str_ends_with($path, '/campaigns')) {
            if (isset($fields['campaign_ids'])) {
                return $http->response(['request' => ['params' => ['account_id' => 'abc123', 'campaign_ids' => explode(',', $fields['campaign_ids'])]], 'next_cursor' => null, 'data' => [['id' => 'c1', 'funding_instrument_id' => 'f1', 'currency' => 'USD']]]);
            }

            return $http->response(['request' => ['params' => ['account_id' => 'abc123']], 'next_cursor' => null, 'data' => [['id' => 'c1', 'name' => 'Synthetic X campaign']]]);
        }
        if (str_contains($path, '/stats/accounts/')) {
            foreach (['entity_ids', 'metric_groups'] as $key) {
                $fields[$key] = explode(',', $fields[$key]);
            }

            return $http->response(['request' => ['params' => $fields], 'data_type' => 'stats', 'time_series_length' => 1,
                'data' => [['id' => 'c1', 'id_data' => [['segment' => null, 'metrics' => ['billed_charge_local_micro' => ['0'], 'impressions' => ['0']]]]]]]);
        }

        return $http->response(['request' => ['params' => ['account_id' => 'abc123']], 'data' => ['id' => 'abc123', 'deleted' => false, 'timezone' => 'Asia/Amman', 'timezone_switch_at' => '2026-01-01T21:00:00Z', 'approval_status' => 'PENDING']]);
    }
    throw new RuntimeException('Unexpected synthetic provider path: '.$host.$path);
});
app()->instance(Factory::class, $http);
