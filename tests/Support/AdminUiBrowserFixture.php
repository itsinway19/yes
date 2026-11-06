<?php

// Synthetic sessions only, in a guard-verified disposable cluster. No login bypass in runtime.
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\IdentityTransition;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
    throw new RuntimeException('Dedicated isolated Redis required.');
}
$db = DB::connection('pgsql_migrations');
$db->table('global_appearance')->delete();
$output = [];
foreach (($argv[1] ?? '') === 'employee' ? ['employee'] : ['founder', 'advertiser'] as $kind) {
    $user = User::firstWhere('email', 'admin-ui-'.$kind.'@example.test') ?? User::factory()->create(['name' => 'Admin UI '.$kind, 'email' => 'admin-ui-'.$kind.'@example.test']);
    if ($kind !== 'advertiser' && ! $db->table('internal_personnel')->where('user_id', $user->id)->exists()) {
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active',
            'attested_at' => now(), 'attested_by' => $user->id, 'factor_independence_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1, 2] as $factor) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Synthetic eligibility '.$factor,
                'credential_id' => 'appearance-'.$user->id.'-'.$factor, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($kind === 'founder' ? ['staff.view', 'staff.invite', 'staff.attest', 'staff.activate', 'staff.revoke', 'grant.manage', 'service.manage', 'business.manage', 'platform.maintenance.manage'] : ['staff.view'] as $action) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'source' => 'founder_bootstrap',
                'action' => $action, 'scope_kind' => 'platform', 'subject_generation' => 1,
                'reason' => 'synthetic appearance browser fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $internal = $kind !== 'advertiser';
    config(['session.cookie' => config($internal ? 'session.internal_cookie' : 'session.advertiser_cookie'), 'session.path' => $internal ? '/internal' : '/']);
    $app['session']->forgetDrivers();
    $app->forgetInstance('session.store');
    Auth::forgetGuards();
    $session = $app['session']->driver();
    $session->start();
    $request = Request::create($internal ? '/internal/administration/appearance' : '/workspaces', 'GET');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    Auth::guard($internal ? 'internal' : 'web')->login($user);
    app(SessionAuthority::class)->issue($request, $user, $internal ? 'internal_passkey' : 'password');
    // Match the existing TestCase synthetic document fixture. A legacy session
    // without a committed lineage is intentionally denied by the native SPA.
    $boundary = $internal ? 'internal' : 'advertiser';
    $lineage = app(IdentityTransition::class)->create($boundary);
    DB::connection('pgsql_authority')->table('browser_identity_transitions')->where('lineage_id', $lineage)->update([
        'epoch' => 1, 'current_session_key_hash' => SessionAuthority::sessionKey($request),
        'principal_id' => $user->id, 'status' => 'active', 'kind' => 'login', 'last_nonce' => bin2hex(random_bytes(32)), 'updated_at' => now(),
    ]);
    $session->put(['builderad.identity_lineage' => $lineage, 'builderad.identity_epoch' => 1,
        'builderad.identity_cookie_lease' => time() + (int) config('session.lifetime') * 60]);
    $session->save();
    $output[$kind] = [];
    foreach ([$session->getName() => $session->getId(), $session->getName().'-lineage' => $lineage] as $name => $value) {
        $output[$kind][] = ['name' => $name, 'value' => Crypt::encryptString(CookieValuePrefix::create($name, app('encrypter')->getKey()).$value),
            'domain' => 'localhost', 'path' => $internal ? '/internal' : '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax'];
    }
}
file_put_contents(getenv('GOALS_SPA_RUN').'/admin-ui-fixture.json', json_encode($output, JSON_THROW_ON_ERROR));
echo json_encode(['fixture' => 'synthetic admin UI sessions', 'database' => $db->selectOne('SELECT current_database() AS db,current_user AS role,inet_server_port() AS port')]), PHP_EOL;
