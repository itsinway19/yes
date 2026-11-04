<?php

use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\LegacyCommercialUpgradeFixture;
use Tests\Support\PurposeDeclarationFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
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
    LegacyCommercialUpgradeFixture::upgrade();
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
