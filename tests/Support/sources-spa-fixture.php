<?php

use App\Models\User;
use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReportingEvidence;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (getenv('GOALS_SPA_ONLY') !== 'sources') {
    throw new RuntimeException('Source proof only');
}
if ($mode === 'source-fresh') {
    $actor = User::findOrFail($user);
    $session = app('session')->driver();
    $session->start();
    Auth::login($actor);
    $request = Request::create('/', 'POST');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $actor);
    app(SessionAuthority::class)->issue($request, $actor);
    $session->save();
    $created = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, 'Synthetic Sources proof', 'sources-'.Str::uuid()));
    $fixture[$who]['workspaces'][0] = $created;
    file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo json_encode($created, JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
require __DIR__.'/sources-spa-runtime.php';
if ($mode === 'source-service') {
    $id = $db->table('service_identities')->insertGetId(['name' => 'Synthetic Source service', 'status' => 'enabled',
        'credential_hash' => hash('sha256', Str::random(64)), 'version' => 1, 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
    echo json_encode(['public_id' => $db->table('service_identities')->where('id', $id)->value('public_id')], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'source-admit') {
    $count = 0;
    foreach ($db->table('source_registry')->where('workspace_id', $workspace)->where('namespace', 'ad_account')->get() as $source) {
        $scope = new ProviderScope($workspace, $source->id, Provider::from($source->family), $source->declared_external_id, 'report.read', 'advertising_analysis');
        $record = ['scope' => $scope->jsonSerialize(), 'configuration' => app(ProviderReportingEvidence::class)->configuration($scope)];
        $root = storage_path('framework/testing/source-admission-'.Str::uuid());
        mkdir($root, 0700, true);
        foreach (['provider_support', 'privacy_purpose'] as $axis) {
            $artifact = 'synthetic:'.$axis;
            file_put_contents($root.'/'.$axis.'.txt', $artifact);
            $record[$axis] = ['provenance' => 'synthetic:reviewed:'.$axis, 'issuer' => 'synthetic-reviewer', 'authority_basis' => 'synthetic-test-only', 'approval_reference' => 'synthetic:'.$axis, 'artifact_sha256' => hash('sha256', $artifact), 'verified_at' => (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM), 'valid_until' => (new DateTimeImmutable('+30 minutes'))->format(DATE_ATOM)];
        }
        file_put_contents($root.'/manifest.json', json_encode($record, JSON_THROW_ON_ERROR));
        $exit = Artisan::call('bb2:admit-reporting-evidence', ['manifest' => $root.'/manifest.json', '--operator' => 'synthetic-operator', '--support-artifact' => $root.'/provider_support.txt', '--privacy-artifact' => $root.'/privacy_purpose.txt', '--attest-reviewed-approvals' => true]);
        if ($exit !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        $count++;
    }
    echo json_encode(['admitted' => $count, 'storage' => storage_path(), 'key_hash' => hash('sha256', (string) config('app.key')),
        'meta_config_hash' => hash('sha256', json_encode(config('services.meta_ads')))], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'source-google-offline-missing' || $mode === 'source-google-offline-restore') {
    Cache::put('source-proof:google-offline-missing', $mode === 'source-google-offline-missing', 600);
    echo json_encode(['missing' => $mode === 'source-google-offline-missing']).PHP_EOL;
    exit;
}
if ($mode === 'source-snap-deny' || $mode === 'source-snap-restore') {
    Cache::put('source-proof:snapchat-deny', $mode === 'source-snap-deny', 600);
    echo json_encode(['denial' => $mode === 'source-snap-deny']).PHP_EOL;
    exit;
}
if ($mode === 'source-run-reports') {
    $count = 0;
    foreach (['meta', 'google', 'tiktok', 'snapchat', 'x'] as $provider) {
        while ($job = Queue::connection('database')->pop('bb2.provider.'.$provider)) {
            $job->fire();
            if (++$count > 20) {
                throw new RuntimeException('Bounded synthetic queue exceeded');
            }
        }
    }
    echo json_encode(['handled' => $count]).PHP_EOL;
    exit;
}
if ($mode !== 'source-inspect') {
    throw new RuntimeException('Unknown Source proof action');
}
echo json_encode(['sources' => $db->table('source_registry')->where('workspace_id', $workspace)->get(['id', 'family', 'namespace', 'declared_external_id', 'version', 'status']), 'receipts' => $db->table('source_command_receipts')->count(), 'accessEvents' => $db->table('provider_access_events')->count(), 'access' => $db->table('provider_access')->where('workspace_id', $workspace)->get(['source_id', 'provider', 'version', 'local_state']), 'jobs' => $db->table('jobs')->count(), 'runs' => $db->table('provider_observation_runs')->get(['generation', 'coverage', 'failure'])], JSON_THROW_ON_ERROR).PHP_EOL;
exit;
