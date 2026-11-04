<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BusinessPurposeTestCase;

class BusinessPurposeTemporalTest extends BusinessPurposeTestCase
{
    public function test_text_correction_preserves_bounded_snapshot_and_explicit_end_removal_requires_confirmation(): void
    {
        $command = self::command();
        $command['effective_until'] = '2030-01-01T00:00:00Z';
        $first = $this->write($command);
        $correction = $this->transition($first, 'correct');
        $correction['effective_until'] = $command['effective_until'];
        $correction['declaration'] = $command['declaration'];
        $correction['declaration']['meaning'] = 'Corrected wording';
        $second = $this->write($correction);
        $revision = $this->project($first['purpose_id'])['items'][0]['applicable'];
        $this->assertSame('2030-01-01T00:00:00+00:00', (new DateTimeImmutable($revision['effective_until']))->format(DATE_ATOM));
        $this->assertJsonStringEqualsJsonString(json_encode($correction['declaration']), json_encode($revision['snapshot']));
        $remove = $this->transition($second, 'replace');
        $remove['declaration'] = $correction['declaration'];
        $remove['confirmations']['standing'] = false;
        $this->denied($remove, '22023');
        $remove['confirmations']['standing'] = true;
        $remove['confirmations']['transition'] = false;
        $this->denied($remove, '22023');
        $remove['confirmations']['transition'] = true;
        $this->write($remove);
        $this->assertNull($this->project($first['purpose_id'])['items'][0]['applicable']['effective_until']);
    }

    public function test_historical_market_reference_is_never_promoted_to_current_alignment(): void
    {
        $this->markets(['SA'], 0);
        $this->markets(['AE'], 1);
        $command = self::command();
        $command['declaration']['dtm'] = ['mode' => 'historical_attestation', 'version' => 1, 'countries' => ['SA'], 'subset' => [], 'statement' => 'Retained earlier market relationship'];
        $command['confirmations']['dtm'] = $command['confirmations']['historical'] = true;
        $command['declaration']['provenance']['historical_support'] = 'Owner attests the preserved earlier statement';
        $first = $this->write($command);
        $item = $this->project($first['purpose_id'])['items'][0];
        $this->assertSame('HISTORICAL_ATTESTATION', $item['dtm_standing']);
        $this->assertFalse($item['relationship_revalidation_required']);
        $this->assertJsonStringEqualsJsonString(json_encode($command['declaration']['dtm']), json_encode($item['applicable']['snapshot']['dtm']));
    }

    public function test_cancelled_future_transition_is_history_not_active_schedule_and_prior_knowledge_survives(): void
    {
        $first = $this->write(self::command());
        $future = $this->write($this->transition($first, 'replace', '2030-01-01T00:00:00Z'));
        $known = DB::connection('pgsql_migrations')->table('object_purpose_revisions')
            ->where('revision_id', $future['revision_id'])->value('accepted_at');
        $replacement = $this->write($this->transition($future, 'replace', '2029-01-01T00:00:00Z', [$future['revision_id']]));
        $now = $this->project($first['purpose_id']);
        $this->assertSame([$replacement['revision_id']], $now['items'][0]['scheduled_revision_ids']);
        $this->assertCount(3, $now['items'][0]['history']);
        $this->assertSame($future['revision_id'], $now['items'][0]['history'][1]['revision_id']);
        $earlier = $this->project($first['purpose_id'], known: $known);
        $this->assertSame([$future['revision_id']], $earlier['items'][0]['scheduled_revision_ids']);
        $this->assertCount(2, $earlier['items'][0]['history']);
        $this->assertSame($replacement['revision_id'], $this->project($first['purpose_id'], '2031-01-01T00:00:00Z')['items'][0]['applicable']['revision_id']);
        $withdrawal = $this->write($this->transition($replacement, 'withdraw', '2032-01-01T00:00:00Z'));
        $this->assertSame([$replacement['revision_id'], $withdrawal['revision_id']], $this->project($first['purpose_id'])['items'][0]['scheduled_revision_ids']);
        $this->assertSame('UNKNOWN', $this->project($first['purpose_id'], '2033-01-01T00:00:00Z')['state']);
    }

    public function test_supported_historical_withdrawal_retains_its_own_support_without_rewriting_original_provenance(): void
    {
        $first = $this->write(self::command());
        $command = $this->transition($first, 'withdraw', '2025-01-01T00:00:00Z', [$first['revision_id']]);
        $command['withdrawal_reason'] = 'Owner attests the preserved shutdown record establishing withdrawal on the stated historical date';
        $this->write($command);
        $projection = $this->project($first['purpose_id']);
        $this->assertSame('UNKNOWN', $projection['state']);
        $this->assertSame($command['withdrawal_reason'], $projection['items'][0]['history'][1]['reason']);
        $this->assertNull($projection['items'][0]['history'][0]['snapshot']['provenance']['historical_support']);
    }

    public function test_opaque_numeric_looking_ids_never_compare_as_numbers(): void
    {
        $c = self::command();
        $c['declaration']['included_objects'][0]['object_id'] = '01';
        $this->write($c);
        $object = $c['declaration']['included_objects'][0];
        $object['object_id'] = '1';
        $this->assertSame('UNKNOWN', $this->project(object: $object)['state']);
    }

