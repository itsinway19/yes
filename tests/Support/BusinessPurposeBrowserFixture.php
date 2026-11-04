<?php

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Synthetic identities, real creator and policy operations. No qualified root insertion.
$path = $run.'/business-purpose-fixture.json';
$creatorStore = new Store('purpose-creator-browser', new ArraySessionHandler(120));
$creatorStore->start();
$creatorRequest = Request::create('/api/businesses');
$creatorRequest->setLaravelSession($creatorStore);
$creatorRequest->setUserResolver(fn () => $recipient);
app(SessionAuthority::class)->issue($creatorRequest, $recipient);
$creatorStore->save();
$db = DB::connection('pgsql_migrations');
if ($mode === 'purpose-setup') {
    $created = $scope->create($creatorRequest, (string) Str::uuid(), 'نشاط أغراضه المعلنة', null);
    $out = ['business' => $created['business_public_id'], 'qualification' => 'OPERATING ORIGINAL CREATOR; PURPOSE OPT-IN MUST BE PERFORMED IN UI',
        'read' => ['issuer' => $created['grant_refs']['business.context.read'], 'leaf' => $created['grant_refs']['business.context.read'], 'action' => 'business.context.read']];
    file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $out = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($mode === 'purpose-revoke-write') {
        $businessId = $db->table('businesses')->where('public_id', $out['business'])->value('id');
        $grant = $db->table('security_action_grants as g')->join('authority_qualification_receipts as q', 'q.qualification_id', '=', 'g.qualification_id')
            ->where('g.business_id', $businessId)->where('g.subject_user_id', $recipient->id)->where('q.policy_id', 'business_purpose_statement_v1')->orderByDesc('g.id')->first(['g.id', 'g.version']);
        if (! $grant) {
            throw new RuntimeException('Purpose UI must complete the real creator opt-in first');
        }
        $out['write'] = ['issuer' => $grant->id, 'leaf' => $grant->id, 'action' => 'business.purpose.declare'];
        file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
    $label = $mode === 'purpose-revoke-read' ? 'read' : 'write';
    $scope->grant($creatorRequest, $out['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $out[$label]['action'], null,
        (int) $out[$label]['leaf'], 1, null, false, null, $select((int) $out[$label]['issuer']));
}
