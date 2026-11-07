<?php

use App\Models\User;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (getenv('GOALS_SPA_PROOF') !== 'enabled' || getenv('GOALS_SPA_ONLY') !== 'data-licenses' || (int) config('database.redis.default.port') !== 6397) {
    throw new RuntimeException('Guarded data licence browser fixture required.');
}
$mode = $argv[1] ?? 'setup';
$db = DB::connection('pgsql_migrations');
require __DIR__.'/sources-spa-runtime.php';
if ($mode === 'setup') {
    $email = 'g01-c02-'.Str::uuid().'@example.test';
    $user = User::factory()->create(['name' => 'Synthetic advertiser', 'email' => $email, 'password' => Hash::make('DisposableGoalsProof42!')]);
    if (($argv[2] ?? '') === 'agency') {
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
    }
    echo json_encode(['status' => 'completed', 'email' => $email, 'public_id' => $user->public_id]).PHP_EOL;
} elseif ($mode === 'agency-prerequisites') {
    $business = $db->table('businesses')->where('public_id', $argv[2])->sole();
    $actor = $db->table('users')->where('public_id', $argv[3])->sole();
    $workspace = $db->table('workspaces')->where('slug', $argv[4])->sole();
    if ((int) $business->created_by === (int) $actor->id
        || ! $db->table('workspace_ownerships')->where('workspace_id', $workspace->id)->where('responsible_owner_user_id', $actor->id)->exists()) {
        throw new RuntimeException('Distinct synthetic client and agency owner required.');
    }
    // Only the pre-existing explicit qualifications and original report are fixtures.
    // The browser creates/ends the relationship, qualifies use, issues, binds and reads.
    $link = BusinessAuthorityFixture::root($business->id, $actor->id, 'business.link.manage');
    $source = (string) Str::uuid7();
    $resource = 'account-c03';
    $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace->id, 'family' => 'meta', 'namespace' => 'ad_account',
        'declared_external_id' => $resource, 'label' => 'حساب إعلاني اصطناعي للوكالة', 'version' => 1, 'status' => 'UNVERIFIED']);
    $db->table('source_capabilities')->insert(['workspace_id' => $workspace->id, 'source_id' => $source, 'resource' => $resource,
        'operation' => 'report.read', 'purpose' => 'capture']);
    $grants = [];
    foreach (['source.read', 'source.manage'] as $action) {
        $grants[$action] = $db->table('security_action_grants')->insertGetId([
            'subject_user_id' => $actor->id, 'granted_by' => $actor->id, 'source' => 'source_owner_request', 'scope_kind' => 'source',
            'workspace_id' => $workspace->id, 'source_target_id' => $source, 'source_resource' => $resource,
            'source_operation' => 'report.read', 'source_purpose' => 'capture', 'action' => $action,
            'subject_generation' => 1, 'status' => 'active', 'version' => 1, 'reason' => 'SYNTHETIC C03 INDEPENDENT SOURCE RIGHT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $namespace = hash('sha256', 'c03:agency-report:'.$source);
    $db->table('provider_observation_streams')->insert(['namespace' => $namespace, 'workspace_id' => $workspace->id, 'source_id' => $source,
        'provider' => 'meta', 'resource' => $resource, 'operation' => 'report.read', 'purpose' => 'capture', 'stream' => 'campaign',
        'generation' => 1, 'access_version' => 1]);
    $db->table('provider_observation_runs')->insert(['namespace' => $namespace, 'generation' => 1, 'command_id' => (string) Str::uuid(),
        'fingerprint' => hash('sha256', $namespace), 'coverage' => 'PARTIAL', 'provenance' => json_encode(['provider_reported' => true]),
        'progress_advanced' => false, 'actor_user_id' => $actor->id, 'actor_generation' => 1]);
    $db->table('provider_observation_entries')->insert(['namespace' => $namespace, 'generation' => 1,
        'identity' => 'bb2:'.hash('sha256', 'row:'.$namespace), 'content_hash' => hash('sha256', 'value:'.$namespace),
        'observation' => json_encode(['campaign_id' => 'agency-campaign-one', 'window' => ['start' => '2026-10-01', 'end' => '2026-10-31', 'boundary' => 'INCLUSIVE_DATES', 'grain' => 'TOTAL', 'timezone' => 'UTC'],
            'spend' => new NativeAmount('17', 'SAR', 'meta.account_currency_major', 0, 'documented-account-currency-scale'),
            'private_field' => 'C03_DO_NOT_DISCLOSE'], JSON_THROW_ON_ERROR), 'relation' => 'FIRST_OBSERVATION']);
    echo json_encode(['status' => 'completed', 'workspace' => $workspace->public_id, 'link_grant' => $link->id,
        'source' => $source, 'source_grants' => $grants]).PHP_EOL;
} elseif ($mode === 'agency-context') {
    $business = $db->table('businesses')->where('public_id', $argv[2])->sole();
    $actor = $db->table('users')->where('public_id', $argv[3])->sole();
    $workspace = $db->table('workspaces')->where('public_id', $argv[4])->sole();
    $db->table('business_workspace_links')->where('business_id', $business->id)->where('workspace_id', $workspace->id)
        ->where('kind', 'agency')->where('status', 'active')->sole();
    $read = BusinessAuthorityFixture::root($business->id, $actor->id, 'business.context.read', agencyWorkspace: $workspace->id);
    echo json_encode(['status' => 'completed', 'access' => $read->id]).PHP_EOL;
} elseif ($mode === 'admit') {
    $business = $db->table('businesses')->where('public_id', $argv[2])->sole();
    $count = 0;
    $connected = $db->table('provider_access')->where('local_state', 'CREDENTIAL_STORED')->select('source_id');
    foreach ($db->table('source_registry')->where('business_id', $business->id)->where('custody_kind', 'business_native')->where('family', 'meta')->where('namespace', 'ad_account')->whereIn('id', $connected)->get() as $source) {
        $scope = ProviderScope::forBusiness((int) $source->business_id, $source->id, Provider::Meta, $source->declared_external_id, 'report.read', 'advertising_analysis');
        $record = ['scope' => $scope->jsonSerialize(), 'configuration' => app(ProviderReportingEvidence::class)->configuration($scope)];
        $root = storage_path('framework/testing/c02-report-'.Str::uuid());
        mkdir($root, 0700, true);
        foreach (['provider_support', 'privacy_purpose'] as $axis) {
            $artifact = 'synthetic:c02:'.$axis;
            file_put_contents($root.'/'.$axis.'.txt', $artifact);
            $record[$axis] = ['provenance' => 'synthetic:c02:'.$axis, 'issuer' => 'synthetic-reviewer', 'authority_basis' => 'synthetic-test-only',
                'approval_reference' => 'synthetic:c02:'.$axis, 'artifact_sha256' => hash('sha256', $artifact), 'verified_at' => (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM), 'valid_until' => (new DateTimeImmutable('+30 minutes'))->format(DATE_ATOM)];
        }
        file_put_contents($root.'/manifest.json', json_encode($record, JSON_THROW_ON_ERROR));
        if (Artisan::call('bb2:admit-reporting-evidence', ['manifest' => $root.'/manifest.json', '--operator' => 'synthetic-operator',
            '--support-artifact' => $root.'/provider_support.txt', '--privacy-artifact' => $root.'/privacy_purpose.txt', '--attest-reviewed-approvals' => true]) !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        $count++;
    }
    echo json_encode(['status' => 'completed', 'admitted' => $count, 'scope' => 'SYNTHETIC EXPLICIT SUPPORT/PRIVACY APPROVAL; NOT REAL VENDOR APPROVAL']).PHP_EOL;
} elseif ($mode === 'jobs') {
    $count = 0;
    while ($job = Queue::connection('database')->pop('bb2.provider.meta')) {
        $job->fire();
        if (++$count > 8) {
            throw new RuntimeException('Bounded synthetic report queue exceeded.');
        }
    }
    echo json_encode(['status' => 'completed', 'handled' => $count]).PHP_EOL;
} elseif ($mode === 'inspect') {
    $business = $db->table('businesses')->where('public_id', $argv[2])->sole();
    echo json_encode(['status' => 'completed',
        'licenses' => $db->table('client_data_boundaries')->where('descriptor->business_public_id', $business->public_id)->count(),
        'synthetic_roots' => $db->table('authority_qualification_receipts')->where('target_ref', $business->public_id)->where('policy_id', 'like', 'test.synthetic%')->count()]).PHP_EOL;
} else {
    throw new RuntimeException('Unknown licence browser fixture operation.');
}
