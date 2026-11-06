<?php

use App\Providers\Ads\Google\GoogleOAuthClient;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;

// Explicitly bounded browser fixture. Actual OAuth state/callback and authority
// run unchanged; only Google responses are synthetic, with stray traffic denied.
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (getenv('GOALS_SPA_PROOF') !== 'enabled' || getenv('GOALS_SPA_ONLY') !== 'businesses'
    || Cache::store('redis')->get('shared-source-return-runtime') !== true) {
    throw new RuntimeException('Isolated shared-source return proof required.');
}
config()->set('cache.default', 'redis');
config()->set('app.url', 'https://builderad.example');
config()->set('services.google_ads', ['version' => 'v25', 'client_id' => 'synthetic.apps.googleusercontent.com',
    'client_secret' => 'synthetic-client-secret', 'developer_token' => 'synthetic-developer-token',
    'redirect_uri' => 'https://builderad.example/providers/google/callback',
    'privacy_url' => 'https://builderad.example/privacy', 'notice_revision' => '1']);
$http = new Factory;
$http->preventStrayRequests();
$http->fake([
    'https://oauth2.googleapis.com/token' => $http->response(['access_token' => 'synthetic-access',
        'refresh_token' => 'synthetic-refresh', 'scope' => GoogleOAuthClient::SCOPE, 'token_type' => 'Bearer', 'expires_in' => 3600]),
    'https://googleads.googleapis.com/*' => $http->response(['resourceNames' => []]),
]);
app()->instance(Factory::class, $http);
