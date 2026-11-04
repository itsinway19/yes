<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;

// Included only by the guarded Business browser fixture after isolated DB checks.
$db = DB::connection('pgsql_migrations');
$outPath = $run.'/business-legacy-history-fixture.json';
if ($mode === 'legacy-history-withdraw') {
    $out = json_decode(file_get_contents($outPath), true, flags: JSON_THROW_ON_ERROR);
    foreach ($out['purpose_qualifications'] as $q) {
        BusinessAuthorityFixture::revokeQualification($q, $owner->id);
    }
} elseif ($mode === 'legacy-history-setup') {
    $workspace = $db->table('workspaces')->select('id', 'public_id')->where('id', $base['a']['workspaces'][0]['id'])->sole();
    if (($base['legacy_purpose']['workspace'] ?? null) !== $workspace->public_id) {
        throw new RuntimeException('Historical Purpose must be seeded before retirement, never by a target writer');
    }
    $first = $base['legacy_purpose']['first'];
    $second = $base['legacy_purpose']['second'];
    $business = $scope->create($request, (string) Str::uuid(), 'نشاط السجل السابق المؤهل', null);
    $businessId = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
    $read = BusinessAuthorityFixture::root($businessId, $recipient->id, 'business.context.read');
    $source = $db->table('migration_cohort_members')->where('workspace_id', $workspace->id)->value('cohort_id');
    $target = $db->table('migration_cohort_members')->where('business_id', $businessId)->value('cohort_id');
    $transition = fn ($cohort, $mode, $epoch, $next, $manifest = null, $seal = null) => json_decode($db->scalar('SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,?,?,?,?::uuid,decode(?,\'hex\'))',
        [(string) Str::uuid(), $cohort, $mode, $epoch, $next, $manifest, $seal ?? hash('sha256', 'synthetic-browser-maintenance')]), true, flags: JSON_THROW_ON_ERROR);
    $transition($target, 'V2_ACTIVE', 0, 'RECOVERY_HOLD');
    $transition($source, 'LEGACY_ACTIVE', 0, 'DRAINING');
    $inventory = json_decode($db->scalar('SELECT builderad_v2_legacy_inventory(?::uuid)', [$source]), true, flags: JSON_THROW_ON_ERROR);
    $manifest = (string) Str::uuid7();
    $entries = [];
    $purposeQualifications = [];
    foreach ($inventory['entries'] as $native) {
        $actions = [match ($native['origin_table']) {
            'workspaces' => 'legacy.context.import', 'business_target_markets' => 'legacy.markets.import',
            'advertiser_goal_preferences' => 'legacy.preferences.import', default => 'legacy.history.map',
        }];
        if ($native['origin_table'] === 'workspaces') {
            $actions[] = 'legacy.intent.import';
        }
        $qid = (string) Str::uuid7();
        BusinessAuthorityFixture::insert('authority_qualification_receipts', ['qualification_id' => $qid,
            'policy_id' => 'test.synthetic.legacy.browser', 'policy_version' => 1, 'issuer_kind' => 'qualified_policy', 'issuer_grant_id' => null,
            'subject_user_id' => $owner->id, 'target_kind' => 'legacy_scope_mapping', 'target_ref' => $business['business_public_id'],
            'allowed_actions' => '{'.implode(',', $actions).'}', 'evidence_ref' => $native['origin_ref'], 'evidence_digest' => '\x'.$native['origin_digest'],
            'status' => 'active', 'revision' => 1, 'valid_from' => now()->subSecond()->toIso8601String(), 'valid_until' => null,
            'provenance' => 'SYNTHETIC browser history mapping only; no operating issuer or actual customer migration'], $owner->id);
        if ($native['origin_table'] === 'object_purpose_revisions') {
            $purposeQualifications[] = $qid;
        }
        $entries[] = [...array_intersect_key($native, array_flip(['origin_table', 'origin_key', 'native_version', 'origin_digest'])),
            'status' => 'qualified', 'target_business_public_id' => $business['business_public_id'], 'qualification_ref' => $qid];
    }
    $import = json_decode($db->scalar('SELECT builderad_v2_legacy_import(?::uuid,?::uuid,?,?::uuid,?::jsonb,decode(?,\'hex\'))',
        [(string) Str::uuid(), $source, $inventory['epoch'], $manifest, json_encode($entries, JSON_THROW_ON_ERROR), $inventory['native_digest']]), true, flags: JSON_THROW_ON_ERROR);
    $transition($source, 'DRAINING', 1, 'V2_ACTIVE', $manifest, $import['checkpoint']);
    $transition($target, 'RECOVERY_HOLD', 1, 'V2_ACTIVE', $manifest, $import['checkpoint']);
    file_put_contents($outPath, json_encode(['business' => $business['business_public_id'], 'read' => $read->id,
        'purpose_qualifications' => $purposeQualifications, 'first' => $first, 'second' => $second,
        'scope' => 'Real synthetic import/cutover; no live data or issuer'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
} else {
    throw new RuntimeException('Unknown original history fixture command');
}
