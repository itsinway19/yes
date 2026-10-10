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

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(DisposableTestDatabaseGuard::class)->assertSafe();
    $run = getenv('CURRENT_INTENT_PROOF_RUN');
    if (! $run || realpath(config('session.files')) !== realpath($run.'/ui-storage/framework/sessions')) {
        throw new RuntimeException('Owned disposable session storage required.');
    }
    $mode = $argv[1] ?? 'setup';
    $db = DB::connection('pgsql_migrations');
    $path = $run.'/client-state-fixture.json';
    if ($mode === 'setup') {
        $base = json_decode(file_get_contents($run.'/ui-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        $a = User::where('email', 'goals-proof@example.test')->firstOrFail();
        $b = User::factory()->create(['name' => 'Synthetic boundary B', 'email' => 'boundary-b@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($b, 1);
        $create = function (User $user, string $name, string $slug) use (&$base): array {
            Auth::forgetGuards();
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            $session = app('session')->driver();
            $session->start();
            Auth::login($user);
            $request = Request::create('/', 'GET');
            $request->setLaravelSession($session);
            $request->setUserResolver(fn () => $user);
            app(SessionAuthority::class)->issue($request, $user);
            $workspace = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, $name, $slug));
            $type = DB::connection('pgsql_migrations')->table('business_types')->where('key', 'real_estate')->value('id');
            app(WorkspaceContext::class)->runBusinessMutation($user, $workspace['public_id'],
                fn (Workspace $active) => app(BusinessContext::class)->write($request, $active, ['business_type_id' => $type, 'version' => 0]), $request);
            $session->save();
            $base['sessionFiles'][] = config('session.files').'/'.$session->getId();

            return $workspace;
        };
        $second = $create($a, 'Synthetic boundary A second workspace', 'boundary-a-second');
        $other = $create($b, 'Synthetic boundary B workspace', 'boundary-b');
        $first = $db->table('workspaces')->where('public_id', $base['workspace'])->first();
        $fixture = ['a' => $a->id, 'b' => $b->id, 'first' => $first->public_id, 'firstId' => $first->id,
            'second' => $second['public_id'], 'other' => $other['public_id']];
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($run.'/ui-fixture.json', json_encode($base, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        echo "CLIENT_STATE_FIXTURE=READY\n";
    } else {
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $sessions = $db->table('security_sessions')->where('user_id', $fixture['a'])->whereNull('revoked_at');
        if ($mode === 'expire') {
            $sessions->update(['last_seen_at' => now()->subMinutes(61), 'idle_expires_at' => now()->subMinute()]);
        } elseif ($mode === 'revoke') {
            $db->table('security_action_grants')->where('subject_user_id', $fixture['a'])->where('workspace_id', $fixture['firstId'])
                ->where('action', 'workspace.view')->update(['status' => 'revoked', 'revoked_at' => now()]);
        } elseif ($mode === 'restore') {
            $db->table('security_action_grants')->where('subject_user_id', $fixture['a'])->where('workspace_id', $fixture['firstId'])
                ->where('action', 'workspace.view')->update(['status' => 'active', 'revoked_at' => null]);
        } elseif ($mode !== 'inspect') {
            throw new RuntimeException('Unknown bounded fixture operation.');
        }
        echo json_encode(['sessions' => $sessions->orderBy('id')->get(['id', 'last_seen_at', 'idle_expires_at', 'revoked_at']),
            'goals' => $db->table('advertiser_goal_preferences')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
