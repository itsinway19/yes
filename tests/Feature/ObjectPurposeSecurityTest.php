<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Models\User;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ObjectPurposeTestCase;

class ObjectPurposeSecurityTest extends ObjectPurposeTestCase
{
    public function test_optional_intent_reference_reads_exact_context_revision_after_other_context_change(): void
    {
        $value = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Completed bookings', 'clarification' => null, 'count_unit' => null],
            'quantity' => null, 'horizon' => null];
        $this->context(fn ($r, $w) => app(BusinessContext::class)->replaceCurrentIntent($r, $w, $value,
            ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true], 0), true);
        $this->context(fn ($r, $w) => app(BusinessContext::class)->replaceTargetMarkets($r, $w, ['SA'], 1), true);
        $command = self::command();
        $command['declaration']['intent'] = ['version' => 2, 'value' => $value, 'statement' => 'Explicit relationship to the retained business-wide booking intent'];
        $command['confirmations']['intent'] = true;
        $this->assertSame(1, $this->write($command)['version']);
        $workspace = DB::connection('pgsql_migrations')->table('workspaces')->find($this->workspace->id);
        $this->assertSame(2, $workspace->business_context_version);
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('business_current_intent_events')->count());
    }

    public function test_fresh_owner_confirmation_admits_exact_finite_scope_without_context_mutation(): void
    {
        $first = $this->write(self::command());
        $this->assertSame(1, $first['version']);
        $this->assertTrue(Str::isUuid($first['purpose_id']));
        $this->assertTrue(Str::isUuid($first['revision_id']));
        $row = DB::connection('pgsql_migrations')->table('object_purpose_revisions')->first();
        $this->assertSame(auth()->id(), $row->actor_user_id);
        $this->assertSame('Introduce the business before launch.', json_decode($row->snapshot, true)['meaning']);
        $actual = DB::connection('pgsql_migrations')->table('workspaces')->find($this->workspace->id);
        $this->assertSame(0, $actual->business_context_version);
        $this->assertNull($actual->current_intent);
    }

    public function test_identical_replay_changed_payload_and_stale_head(): void
    {
        $command = self::command();
        $first = $this->write($command);
        $this->assertSame($first, $this->write($command));
        $changed = $command;
        $changed['declaration']['meaning'] = 'Changed';
        $this->denied($changed, '40001');
        $replace = [...self::command(), 'purpose_id' => $first['purpose_id'], 'operation' => 'replace', 'supersedes_revision_id' => $first['revision_id']];
        $replace['confirmations']['transition'] = true;
        $this->denied($replace, '40001');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('object_purpose_revisions')->count());
    }

    public function test_false_and_nonboolean_confirmation_leave_no_fact(): void
    {
        foreach ([false, 'true', 1, null] as $value) {
            $command = self::command();
            $command['confirmations']['authority'] = $value;
            $this->denied($command, '22023');
        }
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('object_purposes')->count());
    }

    public function test_historical_dtm_is_exact_and_supported_while_current_claim_requires_revalidation(): void
    {
        $this->context(fn ($r, $w) => app(BusinessContext::class)->replaceTargetMarkets($r, $w, ['SA', 'AE'], 0), true);
        $this->context(fn ($r, $w) => app(BusinessContext::class)->replaceTargetMarkets($r, $w, ['KW'], 1), true);
        $command = self::command();
        $command['declaration']['dtm'] = ['mode' => 'current_alignment', 'version' => 1, 'countries' => ['AE', 'SA'], 'subset' => ['SA'], 'statement' => 'Introduces the business in these declared markets'];
        $command['confirmations']['dtm'] = true;
        $this->denied($command, '40001');
        $command['declaration']['dtm']['mode'] = 'historical_attestation';
        $this->denied($command, '22023');
        $command['confirmations']['historical'] = true;
        $command['declaration']['provenance']['historical_support'] = 'Owner attests the preserved earlier markets and statement';
        $first = $this->write($command);
        $this->assertSame(1, $first['version']);
        $command['command_id'] = (string) Str::uuid();
        $command['declaration']['dtm']['countries'] = ['KW'];
        $this->denied($command, '22023');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('object_purpose_revisions')->count());
    }

    public function test_nonowner_and_forged_read_context_cannot_read_or_admit(): void
    {
        $this->write(self::command());
        $member = User::factory()->create();
        $owner = DB::connection('pgsql_migrations');
        $membership = (array) $owner->table('workspace_memberships')->where('workspace_id', $this->workspace->id)->first();
        unset($membership['id']);
        $membership['user_id'] = $member->id;
        $membership['role'] = 'member';
        $owner->table('workspace_memberships')->insert($membership);
        $grant = (array) $owner->table('security_action_grants')->where('workspace_id', $this->workspace->id)->where('action', 'workspace.view')->first();
        unset($grant['id']);
        $grant['subject_user_id'] = $member->id;
        $grant['subject_generation'] = $owner->table('principal_lifecycles')->where('user_id', $member->id)->value('generation');
        $owner->table('security_action_grants')->insert($grant);
        $this->actingAs($member);
        $this->denied(self::command(), '42501');
        $this->expectException(QueryException::class);
        $this->context(function ($r, $w): void {
            DB::selectOne("SELECT set_config('app.object_purpose_session',?,true),set_config('app.object_purpose_signature','forged',true)", [SessionAuthority::sessionKey($r)]);
            $this->assertSame(0, DB::table('object_purpose_revisions')->count());
            try {
                DB::scalar('SELECT builderad_object_purpose_read_begin(?,?)', [$w->id, SessionAuthority::sessionKey($r)]);
                $this->fail('Member read admitted');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
                throw $error;
            }
        });
    }

    public function test_retained_journal_and_receipts_are_immutable(): void
    {
        $this->write(self::command());
        foreach (['object_purpose_revisions', 'object_purpose_command_receipts'] as $table) {
            foreach (['UPDATE '.$table.' SET workspace_id=workspace_id', 'DELETE FROM '.$table] as $sql) {
                try {
                    DB::connection('pgsql_migrations')->statement($sql);
                    $this->fail('Immutable history changed');
                } catch (QueryException $error) {
                    $this->assertSame('23514', $error->getCode());
                }
            }
        }
    }
}
