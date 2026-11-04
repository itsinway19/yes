<?php

namespace Tests\Feature;

use App\Business\AdvertiserGoalPreferences;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BusinessPreferenceVariantsTest extends TestCase
{
    public function test_catalog_results_optional_choices_and_invalid_cross_goal_values_use_only_business_storage(): void
    {
        $this->actingAs(User::factory()->create());
        $file = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent preference owner'])->assertCreated()->json('data');
        $base = '/api/businesses/'.$file['business_public_id'].'/preferences';
        $selection = ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $file['grant_refs']['business.preferences.manage']]]];
        $revision = 0;
        foreach (AdvertiserGoalPreferences::catalog() as $goal) {
            foreach ([[], ...array_map(fn ($result) => [$result['id']], $goal['results'])] as $results) {
                $value = ['goals' => [$goal['id']], 'priority' => $goal['id'], 'results' => [$goal['id'] => $results]];
                $body = ['command_id' => (string) Str::uuid(), 'value' => $value, 'expected_preference' => $revision, 'authority_selection' => $selection];
                $result = $this->putJson($base, $body)->assertOk()->json('data');
                $revision = $result['versions']['preferences'];
                $this->assertEquals($value, $result['value']);
            }
        }
        $valid = ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]];
        $invalid = [
            [...$valid, 'goals' => []], [...$valid, 'goals' => ['sales', 'sales']], [...$valid, 'priority' => 'customers'],
            [...$valid, 'priority' => null], [...$valid, 'priority' => ' sales '], [...$valid, 'results' => ['sales' => ['serious_inquiries']]],
            [...$valid, 'results' => ['sales' => ['actual_sales', 'actual_sales']]], [...$valid, 'results' => ['efficiency' => ['better_results']]],
            [...$valid, 'duration' => 30], [...$valid, 'results' => []],
        ];
        $db = DB::connection('pgsql_migrations');
        $before = $db->table('business_goal_preferences')->sole();
        $events = $db->table('business_preference_transitions')->count();
        foreach ($invalid as $value) {
            $this->putJson($base, ['command_id' => (string) Str::uuid(), 'value' => $value, 'expected_preference' => $revision, 'authority_selection' => $selection])->assertUnprocessable();
        }
        $this->assertEquals($before, $db->table('business_goal_preferences')->sole());
        $this->assertSame($events, $db->table('business_preference_transitions')->count());
        foreach (['workspaces', 'advertiser_goal_preferences', 'advertiser_goal_preference_events', 'object_purposes'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), $table);
        }
        $this->assertSame(0, $db->table('business_context_heads')->value('context_version'));
        $this->assertSame(0, $db->table('business_current_intents')->value('intent_revision'));
    }
}
