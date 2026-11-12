<?php

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\LegacyCommercialUpgradeFixture;
use Tests\Support\ProofRedis;
use Tests\Support\PurposeDeclarationFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (config('session.driver') !== 'redis' || ! ProofRedis::matchesConfiguration()) {
    throw new RuntimeException('Dedicated proof Redis required');
}
$mode = $argv[1] ?? 'setup';
$db = DB::connection('pgsql_migrations');
$file = getenv('GOALS_SPA_RUN').'/fixture.json';
if ($mode === 'setup') {
    LegacyCommercialUpgradeFixture::install();
    $out = [];
    foreach (['a', 'b'] as $who) {
        $user = User::factory()->create(['name' => 'Goals proof '.$who, 'email' => 'goals-'.$who.'@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($user, 1);
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $session = app('session')->driver();
        $session->start();
        Auth::login($user);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(SessionAuthority::class)->issue($request, $user);
        $session->save();
        $workspaces = [];
        foreach ([1, 2] as $index) {
            $created = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, 'نشاط '.$who.' '.$index, 'spa-'.$who.'-'.$index));
            // Historical data is created only on the historical installation,
            // then upgraded before any browser operation on the target schema.
            if ($who === 'a' && $index === 1) {
                $nativeWorkspace = $db->table('workspaces')->where('id', $created['id'])->sole();
                LegacyCommercialUpgradeFixture::write($user, $request, $nativeWorkspace, 'context', [
                    $db->table('business_types')->where('active', true)->orderBy('id')->value('id'), 'نشاط a 1', 0,
                ]);
                $purposeCommand = PurposeDeclarationFixture::command();
                $purposeCommand['declaration']['meaning'] = 'غرض إعلاني أصلي قبل الإطلاق';
                $purposeCommand['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null]];
                $first = json_decode(LegacyCommercialUpgradeFixture::write($user, $request, $nativeWorkspace, 'purpose', [json_encode($purposeCommand)]), true);
                $purposeCommand = [...$purposeCommand, 'command_id' => (string) Str::uuid(), 'purpose_id' => $first['purpose_id'], 'expected_version' => 1,
                    'operation' => 'correct', 'supersedes_revision_id' => $first['revision_id'], 'correction_reason' => 'تصحيح أصلي موثق في الاختبار'];
                $purposeCommand['confirmations']['transition'] = true;
                $purposeCommand['declaration']['meaning'] = 'غرض إعلاني أصلي بعد التصحيح';
                $second = json_decode(LegacyCommercialUpgradeFixture::write($user, $request, $nativeWorkspace, 'purpose', [json_encode($purposeCommand)]), true);
                $out['legacy_purpose'] = ['workspace' => $created['public_id'], 'first' => $first, 'second' => $second];
            }
            $workspaces[] = $created;
        }
        $out[$who] = ['user' => $user->id, 'workspaces' => $workspaces];
    }
    try {
        if (getenv('GOALS_SPA_ONLY') === 'businesses') {
            // Populate the existing internal-team consumer on the actual former
            // installation. Isolated actors keep the standard a/b first-entry
            // counts unchanged. No browser ever receives a pre770 installation.
            $historicalPaths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), '0001_01_01_000770_') < 0));
            if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $historicalPaths, '--realpath' => true, '--force' => true]) !== 0) {
                throw new RuntimeException('Historical internal-team fixture installation failed.');
            }
            $historicalCreator = User::factory()->create(['name' => 'Historical browser creator', 'email' => 'historical-file-creator@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
            $historicalStaff = User::factory()->create(['name' => 'Historical browser employee', 'email' => 'historical-file-staff@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
            $historicalStore = new Store('historical-internal-browser', new ArraySessionHandler(120));
            $historicalStore->start();
            $historicalRequest = Request::create('/api/businesses', 'POST');
            $historicalRequest->setLaravelSession($historicalStore);
            $historicalRequest->setUserResolver(fn () => $historicalCreator);
            app(SessionAuthority::class)->issue($historicalRequest, $historicalCreator);
            $historicalStore->save();
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($historicalCreator, 1);
            $historicalTeam = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($historicalRequest, 'Historical internal file team', 'historical-internal-browser'));
            $db->table('workspace_memberships')->insert(['workspace_id' => $historicalTeam['id'], 'user_id' => $historicalStaff->id,
                'role' => 'member', 'status' => 'active', 'created_at' => now()]);
            $historicalScope = app(BusinessScope::class);
            $historicalBusiness = $historicalScope->create($historicalRequest, (string) Str::uuid(), 'Historical internal client file', null);
            $directSelection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $historicalBusiness['grant_refs']['business.context.read']]]];
            $historicalEnrollment = $historicalScope->internalTeam($historicalRequest, $historicalBusiness['business_public_id'], (string) Str::uuid(),
                $historicalTeam['public_id'], 'enroll', $historicalCreator->public_id, 0, $directSelection);
            $historicalRights = [];
            foreach (['business.context.read', 'business.context.manage'] as $action) {
                $parent = (int) $historicalEnrollment['grant_refs'][$action];
                $selection = ['realm_kind' => 'internal_team', 'realm_workspace_public_id' => $historicalTeam['public_id'], 'requirements' => [
                    ['requirement_kind' => 'business', 'leaf_grant_id' => $parent]]];
                $grant = $historicalScope->grant($historicalRequest, $historicalBusiness['business_public_id'], (string) Str::uuid(), 'issue',
                    $historicalStaff->public_id, $action, $parent, null, 0, null, false, $historicalTeam['public_id'], $selection);
                $historicalRights[$action] = $grant['grant_id'];
            }
            file_put_contents(getenv('GOALS_SPA_RUN').'/internal-team-browser-fixture.json', json_encode([
                'business' => $historicalBusiness, 'team' => $historicalTeam, 'staff' => $historicalStaff->public_id,
                'creator_email' => $historicalCreator->email, 'staff_email' => $historicalStaff->email,
                'team_read' => $historicalEnrollment['grant_refs']['business.context.read'], 'rights' => $historicalRights,
                'qualification' => 'ACTUAL PRE770 ENROLLMENT AND GRANTS; FORWARD UPGRADED BEFORE BROWSER; NO TARGET-SCHEMA LEGACY ISSUANCE',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    } finally {
        LegacyCommercialUpgradeFixture::upgrade();
    }
    if (getenv('GOALS_SPA_ONLY') !== 'businesses') {
        // Lifecycle browser proofs run against the active Business resource.
        // Historical Workspace fixtures above remain solely for qualified JSON reads.
        foreach (['a', 'b'] as $who) {
            $user = User::findOrFail($out[$who]['user']);
            $store = new Store('business-lifecycle-'.$who, new ArraySessionHandler(120));
            $store->start();
            $businessRequest = Request::create('/api/businesses', 'POST');
            $businessRequest->setLaravelSession($store);
            $businessRequest->setUserResolver(fn () => $user);
            app(SessionAuthority::class)->issue($businessRequest, $user);
            $store->save();
            $out[$who]['businesses'] = [];
            foreach ([1, 2] as $index) {
                $out[$who]['businesses'][] = app(BusinessScope::class)
                    ->create($businessRequest, (string) Str::uuid(), 'نشاط دورة المتصفح '.$who.' '.$index, null);
            }
        }
    }
    file_put_contents($file, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $fixture = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $who = $argv[2] ?? 'a';
    $user = $fixture[$who]['user'];
    if (str_starts_with($mode, 'operations-')) {
        require __DIR__.'/internal-operations-fixture.php';
    }
    if (str_starts_with($mode, 'internal-')) {
        require __DIR__.'/internal-identity-fixture.php';
    }
    if (str_starts_with($mode, 'auth-')) {
        require __DIR__.'/auth-entry-fixture.php';
    }
    if (str_starts_with($mode, 'account-')) {
        require __DIR__.'/account-spa-fixture.php';
    }
    $workspace = $fixture[$who]['workspaces'][0]['id'];
    if (str_starts_with($mode, 'source-')) {
        require __DIR__.'/sources-spa-fixture.php';
    }
    match ($mode) {
        'expire' => $db->table('security_sessions')->where('user_id', $user)->update(['idle_expires_at' => now()->subSecond()]),
        'revoke' => $db->table('security_sessions')->where('user_id', $user)->update(['revoked_at' => now()]),
        'deny-create' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore-create' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('action', 'workspace.create')->update(['status' => 'active', 'revoked_at' => null]),
        'deny-business-view' => $db->table('security_action_grants')->where('id', $fixture[$who]['businesses'][0]['grant_refs']['business.context.read'])->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore-business-view' => $db->table('security_action_grants')->where('id', $fixture[$who]['businesses'][0]['grant_refs']['business.context.read'])->update(['status' => 'active', 'revoked_at' => null]),
        'deny-view' => $db->table('workspace_memberships')->where('user_id', $user)->where('workspace_id', $workspace)->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore-view' => $db->table('workspace_memberships')->where('user_id', $user)->where('workspace_id', $workspace)->update(['status' => 'active', 'revoked_at' => null]),
        'inspect' => null,
        default => throw new RuntimeException('Unknown fixture action'),
    };
}
echo json_encode(['database' => $db->selectOne('select current_database() db, current_user role, inet_server_port() port'),
    'rls' => $db->select("select relname,relrowsecurity,relforcerowsecurity from pg_class where relname in ('workspaces','advertiser_goal_preferences')"),
    'goals' => $db->table('advertiser_goal_preferences')->get(),
    'runtimeUnscopedGoals' => DB::connection('pgsql')->table('advertiser_goal_preferences')->count(),
    'purposeCount' => $db->table('object_purposes')->count(),
    'purposeRevisionCount' => $db->table('object_purpose_revisions')->count(),
    'purposeReceiptCount' => $db->table('object_purpose_command_receipts')->count(),
    'transitions' => $db->table('browser_identity_transitions')->get(['lineage_id', 'epoch', 'principal_id', 'current_session_key_hash', 'status', 'kind']),
    'sessions' => $db->table('security_sessions')->get(['user_id', 'session_key_hash', 'idle_expires_at', 'absolute_expires_at', 'revoked_at']),
    'effects' => $db->table('advertiser_goal_preference_events')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
