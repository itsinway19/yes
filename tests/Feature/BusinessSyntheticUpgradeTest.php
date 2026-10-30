<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessSyntheticUpgradeTest extends TestCase
{
    public function test_populated_v1_upgrade_preserves_native_history_and_registers_only_legacy_coordination(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        try {
            $reset->clear();
            $paths = array_map(fn ($path) => 'database/migrations/'.basename($path), array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => strcmp(basename($path), '0001_01_01_000420_create_business_scope_foundation.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--force' => true]));
            $actor = User::factory()->create();
            $db = DB::connection('pgsql_migrations');
            $intent = json_encode(['description' => null, 'direction' => 'increase_value', 'scope' => 'business_wide',
                'outcome' => ['meaning' => 'Retained native declaration', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null], JSON_THROW_ON_ERROR);
            $ids = [];
            for ($i = 0; $i < 2; $i++) {
                $id = $db->table('workspaces')->insertGetId(['name' => 'Unresolved native '.$i, 'slug' => 'upgrade-native-'.$i, 'status' => 'active',
                    'timezone' => 'UTC', 'business_context_version' => 1, 'current_intent' => $intent]);
                $ids[] = $id;
                $db->table('workspace_memberships')->insert(['workspace_id' => $id, 'user_id' => $actor->id, 'role' => 'owner', 'status' => 'active']);
                $db->table('workspace_ownerships')->insert(['workspace_id' => $id, 'responsible_owner_user_id' => $actor->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $db->table('business_current_intent_events')->insert(['workspace_id' => $id, 'actor_user_id' => $actor->id, 'before_version' => 0, 'after_version' => 1,
                    'before_value' => null, 'after_value' => $intent, 'occurred_at' => '2026-10-01T12:00:00+00:00']);
            }
            $snapshot = fn () => $db->select("SELECT id,encode(sha256(convert_to((to_jsonb(w)-'scope_lock_marker')::text,'UTF8')),'hex') AS digest FROM workspaces w ORDER BY id");
            $before = $snapshot();
            $events = $db->table('business_current_intent_events')->orderBy('id')->get()->all();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => ['database/migrations/0001_01_01_000420_create_business_scope_foundation.php'], '--force' => true]));
            $this->assertEquals($before, $snapshot());
            $this->assertEquals($events, $db->table('business_current_intent_events')->orderBy('id')->get()->all());
            $this->assertSame(0, $db->table('businesses')->count());
            $this->assertSame(0, $db->table('business_current_intents')->count());
            $this->assertSame(0, $db->table('business_goal_preferences')->count());
            $this->assertSame(0, $db->table('legacy_scope_mappings')->count());
            $this->assertSame(0, $db->table('security_action_grants')->whereIn('scope_kind', ['business', 'client_data', 'data_use'])->count());
            $this->assertSame(2, $db->table('migration_cohort_members')->where('kind', 'legacy_workspace')->whereIn('workspace_id', $ids)->count());
            $this->assertSame(2, $db->table('migration_cohort_control')->where('mode', 'LEGACY_ACTIVE')->where('guard_schema_version', 1)->count());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertEquals($before, $snapshot());
            $this->assertSame(2, $db->table('migration_cohort_members')->count());
        } finally {
            $reset->install();
        }
    }
}
