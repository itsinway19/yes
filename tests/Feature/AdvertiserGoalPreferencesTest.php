<?php

namespace Tests\Feature;

use App\Business\AdvertiserGoalPreferences;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvertiserGoalPreferencesTest extends TestCase
{
    private object $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $actor = User::factory()->create();
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
        $this->actingAs($actor)->post('/workspaces', ['name' => 'Goals test business', 'slug' => 'goals-test'])->assertRedirect();
        $this->workspace = DB::connection('pgsql_migrations')->table('workspaces')->where('slug', 'goals-test')->first();
    }

    private function url(): string
    {
        return '/workspaces/'.$this->workspace->public_id.'/goals';
    }

    public static function value(): array
    {
        return ['goals' => ['sales', 'customers', 'growth'], 'priority' => 'sales',
            'results' => ['sales' => ['actual_sales'], 'customers' => ['serious_inquiries'], 'growth' => []]];
    }

    private function save(array $value, int $version = 0)
    {
        return $this->postJson($this->url(), ['value' => $value, 'version' => $version]);
    }

    public function test_laravel_migration_rerun_preserves_saved_preferences_and_audit(): void
    {
        $this->save(self::value())->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $before = [$owner->table('advertiser_goal_preferences')->get()->toArray(),
            $owner->table('advertiser_goal_preference_events')->get()->toArray()];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations',
                '--path' => 'database/migrations/0001_01_01_000360_create_advertiser_goal_preferences.php',
                '--force' => true]));
            $this->assertEquals($before, [$owner->table('advertiser_goal_preferences')->get()->toArray(),
                $owner->table('advertiser_goal_preference_events')->get()->toArray()]);
            $this->getJson($this->url())->assertOk()->assertJsonPath('version', 1);
        }
    }

    public function test_post_commit_activity_failure_is_reported_and_does_not_claim_a_rolled_back_save(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $owner->unprepared("CREATE FUNCTION builderad_test_activity_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected post-commit activity failure' USING ERRCODE='P0001'; END $$; CREATE TRIGGER test_activity_failure BEFORE UPDATE ON security_sessions FOR EACH ROW EXECUTE FUNCTION builderad_test_activity_failure()");
        try {
            $this->save(self::value())->assertStatus(500);
            $this->assertSame(1, $owner->table('advertiser_goal_preferences')->count());
            $this->assertSame(1, $owner->table('advertiser_goal_preference_events')->count());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $owner->unprepared('DROP TRIGGER test_activity_failure ON security_sessions; DROP FUNCTION builderad_test_activity_failure()');
        }
    }

    private function protectedState(): array
    {
        $owner = DB::connection('pgsql_migrations');

        return [(array) $owner->table('workspaces')->where('id', $this->workspace->id)->first(),
            $owner->table('business_current_intent_events')->get()->toArray(),
            $owner->table('business_target_markets')->get()->toArray(),
            $owner->table('object_purposes')->get()->toArray(), $owner->table('object_purpose_revisions')->get()->toArray()];
    }

    public function test_persisted_three_goals_results_priority_reload_and_no_implicit_declaration_effect(): void
    {
        $before = $this->protectedState();
        $this->get($this->url())->assertOk()->assertSee('application-bootstrap', false)->assertDontSee('data-page=', false);
        $this->getJson($this->url().'/context')->assertOk()->assertJsonPath('preferences.version', 0)
            ->assertJsonPath('preferences.value.goals', [])->assertJsonCount(4, 'catalog');
        $first = $this->save(self::value())->assertOk()->assertJsonPath('version', 1)->json();
        $this->getJson($this->url())->assertExactJson($first);
        $second = self::value();
        $second['priority'] = 'customers';
        $second['results']['customers'][] = 'closer_to_purchase';
        $saved = $this->save($second, 1)->assertOk()->assertJsonPath('version', 2)->json();
        $this->getJson($this->url())->assertExactJson($saved)->assertJsonPath('value.results.sales', ['actual_sales']);
        $second['priority'] = 'sales';
        $this->save($second, 2)->assertOk()->assertJsonPath('value.results.customers', ['closer_to_purchase', 'serious_inquiries']);
        $this->assertEquals($before, $this->protectedState());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('provider_access')->count());
    }

    public function test_reordered_equal_choices_are_noop_and_stale_conflicts_retain_newer_data(): void
    {
        $first = $this->save(self::value())->assertOk()->json();
        $owner = DB::connection('pgsql_migrations');
        $row = $owner->table('advertiser_goal_preferences')->first();
        $same = self::value();
        $same['goals'] = array_reverse($same['goals']);
        $this->save($same, 1)->assertExactJson($first);
        $this->assertEquals($row, $owner->table('advertiser_goal_preferences')->first());
        $this->assertSame(1, $owner->table('advertiser_goal_preference_events')->count());
        $newer = self::value();
        $newer['priority'] = 'growth';
        $newSaved = $this->save($newer, 1)->assertOk()->json();
        $this->save(self::value(), 1)->assertConflict();
        $this->getJson($this->url())->assertExactJson($newSaved);
        $this->assertSame(2, $owner->table('advertiser_goal_preference_events')->count());
    }

    public function test_invalid_goals_priority_results_and_exact_transport_shapes_have_no_effect(): void
    {
        $invalid = [];
        $value = self::value();
        $value['goals'][] = 'sales';
        $invalid[] = $value;
        $value = self::value();
        $value['goals'][] = 'invented';
        $invalid[] = $value;
        $value = self::value();
        $value['priority'] = 'efficiency';
        $invalid[] = $value;
        $value = self::value();
        $value['priority'] = null;
        $invalid[] = $value;
        $value = self::value();
        $value['results']['sales'] = ['serious_inquiries'];
        $invalid[] = $value;
        $value = self::value();
        $value['results']['efficiency'] = ['better_results'];
        $invalid[] = $value;
        $value = self::value();
        $value['results']['sales'] = ['actual_sales', 'actual_sales'];
        $invalid[] = $value;
        $value = self::value();
        $value['duration'] = 30;
        $invalid[] = $value;
        $value = self::value();
        $value['priority'] = ' sales ';
        $invalid[] = $value;
        $value = self::value();
        $value['goals'] = [];
        $invalid[] = $value;
        foreach ($invalid as $value) {
            $this->save($value)->assertUnprocessable();
        }
        $this->postJson($this->url(), ['value' => self::value(), 'version' => '0'])->assertUnprocessable();
        $this->postJson($this->url(), ['value' => self::value(), 'version' => 0, 'workspace_id' => $this->workspace->id])->assertUnprocessable();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('advertiser_goal_preferences')->count());
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('advertiser_goal_preference_events')->count());
    }

    public function test_every_approved_result_is_accepted_only_for_its_stable_goal_and_results_are_optional(): void
    {
        $version = 0;
        foreach (AdvertiserGoalPreferences::catalog() as $goal) {
            $value = ['goals' => [$goal['id']], 'priority' => $goal['id'], 'results' => [$goal['id'] => array_column($goal['results'], 'id')]];
            $this->save($value, $version++)->assertOk()->assertJsonCount(4, 'value.results.'.$goal['id']);
        }
        $this->save(['goals' => ['sales'], 'priority' => 'sales', 'results' => (object) []], $version)
            ->assertOk()->assertJsonPath('value.results.sales', []);
    }

    public function test_readonly_grant_and_foreign_workspace_and_revoked_session_cannot_write(): void
    {
        $first = $this->save(self::value())->assertOk()->json();
        $actor = auth()->user();
        $owner = DB::connection('pgsql_migrations');
        $owner->table('security_action_grants')->where('subject_user_id', $actor->id)
            ->where('workspace_id', $this->workspace->id)->where('action', 'business.context.manage')->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->getJson($this->url())->assertExactJson($first);
        $this->save(self::value(), 1)->assertForbidden();
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->getJson($this->url())->assertForbidden();
        $this->save(self::value(), 1)->assertForbidden();
        $this->actingAs($actor);
        $owner->table('security_sessions')->where('user_id', $actor->id)->update(['revoked_at' => now()]);
        $this->save(self::value(), 1)->assertStatus(409)->assertJsonPath('identityConflict', true);
        $this->assertSame(1, $owner->table('advertiser_goal_preference_events')->count());
    }

    public function test_runtime_cannot_directly_mutate_preferences_or_audit_and_rls_hides_unselected_workspaces(): void
    {
        $this->save(self::value())->assertOk();
        $this->assertSame([], DB::table('advertiser_goal_preferences')->get()->all());
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => auth()->user());
        foreach (['UPDATE advertiser_goal_preferences SET version=99', 'DELETE FROM advertiser_goal_preferences',
            'UPDATE advertiser_goal_preference_events SET version=99', 'DELETE FROM advertiser_goal_preference_events'] as $sql) {
            try {
                app(WorkspaceContext::class)->run(auth()->user(), $this->workspace->public_id,
                    fn (Workspace $workspace) => DB::statement($sql), $request);
                $this->fail('Runtime direct mutation unexpectedly succeeded');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->getCode());
            }
        }
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('advertiser_goal_preference_events')->count());
    }

    public function test_audit_failure_rolls_back_current_value_atomically(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $owner->unprepared("CREATE FUNCTION test_goal_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Controlled test audit failure' USING ERRCODE='23514'; END $$; CREATE TRIGGER test_goal_audit_failure BEFORE INSERT ON advertiser_goal_preference_events FOR EACH ROW EXECUTE FUNCTION test_goal_audit_failure();");
        try {
            $request = Request::create('/', 'POST');
            $request->setLaravelSession(app('session')->driver());
            $request->setUserResolver(fn () => auth()->user());
            try {
                app(WorkspaceContext::class)->run(auth()->user(), $this->workspace->public_id,
                    fn (Workspace $workspace) => app(AdvertiserGoalPreferences::class)->write($request, $workspace, self::value(), 0), $request);
                $this->fail('Audit failure unexpectedly committed');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->getCode());
            }
            $this->assertSame(0, $owner->table('advertiser_goal_preferences')->count());
        } finally {
            $owner->unprepared('DROP TRIGGER test_goal_audit_failure ON advertiser_goal_preference_events; DROP FUNCTION test_goal_audit_failure()');
        }
    }

    public function test_two_actual_concurrent_writers_cannot_silently_overwrite(): void
    {
        $this->save(self::value())->assertOk();
        $run = storage_path('framework/testing/goals-race-'.bin2hex(random_bytes(8)));
        mkdir($run, 0700, true);
        $workers = [];
        try {
            foreach (['customers', 'growth'] as $priority) {
                $value = self::value();
                $value['priority'] = $priority;
                $fixture = ['actor' => auth()->id(), 'workspace' => $this->workspace->id,
                    'session' => hash('sha256', app('session')->driver()->getId()),
                    'version' => 1, 'value' => $value, 'ready' => $run.'/'.$priority.'.ready', 'release' => $run.'/release'];
                $path = $run.'/'.$priority.'.json';
                file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/AdvertiserGoalsCommandWorker.php'), $path], base_path());
                $worker->setTimeout(35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            while (! is_file($run.'/customers.ready') || ! is_file($run.'/growth.ready')) {
                $this->assertLessThan($deadline, microtime(true), 'Workers did not become ready');
                clearstatcache();
                usleep(10000);
            }
            file_put_contents($run.'/release', 'release');
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outputs[] = $worker->getOutput();
            }
            $this->assertCount(1, array_filter($outputs, fn ($output) => str_contains($output, 'SAVED=')));
            $this->assertCount(1, array_filter($outputs, fn ($output) => str_contains($output, 'STATE=40001')));
            $this->getJson($this->url())->assertJsonPath('version', 2);
            $this->assertSame(2, DB::connection('pgsql_migrations')->table('advertiser_goal_preference_events')->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($run.'/*') as $path) {
                unlink($path);
            }
            rmdir($run);
        }
    }
}
