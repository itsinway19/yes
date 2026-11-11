<?php

use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Loaded only by the guarded Business browser fixture. Identity creation is the
// sole fixture shortcut: all Business, team, mandate and assignment roots are
// obtained through the same runtime writers as the application.
$agencyFixturePath = $run.'/business-agency-browser-fixture.json';
$agencyDb = DB::connection('pgsql_migrations');
if ($mode === 'business-agency-setup') {
    $agencyActors = [];
    foreach (['client', 'agency', 'employee'] as $agencyRole) {
        $agencyActor = User::factory()->create([
            'name' => 'Agency browser '.$agencyRole,
            'email' => 'agency-browser-'.$agencyRole.'-'.Str::uuid().'@example.test',
            'password' => Hash::make('DisposableGoalsProof42!'),
        ]);
        $agencyActors[$agencyRole] = $agencyActor;
    }
    $agencyRequests = [];
    foreach ($agencyActors as $agencyRole => $agencyActor) {
        $agencyStore = new Store('agency-browser-'.$agencyRole, new ArraySessionHandler(120));
        $agencyStore->start();
        $agencyRequest = Request::create('/api/businesses');
        $agencyRequest->setLaravelSession($agencyStore);
        $agencyRequest->setUserResolver(fn () => $agencyActor);
        app(SessionAuthority::class)->issue($agencyRequest, $agencyActor);
        $agencyStore->save();
        $agencyRequests[$agencyRole] = $agencyRequest;
    }
    $agencyFixture = [
        'qualification' => 'IDENTITIES ONLY ARE FIXTURES; BUSINESS CREATION USES RUNTIME; TEAM AND AGENCY WRITERS RUN THROUGH BROWSER',
        'workspace_count_before' => $agencyDb->table('workspaces')->count(),
        'actors' => array_map(fn ($actor) => ['id' => $actor->id, 'public_id' => $actor->public_id, 'email' => $actor->email], $agencyActors),
        'client' => $scope->create($agencyRequests['client'], (string) Str::uuid(), 'عميل التفويض المستقل', null),
        'other_client' => $scope->create($agencyRequests['client'], (string) Str::uuid(), 'عميل آخر غير مسند', null),
        'agency' => $scope->create($agencyRequests['agency'], (string) Str::uuid(), 'نشاط الوكالة المستقل', null),
    ];
    file_put_contents($agencyFixturePath, json_encode($agencyFixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $agencyFixture = json_decode(file_get_contents($agencyFixturePath), true, flags: JSON_THROW_ON_ERROR);
    $agencyActorIds = array_column($agencyFixture['actors'], 'id');
    $agencyAudit = [
        'workspace_count_before' => $agencyFixture['workspace_count_before'],
        'workspace_count_after' => $agencyDb->table('workspaces')->count(),
        'actor_workspace_memberships' => $agencyDb->table('workspace_memberships')->whereIn('user_id', $agencyActorIds)->count(),
        'actor_external_resource_grants' => $agencyDb->table('security_action_grants')->whereIn('subject_user_id', $agencyActorIds)
            ->whereIn('scope_kind', ['source', 'client_data', 'data_use'])->count(),
    ];
    if ($agencyAudit['workspace_count_before'] !== $agencyAudit['workspace_count_after']
        || $agencyAudit['actor_workspace_memberships'] !== 0 || $agencyAudit['actor_external_resource_grants'] !== 0) {
        throw new RuntimeException('Business team and agency browser flow created an inferred Workspace or external resource right.');
    }
    file_put_contents($run.'/business-agency-browser-audit.json', json_encode($agencyAudit, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}