    private function transition(array $previous, string $operation, ?string $effective = null, array $affected = []): array
    {
        $c = self::command();
        $c['purpose_id'] = $previous['purpose_id'];
        $c['expected_version'] = $previous['version'];
        $c['operation'] = $operation;
        $c['supersedes_revision_id'] = $previous['revision_id'];
        $c['effective_from'] = $effective;
        $c['affected_revision_ids'] = $affected;
        $c['confirmations']['transition'] = true;
        if ($effective && new DateTimeImmutable($effective) < new DateTimeImmutable) {
            $c['confirmations']['historical'] = true;
            $c['declaration']['provenance']['historical_support'] = 'Owner attests preserved historical standing';
        }
        if ($operation === 'correct') {
            $c['correction_reason'] = 'Corrects the documented historical meaning';
        }
        if ($operation === 'withdraw') {
            $c['declaration'] = null;
            $c['withdrawal_reason'] = 'Business no longer assigns this use';
        }

        return $c;
    }

    public function test_future_replacement_and_later_correction_preserve_earlier_knowledge(): void
    {
        $c = self::command();
        $c['effective_from'] = '2020-01-01T00:00:00Z';
        $c['confirmations']['historical'] = true;
        $c['declaration']['provenance']['historical_support'] = 'Preserved Owner statement';
        $first = $this->write($c);
        $k1 = DB::connection('pgsql_migrations')->table('object_purpose_revisions')->value('accepted_at');
        $next = $this->transition($first, 'replace', '2030-01-01T00:00:00Z');
        $next['declaration']['meaning'] = 'After launch use';
        $second = $this->write($next);
        $this->assertSame(1, $this->project($first['purpose_id'], '2025-01-01T00:00:00Z')['items'][0]['applicable']['version']);
        $this->assertSame(2, $this->project($first['purpose_id'], '2031-01-01T00:00:00Z')['items'][0]['applicable']['version']);
        $correct = $this->transition($second, 'correct', '2021-01-01T00:00:00Z', [$second['revision_id']]);
        $correct['declaration']['meaning'] = 'Corrected historical use';
        $this->write($correct);
        $this->assertSame(3, $this->project($first['purpose_id'], '2025-01-01T00:00:00Z')['items'][0]['applicable']['version']);
        $then = $this->project($first['purpose_id'], '2025-01-01T00:00:00Z', $k1);
        $this->assertSame(1, $then['items'][0]['applicable']['version']);
        $this->assertCount(1, $then['items'][0]['history']);
    }

    public function test_withdrawal_is_unknown_and_full_old_revision_remains_retrievable(): void
    {
        $first = $this->write(self::command());
        $this->write($this->transition($first, 'withdraw'));
        $p = $this->project($first['purpose_id']);
        $this->assertSame('UNKNOWN', $p['state']);
        $this->assertNull($p['items'][0]['applicable']);
        $this->assertCount(2, $p['items'][0]['history']);
        $this->assertSame('Introduce the business before launch.', $p['items'][0]['history'][0]['snapshot']['meaning']);
    }

    public function test_object_lookup_is_exact_no_descendants_account_merge_or_latest_winner(): void
    {
        $c = self::command();
        $obj = $c['declaration']['included_objects'][0];
        $this->write($c);
        $c = self::command();
        $c['declaration']['meaning'] = 'Another compatible use';
        $this->write($c);
        $this->assertSame('MULTIPLE_APPLICABLE', $this->project(object: $obj)['state']);
        $this->assertCount(2, $this->project(object: $obj)['items']);
        $obj['account_id'] = 'Another-account';
        $this->assertSame('UNKNOWN', $this->project(object: $obj)['state']);
        $obj['account_id'] = 'Ac-1';
        $obj['object_kind'] = 'child';
        $this->assertSame('UNKNOWN', $this->project(object: $obj)['state']);
    }

    public function test_dtm_reference_survives_change_and_current_relation_requires_review(): void
    {
        $this->markets(['SA'], 0);
        $c = self::command();
        $c['declaration']['dtm'] = ['mode' => 'current_dependency', 'version' => 1, 'countries' => ['SA'], 'subset' => [], 'statement' => 'Use depends on the declared markets'];
        $c['confirmations']['dtm'] = true;
        $first = $this->write($c);
        $unchanged = $this->project($first['purpose_id'])['items'][0];
        $this->assertFalse($unchanged['relationship_revalidation_required']);
        $this->assertSame('CURRENT_REFERENCE_AT_READ', $unchanged['dtm_standing']);
        $this->markets(['AE'], 1);
        $p = $this->project($first['purpose_id']);
        $this->assertSame(1, $p['items'][0]['applicable']['snapshot']['dtm']['version']);
        $this->assertSame(['SA'], $p['items'][0]['applicable']['snapshot']['dtm']['countries']);
        $this->assertTrue($p['items'][0]['relationship_revalidation_required']);
        $this->assertSame('REFERENCED_CURRENT_CLAIM_REQUIRES_REVALIDATION', $p['items'][0]['dtm_standing']);
        $this->assertSame('CONDITION_CONTINUITY_NOT_ESTABLISHED', $p['items'][0]['continuity']);
        $this->assertNull($p['items'][0]['applicable']['snapshot']['intent']);
    }

    public function test_explicit_conflicts_are_preserved_without_text_classification(): void
    {
        $c = self::command();
        $c['declaration']['conflicts'] = ['Owner declares incompatibility with retained earlier statement'];
        $this->write($c);
        $p = $this->project();
        $this->assertSame('CONFLICTING', $p['state']);
        $this->assertSame(['Owner declares incompatibility with retained earlier statement'], $p['items'][0]['applicable']['snapshot']['conflicts']);
    }
}
