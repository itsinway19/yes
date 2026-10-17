<?php

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            $type = $db->table('business_types')->where('key', 'real_estate')->value('id');
            app(WorkspaceContext::class)->runBusinessMutation($user, $created['public_id'],
                fn (Workspace $active) => app(BusinessContext::class)->write($request, $active, ['business_type_id' => $type, 'version' => 0]), $request);
            $workspaces[] = $created;
        }
        $out[$who] = ['user' => $user->id, 'workspaces' => $workspaces];
    }
    file_put_contents($file, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $fixture = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $who = $argv[2] ?? 'a';
    $user = $fixture[$who]['user'];
    $workspace = $fixture[$who]['workspaces'][0]['id'];
    match ($mode) {
        'expire' => $db->table('security_sessions')->where('user_id', $user)->update(['idle_expires_at' => now()->subSecond()]),
        'revoke' => $db->table('security_sessions')->where('user_id', $user)->update(['revoked_at' => now()]),
        'deny-create' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('action', 'workspace.create')->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore-create' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('action', 'workspace.create')->update(['status' => 'active', 'revoked_at' => null]),
        'deny' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('workspace_id', $workspace)->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore' => $db->table('security_action_grants')->where('subject_user_id', $user)->where('workspace_id', $workspace)->where('action', 'business.context.manage')->update(['status' => 'active', 'revoked_at' => null]),
        'deny-view' => $db->table('workspace_memberships')->where('user_id', $user)->where('workspace_id', $workspace)->update(['status' => 'revoked', 'revoked_at' => now()]),
        'restore-view' => $db->table('workspace_memberships')->where('user_id', $user)->where('workspace_id', $workspace)->update(['status' => 'active', 'revoked_at' => null]),
        'inspect' => null,
        'locks' => null,
        default => throw new RuntimeException('Unknown fixture action'),
    };
}
if ($mode === 'locks') {
    // Read-only observer of this guarded disposable server. Application roles
    // retain their actual privileges; no monitoring privilege is granted to them.
    $observer = config('database.connections.pgsql_migrations');
    $observer['username'] = 'postgres';
    $observer['password'] = '';
    config(['database.connections.goals_proof_observer' => $observer]);
    echo json_encode(DB::connection('goals_proof_observer')->select(<<<'SQL'
        SELECT pid, usename, application_name, state, wait_event_type, wait_event,
               backend_xid, query, to_json(pg_blocking_pids(pid))::text blockers
        FROM pg_stat_activity
        WHERE datname = current_database() AND pid <> pg_backend_pid()
          AND wait_event_type = 'Lock' AND query LIKE '%builderad_advertiser_goals_write%'
        SQL), JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
echo json_encode(['database' => $db->selectOne('select current_database() db, current_user role, inet_server_port() port'),
    'rls' => $db->select("select relname,relrowsecurity,relforcerowsecurity from pg_class where relname in ('workspaces','advertiser_goal_preferences')"),
    'goals' => $db->table('advertiser_goal_preferences')->get(),
    'runtimeUnscopedGoals' => DB::connection('pgsql')->table('advertiser_goal_preferences')->count(),
    'transitions' => $db->table('browser_identity_transitions')->get(['lineage_id', 'epoch', 'principal_id', 'current_session_key_hash', 'status', 'kind']),
    'sessions' => $db->table('security_sessions')->get(['session_key_hash', 'idle_expires_at', 'absolute_expires_at', 'revoked_at']),
    'effects' => $db->table('advertiser_goal_preference_events')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
