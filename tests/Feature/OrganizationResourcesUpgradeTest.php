<?php

namespace Tests\Feature;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class OrganizationResourcesUpgradeTest extends TestCase
{
    public function test_populated_organizational_upgrade_preserves_native_facts_origins_grants_and_membership(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000590_organization_resources.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $actor = User::factory()->create();
            app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
            $this->actingAs($actor);
            $request = Request::create('/organizations');
            $request->setUserResolver(fn () => $actor);
            $request->setLaravelSession(app('session')->driver());
            $session = SessionAuthority::sessionKey($request);
            $publicId = DB::transaction(function () use ($actor, $session) {
                app(PrincipalContext::class)->activate($actor);

                return DB::scalar('SELECT builderad_create_workspace(?,?,?,?)', [$actor->id, $session, 'Original qualified record', 'upgrade-organization']);
            });
            $db = DB::connection('pgsql_migrations');
            $id = $db->table('workspaces')->where('public_id', $publicId)->value('id');
            DB::transaction(function () use ($actor, $publicId, $id, $session) {
                app(PrincipalContext::class)->activate($actor);
                DB::scalar('SELECT builderad_v2_legacy_enter(?::uuid)', [$publicId]);
                DB::scalar('SELECT builderad_business_context_begin(?,?)', [$id, $session]);
                DB::scalar("SELECT builderad_business_target_markets_write(?,?,ARRAY['SA'],0)", [$id, $session]);
            });
            $tables = ['workspaces', 'workspace_memberships', 'workspace_ownerships', 'security_action_grants', 'business_target_markets', 'business_target_market_events', 'business_context_events'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($tables as $table) {
                $this->assertSame($before[$table], $db->scalar("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]')::text FROM {$table} r"), $table);
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $preservedName = $db->table('legacy_workspace_commercial_names')->where('workspace_id', $id)->value('name');
            $this->assertSame('Original qualified record', $preservedName);
            foreach (["UPDATE legacy_workspace_commercial_names SET name='Lost origin' WHERE workspace_id=".(int) $id] as $forbidden) {
                try {
                    $db->transaction(fn () => $db->statement($forbidden));
                    $this->fail('Original commercial name was mutable');
                } catch (QueryException $error) {
                    $this->assertSame('42501', $error->errorInfo[0]);
                    $this->assertStringContainsString('WORKSPACE_COMMERCIAL_WRITER_RETIRED', $error->getMessage());
                }
            }
            // The existing guard_effect permits only marked disposable-owner
            // fixture cleanup. The runtime role has no archive truncation right.
            try {
                DB::transaction(fn () => DB::statement('TRUNCATE legacy_workspace_commercial_names'));
                $this->fail('Runtime truncated the original commercial names');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
            }
            $db->table('workspaces')->where('id', $id)->update(['name' => 'Independent organizational label']);
            $this->assertSame($preservedName, $db->table('legacy_workspace_commercial_names')->where('workspace_id', $id)->value('name'));
            $cohort = $db->table('migration_cohort_members')->where('workspace_id', $id)->value('cohort_id');
            $db->scalar("SELECT builderad_v2_cohort_transition(?::uuid,?::uuid,'LEGACY_ACTIVE',0,'DRAINING',NULL,decode(?,'hex'))",
                [(string) Str::uuid(), $cohort, hash('sha256', 'preserved synthetic upgrade')]);
            $this->actingAs($actor)->getJson('/workspaces/'.$publicId.'/foundation')->assertOk()->assertJsonPath('workspace.public_id', $publicId);
            $this->getJson('/workspaces')->assertOk();
            $this->assertSame(1, $db->table('business_target_market_events')->count());
            $this->assertSame(0, $db->table('businesses')->count());
        } finally {
            DB::disconnect('pgsql');
            $reset->install();
        }
    }
}
