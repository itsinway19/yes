<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;

// Included only by the marked-disposable Business fixture. No operating root issuer.
$path = $run.'/business-purpose-fixture.json';
if ($mode === 'purpose-setup') {
    $created = $scope->create($request, (string) Str::uuid(), 'نشاط أغراض العناصر', null);
    $id = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $created['business_public_id'])->value('id');
    $out = ['business' => $created['business_public_id'], 'qualification' => 'SYNTHETIC QUALIFIED PURPOSE AUTHORITY ONLY'];
    foreach (['read' => 'business.context.read', 'write' => 'business.purpose.declare'] as $label => $action) {
        $root = BusinessAuthorityFixture::root($id, $owner->id, $action);
        $issued = $scope->grant($request, $out['business'], (string) Str::uuid(), 'issue', $recipient->public_id, $action, $root->id, null, 0, null, false, null, $select($root->id));
        $out[$label] = ['issuer' => $root->id, 'leaf' => $issued['grant_id'], 'action' => $action];
    }
    file_put_contents($path, json_encode($out, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    $out = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $label = $mode === 'purpose-revoke-read' ? 'read' : 'write';
    $scope->grant($request, $out['business'], (string) Str::uuid(), 'revoke', $recipient->public_id, $out[$label]['action'], null, $out[$label]['leaf'], 1, null, false, null, $select($out[$label]['issuer']));
}
