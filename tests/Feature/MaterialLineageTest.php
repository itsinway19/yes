<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaterialLineageTest extends TestCase
{
    private function executor(callable $operation): mixed
    {
        $db = DB::connection('pgsql_migrations');
        $db->statement('SET ROLE builderad_scope_executor');
        try {
            return $operation($db);
        } finally {
            $db->statement('RESET ROLE');
        }
    }

    private function digest(string $domain, mixed $value): string
    {
        return $this->executor(fn ($db) => $db->scalar('SELECT encode(builderad_private.lineage_digest(?,?::jsonb),\'hex\')', [$domain, json_encode($value, JSON_THROW_ON_ERROR)]));
    }

    private function ref(string $key): array
    {
        return ['namespace' => 'test.synthetic_material', 'key' => $key, 'version' => '1'];
    }

    private function node(string $key, array $dependencies = []): array
    {
        usort($dependencies, fn ($a, $b) => strcmp($this->jsonOrder($a), $this->jsonOrder($b)));

        return ['ref' => $this->ref($key), 'kind' => $dependencies === [] ? 'original' : 'derived',
            'resolver_contract_version' => 1, 'material_digest' => hash('sha256', $key), 'producer_receipt_ref' => 'test:producer:'.$key,
            'direct_dependency_count' => count($dependencies), 'direct_dependency_digest' => $this->digest('dependency-set:1', $dependencies),
            'dependencies' => $dependencies, 'closure_digest' => null];
    }

    /** Use PostgreSQL's exact jsonb text ordering, independently of PHP insertion order. */
    private function jsonOrder(array $value): string
    {
        return DB::connection('pgsql_migrations')->scalar('SELECT ?::jsonb::text', [json_encode($value)]);
    }

    private function edge(string $input): array
    {
        return ['input' => $this->ref($input), 'data_use_scope_id' => '11111111-1111-7111-8111-111111111111',
            'policy_id' => 'test.synthetic.operation_policy', 'policy_version' => 1];
    }

    private function seal(array $graph, string $root): string
    {
        $nodes = $edges = [];
        $todo = [$root];
        while ($todo !== []) {
            $key = array_pop($todo);
            $node = $graph[$key];
            $id = $this->digest('lineage-manifest:1', $node['ref']);
            if (isset($nodes[$id])) {
                continue;
            }
            $nodes[$id] = array_intersect_key($node, array_flip(['ref', 'kind', 'material_digest', 'resolver_contract_version']));
            foreach ($node['dependencies'] as $dependency) {
                $edges[] = ['from' => $id, 'to' => $this->digest('lineage-manifest:1', $dependency['input']), 'edge' => $dependency];
                $todo[] = $dependency['input']['key'];
            }
        }
        ksort($nodes, SORT_STRING);
        usort($edges, fn ($a, $b) => strcmp($this->jsonOrder($a), $this->jsonOrder($b)));

        return $this->digest('lineage-manifest:1', ['materials' => array_values($nodes), 'dependencies' => $edges]);
    }

    /** Isolated transaction replaces only the resolver with a trusted synthetic producer; always rolled back. */
    private function synthetic(array $graph, array $roots): array
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        $db->beginTransaction();
        try {
            $db->statement('GRANT CREATE ON SCHEMA builderad_private TO builderad_scope_executor');
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $literal = $db->getPdo()->quote(json_encode($graph, JSON_THROW_ON_ERROR));
            $db->unprepared("CREATE OR REPLACE FUNCTION builderad_private.resolve_material(p_ref jsonb) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS \$fixture\$ SELECT {$literal}::jsonb -> (p_ref->>'key') \$fixture\$");
            $db->statement('RESET ROLE');
            $db->statement('REVOKE CREATE ON SCHEMA builderad_private FROM builderad_scope_executor');
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return json_decode($db->scalar('SELECT builderad_private.material_closure(?::jsonb)', [json_encode(array_map($this->ref(...), $roots))]), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $db->rollBack();
        }
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Incomplete lineage was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
            $this->assertStringContainsString('LINEAGE_UNVERIFIED', $e->getMessage());
        }
    }

    public function test_full_sealed_closure_keeps_deep_dependencies_and_shared_nodes_without_duplicate_visits(): void
    {
        $g = ['root' => $this->node('root'), 'd1' => $this->node('d1', [$this->edge('root')])];
        $g['d1']['closure_digest'] = $this->seal($g, 'd1');
        $g['d2'] = $this->node('d2', [$this->edge('d1'), $this->edge('root')]);
        $g['d2']['closure_digest'] = $this->seal($g, 'd2');
        $g['report'] = $this->node('report', [$this->edge('d2')]);
        $g['report']['closure_digest'] = $this->seal($g, 'report');
        $result = $this->synthetic($g, ['report', 'root']);
        $this->assertSame(4, $result['distinct_nodes']);
        $this->assertSame(4, $result['edge_count']);
        $this->assertCount(4, $result['materials']);
        $this->assertSame($result['dependency_digest'], $this->synthetic($g, ['root', 'report'])['dependency_digest']);
        $g['root']['material_digest'] = hash('sha256', 'changed-deep-original');
        $this->denied(fn () => $this->synthetic($g, ['report']));
    }

    public function test_missing_manifest_cycle_omitted_edge_and_missing_policy_cannot_be_interpreted_as_no_dependencies(): void
    {
        $root = $this->node('root');
        $derived = $this->node('d1', [$this->edge('root')]);
        $derived['closure_digest'] = str_repeat('a', 64);
        $this->denied(fn () => $this->synthetic(['d1' => $derived], ['d1']));
        $cycle = $this->node('root', [$this->edge('d1')]);
        $cycle['closure_digest'] = str_repeat('a', 64);
        $this->denied(fn () => $this->synthetic(['root' => $cycle, 'd1' => $derived], ['d1']));
        $derived['dependencies'] = [];
        $this->denied(fn () => $this->synthetic(['root' => $root, 'd1' => $derived], ['d1']));
        $derived = $this->node('d1', [array_diff_key($this->edge('root'), ['policy_id' => true])]);
        $this->denied(fn () => $this->synthetic(['root' => $root, 'd1' => $derived], ['d1']));
    }

    public function test_exceeding_depth_is_unavailable_without_truncated_success(): void
    {
        $g = ['n33' => $this->node('n33')];
        for ($i = 32; $i >= 0; $i--) {
            $g['n'.$i] = $this->node('n'.$i, [$this->edge('n'.($i + 1))]);
            $g['n'.$i]['closure_digest'] = str_repeat('a', 64);
        }
        $this->denied(fn () => $this->synthetic($g, ['n0']));
    }

    public function test_closure_node_limit_and_missing_content_digest_deny_before_returning_any_partial_graph(): void
    {
        $prototype = $this->node('prototype');
        $g = $edges = [];
        for ($i = 0; $i < 512; $i++) {
            $key = 'leaf-'.$i;
            $g[$key] = array_replace($prototype, ['ref' => $this->ref($key), 'material_digest' => hash('sha256', $key)]);
            $edges[] = $this->edge($key);
        }
        $g['root'] = $this->node('root', $edges);
        $g['root']['closure_digest'] = str_repeat('a', 64);
        try {
            $this->synthetic($g, ['root']);
            $this->fail('513 distinct nodes were accepted.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
            $this->assertStringContainsString('closure limit', $e->getMessage());
        }
        unset($prototype['material_digest']);
        $this->denied(fn () => $this->synthetic(['prototype' => $prototype], ['prototype']));
    }

    public function test_registered_observation_resolver_uses_exact_native_key_and_preserves_partial_coverage_without_progress_access(): void
    {
        $u = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($u, 1);
        $this->actingAs($u)->post('/workspaces', ['name' => 'Native metadata fixture', 'slug' => 'native-metadata'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $w = $db->table('workspaces')->where('slug', 'native-metadata')->value('id');
        $source = (string) Str::uuid7();
        $ns = hash('sha256', 'test:native-entry-stream');
        $identity = 'bb2:'.hash('sha256', 'test:native-entry');
        $hash = hash('sha256', 'synthetic immutable observation content');
        $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $w, 'family' => 'meta', 'namespace' => 'test.native',
            'declared_external_id' => 'synthetic', 'label' => 'Fixture', 'version' => 1, 'status' => 'UNVERIFIED']);
        $db->table('source_capabilities')->insert(['workspace_id' => $w, 'source_id' => $source, 'resource' => 'account-1', 'operation' => 'report.read', 'purpose' => 'review']);
        $db->table('provider_observation_streams')->insert(['namespace' => $ns, 'workspace_id' => $w, 'source_id' => $source, 'provider' => 'meta',
            'resource' => 'account-1', 'operation' => 'report.read', 'purpose' => 'review', 'stream' => 'campaign', 'generation' => 1, 'access_version' => 1,
            'encrypted_progress' => 'test-only-progress-must-not-be-readable', 'progress_generation' => 1]);
        $db->table('provider_observation_runs')->insert(['namespace' => $ns, 'generation' => 1, 'command_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', 'fixture-run'),
            'coverage' => 'PARTIAL', 'provenance' => json_encode(['fixture' => true, 'api_version' => 1.5]), 'failure' => null, 'progress_advanced' => false, 'actor_user_id' => $u->id, 'actor_generation' => 1]);
        $db->table('provider_observation_entries')->insert(['namespace' => $ns, 'generation' => 1, 'identity' => $identity,
            'content_hash' => $hash, 'observation' => json_encode(['fixture_payload' => true]), 'prior_generation' => null, 'relation' => 'FIRST_OBSERVATION']);
        $key = $db->scalar('SELECT jsonb_build_object(\'namespace\',?::text,\'generation\',1,\'identity\',?::text)::text', [$ns, $identity]);
        $ref = ['namespace' => 'provider_observation_entry', 'key' => $key, 'version' => $hash];
        $result = $this->executor(fn ($db) => json_decode($db->scalar('SELECT builderad_private.material_closure(?::jsonb)', [json_encode([$ref])]), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(1, $result['distinct_nodes']);
        $this->assertSame(0, $result['edge_count']);
        $this->assertSame('PARTIAL', $result['materials'][0]['origin']['coverage']);
        $this->assertSame(1.5, $result['materials'][0]['origin']['provenance']['api_version']);
        $this->assertSame($source, $result['materials'][0]['origin']['source_id']);
        $this->assertStringNotContainsString('encrypted_progress', json_encode($result));
        $this->assertStringNotContainsString('fixture_payload', json_encode($result));
        $this->assertFalse((bool) $db->scalar("SELECT has_column_privilege('builderad_scope_executor','provider_observation_streams','encrypted_progress','SELECT')"));
        $this->assertTrue((bool) $db->scalar("SELECT has_column_privilege('builderad_scope_executor','provider_observation_entries','observation','SELECT')"));
        $this->assertFalse((bool) $db->scalar("SELECT has_function_privilege('builderad_app','builderad_private.resolve_material(jsonb)','EXECUTE')"));
        $ref['version'] = hash('sha256', 'another-version');
        $this->denied(fn () => $this->executor(fn ($db) => $db->scalar('SELECT builderad_private.material_closure(?::jsonb)', [json_encode([$ref])])));
    }

    public function test_production_dispatch_rejects_synthetic_and_unknown_producers_and_runtime_cannot_call_metadata_helpers(): void
    {
        foreach (['test.synthetic_material', 'unknown.artifact', 'object_purpose_revision'] as $namespace) {
            try {
                $this->executor(fn ($db) => $db->scalar('SELECT builderad_private.material_closure(?::jsonb)', [json_encode([['namespace' => $namespace, 'key' => (string) Str::uuid7(), 'version' => '1']])]));
                $this->fail('Unregistered producer resolved.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
                $this->assertStringContainsString('MATERIAL_KIND_UNAVAILABLE', $e->getMessage());
            }
        }
        try {
            DB::scalar("SELECT builderad_private.material_closure('[]')");
            $this->fail('Runtime called private metadata helper.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }
    }
}
