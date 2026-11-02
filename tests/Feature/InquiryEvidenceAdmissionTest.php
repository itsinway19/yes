<?php

namespace Tests\Feature;

use App\Commercial\InquiryEvidence;
use App\Foundation\Business\BusinessScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\InquiryEvidenceFixture;
use Tests\TestCase;

class InquiryEvidenceAdmissionTest extends TestCase
{
    use InquiryEvidenceFixture;

    public function test_criterion_definitions_survive_exact_replay_correction_history_and_withdrawal(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $correct = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.correct']);
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            } foreach ($value as &$child) {
                $child = $canonical($child);
            } unset($child);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };
        $a = $this->value();
        $a['acceptance']['criterion_definition'] = 'Inquiry explicitly requests an available service';
        $command = (string) Str::uuid();
        $first = $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $a, $record);
        $this->assertSame($first, $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $a, $record));
        $b = $a;
        $b['acceptance']['criterion_definition'] = 'الاستفسار يؤكد الاستعداد لطلب خدمة ضمن نطاق النشاط';
        $b['acceptance']['criterion_version'] = 2;
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $b, $correct);
        $current = $f['evidence']->read($f['request'], $f['business'], $read)['items'][0];
        $this->assertSame($canonical($b), $canonical($current['value']));
        $history = $f['evidence']->read($f['request'], $f['business'], $read, $first['declaration_id']);
        $this->assertSame($canonical([$a, $b]), $canonical(array_column($history['items'], 'value')));
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $a, $correct), 'I4090');
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $b, $record), '40001');
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 2, 'withdraw', null, $correct);
        $this->assertNull($f['evidence']->read($f['request'], $f['business'], $read)['items'][0]['value']);
        $this->assertSame($canonical([$a, $b, null]), $canonical(array_column($f['evidence']->read($f['request'], $f['business'], $read, $first['declaration_id'])['items'], 'value')));
    }

    public function test_criterion_definition_rejects_missing_unbounded_contact_code_reference_and_extra_fields(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        foreach ([null, '', 'short', str_repeat('a', 501), 'Call +966 555 123 456', 'Email client@example.test', '<script>alert(1)</script>', 'https://example.test/rule', 'javascript:alert(1)', 'Rule { execute }', "Line\ncontrol"] as $definition) {
            $value = $this->value();
            $value['acceptance']['criterion_definition'] = $definition;
            $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        }
        $value = $this->value();
        unset($value['acceptance']['criterion_definition']);
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        $value = $this->value();
        $value['acceptance']['definition_url'] = 'https://example.test';
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        $value = $this->value();
        $value['acceptance']['criterion_code'] = 'within_service_area';
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        $value['acceptance']['criterion_definition'] = null;
        $receipt = $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record);
        $this->assertSame('SOURCE_ASSERTED', $receipt['assurance']);
    }

    public function test_explicit_enrollment_creates_only_three_private_non_delegable_actions(): void
    {
        $f = $this->fixture();
        $this->assertCount(3, $f['enrolled']['grants']);
        $grants = DB::connection('pgsql_migrations')->table('security_action_grants')->where('qualification_id', $f['enrolled']['qualification'])->get();
        $this->assertCount(3, $grants);
        foreach ($grants as $grant) {
            $this->assertFalse($grant->delegable);
            $this->assertSame('qualified_policy', $grant->source);
            $this->assertSame($f['user']->id, $grant->subject_user_id);
        }
        $this->assertSame(7, DB::connection('pgsql_migrations')->table('security_action_grants')->where('source', 'business_draft_creation')->count());
    }

    public function test_record_read_correct_history_and_withdraw_keep_assertions_and_issuer(): void
    {
        $f = $this->fixture();
        $write = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        $correct = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.correct']);
        $first = $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $write);
        $this->assertArrayNotHasKey('value', $first);
        $this->assertFalse($first['independently_verified']);
        $value = $this->value();
        $value['acceptance']['count'] = 20;
        $second = $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $value, $correct);
        $this->assertSame(2, $second['revision']);
        $history = $f['evidence']->read($f['request'], $f['business'], $read, $first['declaration_id']);
        $this->assertSame([10, 20], array_column(array_column(array_column($history['items'], 'value'), 'acceptance'), 'count'));
        $this->assertSame($f['user']->public_id, $history['items'][0]['original_issuer']);
        $this->assertFalse($history['independently_verified']);
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 2, 'withdraw', null, $correct);
        $current = $f['evidence']->read($f['request'], $f['business'], $read);
        $this->assertSame('withdrawn', $current['items'][0]['state']);
        $this->assertNull($current['items'][0]['value']);
        $this->assertSame(3, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }

    public function test_command_replay_is_exact_and_stale_correction_conflicts(): void
    {
        $f = $this->fixture();
        $write = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $correct = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.correct']);
        $command = (string) Str::uuid();
        $first = $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $this->value(), $write);
        $this->assertSame($first, $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $this->value(), $write));
        $other = $this->value();
        $other['inquiries']['count'] = 101;
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $other, $write), '40001');
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $other, $correct);
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $this->value(), $correct), 'I4090');
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }

    public function test_missing_axes_and_zero_are_not_filled_or_reinterpreted(): void
    {
        $f = $this->fixture();
        $value = $this->value();
        $value['cost'] = null;
        $value['inquiries'] = null;
        $value['acceptance']['count'] = 0;
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']));
        $item = $f['evidence']->read($f['request'], $f['business'], $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']))['items'][0];
        $this->assertNull($item['value']['cost']);
        $this->assertNull($item['value']['inquiries']);
        $this->assertSame(0, $item['value']['acceptance']['count']);
    }

    public function test_old_rights_do_not_implicitly_authorize_new_operations_or_auto_enroll(): void
    {
        $request = $this->requestFor(User::factory()->create());
        $draft = app(BusinessScope::class)->create($request, (string) Str::uuid(), 'Not enrolled', null);
        $evidence = app(InquiryEvidence::class);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('inquiry_evidence_enrollments')->count());
        foreach (['business.context.read', 'business.context.manage'] as $action) {
            $selection = $this->selection($draft['grant_refs'][$action]);
            $this->denied(fn () => $evidence->write($request, $draft['business_public_id'], (string) Str::uuid(), null, 0, 'record', $this->value(), $selection));
            $this->denied(fn () => $evidence->read($request, $draft['business_public_id'], $selection));
        }
        $basis = $this->selection($draft['grant_refs']['business.context.read']);
        $this->denied(fn () => $evidence->enroll($request, $draft['business_public_id'], (string) Str::uuid(), 1, false, $basis), '22023');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('inquiry_evidence_enrollments')->count());
    }

    public function test_enrollment_replay_has_no_implicit_regrant_and_requires_current_basis(): void
    {
        $f = $this->fixture();
        $command = (string) Str::uuid();
        $first = $f['evidence']->enroll($f['request'], $f['business'], $command, 1, true, $f['basis']);
        $this->assertSame($first, $f['evidence']->enroll($f['request'], $f['business'], $command, 1, true, $f['basis']));
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('inquiry_evidence_enrollments')->count());
        BusinessAuthorityFixture::revokeQualification($f['eligibility']['basis_qualification'], $f['user']->id);
        $this->denied(fn () => $f['evidence']->enroll($f['request'], $f['business'], $command, 1, true, $f['basis']));
    }

    public function test_revoke_lost_ack_replays_without_reviving_right_and_payload_conflict_is_rejected(): void
    {
        $f = $this->fixture();
        $action = 'business.inquiry_evidence.read';
        $selection = $this->selection($f['enrolled']['grants'][$action]);
        $command = (string) Str::uuid();
        $first = $f['evidence']->revoke($f['request'], $f['business'], $command, $action, $selection);
        $this->assertSame($first, $f['evidence']->revoke($f['request'], $f['business'], $command, $action, $selection));
        $this->denied(fn () => $f['evidence']->read($f['request'], $f['business'], $selection));
        $this->denied(fn () => $f['evidence']->revoke($f['request'], $f['business'], $command, 'business.inquiry_evidence.record', $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record'])), '40001');
        BusinessAuthorityFixture::revokeQualification($f['eligibility']['basis_qualification'], $f['user']->id);
        $this->denied(fn () => $f['evidence']->revoke($f['request'], $f['business'], $command, $action, $selection));
    }

    public function test_permissions_are_independent_and_write_ack_does_not_disclose_current_values(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        $correct = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.correct']);
        $f['evidence']->revoke($f['request'], $f['business'], (string) Str::uuid(), 'business.inquiry_evidence.read', $read);
        $first = $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $record);
        $this->assertArrayNotHasKey('value', $first);
        $this->denied(fn () => $f['evidence']->read($f['request'], $f['business'], $record));
        $this->denied(fn () => $f['evidence']->read($f['request'], $f['business'], $read));
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $this->value(), $record));
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 1, 'correct', $this->value(), $correct);
        $f['evidence']->revoke($f['request'], $f['business'], (string) Str::uuid(), 'business.inquiry_evidence.record', $record);
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $record));
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), $first['declaration_id'], 2, 'correct', $this->value(), $correct);
    }

    public function test_original_qualification_revocation_denies_new_operations_and_existing_command_replay(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        $command = (string) Str::uuid();
        $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $this->value(), $record);
        BusinessAuthorityFixture::revokeQualification($f['eligibility']['basis_qualification'], $f['user']->id);
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $this->value(), $record));
        $this->denied(fn () => $f['evidence']->read($f['request'], $f['business'], $read));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }

    public function test_foreign_business_actor_and_synthetic_non_original_root_cannot_enroll_or_read(): void
    {
        $f = $this->fixture();
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        $other = app(BusinessScope::class)->create($f['request'], (string) Str::uuid(), 'Other private business', null);
        $this->denied(fn () => $f['evidence']->read($f['request'], $other['business_public_id'], $read));
        $bob = User::factory()->create();
        $request = $this->requestFor($bob);
        $this->denied(fn () => $f['evidence']->read($request, $f['business'], $read));
        $bid = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $f['business'])->value('id');
        $fake = BusinessAuthorityFixture::root($bid, $bob->id, 'business.context.read');
        $this->denied(fn () => $f['evidence']->enroll($request, $f['business'], (string) Str::uuid(), 1, true, $this->selection($fake->id)));
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('inquiry_evidence_enrollments')->count());
    }

    public function test_no_assurance_outcome_pii_or_unbounded_payload_enters_through_sql_validation(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $variants = [];
        $v = $this->value();
        $v['verified'] = true;
        $variants[] = $v;
        $v = $this->value();
        $v['customer_phone'] = '+966500000000';
        $variants[] = $v;
        $v = $this->value();
        $v['revenue'] = '10000';
        $variants[] = $v;
        $v = $this->value();
        $v['inquiries']['count'] = -1;
        $variants[] = $v;
        $v = $this->value();
        $v['acceptance']['count'] = 101;
        $variants[] = $v;
        $v = $this->value();
        $v['cost']['cost_basis'] = null;
        $variants[] = $v;
        $v = $this->value();
        $v['cost']['precision'] = 1;
        $variants[] = $v;
        $v = $this->value();
        $v['observation']['as_of'] = '2020-01-03T00:00:00';
        $variants[] = $v;
        foreach ($variants as $value) {
            $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('inquiry_evidence_heads')->count());
    }

    public function test_collection_seek_pagination_preserves_read_authority_and_discovers_all_records(): void
    {
        $f = $this->fixture();
        $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
        $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
        for ($i = 0; $i < 26; $i++) {
            $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $record);
        }
        $first = $f['evidence']->read($f['request'], $f['business'], $read);
        $this->assertCount(25, $first['items']);
        $this->assertTrue($first['more']);
        $second = $f['evidence']->read($f['request'], $f['business'], $read, cursor: $first['next_declaration']);
        $this->assertCount(1, $second['items']);
        $this->assertFalse($second['more']);
        $this->assertCount(26, array_unique([...array_column($first['items'], 'declaration_id'), ...array_column($second['items'], 'declaration_id')]));
        $f['evidence']->revoke($f['request'], $f['business'], (string) Str::uuid(), 'business.inquiry_evidence.read', $read);
        $this->denied(fn () => $f['evidence']->read($f['request'], $f['business'], $read, cursor: $first['next_declaration']));
    }

    public function test_http_enroll_record_and_read_have_no_store_and_reject_forced_assurance(): void
    {
        $f = $this->fixture();
        $path = '/api/businesses/'.$f['business'].'/inquiry-evidence';
        $this->getJson($path.'/eligibility?access='.$f['draft']['grant_refs']['business.context.read'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $body = ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']), 'value' => $this->value()];
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.assurance', 'SOURCE_ASSERTED')->assertJsonPath('data.independently_verified', false);
        $this->getJson($path.'?access='.$f['enrolled']['grants']['business.inquiry_evidence.read'])->assertOk()->assertJsonPath('data.items.0.value.inquiries.count', 100);
        $body['command_id'] = (string) Str::uuid();
        $body['verified'] = true;
        $this->postJson($path, $body)->assertUnprocessable();
    }

    public function test_http_empty_permission_map_is_an_object_not_a_list(): void
    {
        $f = $this->fixture();
        foreach ($f['enrolled']['grants'] as $action => $grant) {
            $f['evidence']->revoke($f['request'], $f['business'], (string) Str::uuid(), $action, $this->selection($grant));
        }
        $response = $this->getJson('/api/businesses/'.$f['business'].'/inquiry-evidence/eligibility?access='.$f['draft']['grant_refs']['business.context.read'])->assertOk();
        $map = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR)->data->grants;
        $this->assertInstanceOf(\stdClass::class, $map);
        $this->assertSame([], get_object_vars($map));
    }

    public function test_runtime_and_authority_roles_cannot_directly_read_or_write_private_tables(): void
    {
        $f = $this->fixture();
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            $this->denied(fn () => DB::connection($connection)->table('inquiry_evidence_revisions')->get());
        }
        $this->denied(fn () => DB::table('inquiry_evidence_heads')->insert(['declaration_id' => (string) Str::uuid7(), 'business_id' => 1, 'issuer_user_id' => $f['user']->id, 'revision' => 1, 'created_at' => now()]));
    }
}
