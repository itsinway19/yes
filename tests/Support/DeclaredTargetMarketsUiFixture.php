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
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseReset;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (config('session.driver') !== 'file') {
    throw new RuntimeException('UI proof requires persistent disposable file sessions.');
}
(new DisposableDatabaseReset)->install();
$files = [];
$sessionFor = function (User $user) use (&$files): array {
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    $session = app('session')->driver();
    $session->start();
    Auth::login($user);
    $request = Request::create('/', 'GET');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    app(SessionAuthority::class)->issue($request, $user, 'password');
    $session->save();
    $files[] = config('session.files').'/'.$session->getId();
    $encrypter = app('encrypter');
    $cookie = $encrypter->encrypt(CookieValuePrefix::create($session->getName(), $encrypter->getKey()).$session->getId(), false);

    return [$request, ['name' => $session->getName(), 'value' => $cookie]];
};
$owner = User::factory()->create(['name' => 'Synthetic UI owner']);
app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($owner, 1);
[$request, $ownerCookie] = $sessionFor($owner);
$workspace = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, 'نشاط اختبار الأسواق', 'dtm-ui-proof'));
$type = DB::connection('pgsql_migrations')->table('business_types')->where('key', 'real_estate')->value('id');
app(WorkspaceContext::class)->runBusinessMutation($owner, $workspace['public_id'],
    fn (Workspace $active) => app(BusinessContext::class)->write($request, $active, ['business_type_id' => $type, 'version' => 0]), $request);
$member = User::factory()->create(['name' => 'Synthetic UI reader']);
$db = DB::connection('pgsql_migrations');
$db->table('workspace_memberships')->insert(['workspace_id' => $workspace['id'], 'user_id' => $member->id,
    'role' => 'member', 'status' => 'active', 'created_at' => now()]);
$db->table('security_action_grants')->insert(['subject_user_id' => $member->id, 'granted_by' => $owner->id,
    'subject_generation' => 1, 'source' => 'human', 'scope_kind' => 'workspace', 'workspace_id' => $workspace['id'],
    'action' => 'workspace.view', 'reason' => 'Disposable UI read fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
[, $readerCookie] = $sessionFor($member);
$proof = ['workspace' => $workspace['public_id'], 'ownerCookie' => $ownerCookie, 'readerCookie' => $readerCookie, 'sessionFiles' => $files];
file_put_contents(getenv('DTM_PROOF_RUN').'/ui-fixture.json', json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "UI_FIXTURE=READY; PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0\n";
