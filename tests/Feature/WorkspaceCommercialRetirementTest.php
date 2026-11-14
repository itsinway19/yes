<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceCommercialRetirementTest extends TestCase
{
    public function test_retired_workspace_commercial_http_and_sql_writers_are_absent(): void
    {
        $this->actingAs(User::factory()->create());
        $id = (string) Str::uuid7();
        foreach (['business', 'business/authority', 'business/target-markets', 'business/current-intent', 'goals'] as $suffix) {
            $this->postJson('/workspaces/'.$id.'/'.$suffix, ['version' => 0])->assertNotFound();
        }
        foreach (['business', 'goals', 'goals/review', 'goals/context', 'commercial-history'] as $suffix) {
            $this->getJson('/workspaces/'.$id.'/'.$suffix)->assertNotFound();
        }
        $db = DB::connection('pgsql_migrations');
        foreach (['builderad_business_context_write(bigint,text,bigint,text,bigint)',
            'builderad_business_target_markets_write(bigint,text,text[],bigint)',
            'builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)',
            'builderad_advertiser_goals_write(bigint,text,jsonb,bigint)',
            'builderad_v2_legacy_context_grant(bigint,text)',
            'builderad_private.legacy_creation_grant(bigint,bigint,bigint)'] as $signature) {
            $this->assertNull($db->scalar('SELECT to_regprocedure(?)', [$signature]), $signature);
        }
        foreach (['workspaces', 'business_context_events', 'business_target_markets',
            'business_current_intent_events', 'advertiser_goal_preferences'] as $table) {
            $this->assertNull($db->scalar('SELECT to_regclass(?)', ['public.'.$table]), $table);
        }
    }
}
