<?php

// Explicit disposable browser fixture. Not loaded by application routing or production code.
use App\Commercial\ApprovedCatalogReference;
use App\Commercial\MarketRegistry;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$runRoot = $argv[1];
$owner = DB::connection('pgsql_migrations');
$owner->statement('TRUNCATE TABLE users, workspaces, commercial_markets, commercial_scopes, commercial_command_results, security_commercial_audit_custody, security_commercial_mandate_executions RESTART IDENTITY CASCADE');
$operator = User::factory()->create(['name' => 'Synthetic SA-01 operator']);
$issuer = User::factory()->create(['name' => 'Synthetic distinct grant issuer']);
foreach ([$operator, $issuer] as $staff) {
    $owner->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
    $owner->table('internal_personnel')->insert(['user_id' => $staff->id, 'kind' => 'employee', 'status' => 'active',
        'attested_at' => now(), 'attested_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);
    $owner->table('passkeys')->insert(['user_id' => $staff->id, 'name' => 'Synthetic session eligibility fixture',
        'credential_id' => 'synthetic-browser-'.$staff->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
}
$grant = function (User $user, string $effect, string $target, ?int $workspace = null, ?string $version = null) use ($owner, $issuer): void {
    $owner->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'granted_by' => $issuer->id,
        'source' => 'human', 'action' => $effect, 'scope_kind' => 'commercial', 'commercial_target_id' => $target,
        'commercial_version_id' => $version, 'workspace_id' => $workspace, 'subject_generation' => 1,
        'reason' => 'synthetic_browser_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
};
$sessionFixture = function (User $user, bool $internal) use ($app): array {
    config()->set('session.cookie', config($internal ? 'session.internal_cookie' : 'session.advertiser_cookie'));
    config()->set('session.path', $internal ? '/internal' : '/');
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    Auth::forgetGuards();
    $session = $app['session']->driver();
    $session->start();
    $request = Request::create($internal ? '/internal/commercial' : '/', 'GET');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    Auth::guard($internal ? 'internal' : 'web')->login($user);
    app(SessionAuthority::class)->issue($request, $user, $internal ? 'internal_passkey' : 'password');
    $session->save();
    $cookie = Crypt::encryptString(CookieValuePrefix::create($session->getName(), app('encrypter')->getKey()).$session->getId());

    return [$request, ['name' => $session->getName(), 'value' => $cookie, 'path' => $internal ? '/internal' : '/']];
};
[$request, $internalCookie] = $sessionFixture($operator, true);
$public = [];
foreach (['Saudi Arabia' => 'SAR', 'Jordan' => 'JOD'] as $label => $denomination) {
    $market = (string) Str::uuid7();
    $catalog = (string) Str::uuid7();
    $configuration = (string) Str::uuid7();
    foreach (['ReadCommercialMarketRegistry', 'ConfigureCommercialMarket', 'EnableCommercialMarket', 'DisableCommercialMarket', 'ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState', 'RetryOrRecoverExistingAuditDelivery'] as $effect) {
        $grant($operator, $effect, $market);
    }
    $grant($operator, 'RecordOrVerifyApprovedMarketReference', $market, null, $catalog);
    DB::connection('pgsql_authority')->transaction(function () use ($request, $market, $catalog, $configuration, $label, $denomination): void {
        $base = ['command_id' => (string) Str::uuid7(), 'reason_code' => 'synthetic_browser_configuration', 'market_id' => $market];
        app(MarketRegistry::class)->configure($request, [...$base, 'label' => $label]);
        app(ApprovedCatalogReference::class)->record($request, [...$base, 'command_id' => (string) Str::uuid7(),
            'catalog_reference_id' => $catalog, 'catalog_identity' => (string) Str::uuid7(), 'catalog_version' => '1',
            'approval_reference' => 'synthetic:browser:approved', 'source_sha256' => hash('sha256', 'synthetic '.$label)]);
        app(MarketRegistry::class)->configuration($request, [...$base, 'command_id' => (string) Str::uuid7(),
            'configuration_id' => $configuration, 'catalog_reference_id' => $catalog, 'denomination_code' => $denomination]);
        if ($label === 'Saudi Arabia') {
            app(MarketRegistry::class)->assignability($request, [...$base, 'command_id' => (string) Str::uuid7(),
                'configuration_id' => $configuration, 'expected_revision' => 1]);
        }
    });
    $beneficiary = User::factory()->create(['name' => 'Synthetic '.$label.' beneficiary']);
    $workspace = $owner->table('workspaces')->insertGetId(['name' => 'Synthetic '.$label.' Workspace', 'slug' => 'synthetic-'.$market,
        'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
    $owner->table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $beneficiary->id, 'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
    $owner->table('workspace_ownerships')->insert(['workspace_id' => $workspace, 'responsible_owner_user_id' => $beneficiary->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $owner->table('security_action_grants')->insert(['subject_user_id' => $beneficiary->id, 'source' => 'system_workspace_creation',
        'action' => 'workspace.view', 'scope_kind' => 'workspace', 'workspace_id' => $workspace, 'subject_generation' => 1,
        'reason' => 'synthetic_browser_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $scope = (string) Str::uuid7();
    foreach (['RegisterCommercialScope', 'RecordBeneficiaryBinding', 'ReadCommercialContextInternal', 'ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState', 'RetryOrRecoverExistingAuditDelivery'] as $effect) {
        $grant($operator, $effect, $scope, $workspace);
    }
    $grant($operator, 'AssignCommercialMarket', $scope, $workspace, $configuration);
    $grant($beneficiary, 'ReadCommercialContextAdvertiser', $scope, $workspace);
    $workspacePublic = $owner->table('workspaces')->where('id', $workspace)->value('public_id');
    $public[$label] = ['market' => $market, 'configuration' => $configuration, 'catalog' => $catalog, 'scope' => $scope, 'workspace' => $workspacePublic];
    if ($label === 'Saudi Arabia') {
        $advertiser = $beneficiary;
    }
}
[, $advertiserCookie] = $sessionFixture($advertiser, false);
$token = bin2hex(random_bytes(16));
file_put_contents($runRoot.'/browser-cookies.json', json_encode(['internal' => $internalCookie, 'advertiser' => $advertiserCookie], JSON_THROW_ON_ERROR));
$router = <<<'PHP'
<?php
// Disposable-only cookie delivery for sessions issued by the real SessionAuthority fixture.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$run = __DIR__;
$token = trim(file_get_contents($run.'/browser-token'));
foreach (['internal' => '/internal/commercial', 'advertiser' => '/workspaces/'] as $kind => $destination) {
    if ($path === '/__sa01/'.$token.'/'.$kind) {
        $cookie = json_decode(file_get_contents($run.'/browser-cookies.json'), true)[$kind];
        setcookie($cookie['name'], $cookie['value'], ['path' => $cookie['path'], 'httponly' => true, 'samesite' => 'Lax']);
        if ($kind === 'advertiser') { $destination .= trim(file_get_contents($run.'/browser-workspace')); }
        header('Location: '.$destination); exit;
    }
}
$file = getcwd().'/public'.$path;
if ($path !== '/' && is_file($file)) { return false; }
require getcwd().'/public/index.php';
PHP;
file_put_contents($runRoot.'/browser-router.php', $router);
file_put_contents($runRoot.'/browser-token', $token);
file_put_contents($runRoot.'/browser-workspace', $public['Saudi Arabia']['workspace']);
echo json_encode(['fixtures' => $public, 'internal_url' => 'http://127.0.0.1:8765/__sa01/'.$token.'/internal',
    'advertiser_url' => 'http://127.0.0.1:8765/__sa01/'.$token.'/advertiser'], JSON_THROW_ON_ERROR).PHP_EOL;
