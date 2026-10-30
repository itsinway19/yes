<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeclaredTargetMarketsDatabaseTest extends TestCase
{
    private function write(object $workspace, string $arrayExpression, ?int $version): int
    {
        $owner = DB::connection('pgsql_migrations');
        $actor = $owner->table('workspace_ownerships')->where('workspace_id', $workspace->id)->value('responsible_owner_user_id');
        $session = $owner->table('security_sessions')->where('user_id', $actor)->value('session_key_hash');

        return DB::transaction(function () use ($actor, $session, $workspace, $arrayExpression, $version): int {
            app(PrincipalContext::class)->activate(User::findOrFail($actor));
            DB::selectOne('SELECT builderad_v2_legacy_enter(?::uuid)', [$workspace->public_id]);
            DB::selectOne('SELECT builderad_business_context_begin(?,?)', [$workspace->id, $session]);

            // Expressions are fixed test probes, never production/user interpolation.
            return (int) DB::scalar('SELECT builderad_business_target_markets_write(?,?,'.$arrayExpression.',?)', [$workspace->id, $session, $version]);
        });
    }

    private function snapshot(object $workspace): array
    {
        $owner = DB::connection('pgsql_migrations');

        return ['workspace' => $owner->table('workspaces')->where('id', $workspace->id)->first(),
            'codes' => $owner->table('business_target_markets')->where('workspace_id', $workspace->id)->orderBy('country_code')->pluck('country_code')->all(),
            'events' => $owner->table('business_target_market_events')->where('workspace_id', $workspace->id)->orderBy('version')->get()->all()];
    }

    public function test_same_set_stale_retry_is_noop(): void
    {
        $workspace = $this->business();
        $this->assertSame(1, $this->write($workspace, "ARRAY['SA','KW','AE','SA']", 0));
        $before = $this->snapshot($workspace);
        $this->assertSame(['AE', 'KW', 'SA'], $before['codes']);
        $this->assertCount(1, $before['events']);
        $this->assertSame('{}', $before['events'][0]->before_codes);
        $this->assertSame('{AE,KW,SA}', $before['events'][0]->after_codes);
        $this->assertSame(1, $this->write($workspace, "ARRAY['KW','SA','AE']", 0));
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_stale_different_set_rolls_back(): void
    {
        $workspace = $this->business();
        $this->write($workspace, "ARRAY['SA']", 0);
        $before = $this->snapshot($workspace);
        $this->denied(fn () => $this->write($workspace, "ARRAY['AE']", 0), '40001');
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_last_removal_returns_unknown(): void
    {
        $workspace = $this->business();
        $this->write($workspace, "ARRAY['SA']", 0);
        $this->assertSame(2, $this->write($workspace, 'ARRAY[]::text[]', 1));
        $before = $this->snapshot($workspace);
        $this->assertSame([], $before['codes']);
        $this->assertCount(2, $before['events']);
        $this->assertSame('{SA}', $before['events'][1]->before_codes);
        $this->assertSame('{}', $before['events'][1]->after_codes);
        $this->assertSame(2, $this->write($workspace, 'ARRAY[]::text[]', 0));
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    public function test_malformed_sql_arrays_are_rejected_atomically(): void
    {
        $workspace = $this->business();
        $this->assertSame(1, $this->write($workspace, "ARRAY['KW','SA']", 0));
        $before = $this->snapshot($workspace);
        foreach (['NULL::text[]', "ARRAY[['SA','AE']]", "ARRAY['XX']", "ARRAY['sa']", 'ARRAY[NULL::text]', "ARRAY['GCC']", "array_fill('SA'::text,ARRAY[250])",
            "ARRAY['S\"A']", "ARRAY['S''A']", "ARRAY['{SA}']", "ARRAY['SA','{AE}']"] as $expression) {
            $this->denied(fn () => $this->write($workspace, $expression, 1), '22023');
            $this->assertEquals($before, $this->snapshot($workspace));
        }
        foreach ([null, -1] as $version) {
            $this->denied(fn () => $this->write($workspace, "ARRAY['SA']", $version), '22023');
            $this->assertEquals($before, $this->snapshot($workspace));
        }
    }

    public function test_unauthorized_identical_set_is_denied(): void
    {
        $workspace = $this->business();
        $this->write($workspace, "ARRAY['SA']", 0);
        $before = $this->snapshot($workspace);
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('workspace_id', $workspace->id)->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => DB::raw('clock_timestamp()')]);
        $this->denied(fn () => $this->write($workspace, "ARRAY['SA']", 0), '42501');
        $this->assertEquals($before, $this->snapshot($workspace));
    }

    private function business(): object
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Market business', 'slug' => 'market-business'])->assertRedirect();

        return DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'market-business')->first();
    }

    private function denied(callable $operation, string $sqlstate): void
    {
        try {
            $operation();
            $this->fail('Database integrity operation unexpectedly succeeded.');
        } catch (QueryException $error) {
            $this->assertSame($sqlstate, $error->getCode());
        }
    }

    public function test_valid_country_fk_and_unique_business_country(): void
    {
        $workspace = $this->business();
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(1, $this->write($workspace, "ARRAY['SA']", 0));
        $this->assertSame(1, $owner->table('business_target_markets')->count());
        // Fixed, test-only owner probe on the caller's admitted backend: guards remain enabled,
        // so these operations reach the actual FK/unique constraints instead of failing admission first.
        $owner->unprepared(<<<'SQL'
            CREATE FUNCTION public.builderad_test_dtm_constraint_probe(w bigint,c text) RETURNS void
            LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
            BEGIN INSERT INTO public.business_target_markets(workspace_id,country_code) VALUES(w,c); END $$;
            REVOKE ALL ON FUNCTION public.builderad_test_dtm_constraint_probe(bigint,text) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.builderad_test_dtm_constraint_probe(bigint,text) TO builderad_app;
            SQL);
        try {
            $insert = fn (string $code) => DB::transaction(function () use ($workspace, $code): void {
                app(PrincipalContext::class)->activate(auth()->user());
                DB::selectOne('SELECT builderad_v2_legacy_enter(?::uuid)', [$workspace->public_id]);
                DB::statement('SELECT builderad_test_dtm_constraint_probe(?,?)', [$workspace->id, $code]);
            });
            $this->denied(fn () => $insert('SA'), '23505');
            $this->denied(fn () => $insert('XX'), '23503');
            $this->denied(fn () => $owner->table('country_references')->where('code', 'SA')->delete(), '23001');
            $this->assertTrue($owner->table('country_references')->where('code', 'SA')->exists());
        } finally {
            $owner->statement('DROP FUNCTION public.builderad_test_dtm_constraint_probe(bigint,text)');
        }
    }

    public function test_runtime_cannot_write_reference_or_relations(): void
    {
        $workspace = $this->business();
        foreach (['country_references', 'business_target_markets', 'business_target_market_events'] as $table) {
            $this->denied(fn () => DB::statement('DELETE FROM '.$table), '42501');
            $this->denied(fn () => DB::statement('TRUNCATE '.$table), '42501');
            $this->denied(fn () => DB::statement('UPDATE '.$table.' SET '.($table === 'country_references' ? "name_en='forged'" : 'workspace_id='.$workspace->id)), '42501');
        }
        $this->denied(fn () => DB::table('business_target_markets')->insert(['workspace_id' => $workspace->id, 'country_code' => 'SA']), '42501');
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('business_target_markets')->count());
    }

    public function test_empty_install_has_no_declarations(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $codes = json_decode(file_get_contents(database_path('reference/countries/identities.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($codes, $owner->table('country_references')->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, $owner->table('business_target_markets')->count());
        $this->assertSame(0, $owner->table('business_target_market_events')->count());
        $catalog = $owner->select("SELECT c.relname,c.relrowsecurity,c.relforcerowsecurity,r.rolname FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner WHERE c.relname IN ('business_target_markets','business_target_market_events') ORDER BY c.relname");
        $this->assertCount(2, $catalog);
        foreach ($catalog as $table) {
            $this->assertTrue($table->relrowsecurity);
            $this->assertTrue($table->relforcerowsecurity);
            $this->assertSame('builderad_owner', $table->rolname);
        }
    }

    public function test_audit_is_immutable(): void
    {
        $workspace = $this->business();
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(1, $this->write($workspace, "ARRAY['SA']", 0));
        $this->denied(fn () => $owner->table('business_target_market_events')->update(['version' => 2]), '23514');
        $this->denied(fn () => $owner->table('business_target_market_events')->delete(), '23514');
        $this->assertSame(1, $owner->table('business_target_market_events')->count());
    }
}
