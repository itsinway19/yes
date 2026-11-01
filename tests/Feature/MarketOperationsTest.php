<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketProcessingFixture;
use Tests\TestCase;

final class MarketOperationsTest extends TestCase
{
    use MarketProcessingFixture;

    private const PATH = '/internal/administration/market-knowledge';

    private const ACTION = 'market.knowledge.observe';

    private function observer(bool $grant = true, string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(), 'factor_independence_attested_at' => now()]);
        foreach ([1, 2] as $key) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'SYNTHETIC', 'credential_id' => $user->id.'-'.$key, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($grant) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'subject_generation' => 1, 'source' => 'founder_bootstrap',
                'action' => self::ACTION, 'scope_kind' => 'platform', 'reason' => 'SYNTHETIC explicit operational metadata grant',
                'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    public function test_qualified_observer_receives_only_explicit_source_metadata_and_actual_method_availability(): void
    {
        $source = $this->marketSource();
        $this->actingAsInternal($this->observer());
        $response = $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.connection_status', 'not_observed')
            ->assertJsonPath('data.private_processing', 'business_scoped')->assertJsonCount(1, 'data.sources')->assertJsonCount(5, 'data.methods');
        $this->assertSame($source, $response->json('data.sources.0.source_ref'));
        $this->assertEqualsCanonicalizing(['source_ref', 'custody_revision', 'state', 'rights_window', 'valid_until'], array_keys($response->json('data.sources.0')));
        $this->assertSame('current', $response->json('data.sources.0.rights_window'));
        foreach (['test:synthetic-licence-only', 'test.synthetic.market', 'SYNTHETIC MARKET', 'NOT REAL DEMAND', 'allowed_fields', 'evidence_digest', 'actor_user_id'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $html = $this->get(self::PATH, ['Accept' => 'text/html'])->assertOk();
        $this->assertContains(self::PATH, $html->viewData('bootstrap')['navigation']);
    }

    public function test_general_staff_founder_and_advertiser_do_not_inherit_operational_access(): void
    {
        $founder = $this->observer(false, 'founder');
        app(GrantAuthority::class)->issueFounderBootstrapGrants($founder, 1);
        $this->actingAsInternal($founder)->getJson(self::PATH)->assertForbidden();
        $employee = $this->observer(false);
        $this->actingAsInternal($employee)->getJson(self::PATH)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson(self::PATH)->assertConflict();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $founder->id)->where('action', self::ACTION)->count());
    }

    public function test_expired_or_revoked_observer_cannot_read_even_a_direct_api_request(): void
    {
        $this->actingAsInternal($user = $this->observer());
        $this->getJson(self::PATH)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $db->table('security_action_grants')->where('subject_user_id', $user->id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson(self::PATH)->assertForbidden();
        $db->table('security_action_grants')->where('subject_user_id', $user->id)->update(['expires_at' => null, 'status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson(self::PATH)->assertForbidden();
    }

    public function test_private_business_execution_and_result_do_not_change_the_operational_projection(): void
    {
        $originals = $this->processingOriginals();
        $signals = $this->processingOriginals('signal');
        $record = $signals['records'][0];
        $payload = $record['payload'];
        $payload['fields']['value'] = 0;
        $right = $this->marketRight($signals['writer'], $record['source'], $record['evidence'], 'correct', array_keys($payload['fields']));
        $signals['records'][0]['ref'] = $this->writeEvidence($record['evidence'], $right, $payload, 1)->assertOk()->json('data.material_ref');
        $signals['records'][0]['payload'] = $payload;
        $a = $this->processingConsumer($originals);
        $b = $this->processingConsumer($signals);
        $observer = $this->observer();
        $this->actingAsInternal($observer);
        $before = $this->getJson(self::PATH)->assertOk()->json('data');
        foreach ([[$a, $originals, 'compare_offers', 'validated'], [$b, $signals, 'compare_interest', 'abstained'], [$a, $originals, 'compare_offers', 'validated']] as [$consumer, $inputs, $question, $state]) {
            $this->actingAs($consumer['user']);
            $body = $this->processingBody($inputs, $consumer, $question);
            $this->prepareProcessing($consumer, $body)->assertOk();
            $this->completeProcessing($consumer, $body['command_id'])->assertOk()->assertJsonPath('data.state', $state);
        }
        $this->actingAsInternal($observer);
        $after = $this->getJson(self::PATH)->assertOk()->json('data');
        $this->assertSame($before, $after, 'Private executions, results, owners and timestamps must not affect the operational projection.');
        foreach ([$a, $b] as $consumer) {
            $this->assertStringNotContainsString($consumer['business']['business_public_id'], json_encode($after));
            $this->assertStringNotContainsString($consumer['user']->public_id, json_encode($after));
        }
    }

    public function test_populated_470_upgrade_preserves_private_results_and_rejects_unqualified_catalog_drift(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => strcmp(basename($p), '0001_01_01_000480_create_market_operations.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $originals = $this->processingOriginals();
            $consumer = $this->processingConsumer($originals);
            $body = $this->processingBody($originals, $consumer);
            $this->prepareProcessing($consumer, $body)->assertOk();
            $this->completeProcessing($consumer, $body['command_id'])->assertOk();
            $db = DB::connection('pgsql_migrations');
            $before = [];
            foreach (['market_evidence_revisions', 'market_processing_runs', 'security_action_grants', 'business_command_receipts', 'platform_maintenance'] as $table) {
                $before[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]')::text FROM ".$table.' t');
            }
            $db->unprepared("CREATE FUNCTION public.market_ops_upgrade_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
                $this->fail('Unqualified drift must not be promoted into the operations installation.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('MARKET_OPERATIONS_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $error->getMessage());
                $this->assertNull($db->scalar("SELECT to_regprocedure('builderad_v2_market_operations_read(text,uuid)')::text"));
            } finally {
                $db->statement('DROP FUNCTION public.market_ops_upgrade_drift()');
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($before as $table => $snapshot) {
                $this->assertSame($snapshot, $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]')::text FROM ".$table.' t'));
            }
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
            });
            $this->assertFalse($db->scalar("SELECT has_table_privilege('builderad_app','market_source_custody','SELECT')"));
            $this->assertFalse($db->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertSame('builderad_owner', $db->scalar("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid='builderad_private.market_operator(text,text,boolean)'::regprocedure"));
            foreach (['market_knowledge_captures', 'market_business_finding_captures', 'market_knowledge_completion_tickets'] as $table) {
                $this->assertSame(0, $db->table($table)->count(), 'An upgrade must not seed knowledge or execution rights.');
                $this->assertTrue($db->scalar('SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]));
                $this->assertFalse($db->scalar("SELECT has_table_privilege('builderad_app',?,'SELECT,INSERT,UPDATE,DELETE')", [$table]));
                $this->assertSame('builderad_owner', $db->scalar('SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid=?::regclass', [$table]));
            }
            $this->assertFalse($db->scalar("SELECT has_schema_privilege('builderad_scope_executor','builderad_private','CREATE')"));
            $this->assertFalse($db->scalar("SELECT has_function_privilege('builderad_owner','builderad_private.guard_effect()','EXECUTE')"));
            foreach (['builderad_app', 'builderad_authority'] as $role) {
                $this->assertFalse($db->scalar("SELECT has_function_privilege(?,p.oid,'EXECUTE') FROM pg_proc p WHERE p.proname='builderad_v2_market_knowledge_enroll'", [$role]));
                $this->assertFalse($db->scalar("SELECT has_function_privilege(?,p.oid,'EXECUTE') FROM pg_proc p WHERE p.proname='builderad_v2_market_knowledge_withdraw'", [$role]));
            }
            $this->assertNotNull($db->scalar("SELECT to_regclass('market_knowledge_operations_order')::text"));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->actingAsInternal($this->observer());
            $this->getJson(self::PATH)->assertOk()->assertJsonCount(1, 'data.sources')->assertJsonCount(0, 'data.shared_knowledge')->assertJsonCount(5, 'data.methods');
        } finally {
            $reset->install();
        }
    }

    public function test_runtime_has_no_raw_market_table_access_or_unsigned_projection_access(): void
    {
        $source = $this->marketSource();
        foreach (['market_source_custody', 'market_evidence_revisions', 'market_processing_runs', 'market_knowledge_captures', 'market_knowledge_completion_tickets', 'market_business_finding_captures'] as $table) {
            try {
                DB::table($table)->get();
                $this->fail('Raw protected table must remain unavailable: '.$table);
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }
        try {
            DB::scalar('SELECT builderad_v2_market_operations_read(?,NULL)', ['invented_session']);
            $this->fail('A caller-supplied session is not a signed staff principal.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
    }
}
