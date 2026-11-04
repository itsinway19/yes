<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessPurposeTestCase;

class BusinessPurposeDtmLineageTest extends BusinessPurposeTestCase
{
    private function intent(int $version): void
    {
        $value = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Completed bookings', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
        app(BusinessScope::class)->intent($this->request(), $this->business['business_public_id'], (string) Str::uuid(), $value, ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true], $version, (int) DB::connection('pgsql_migrations')->table('business_current_intents')->value('intent_revision'), $this->selection($this->business['grant_refs']['business.intent.declare']));
    }

    private function commandFor(string $mode, int $version = 1, array $countries = ['SA']): array
    {
        $command = self::command();
        $command['declaration']['dtm'] = ['mode' => $mode, 'version' => $version, 'countries' => $countries, 'subset' => [], 'statement' => 'Explicit exact market relationship'];
        $command['confirmations']['dtm'] = true;
        if ($mode === 'historical_attestation') {
            $command['confirmations']['historical'] = true;
            $command['declaration']['provenance']['historical_support'] = 'Owner attests the preserved earlier statement';
        }

        return $command;
    }

    private function item(string $id): array
    {
        return $this->project($id)['items'][0];
    }

    public function test_intent_only_context_change_does_not_revalidate_an_existing_current_relation(): void
    {
        $this->markets(['SA'], 0);
        $command = $this->commandFor('current_dependency');
        $first = $this->write($command);
        $this->intent(1);
        $item = $this->item($first['purpose_id']);
        $this->assertFalse($item['relationship_revalidation_required']);
        $this->assertSame('CURRENT_REFERENCE_AT_READ', $item['dtm_standing']);
        $this->assertSame(1, $item['applicable']['snapshot']['dtm']['version']);
        $this->assertSame(['SA'], $item['applicable']['snapshot']['dtm']['countries']);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_context_heads')->value('context_version'));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_market_transitions')->count());
    }

    public function test_fresh_current_modes_accept_an_older_still_current_dtm_reference_after_intent_change(): void
    {
        $this->markets(['SA'], 0);
        $this->intent(1);
        foreach (['current_alignment', 'current_dependency'] as $mode) {
            $first = $this->write($this->commandFor($mode));
            $this->assertFalse($this->item($first['purpose_id'])['relationship_revalidation_required']);
            $this->write($this->commandFor($mode, 2)); // Context revision supplied by Intent, same exact DTM snapshot.
        }
        $wrong = $this->commandFor('current_alignment', 1, ['AE']);
        $this->denied($wrong, '22023');
        $this->denied($this->commandFor('current_alignment', 99), '22023');
        $this->assertSame(4, DB::connection('pgsql_migrations')->table('object_purpose_revisions')->count());
        $http = $this->commandFor('current_dependency');
        $http['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null]];
        $this->postJson('/api/businesses/'.$this->business['business_public_id'].'/purposes', ['command' => $http, 'expected_context' => $this->contextVersion(), 'authority_selection' => $this->purposeSelection])->assertCreated();
    }

    public function test_real_dtm_transition_revalidates_existing_and_conflicts_with_fresh_stale_http_admission(): void
    {
        $this->markets(['SA'], 0);
        $first = $this->write($this->commandFor('current_dependency'));
        $this->markets(['AE'], 1);
        $this->assertTrue($this->item($first['purpose_id'])['relationship_revalidation_required']);
        foreach (['current_alignment', 'current_dependency'] as $mode) {
            $command = $this->commandFor($mode);
            $command['declaration']['included_objects'] = [['provider' => 'meta', 'account_namespace' => 'meta_ads_account', 'account_id' => '100', 'object_kind' => 'campaign', 'object_id' => '200', 'incarnation' => null]];
            $this->postJson('/api/businesses/'.$this->business['business_public_id'].'/purposes', ['command' => $command, 'expected_context' => $this->contextVersion(), 'authority_selection' => $this->purposeSelection])->assertConflict();
        }
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('object_purpose_revisions')->count());
    }

    public function test_away_and_back_does_not_erase_dtm_lineage_and_noop_does_not_create_drift(): void
    {
        $this->markets(['SA'], 0);
        $first = $this->write($this->commandFor('current_alignment'));
        $this->assertSame(1, $this->markets(['SA', 'SA'], 1));
        $this->assertFalse($this->item($first['purpose_id'])['relationship_revalidation_required']);
        $this->markets(['AE'], 1);
        $this->markets(['SA'], 2);
        $item = $this->item($first['purpose_id']);
        $this->assertTrue($item['relationship_revalidation_required']);
        $this->assertSame(['SA'], $item['applicable']['snapshot']['dtm']['countries']);
        foreach (['current_alignment', 'current_dependency'] as $mode) {
            $this->denied($this->commandFor($mode), '40001');
        }
        $fresh = $this->write($this->commandFor('current_dependency', 3));
        $this->assertFalse($this->item($fresh['purpose_id'])['relationship_revalidation_required']);
        $this->assertSame(3, DB::connection('pgsql_migrations')->table('business_market_transitions')->count());
    }

    public function test_historical_attestation_keeps_exact_premise_after_intent_and_dtm_transitions(): void
    {
        $this->markets(['SA'], 0);
        $first = $this->write($this->commandFor('historical_attestation'));
        $this->intent(1);
        $this->markets(['AE'], 2);
        $this->markets(['SA'], 3);
        $item = $this->item($first['purpose_id']);
        $this->assertSame('HISTORICAL_ATTESTATION', $item['dtm_standing']);
        $this->assertFalse($item['relationship_revalidation_required']);
        $this->assertSame(1, $item['applicable']['snapshot']['dtm']['version']);
        $this->assertSame(['SA'], $item['applicable']['snapshot']['dtm']['countries']);
    }
}
