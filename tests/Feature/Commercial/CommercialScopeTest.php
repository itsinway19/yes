<?php

namespace Tests\Feature\Commercial;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\CommercialAuditCustody;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CommercialScopeTest extends TestCase
{
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->staff();
        $this->actingAsInternal($this->operator);
        $this->withCredentials();
    }

    public function test_generic_saudi_and_jordan_configuration_assignment_and_immutable_history(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR', false);
        $jo = $this->market('Jordan', 'JOD', false);
        $incomplete = $this->id();
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $incomplete])->assertOk();
        $this->postCommand('assignability', ['market_id' => $sa['market'], 'configuration_id' => $incomplete, 'expected_revision' => 1])->assertUnprocessable();
        $this->assertSqlDenied(fn () => $this->owner()->table('commercial_market_assignability')
            ->where('market_id', $sa['market'])->update(['configuration_id' => $incomplete]), '23514');
        $first = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', $first)->assertUnprocessable();
        $this->enable($sa, 1);
        $this->postJson('/internal/commercial/registrations', $first)->assertOk()->assertJsonPath('configuration_id', $sa['configuration'])
            ->assertJsonPath('denomination_code', 'SAR')->assertJsonPath('scope_id', $first['scope_id']);
        $before = $this->owner()->table('commercial_market_assignments')->where('scope_id', $first['scope_id'])->first();
        $this->assertSame($sa['catalog'], $before->catalog_reference_id);
        $second = $this->registration($jo);
        $this->postJson('/internal/commercial/registrations', $second)->assertUnprocessable();
        $this->enable($jo, 1);
        $wrongSaudi = [...$first, 'command_id' => $this->id(), 'catalog_reference_id' => $jo['catalog']];
        $this->postJson('/internal/commercial/registrations', $wrongSaudi)->assertUnprocessable();
        $this->postJson('/internal/commercial/registrations', [...$second, 'catalog_reference_id' => $sa['catalog']])->assertUnprocessable();
        $this->postJson('/internal/commercial/registrations', $second)->assertOk()->assertJsonPath('market_id', $jo['market']);
        $this->postCommand('configurations', ['market_id' => $jo['market'], 'configuration_id' => $this->id(),
            'catalog_reference_id' => $sa['catalog'], 'denomination_code' => 'SAR'])->assertUnprocessable();
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $this->id(),
            'catalog_reference_id' => $jo['catalog'], 'denomination_code' => 'JOD'])->assertUnprocessable();

        $newCatalog = $this->id();
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $sa['market'], null, $newCatalog);
        $this->postCommand('catalog-references', ['market_id' => $sa['market'], 'catalog_reference_id' => $newCatalog,
            'catalog_identity' => $this->id(), 'catalog_version' => '2', 'approval_reference' => 'synthetic:sa:v2',
            'source_sha256' => hash('sha256', 'synthetic catalog 2')])->assertOk();
        $newConfiguration = $this->id();
        // Denomination is explicit independent configuration: the Saudi identity may carry JOD in synthetic proof.
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $newConfiguration,
            'catalog_reference_id' => $newCatalog, 'denomination_code' => 'JOD'])->assertOk()->assertJsonPath('version', 3);
        $this->enable([...$sa, 'configuration' => $newConfiguration], 2);
        $third = $this->registration([...$sa, 'configuration' => $newConfiguration, 'catalog' => $newCatalog, 'denomination' => 'JOD']);
        $this->postJson('/internal/commercial/registrations', $third)->assertOk()->assertJsonPath('market_id', $sa['market'])->assertJsonPath('denomination_code', 'JOD');
        $this->assertEquals($before, $this->owner()->table('commercial_market_assignments')->where('scope_id', $first['scope_id'])->first());
        $this->postCommand('assignability', ['market_id' => $sa['market'], 'configuration_id' => null, 'expected_revision' => 3])->assertOk();
        $fourth = $this->registration([...$sa, 'configuration' => $newConfiguration, 'catalog' => $newCatalog, 'denomination' => 'JOD']);
        $this->postJson('/internal/commercial/registrations', $fourth)->assertUnprocessable();
        $this->assertEquals($before, $this->owner()->table('commercial_market_assignments')->where('scope_id', $first['scope_id'])->first());
        foreach (['commercial_market_configuration_versions', 'commercial_market_assignments', 'commercial_command_results'] as $table) {
            $this->assertSqlDenied(fn () => $this->owner()->table($table)->limit(1)->update($table === 'commercial_market_configuration_versions'
                ? ['denomination_code' => 'USD'] : ($table === 'commercial_market_assignments' ? ['denomination_code' => 'USD'] : ['reason_code' => 'rewrite'])), '23514');
        }
        $this->get('/internal/commercial')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Internal/Commercial')->has('markets', 2)->has('configurations', 4));
    }

    public function test_non_ecommerce_service_reference_uses_the_same_commercial_foundation(): void
    {
        $market = $this->market('Synthetic service reference market', 'SAR');
        [$beneficiary, $workspace] = $this->workspace();
        $this->owner()->table('workspaces')->where('public_id', $workspace)->update(['name' => 'Synthetic offline consultation business']);
        $input = $this->registration($market, $workspace);
        // This exact shared request has no product, SKU, cart, checkout, order or payment fact.
        $this->postJson('/internal/commercial/registrations', $input)->assertOk()
            ->assertJsonPath('scope_id', $input['scope_id'])->assertJsonPath('agreement_state', 'UNKNOWN')
            ->assertJsonPath('amount_state', 'UNKNOWN');
        $workspaceId = $this->owner()->table('workspaces')->where('public_id', $workspace)->value('id');
        $this->grant($beneficiary, 'ReadCommercialContextAdvertiser', $input['scope_id'], $workspaceId);
        $this->actingAs($beneficiary)->get('/workspaces/'.$workspace)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('commercial.state', 'CONTEXT_RECORDED')->where('commercial.context.scope_id', $input['scope_id'])
            ->where('commercial.agreement_state', 'UNKNOWN')->where('commercial.amount_state', 'UNKNOWN'));
    }

    public function test_registration_retry_receipt_conflict_and_competing_initial_association(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $first = $this->postJson('/internal/commercial/registrations', $input)->assertOk()->json();
        $this->postJson('/internal/commercial/registrations', array_reverse($input, true))->assertOk()->assertExactJson($first);
        $this->postJson('/internal/commercial/registrations', [...$input, 'reason_code' => 'changed'])->assertConflict();
        $this->postJson('/internal/commercial/registrations', [...$input, 'command_id' => $this->id()])->assertConflict();
        $competing = $this->registration($sa, $input['workspace_public_id']);
        $this->postJson('/internal/commercial/registrations', $competing)->assertConflict();
        $this->getJson('/internal/commercial/receipts/'.$input['command_id'])->assertOk()->assertJsonPath('target_id', $input['scope_id']);
        $this->assertSame(1, $this->owner()->table('commercial_scopes')->count());
        $this->assertSame(1, $this->owner()->table('commercial_beneficiary_bindings')->count());
        $this->assertSame(1, $this->owner()->table('commercial_market_assignments')->count());
        $this->assertSame(1, $this->owner()->table('commercial_audit_obligations')->where('command_id', $input['command_id'])->count());
    }

    public function test_advertiser_membership_is_not_commercial_read_and_workspace_facts_never_select_market(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $workspace = $this->owner()->table('workspaces')->where('public_id', $input['workspace_public_id'])->first();
        $beneficiary = User::on('pgsql_authority')->findOrFail($this->owner()->table('workspace_ownerships')->where('workspace_id', $workspace->id)->value('responsible_owner_user_id'));
        $this->actingAs($beneficiary)->get('/workspaces/'.$workspace->public_id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('commercial.state', 'UNAVAILABLE')->where('commercial.context', null));
        $this->grant($beneficiary, 'ReadCommercialContextAdvertiser', $input['scope_id'], $workspace->id);
        $this->owner()->table('workspaces')->where('id', $workspace->id)->update(['currency_code' => 'USD', 'timezone' => 'Asia/Tokyo']);
        $this->withHeader('Accept-Language', 'en')->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/workspaces/'.$workspace->public_id.'?locale=en')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('commercial.context.market_id', $sa['market'])->where('commercial.context.denomination_code', 'SAR')
            ->where('commercial.context.configuration_id', $sa['configuration'])->where('commercial.amount_state', 'UNKNOWN')
            ->missing('commercial.context.actor_user_id')->missing('commercial.context.audit'));
        [$other, $otherWorkspace] = $this->workspace();
        $this->actingAs($other)->get('/workspaces/'.$workspace->public_id)->assertForbidden();
        $this->get('/workspaces/'.$otherWorkspace)->assertOk()->assertInertia(fn (Assert $page) => $page->where('commercial.context', null));
        $this->grant($other, 'ReadCommercialContextAdvertiser', $input['scope_id'], $workspace->id);
        // Exact grant without membership still cannot reveal the other beneficiary.
        DB::transaction(function () use ($other): void {
            app(PrincipalContext::class)->activate($other);
            $this->assertSame(0, DB::table('commercial_market_assignments')->count());
            $this->assertSame(0, DB::table('commercial_audit_obligations')->count());
        });
    }

    public static function invalidAuthority(): array
    {
        return array_map(fn ($kind) => [$kind], ['missing', 'wrong_target', 'revoked', 'expired', 'stale_generation', 'stale_session', 'fresh_auth']);
    }

    #[DataProvider('invalidAuthority')]
    public function test_registration_denies_invalid_authority_without_partial_truth(string $kind): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $grants = $this->owner()->table('security_action_grants')->where('subject_user_id', $this->operator->id)
            ->where('action', 'RegisterCommercialScope')->where('commercial_target_id', $input['scope_id']);
        match ($kind) {
            'missing' => $grants->delete(),
            'wrong_target' => $grants->update(['commercial_target_id' => $this->id()]),
            'revoked' => $grants->update(['status' => 'revoked', 'revoked_at' => now()]),
            'expired' => $grants->update(['expires_at' => now()->subMinute()]),
            'stale_generation' => $grants->update(['subject_generation' => 99]),
            'stale_session' => $this->owner()->table('security_sessions')->where('user_id', $this->operator->id)->update(['revoked_at' => now()]),
            'fresh_auth' => $this->owner()->table('security_sessions')->where('user_id', $this->operator->id)->update(['authenticated_at' => now()->subMinutes(6)]),
        };
        $response = $this->postJson('/internal/commercial/registrations', $input);
        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(0, $this->owner()->table('commercial_scopes')->count());
        $this->assertSame(0, $this->owner()->table('commercial_command_results')->where('command_id', $input['command_id'])->count());
    }

    public function test_unauthorized_configuration_assignability_and_registry_target_isolation(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $jo = $this->market('Jordan', 'JOD', false);
        $ungranted = $this->staff();
        $this->actingAsInternal($ungranted);
        $this->postCommand('markets', ['market_id' => $this->id(), 'label' => 'Synthetic future market'])->assertForbidden();
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $this->id()])->assertForbidden();
        $this->postCommand('assignability', ['market_id' => $jo['market'], 'configuration_id' => $jo['configuration'], 'expected_revision' => 1])->assertForbidden();
        $this->postCommand('assignability', ['market_id' => $sa['market'], 'configuration_id' => null, 'expected_revision' => 2])->assertForbidden();
        $this->grant($ungranted, 'ReadCommercialMarketRegistry', $sa['market']);
        $this->get('/internal/commercial')->assertOk()->assertInertia(fn (Assert $page) => $page->has('markets', 1)->where('markets.0.id', $sa['market'])->has('configurations', 1));
        $this->assertSame($sa['configuration'], $this->owner()->table('commercial_market_assignability')->where('market_id', $sa['market'])->value('configuration_id'));
    }

    public function test_a003_exceptional_mandate_and_bounded_operational_grants_do_not_inherit_admin(): void
    {
        $executor = $this->operator;
        $administrator = $this->staff();
        $target = $this->staff();
        $marketId = $this->id();
        $mandateId = $this->id();
        $mandate = ['executor' => $executor->public_id, 'grantee' => $administrator->public_id,
            'action' => 'assign', 'target_id' => $marketId, 'executor_generation' => 1, 'grantee_generation' => 1,
            'expires_at' => now()->addHour()->toIso8601String(), 'approval_reference' => 'synthetic_owner_approval'];
        $this->postJson('/internal/commercial/owner-mandate', ['mandate_id' => $mandateId])->assertForbidden();
        config()->set('commercial_authority.owner_mandates.'.$mandateId, $mandate);
        $grant = $this->postJson('/internal/commercial/owner-mandate', ['mandate_id' => $mandateId])->assertOk()->json('grant_id');
        $this->postJson('/internal/commercial/owner-mandate', ['mandate_id' => $mandateId])->assertOk()->assertJsonPath('grant_id', $grant);
        $selfMandate = $this->id();
        config()->set('commercial_authority.owner_mandates.'.$selfMandate, [...$mandate, 'grantee' => $executor->public_id]);
        $this->postJson('/internal/commercial/owner-mandate', ['mandate_id' => $selfMandate])->assertForbidden();
        $this->actingAsInternal($administrator);
        $body = ['grantee' => $target->public_id, 'effect' => 'ConfigureCommercialMarket', 'target_id' => $marketId, 'reason_code' => 'synthetic_grant'];
        $operationalId = $this->postJson('/internal/commercial/grants', $body)->assertOk()->json('grant_id');
        foreach (['ReadCommercialMarketRegistry', 'ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState', 'RetryOrRecoverExistingAuditDelivery'] as $effect) {
            $this->postJson('/internal/commercial/grants', [...$body, 'effect' => $effect])->assertOk();
        }
        $this->postJson('/internal/commercial/grants', [...$body, 'target_id' => $this->id()])->assertForbidden();
        $this->postJson('/internal/commercial/grants', [...$body, 'grantee' => $administrator->public_id])->assertForbidden();
        $this->postJson('/internal/commercial/grants', [...$body, 'effect' => 'SA01CommercialGrantAdministrator'])->assertForbidden();
        $this->postCommand('markets', ['market_id' => $marketId, 'label' => 'Not inherited'])->assertForbidden();
        $this->actingAsInternal($target);
        $this->postCommand('markets', ['market_id' => $marketId, 'label' => 'Synthetic governed market'])->assertOk();
        $marketCommand = $this->owner()->table('commercial_command_results')->where('target_id', $marketId)->value('command_id');
        $this->getJson('/internal/commercial/receipts/'.$marketCommand)->assertOk();
        $marketObligation = $this->owner()->table('commercial_audit_obligations')->where('command_id', $marketCommand)->value('id');
        $this->postJson('/internal/commercial/audit/'.$marketObligation.'/recover')->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->actingAsInternal($administrator)->deleteJson('/internal/commercial/grants/'.$operationalId)->assertNoContent();
        $this->actingAsInternal($target)->postCommand('configurations', ['market_id' => $marketId, 'configuration_id' => $this->id()])->assertForbidden();
        $this->actingAsInternal($executor);
        $revoke = $this->id();
        config()->set('commercial_authority.owner_mandates.'.$revoke, [...$mandate, 'action' => 'revoke', 'grant_id' => $grant]);
        $this->postJson('/internal/commercial/owner-mandate', ['mandate_id' => $revoke])->assertOk();
        $this->actingAsInternal($administrator)->postJson('/internal/commercial/grants', $body)->assertForbidden();
        $this->assertSame(2, $this->owner()->table('security_commercial_mandate_executions')->count());
        $this->assertGreaterThanOrEqual(4, $this->owner()->table('security_audit_events')->where('event', 'like', 'staff_grant_%')->count());
    }

    public function test_required_local_audit_failure_rolls_back_fact_binding_assignment_and_receipt(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->owner()->unprepared("CREATE OR REPLACE FUNCTION sa01_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic fault'; END \$\$; CREATE TRIGGER sa01_test_fault BEFORE INSERT ON commercial_audit_obligations FOR EACH ROW EXECUTE FUNCTION sa01_test_fail();");
        try {
            $this->postJson('/internal/commercial/registrations', $input)->assertStatus(500);
        } finally {
            $this->owner()->statement('DROP TRIGGER sa01_test_fault ON commercial_audit_obligations');
        }
        foreach (['commercial_scopes', 'commercial_beneficiary_bindings', 'commercial_market_assignments'] as $table) {
            $this->assertSame(0, $this->owner()->table($table)->count());
        }
        $this->assertSame(0, $this->owner()->table('commercial_command_results')->where('command_id', $input['command_id'])->count());
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
    }

    public function test_custody_failure_lost_acknowledgment_idempotent_recovery_and_fingerprint_conflict(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $origin = $this->owner()->table('commercial_audit_obligations')->where('command_id', $input['command_id'])->first();
        $this->assertSame('pending', $this->owner()->table('commercial_audit_delivery')->where('obligation_id', $origin->id)->value('state'));
        $this->owner()->unprepared("CREATE OR REPLACE FUNCTION sa01_test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic custody unavailable'; END \$\$; CREATE TRIGGER sa01_test_fault BEFORE INSERT ON security_commercial_audit_custody FOR EACH ROW EXECUTE FUNCTION sa01_test_fail();");
        try {
            $this->postJson('/internal/commercial/audit/'.$origin->id.'/recover')->assertOk()->assertJsonPath('state', 'failed');
        } finally {
            $this->owner()->statement('DROP TRIGGER sa01_test_fault ON security_commercial_audit_custody');
        }
        $this->assertSame(1, $this->owner()->table('commercial_scopes')->count());
        $ack = app(CommercialAuditCustody::class)->accept($origin->id, $origin->fingerprint, $origin->envelope);
        // Crash/lost acknowledgment: custody accepted independently, origin still failed until recovery.
        $this->assertSame('failed', $this->owner()->table('commercial_audit_delivery')->where('obligation_id', $origin->id)->value('state'));
        $this->postJson('/internal/commercial/audit/'.$origin->id.'/recover')->assertOk()->assertJsonPath('acknowledgment_id', $ack);
        $this->postJson('/internal/commercial/audit/'.$origin->id.'/recover')->assertOk()->assertJsonPath('acknowledgment_id', $ack);
        $this->assertSame(1, $this->owner()->table('security_commercial_audit_custody')->where('obligation_id', $origin->id)->count());
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $this->assertSame(1, $this->owner()->table('commercial_audit_obligations')->where('command_id', $input['command_id'])->count());
        $this->actingAsInternal($this->staff())->postJson('/internal/commercial/audit/'.$origin->id.'/recover')->assertForbidden();
        $this->expectException(RuntimeException::class);
        app(CommercialAuditCustody::class)->accept($origin->id, hash('sha256', 'different'), $origin->envelope);
    }

    public function test_database_roles_rls_and_raw_write_boundaries_are_actual(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $this->assertSame(0, DB::table('commercial_markets')->count());
        $this->assertSame(0, DB::table('commercial_scopes')->count());
        foreach (['commercial_markets', 'commercial_scopes', 'commercial_audit_obligations'] as $table) {
            $row = $this->owner()->selectOne('SELECT relrowsecurity,relforcerowsecurity,pg_get_userbyid(relowner) AS owner FROM pg_class WHERE relname=?', [$table]);
            $this->assertTrue($row->relrowsecurity);
            $this->assertTrue($row->relforcerowsecurity);
            $this->assertSame('builderad_owner', $row->owner);
            $this->assertSqlDenied(fn () => DB::statement('DELETE FROM '.$table), '42501');
            $this->assertSqlDenied(fn () => DB::connection('pgsql_authority')->statement('DELETE FROM '.$table), '42501');
        }
        $this->assertSqlDenied(fn () => DB::selectOne('SELECT builderad_sa01_finish(?,?,?,?,?,?,?::jsonb)',
            [$this->id(), 'ConfigureCommercialMarket', $sa['market'], null, str_repeat('a', 64), 'synthetic', '{}']), '42501');
        $this->assertSqlDenied(fn () => DB::selectOne('SELECT builderad_configure_commercial_market(?,?,?,?)',
            [$this->id(), $sa['market'], 'forged', 'synthetic']), '42501');
    }

    public function test_commit_first_authority_and_assignability_locks_preserve_fact_then_invalidation_denies_retry(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $contender = DB::build(config('database.connections.pgsql_authority'));
        $ownerContender = DB::build(config('database.connections.pgsql_migrations'));
        try {
            DB::transaction(function () use ($input, $sa, $contender, $ownerContender): void {
                app(PrincipalContext::class)->activate($this->operator);
                $result = json_decode(DB::scalar('SELECT builderad_register_commercial_scope(?,?,?,?,?,?,?,?)', [
                    $input['command_id'], $input['scope_id'], $input['workspace_public_id'], $input['market_id'],
                    $input['configuration_id'], $input['catalog_reference_id'], $input['denomination_code'], $input['reason_code'],
                ]), true);
                $this->assertSame($input['scope_id'], $result['scope_id']);
                $this->assertSqlDenied(fn () => $contender->transaction(function () use ($contender, $input): void {
                    $contender->statement("SET LOCAL lock_timeout = '200ms'");
                    $contender->table('security_action_grants')->where('commercial_target_id', $input['scope_id'])
                        ->where('action', 'RegisterCommercialScope')->update(['status' => 'revoked', 'revoked_at' => now()]);
                }), '55P03');
                $this->assertSqlDenied(fn () => $ownerContender->transaction(function () use ($ownerContender, $sa): void {
                    $ownerContender->statement("SET LOCAL lock_timeout = '200ms'");
                    $ownerContender->table('commercial_market_assignability')->where('market_id', $sa['market'])->update(['configuration_id' => null]);
                }), '55P03');
            });
            $contender->table('security_action_grants')->where('commercial_target_id', $input['scope_id'])
                ->where('action', 'RegisterCommercialScope')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $this->postJson('/internal/commercial/registrations', $input)->assertForbidden();
            $this->assertSame(1, $this->owner()->table('commercial_scopes')->count());
            $this->assertSame(1, $this->owner()->table('commercial_command_results')->where('command_id', $input['command_id'])->count());
            $this->assertSame(1, $this->owner()->table('commercial_audit_obligations')->where('command_id', $input['command_id'])->count());
        } finally {
            $contender->disconnect();
            $ownerContender->disconnect();
        }
    }

    public function test_unapproved_catalog_missing_reference_and_wrong_workspace_are_denied(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $reference = $this->id();
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $sa['market'], null, $reference);
        $this->postCommand('catalog-references', ['market_id' => $sa['market'], 'catalog_reference_id' => $reference,
            'catalog_identity' => $this->id(), 'catalog_version' => 'unapproved', 'source_sha256' => hash('sha256', 'unapproved')])->assertUnprocessable();
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $this->id(),
            'catalog_reference_id' => $reference, 'denomination_code' => 'SAR'])->assertUnprocessable();
        $input = $this->registration($sa);
        [, $otherWorkspace] = $this->workspace();
        $this->postJson('/internal/commercial/registrations', [...$input, 'workspace_public_id' => $otherWorkspace])->assertForbidden();
        $this->postJson('/internal/commercial/registrations', [...$input, 'denomination_code' => 'USD'])->assertUnprocessable();
        $this->assertSame(0, $this->owner()->table('commercial_scopes')->count());
    }

    public function test_internal_context_read_requires_its_own_exact_current_grant(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $reader = $this->staff();
        $this->actingAsInternal($reader)->getJson('/internal/commercial/contexts/'.$input['scope_id'])->assertForbidden();
        $workspace = $this->owner()->table('workspaces')->where('public_id', $input['workspace_public_id'])->value('id');
        $this->grant($reader, 'ReadCommercialContextInternal', $input['scope_id'], $workspace);
        $this->getJson('/internal/commercial/contexts/'.$input['scope_id'])->assertOk()
            ->assertJsonPath('context.configuration_id', $sa['configuration'])->assertJsonPath('context.workspace_public_id', $input['workspace_public_id']);
        $this->getJson('/internal/commercial/contexts/'.$this->id())->assertForbidden();
        $this->getJson('/internal/commercial/receipts/'.$input['command_id'])->assertForbidden();
        $this->owner()->table('security_action_grants')->where('subject_user_id', $reader->id)
            ->where('action', 'ReadCommercialContextInternal')->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/internal/commercial/contexts/'.$input['scope_id'])->assertForbidden();
    }

    public function test_r1_beneficiary_tuple_serializes_both_invalidation_orders(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $workspace = $this->owner()->table('workspaces')->where('public_id', $input['workspace_public_id'])->value('id');
        $ownerId = $this->owner()->table('workspace_ownerships')->where('workspace_id', $workspace)->value('responsible_owner_user_id');
        $other = DB::build(config('database.connections.pgsql_migrations'));
        try {
            DB::transaction(function () use ($input, $workspace, $ownerId, $other): void {
                app(PrincipalContext::class)->activate($this->operator);
                $this->registerSql($input);
                foreach ([['workspace_ownerships', 'workspace_id', $workspace, ['version' => 2]],
                    ['principal_lifecycles', 'user_id', $ownerId, ['status' => 'disabled', 'generation' => 2]],
                    ['users', 'id', $ownerId, ['email_verified_at' => null]],
                    ['workspace_memberships', 'workspace_id', $workspace, ['status' => 'revoked', 'revoked_at' => now()]]] as [$table, $key, $value, $change]) {
                    $this->assertSqlDenied(fn () => $other->transaction(function () use ($other, $table, $key, $value, $change): void {
                        $other->statement("SET LOCAL lock_timeout = '150ms'");
                        $other->table($table)->where($key, $value)->update($change);
                    }), '55P03');
                }
            });
            $this->assertSame(1, $this->owner()->table('commercial_scopes')->count());
            foreach (['ownership', 'lifecycle', 'identity', 'membership'] as $qualification) {
                $next = $this->registration($sa);
                $nextWorkspace = $this->owner()->table('workspaces')->where('public_id', $next['workspace_public_id'])->value('id');
                $nextOwner = $this->owner()->table('workspace_ownerships')->where('workspace_id', $nextWorkspace)->value('responsible_owner_user_id');
                match ($qualification) {
                    'ownership' => $other->table('workspace_ownerships')->where('workspace_id', $nextWorkspace)->update(['version' => 0]),
                    'lifecycle' => $other->table('principal_lifecycles')->where('user_id', $nextOwner)->update(['status' => 'disabled', 'generation' => 2]),
                    'identity' => $other->table('users')->where('id', $nextOwner)->update(['email_verified_at' => null]),
                    'membership' => $other->table('workspace_memberships')->where('workspace_id', $nextWorkspace)->update(['status' => 'revoked', 'revoked_at' => now()]),
                };
                $this->postJson('/internal/commercial/registrations', $next)->assertForbidden();
                $this->assertFalse($this->owner()->table('commercial_scopes')->where('id', $next['scope_id'])->exists());
            }
        } finally {
            $other->disconnect();
        }
    }

    public function test_r2_grant_expiry_during_real_assignability_contention_denies_material_effect(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $holder = $this->blockingHolder('SELECT 1 FROM commercial_market_assignability WHERE market_id = ? FOR UPDATE', [$sa['market']]);
        try {
            $this->owner()->table('security_action_grants')->where('commercial_target_id', $input['scope_id'])
                ->where('action', 'RegisterCommercialScope')->update(['expires_at' => DB::raw("clock_timestamp() + interval '2 seconds'")]);
            $started = microtime(true);
            $this->postJson('/internal/commercial/registrations', $input)->assertForbidden();
            $this->assertGreaterThan(0.4, microtime(true) - $started);
            $this->assertFalse($this->owner()->table('commercial_scopes')->where('id', $input['scope_id'])->exists());
            $this->assertFalse($this->owner()->table('commercial_command_results')->where('command_id', $input['command_id'])->exists());
        } finally {
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
        }
    }

    public function test_r2_commercial_admin_expiry_after_grantee_contention_rolls_back_issuance(): void
    {
        $target = $this->staff();
        $market = $this->id();
        $this->grant($this->operator, 'SA01CommercialGrantAdministrator', $market);
        $holder = $this->blockingHolder('SELECT 1 FROM principal_lifecycles WHERE user_id = ? FOR UPDATE', [$target->id], 'pgsql_authority');
        try {
            $this->owner()->table('security_action_grants')->where('subject_user_id', $this->operator->id)
                ->where('action', 'SA01CommercialGrantAdministrator')->update(['expires_at' => DB::raw("clock_timestamp() + interval '2 seconds'")]);
            $started = microtime(true);
            $this->postJson('/internal/commercial/grants', ['grantee' => $target->public_id, 'effect' => 'ConfigureCommercialMarket',
                'target_id' => $market, 'reason_code' => 'synthetic_expiry'])->assertForbidden();
            $this->assertGreaterThan(0.4, microtime(true) - $started);
            $this->assertFalse($this->owner()->table('security_action_grants')->where('subject_user_id', $target->id)->where('commercial_target_id', $market)->exists());
        } finally {
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
        }
    }

    public function test_r3_catalog_requires_separate_producer_commit_in_both_transactions(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $reference = $this->id();
        $configuration = $this->id();
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $sa['market'], null, $reference);
        config()->set('database.connections.sa01_consumer', config('database.connections.pgsql'));
        $consumer = DB::connection('sa01_consumer');
        try {
            DB::transaction(function () use ($sa, $reference, $configuration, $consumer): void {
                app(PrincipalContext::class)->activate($this->operator);
                DB::scalar('SELECT builderad_record_commercial_catalog(?,?,?,?,?,?,?,?)', [$this->id(), $sa['market'], $reference,
                    $this->id(), '2', 'synthetic:separate:owner', hash('sha256', 'separate producer'), 'synthetic_reference']);
                $args = [$this->id(), $sa['market'], $configuration, $reference, 'SAR', 'synthetic_configuration'];
                // Attempts to remove the producer marker cannot enable consumption.
                $key = DB::scalar("SELECT hashtextextended('sa01.catalog.producer.'||?::text,0)", [$reference]);
                $this->assertFalse(DB::scalar('SELECT pg_advisory_unlock_shared(?::bigint)', [$key]));
                $this->assertFalse(DB::scalar('SELECT pg_advisory_unlock(?::bigint)', [$key]));
                DB::statement('SELECT pg_advisory_unlock_all()');
                DB::statement('DISCARD TEMP');
                $this->assertSqlDenied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_create_market_configuration(?,?,?,?,?,?)', $args)), '23514');
                DB::setDefaultConnection('sa01_consumer');
                try {
                    $this->assertSqlDenied(fn () => $consumer->transaction(function () use ($args): void {
                        app(PrincipalContext::class)->activate($this->operator);
                        DB::scalar('SELECT builderad_create_market_configuration(?,?,?,?,?,?)', $args);
                    }), '23514');
                } finally {
                    DB::setDefaultConnection('pgsql');
                }
            });
            $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $configuration,
                'catalog_reference_id' => $reference, 'denomination_code' => 'SAR'])->assertOk();
            $this->enable([...$sa, 'configuration' => $configuration], 2);
            $input = $this->registration([...$sa, 'configuration' => $configuration, 'catalog' => $reference]);
            $this->postJson('/internal/commercial/registrations', $input)->assertOk()->assertJsonPath('catalog_reference_id', $reference);
        } finally {
            DB::setDefaultConnection('pgsql');
            $consumer->disconnect();
        }
    }

    public function test_n1_same_top_level_producer_transaction_cannot_consume(): void
    {
        $sa = $this->market('Synthetic top-level market', 'SAR');
        $reference = $this->id();
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $sa['market'], null, $reference);
        $this->assertSqlDenied(fn () => DB::transaction(function () use ($sa, $reference): void {
            app(PrincipalContext::class)->activate($this->operator);
            DB::scalar('SELECT builderad_record_commercial_catalog(?,?,?,?,?,?,?,?)', [
                $this->id(), $sa['market'], $reference, $this->id(), '2', 'synthetic:top-level', hash('sha256', 'top-level producer'), 'synthetic_reference']);
            DB::scalar('SELECT builderad_create_market_configuration(?,?,?,?,?,?)', [
                $this->id(), $sa['market'], $this->id(), $reference, 'SAR', 'synthetic_configuration']);
        }), '23514');
        $this->assertFalse($this->owner()->table('commercial_catalog_references')->where('id', $reference)->exists());
    }

    public function test_n1_savepoint_producer_release_cannot_upgrade_commit_condition(): void
    {
        $sa = $this->market('Synthetic savepoint market', 'SAR');
        $reference = $this->id();
        $configuration = $this->id();
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $sa['market'], null, $reference);
        DB::transaction(function () use ($sa, $reference, $configuration): void {
            app(PrincipalContext::class)->activate($this->operator);
            DB::transaction(fn () => DB::scalar('SELECT builderad_record_commercial_catalog(?,?,?,?,?,?,?,?)', [
                $this->id(), $sa['market'], $reference, $this->id(), '2', 'synthetic:savepoint', hash('sha256', 'savepoint producer'), 'synthetic_reference']));
            // Released producer savepoint is not a top-level commit. Neither making
            // constraints immediate nor nesting the consumer can clear the marker.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->assertSqlDenied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_create_market_configuration(?,?,?,?,?,?)', [
                $this->id(), $sa['market'], $configuration, $reference, 'SAR', 'synthetic_configuration'])), '23514');
            $this->assertSqlDenied(fn () => DB::transaction(fn () => DB::statement('UPDATE commercial_catalog_references SET approval_reference = ? WHERE id = ?', ['forged:approval', $reference])), '42501');
            $this->assertSqlDenied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_sa01_require_committed_catalog(?,?)', [$reference, $sa['market']])), '42501');
        });
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $configuration,
            'catalog_reference_id' => $reference, 'denomination_code' => 'SAR'])->assertOk();
    }

    public function test_n1_different_history_durability(): void
    {
        $this->assertFalse($this->owner()->table('information_schema.columns')->where('table_name', 'commercial_catalog_references')
            ->where('column_name', 'producer_transaction')->exists());
        $phase = getenv('SA01_N1_DURABILITY_PHASE');
        if (! $phase) {
            return; // The real two-installation proof is run explicitly before the full cone.
        }
        $file = getenv('SA01_N1_PRESERVATION_FILE');
        $this->assertNotEmpty($file);
        if ($phase === 'source') {
            $sa = $this->market('Synthetic preserved commercial reference', 'SAR', false);
            $data = ['market' => (array) $this->owner()->table('commercial_markets')->where('id', $sa['market'])->first(),
                'catalog' => (array) $this->owner()->table('commercial_catalog_references')->where('id', $sa['catalog'])->first(),
                'assignability' => (array) $this->owner()->table('commercial_market_assignability')->where('market_id', $sa['market'])->first()];
            // Diagnostic XID is outside the preserved commercial data, never imported.
            $envelope = ['data' => $data, 'sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'source_xid' => (int) $this->owner()->scalar('SELECT pg_current_xact_id()::text'),
                'source_run' => getenv('BUILDERAD_TEST_ISOLATION_TOKEN')];
            $this->assertNotFalse(file_put_contents($file, json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
            fwrite(STDOUT, "\nN1_SOURCE_COMMITTED_EXPORT=PASS; SOURCE_XID={$envelope['source_xid']}; SOURCE_RUN={$envelope['source_run']}\n");

            return;
        }
        $this->assertSame('target', $phase);
        $envelope = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $data = $envelope['data'];
        $this->assertSame($envelope['sha256'], hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));
        $this->assertNotSame($envelope['source_run'], getenv('BUILDERAD_TEST_ISOLATION_TOKEN'));
        $targetXid = (int) $this->owner()->scalar('SELECT pg_current_xact_id()::text');
        $this->assertLessThan($envelope['source_xid'], $targetXid, 'Target must have genuinely different, earlier transaction history.');
        // Trusted bounded logical reseed into a fresh independently initialized
        // installation, not a runtime writer or a general restoration subsystem.
        $this->owner()->transaction(function () use ($data): void {
            $this->owner()->table('commercial_markets')->insert($data['market']);
            $this->owner()->table('commercial_catalog_references')->insert($data['catalog']);
            $this->owner()->table('commercial_market_assignability')->insert($data['assignability']);
        });
        $this->assertEquals($data['catalog'], (array) $this->owner()->table('commercial_catalog_references')->where('id', $data['catalog']['id'])->first());
        $sa = ['market' => $data['market']['id'], 'catalog' => $data['catalog']['id'], 'configuration' => $this->id(), 'denomination' => 'SAR'];
        foreach (['ConfigureCommercialMarket', 'EnableCommercialMarket'] as $effect) {
            $this->grant($this->operator, $effect, $sa['market']);
        }
        $this->postCommand('configurations', ['market_id' => $sa['market'], 'configuration_id' => $sa['configuration'],
            'catalog_reference_id' => $sa['catalog'], 'denomination_code' => 'SAR'])->assertOk();
        $this->enable($sa, 1);
        $this->postJson('/internal/commercial/registrations', $this->registration($sa))->assertOk()
            ->assertJsonPath('catalog_reference_id', $sa['catalog'])->assertJsonPath('catalog_identity', $data['catalog']['catalog_identity'])
            ->assertJsonPath('catalog_version', $data['catalog']['catalog_version']);
        fwrite(STDOUT, "\nN1_DIFFERENT_HISTORY_DURABILITY=PASS; SOURCE_XID={$envelope['source_xid']}; TARGET_XID=$targetXid; PRESERVED_SHA256={$envelope['sha256']}\n");
    }

    public function test_r2_configuration_expiry_after_wait_rolls_back_fact_and_obligation(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $configuration = $this->id();
        $command = $this->id();
        $holder = $this->blockingHolder('SELECT 1 FROM commercial_market_assignability WHERE market_id = ? FOR UPDATE', [$sa['market']]);
        try {
            $this->owner()->table('security_action_grants')->where('commercial_target_id', $sa['market'])
                ->where('action', 'ConfigureCommercialMarket')->update(['expires_at' => DB::raw("clock_timestamp() + interval '2 seconds'")]);
            $this->postCommand('configurations', ['command_id' => $command, 'market_id' => $sa['market'],
                'configuration_id' => $configuration, 'catalog_reference_id' => $sa['catalog'], 'denomination_code' => 'SAR'])->assertForbidden();
            $this->assertFalse($this->owner()->table('commercial_market_configuration_versions')->where('id', $configuration)->exists());
            $this->assertFalse($this->owner()->table('commercial_command_results')->where('command_id', $command)->exists());
            $this->assertFalse($this->owner()->table('commercial_audit_obligations')->where('command_id', $command)->exists());
        } finally {
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
        }
    }

    private function registerSql(array $input): mixed
    {
        return DB::scalar('SELECT builderad_register_commercial_scope(?,?,?,?,?,?,?,?)', [
            $input['command_id'], $input['scope_id'], $input['workspace_public_id'], $input['market_id'],
            $input['configuration_id'], $input['catalog_reference_id'], $input['denomination_code'], $input['reason_code']]);
    }

    public function test_r4_field_validation_conflict_and_denial_are_distinct_safe_responses(): void
    {
        $sa = $this->market('Saudi Arabia', 'SAR');
        $input = $this->registration($sa);
        $this->postJson('/internal/commercial/registrations', [...$input, 'reason_code' => 'INVALID REASON'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $this->postJson('/internal/commercial/registrations', $input)->assertOk();
        $this->postJson('/internal/commercial/registrations', [...$input, 'reason_code' => 'changed_request'])->assertConflict();
        $this->owner()->table('security_action_grants')->where('commercial_target_id', $input['scope_id'])
            ->where('action', 'RegisterCommercialScope')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson('/internal/commercial/registrations', $input)->assertForbidden();
        $this->assertSame(1, $this->owner()->table('commercial_scopes')->count());
        $this->get('/internal/commercial')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalogReferences.0.approval_reference', 'synthetic:approved')
            ->where('catalogReferences.0.catalog_version', '1')->missing('catalogReferences.0.producer_transaction'));
    }

    private function blockingHolder(string $sql, array $bindings, string $consumerConnection = 'pgsql'): Process
    {
        // Real second process/transaction. Readiness is emitted only after its row lock exists.
        $consumerPid = (int) DB::connection($consumerConnection)->scalar('SELECT pg_backend_pid()');
        $code = <<<'PHP'
        $db = new PDO('pgsql:host=127.0.0.1;port=5433;dbname=builderad_test_disposable', getenv('DB_OWNER_USERNAME'), getenv('DB_OWNER_PASSWORD'));
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction();
        $statement=$db->prepare($argv[1]); $statement->execute(json_decode($argv[2],true));
        $xid=$db->query('SELECT pg_current_xact_id()::text')->fetchColumn();
        echo 'LOCK_READY'; fflush(STDOUT);
        $deadline=microtime(true)+5;
        $probe=$db->prepare("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='transactionid' AND transactionid=?::xid AND pid=? AND NOT granted)");
        do { $probe->execute([$xid,$argv[3]]); $raw=$probe->fetchColumn(); $waiting=in_array($raw,[true,1,'1','t'],true); if($waiting) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$waiting) { $db->rollBack(); fwrite(STDERR,'No real waiter observed'); exit(1); }
        echo 'WAIT_OBSERVED; CONSUMER_PID='.$argv[3].'; BOOLEAN_TYPE='.get_debug_type($raw); fflush(STDOUT);
        $db->query('SELECT pg_sleep(2.2)'); $db->commit();
        PHP;
        $holder = new Process([PHP_BINARY, '-r', $code, $sql, json_encode($bindings, JSON_THROW_ON_ERROR), (string) $consumerPid]);
        $holder->setTimeout(10);
        $holder->start();
        $holder->waitUntil(fn ($type, $output) => str_contains($output, 'LOCK_READY'));
        $this->assertTrue($holder->isRunning(), $holder->getErrorOutput());

        return $holder;
    }

    private function market(string $label, string $denomination, bool $enable = true): array
    {
        $market = $this->id();
        $catalog = $this->id();
        $configuration = $this->id();
        foreach (['ConfigureCommercialMarket', 'ReadCommercialMarketRegistry', 'EnableCommercialMarket', 'DisableCommercialMarket'] as $effect) {
            $this->grant($this->operator, $effect, $market);
        }
        $this->grant($this->operator, 'RecordOrVerifyApprovedMarketReference', $market, null, $catalog);
        $this->postCommand('markets', ['market_id' => $market, 'label' => $label])->assertOk();
        $this->postCommand('catalog-references', ['market_id' => $market, 'catalog_reference_id' => $catalog,
            'catalog_identity' => $this->id(), 'catalog_version' => '1', 'approval_reference' => 'synthetic:approved',
            'source_sha256' => hash('sha256', $label)])->assertOk();
        $this->postCommand('configurations', ['market_id' => $market, 'configuration_id' => $configuration,
            'catalog_reference_id' => $catalog, 'denomination_code' => $denomination])->assertOk();
        $result = compact('market', 'catalog', 'configuration', 'denomination');
        if ($enable) {
            $this->enable($result, 1);
        }

        return $result;
    }

    private function enable(array $market, int $revision): void
    {
        $this->postCommand('assignability', ['market_id' => $market['market'], 'configuration_id' => $market['configuration'], 'expected_revision' => $revision])->assertOk();
    }

    private function registration(array $market, ?string $workspacePublicId = null): array
    {
        if ($workspacePublicId === null) {
            [, $workspacePublicId] = $this->workspace();
        }
        $workspace = $this->owner()->table('workspaces')->where('public_id', $workspacePublicId)->value('id');
        $scope = $this->id();
        foreach (['RegisterCommercialScope', 'RecordBeneficiaryBinding', 'ReadCommercialContextInternal', 'ReadCommercialRegistrationReceipt', 'ReadCommercialAuditDeliveryState', 'RetryOrRecoverExistingAuditDelivery'] as $effect) {
            $this->grant($this->operator, $effect, $scope, $workspace);
        }
        $this->grant($this->operator, 'AssignCommercialMarket', $scope, $workspace, $market['configuration']);

        return ['command_id' => $this->id(), 'reason_code' => 'synthetic_registration', 'scope_id' => $scope,
            'workspace_public_id' => $workspacePublicId, 'market_id' => $market['market'],
            'configuration_id' => $market['configuration'], 'catalog_reference_id' => $market['catalog'], 'denomination_code' => $market['denomination']];
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $workspace = $this->owner()->table('workspaces')->insertGetId(['name' => 'Synthetic beneficiary', 'slug' => 'synthetic-'.$this->id(), 'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
        $this->owner()->table('workspace_ownerships')->insert(['workspace_id' => $workspace, 'responsible_owner_user_id' => $user->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'source' => 'system_workspace_creation', 'action' => 'workspace.view', 'scope_kind' => 'workspace', 'workspace_id' => $workspace, 'subject_generation' => 1, 'reason' => 'synthetic_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $this->owner()->table('workspaces')->where('id', $workspace)->value('public_id')];
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $this->owner()->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $this->owner()->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => 'employee', 'status' => 'active', 'attested_by' => $user->id, 'attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Synthetic factor', 'credential_id' => 'synthetic-'.$user->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function grant(User $user, string $effect, string $target, ?int $workspace = null, ?string $version = null): void
    {
        $issuer = $user->id === $this->operator->id ? $this->staff()->id : $this->operator->id;
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'granted_by' => $issuer, 'source' => 'human',
            'action' => $effect, 'scope_kind' => 'commercial', 'commercial_target_id' => $target, 'workspace_id' => $workspace,
            'commercial_version_id' => $version, 'subject_generation' => 1, 'reason' => 'synthetic_fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function postCommand(string $path, array $input)
    {
        return $this->postJson('/internal/commercial/'.$path, ['command_id' => $this->id(), 'reason_code' => 'synthetic_configuration', ...$input]);
    }

    private function id(): string
    {
        return (string) Str::uuid7();
    }

    private function owner()
    {
        return DB::connection('pgsql_migrations');
    }

    private function assertSqlDenied(callable $operation, string $state): void
    {
        try {
            $operation();
            $this->fail('SQL boundary accepted forbidden operation.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }
}
