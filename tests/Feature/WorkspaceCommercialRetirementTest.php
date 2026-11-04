<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WorkspaceCommercialRetirementTest extends TestCase
{
    public function test_old_http_and_sql_writers_cannot_run_even_with_current_organizational_authority(): void
    {
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Organization only', 'slug' => 'retired-writer'])->assertRedirect();
        $db = DB::connection('pgsql_migrations');
        $organization = $db->table('workspaces')->where('slug', 'retired-writer')->sole();
        foreach (['business', 'business/authority', 'business/target-markets', 'business/current-intent', 'goals'] as $suffix) {
            $this->postJson('/workspaces/'.$organization->public_id.'/'.$suffix, ['version' => 0, 'business_type_id' => 1])->assertStatus(410);
        }
        foreach (['business', 'goals', 'goals/review', 'goals/context'] as $suffix) {
            $this->getJson('/workspaces/'.$organization->public_id.'/'.$suffix)->assertStatus(410);
        }
        foreach (['builderad_business_context_write(bigint,text,bigint,text,bigint)', 'builderad_business_target_markets_write(bigint,text,text[],bigint)',
            'builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)', 'builderad_advertiser_goals_write(bigint,text,jsonb,bigint)',
            'builderad_business_context_begin(bigint,text)', 'builderad_business_context_require_prepared(bigint)',
            'builderad_v2_legacy_context_grant(bigint,text)', 'builderad_private.legacy_creation_grant(bigint,bigint,bigint)'] as $signature) {
            $this->assertNull($db->scalar('SELECT to_regprocedure(?)', [$signature]), $signature);
        }
        // Guard the effects as well as function names: a newly named stale
        // definer must not reopen any retired head or journal through SQL.
        foreach (['business_context_events', 'business_target_markets', 'business_target_market_events',
            'business_current_intent_events', 'advertiser_goal_preferences', 'advertiser_goal_preference_events'] as $table) {
            try {
                $db->transaction(fn () => $db->statement('INSERT INTO '.$table.' DEFAULT VALUES'));
                $this->fail('Retired commercial table accepted a new row: '.$table);
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0], $table);
                $this->assertStringContainsString('WORKSPACE_COMMERCIAL_WRITER_RETIRED', $error->getMessage(), $table);
            }
        }
        $this->assertSame(0, $db->table('security_action_grants')->where('scope_kind', 'workspace')->where('action', 'business.context.manage')->count());
        // Even a stale SECURITY DEFINER binary owned by the migration owner
        // cannot bypass the terminal table effect guard.
        $db->unprepared(<<<'SQL'
            CREATE FUNCTION builderad_test_stale_commercial(w bigint) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
            BEGIN UPDATE workspaces SET business_context_version=business_context_version+1 WHERE id=w; END $$;
            REVOKE ALL ON FUNCTION builderad_test_stale_commercial(bigint) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION builderad_test_stale_commercial(bigint) TO builderad_app;
            SQL);
        try {
            try {
                DB::transaction(function () use ($actor, $organization) {
                    app(PrincipalContext::class)->activate($actor);
                    DB::scalar('SELECT builderad_v2_legacy_enter(?::uuid)', [$organization->public_id]);
                    DB::scalar('SELECT builderad_select_workspace_context(?)', [$organization->id]);
                    DB::scalar('SELECT builderad_test_stale_commercial(?)', [$organization->id]);
                });
                $this->fail('Stale binary wrote retired facts');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('WORKSPACE_COMMERCIAL_WRITER_RETIRED', $error->getMessage());
            }
        } finally {
            $db->statement('DROP FUNCTION builderad_test_stale_commercial(bigint)');
        }
        $this->assertSame(0, $db->table('workspaces')->where('id', $organization->id)->value('business_context_version'));
        $this->getJson('/workspaces/'.$organization->public_id)->assertOk();
        $history = $this->getJson('/workspaces/'.$organization->public_id.'/commercial-history')->assertOk();
        $history->assertJsonPath('data.read_only', true)->assertJsonPath('data.current_business_inferred', false);
        $this->actingAs(User::factory()->create())->getJson('/workspaces/'.$organization->public_id.'/commercial-history')->assertForbidden();
    }
}
