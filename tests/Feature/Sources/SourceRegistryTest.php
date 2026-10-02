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
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SourceRegistryTest extends TestCase
{
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
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('sources.items', 6)->where('sources.items.0.identity_verification', 'UNVERIFIED')
            ->where('sources.items.0.capability.state', 'UNKNOWN')->where('sources.items.0.capability.last_verified_at', null)
            ->where('sources.items.0.axes.provider_permission', 'UNKNOWN')->where('sources.items.0.audit_delivery.0.state', 'PENDING'));
        $this->command([...$this->input, 'provider_permission' => 'VERIFIED'])->assertUnprocessable();
        $this->command([...$this->input, 'token' => 'never_store'])->assertUnprocessable();
    }

    public function test_workspace_presents_stable_opaque_session_binding_without_granting_source_authority(): void
    {
        $sessionId = $this->app['session']->driver()->getId();
        $expected = hash_hmac('sha256', $sessionId, (string) config('app.key'));
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.sessionContextId', $expected)->where('sources.state', 'NO_VISIBLE_SOURCE_AUTHORITY'));
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.sessionContextId', $expected));
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
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p
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
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p->has('sources.items', 1));
        $this->grantFixture('source.read', ['source_operation' => 'discovery.read']);
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p->has('sources.items', 2));
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
        $this->assertContains($this->command($this->input)->status(), [401, 403]);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['absolute_expires_at' => now()->addHour()]);
        $this->actingAs($this->actor);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['revoked_at' => now()]);
        $this->assertContains($this->command($this->input)->status(), [401, 403]);
        $this->owner()->table('security_sessions')->where('user_id', $this->actor->id)->update(['revoked_at' => null]);
        $this->actingAs($this->actor);
        $this->owner()->table('principal_lifecycles')->where('user_id', $this->actor->id)->update(['generation' => 2]);
        $this->assertContains($this->command($this->input)->status(), [401, 403]);
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
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p->has('sources.items', 1)
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
        $this->get('/workspaces/'.$public)->assertInertia(fn (Assert $p) => $p->has('sources.items', 1));
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
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(fn (Assert $p) => $p
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
            $holder->waitUntil(fn ($type, $text) => str_contains($text, 'LOCK_READY'));
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
        $this->get('/workspaces/'.$this->workspace)->assertOk()->assertInertia(function (Assert $p) use ($a, $b): void {
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
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p
            ->where('sources.items.0.audit_delivery.1.can_recover', false)
            ->where('sources.items.0.audit_delivery.1.recovery_reason', 'already_acknowledged')
            ->where('sources.items.0.capability.state', 'REVOKED')->has('sources.items.0.scope_choices', 0));
    }

    public function test_recovery_projection_does_not_authorize_effect_or_leak_across_workspaces(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p->where('sources.items.0.audit_delivery.0.can_recover', true));
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
        $this->get('/workspaces/'.$public)->assertInertia(fn (Assert $p) => $p->has('sources.items', 0));
        $this->postJson('/workspaces/'.$public.'/source-audit/'.$receipt['obligation_id'].'/recover')->assertForbidden();
        $this->actingAs($this->actor);
        $this->owner()->table('principal_lifecycles')->where('user_id', $this->actor->id)->update(['generation' => 2]);
        $this->assertContains($this->postJson($this->recoveryUrl($receipt['obligation_id']))->status(), [401, 403]);
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
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p
            ->where('sources.items.0.scope_choices', [$scope])->where('sources.items.0.version', 1));
        $this->grantFixture('source.read', ['source_resource' => $scope['resource'], 'source_operation' => $scope['operation'], 'source_purpose' => $scope['purpose']]);
        $input = $this->transition('declare_scope', 1, $scope);
        $receipt = $this->command($input)->assertOk()->assertJsonPath('version', 2)->json();
        $this->command($input)->assertExactJson($receipt);
        $this->command([...$input, 'reason' => 'changed_payload'])->assertConflict();
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p
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
        $this->get('/workspaces/'.$this->workspace)->assertInertia(fn (Assert $p) => $p->has('sources.items.0.scope_choices', 0)
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
        $deadline=microtime(true)+6; $wait=false;
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
        $holder->setTimeout(12);
        $holder->start();
        try {
            $holder->waitUntil(fn ($type, $text) => str_contains($text, 'LOCK_READY'));
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
                $p->setTimeout(15);
                $p->start();
                $processes[] = $p;
            }
            $deadline = microtime(true) + 8;
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
