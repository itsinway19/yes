<?php

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Ads\AdObservation;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\ObservationBatch;
use App\Providers\Ads\ObservationJournal;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderAccessStore;
use App\Providers\Ads\ProviderCredentials;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReportWindow;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use App\Sources\EffectiveCapability;
use App\Sources\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Included only after the caller has guarded and installed the exact baseline.
$owner = DB::connection('pgsql_migrations');
$actor = User::factory()->create(['name' => 'Synthetic baseline advertiser']);
app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
$request = Request::create('/', 'POST');
$request->setLaravelSession(app('session')->driver());
$request->setUserResolver(fn () => $actor);
app(SessionAuthority::class)->issue($request, $actor, 'password');
$type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
$workspaces = [];
foreach ([0, 1] as $index) {
    $workspace = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, 'Synthetic preserved '.$index, 'exact-baseline-'.$index));
    app(WorkspaceContext::class)->run($actor, $workspace['public_id'],
        fn (Workspace $active) => app(BusinessContext::class)->write($request, $active, ['business_type_id' => $type, 'version' => 0]), $request);
    if ($index === 1) {
        app(WorkspaceContext::class)->run($actor, $workspace['public_id'],
            fn (Workspace $active) => app(BusinessContext::class)->write($request, $active,
                ['business_type_id' => $type, 'name' => 'Synthetic preserved revision two', 'version' => 1]), $request);
    }
    $workspaces[] = $workspace;
}
$owner->table('business_source_guidance')->insert(['business_type_id' => $type, 'provider' => 'meta', 'priority' => 20,
    'rationale_ar' => 'إرشاد اصطناعي محفوظ', 'rationale_en' => 'Preserved synthetic guidance', 'active' => true]);
$source = (string) Str::uuid7();
$scope = new ProviderScope($workspaces[0]['id'], $source, Provider::Meta, 'synthetic-native-account', 'report.read', 'advertising_analysis');
foreach (GrantAuthority::SOURCE_ACTIONS as $action) {
    $owner->table('security_action_grants')->insert(['subject_user_id' => $actor->id, 'granted_by' => $actor->id,
        'source' => 'source_owner_request', 'scope_kind' => 'source', 'workspace_id' => $scope->workspaceId,
        'source_target_id' => $source, 'source_resource' => $scope->accountId, 'source_operation' => $scope->operation,
        'source_purpose' => $scope->purpose, 'action' => $action, 'subject_generation' => 1, 'status' => 'active',
        'reason' => 'synthetic_baseline_fixture', 'created_at' => now(), 'updated_at' => now()]);
}
$within = fn (callable $callback) => app(WorkspaceContext::class)->run($actor, $workspaces[0]['public_id'], $callback, $request);
$within(fn (Workspace $active) => app(SourceRegistry::class)->command($request, $active, [
    'source_id' => $source, 'command_id' => (string) Str::uuid(), 'effect' => 'register', 'family' => 'meta',
    'namespace' => 'ad_account', 'external_id' => $scope->accountId, 'label' => 'Synthetic Jordan account',
    'resource' => $scope->accountId, 'operation' => $scope->operation, 'purpose' => $scope->purpose,
    'expected_version' => 0, 'reason' => 'synthetic_registration',
]));
$verification = array_fill_keys(EffectiveCapability::AXES, ['state' => 'VERIFIED', 'provenance' => 'synthetic:baseline-verifier',
    'verified_at' => '2026-01-01T00:00:00Z', 'valid_until' => '2099-01-01T00:00:00Z']);
$within(fn () => app(ProviderAccessStore::class)->store($request, $scope, (string) Str::uuid(), 0,
    new ProviderCredentials(['access_token' => 'synthetic-baseline-private', 'account_country' => 'JO']), null, 'SYNTHETIC_AUTHORIZED', $verification));
$provenance = ['api_version' => 'synthetic:v1', 'report' => 'synthetic:campaign', 'documentation_basis' => 'synthetic:baseline'];
$observation = new AdObservation($scope, 'synthetic-campaign',
    new ReportWindow('2026-09-01', '2026-09-01', 'INCLUSIVE_DATES', 'DAY', 'Asia/Amman', 'synthetic:account-timezone'),
    new NativeAmount('12.50', 'JOD', 'major', 0, 'synthetic:provider-native'),
    ['account_country' => ['value' => 'JO', 'unit' => 'country_code', 'meaning' => 'synthetic:provider-native-country', 'basis' => 'PROVIDER_REPORTED']],
    $provenance, new DateTimeImmutable('2026-10-01T08:00:00Z'), null);
$within(fn () => app(ObservationJournal::class)->accept($request, new ObservationBatch($scope, [$observation], 'COMPLETE', null, null),
    'synthetic-baseline', (string) Str::uuid(), 0, 1, $provenance, null));
// Valid historical Commercial installation fixtures, with every FK/check active.
$market = (string) Str::uuid7();
$catalog = (string) Str::uuid7();
$configuration = (string) Str::uuid7();
$commercialScope = (string) Str::uuid7();
$owner->table('commercial_markets')->insert(['id' => $market, 'label' => 'Synthetic Jordan Commercial market']);
$owner->table('commercial_catalog_references')->insert(['id' => $catalog, 'market_id' => $market, 'catalog_identity' => (string) Str::uuid7(),
    'catalog_version' => 'baseline-v1', 'approval_reference' => 'synthetic:baseline', 'source_sha256' => hash('sha256', 'synthetic baseline catalog')]);
$owner->table('commercial_market_configuration_versions')->insert(['id' => $configuration, 'market_id' => $market,
    'version' => 1, 'catalog_reference_id' => $catalog, 'denomination_code' => 'JOD']);
$owner->table('commercial_market_assignability')->insert(['market_id' => $market, 'configuration_id' => $configuration, 'revision' => 1]);
$owner->table('commercial_scopes')->insert(['id' => $commercialScope]);
$owner->table('commercial_beneficiary_bindings')->insert(['scope_id' => $commercialScope, 'workspace_id' => $workspaces[0]['id'], 'decided_at' => now(), 'effective_at' => now()]);
$owner->table('commercial_market_assignments')->insert(['scope_id' => $commercialScope, 'workspace_id' => $workspaces[0]['id'],
    'market_id' => $market, 'configuration_id' => $configuration, 'catalog_reference_id' => $catalog, 'denomination_code' => 'JOD', 'decided_at' => now(), 'effective_at' => now()]);

return ['actor' => $actor, 'request' => $request, 'workspaces' => $workspaces];
