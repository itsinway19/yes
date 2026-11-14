<?php

use App\Models\User;
use App\Providers\Ads\NativeAmount;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\ProofRedis;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (getenv('GOALS_SPA_PROOF') !== 'enabled' || getenv('GOALS_SPA_ONLY') !== 'data-licenses' || ! ProofRedis::matchesConfiguration()) {
    throw new RuntimeException('Guarded data licence browser fixture required.');
}
$mode = $argv[1] ?? 'setup';
$db = DB::connection('pgsql_migrations');
require __DIR__.'/sources-spa-runtime.php';
if ($mode === 'setup') {
    $email = 'g01-c02-'.Str::uuid().'@example.test';
    $user = User::factory()->create(['name' => 'Synthetic advertiser', 'email' => $email, 'password' => Hash::make('DisposableGoalsProof42!')]);
    echo json_encode(['status' => 'completed', 'email' => $email, 'public_id' => $user->public_id]).PHP_EOL;
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
