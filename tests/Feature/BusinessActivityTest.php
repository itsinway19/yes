<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\TestCase;

final class BusinessActivityTest extends TestCase
{
    private function selection(int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
    }

    private function value(string $kind = 'sale'): array
    {
        return ['name' => 'بيع أجهزة التكييف', 'description' => 'تصريح عام عن الممارسة', 'kind' => $kind,
            'sectors' => ['تجارة'], 'specializations' => ['تكييف'], 'offering' => 'أجهزة جديدة', 'commercial_model' => null, 'channels' => [],
            'target_markets' => ['status' => 'declared', 'areas' => [['authority' => 'iso3166-1', 'level' => 'country', 'ref' => 'SA']]],
            'fulfillment' => ['status' => 'unspecified', 'areas' => []]];
    }

    private function setupBusiness(): array
    {
        $this->actingAs(User::factory()->create());
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'نشاط متعدد الممارسات'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$business['business_public_id'];
        $read = $business['grant_refs']['business.context.read'];
        $command = ['command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'operation' => 'create',
            'expected_revision' => 0, 'value' => $this->value(), 'authority_selection' => $this->selection($business['grant_refs']['business.context.manage']), 'read_selection' => $this->selection($read)];

        return [$base, $read, $command];
    }

    public function test_minimal_creation_multiple_practices_sectors_and_independent_history(): void
    {
        [$base, $read, $sale] = $this->setupBusiness();
        $this->getJson($base.'/practices?access='.$read)->assertOk()->assertJsonPath('data.practices', []);
        $this->postJson($base.'/practices', $sale)->assertOk()->assertJsonPath('data.practice.revision', 1);
        $maintenance = [...$sale, 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'value' => [...$this->value('maintenance'), 'name' => 'صيانة الأجهزة']];
        $this->postJson($base.'/practices', $maintenance)->assertOk();
        $installation = [...$sale, 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'value' => [...$this->value('installation'), 'name' => 'تركيب الأجهزة', 'sectors' => ['خدمات المقاولات']]];
        $this->postJson($base.'/practices', $installation)->assertOk();
        $reclassify = [...$sale, 'command_id' => (string) Str::uuid(), 'operation' => 'reclassify', 'expected_revision' => 1, 'value' => [...$this->value(), 'sectors' => ['تجارة المعدات']]];
        $this->postJson($base.'/practices', $reclassify)->assertOk()->assertJsonPath('data.practice.practice_id', $sale['practice_id'])->assertJsonPath('data.practice.revision', 2);
        $this->getJson($base.'/practices?access='.$read.'&practice='.$sale['practice_id'])->assertOk()->assertJsonCount(2, 'data.history')->assertJsonPath('data.history.1.value.sectors', ['تجارة']);
        $this->getJson($base.'/practices?access='.$read)->assertOk()->assertJsonCount(3, 'data.practices');
        $this->postJson($base.'/practices', [...$maintenance, 'command_id' => (string) Str::uuid(), 'operation' => 'deactivate', 'expected_revision' => 1, 'value' => null])->assertOk()->assertJsonPath('data.practice.state', 'inactive');
        $this->getJson($base.'/practices?access='.$read.'&practice='.$sale['practice_id'])->assertOk()->assertJsonPath('data.practices.0.revision', 2);
    }

    public function test_transition_meaning_rejects_mislabeled_replacement_without_a_revision_or_receipt(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $this->postJson($base.'/practices', $command)->assertOk();
        $invalid = [
            ['update', [...$command['value'], 'sectors' => ['سيارات']], null],
            ['update', $this->value('maintenance'), null],
            ['reclassify', [...$command['value'], 'description' => 'استبدال غير تصنيفي'], null],
            ['reclassify', $this->value('maintenance'), null],
            ['correct', [...$command['value'], 'description' => 'خطأ سابق'], null],
            ['correct', [...$this->value('rental'), 'offering' => 'سيارات'], 'تصحيح لا يبرر تبديل هوية العمل بالكامل'],
            ['update', $command['value'], null],
        ];
        foreach ($invalid as [$operation, $value, $reason]) {
            $id = (string) Str::uuid();
            $body = [...$command, 'command_id' => $id, 'expected_revision' => 1, 'operation' => $operation, 'value' => $value];
            if ($reason !== null) {
                $body['correction_reason'] = $reason;
            }
            $this->postJson($base.'/practices', $body)->assertUnprocessable();
            $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_practice_revisions')->count());
            $this->assertFalse(DB::connection('pgsql_migrations')->table('business_command_receipts')->where('command_id', $id)->exists());
        }
    }

