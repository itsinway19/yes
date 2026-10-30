<?php

namespace Tests\Feature;

use App\Business\BusinessContext;
use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyCohortFenceTest extends TestCase
{
    private function fixture(): array
    {
        $u = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($u, 1);
        $this->actingAs($u)->post('/workspaces', ['name' => 'Retained collaboration label', 'slug' => 'legacy-fence'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $w = $db->table('workspaces')->where('slug', 'legacy-fence')->first();
        $r = Request::create('/workspaces/'.$w->public_id);
        $r->setUserResolver(fn () => $u);
        $r->setLaravelSession(app('session')->driver());

        return [$u, $r, $w, $db->table('migration_cohort_members')->where('workspace_id', $w->id)->value('cohort_id')];
    }

    private function denied(callable $op): void
    {
        try {
            $op();
            $this->fail('Legacy path bypassed its cohort gate.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->errorInfo[0]);
        }
    }

    private function transition(string $cohort, string $before, int $epoch, string $after): void
    {
        DB::connection('pgsql_migrations')->scalar('SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,?,?,?,NULL,decode(?,\'hex\'))',
            [(string) Str::uuid(), $cohort, $before, $epoch, $after, hash('sha256', 'isolated legacy checkpoint')]);
    }

    public function test_draining_denies_old_sql_and_http_writers_while_new_business_is_independent(): void
    {
        [$u, $r, $w, $cohort] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        $a = app(BusinessScope::class)->create($r, (string) Str::uuid(), 'Canonical independent business', null);
        app(WorkspaceContext::class)->runBusinessMutation($u, $w->public_id,
            fn (Workspace $scope) => app(BusinessContext::class)->replaceTargetMarkets($r, $scope, ['SA'], 0), $r);
        $before = $db->table('business_target_market_events')->count();
        $this->transition($cohort, 'LEGACY_ACTIVE', 0, 'DRAINING');
        $this->post('/workspaces/'.$w->public_id.'/business/target-markets', ['country_codes' => ['JO'], 'version' => 1])->assertForbidden();
        $this->denied(fn () => DB::transaction(function () use ($u, $w) {
            app(PrincipalContext::class)->activate($u);
            DB::scalar('SELECT builderad_select_workspace_context(?)', [$w->id]);
        }));
        $this->denied(fn () => DB::connection('pgsql_authority')->statement("UPDATE security_action_grants SET status='revoked',revoked_at=clock_timestamp() WHERE workspace_id=? AND action='business.context.manage'", [$w->id]));
        $this->assertSame($before, $db->table('business_target_market_events')->count());
        $this->assertSame(1, $db->table('workspaces')->where('id', $w->id)->value('business_context_version'));
        $this->assertSame('Canonical independent business', app(BusinessScope::class)->read($r, $a['business_public_id'],
            ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $a['grant_refs']['business.context.read']]]])['name']);
        $this->transition($cohort, 'DRAINING', 1, 'LEGACY_ACTIVE');
        app(WorkspaceContext::class)->runBusinessMutation($u, $w->public_id,
            fn (Workspace $scope) => app(BusinessContext::class)->replaceTargetMarkets($r, $scope, ['JO'], 1), $r);
        $this->assertSame(2, $db->table('workspaces')->where('id', $w->id)->value('business_context_version'));
    }

    public function test_stale_owner_definer_without_entry_cannot_write_around_table_guard(): void
    {
        [$u, $r, $w, $cohort] = $this->fixture();
        $db = DB::connection('pgsql_migrations');
        // Isolated stale binary fixture: deliberately omits every new prefix. No production guard is disabled.
        $db->unprepared(<<<'SQL'
            CREATE FUNCTION public.builderad_test_stale_legacy(w bigint) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
            BEGIN UPDATE public.workspaces SET business_context_version=business_context_version+1 WHERE id=w; END $$;
            REVOKE ALL ON FUNCTION public.builderad_test_stale_legacy(bigint) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.builderad_test_stale_legacy(bigint) TO builderad_app;
            SQL);
        try {
            $this->denied(fn () => DB::scalar('SELECT builderad_test_stale_legacy(?)', [$w->id]));
            $this->transition($cohort, 'LEGACY_ACTIVE', 0, 'DRAINING');
            $this->denied(fn () => DB::scalar('SELECT builderad_test_stale_legacy(?)', [$w->id]));
            $this->assertSame(0, $db->table('workspaces')->where('id', $w->id)->value('business_context_version'));
            $this->denied(fn () => $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'DRAINING',1,'V2_ACTIVE',NULL,decode(?,'hex'))", [(string) Str::uuid(), $cohort, hash('sha256', 'not a qualified import')]));
        } finally {
            $db->statement('DROP FUNCTION public.builderad_test_stale_legacy(bigint)');
        }
    }
}
