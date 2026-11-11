<?php

use App\Foundation\Tenancy\WorkspaceContext;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;

// Explicit historical rows for retained end/replay behavior. No production
// writer is reinstalled and the ordinary Business surface stays unchanged.
$db = DB::connection('pgsql_migrations');
$statePath = $run.'/historical-links-browser.json';
$store = new Store('historical-link-browser', new ArraySessionHandler(120));
$store->start();
$historicalRequest = Request::create('/api/businesses');
$historicalRequest->setLaravelSession($store);
$historicalRequest->setUserResolver(fn () => $recipient);
app(SessionAuthority::class)->issue($historicalRequest, $recipient);
$store->save();
if ($mode === 'historical-links-setup') {
    $created = $scope->create($historicalRequest, (string) Str::uuid(), 'Historical link browser fixture', null);
    $state = ['business' => $created['business_public_id'], 'read' => $created['grant_refs']['business.context.read'],
        'link' => $created['grant_refs']['business.link.manage'], 'kind' => 'collaboration'];
    $businessId = $db->table('businesses')->where('public_id', $state['business'])->value('id');
    $authority = $db->table('security_action_grants')->where('id', $state['link'])->sole();
    BusinessAuthorityFixture::legacyWorkspaceLink($businessId, $base['b']['workspaces'][0]['id'], $authority, $recipient->id, 'collaboration');
} else {
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    $businessId = $db->table('businesses')->where('public_id', $state['business'])->value('id');
    if ($mode === 'historical-links-replace') {
        $authority = $db->table('security_action_grants')->where('id', $state['link'])->sole();
        BusinessAuthorityFixture::legacyWorkspaceLink($businessId, $base['b']['workspaces'][0]['id'], $authority, $recipient->id, $state['kind']);
    } elseif ($mode === 'historical-links-agency') {
        $agency = json_decode(file_get_contents($run.'/business-agency-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        $state = ['business' => $agency['business'], 'read' => $agency['read']['leaf'], 'link' => $agency['link']['leaf'], 'kind' => 'agency'];
        $businessId = $db->table('businesses')->where('public_id', $state['business'])->value('id');
        $authority = $db->table('security_action_grants')->where('id', $state['link'])->sole();
        BusinessAuthorityFixture::legacyWorkspaceLink($businessId, $base['b']['workspaces'][0]['id'], $authority, $recipient->id, 'agency');
    }
}
// Runtime Business qualification and each independent Workspace qualification
// precede privileged fixture inspection. Rows are test evidence, never a new UI
// projection or a substitute for a production permission boundary.
$scope->read($historicalRequest, $state['business'], $select((int) $state['read']));
$visible = [];
foreach ($base['b']['workspaces'] as $workspace) {
    try {
        app(WorkspaceContext::class)->run($recipient, $workspace['public_id'], function () use (&$visible, $workspace): void {
            $visible[] = $workspace['id'];
        }, $historicalRequest);
    } catch (AuthorizationException) {
        // Failed authority excludes metadata; it never establishes an empty DB.
    }
}
$state['qualified_workspace_ids'] = $visible;
$state['workspaces'] = $db->table('workspaces')->whereIn('id', $visible)->orderBy('id')->get(['public_id', 'slug'])->all();
$state['history'] = $db->table('business_workspace_links')->where('business_id', $businessId)->whereIn('workspace_id', $visible)
    ->orderBy('revision')->get(['link_id', 'workspace_id', 'kind', 'status', 'revision'])->all();
$state['all_historical_rows'] = $db->table('business_workspace_links')->where('business_id', $businessId)->count();
$state['authority_selection'] = $select((int) $state['link']);
$state['qualification'] = 'SYNTHETIC HISTORICAL FIXTURE; runtime Business and Workspace qualifications checked; no product UI claim';
file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
