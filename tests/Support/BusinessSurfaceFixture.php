<?php

use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DataUseSurfaceFixture;
use Tests\Support\ProofRedis;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (getenv('GOALS_SPA_ONLY') !== 'businesses' || config('session.driver') !== 'redis' || ! ProofRedis::matchesConfiguration()) {
    throw new RuntimeException('Dedicated Business browser fixture required.');
}
$run = getenv('GOALS_SPA_RUN');
$base = json_decode(file_get_contents($run.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::findOrFail($base['a']['user']);
$recipient = User::findOrFail($base['b']['user']);
$session = app('session')->driver();
$session->start();
$request = Request::create('/api/businesses');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $owner);
app(SessionAuthority::class)->issue($request, $owner);
$session->save();
$scope = app(BusinessScope::class);
$path = $run.'/business-surface-fixture.json';
$mode = $argv[1] ?? 'setup';
$select = fn (int $grant) => ['realm_kind' => 'direct', 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
if (in_array($mode, ['business-agency-setup', 'business-agency-audit'], true)) {
    require __DIR__.'/BusinessAgencyBrowserFixture.php';
} elseif (in_array($mode, ['activity-knowledge-setup', 'activity-knowledge-withdraw'], true)) {
    require __DIR__.'/BusinessActivityBrowserFixture.php';
} elseif ($mode === 'new-advertiser-identity') {
    // Verified identity fixture only. No Workspace, Business or resource grant
    // is inserted; the browser exercises first entry and the operating writers.
    $newAdvertiser = User::factory()->create(['name' => 'New advertiser browser', 'email' => 'new-advertiser-browser@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
    file_put_contents($run.'/new-advertiser-identity.json', json_encode(['email' => $newAdvertiser->email,
        'qualification' => 'VERIFIED IDENTITY FIXTURE ONLY; NO BUSINESS OR WORKSPACE CREATED'], JSON_THROW_ON_ERROR));
} elseif ($mode === 'team-identities') {
    file_put_contents($run.'/team-identities.json', json_encode(['manager' => $recipient->public_id, 'staff' => $owner->public_id,
        'qualification' => 'IDENTITY FIXTURES ONLY; NO MEMBERSHIP OR BUSINESS GRANT INSERTED'], JSON_THROW_ON_ERROR));
} elseif (in_array($mode, ['purpose-setup', 'purpose-revoke-read', 'purpose-revoke-write'], true)) {
    require __DIR__.'/BusinessPurposeBrowserFixture.php';
} elseif ($mode === 'history-withdraw') {
    $out = json_decode(file_get_contents($run.'/business-history-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $q = DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $out['a']['grant_refs']['business.context.read'])->value('qualification_id');
    BusinessAuthorityFixture::revokeQualification($q, $recipient->id);
} elseif ($mode === 'data-use-setup') {
    // The owner fixture already issued the application's default Store. A second
    // synthetic principal needs its own identity, never that same session hash.
    $sessionB = new Store('data-use-fixture', new ArraySessionHandler(120));
    $sessionB->start();
    $requestB = Request::create('/api/businesses');
    $requestB->setLaravelSession($sessionB);
    $requestB->setUserResolver(fn () => $recipient);
    app(SessionAuthority::class)->issue($requestB, $recipient);
    $sessionB->save();
    $out = ['qualification' => 'SYNTHETIC PREQUALIFIED USE SCOPES; NO OPERATING ISSUER OR LIVE DATA'];
    $shared = null;
    foreach (['a' => 'نشاط استعمال البيانات الأول', 'b' => 'نشاط استعمال البيانات الثاني'] as $label => $name) {
        $business = $scope->create($requestB, (string) Str::uuid(), $name, null);
        $data = DataUseSurfaceFixture::scope($recipient, $shared);
        $shared = $data['source'];
        $selection = ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'client_data', 'leaf_grant_id' => $data['boundary']['grant_id']],
            ['requirement_kind' => 'business', 'leaf_grant_id' => $business['grant_refs']['business.link.manage']]]];
        $scope->resourceRight($requestB, (string) Str::uuid(), 'boundary_subject', $data['boundary']['boundary_id'], 'attach_subject',
            (object) ['business_public_id' => $business['business_public_id'], 'branch_id' => null], 0, $selection);
        $out[$label] = ['business' => $business, 'data' => $data, 'name' => $name];
    }
    $out['foreign'] = DataUseSurfaceFixture::scope($owner, $shared);
    file_put_contents($run.'/business-data-use-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'data-use-withdraw-a') {
    $out = json_decode(file_get_contents($run.'/business-data-use-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    BusinessAuthorityFixture::revokeQualification($out['a']['data']['qualification'], $recipient->id);
} elseif ($mode === 'data-use-requalify-a') {
    $out = json_decode(file_get_contents($run.'/business-data-use-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $out['a']['data']['grant_id'] = DataUseSurfaceFixture::requalify($out['a']['data'], $recipient);
    file_put_contents($run.'/business-data-use-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'source-setup') {
    $db = DB::connection('pgsql_migrations');
    $out = ['qualification' => 'SYNTHETIC EXISTING NATIVE OWNER RIGHTS; NOT A LIVE PROVIDER'];
    foreach (['a' => 'مصدر العميل الأول', 'b' => 'مصدر العميل الثاني', 'foreign' => 'مصدر غير مؤهل'] as $key => $name) {
        $actor = $key === 'foreign' ? $owner : $recipient;
        $actorStore = new Store('source-fixture-'.$key, new ArraySessionHandler(120));
        $actorStore->start();
        $actorRequest = Request::create('/api/businesses');
        $actorRequest->setLaravelSession($actorStore);
        $actorRequest->setUserResolver(fn () => $actor);
        app(SessionAuthority::class)->issue($actorRequest, $actor);
        $actorStore->save();
        $business = $scope->create($actorRequest, (string) Str::uuid7(), $name, null);
        $source = (string) Str::uuid7();
        $custody = DB::transaction(function () use ($actor, $actorRequest, $business, $source, $key, $name, $select) {
            app(PrincipalContext::class)->activate($actor);

            return app(GrantAuthority::class)->bootstrapBusinessSource($actorRequest, $business['business_public_id'], $source,
                (string) Str::uuid7(), ['family' => 'business', 'namespace' => 'synthetic', 'external_id' => 'private-'.$key,
                    'label' => $name, 'resource' => 'synthetic-records', 'operation' => 'report.read', 'purpose' => 'capture',
                    'expected_version' => 0, 'reason' => 'synthetic_source_fixture'], $select((int) $business['grant_refs']['business.context.read']));
        });
        $out[$key] = ['source' => $source, 'business' => $business, 'name' => $name, 'grants' => $custody['grant_refs']];
    }
    file_put_contents($run.'/business-source-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'source-add-scope') {
    $out = json_decode(file_get_contents($run.'/business-source-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $db = DB::connection('pgsql_migrations');
    $values = (array) $db->table('security_action_grants')->where('id', $out['a']['grants']['source.manage'])->sole();
    $values['id'] = (int) $db->scalar("SELECT nextval('security_action_grants_id_seq')");
    $values['source_operation'] = 'discovery.read';
    $values['created_at'] = $values['updated_at'] = now()->toIso8601String();
    $row = BusinessAuthorityFixture::insert('security_action_grants', $values, $recipient->id);
    $out['a']['scope_manager'] = $row->id;
    file_put_contents($run.'/business-source-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'source-withdraw-owner') {
    $out = json_decode(file_get_contents($run.'/business-source-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    BusinessAuthorityFixture::revokeQualification(DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $out['a']['business']['grant_refs']['business.context.read'])->value('qualification_id'), $recipient->id);
} elseif ($mode === 'boundary-setup') {
    $out = ['qualification' => 'SYNTHETIC EXISTING BOUNDARIES; NO OPERATING ROOT ISSUER'];
    foreach (['a' => 'عميل نطاق البيانات الأول', 'b' => 'عميل نطاق البيانات الثاني'] as $key => $name) {
        $draft = $scope->create($request, (string) Str::uuid(), $name, null);
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
        $out[$key] = ['business' => $draft['business_public_id'], 'name' => $name, 'boundary' => BusinessAuthorityFixture::boundary($recipient->id)];
        foreach (['read' => 'business.context.read', 'link' => 'business.link.manage'] as $label => $action) {
            $root = BusinessAuthorityFixture::root($id, $owner->id, $action);
            $issued = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id, $action, $root->id, null, 0, null, false, $select($root->id));
            $out[$key][$label] = ['issuer' => $root->id, 'leaf' => $issued['grant_id'], 'action' => $action];
        }
    }
    $out['foreign'] = BusinessAuthorityFixture::boundary($owner->id);
    file_put_contents($run.'/business-boundary-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'boundary-drop-business-authority') {
    $out = json_decode(file_get_contents($run.'/business-boundary-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['read', 'link'] as $label) {
        $grant = $out['a'][$label];
        $scope->grant($request, $out['a']['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $grant['action'], null, $grant['leaf'], 1, null, false, $select($grant['issuer']));
    }
} elseif ($mode === 'boundary-withdraw-manager') {
    $out = json_decode(file_get_contents($run.'/business-boundary-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $qualification = DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $out['a']['boundary']['grant_id'])->value('qualification_id');
    BusinessAuthorityFixture::revokeQualification($qualification, $recipient->id);
} elseif ($mode === 'delegation-setup') {
    $target = User::factory()->create(['name' => 'Synthetic delegated colleague', 'email' => 'delegation-c@example.test',
        'password' => Hash::make('DisposableGoalsProof42!')]);
    $out = ['recipient_public_id' => $target->public_id, 'recipient_email' => $target->email,
        'qualification' => 'SYNTHETIC TEST ROOTS; NOT AN OPERATING ISSUER POLICY'];
    foreach (['a' => 'عميل التفويض الأول', 'b' => 'عميل التفويض الثاني'] as $key => $name) {
        $draft = $scope->create($request, (string) Str::uuid(), $name, null);
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
        $root = BusinessAuthorityFixture::root($id, $owner->id, 'business.context.read');
        $parent = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id,
            'business.context.read', $root->id, null, 0, null, true, $select($root->id));
        $independent = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id,
            'business.context.read', $root->id, null, 0, null, false, $select($root->id));
        $out[$key] = ['business' => $draft['business_public_id'], 'issuer' => $root->id, 'parent' => $parent['grant_id'], 'read' => $independent['grant_id']];
    }
    file_put_contents($run.'/business-delegation-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'delegation-revoke-parent') {
    $out = json_decode(file_get_contents($run.'/business-delegation-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $scope->grant($request, $out['a']['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, 'business.context.read',
        null, $out['a']['parent'], 1, null, false, $select($out['a']['issuer']));
} elseif (in_array($mode, ['setup', 'agency-setup'], true)) {
    if ($mode === 'agency-setup') {
        $path = $run.'/business-agency-fixture.json';
    }
    $draft = $scope->create($request, (string) Str::uuid(), $mode === 'agency-setup' ? 'نشاط إدارة الوكالة' : 'نشاط العميل المفوض', null);
    $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
    $out = ['business' => $draft['business_public_id'], 'actor_public_id' => $recipient->public_id,
        'qualification' => 'SYNTHETIC TEST ROOT; NOT AN OPERATING ISSUER POLICY'];
    $team = $mode === 'setup' ? $scope->enrollDirectTeam($request, $draft['business_public_id'], (string) Str::uuid(),
        $select($draft['grant_refs']['business.context.read']))['grant_refs'] : null;
    $actions = $mode === 'agency-setup' ? ['read' => 'business.context.read', 'link' => 'business.link.manage']
        : ['read' => 'business.context.read', 'edit' => 'business.context.manage'];
    foreach ($actions as $label => $action) {
        $root = (int) ($team[$action] ?? BusinessAuthorityFixture::root($id, $owner->id, $action)->id);
        $issued = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id, $action, $root, null, 0, null, false, $select($root));
        $out[$label] = ['issuer' => $root, 'leaf' => $issued['grant_id'], 'action' => $action];
    }
    $out['grant_count'] = DB::connection('pgsql_migrations')->table('security_action_grants')->where('business_id', $id)->count();
    file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'agency-audit') {
    $out = json_decode(file_get_contents($run.'/business-agency-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $out['business'])->value('id');
    if (DB::connection('pgsql_migrations')->table('security_action_grants')->where('business_id', $id)->count() !== $out['grant_count']) {
        throw new RuntimeException('A collaboration relationship must not issue Business grants.');
    }
} else {
    $out = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $label = match ($mode) {
        'revoke-edit' => 'edit', 'revoke-read' => 'read', default => throw new RuntimeException('Unknown Business fixture command')
    };
    $scope->grant($request, $out['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $out[$label]['action'], null, $out[$label]['leaf'], 1, null, false, $select($out[$label]['issuer']));
}
echo json_encode(['mode' => $mode, 'status' => 'completed', 'qualification' => 'synthetic-test-only'], JSON_THROW_ON_ERROR).PHP_EOL;