    public function test_correction_names_the_prior_revision_and_reason_while_reclassification_preserves_other_dimensions(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $this->postJson($base.'/practices', $command)->assertOk();
        $corrected = [...$command['value'], 'description' => 'تصحيح وصف سابق', 'sectors' => ['تجارة المعدات']];
        $correction = [...$command, 'command_id' => (string) Str::uuid(), 'expected_revision' => 1, 'operation' => 'correct',
            'value' => $corrected, 'correction_reason' => 'كان الوصف والتصنيف السابقان غير دقيقين'];
        $this->postJson($base.'/practices', $correction)->assertOk()->assertJsonPath('data.practice.corrects_revision', 1)
            ->assertJsonPath('data.practice.correction_reason', $correction['correction_reason']);
        $this->postJson($base.'/practices', $correction)->assertOk()->assertJsonPath('data.historical_receipt', true);
        $this->postJson($base.'/practices', [...$command, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2,
            'operation' => 'reclassify', 'value' => [...$corrected, 'specializations' => ['تجهيز المباني']]])->assertOk()->assertJsonPath('data.practice.corrects_revision', null);
        $history = $this->getJson($base.'/practices?access='.$read.'&practice='.$command['practice_id'])->assertOk()
            ->assertJsonCount(3, 'data.history')->json('data.history.2.value');
        $this->assertEquals($command['value'], $history); // JSON object key order is not semantic.
    }

    public function test_replay_preserves_effect_and_history_but_requalifies_current_read_and_write_authority(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $first = $this->postJson($base.'/practices', $command)->assertOk()->json('data.practice');
        $this->postJson($base.'/practices', [...$command, 'command_id' => (string) Str::uuid(), 'operation' => 'correct', 'correction_reason' => 'تصحيح وصف سابق غير دقيق', 'expected_revision' => 1, 'value' => [...$this->value(), 'description' => 'تصحيح الوصف']])->assertOk();
        $this->postJson($base.'/practices', $command)->assertOk()->assertJsonPath('data.practice', $first)->assertJsonPath('data.current_revision', 2)->assertJsonPath('data.historical_receipt', true);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('business_practice_revisions')->count());
        $qualification = DB::connection('pgsql_migrations')->table('security_action_grants')->where('id', $read)->value('qualification_id');
        BusinessAuthorityFixture::revokeQualification($qualification, auth()->id());
        $this->postJson($base.'/practices', $command)->assertForbidden();
        $this->getJson($base.'/practices?access='.$read)->assertForbidden();
    }

    public function test_current_offering_and_context_can_evolve_together_without_claiming_a_historical_error(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $this->postJson($base.'/practices', $command)->assertOk();
        $value = [...$command['value'], 'offering' => 'أجهزة جديدة وخيارات إضافية', 'description' => 'اتساع العرض الحالي للعمل نفسه',
            'channels' => ['متجر', 'هاتف'], 'commercial_model' => 'بيع مباشر', 'sectors' => ['تجارة المعدات والتجهيزات']];
        $this->postJson($base.'/practices', [...$command, 'command_id' => (string) Str::uuid(), 'operation' => 'update',
            'expected_revision' => 1, 'value' => $value])->assertOk()->assertJsonPath('data.practice.operation', 'update')
            ->assertJsonPath('data.practice.corrects_revision', null)->assertJsonPath('data.practice.value.offering', $value['offering']);
    }

    public function test_conflict_unknown_fields_and_cross_business_access_fail_without_effect(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $this->postJson($base.'/practices', $command)->assertOk();
        $this->postJson($base.'/practices', [...$command, 'command_id' => (string) Str::uuid(), 'operation' => 'update'])->assertConflict()->assertJsonPath('error', 'practice_revision_rejected');
        $this->postJson($base.'/practices', [...$command, 'command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'value' => [...$this->value(), 'margin' => 30]])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->getJson($base.'/practices?access='.$read)->assertForbidden();
        $this->postJson($base.'/practices', $command)->assertForbidden();
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_practice_revisions')->count());
    }

    public function test_current_read_access_does_not_grant_declaration_management_or_other_business_access(): void
    {
        [$base, $read, $command] = $this->setupBusiness();
        $this->postJson($base.'/practices', $command)->assertOk();
        $reader = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', basename($base))->value('id');
        $grant = BusinessAuthorityFixture::root($id, $reader->id, 'business.context.read');
        $this->actingAs($reader)->getJson($base.'/practices?access='.$grant->id)->assertOk()->assertJsonCount(1, 'data.practices');
        $this->postJson($base.'/practices', [...$command, 'authority_selection' => $this->selection($grant->id), 'read_selection' => $this->selection($grant->id)])->assertForbidden();
        $other = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'نشاط منفصل'])->assertCreated()->json('data');
        $this->postJson('/api/businesses/'.$other['business_public_id'].'/practices', [...$command, 'authority_selection' => $this->selection($other['grant_refs']['business.context.manage']), 'read_selection' => $this->selection($other['grant_refs']['business.context.read'])])->assertConflict();
        $this->assertSame(1, $db->table('business_practice_revisions')->count());
    }
}
