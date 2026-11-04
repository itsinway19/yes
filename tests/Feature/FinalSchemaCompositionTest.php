<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\MarketKnowledgeFixture;
use Tests\Support\NativeMaterialFixture;
use Tests\Support\PurposeDeclarationFixture;
use Tests\TestCase;

/** Exercise the installed composition, rather than each migration in isolation. */
final class FinalSchemaCompositionTest extends TestCase
{
    use MarketKnowledgeFixture;
    use NativeMaterialFixture;

    public function test_fresh_final_schema_composes_business_native_source_market_staff_and_purpose_authority(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        try {
            $reset->install();
            $native = $this->original(adoptViaCommand: true);
            $nativeResult = $this->nativeRead($native)->assertOk()->json();
            $shared = $this->publishedKnowledge();
            $this->assertNativeDependencyDigest($native);
            $this->assertFinalBehavior($native, $nativeResult, $shared);
            $this->assertFinalFunctionOwners();
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    public function test_populated_pre580_upgrade_preserves_receipts_digests_grants_and_exact_replay_in_final_composition(): void
    {
        $this->usePersistentTestSessions();
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), '0001_01_01_000580_organization_team.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $native = $this->original(adoptViaCommand: true, historicalWorkspace: true);
            $nativeResult = $this->nativeRead($native)->assertOk()->json();
            $shared = $this->publishedKnowledge();
            $dependency = $this->assertNativeDependencyDigest($native);
            $owner = DB::connection('pgsql_migrations');
            $scopeDigest = $owner->scalar("SELECT encode(scope_digest,'hex') FROM data_use_scopes WHERE scope_id=?::uuid", [$native['scope']]);
            // Install the initialized candidate through620, then reproduce only
            // the known final-definition collisions. These are deliberately
            // simulated regressions, not an archived historical deployment.
            $repair = '0001_01_01_000630_preserve_final_scope_contracts';
            $through620 = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), $repair.'.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $through620, '--realpath' => true, '--force' => true]));
            $this->assertTrue($owner->table('migrations')->where('migration', '0001_01_01_000620_business_purpose_statement_policy')->exists());
            $this->assertFalse($owner->table('migrations')->where('migration', $repair)->exists());
            $this->simulateKnownFinalDefinitionCollisions();
            $installed = $owner->table('migrations')->pluck('migration')->all();
            $before = [];
            foreach (['business_command_receipts', 'security_action_grants', 'authority_qualification_receipts', 'market_evidence_revisions', 'material_lineage_manifests'] as $table) {
                $before[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }

            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertSame([$repair], array_values(array_diff($owner->table('migrations')->pluck('migration')->all(), $installed)), 'Only additive630 may repair the initialized620 catalog');
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), $table.' historical rows must survive final-schema installation unchanged');
            }
            $this->assertSame($scopeDigest, $owner->scalar("SELECT encode(scope_digest,'hex') FROM data_use_scopes WHERE scope_id=?::uuid", [$native['scope']]));
            $this->assertSame($dependency, $this->assertNativeDependencyDigest($native), 'The historical six-field resource image retains its exact CB1 digest');
            $this->assertFinalBehavior($native, $nativeResult, $shared);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertFinalFunctionOwners();
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }

    /** Marked disposable fixture only: reverse four precise corrected branches. */
    private function simulateKnownFinalDefinitionCollisions(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $changes = [
            'resource_dependency_digest(jsonb)' => null,
            'assert_invocation_current()' => ["  IF i.action IN('market.knowledge.capture','market.knowledge.publish') THEN\n   PERFORM builderad_private.market_operator(session_ref,i.action,false);\n  ELSE PERFORM builderad_private.principal(session_ref,false); END IF;", '  PERFORM builderad_private.principal(session_ref,false);'],
            'guard_effect()' => ["IF NOT adopted_source AND NOT(TG_TABLE_NAME='source_registry' AND coalesce(after_row->>'custody_kind',before_row->>'custody_kind','')='platform_market') THEN", 'IF NOT adopted_source THEN'],
            'require_scope(text,text,bigint,text,jsonb,text,bigint[],bigint[])' => [" OR(f.kind='data_use' AND p_kind='data_use' AND (to_jsonb(f)->>'data_use_scope_id')::uuid=p_target::uuid)", ''],
        ];
        $owner->statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        try {
            $owner->transaction(function () use ($owner, $changes): void {
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                foreach ($changes as $signature => $change) {
                    $body = str_replace("\r\n", "\n", $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', ['builderad_private.'.$signature]));
                    if ($change === null) {
                        $start = "  IF row_value->>'data_use_scope_id' IS NULL THEN";
                        $end = '  prior_id:=current_id;';
                        $this->assertSame(1, substr_count($body, $start));
                        $this->assertSame(1, substr_count($body, $end));
                        $change = [substr($body, strpos($body, $start), strpos($body, $end) - strpos($body, $start)), "  payload:=payload||builderad_private.row_image('public.resource_right_fences',row_value);\n"];
                    }
                    [$before, $after] = $change;
                    $this->assertSame(1, substr_count($body, $before), 'One exact simulated-regression anchor for '.$signature);
                    $regressed = str_replace($before, $after, $body);
                    $this->assertNotSame($body, $regressed);
                    $owner->unprepared($regressed);
                    $actual = str_replace("\r\n", "\n", $owner->scalar('SELECT pg_get_functiondef(?::regprocedure)', ['builderad_private.'.$signature]));
                    $this->assertSame(0, substr_count($actual, $before), 'The fixture must actually install its simulated regression');
                }
            });
        } finally {
            $owner->statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        }
        // Use the same catalog-seal boundary as real migrations; do not disable
        // baseline qualification or alter the repair migration's admission.
        $owner->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    private function selection(int|string $grant, string $kind = 'business'): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => $kind, 'leaf_grant_id' => (int) $grant]]];
    }

    private function nativeRead(array $native, ?array $body = null)
    {
        return $this->actingAs($native['user'])->postJson('/api/businesses/'.$native['a']['business_public_id'].'/materials/read', $body ?? $native['body']);
    }

    private function executor(callable $operation): mixed
    {
        $owner = DB::connection('pgsql_migrations');

        return $owner->transaction(function () use ($owner, $operation) {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');

            return $operation($owner);
        });
    }

    private function assertNativeDependencyDigest(array $native): string
    {
        $owner = DB::connection('pgsql_migrations');
        $rows = array_map(fn ($row) => json_decode($row->image, true, flags: JSON_THROW_ON_ERROR), $owner->select(
            "SELECT (to_jsonb(f)-'data_use_scope_id')::text AS image FROM resource_right_fences f WHERE (kind='source' AND source_id=?::uuid) OR (kind='client_boundary' AND boundary_id=?::uuid) ORDER BY fence_id",
            [$native['source'], $native['boundary']['boundary_id']]));
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertCount(6, $row, 'Native/client dependency format predates the nullable market data-use column');
        }
        $historical = json_encode($rows, JSON_THROW_ON_ERROR);
        $withNull = json_encode(array_map(fn ($row) => [...$row, 'data_use_scope_id' => null], $rows), JSON_THROW_ON_ERROR);
        $tags = json_encode(array_fill(0, count($rows), ['fence_id' => 'U', 'source_id' => 'U', 'boundary_id' => 'U', 'rights_epoch' => 'I']), JSON_THROW_ON_ERROR);
        $digests = $this->executor(fn ($db) => $db->selectOne(<<<'SQL'
            SELECT encode(builderad_private.resource_dependency_digest(?::jsonb),'hex') AS historical,
             encode(builderad_private.resource_dependency_digest(?::jsonb),'hex') AS expanded,
             encode(builderad_private.digest('dependency-set:1',?::jsonb,?::jsonb),'hex') AS typed
            SQL, [$historical, $withNull, $historical, $tags]));
        $this->assertSame($digests->typed, $digests->historical, 'Controlled SQL UUID/integer types keep the original six-field encoding');
        $this->assertSame($digests->historical, $digests->expanded, 'Adding the nullable market column cannot rewrite historical resource identity');
        $this->assertSame($digests->historical, $owner->scalar("SELECT encode(dependency_digest,'hex') FROM business_command_receipts WHERE command_id=?::uuid", [$native['body']['command_id']]));

        return $digests->historical;
    }

    private function assertFinalBehavior(array $native, array $nativeResult, array $shared): void
    {
        $owner = DB::connection('pgsql_migrations');
        // Capture replay is deliberately credential-bound. publishedKnowledge
        // leaves the original qualified staff session active; migrations must
        // preserve its exact replay before any advertiser context switch.
        $session = app('session')->driver();
        $originalSession = hash('sha256', $session->getId());
        $this->assertSame(config('session.internal_cookie'), $session->getName());
        $this->assertSame('internal_passkey', $owner->table('security_sessions')->where('user_id', $shared['operator']->id)->where('session_key_hash', $originalSession)->value('auth_method'));
        foreach (['capture', 'publish'] as $effect) {
            $body = $shared[$effect];
            $saved = json_decode($owner->table('business_command_receipts')->where('command_id', $body['command_id'])->value('result_ref'), true, flags: JSON_THROW_ON_ERROR);
            $response = $this->postJson('/internal/market-knowledge/'.$effect, $body);
            $this->assertSame(200, $response->status(), 'Original-credential staff '.$effect.' replay response: '.$response->getContent());
            $this->assertSame($saved, $response->json('data'), 'Staff '.$effect.' exactly replays its original receipt');
            $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $body['command_id'])->count());
        }
        $base = '/api/businesses/'.$native['a']['business_public_id'];
        $read = $this->selection($native['a']['grant_refs']['business.context.read']);
        $write = $this->selection($native['a']['grant_refs']['business.context.manage']);
        $this->actingAs($native['user'])->postJson($base.'/read', ['authority_selection' => $read])->assertOk()->assertJsonPath('data.name', 'Material client');
        $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'name' => 'SYNTHETIC final composition', 'business_type_id' => null,
            'expected_context' => 0, 'authority_selection' => $write])->assertOk();
        $this->postJson($base.'/read', ['authority_selection' => $read])->assertOk()->assertJsonPath('data.name', 'SYNTHETIC final composition');
        $this->assertSame($nativeResult, $this->nativeRead($native)->assertOk()->json(), 'A historical exact command replays under the final definitions');
        $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $native['body']['command_id'])->count());

        // Source administration, source read, Business use and recipient data-use
        // remain separate rights even though they now share the final catalog.
        $this->getJson('/api/source-rights/'.$native['source'].'?access='.$native['manager'])->assertOk()->assertJsonPath('data.source.adopted', true);
        $this->getJson('/api/source-rights/'.$native['source'].'?access='.$native['use']->id)->assertForbidden();
        foreach ([1, 2] as $missing) {
            $body = $native['body'];
            $body['command_id'] = (string) Str::uuid();
            array_splice($body['authority_selection']['requirements'], $missing, 1);
            $this->nativeRead($native, $body)->assertForbidden();
        }

        $this->actingAsInternal($shared['operator']);
        $session = app('session')->driver();
        $this->assertSame(config('session.internal_cookie'), $session->getName(), 'Staff replay must use the internal cookie after the advertiser round trip');
        $this->assertSame('internal_passkey', $owner->table('security_sessions')->where('user_id', $shared['operator']->id)->where('session_key_hash', hash('sha256', $session->getId()))->value('auth_method'), 'Staff replay must retain its qualified internal authentication method');
        $this->assertNotSame($originalSession, hash('sha256', $session->getId()), 'The guard round trip creates a different staff credential');
        foreach (['capture', 'publish'] as $effect) {
            $this->postJson('/internal/market-knowledge/'.$effect, $shared[$effect])->assertForbidden();
            $this->assertSame(1, $owner->table('business_command_receipts')->where('command_id', $shared[$effect]['command_id'])->count(), 'A new credential cannot recreate the old command');
        }
        $this->assertSame('published', $owner->table('market_knowledge_captures')->where('run_id', $shared['capture']['command_id'])->value('state'));
        $this->assertSame(1, $owner->table('material_lineage_manifests')->where('material_key', $shared['ref']['key'])->count());
        $this->assertSame(0, $owner->table('security_action_grants')->where('subject_user_id', $shared['operator']->id)->whereIn('scope_kind', ['business', 'workspace', 'source', 'client_data'])->count(), 'Platform staff effects do not acquire advertiser or Workspace authority');
        $this->assertSame(0, $owner->table('workspace_memberships')->where('user_id', $shared['operator']->id)->count());
        // A staff credential cannot be issued as an advertiser by the test
        // login helper. Exercise the actual SQL boundary with its legitimate
        // signed internal principal instead of fabricating that invalid login.
        foreach ([
            ['SELECT builderad_v2_business_create_draft(?,?::uuid,?,?)', [$originalSession, (string) Str::uuid(), 'Forbidden staff Business', null]],
            ['SELECT builderad_v2_business_read(?,?::uuid,?::jsonb)', [$originalSession, $native['a']['business_public_id'], json_encode($read, JSON_THROW_ON_ERROR)]],
        ] as [$sql, $bindings]) {
            try {
                DB::transaction(function () use ($shared, $sql, $bindings): void {
                    app(PrincipalContext::class)->activate($shared['operator']);
                    DB::scalar($sql, $bindings);
                });
                $this->fail('Market staff must not gain Business authority.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
        }

        $this->assertMarketScopeEpochs($shared);
        $this->assertOwnPurposeStillOperates($native, $base, $read);
    }

    private function assertMarketScopeEpochs(array $shared): void
    {
        $owner = DB::connection('pgsql_migrations');
        $record = $shared['originals']['records'][0];
        $this->assertSame('platform_market', $owner->table('source_registry')->where('id', $record['source'])->value('custody_kind'));
        $this->assertNull($owner->table('source_registry')->where('id', $record['source'])->value('workspace_id'));
        $command = $owner->table('market_evidence_revisions')->where('evidence_id', $record['evidence'])->value('command_id');
        $snapshot = $this->executor(fn ($db) => $db->scalar("SELECT authority_selection::text FROM builderad_private.invocations WHERE command_id=?::uuid AND action='market.ingest' AND phase='completed'", [$command]));
        $proof = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['proofs'][0];
        $scope = $proof['qualification']['target_ref'];
        $this->assertNotEmpty($proof['epochs'], 'Market require_scope must record its resource epochs');
        $expected = json_decode($owner->scalar("SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id)::text FROM resource_right_fences f WHERE (kind='source' AND source_id=?::uuid) OR (kind='data_use' AND data_use_scope_id=?::uuid)", [$record['source'], $scope]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $expected);
        $this->assertSame($expected, $proof['epochs'], 'Market require_scope includes the exact data-use fence as well as the source fence');
    }

    private function assertOwnPurposeStillOperates(array $native, string $base, array $read): void
    {
        $this->actingAs($native['user']);
        $receipt = $this->postJson($base.'/purpose-enrollment', ['command_id' => (string) Str::uuid(), 'confirmed' => true, 'previous_grant' => '0', 'authority_selection' => $read])
            ->assertOk()->assertJsonPath('data.establishes_external_authority', false)->json('data');
        $grant = $receipt['grant_refs']['business.purpose.declare'];
        $command = PurposeDeclarationFixture::command();
        $this->postJson($base.'/purposes', ['command' => $command, 'expected_context' => 1, 'authority_selection' => $this->selection($grant)])->assertCreated();
        $projection = $this->getJson($base.'/purposes?access='.$native['a']['grant_refs']['business.context.read'])->assertOk();
        $projection->assertJsonPath('data.projection.items.0.applicable.snapshot.meaning', $command['declaration']['meaning']);
        $this->actingAs(User::factory()->create())->getJson($base.'/purposes?access='.$native['a']['grant_refs']['business.context.read'])->assertForbidden();
    }

    private function assertFinalFunctionOwners(): void
    {
        foreach ([
            ['builderad_private', 'resource_dependency_digest', 'authority'],
            ['builderad_private', 'assert_invocation_current', 'authority'],
            ['builderad_private', 'guard_effect', 'purpose_retirement'],
            ['builderad_private', 'require_scope', 'scope_authority'],
            ['public', 'builderad_v2_business_purpose_snapshot', 'purpose_snapshot'],
        ] as [$schema, $function, $owner]) {
            $source = file_get_contents(database_path('sql/business_scope/'.$owner.'.sql'));
            $name = ($schema === 'public' ? '(?:public\.)?' : preg_quote($schema, '/').'\.').preg_quote($function, '/');
            $pattern = '/CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+'.$name.'\s*\([\s\S]*?\bAS\s+\$\$(.*?)\$\$;/si';
            $this->assertSame(1, preg_match($pattern, $source, $match), 'Exactly one authoritative literal function body must be available for '.$function);
            $installed = DB::connection('pgsql_migrations')->select('SELECT p.prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=? AND p.proname=?', [$schema, $function]);
            $this->assertCount(1, $installed, 'One final installed definition for '.$function);
            $normalize = fn ($body) => trim(str_replace("\r\n", "\n", $body));
            $this->assertSame(hash('sha256', $normalize($match[1])), hash('sha256', $normalize($installed[0]->prosrc)), 'Final '.$function.' must retain its canonical owner after every migration');
        }
    }
}
