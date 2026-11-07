<?php

namespace Tests\Feature\Sources;

use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Security\ServiceWorkspaceContext;
use App\Security\SessionAuthority;
use App\Security\SourceAuditCustody;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson as Assert;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\RowLockReadiness;
use Tests\TestCase;

class SourceRegistryTest extends TestCase
{
    use ApplicationContractAssertions;

    private User $actor;

    private int $workspaceId;

    private string $workspace;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->actor, $this->workspaceId, $this->workspace] = $this->workspaceFixture();
        $this->actingAs($this->actor)->withCredentials();
        $this->input = ['source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid(), 'effect' => 'register',
            'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => 'synthetic-account', 'label' => 'Declared account',
            'resource' => 'synthetic-account', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
            'expected_version' => 0, 'reason' => 'synthetic_declaration'];
        foreach (['source.register', 'source.read', 'source.manage', 'source.audit.recover'] as $action) {
            $this->grantFixture($action);
        }
    }

    public function test_organization_sources_continue_after_commercial_legacy_drains_without_reopening_raw_facts(): void
    {
        $this->command($this->input)->assertOk();
        $cohort = $this->owner()->table('migration_cohort_members')->where('workspace_id', $this->workspaceId)->value('cohort_id');
        $this->owner()->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'LEGACY_ACTIVE',0,'DRAINING',NULL,decode(?,'hex'))",
            [(string) Str::uuid(), $cohort, hash('sha256', 'synthetic commercial drain')]);
        $this->getJson('/workspaces/'.$this->workspace)->assertOk()
            ->assertJsonMissingPath('data.business')->assertJsonMissingPath('data.guidance');
        $request = $this->request();
        app(WorkspaceContext::class)->run($this->actor, $this->workspace, function () {
            $this->assertSame(0, DB::table('workspaces')->count(), 'Raw commercial profile remains fenced');
            $metadata = DB::table('organization_workspaces')->sole();
            $this->assertSame($this->workspace, $metadata->public_id);
            $this->assertFalse(property_exists($metadata, 'business_type_id'));
            $this->assertFalse(property_exists($metadata, 'current_intent'));
            $this->assertFalse(property_exists($metadata, 'business_context_version'));
        }, $request);
        $this->command($this->transition('rename', 1, ['label' => 'Organization source after commercial retirement']))->assertOk();
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
        $this->owner()->table('workspace_memberships')->where('workspace_id', $this->workspaceId)->where('user_id', $this->actor->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson('/workspaces/'.$this->workspace)->assertForbidden();
        $this->command($this->transition('rename', 2, ['label' => 'Denied after departure']))->assertForbidden();
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
    }

    public function test_registration_replay_conflict_truthful_projection_and_five_families(): void
    {
        foreach (['meta', 'google', 'snapchat', 'tiktok', 'x', 'business'] as $family) {
            if ($family !== 'meta') {
                $this->input = [...$this->input, 'source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid(),
                    'family' => $family];
                foreach (['source.register', 'source.read'] as $action) {
                    $this->grantFixture($action);
                }
            }
            $first = $this->command($this->input)->assertOk()->assertJsonPath('registration_state', 'UNVERIFIED')->json();
            $this->command(array_reverse($this->input, true))->assertOk()->assertExactJson($first);
            $this->command([...$this->input, 'label' => 'Changed under same key'])->assertConflict();
            $this->command([...$this->input, 'command_id' => (string) Str::uuid()])->assertConflict();
        }
        $this->assertSame(6, $this->owner()->table('source_registry')->count());
        $this->assertSame(6, $this->owner()->table('source_command_receipts')->count());
        $this->assertSame(6, $this->owner()->table('source_audit_obligations')->count());
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->has('sources.items', 6)->where('sources.items.0.identity_verification', 'UNVERIFIED')
            ->where('sources.items.0.capability.state', 'UNKNOWN')->where('sources.items.0.capability.last_verified_at', null)
            ->where('sources.items.0.axes.provider_permission', 'UNKNOWN')->where('sources.items.0.audit_delivery.0.state', 'PENDING'));
        $this->command([...$this->input, 'provider_permission' => 'VERIFIED'])->assertUnprocessable();
        $this->command([...$this->input, 'token' => 'never_store'])->assertUnprocessable();
    }

    public function test_new_non_advertising_family_reuses_registry_without_advertising_or_data_use_authority(): void
    {
        $this->input = [...$this->input, 'family' => 'synthetic_store', 'namespace' => 'orders', 'label' => 'Synthetic commerce source',
            'resource' => 'synthetic-order-stream', 'operation' => 'orders.read', 'purpose' => 'commerce_measurement'];
        foreach (['source.register', 'source.read', 'source.manage', 'source.audit.recover'] as $action) {
            $this->grantFixture($action);
        }
        $input = $this->input;
        $this->command($input)->assertOk()->assertJsonPath('registration_state', 'UNVERIFIED');
        $response = $this->getJson('/workspaces/'.$this->workspace)->assertOk()
            ->assertJsonPath('data.sources.items.0.family', 'synthetic_store')
            ->assertJsonPath('data.sources.items.0.namespace', 'orders')
            ->assertJsonPath('data.sources.items.0.capability.state', 'UNKNOWN')
            ->assertJsonPath('data.sources.items.0.axes.provider_permission', 'UNKNOWN');
        $this->assertStringNotContainsString('VERIFIED_AVAILABLE', $response->getContent());
        $this->assertSame(0, $this->owner()->table('provider_access')->count());
        $this->assertSame(0, $this->owner()->table('data_use_scopes')->count());
        $this->assertSame(0, $this->owner()->table('business_data_use_bindings')->count());
        $this->command($input)->assertOk();
        $this->assertSame(1, $this->owner()->table('source_registry')->count());
        $this->assertSame(1, $this->owner()->table('source_command_receipts')->count());
    }

    public function test_application_source_resource_preserves_exact_scope_truth_and_passive_deadlines(): void
    {
        $path = '/workspaces/'.$this->workspace;
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data.sources.items')
            ->assertJsonPath('data.workspace.public_id', $this->workspace)->assertJsonPath('visibility.protected', true);
        $receipt = $this->command($this->input)->assertOk()->json();
        $resource = $this->getJson($path)->assertOk()->assertJsonCount(1, 'data.sources.items')
            ->assertJsonPath('data.sources.items.0.id', $this->input['source_id'])
            ->assertJsonPath('data.sources.items.0.identity_verification', 'UNVERIFIED')
            ->assertJsonPath('data.sources.items.0.capability.state', 'UNKNOWN')
            ->assertJsonPath('data.sources.items.0.capability.last_verified_at', null)
            ->assertJsonPath('data.sources.items.0.audit_delivery.0.state', 'PENDING');
        $this->assertStringContainsString('no-store', $resource->headers->get('Cache-Control'));
        $before = $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->sole();
        $this->travel(30)->seconds();
        $this->getJson($path, ['X-Builderad-Passive' => '1'])->assertOk();
        $this->getJson($path, ['X-Builderad-Witness' => '1'])->assertOk()
            ->assertJsonPath('visibility.protected', true)->assertJsonMissingPath('data');
        $after = $this->owner()->table('security_sessions')->where('id', $before->id)->sole();
        $this->assertSame($before->idle_expires_at, $after->idle_expires_at);
        $this->assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        $this->command($this->input)->assertExactJson($receipt);
        $this->assertSame(1, $this->owner()->table('source_command_receipts')->count());
        $this->owner()->table('security_action_grants')->where('workspace_id', $this->workspaceId)
            ->where('action', 'source.read')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data.sources.items');
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
    }

    public function test_workspace_presents_stable_opaque_session_binding_without_granting_source_authority(): void
    {
        $sessionId = $this->app['session']->driver()->getId();
        $expected = hash_hmac('sha256', $sessionId, (string) config('app.key'));
        foreach ([1, 2] as $attempt) {
            $response = $this->get('/workspaces/'.$this->workspace)->assertOk()->assertViewIs('spa')
                ->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false)
                ->assertDontSee('sourceCandidateId', false)->assertDontSee('commercial', false);
            $this->assertSame($expected, $response->viewData('identity')['binding']['context']);
            $this->assertSame($this->actor->id, $response->viewData('identity')['binding']['principal']);
        }
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $page) => $page->where('sources.state', 'NO_VISIBLE_SOURCE_AUTHORITY'));
        $this->assertNotSame($sessionId, $expected);
    }

    public function test_versioned_corrections_scopes_and_revocation_preserve_history(): void
    {
        $this->command($this->input)->assertOk();
        $rename = $this->transition('rename', 1, ['label' => 'Corrected label']);
        $receipt = $this->command($rename)->assertOk()->assertJsonPath('version', 2)->json();
        $this->command($rename)->assertOk()->assertExactJson($receipt);
        $this->command($this->transition('rename', 1, ['label' => 'Stale rename']))->assertConflict();
        $this->command($this->transition('revoke', 2))->assertOk()->assertJsonPath('version', 3);
        $this->command($this->transition('rename', 3, ['label' => 'Revive']))->assertUnprocessable();
        $this->assertSame(3, $this->owner()->table('source_versions')->count());
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->where('sources.items.0.capability.state', 'REVOKED')->where('sources.items.0.can_manage', false));
        $this->denied(fn () => $this->owner()->table('source_versions')->update(['snapshot' => '{}']), '23514');
    }

    public function test_additional_scope_requires_exact_grant_and_has_independent_visibility(): void
    {
        $this->command($this->input)->assertOk();
        $other = $this->transition('declare_scope', 1, ['operation' => 'discovery.read']);
        $this->command($other)->assertForbidden();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $this->command($other)->assertOk();
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p->has('sources.items', 1));
        $this->grantFixture('source.read', ['source_operation' => 'discovery.read']);
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p->has('sources.items', 2));
    }

    public function test_exact_action_target_purpose_expiry_revocation_and_generation_are_enforced(): void
    {
        foreach ([['action' => 'source.read'], ['source_target_id' => (string) Str::uuid7()],
            ['source_purpose' => 'other_purpose'], ['source_resource' => 'other_resource'], ['source_operation' => 'other.operation'],
            ['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")], ['subject_generation' => 2],
            ['status' => 'revoked', 'revoked_at' => now()]] as $change) {
            $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->where('action', 'source.register')->delete();
            if (! isset($change['action'])) {
                $this->grantFixture('source.register', $change);
            }
            $this->command($this->input)->assertForbidden();
            $this->assertSame(0, $this->owner()->table('source_registry')->count());
        }
    }

    public function test_expired_revoked_and_stale_generation_sessions_cannot_write(): void
    {
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)
            ->update(['absolute_expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $this->command($this->input)->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['absolute_expires_at' => now()->addHour()]);
        $this->actingAs($this->actor);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['revoked_at' => now()]);
        $this->command($this->input)->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['revoked_at' => null]);
        $this->actingAs($this->actor);
        $this->owner()->table('principal_lifecycles')->where('user_id', $this->actor->id)->update(['generation' => 2]);
        $this->command($this->input)->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame(0, $this->owner()->table('source_registry')->count());
    }

    public function test_member_with_exact_grants_can_read_but_cannot_manage_or_request_owner_grants(): void
    {
        $this->command($this->input)->assertOk();
        $member = User::factory()->create();
        $this->owner()->table('workspace_memberships')->insert(['workspace_id' => $this->workspaceId, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $member->id, 'granted_by' => $this->actor->id,
            'source' => 'human', 'action' => 'workspace.view', 'scope_kind' => 'workspace', 'workspace_id' => $this->workspaceId,
            'subject_generation' => 1, 'status' => 'active', 'reason' => 'synthetic_member', 'created_at' => now(), 'updated_at' => now()]);
        // Synthetic grant fixture is deliberately stronger than the public Owner issuance route.
        $this->grantFixture('source.read', ['subject_user_id' => $member->id, 'granted_by' => $member->id]);
        $this->grantFixture('source.manage', ['subject_user_id' => $member->id, 'granted_by' => $member->id]);
        $this->actingAs($member);
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p->has('sources.items', 1)
            ->where('canRequestSourceAuthority', false)->where('sources.items.0.can_manage', false));
        $this->command($this->transition('revoke', 1))->assertForbidden();
        $this->postJson('/workspaces/'.$this->workspace.'/source-grants', $this->grantInput('source.manage'))->assertForbidden();
        $this->actingAs($this->actor);
        $this->owner()->table('workspace_memberships')->where('workspace_id', $this->workspaceId)->where('user_id', $this->actor->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->command($this->transition('revoke', 1))->assertForbidden();
    }

    public function test_a003_explicit_owner_grant_issuance_replay_revocation_and_audit(): void
    {
        $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->delete();
        $this->command($this->input)->assertForbidden();
        $url = '/workspaces/'.$this->workspace.'/source-grants';
        $input = $this->grantInput('source.register');
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $input)->assertOk();
        $this->assertSame(1, $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->count());
        $this->command($this->input)->assertOk();
        $this->postJson($url, [...$input, 'decision' => 'revoke'])->assertOk();
        $this->command($this->input)->assertForbidden();
        $this->assertSame(2, $this->owner()->table('security_audit_events')->where('event', 'like', 'source_grant_%')->count());
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson($url, $this->grantInput('source.read'))->assertForbidden();
        $grantRow = (array) $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->first();
        unset($grantRow['id']);
        $this->denied(fn () => DB::table('security_action_grants')->insert($grantRow), '42501');
    }

    public function test_cross_workspace_natural_identity_and_command_keys_never_merge(): void
    {
        $first = $this->command($this->input)->assertOk()->json();
        [$other, $wid, $public] = $this->workspaceFixture();
        $this->actingAs($other);
        $this->command($this->input)->assertForbidden();
        $this->workspace = $public;
        $this->workspaceId = $wid;
        $this->actor = $other;
        $this->input['source_id'] = (string) Str::uuid7();
        foreach (['source.register', 'source.read'] as $action) {
            $this->grantFixture($action);
        }
        $second = $this->command($this->input)->assertOk()->json();
        $this->assertNotSame($first['source_id'], $second['source_id']);
        $this->assertSame(2, $this->owner()->table('source_registry')->count());
        $this->sourceProjection('/workspaces/'.$public, fn (Assert $p) => $p->has('sources.items', 1));
        $request = $this->request();
        app(WorkspaceContext::class)->run($other, $public, function () use ($request, $first): void {
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$this->workspaceId, SessionAuthority::sessionKey($request)]);
            $this->assertFalse(DB::table('source_registry')->where('id', $first['source_id'])->exists());
        }, $request);
    }

    public function test_catalog_force_rls_raw_writes_missing_and_forged_context_and_session_binding(): void
    {
        $this->command($this->input)->assertOk();
        foreach (['source_registry', 'source_capabilities', 'source_versions', 'source_command_receipts', 'source_audit_obligations', 'source_audit_delivery', 'security_source_audit_custody'] as $table) {
            $catalog = $this->owner()->selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
            $this->assertTrue($catalog->relrowsecurity && $catalog->relforcerowsecurity);
            $this->assertFalse($this->owner()->scalar('SELECT has_table_privilege(?, ?, ?)', ['builderad_app', $table, 'INSERT']));
            $this->assertFalse($this->owner()->scalar('SELECT has_table_privilege(?, ?, ?)', ['builderad_app', $table, 'UPDATE']));
            $this->assertFalse($this->owner()->scalar('SELECT has_table_privilege(?, ?, ?)', ['builderad_app', $table, 'DELETE']));
        }
        $this->assertSame(0, DB::table('source_registry')->count());
        DB::transaction(function (): void {
            DB::selectOne("SELECT set_config('app.principal_user_id',?,true),set_config('app.workspace_id',?,true),set_config('app.authorization_phase','workspace',true),set_config('app.source_session',?,true)",
                [(string) $this->actor->id, (string) $this->workspaceId, SessionAuthority::sessionKey($this->request())]);
            $this->assertSame(0, DB::table('source_registry')->count());
        });
        $request = $this->request();
        app(WorkspaceContext::class)->run($this->actor, $this->workspace, function () use ($request): void {
            $this->assertSame(0, DB::table('source_registry')->count()); // No verified source session binding yet.
            DB::selectOne('SELECT builderad_source_begin_read(?, ?)', [$this->workspaceId, SessionAuthority::sessionKey($request)]);
            $this->assertSame(1, DB::table('source_registry')->count());
            $this->denied(fn () => DB::transaction(fn () => DB::table('source_registry')->where('id', $this->input['source_id'])->update(['label' => 'raw'])), '42501');
            DB::selectOne("SELECT set_config('app.source_session','forged',true)");
            $this->assertSame(0, DB::table('source_registry')->count());
        }, $request);
        $this->denied(fn () => DB::statement('SET ROLE builderad_owner'), '42501');
    }

    public function test_local_audit_failure_rolls_back_all_facts_and_retry_recovers_once(): void
    {
        $this->owner()->unprepared("CREATE FUNCTION bb1_test_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic required audit failure' USING ERRCODE='23514'; END $$;
            CREATE TRIGGER bb1_test_fail BEFORE INSERT ON source_audit_obligations FOR EACH ROW EXECUTE FUNCTION bb1_test_audit_failure()");
        try {
            $this->command($this->input)->assertUnprocessable();
            foreach (['source_registry', 'source_command_receipts', 'source_audit_obligations', 'source_versions'] as $table) {
                $this->assertSame(0, $this->owner()->table($table)->count());
            }
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb1_test_fail ON source_audit_obligations; DROP FUNCTION bb1_test_audit_failure()');
        }
        $this->command($this->input)->assertOk();
        $this->assertSame(1, $this->owner()->table('source_registry')->count());
    }

    public function test_custody_failure_recovery_and_lost_ack_are_independent_and_idempotent(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $request = $this->request();
        app(WorkspaceContext::class)->run($this->actor, $this->workspace, function () use ($receipt, $request): void {
            $this->denied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_source_record_delivery(?, ?, ?::uuid, ?::uuid)', [
                $this->workspaceId, SessionAuthority::sessionKey($request), $receipt['obligation_id'], (string) Str::uuid7(),
            ])), '42501');
        }, $request);
        $url = '/workspaces/'.$this->workspace.'/source-audit/'.$receipt['obligation_id'].'/recover';
        $this->app->instance(SourceAuditCustody::class, new class extends SourceAuditCustody
        {
            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                throw new RuntimeException('Synthetic custody unavailable');
            }
        });
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->assertSame(1, $this->owner()->table('source_registry')->count());
        $this->app->instance(SourceAuditCustody::class, new class extends SourceAuditCustody
        {
            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                parent::accept($obligation, $fingerprint, $envelope);
                throw new RuntimeException('Synthetic lost custody acknowledgment');
            }
        });
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
        $this->app->instance(SourceAuditCustody::class, new SourceAuditCustody);
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->command($this->input)->assertExactJson($receipt);
        $this->assertSame(1, $this->owner()->table('source_command_receipts')->count());
        $this->assertSame(1, $this->owner()->table('source_audit_obligations')->count());
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
    }

    public function test_source_read_revoke_and_stale_responsible_owner_fail_closed(): void
    {
        $this->command($this->input)->assertOk();
        $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->where('action', 'source.read')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->has('sources.items', 0)->where('sources.state', 'NO_VISIBLE_SOURCE_AUTHORITY'));
        $this->owner()->table('workspace_ownerships')->where('workspace_id', $this->workspaceId)->delete();
        $this->command($this->transition('revoke', 1))->assertForbidden();
        $this->assertSame(1, $this->owner()->table('source_registry')->value('version'));
    }

    public function test_real_concurrent_exact_and_conflicting_replay_commit_one_receipt(): void
    {
        $outputs = $this->race([$this->input, $this->input]);
        $this->assertSame($outputs[0], $outputs[1]);
        $this->assertStringStartsWith('RESULT=', $outputs[0]);
        $this->assertSame(1, $this->owner()->table('source_command_receipts')->count());
        $rename = $this->transition('rename', 1, ['label' => 'Race winner A']);
        $outputs = $this->race([$rename, [...$rename, 'label' => 'Race winner B']]);
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'DENIED=23505'));
        $this->assertSame(2, $this->owner()->table('source_command_receipts')->count());
    }

    public function test_real_concurrent_natural_identity_and_versioned_revocation_races(): void
    {
        $other = [...$this->input, 'command_id' => (string) Str::uuid(), 'source_id' => (string) Str::uuid7()];
        $this->grantFixture('source.register', ['source_target_id' => $other['source_id']]);
        $outputs = $this->race([$this->input, $other], false);
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'DENIED=23505'));
        $this->assertSame(1, $this->owner()->table('source_registry')->count());
        // Whichever natural-identity command won is the source for the version race.
        $winner = $this->owner()->table('source_registry')->first();
        if ($winner->id !== $this->input['source_id']) {
            $this->input['source_id'] = $winner->id;
            foreach (['source.manage', 'source.read'] as $action) {
                $this->grantFixture($action);
            }
        }
        $outputs = $this->race([$this->transition('revoke', 1), $this->transition('rename', 1, ['label' => 'Concurrent correction'])]);
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'DENIED=23505'));
        $this->assertSame(2, $this->owner()->table('source_registry')->value('version'));
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
    }

    public function test_grant_expiry_after_real_source_row_wait_rolls_back_fact_and_audit(): void
    {
        $this->command($this->input)->assertOk();
        $pid = DB::scalar('SELECT pg_backend_pid()');
        $code = <<<'PHP'
        $db=new PDO('pgsql:host=127.0.0.1;port=5433;dbname=builderad_test_disposable',getenv('DB_OWNER_USERNAME'),getenv('DB_OWNER_PASSWORD'));
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->beginTransaction();
        $q=$db->prepare('SELECT id FROM source_registry WHERE id=?::uuid FOR UPDATE'); $q->execute([$argv[1]]);
        $xid=$db->query('SELECT pg_current_xact_id()::text')->fetchColumn(); echo 'LOCK_READY'; fflush(STDOUT);
        $q=$db->prepare("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='transactionid' AND transactionid=?::xid AND pid=? AND NOT granted)");
        $deadline=microtime(true)+5; $wait=false;
        do { $q->execute([$xid,$argv[2]]); $wait=in_array($q->fetchColumn(),[true,1,'1','t'],true); if($wait) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$wait) { $db->rollBack(); exit(1); } echo 'WAIT_OBSERVED'; fflush(STDOUT);
        $expiry=$db->prepare('SELECT expires_at IS NOT NULL AND expires_at <= clock_timestamp() FROM security_action_grants WHERE id=?');
        $deadline=microtime(true)+5; $expired=false;
        do { $expiry->execute([$argv[3]]); $expired=in_array($expiry->fetchColumn(),[true,1,'1','t'],true); if($expired) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$expired) { $db->rollBack(); exit(3); } echo 'EXPIRED_BEFORE_RELEASE'; fflush(STDOUT); $db->commit();
        PHP;
        $grantId = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])
            ->where('action', 'source.manage')->where('source_operation', 'report.read')->value('id');
        $this->assertNotNull($grantId);
        $holder = new Process([PHP_BINARY, '-r', $code, $this->input['source_id'], (string) $pid, (string) $grantId]);
        $holder->setTimeout(10);
        $holder->start();
        try {
            RowLockReadiness::await($holder);
            $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->where('action', 'source.manage')
                ->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
            $this->command($this->transition('rename', 1, ['label' => 'Must roll back']))->assertForbidden();
            $this->assertSame(1, $this->owner()->table('source_registry')->value('version'));
            $this->assertSame(1, $this->owner()->table('source_audit_obligations')->count());
        } finally {
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
            $this->assertStringContainsString('EXPIRED_BEFORE_RELEASE', $holder->getOutput());
            if ($holder->isRunning()) {
                $holder->stop();
            }
        }
    }

    public function test_scoped_service_identity_cannot_inherit_human_source_authority(): void
    {
        $this->command($this->input)->assertOk();
        $credential = bin2hex(random_bytes(32));
        $serviceId = $this->owner()->table('service_identities')->insertGetId([
            'name' => 'Synthetic scoped metadata service', 'status' => 'enabled', 'credential_hash' => hash('sha256', $credential),
            'version' => 1, 'created_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicePublic = $this->owner()->table('service_identities')->where('id', $serviceId)->value('public_id');
        $this->owner()->table('service_workspace_scopes')->insert([
            'service_identity_id' => $serviceId, 'workspace_id' => $this->workspaceId, 'authorized_by' => $this->actor->id,
            'purpose' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->owner()->table('service_action_grants')->insert([
            'service_identity_id' => $serviceId, 'workspace_id' => $this->workspaceId, 'granted_by' => $this->actor->id,
            'action' => 'workspace.view', 'reason' => 'synthetic_metadata_read', 'status' => 'active', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ServiceWorkspaceContext::class)->run($servicePublic, $credential, $this->workspace, function (): void {
            $this->assertSame(0, DB::table('source_registry')->count());
            $payload = array_diff_key($this->transition('revoke', 1), array_flip(['source_id', 'command_id', 'effect']));
            $this->denied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_source_command(?, ?, ?::uuid, ?::uuid, ?, ?::jsonb)', [
                $this->workspaceId, SessionAuthority::sessionKey($this->request()), (string) Str::uuid(),
                $this->input['source_id'], 'revoke', json_encode($payload, JSON_THROW_ON_ERROR),
            ])), '42501');
        });
        $this->assertSame('UNVERIFIED', $this->owner()->table('source_registry')->value('status'));
    }

    public function test_staff_principal_and_missing_signed_context_cannot_execute_source_effects(): void
    {
        $payload = array_diff_key($this->input, array_flip(['source_id', 'command_id', 'effect']));
        $execute = fn () => DB::scalar('SELECT builderad_source_command(?, ?, ?::uuid, ?::uuid, ?, ?::jsonb)', [
            $this->workspaceId, SessionAuthority::sessionKey($this->request()), $this->input['command_id'],
            $this->input['source_id'], 'register', json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $this->denied(fn () => DB::transaction($execute), '42501');
        $staff = User::factory()->create();
        $this->owner()->table('principal_lifecycles')->where('user_id', $staff->id)->update(['principal_class' => 'staff']);
        DB::transaction(function () use ($staff, $execute): void {
            app(PrincipalContext::class)->activate($staff);
            $this->denied(fn () => DB::transaction($execute), '42501');
        });
        $this->assertSame(0, $this->owner()->table('source_registry')->count());
    }

    public function test_mixed_scope_recovery_projects_each_receipt_and_preserves_revoked_source_history(): void
    {
        $a = $this->command($this->input)->assertOk()->json();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $this->grantFixture('source.read', ['source_operation' => 'discovery.read']);
        $this->grantFixture('source.audit.recover', ['source_operation' => 'discovery.read']);
        $b = $this->command($this->transition('declare_scope', 1, ['operation' => 'discovery.read']))->assertOk()->json();
        $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->where('source_operation', 'report.read')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->sourceProjection('/workspaces/'.$this->workspace, function (Assert $p) use ($a, $b): void {
            $p->has('sources.items', 2);
            foreach ([0, 1] as $index) {
                $p->where("sources.items.$index.audit_delivery.0.id", $a['obligation_id'])
                    ->where("sources.items.$index.audit_delivery.0.operation", 'report.read')
                    ->where("sources.items.$index.audit_delivery.0.receipt_version", 1)
                    ->where("sources.items.$index.audit_delivery.0.can_recover", false)
                    ->where("sources.items.$index.audit_delivery.0.recovery_reason", 'exact_recovery_authority_required')
                    ->where("sources.items.$index.audit_delivery.1.id", $b['obligation_id'])
                    ->where("sources.items.$index.audit_delivery.1.operation", 'discovery.read')
                    ->where("sources.items.$index.audit_delivery.1.receipt_version", 2)
                    ->where("sources.items.$index.audit_delivery.1.can_recover", true);
            }
        });
        $this->postJson($this->recoveryUrl($a['obligation_id']))->assertForbidden();
        $this->command($this->transition('revoke', 2))->assertOk();
        // Recovery concerns historical local audit, not use of the revoked source.
        $this->postJson($this->recoveryUrl($b['obligation_id']))->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->postJson($this->recoveryUrl($b['obligation_id']))->assertOk();
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
        $this->assertSame(1, $this->owner()->table('source_audit_delivery')->where('obligation_id', $b['obligation_id'])->value('attempts'));
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->where('sources.items.0.audit_delivery.1.can_recover', false)
            ->where('sources.items.0.audit_delivery.1.recovery_reason', 'already_acknowledged')
            ->where('sources.items.0.capability.state', 'REVOKED')->has('sources.items.0.scope_choices', 0));
    }

    public function test_recovery_projection_does_not_authorize_effect_or_leak_across_workspaces(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p->where('sources.items.0.audit_delivery.0.can_recover', true));
        foreach (['source_resource' => 'wrong-resource', 'source_operation' => 'wrong.operation', 'source_purpose' => 'wrong_purpose'] as $field => $value) {
            $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->update([$field => $value]);
            $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertForbidden();
            $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->update([$field => $this->input[substr($field, 7)]]);
        }
        $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->update(['expires_at' => now()->subSecond()]);
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertForbidden();
        $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->update(['expires_at' => null, 'status' => 'revoked', 'revoked_at' => now()]);
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertForbidden();
        [$other, $wid, $public] = $this->workspaceFixture();
        $this->actingAs($other);
        $this->sourceProjection('/workspaces/'.$public, fn (Assert $p) => $p->has('sources.items', 0));
        $this->postJson('/workspaces/'.$public.'/source-audit/'.$receipt['obligation_id'].'/recover')->assertForbidden();
        $this->actingAs($this->actor);
        $this->owner()->table('principal_lifecycles')->where('user_id', $this->actor->id)->update(['generation' => 2]);
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame(0, $this->owner()->table('security_source_audit_custody')->count());
        $this->assertSame(0, $this->owner()->table('source_audit_delivery')->value('attempts'));
    }

    public function test_scope_choices_use_exact_current_server_grants_and_declaration_refreshes_truth(): void
    {
        $this->command($this->input)->assertOk();
        $scope = ['resource' => 'allowed-resource', 'operation' => 'discovery.read', 'purpose' => 'diagnostic_review'];
        $this->grantFixture('source.manage', ['source_resource' => $scope['resource'], 'source_operation' => $scope['operation'], 'source_purpose' => $scope['purpose']]);
        $this->grantFixture('source.manage', ['source_resource' => 'expired', 'expires_at' => now()->subSecond()]);
        $this->grantFixture('source.manage', ['source_resource' => 'revoked', 'status' => 'revoked', 'revoked_at' => now()]);
        $this->grantFixture('source.manage', ['source_resource' => 'other-source', 'source_target_id' => (string) Str::uuid7()]);
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->where('sources.items.0.scope_choices', [$scope])->where('sources.items.0.version', 1));
        $this->grantFixture('source.read', ['source_resource' => $scope['resource'], 'source_operation' => $scope['operation'], 'source_purpose' => $scope['purpose']]);
        $input = $this->transition('declare_scope', 1, $scope);
        $receipt = $this->command($input)->assertOk()->assertJsonPath('version', 2)->json();
        $this->command($input)->assertExactJson($receipt);
        $this->command([...$input, 'reason' => 'changed_payload'])->assertConflict();
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p
            ->has('sources.items', 2)->where('sources.items', fn ($items) => collect($items)->contains('resource', $scope['resource']))
            ->where('sources.items.1.version', 2)->where('sources.items.1.capability.state', 'UNKNOWN')
            ->where('sources.items.1.identity_verification', 'UNVERIFIED')->has('sources.items.0.scope_choices', 0));
        $this->assertSame(2, $this->owner()->table('source_command_receipts')->count());
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
        $this->assertSame(2, $this->owner()->table('source_audit_obligations')->count());
    }

    public function test_declare_scope_negative_authority_validation_version_and_revocation(): void
    {
        $this->command($this->input)->assertOk();
        $input = $this->transition('declare_scope', 1, ['operation' => 'discovery.read']);
        $this->command($input)->assertForbidden();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        foreach ([['resource' => 'wrong'], ['operation' => 'wrong.operation'], ['purpose' => 'wrong_purpose'], ['source_id' => (string) Str::uuid7()]] as $change) {
            $this->command([...$input, ...$change])->assertForbidden();
        }
        foreach ([['operation' => 'INVALID'], ['purpose' => 'INVALID'], ['resource' => str_repeat('r', 181)],
            ['expected_version' => -1], ['provider_permission' => 'VERIFIED'], ['token' => 'asserted'], ['label' => 'not_allowed']] as $change) {
            $this->command([...$input, ...$change])->assertUnprocessable();
        }
        $this->command([...$input, 'expected_version' => 0])->assertConflict();
        [$other, $wid, $public] = $this->workspaceFixture();
        $this->actingAs($other)->postJson('/workspaces/'.$public.'/sources', $input)->assertForbidden();
        $this->actingAs($this->actor);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->sourceProjection('/workspaces/'.$this->workspace, fn (Assert $p) => $p->has('sources.items.0.scope_choices', 0)
            ->where('sources.items.0.audit_delivery.0.recovery_reason', 'fresh_authentication_required'));
        $this->command($input)->assertForbidden();
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()]);
        $this->command($this->transition('revoke', 1))->assertOk();
        $this->command([...$input, 'expected_version' => 2])->assertUnprocessable();
        $this->assertSame(1, $this->owner()->table('source_capabilities')->count());
    }

    public function test_real_concurrent_scope_declarations_same_and_different_tuples(): void
    {
        $this->command($this->input)->assertOk();
        foreach (['discovery.read', 'diagnostic.read'] as $op) {
            $this->grantFixture('source.manage', ['source_operation' => $op]);
        }
        $a = $this->transition('declare_scope', 1, ['operation' => 'discovery.read']);
        $outputs = $this->race([$a, $a]);
        $this->assertSame($outputs[0], $outputs[1]);
        $this->assertStringStartsWith('RESULT=', $outputs[0]);
        $outputs = $this->race([$this->transition('declare_scope', 2, ['operation' => 'diagnostic.read']),
            $this->transition('declare_scope', 2, ['operation' => 'diagnostic.read'])]);
        $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'RESULT=')));
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'DENIED=23505'));
        $this->assertSame(3, $this->owner()->table('source_capabilities')->count());
        // Two distinct, valid new scopes use optimistic version concurrency: one must refresh/retry.
        foreach (['fresh.read', 'another.read'] as $op) {
            $this->grantFixture('source.manage', ['source_operation' => $op]);
        }
        $outputs = $this->race([$this->transition('declare_scope', 3, ['operation' => 'fresh.read']),
            $this->transition('declare_scope', 3, ['operation' => 'another.read'])]);
        $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'RESULT=')));
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'DENIED=23505'));
        $this->assertSame(4, $this->owner()->table('source_registry')->value('version'));
        $this->assertSame(4, $this->owner()->table('source_audit_obligations')->count());
    }

    public function test_declare_scope_revoke_or_expiry_while_workers_wait_denies_every_effect(): void
    {
        $this->command($this->input)->assertOk();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        foreach ([['status' => 'revoked', 'revoked_at' => now()], ['status' => 'active', 'revoked_at' => null, 'expires_at' => now()->subSecond()]] as $change) {
            $this->owner()->table('security_action_grants')->where('source_operation', 'discovery.read')->update(['status' => 'active', 'revoked_at' => null, 'expires_at' => null]);
            $outputs = $this->race([$this->transition('declare_scope', 1, ['operation' => 'discovery.read']),
                $this->transition('declare_scope', 1, ['operation' => 'discovery.read'])], true,
                fn () => $this->owner()->table('security_action_grants')->where('source_operation', 'discovery.read')->update($change));
            $this->assertSame(['DENIED=42501', 'DENIED=42501'], $outputs);
            $this->assertSame(1, $this->owner()->table('source_registry')->value('version'));
            $this->assertSame(1, $this->owner()->table('source_audit_obligations')->count());
        }
    }

    private function recoveryUrl(string $obligation): string
    {
        return '/workspaces/'.$this->workspace.'/source-audit/'.$obligation.'/recover';
    }

    public function test_declare_scope_local_audit_rollback_and_independent_custody_outage(): void
    {
        $this->command($this->input)->assertOk();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $this->grantFixture('source.read', ['source_operation' => 'discovery.read']);
        $this->grantFixture('source.audit.recover', ['source_operation' => 'discovery.read']);
        $input = $this->transition('declare_scope', 1, ['operation' => 'discovery.read']);
        $this->owner()->unprepared("CREATE FUNCTION bb1_scope_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'required local audit failure' USING ERRCODE='23514'; END $$;
            CREATE TRIGGER bb1_scope_fail BEFORE INSERT ON source_audit_obligations FOR EACH ROW EXECUTE FUNCTION bb1_scope_audit_failure()");
        try {
            $this->command($input)->assertUnprocessable();
            foreach (['source_capabilities', 'source_command_receipts', 'source_audit_obligations', 'source_versions'] as $table) {
                $this->assertSame(1, $this->owner()->table($table)->count());
            }
            $this->assertSame(1, $this->owner()->table('source_registry')->value('version'));
        } finally {
            $this->owner()->unprepared('DROP TRIGGER bb1_scope_fail ON source_audit_obligations; DROP FUNCTION bb1_scope_audit_failure()');
        }
        $receipt = $this->command($input)->assertOk()->json();
        $this->app->instance(SourceAuditCustody::class, new class extends SourceAuditCustody
        {
            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                throw new RuntimeException('Synthetic custody outage');
            }
        });
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->command($input)->assertExactJson($receipt);
        $this->assertSame(2, $this->owner()->table('source_capabilities')->count());
        $this->assertSame(2, $this->owner()->table('source_registry')->value('version'));
        $this->assertSame(2, $this->owner()->table('source_audit_obligations')->count());
        $this->app->instance(SourceAuditCustody::class, new SourceAuditCustody);
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
    }

    public function test_declare_scope_expiry_after_source_row_wait_rolls_back_every_local_fact(): void
    {
        $this->command($this->input)->assertOk();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $this->withObservedRowWait('source_registry', 'id', $this->input['source_id'], false, function (): void {
            $this->owner()->table('security_action_grants')->where('action', 'source.manage')->where('source_operation', 'discovery.read')
                ->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
            $this->command($this->transition('declare_scope', 1, ['operation' => 'discovery.read']))->assertForbidden();
        });
        foreach (['source_capabilities', 'source_command_receipts', 'source_audit_obligations', 'source_versions'] as $table) {
            $this->assertSame(1, $this->owner()->table($table)->count());
        }
        $this->assertSame(1, $this->owner()->table('source_registry')->value('version'));
    }

    public function test_recovery_expiry_after_delivery_wait_keeps_independent_custody_and_local_state_separate(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $this->withObservedRowWait('source_audit_delivery', 'obligation_id', $receipt['obligation_id'], false, function () use ($receipt): void {
            $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')
                ->update(['expires_at' => DB::raw("clock_timestamp()+interval '2 seconds'")]);
            $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertForbidden();
        });
        $this->assertSame('PENDING', $this->owner()->table('source_audit_delivery')->value('state'));
        $this->assertSame(0, $this->owner()->table('source_audit_delivery')->value('attempts'));
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
        $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->update(['expires_at' => null]);
        $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->assertSame(1, $this->owner()->table('security_source_audit_custody')->count());
    }

    public function test_recovery_revocation_while_waiting_on_actual_grant_denies_before_custody(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $grantId = $this->owner()->table('security_action_grants')->where('action', 'source.audit.recover')->value('id');
        $this->withObservedRowWait('security_action_grants', 'id', (string) $grantId, true,
            fn () => $this->postJson($this->recoveryUrl($receipt['obligation_id']))->assertForbidden());
        $this->assertSame(0, $this->owner()->table('security_source_audit_custody')->count());
        $this->assertSame(0, $this->owner()->table('source_audit_delivery')->value('attempts'));
    }

    private function withObservedRowWait(string $table, string $column, string $id, bool $revoke, callable $effect): void
    {
        $expiryGrant = $revoke ? null : $this->owner()->table('security_action_grants')
            ->where('action', $table === 'source_registry' ? 'source.manage' : 'source.audit.recover')
            ->when($table === 'source_registry', fn ($query) => $query->where('source_operation', 'discovery.read'))
            ->where('source_target_id', $this->input['source_id'])->value('id');
        if (! $revoke) {
            $this->assertNotNull($expiryGrant, 'The exact expiry fixture grant must exist.');
        }
        $code = <<<'PHP'
        require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        app(App\Security\DisposableTestDatabaseGuard::class)->assertSafe();
        $db=Illuminate\Support\Facades\DB::connection('pgsql_migrations')->getPdo(); $db->beginTransaction();
        $allowed=['source_registry'=>'id','source_audit_delivery'=>'obligation_id','security_action_grants'=>'id'];
        if(($allowed[$argv[1]]??null)!==$argv[2]) exit(2);
        $q=$db->prepare('SELECT '.$argv[2].' FROM '.$argv[1].' WHERE '.$argv[2].'=? FOR UPDATE'); $q->execute([$argv[3]]);
        $xid=$db->query('SELECT pg_current_xact_id()::text')->fetchColumn(); echo 'LOCK_READY'; fflush(STDOUT);
        $q=$db->prepare("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='transactionid' AND transactionid=?::xid AND pid=? AND NOT granted)");
        $deadline=microtime(true)+25; $wait=false;
        do { $q->execute([$xid,$argv[4]]); $wait=in_array($q->fetchColumn(),[true,1,'1','t'],true); if($wait) break; usleep(20000); } while(microtime(true)<$deadline);
        if(!$wait) { $db->rollBack(); exit(1); } echo 'WAIT_OBSERVED'; fflush(STDOUT);
        if($argv[5]==='revoke') { $q=$db->prepare("UPDATE security_action_grants SET status='revoked',revoked_at=clock_timestamp() WHERE id=?"); $q->execute([$argv[3]]); }
        else {
            $expiry=$db->prepare('SELECT expires_at IS NOT NULL AND expires_at <= clock_timestamp() FROM security_action_grants WHERE id=?');
            $deadline=microtime(true)+6; $expired=false;
            do { $expiry->execute([$argv[6]]); $expired=in_array($expiry->fetchColumn(),[true,1,'1','t'],true); if($expired) break; usleep(20000); } while(microtime(true)<$deadline);
            if(!$expired) { $db->rollBack(); exit(3); } echo 'EXPIRED_BEFORE_RELEASE'; fflush(STDOUT);
        }
        $db->commit();
        PHP;
        $holder = new Process([PHP_BINARY, '-r', $code, $table, $column, $id, (string) DB::scalar('SELECT pg_backend_pid()'), $revoke ? 'revoke' : 'expire', (string) $expiryGrant], base_path());
        $holder->setTimeout(40);
        $holder->start();
        try {
            RowLockReadiness::await($holder);
            $effect();
            $holder->wait();
            $this->assertTrue($holder->isSuccessful(), $holder->getErrorOutput());
            $this->assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
            if (! $revoke) {
                $this->assertStringContainsString('EXPIRED_BEFORE_RELEASE', $holder->getOutput());
            }
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }
        }
    }

    private function race(array $inputs, bool $sameSource = true, ?callable $whileBlocked = null): array
    {
        $run = 'bb1-race-'.Str::uuid();
        $holder = DB::build(config('database.connections.pgsql_migrations'));
        $processes = [];
        $holder->beginTransaction();
        // Same-source commands queue behind one fence; distinct sources queue behind their own fences.
        foreach (array_unique(array_column($inputs, 'source_id')) as $source) {
            $holder->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('bb1.source.'||?::text||'.'||?::text,0))", [$this->workspaceId, $source]);
        }
        try {
            foreach ($inputs as $input) {
                $p = new Process([PHP_BINARY, base_path('tests/SourceCommandWorker.php'), json_encode([
                    'run' => $run, 'actor' => $this->actor->id, 'workspace' => $this->workspaceId,
                    'session' => SessionAuthority::sessionKey($this->request()), 'input' => $input,
                ], JSON_THROW_ON_ERROR)]);
                $p->setTimeout(45);
                $p->start();
                $processes[] = $p;
            }
            $deadline = microtime(true) + 30;
            do {
                // Same restricted role can inspect its own workers without pg_read_all_stats.
                $waiters = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND lower(wait_event)='advisory'", [$run]);
                if ($waiters === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $diagnostics = implode('\n', array_map(fn ($p) => $p->getOutput().' '.$p->getErrorOutput(), $processes));
            $this->assertSame(2, $waiters, 'Both real worker transactions must overlap at database locks. '.$diagnostics);
            if ($whileBlocked) {
                $whileBlocked();
            }
            $holder->commit();
            $outputs = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $outputs[] = $p->getOutput();
            }

            return $outputs;
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
            $holder->disconnect();
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }

    public function test_adopted_source_denies_old_native_fact_and_authority_writers_at_database_without_affecting_native_only_sources(): void
    {
        $this->command($this->input)->assertOk();
        $this->command($this->transition('rename', 1, ['label' => 'Native before adoption']))->assertOk();
        $source = $this->input['source_id'];
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.read')->first();
        BusinessAuthorityFixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source',
            'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $this->actor->id);
        $this->owner()->beginTransaction();
        try {
            $this->owner()->selectOne('SELECT id FROM security_action_grants WHERE id=? FOR UPDATE', [$grant->id]);
            // Rejection must precede the authority child/target-row lock; its production timeout is unchanged.
            $this->postJson('/workspaces/'.$this->workspace.'/source-grants', [...$this->grantInput('source.read'), 'decision' => 'revoke'])->assertForbidden();
        } finally {
            $this->owner()->rollBack();
        }
        $beforeReceipts = $this->owner()->table('source_command_receipts')->count();
        $this->command($this->transition('rename', 2, ['label' => 'Stale native writer']))->assertForbidden();
        foreach (['pgsql_migrations', 'pgsql_authority'] as $name) {
            $this->denied(fn () => DB::connection($name)->table('security_action_grants')->where('id', $grant->id)
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2, 'updated_at' => now()]), '42501');
        }
        $this->denied(fn () => $this->owner()->table('source_registry')->where('id', $source)->update(['label' => 'Direct mutation', 'version' => 3]), '42501');
        $this->denied(fn () => $this->owner()->table('source_capabilities')->where('source_id', $source)->delete(), '42501');
        $this->denied(fn () => $this->grantFixture('source.audit.recover', ['action' => 'source.read', 'subject_generation' => 2]), '42501');
        $this->assertSame(1, $this->owner()->table('security_action_grants')->where('id', $grant->id)->update(['runtime_lock_marker' => true]));
        $this->assertSame(1, $this->owner()->table('source_registry')->where('id', $source)->update(['scope_lock_marker' => true]));
        $this->assertSame('active', $this->owner()->table('security_action_grants')->where('id', $grant->id)->value('status'));
        $this->assertSame('Native before adoption', $this->owner()->table('source_registry')->where('id', $source)->value('label'));
        $this->assertSame(2, $this->owner()->table('source_registry')->where('id', $source)->value('version'));
        $this->assertSame($beforeReceipts, $this->owner()->table('source_command_receipts')->count());
        $this->assertSame(1, $this->owner()->table('source_capabilities')->where('source_id', $source)->count());
        $this->input = [...$this->input, 'source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid(), 'external_id' => 'independent-native-source'];
        $this->grantFixture('source.register');
        $this->grantFixture('source.read');
        $this->command($this->input)->assertOk();
        $this->assertSame(2, $this->owner()->table('source_registry')->count());
    }

    public function test_source_owner_adopts_without_issuing_business_rights_and_reconciles_the_exact_command(): void
    {
        $native = $this->command($this->input)->assertOk()->json();
        $this->getJson('/workspaces/'.$this->workspace)->assertOk()->assertJsonPath('data.sources.items.0.can_manage', true);
        $body = $this->adoptionBody();
        $before = $this->owner()->table('security_action_grants')->count();
        $result = $this->postJson('/api/data-rights/commands', $body)->assertOk()
            ->assertJsonPath('data.source_id', $this->input['source_id'])
            ->assertJsonPath('data.adoption_state', 'v2_adopted')->assertJsonPath('data.versions.source', 1)
            ->assertJsonPath('data.versions.rights_epoch', 1);
        $this->assertApplicationContract('BusinessResourceRightResult', $result->json());
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertExactJson($result->json());
        $this->getJson('/workspaces/'.$this->workspace)->assertOk()->assertJsonPath('data.sources.items.0.can_manage', false)
            ->assertJsonPath('data.sources.items.0.scope_choices', []);
        $this->assertSame($before, $this->owner()->table('security_action_grants')->count());
        $this->assertSame(0, $this->owner()->table('businesses')->count());
        $this->assertSame(1, $this->owner()->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        $this->assertSame($native, json_decode($this->owner()->table('source_command_receipts')->where('command_id', $this->input['command_id'])->value('result'), true));
        $this->command($this->transition('rename', 1, ['label' => 'Stale native writer']))->assertForbidden();
        $this->postJson('/workspaces/'.$this->workspace.'/source-grants', $this->grantInput('source.read'))->assertForbidden();
        $body['command_id'] = (string) Str::uuid();
        $this->postJson('/api/data-rights/commands', $body)->assertConflict();
        $this->assertSame('UNVERIFIED', $this->owner()->table('source_registry')->value('status'));
        $this->assertSame(1, $this->owner()->table('source_versions')->count());
    }

    public function test_source_adoption_rejects_wrong_capability_stale_owner_and_unqualified_payload_without_effects(): void
    {
        $this->command($this->input)->assertOk();
        $body = $this->adoptionBody();
        $read = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->value('id');
        $wrong = $body;
        $wrong['authority_selection']['requirements'][0]['leaf_grant_id'] = $read;
        $this->postJson('/api/data-rights/commands', $wrong)->assertForbidden();
        $wrong = $body;
        $wrong['expected_revision'] = 2;
        $this->postJson('/api/data-rights/commands', $wrong)->assertConflict();
        $wrong = $body;
        $wrong['payload'] = (object) ['business_public_id' => (string) Str::uuid7()];
        $this->postJson('/api/data-rights/commands', $wrong)->assertUnprocessable();
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()]);
        $this->owner()->table('workspace_ownerships')->where('workspace_id', $this->workspaceId)->update(['responsible_owner_user_id' => User::factory()->create()->id, 'version' => 2]);
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->assertSame(0, $this->owner()->table('resource_right_fences')->count());
        $this->assertSame(0, $this->owner()->table('business_command_receipts')->count());
    }

    public function test_native_source_authority_cannot_write_without_its_admission_or_use_it_after_adoption(): void
    {
        $this->command($this->input)->assertOk();
        $source = $this->input['source_id'];
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.read')->first();
        $authority = DB::connection('pgsql_authority');
        $this->denied(fn () => $authority->table('security_action_grants')->where('id', $grant->id)
            ->update(['status' => 'revoked', 'revoked_at' => now(), 'version' => 2, 'updated_at' => now()]), '42501');
        $unrelated = (string) Str::uuid7();
        $this->denied(fn () => $authority->transaction(function () use ($authority, $unrelated, $grant): void {
            $authority->scalar('SELECT builderad_v2_native_source_authority_enter(?::uuid)', [$unrelated]);
            $authority->table('security_action_grants')->where('id', $grant->id)->update(['source_target_id' => $unrelated, 'version' => 2, 'updated_at' => now()]);
        }), '42501');
        $this->denied(fn () => DB::scalar('SELECT builderad_v2_native_source_authority_enter(?::uuid)', [$source]), '42501');
        $this->postJson('/api/data-rights/commands', $this->adoptionBody())->assertOk();
        $this->denied(fn () => $authority->transaction(fn () => $authority->scalar('SELECT builderad_v2_native_source_authority_enter(?::uuid)', [$source])), '42501');
        $this->assertSame('active', $this->owner()->table('security_action_grants')->where('id', $grant->id)->value('status'));
    }

    public function test_protected_source_administration_surface_preserves_its_exact_native_scope(): void
    {
        $this->command($this->input)->assertOk();
        $body = $this->adoptionBody();
        $access = $body['authority_selection']['requirements'][0]['leaf_grant_id'];
        $list = $this->getJson('/api/source-rights')->assertOk()->assertJsonCount(1, 'data.sources');
        $this->assertApplicationContract('NativeSourceChoicesResult', $list->json());
        $url = '/api/source-rights/'.$this->input['source_id'].'?access='.$access;
        $surface = $this->getJson($url)->assertOk()->assertJsonPath('data.source.adopted', false)
            ->assertJsonPath('data.source.label', $this->input['label'])->assertJsonPath('data.can_command', true);
        $this->assertApplicationContract('NativeSourceSurfaceResult', $surface->json());
        $this->assertArrayNotHasKey('declared_external_id', $surface->json('data.source'));
        $this->getJson('/source-rights/'.$this->input['source_id'].'?access='.$access)->assertOk()->assertJsonPath('visibility.protected', true);
        $this->getJson('/api/source-rights/'.Str::uuid7().'?access='.$access)->assertForbidden();
        $read = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->value('id');
        $this->getJson('/api/source-rights/'.$this->input['source_id'].'?access='.$read)->assertForbidden();
        $this->postJson('/api/data-rights/commands', $body)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.source.adopted', true);
        $this->assertSame(0, $this->owner()->table('businesses')->count());
        $this->assertSame(0, $this->owner()->table('data_use_scopes')->count());
        $this->owner()->table('workspace_ownerships')->where('workspace_id', $this->workspaceId)->update(['responsible_owner_user_id' => User::factory()->create()->id, 'version' => 2]);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/source-rights')->assertOk()->assertJsonCount(0, 'data.sources');
    }

    public function test_protected_source_rename_reuses_native_history_and_retains_exact_idempotence(): void
    {
        $this->command($this->input)->assertOk();
        $adopt = $this->adoptionBody();
        $this->postJson('/api/data-rights/commands', $adopt)->assertOk();
        $rename = [...$adopt, 'command_id' => (string) Str::uuid(), 'effect' => 'rename', 'payload' => ['label' => 'Qualified new source name', 'reason' => 'explicit_source_label']];
        $first = $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertJsonPath('data.versions.source', 2)->json();
        $this->assertApplicationContract('BusinessResourceRightResult', $first);
        $this->postJson('/api/data-rights/commands', $rename)->assertOk()->assertExactJson($first);
        $this->postJson('/api/data-rights/commands', [...$rename, 'payload' => ['label' => 'Changed uncertain command', 'reason' => 'explicit_source_label']])->assertConflict();
        $this->postJson('/api/data-rights/commands', [...$rename, 'command_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/data-rights/commands', [...$rename, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2, 'payload' => ['label' => ' ', 'reason' => 'explicit_source_label']])->assertUnprocessable();
        $this->command($this->transition('rename', 2, ['label' => 'Legacy bypass']))->assertForbidden();
        $this->assertSame('Qualified new source name', $this->owner()->table('source_registry')->value('label'));
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
        $this->assertSame(2, $this->owner()->table('source_audit_obligations')->count());
        $this->assertSame(2, $this->owner()->table('source_command_receipts')->count());
        $this->assertSame('rename', $this->owner()->table('source_command_receipts')->orderByDesc('decided_at')->value('effect'));
        $this->assertSame(2, $this->owner()->table('resource_right_fences')->value('rights_epoch'));
    }

    public function test_protected_source_rename_rolls_back_when_freshness_expires_during_final_receipt_wait(): void
    {
        $this->assertSourceFinalFreshness('rename');
    }

    public function test_source_surface_rechecks_responsible_owner_after_actual_rights_fence_wait(): void
    {
        $this->assertSourceReaderPostWait('source-surface');
    }

    public function test_source_choices_rechecks_responsible_owner_after_actual_rights_fence_wait(): void
    {
        $this->assertSourceReaderPostWait('source-choices');
    }

    private function assertSourceReaderPostWait(string $operation): void
    {
        $this->command($this->input)->assertOk();
        $body = $this->adoptionBody();
        $this->postJson('/api/data-rights/commands', $body)->assertOk();
        $access = $body['authority_selection']['requirements'][0]['leaf_grant_id'];
        $run = $operation.'-withdrawal-'.Str::uuid();
        $path = storage_path('framework/testing/'.$run.'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $release = $path.'.release';
        file_put_contents($path, json_encode(['actor' => $this->actor->id, 'run' => $run, 'operation' => $operation, 'source' => $this->input['source_id'],
            'read_grant' => $access, 'session' => SessionAuthority::sessionKey($this->request()), 'release' => $release], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $db = $this->owner();
        $other = User::factory()->create();
        $evidence = ['run' => $run];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 15;
            do {
                if ($result = $probe()) {
                    return $result;
                }
                if (! $worker->isRunning()) {
                    throw new RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Required actual source read contention not witnessed.');
        };
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select("SELECT fence_id FROM resource_right_fences WHERE kind='source' AND source_id=?::uuid FOR UPDATE", [$this->input['source_id']]);
            file_put_contents($release, 'go');
            $evidence['wait'] = $wait(fn () => DB::selectOne('SELECT pid,backend_xid::text xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE application_name=? AND ?=ANY(pg_blocking_pids(pid))', [$run, $blocker]));
            // The reader is at K1. Its actor's K4 identity must still be unlocked.
            // NOWAIT turns a backward lock into a decisive failure, never a deadlock.
            $db->transaction(fn () => $db->select('SELECT user_id FROM principal_lifecycles WHERE user_id=? FOR UPDATE NOWAIT', [$this->actor->id]));
            $evidence['identity_lock_available_while_reader_waited'] = true;
            $db->table('workspace_ownerships')->where('workspace_id', $this->workspaceId)->update(['responsible_owner_user_id' => $other->id, 'version' => 2]);
            $db->commit();
            $worker->wait();
            $this->assertSame($operation === 'source-surface' ? 2 : 0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            if ($operation === 'source-surface') {
                $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
                $this->assertStringNotContainsString('COMMITTED=', $worker->getOutput());
            } else {
                $this->assertStringContainsString('COMMITTED=', $worker->getOutput());
                $this->assertStringContainsString('"sources": []', $worker->getOutput());
            }
            $this->assertStringNotContainsString('Declared account', $worker->getOutput());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            foreach ([$path, $release] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_source_issue_receipt_cannot_be_reinterpreted_under_another_native_capability(): void
    {
        $this->command($this->input)->assertOk();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $this->command($this->transition('declare_scope', 1, ['operation' => 'discovery.read']))->assertOk();
        $body = [...$this->adoptionBody(), 'expected_revision' => 2];
        $this->postJson('/api/data-rights/commands', $body)->assertOk();
        $read = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->value('id');
        $this->postJson('/api/data-rights/commands', [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'expected_revision' => 1, 'payload' => ['grant_id' => (string) $read]])->assertOk();
        $issue = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'qualify_issue', 'expected_revision' => 0,
            'payload' => ['action' => 'source.read', 'reason' => 'explicit_source_right', 'expires_at' => null]];
        $first = $this->postJson('/api/data-rights/commands', $issue)->assertOk()->json();
        $other = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.manage')->where('source_operation', 'discovery.read')->value('id');
        $changed = $issue;
        $changed['authority_selection']['requirements'][0]['leaf_grant_id'] = $other;
        $this->postJson('/api/data-rights/commands', $changed)->assertConflict();
        $this->postJson('/api/data-rights/commands', $issue)->assertOk()->assertExactJson($first);
        $this->assertSame(0, $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('source_operation', 'discovery.read')->where('action', 'source.read')->count());
        $changed['command_id'] = (string) Str::uuid();
        $second = $this->postJson('/api/data-rights/commands', $changed)->assertOk()->json('data.grant_id');
        $this->assertNotSame($first['data']['grant_id'], $second);
        $this->assertSame('discovery.read', $this->owner()->table('security_action_grants')->where('id', $second)->value('source_operation'));
    }

    public function test_adopted_source_declares_only_its_independently_qualified_new_capability_through_the_native_writer(): void
    {
        $this->command($this->input)->assertOk();
        $this->grantFixture('source.manage', ['source_operation' => 'discovery.read']);
        $adopt = $this->adoptionBody();
        $this->postJson('/api/data-rights/commands', $adopt)->assertOk();
        $access = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.manage')->where('source_operation', 'discovery.read')->value('id');
        $url = '/api/source-rights/'.$this->input['source_id'].'?access='.$access;
        $this->getJson('/api/source-rights')->assertOk()->assertJsonCount(2, 'data.sources');
        $this->getJson($url)->assertOk()->assertJsonPath('data.source.scope_declared', false);
        $declare = [...$adopt, 'command_id' => (string) Str::uuid(), 'effect' => 'declare_scope', 'payload' => ['reason' => 'explicit_native_scope']];
        $declare['authority_selection']['requirements'][0]['leaf_grant_id'] = $access;
        $first = $this->postJson('/api/data-rights/commands', $declare)->assertOk()->assertJsonPath('data.versions.source', 2)->json();
        $this->assertApplicationContract('BusinessResourceRightResult', $first);
        $this->postJson('/api/data-rights/commands', $declare)->assertOk()->assertExactJson($first);
        $this->getJson($url)->assertOk()->assertJsonPath('data.source.scope_declared', true)->assertJsonPath('data.source.operation', 'discovery.read');
        $this->postJson('/api/data-rights/commands', [...$declare, 'command_id' => (string) Str::uuid(), 'expected_revision' => 2, 'payload' => ['reason' => 'explicit_native_scope', 'operation' => 'unauthorized.scope']])->assertUnprocessable();
        $this->command($this->transition('declare_scope', 2, ['operation' => 'discovery.read']))->assertForbidden();
        $this->assertSame(2, $this->owner()->table('source_capabilities')->count());
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
        $this->assertSame(2, $this->owner()->table('source_audit_obligations')->count());
        $this->assertSame(0, $this->owner()->table('businesses')->count());
        $this->assertSame(0, $this->owner()->table('data_use_scopes')->count());
    }

    private function adoptionBody(): array
    {
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.manage')->value('id');

        return ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $this->input['source_id'], 'effect' => 'adopt',
            'payload' => (object) [], 'expected_revision' => 1, 'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $grant]]]];
    }

    public function test_adopted_source_native_owner_issues_revokes_and_reissues_an_exact_right_atomically(): void
    {
        $this->command($this->input)->assertOk();
        $adopt = $this->adoptionBody();
        $this->postJson('/api/data-rights/commands', $adopt)->assertOk();
        $read = $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->sole();
        $revoke = [...$adopt, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'payload' => ['grant_id' => (string) $read->id]];
        $this->postJson('/api/data-rights/commands', $revoke)->assertOk();
        $issue = [...$adopt, 'command_id' => (string) Str::uuid(), 'effect' => 'qualify_issue', 'expected_revision' => 0,
            'payload' => ['action' => 'source.read', 'reason' => 'explicit_native_owner', 'expires_at' => now()->addHour()->toIso8601String()]];
        $issued = $this->postJson('/api/data-rights/commands', $issue)->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertApplicationContract('BusinessResourceRightResult', $issued->json());
        $this->postJson('/api/data-rights/commands', $issue)->assertOk()->assertExactJson($issued->json());
        $id = $issued->json('data.grant_id');
        $this->assertNotSame($read->id, $id);
        $grant = $this->owner()->table('security_action_grants')->where('id', $id)->sole();
        $this->assertSame($this->actor->id, $grant->subject_user_id);
        $this->assertSame($this->actor->id, $grant->granted_by);
        $this->assertNull($grant->business_id);
        $this->assertNull($grant->qualification_id);
        $this->assertNull($grant->parent_grant_id);
        $this->assertSame($this->input['resource'], $grant->source_resource);
        $this->assertSame(3, $this->owner()->table('resource_right_fences')->value('rights_epoch'));
        $duplicate = [...$issue, 'command_id' => (string) Str::uuid()];
        $this->postJson('/api/data-rights/commands', $duplicate)->assertConflict();
        $duplicate['payload']['business_public_id'] = (string) Str::uuid7();
        $this->postJson('/api/data-rights/commands', $duplicate)->assertUnprocessable();
        $revoke['command_id'] = (string) Str::uuid();
        $revoke['payload']['grant_id'] = (string) $id;
        $this->postJson('/api/data-rights/commands', $revoke)->assertOk();
        $issue['command_id'] = (string) Str::uuid();
        $this->postJson('/api/data-rights/commands', $issue)->assertOk();
        $this->assertSame('revoked', $this->owner()->table('security_action_grants')->where('id', $id)->value('status'));
        $this->assertSame(1, $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->where('status', 'active')->count());
        $this->assertSame(5, $this->owner()->table('resource_right_fences')->value('rights_epoch'));
    }

    public function test_adoption_drains_a_real_native_writer_before_accepting_its_committed_source_version(): void
    {
        $this->assertAdoptionWait(false);
    }

    public function test_adoption_rechecks_withdrawn_manager_after_its_native_admission_wait(): void
    {
        $this->assertAdoptionWait(true);
    }

    public function test_late_native_provider_admission_denies_instead_of_waiting_backwards_behind_adoption(): void
    {
        $this->assertAdoptionWait(false, true);
    }

    public function test_adoption_rejects_a_selected_grant_retargeted_to_another_source_during_the_wait(): void
    {
        $this->assertAdoptionWait(true, identityChange: 'source');
    }

    public function test_adoption_rejects_a_selected_manager_downgraded_to_read_during_the_wait(): void
    {
        $this->assertAdoptionWait(true, identityChange: 'action');
    }

    public function test_source_adoption_rolls_back_if_owner_freshness_expires_at_the_final_receipt_wait(): void
    {
        $this->assertSourceFinalFreshness('adopt');
    }

    public function test_source_issuance_rolls_back_new_grant_and_epoch_if_freshness_expires_at_the_final_receipt_wait(): void
    {
        $this->assertSourceFinalFreshness('qualify_issue');
    }

    public function test_protected_source_retirement_preserves_native_history_and_records_one_audit_obligation(): void
    {
        $this->command($this->input)->assertOk();
        $adopt = $this->adoptionBody();
        $this->postJson('/api/data-rights/commands', $adopt)->assertOk();
        $retire = [...$adopt, 'command_id' => (string) Str::uuid(), 'effect' => 'retire', 'payload' => ['reason' => 'explicit_native_retirement']];
        $result = $this->postJson('/api/data-rights/commands', $retire)->assertOk()
            ->assertJsonPath('data.versions.source', 2)->assertJsonPath('data.status', 'REVOKED')->assertJsonPath('data.native_receipt.local_audit', 'DURABLE');
        $this->assertApplicationContract('BusinessResourceRightResult', $result->json());
        $this->postJson('/api/data-rights/commands', $retire)->assertOk()->assertExactJson($result->json());
        $db = $this->owner();
        foreach (['source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery'] as $table) {
            $this->assertSame(2, $db->table($table)->count());
        }
        $this->assertSame(1, $db->table('source_registry')->count());
        $this->assertSame(1, $db->table('source_capabilities')->count());
        $this->assertSame(2, $db->table('resource_right_fences')->value('rights_epoch'));
        $this->assertSame(4, $db->table('security_action_grants')->where('scope_kind', 'source')->where('status', 'active')->count()); // Metadata retirement does not silently revoke independent licences.
        $this->assertSame(1, $db->table('source_versions')->where('version', 1)->count());
        $retire['command_id'] = (string) Str::uuid();
        $retire['expected_revision'] = 2;
        $this->postJson('/api/data-rights/commands', $retire)->assertUnprocessable();
        $this->assertSame(2, $db->table('source_versions')->count());
        $this->command($this->transition('rename', 2, ['label' => 'Cannot revive native source']))->assertForbidden();
        $this->denied(fn () => $db->table('source_audit_obligations')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $this->workspaceId,
            'source_id' => $this->input['source_id'], 'command_id' => $this->input['command_id'], 'envelope' => '{}', 'fingerprint' => str_repeat('0', 64)]), '42501');
    }

    public function test_source_retirement_rolls_back_facts_history_and_audit_if_freshness_expires_at_the_final_receipt_wait(): void
    {
        $this->assertSourceFinalFreshness('retire');
    }

    public function test_retired_source_can_coordinate_existing_rights_and_authorize_cleanup_without_reviving_registration(): void
    {
        $this->command($this->input)->assertOk();
        $this->command($this->transition('revoke', 1))->assertOk();
        $body = [...$this->adoptionBody(), 'expected_revision' => 2];
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.versions.source', 2);
        $issue = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'qualify_issue', 'expected_revision' => 0,
            'payload' => ['action' => 'provider.revoke', 'reason' => 'explicit_local_custody_cleanup', 'expires_at' => null]];
        $this->postJson('/api/data-rights/commands', $issue)->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('REVOKED', $this->owner()->table('source_registry')->value('status'));
        $this->assertSame(2, $this->owner()->table('source_registry')->value('version'));
        $this->assertSame(2, $this->owner()->table('source_versions')->count());
        $this->assertSame(0, $this->owner()->table('businesses')->count());
        $this->assertSame(0, $this->owner()->table('security_source_audit_custody')->count());
    }

    private function assertSourceFinalFreshness(string $effect): void
    {
        $this->command($this->input)->assertOk();
        $body = $this->adoptionBody();
        $db = $this->owner();
        if ($effect !== 'adopt') {
            $this->postJson('/api/data-rights/commands', $body)->assertOk();
        }
        if ($effect === 'retire') {
            $body = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'retire', 'payload' => ['reason' => 'native_freshness_test']];
        } elseif ($effect === 'rename') {
            $body = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'rename', 'payload' => ['label' => 'Must roll back after wait', 'reason' => 'native_freshness_test']];
        } elseif ($effect === 'qualify_issue') {
            $read = $db->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->value('id');
            $this->postJson('/api/data-rights/commands', [...$body, 'command_id' => (string) Str::uuid(), 'effect' => 'revoke', 'payload' => ['grant_id' => (string) $read]])->assertOk();
            $body = [...$body, 'command_id' => (string) Str::uuid(), 'effect' => $effect, 'expected_revision' => 0,
                'payload' => ['action' => 'source.read', 'reason' => 'native_freshness_test', 'expires_at' => null]];
        }
        $run = 'source-final-'.$effect.'-'.Str::uuid();
        $file = storage_path('framework/testing/'.$run.'.json');
        $release = $file.'.release';
        file_put_contents($file, json_encode(['actor' => $this->actor->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($this->request()),
            'command' => $body['command_id'], 'release' => $release, 'operation' => 'resource', 'kind' => 'source', 'target' => $body['target_ref'],
            'effect' => $effect, 'payload' => (object) $body['payload'], 'expected' => $body['expected_revision'], 'selection' => $body['authority_selection']], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $snapshot = fn () => array_map(fn ($table) => $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM {$table} r"),
            ['security_action_grants', 'resource_right_fences', 'source_registry', 'source_capabilities', 'source_command_receipts', 'source_versions', 'source_audit_obligations', 'source_audit_delivery']);
        $before = $snapshot();
        $wait = function (callable $probe) use ($worker): mixed {
            $deadline = microtime(true) + 15;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Source final-wait worker exited: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Actual source final receipt wait was not observed.');
        };
        $evidence = ['run' => $run, 'effect' => $effect];
        try {
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            $db->table('security_sessions')->where('user_id', $this->actor->id)
                ->update(['authenticated_at' => DB::raw("clock_timestamp()-interval '15 minutes'+interval '2 seconds'")]);
            $until = $db->scalar("SELECT (authenticated_at+interval '15 minutes')::text FROM security_sessions WHERE user_id=? ORDER BY authenticated_at DESC LIMIT 1", [$this->actor->id]);
            $db->beginTransaction();
            $db->statement('LOCK TABLE business_command_receipts IN SHARE MODE');
            file_put_contents($release, 'release');
            $evidence['waiter'] = $wait(function () use ($run) {
                return DB::selectOne("SELECT a.pid,a.backend_xid::text AS xid,a.wait_event_type,a.wait_event,pg_blocking_pids(a.pid)::text AS blockers,l.mode,l.relation::regclass::text AS relation
                    FROM pg_stat_activity a JOIN pg_locks l ON l.pid=a.pid WHERE a.application_name=? AND a.wait_event_type='Lock' AND l.relation='business_command_receipts'::regclass AND NOT l.granted", [$run]);
            });
            $this->assertSame('RowExclusiveLock', $evidence['waiter']->mode);
            $wait(fn () => (bool) DB::scalar('SELECT clock_timestamp()>?::timestamptz', [$until]));
            $db->rollBack();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertStringContainsString('Current native source ownership, session and exact grant required', $worker->getOutput());
            $this->assertSame($before, $snapshot());
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$file, $release] as $owned) {
                if (is_file($owned)) {
                    unlink($owned);
                }
            }
        }
    }

    private function assertAdoptionWait(bool $withdraw, bool $lateNativeAdmission = false, ?string $identityChange = null): void
    {
        $this->command($this->input)->assertOk();
        $body = $this->adoptionBody();
        if ($identityChange === 'action') {
            // The disposable deployment role models an independent pre-adoption administrative change.
            $this->owner()->table('security_action_grants')->where('source_target_id', $this->input['source_id'])->where('action', 'source.read')->delete();
        }
        $request = $this->request();
        $run = 'source-adoption-'.Str::uuid();
        $dir = storage_path('framework/testing');
        $file = $dir.'/'.$run.'.json';
        $release = $dir.'/'.$run.'.release';
        file_put_contents($file, json_encode(['actor' => $this->actor->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($request),
            'command' => $body['command_id'], 'release' => $release, 'operation' => 'resource', 'kind' => 'source', 'target' => $body['target_ref'],
            'effect' => 'adopt', 'payload' => [], 'expected' => $withdraw || $lateNativeAdmission ? 1 : 2, 'selection' => $body['authority_selection']], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = ['run' => $run, 'withdrawn_while_waiting' => $withdraw, 'late_native_admission' => $lateNativeAdmission, 'identity_change' => $identityChange];
        $wait = function (callable $probe) use ($worker): mixed {
            $deadline = microtime(true) + 25;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Source adoption worker exited: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Actual source adoption admission wait was not observed.');
        };
        try {
            $observer->getPdo();
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            DB::beginTransaction();
            app(PrincipalContext::class)->activate($this->actor);
            if (! $lateNativeAdmission) {
                DB::selectOne('SELECT builderad_v2_native_source_enter(?::uuid,?::uuid)', [$this->workspace, $this->input['source_id']]);
            }
            $holder = DB::scalar('SELECT pg_backend_pid()');
            if (! $withdraw) {
                DB::selectOne('SELECT builderad_v2_legacy_enter(?::uuid)', [$this->workspace]);
                DB::selectOne('SELECT builderad_select_workspace_context(?)', [$this->workspaceId]);
                if ($lateNativeAdmission) {
                    $this->assertNotNull(DB::selectOne('SELECT id FROM security_action_grants WHERE id=? FOR UPDATE', [$body['authority_selection']['requirements'][0]['leaf_grant_id']]));
                }
                if (! $lateNativeAdmission) {
                    DB::scalar('SELECT builderad_source_command(?,?,?::uuid,?::uuid,?,?::jsonb)', [$this->workspaceId, SessionAuthority::sessionKey($request),
                        (string) Str::uuid(), $this->input['source_id'], 'rename', json_encode(['resource' => $this->input['resource'], 'operation' => $this->input['operation'],
                            'purpose' => $this->input['purpose'], 'expected_version' => 1, 'reason' => 'native_drain_test', 'label' => 'Committed before adoption'])]);
                }
            }
            file_put_contents($release, 'release');
            $evidence['waiter'] = $wait(function () use ($observer, $run, $holder, $lateNativeAdmission) {
                $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);

                return $row?->wait_event_type === 'Lock' && ($lateNativeAdmission || $row->wait_event === 'advisory') && str_contains($row->blockers, (string) $holder) ? $row : false;
            });
            if ($lateNativeAdmission) {
                $evidence['adoption_gate'] = $observer->select("SELECT mode,granted FROM pg_locks WHERE pid=? AND locktype='advisory' AND mode='ExclusiveLock' AND granted", [$evidence['waiter']->pid]);
                $this->assertNotEmpty($evidence['adoption_gate']);
                $this->denied(fn () => DB::transaction(fn () => DB::scalar('SELECT builderad_v2_legacy_source_grant_guard(?::uuid)', [$this->input['source_id']])), '42501');
            }
            if ($withdraw) {
                $this->owner()->table('security_action_grants')->where('id', $body['authority_selection']['requirements'][0]['leaf_grant_id'])
                    ->update(match ($identityChange) {
                        'source' => ['source_target_id' => (string) Str::uuid7(), 'version' => 2, 'updated_at' => now()],
                        'action' => ['action' => 'source.read', 'version' => 2, 'updated_at' => now()],
                        default => ['status' => 'revoked', 'revoked_at' => now(), 'version' => 2, 'updated_at' => now()],
                    });
            }
            DB::commit();
            $worker->wait();
            $this->assertSame($withdraw ? 2 : 0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString($withdraw ? 'DENIED_STATE=42501' : 'COMMITTED=', $worker->getOutput());
            $this->assertSame($withdraw ? 0 : 1, $this->owner()->table('resource_right_fences')->count());
            $this->assertSame($withdraw ? 0 : 1, $this->owner()->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
            if (! $withdraw && ! $lateNativeAdmission) {
                $this->assertSame('Committed before adoption', $this->owner()->table('source_registry')->value('label'));
                $this->assertSame(2, $this->owner()->table('source_versions')->count());
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($dir.'/'.$run.'-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $observer->disconnect();
            foreach ([$file, $release] as $owned) {
                if (is_file($owned)) {
                    unlink($owned);
                }
            }
        }
    }

    public function test_protected_adopted_source_revoke_uses_its_exact_native_contract_and_preserves_independent_rights(): void
    {
        $this->command($this->input)->assertOk();
        $source = $this->input['source_id'];
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.read')->first();
        $other = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.manage')->first();
        $fence = BusinessAuthorityFixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source',
            'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $this->actor->id);
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $grant->id]]];
        $body = ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $source, 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $other->id], 'expected_revision' => 1, 'authority_selection' => $selection];
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->assertSame(0, $this->owner()->table('resource_right_fences')->where('fence_id', $fence->fence_id)->value('rights_epoch'));
        $bad = $body;
        $bad['payload']['grant_id'] = (string) $grant->id;
        $bad['authority_selection']['requirements'][0]['requirement_kind'] = 'business';
        $this->postJson('/api/data-rights/commands', $bad)->assertForbidden();
        $bad = $body;
        $bad['target_ref'] = (string) Str::uuid7();
        $this->postJson('/api/data-rights/commands', $bad)->assertForbidden();
        foreach (['not-an-id', '9223372036854775808', []] as $malformed) {
            $bad = $body;
            $bad['payload']['grant_id'] = (string) $grant->id;
            $bad['authority_selection']['requirements'][0]['leaf_grant_id'] = $malformed;
            $this->postJson('/api/data-rights/commands', $bad)->assertForbidden();
        }
        $body['payload']['grant_id'] = (string) $grant->id;
        $body['expected_revision'] = 2;
        $this->postJson('/api/data-rights/commands', $body)->assertConflict();
        $body['expected_revision'] = 1;
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()]);
        $result = $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.versions.grant', 2);
        $this->assertApplicationContract('BusinessResourceRightResult', $result->json());
        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        $this->assertSame(1, $this->owner()->table('resource_right_fences')->where('fence_id', $fence->fence_id)->value('rights_epoch'));
        $this->assertSame('active', $this->owner()->table('security_action_grants')->where('id', $other->id)->value('status'));
        $this->assertSame('UNVERIFIED', $this->owner()->table('source_registry')->where('id', $source)->value('status'));
        $receipt = $this->owner()->table('business_command_receipts')->where('command_id', $body['command_id'])->sole();
        $this->assertNull($receipt->business_id);
        $this->assertSame('resource.revoke', $receipt->action);
        $this->assertSame(0, $this->owner()->table('businesses')->count());
        $this->assertSame(0, $this->owner()->table('authority_qualification_receipts')->count());
        $this->assertSame(0, $this->owner()->table('security_action_grants')->whereIn('scope_kind', ['business', 'client_data', 'data_use'])->count());
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        // Explicit reconciliation uses a different currently authorized path; the server never chooses a fallback.
        $body['authority_selection']['requirements'][0]['leaf_grant_id'] = $other->id;
        $this->postJson('/api/data-rights/commands', $body)->assertOk()->assertExactJson($result->json());
        $this->assertSame(1, $this->owner()->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        $body['expected_revision'] = 2;
        $this->postJson('/api/data-rights/commands', $body)->assertConflict();
        $body['expected_revision'] = 1;
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()->subHour()]);
        $this->postJson('/api/data-rights/commands', $body)->assertForbidden();
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['authenticated_at' => now()]);
        $body['command_id'] = (string) Str::uuid();
        $this->postJson('/api/data-rights/commands', $body)->assertConflict();
        $this->assertSame('active', $this->owner()->table('security_action_grants')->where('id', $other->id)->value('status'));
        $this->assertSame(1, $this->owner()->table('resource_right_fences')->where('fence_id', $fence->fence_id)->value('rights_epoch'));
    }

    public function test_source_right_revoke_does_not_take_a_business_or_cohort_lock(): void
    {
        $this->command($this->input)->assertOk();
        $source = $this->input['source_id'];
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.read')->first();
        BusinessAuthorityFixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source',
            'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $this->actor->id);
        $a = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Unrelated Business'])->assertCreated()->json('data');
        $b = $this->owner()->table('businesses')->where('public_id', $a['business_public_id'])->value('id');
        $this->owner()->beginTransaction();
        try {
            $this->owner()->selectOne('SELECT business_id FROM business_authority_fences WHERE business_id=? FOR UPDATE', [$b]);
            $this->owner()->selectOne('SELECT c.cohort_id FROM migration_cohort_control c JOIN migration_cohort_members m ON m.cohort_id=c.cohort_id WHERE m.business_id=? FOR UPDATE OF c', [$b]);
            $this->postJson('/api/data-rights/commands', ['command_id' => (string) Str::uuid(), 'target_kind' => 'source', 'target_ref' => $source, 'effect' => 'revoke',
                'payload' => ['grant_id' => (string) $grant->id], 'expected_revision' => 1,
                'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                    'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $grant->id]]]])->assertOk()->assertJsonPath('data.status', 'revoked');
        } finally {
            $this->owner()->rollBack();
        }
        $this->assertSame(0, $this->owner()->table('business_authority_fences')->where('business_id', $b)->value('auth_epoch'));
        $this->assertSame(0, $this->owner()->table('business_context_transitions')->count());
        $this->assertSame(7, $this->owner()->table('security_action_grants')->where('scope_kind', 'business')->where('status', 'active')->count());
        $this->assertSame('revoked', $this->owner()->table('security_action_grants')->where('id', $grant->id)->value('status'));
    }

    public function test_source_revoke_commits_before_a_queued_old_authority_attempt_which_then_denies_without_a_second_effect(): void
    {
        $this->command($this->input)->assertOk();
        $source = $this->input['source_id'];
        $grant = $this->owner()->table('security_action_grants')->where('source_target_id', $source)->where('action', 'source.read')->first();
        $fence = BusinessAuthorityFixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source',
            'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $this->actor->id);
        $r = $this->request();
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => $grant->id]]];
        $run = 'adopted-source-revoke-'.Str::uuid();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $release = $dir.'/'.$run.'.release';
        $file = $dir.'/'.$run.'.json';
        file_put_contents($file, json_encode(['actor' => $this->actor->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($r),
            'command' => (string) Str::uuid(), 'release' => $release, 'operation' => 'resource', 'kind' => 'source', 'target' => $source, 'effect' => 'revoke',
            'payload' => ['grant_id' => (string) $grant->id], 'expected' => 1, 'selection' => $selection], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = ['run' => $run];
        $wait = function (callable $probe) use ($worker) {
            $deadline = microtime(true) + 25;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    $this->fail('Participant stopped before required source wait: '.$worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->fail('Actual adopted-source authority wait was not observed.');
        };
        try {
            $observer->getPdo();
            $worker->start();
            $wait(fn () => str_contains($worker->getOutput(), 'READY_PID='));
            DB::beginTransaction();
            app(PrincipalContext::class)->activate($this->actor);
            $holder = DB::scalar('SELECT pg_backend_pid()');
            DB::scalar("SELECT builderad_v2_resource_right_command(?,?::uuid,'source',?::uuid,'revoke',?::jsonb,1,?::jsonb)",
                [SessionAuthority::sessionKey($r), (string) Str::uuid(), $source, json_encode(['grant_id' => (string) $grant->id]), json_encode($selection)]);
            file_put_contents($release, 'release');
            $evidence['waiter'] = $wait(function () use ($observer, $run, $holder) {
                $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);

                return $row?->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $holder) ? $row : false;
            });
            DB::commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame(1, $this->owner()->table('resource_right_fences')->where('fence_id', $fence->fence_id)->value('rights_epoch'));
            $this->assertSame(2, $this->owner()->table('security_action_grants')->where('id', $grant->id)->value('version'));
            $this->assertSame(1, $this->owner()->table('business_command_receipts')->where('action', 'resource.revoke')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($dir.'/'.$run.'-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $observer->disconnect();
            foreach ([$release, $file] as $ownedFile) {
                if (is_file($ownedFile)) {
                    unlink($ownedFile);
                }
            }
        }
    }

    private function workspaceFixture(): array
    {
        $user = User::factory()->create();
        $id = $this->owner()->table('workspaces')->insertGetId(['name' => 'Synthetic source Workspace',
            'slug' => 'bb1-'.Str::uuid(), 'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('workspace_memberships')->insert(['workspace_id' => $id, 'user_id' => $user->id,
            'role' => 'owner', 'status' => 'active', 'created_at' => now()]);
        $this->owner()->table('workspace_ownerships')->insert(['workspace_id' => $id, 'responsible_owner_user_id' => $user->id,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->owner()->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'source' => 'system_workspace_creation',
            'scope_kind' => 'workspace', 'workspace_id' => $id, 'action' => 'workspace.view', 'subject_generation' => 1,
            'status' => 'active', 'reason' => 'synthetic_fixture', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $id, $this->owner()->table('workspaces')->where('id', $id)->value('public_id')];
    }

    private function grantFixture(string $action, array $overrides = []): void
    {
        $this->owner()->table('security_action_grants')->insert([
            'subject_user_id' => $this->actor->id, 'granted_by' => $this->actor->id, 'source' => 'source_owner_request',
            'scope_kind' => 'source', 'workspace_id' => $this->workspaceId, 'source_target_id' => $this->input['source_id'],
            'source_resource' => $this->input['resource'], 'source_operation' => $this->input['operation'], 'source_purpose' => $this->input['purpose'],
            'action' => $action, 'subject_generation' => 1, 'status' => 'active', 'reason' => 'synthetic_source_fixture',
            'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);
    }

    private function grantInput(string $action): array
    {
        return array_intersect_key($this->input, array_flip(['source_id', 'resource', 'operation', 'purpose', 'reason']))
            + ['action' => $action, 'decision' => 'authorize'];
    }

    private function sourceProjection(string $path, callable $assertion): void
    {
        $this->getJson($path)->assertOk()->assertJson(fn (Assert $page) => $page
            ->has('data', function (Assert $data) use ($assertion): void {
                $assertion($data);
                $data->etc();
            })->etc());
    }

    private function transition(string $effect, int $version, array $extra = []): array
    {
        return [...array_intersect_key($this->input, array_flip(['source_id', 'resource', 'operation', 'purpose', 'reason'])),
            'command_id' => (string) Str::uuid(), 'effect' => $effect, 'expected_version' => $version, ...$extra];
    }

    private function command(array $input)
    {
        return $this->postJson('/workspaces/'.$this->workspace.'/sources', $input);
    }

    private function request(): Request
    {
        $request = Request::create('/workspaces/'.$this->workspace);
        $request->setUserResolver(fn () => $this->actor);
        $request->setLaravelSession($this->app['session']->driver());

        return $request;
    }

    private function owner()
    {
        return DB::connection('pgsql_migrations');
    }

    private function denied(callable $operation, string $state): void
    {
        try {
            $operation();
            $this->fail('Forbidden database operation was accepted.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->getCode());
        }
    }
}
