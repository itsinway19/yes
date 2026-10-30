<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketEvidenceFixture;
use Tests\TestCase;

final class MarketEvidenceSecurityTest extends TestCase
{
    use MarketEvidenceFixture;

    private function denied(string $connection, string $sql, array $bindings = []): void
    {
        try {
            DB::connection($connection)->transaction(fn () => DB::connection($connection)->select($sql, $bindings));
            $this->fail('Protected market operation unexpectedly succeeded: '.$sql);
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0], $error->getMessage());
        }
    }

    public function test_both_runtime_roles_cannot_read_mutate_truncate_admit_or_impersonate_an_executor(): void
    {
        $source = $this->marketSource();
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            foreach (['market_source_custody', 'market_evidence_heads', 'market_evidence_revisions', 'market_evidence_transitions'] as $table) {
                $this->denied($connection, 'SELECT * FROM '.$table);
                $this->denied($connection, 'INSERT INTO '.$table.' DEFAULT VALUES');
                $this->denied($connection, 'UPDATE '.$table.' SET '.($table === 'market_source_custody' ? 'source_id=source_id' : 'evidence_id=evidence_id').' WHERE false');
                $this->denied($connection, 'DELETE FROM '.$table.' WHERE false');
                $this->denied($connection, 'TRUNCATE '.$table.' CASCADE');
            }
            $this->denied($connection, 'SET ROLE builderad_scope_executor');
            $this->denied($connection, 'SELECT builderad_v2_market_admit(?::uuid,?,?::uuid,?,?::jsonb)', [(string) Str::uuid(), 'restrict_source', $source, 1, '{"reason":"forged"}']);
            if ($connection === 'pgsql') {
                $this->assertSame(0, DB::connection($connection)->table('source_registry')->where('id', $source)->count());
            } else {
                $this->denied($connection, 'SELECT * FROM source_registry WHERE id=?::uuid', [$source]);
            }
        }
    }

    public function test_market_genesis_never_uses_the_legacy_unadopted_source_bypass(): void
    {
        $this->denied('pgsql_migrations', "INSERT INTO source_registry(id,workspace_id,family,namespace,declared_external_id,label,version,status,custody_kind) VALUES(?::uuid,NULL,'synthetic','test','forged','forged',1,'UNVERIFIED','platform_market')", [(string) Str::uuid7()]);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('source_registry')->count());
    }

    public function test_forged_gucs_do_not_replace_a_signed_current_principal_and_selected_right(): void
    {
        $user = User::factory()->create();
        $source = $this->marketSource();
        $evidence = (string) Str::uuid7();
        $right = $this->marketRight($user, $source, $evidence, 'ingest', array_keys($this->offer()['fields']));
        DB::transaction(function () use ($user, $evidence, $right): void {
            DB::statement("SELECT set_config('app.actor_user_id',?,true)", [(string) $user->id]);
            DB::statement("SELECT set_config('app.principal_generation','1',true)");
            $this->denied('pgsql', 'SELECT builderad_v2_market_evidence_write(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?::jsonb)', [
                'forged-session', (string) Str::uuid(), $evidence, $right['scope_id'], 0, json_encode($this->offer()), json_encode($this->marketSelection($right)),
            ]);
        });
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('market_evidence_heads')->count());
    }
}
