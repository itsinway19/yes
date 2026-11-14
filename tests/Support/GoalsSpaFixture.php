<?php

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ProofRedis;
use Tests\Support\BusinessAuthorityFixture;

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
    if (getenv('GOALS_SPA_ONLY') === 'businesses') {
        $out = [];
        foreach (['a', 'b'] as $who) {
            $user = User::factory()->create(['name' => 'Goals proof '.$who, 'email' => 'goals-'.$who.'@example.test',
                'password' => Hash::make('DisposableGoalsProof42!')]);
            $out[$who] = ['user' => $user->id];
        }
        file_put_contents($file, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        echo json_encode(['database' => $db->selectOne('select current_database() db, current_user role, inet_server_port() port')], JSON_THROW_ON_ERROR).PHP_EOL;

        return;
    }
    $out = [];
    foreach (['a', 'b'] as $who) {
        $user = User::factory()->create(['name' => 'Goals proof '.$who, 'email' => 'goals-'.$who.'@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
        $store = new Store('business-lifecycle-'.$who, new ArraySessionHandler(120));
        $store->start();
        $businessRequest = Request::create('/api/businesses', 'POST');
        $businessRequest->setLaravelSession($store);
        $businessRequest->setUserResolver(fn () => $user);
        app(SessionAuthority::class)->issue($businessRequest, $user);
        $store->save();
        $out[$who] = ['user' => $user->id, 'businesses' => []];
        foreach ([1, 2] as $index) {
            $out[$who]['businesses'][] = app(BusinessScope::class)->create($businessRequest, (string) Str::uuid(), 'Business '.$who.' '.$index, null);
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
    if (str_starts_with($mode, 'source-')) {
        require __DIR__.'/sources-spa-fixture.php';
    }
    if ($mode === 'deny-business-view') {
        $id = $fixture[$who]['businesses'][0]['grant_refs']['business.context.read'];
        $qualification = $db->table('security_action_grants')->where('id', $id)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, $user);
    }
    match ($mode) {
        'expire' => $db->table('security_sessions')->where('user_id', $user)->update(['idle_expires_at' => now()->subSecond()]),
        'revoke' => $db->table('security_sessions')->where('user_id', $user)->update(['revoked_at' => now()]),
        'inspect', 'deny-business-view' => null,
        default => throw new RuntimeException('Unknown fixture action'),
    };
}
echo json_encode(['database' => $db->selectOne('select current_database() db, current_user role, inet_server_port() port'),
    'transitions' => $db->table('browser_identity_transitions')->get(['lineage_id', 'epoch', 'principal_id', 'current_session_key_hash', 'status', 'kind']),
    'sessions' => $db->table('security_sessions')->get(['user_id', 'session_key_hash', 'idle_expires_at', 'absolute_expires_at', 'revoked_at']),
    'effects' => $db->table('business_command_receipts')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
