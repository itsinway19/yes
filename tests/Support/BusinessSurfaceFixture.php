<?php

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DataUseSurfaceFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (getenv('GOALS_SPA_ONLY') !== 'businesses' || config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
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
$select = fn (int $grant) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
if ($mode === 'new-advertiser-identity') {
    // Verified identity fixture only. No Workspace, Business or resource grant
    // is inserted; the browser exercises first entry and the operating writers.
    $newAdvertiser = User::factory()->create(['name' => 'New advertiser browser', 'email' => 'new-advertiser-browser@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
    file_put_contents($run.'/new-advertiser-identity.json', json_encode(['email' => $newAdvertiser->email,
        'qualification' => 'VERIFIED IDENTITY FIXTURE ONLY; NO BUSINESS OR WORKSPACE CREATED'], JSON_THROW_ON_ERROR));
} elseif ($mode === 'team-identities') {
    file_put_contents($run.'/team-identities.json', json_encode(['manager' => $recipient->public_id, 'staff' => $owner->public_id,
        'qualification' => 'IDENTITY FIXTURES ONLY; NO MEMBERSHIP OR BUSINESS GRANT INSERTED'], JSON_THROW_ON_ERROR));
} elseif ($mode === 'internal-team-setup') {
    $team = $base['b']['workspaces'][0];
    DB::connection('pgsql_migrations')->table('workspace_memberships')->updateOrInsert(
        ['workspace_id' => $team['id'], 'user_id' => $owner->id],
        ['role' => 'member', 'status' => 'active', 'created_at' => now()],
    );
    $store = new Store('internal-file-browser', new ArraySessionHandler(120));
    $store->start();
    $creatorRequest = Request::create('/api/businesses');
    $creatorRequest->setLaravelSession($store);
    $creatorRequest->setUserResolver(fn () => $recipient);
    app(SessionAuthority::class)->issue($creatorRequest, $recipient);
    $store->save();
    $draft = $scope->create($creatorRequest, (string) Str::uuid(), 'ملف عميل لإدارة الفريق', null);
    file_put_contents($run.'/internal-team-browser-fixture.json', json_encode([
        'business' => $draft, 'team' => $team, 'staff' => $owner->public_id,
        'qualification' => 'REAL CREATOR PATH; NO SYNTHETIC INTERNAL TEAM ROOT',
    ], JSON_THROW_ON_ERROR));
} elseif (in_array($mode, ['purpose-setup', 'purpose-revoke-read', 'purpose-revoke-write'], true)) {
    require __DIR__.'/BusinessPurposeBrowserFixture.php';
} elseif (in_array($mode, ['legacy-history-setup', 'legacy-history-withdraw'], true)) {
    require __DIR__.'/LegacyHistoryBrowserFixture.php';
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
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
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
        $workspace = $db->table('workspaces')->insertGetId(['name' => 'Synthetic source custody', 'slug' => 'source-custody-'.Str::uuid(),
            'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $actor->id, 'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
        $db->table('workspace_ownerships')->insert(['workspace_id' => $workspace, 'responsible_owner_user_id' => $actor->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('security_action_grants')->insert(['subject_user_id' => $actor->id, 'subject_generation' => 1, 'source' => 'system_workspace_creation',
            'scope_kind' => 'workspace', 'workspace_id' => $workspace, 'action' => 'workspace.view', 'status' => 'active', 'version' => 1,
            'reason' => 'synthetic_source_fixture', 'created_at' => now(), 'updated_at' => now()]);
        $source = (string) Str::uuid7();
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace, 'family' => 'business', 'namespace' => 'synthetic',
            'declared_external_id' => 'private-'.$key, 'label' => $name, 'version' => 1, 'status' => 'UNVERIFIED']);
        $db->table('source_capabilities')->insert(['source_id' => $source, 'workspace_id' => $workspace, 'resource' => 'synthetic-records', 'operation' => 'report.read', 'purpose' => 'capture']);
        $out[$key] = ['source' => $source, 'workspace' => $workspace, 'name' => $name, 'grants' => []];
        foreach (['source.manage', 'source.read', 'source.audit.recover'] as $action) {
            $out[$key]['grants'][$action] = $db->table('security_action_grants')->insertGetId(['subject_user_id' => $actor->id, 'subject_generation' => 1, 'granted_by' => $actor->id,
                'source' => 'source_owner_request', 'scope_kind' => 'source', 'workspace_id' => $workspace, 'source_target_id' => $source,
                'source_resource' => 'synthetic-records', 'source_operation' => 'report.read', 'source_purpose' => 'capture', 'action' => $action,
                'status' => 'active', 'version' => 1, 'reason' => 'synthetic_source_fixture', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    file_put_contents($run.'/business-source-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'shared-return-setup') {
    $sources = json_decode(file_get_contents($run.'/business-source-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $db = DB::connection('pgsql_migrations');
    $draft = $scope->create($request, (string) Str::uuid(), 'نشاط العودة المستقلة', null);
    $out = ['business' => $draft['business_public_id']];
    $id = $db->table('businesses')->where('public_id', $out['business'])->value('id');
    $readRoot = BusinessAuthorityFixture::root($id, $owner->id, 'business.context.read');
    $read = $scope->grant($request, $out['business'], (string) Str::uuid(), 'issue', $recipient->public_id,
        'business.context.read', $readRoot->id, null, 0, null, false, null, $select($readRoot->id));
    $root = BusinessAuthorityFixture::root($id, $owner->id, 'business.link.manage');
    $issued = $scope->grant($request, $out['business'], (string) Str::uuid(), 'issue', $recipient->public_id,
        'business.link.manage', $root->id, null, 0, null, false, null, $select($root->id));
    $request->setUserResolver(fn () => $recipient);
    $session->regenerate();
    app(SessionAuthority::class)->issue($request, $recipient);
    $session->save();
    $workspace = $db->table('workspaces')->where('id', $sources['a']['workspace'])->value('public_id');
    $scope->link($request, $out['business'], $workspace, (string) Str::uuid(), 'add', 0, $select($issued['grant_id']));
    file_put_contents($run.'/shared-return-fixture.json', json_encode(['business' => $out['business'],
        'access' => $read['grant_id'], 'read_issuer' => $readRoot->id, 'workspace' => $workspace, 'other_workspace' => $base['b']['workspaces'][0]['public_id'],
        'other_identity_workspace' => $base['a']['workspaces'][0]['public_id'],
        'qualification' => 'ISOLATED SYNTHETIC RIGHTS; OPERATING LINK WRITER; NO LIVE PROVIDER'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    Cache::store('redis')->put('shared-source-return-runtime', true, 600);
} elseif ($mode === 'shared-return-revoke') {
    $out = json_decode(file_get_contents($run.'/shared-return-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $scope->grant($request, $out['business'], (string) Str::uuid(), 'revoke', $recipient->public_id,
        'business.context.read', null, $out['access'], 1, null, false, null, $select($out['read_issuer']));
} elseif ($mode === 'shared-return-finish') {
    Cache::store('redis')->forget('shared-source-return-runtime');
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
    DB::connection('pgsql_migrations')->table('workspace_ownerships')->where('workspace_id', $out['a']['workspace'])->update(['responsible_owner_user_id' => $owner->id, 'version' => 2]);
} elseif ($mode === 'boundary-setup') {
    $out = ['qualification' => 'SYNTHETIC EXISTING BOUNDARIES; NO OPERATING ROOT ISSUER'];
    foreach (['a' => 'عميل نطاق البيانات الأول', 'b' => 'عميل نطاق البيانات الثاني'] as $key => $name) {
        $draft = $scope->create($request, (string) Str::uuid(), $name, null);
        $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
        $out[$key] = ['business' => $draft['business_public_id'], 'name' => $name, 'boundary' => BusinessAuthorityFixture::boundary($recipient->id)];
        foreach (['read' => 'business.context.read', 'link' => 'business.link.manage'] as $label => $action) {
            $root = BusinessAuthorityFixture::root($id, $owner->id, $action);
            $issued = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id, $action, $root->id, null, 0, null, false, null, $select($root->id));
            $out[$key][$label] = ['issuer' => $root->id, 'leaf' => $issued['grant_id'], 'action' => $action];
        }
    }
    $out['foreign'] = BusinessAuthorityFixture::boundary($owner->id);
    file_put_contents($run.'/business-boundary-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'boundary-drop-business-authority') {
    $out = json_decode(file_get_contents($run.'/business-boundary-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['read', 'link'] as $label) {
        $grant = $out['a'][$label];
        $scope->grant($request, $out['a']['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $grant['action'], null, $grant['leaf'], 1, null, false, null, $select($grant['issuer']));
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
            'business.context.read', $root->id, null, 0, null, true, null, $select($root->id));
        $independent = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id,
            'business.context.read', $root->id, null, 0, null, false, null, $select($root->id));
        $out[$key] = ['business' => $draft['business_public_id'], 'issuer' => $root->id, 'parent' => $parent['grant_id'], 'read' => $independent['grant_id']];
    }
    file_put_contents($run.'/business-delegation-fixture.json', json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'delegation-revoke-parent') {
    $out = json_decode(file_get_contents($run.'/business-delegation-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $scope->grant($request, $out['a']['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, 'business.context.read',
        null, $out['a']['parent'], 1, null, false, null, $select($out['a']['issuer']));
} elseif (in_array($mode, ['setup', 'agency-setup'], true)) {
    if ($mode === 'agency-setup') {
        $path = $run.'/business-agency-fixture.json';
    }
    $draft = $scope->create($request, (string) Str::uuid(), $mode === 'agency-setup' ? 'نشاط إدارة الوكالة' : 'نشاط العميل المفوض', null);
    $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $draft['business_public_id'])->value('id');
    $out = ['business' => $draft['business_public_id'], 'actor_public_id' => $recipient->public_id,
        'qualification' => 'SYNTHETIC TEST ROOT; NOT AN OPERATING ISSUER POLICY'];
    $actions = $mode === 'agency-setup' ? ['read' => 'business.context.read', 'link' => 'business.link.manage']
        : ['read' => 'business.context.read', 'edit' => 'business.context.manage'];
    foreach ($actions as $label => $action) {
        $root = BusinessAuthorityFixture::root($id, $owner->id, $action);
        $issued = $scope->grant($request, $draft['business_public_id'], (string) Str::uuid(), 'issue', $recipient->public_id, $action, $root->id, null, 0, null, false, null, $select($root->id));
        $out[$label] = ['issuer' => $root->id, 'leaf' => $issued['grant_id'], 'action' => $action];
    }
    $out['grant_count'] = DB::connection('pgsql_migrations')->table('security_action_grants')->where('business_id', $id)->count();
    file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} elseif ($mode === 'agency-audit') {
    $out = json_decode(file_get_contents($run.'/business-agency-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $out['business'])->value('id');
    if (DB::connection('pgsql_migrations')->table('security_action_grants')->where('business_id', $id)->count() !== $out['grant_count']) {
        throw new RuntimeException('A collaboration relationship must not issue Business grants.');
    }
} elseif ($mode === 'withdraw-workspace') {
    // Synthetic independent withdrawal only; no operating issuer or new authority path.
    $workspace = $base['b']['workspaces'][0]['id'];
    DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $recipient->id)
        ->where('scope_kind', 'workspace')->where('workspace_id', $workspace)->where('action', 'workspace.view')
        ->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2]);
} else {
    $out = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $label = match ($mode) {
        'revoke-edit' => 'edit', 'revoke-read' => 'read', default => throw new RuntimeException('Unknown Business fixture command')
    };
    $scope->grant($request, $out['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $out[$label]['action'], null, $out[$label]['leaf'], 1, null, false, null, $select($out[$label]['issuer']));
}
echo json_encode(['mode' => $mode, 'status' => 'completed', 'qualification' => 'synthetic-test-only'], JSON_THROW_ON_ERROR).PHP_EOL;
