<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BusinessScopeFoundationTest extends TestCase
{
    private function owner()
    {
        return DB::connection('pgsql_migrations');
    }

    private function request(User $user): Request
    {
        $this->actingAs($user);
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    private function draft(User $user, string $name = 'Independent business'): array
    {
        return app(BusinessScope::class)->create($this->request($user), (string) Str::uuid(), $name, null);
    }

    private function selection(array $draft, string $action = 'business.context.read'): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $draft['grant_refs'][$action]]]];
    }

    private function denied(callable $operation, string $state = '42501'): void
    {
        try {
            $operation();
            $this->fail('Unauthorized database operation succeeded.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
        }
    }

    public function test_genesis_is_independent_atomic_bounded_and_has_no_preferences_row(): void
    {
        $user = User::factory()->create();
        $result = $this->draft($user);
        $this->assertTrue(Str::isUuid($result['business_public_id']));
        $this->assertSame('declared_draft', $result['state']);
        $this->assertSame(1, $this->owner()->table('businesses')->count());
        $this->assertSame(0, $this->owner()->table('workspaces')->count());
        $this->assertSame(0, $this->owner()->table('business_goal_preferences')->count());
        $this->assertSame(1, $this->owner()->table('business_current_intents')->whereNull('value')->count());
        $this->assertSame(1, $this->owner()->table('migration_cohort_control')->where('mode', 'V2_ACTIVE')->count());
        $this->assertSame(7, $this->owner()->table('security_action_grants')->where('scope_kind', 'business')->where('delegable', false)->count());
        $this->assertSame(0, $this->owner()->table('security_action_grants')->where('scope_kind', 'source')->count());
        $this->assertCount(3, $result['versions']);
        foreach (['context', 'intent', 'preferences'] as $version) {
            $this->assertSame(0, $result['versions'][$version]);
        }
    }

    public function test_creation_replay_is_idempotent_but_changed_payload_conflicts(): void
    {
        $request = $this->request(User::factory()->create());
        $command = (string) Str::uuid();
        $scope = app(BusinessScope::class);
        $first = $scope->create($request, $command, 'My business', null);
        $this->assertSame($first, $scope->create($request, $command, 'My business', null));
        $this->assertSame(1, $this->owner()->table('businesses')->count());
        $this->denied(fn () => $scope->create($request, $command, 'Other business', null), '40001');
    }

    public function test_two_businesses_are_read_by_exact_current_authority_not_guessed_subject(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $a = $this->draft($alice, 'Alice private');
        $b = $this->draft($bob, 'Bob private');
        $scope = app(BusinessScope::class);
        $this->assertSame('Alice private', $scope->read($this->request($alice), $a['business_public_id'], $this->selection($a))['name']);
        $this->denied(fn () => $scope->read($this->request($bob), $a['business_public_id'], $this->selection($a)));
        $this->denied(fn () => $scope->read($this->request($alice), $b['business_public_id'], $this->selection($a)));
        $this->denied(fn () => $scope->read($this->request($alice), $a['business_public_id'], []));
    }

    public function test_raw_runtime_reads_writes_guc_forgery_and_role_switch_do_not_bypass_database(): void
    {
        $a = $this->draft(User::factory()->create());
        $this->denied(fn () => DB::select('SELECT * FROM businesses'));
        $this->denied(fn () => DB::statement("UPDATE business_context_heads SET display_name='Injected'"));
        $this->denied(fn () => DB::statement('SET ROLE builderad_scope_executor'));
        $this->denied(fn () => DB::statement('TRUNCATE businesses CASCADE'));
        $this->denied(fn () => DB::select('SELECT * FROM builderad_private.command_permits'));
        $this->denied(fn () => DB::transaction(function () use ($a): void {
            DB::statement("SELECT set_config('app.principal_user_id','1',true), set_config('app.signed_context','forged',true)");
            DB::select('SELECT builderad_v2_business_read(?,?,?::jsonb)', ['fake', $a['business_public_id'], json_encode($this->selection($a))]);
        }));
    }

    public static function principalReuseFailures(): array
    {
        return ['exception rollback' => [false], 'statement timeout rollback' => [true]];
    }

    #[DataProvider('principalReuseFailures')]
    public function test_same_runtime_backend_requalifies_each_principal_after_commit_and_rollback(bool $timeout): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $a = $this->draft($alice, 'Alice on reused backend');
        $b = $this->draft($bob, 'Bob on reused backend');
        $scope = app(BusinessScope::class);
        $connection = DB::connection();
        $pid = $connection->scalar('SELECT pg_backend_pid()');
        $this->assertSame('Alice on reused backend', $scope->read($this->request($alice), $a['business_public_id'], $this->selection($a))['name']);

        $aliceRequest = $this->request($alice);
        $rollback = new \RuntimeException('Synthetic exception after a qualified read');
        try {
            $connection->transaction(function () use ($connection, $alice, $aliceRequest, $a, $pid, $rollback, $timeout): void {
                app(PrincipalContext::class)->activate($alice);
                $this->assertSame($pid, $connection->scalar('SELECT pg_backend_pid()'));
                $result = $connection->scalar('SELECT builderad_v2_business_read(?,?::uuid,?::jsonb)', [
                    SessionAuthority::sessionKey($aliceRequest), $a['business_public_id'], json_encode($this->selection($a), JSON_THROW_ON_ERROR),
                ]);
                $this->assertSame('Alice on reused backend', json_decode($result, true, flags: JSON_THROW_ON_ERROR)['name']);
                if ($timeout) {
                    $connection->statement("SET LOCAL statement_timeout='30ms'");
                    $connection->select('SELECT pg_sleep(1)');
                    $this->fail('The controlled statement timeout did not fire.');
                }

                throw $rollback;
            });
        } catch (QueryException $error) {
            $this->assertTrue($timeout);
            $this->assertSame('57014', $error->errorInfo[0]);
        } catch (\RuntimeException $error) {
            $this->assertFalse($timeout);
            $this->assertSame($rollback, $error);
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($pid, $connection->scalar('SELECT pg_backend_pid()'));
        $this->assertSame('builderad_app', $connection->scalar('SELECT current_user'));
        $this->assertSame(0, $connection->scalar("SELECT count(*) FROM unnest(ARRAY['app.authorization_phase','app.principal_user_id','app.workspace_id','app.signed_context']) AS settings(key) WHERE nullif(current_setting(key,true),'') IS NOT NULL"));
        $this->denied(fn () => $scope->read($this->request($bob), $a['business_public_id'], $this->selection($a)));
        $this->assertSame('Bob on reused backend', $scope->read($this->request($bob), $b['business_public_id'], $this->selection($b))['name']);
        $this->assertSame($pid, $connection->scalar('SELECT pg_backend_pid()'));
    }

    public function test_authority_connection_cannot_directly_mutate_or_read_new_business_grants(): void
    {
        $a = $this->draft(User::factory()->create());
        $this->assertSame([], DB::connection('pgsql_authority')->select("SELECT id FROM security_action_grants WHERE scope_kind='business'"));
        $this->assertSame(0, DB::connection('pgsql_authority')->affectingStatement(
            "UPDATE security_action_grants SET status='revoked',version=version+1 WHERE id=?",
            [$a['grant_refs']['business.context.read']],
        ));
        $this->assertSame('active', $this->owner()->table('security_action_grants')->where('id', $a['grant_refs']['business.context.read'])->value('status'));
    }

    public function test_expired_session_or_changed_lifecycle_denies_even_with_original_grant(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $request = $this->request($user);
        $this->owner()->table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->update(['idle_expires_at' => now()->subMinute()]);
        $this->denied(fn () => app(BusinessScope::class)->read($request, $a['business_public_id'], $this->selection($a)));
        $this->owner()->table('principal_lifecycles')->where('user_id', $user->id)->increment('generation');
        $this->denied(fn () => app(BusinessScope::class)->read($request, $a['business_public_id'], $this->selection($a)));
    }

    public function test_http_creation_and_explicit_read_selection_use_real_kernel(): void
    {
        $this->actingAs(User::factory()->create());
        $result = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'HTTP business'])->assertCreated()->json('data');
        $this->postJson('/api/businesses/'.$result['business_public_id'].'/read', ['authority_selection' => $this->selection($result)])
            ->assertOk()->assertJsonPath('data.name', 'HTTP business');
        $this->actingAs(User::factory()->create())->postJson('/api/businesses/'.$result['business_public_id'].'/read', ['authority_selection' => $this->selection($result)])->assertForbidden();
    }

    public function test_two_businesses_link_to_same_workspace_without_merging_identity_or_authority(): void
    {
        $alice = User::factory()->create();
        $request = $this->request($alice);
        app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($alice, 1);
        $this->post('/workspaces', ['name' => 'Collaboration label', 'slug' => 'shared-team'])->assertRedirect();
        $workspace = $this->owner()->table('workspaces')->where('slug', 'shared-team')->first();
        $a = $this->draft($alice, 'Business A');
        $b = $this->draft($alice, 'Business B');
        $request = $this->request($alice);
        foreach ([$a, $b] as $draft) {
            app(BusinessScope::class)->link($request, $draft['business_public_id'], $workspace->public_id, (string) Str::uuid(), 'add', 0, $this->selection($draft, 'business.link.manage'));
        }
        $this->assertSame(2, $this->owner()->table('business_workspace_links')->where('workspace_id', $workspace->id)->count());
        $this->assertSame('Collaboration label', $this->owner()->table('workspaces')->where('id', $workspace->id)->value('name'));
        $this->assertNotSame($workspace->public_id, $a['business_public_id']);
        $this->assertSame('Business A', app(BusinessScope::class)->read($request, $a['business_public_id'], $this->selection($a))['name']);
        $bob = User::factory()->create();
        $this->owner()->table('workspace_memberships')->insert(['workspace_id' => $workspace->id, 'user_id' => $bob->id, 'role' => 'member', 'status' => 'active', 'created_at' => now()]);
        $this->denied(fn () => app(BusinessScope::class)->read($this->request($bob), $a['business_public_id'], $this->selection($a)));
    }

    private function intentValue(): array
    {
        return ['description' => null, 'direction' => 'increase_value', 'scope' => 'business_wide',
            'outcome' => ['meaning' => 'Qualified purchases', 'clarification' => null, 'count_unit' => null],
            'quantity' => null, 'horizon' => null];
    }

    public function test_material_facts_share_context_but_intent_has_one_independent_writer(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $r = $this->request($user);
        $scope = app(BusinessScope::class);
        $context = $scope->context($r, $a['business_public_id'], (string) Str::uuid(), 'Updated name', null, 0, $this->selection($a, 'business.context.manage'));
        $this->assertSame(1, $context['versions']['context']);
        $this->denied(fn () => $scope->markets($r, $a['business_public_id'], (string) Str::uuid(), ['SA'], 0, $this->selection($a, 'business.markets.declare')), '40001');
        $markets = $scope->markets($r, $a['business_public_id'], (string) Str::uuid(), ['SA', 'JO', 'SA'], 1, $this->selection($a, 'business.markets.declare'));
        $this->assertSame(['JO', 'SA'], $markets['codes']);
        $this->assertSame(2, $markets['versions']['context']);
        $value = $this->intentValue();
        $confirm = ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true];
        $intent = $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), $value, $confirm, 2, 0, $this->selection($a, 'business.intent.declare'));
        $this->assertSame(3, $intent['versions']['context']);
        $this->assertSame(1, $intent['versions']['intent']);
        $this->assertSame(1, $this->owner()->table('business_context_transitions')->count());
        $this->assertSame(1, $this->owner()->table('business_market_transitions')->count());
        $this->assertSame(1, $this->owner()->table('business_intent_transitions')->count());
        $head = $this->owner()->table('business_current_intents')->first();
        $this->assertSame($head->last_event_id, $this->owner()->table('business_intent_transitions')->value('event_id'));
        $this->denied(fn () => $scope->markets($r, $a['business_public_id'], (string) Str::uuid(), ['ZZ'], 3, $this->selection($a, 'business.markets.declare')), '22023');
    }

    public function test_intent_equal_stale_tokens_is_noop_but_material_stale_or_missing_confirmation_is_denied(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $r = $this->request($user);
        $scope = app(BusinessScope::class);
        $selection = $this->selection($a, 'business.intent.declare');
        $v = $this->intentValue();
        $confirm = ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true];
        $this->denied(fn () => $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), $v, ['declaration' => true], 0, 0, $selection), '22023');
        $first = $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), $v, $confirm, 0, 0, $selection);
        $same = $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), $v, $confirm, 0, 0, $selection);
        $this->assertSame($first, $same);
        $this->assertSame(1, $this->owner()->table('business_intent_transitions')->count());
        $this->denied(fn () => $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), null, ['declaration' => true], 0, 0, $selection), '40001');
        $cleared = $scope->intent($r, $a['business_public_id'], (string) Str::uuid(), null, ['declaration' => true], 1, 1, $selection);
        $this->assertNull($cleared['value']);
        $this->assertSame(2, $cleared['versions']['intent']);
    }

    public function test_preference_absence_first_insert_and_cas_first_never_mutate_context(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $r = $this->request($user);
        $scope = app(BusinessScope::class);
        $selection = $this->selection($a, 'business.preferences.manage');
        $value = ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]];
        $command = (string) Str::uuid();
        $first = $scope->preferences($r, $a['business_public_id'], $command, $value, 0, $selection);
        $this->assertSame(1, $first['versions']['preferences']);
        $this->assertNull($this->owner()->table('business_preference_transitions')->value('before_value'));
        $this->assertSame(0, $this->owner()->table('business_context_heads')->value('context_version'));
        $this->assertSame(0, $this->owner()->table('business_current_intents')->value('intent_revision'));
        $this->assertSame($first, $scope->preferences($r, $a['business_public_id'], $command, $value, 0, $selection));
        $this->denied(fn () => $scope->preferences($r, $a['business_public_id'], (string) Str::uuid(), $value, 0, $selection), '40001');
        $this->assertSame($first, $scope->preferences($r, $a['business_public_id'], (string) Str::uuid(), $value, 1, $selection));
        $this->assertSame(1, $this->owner()->table('business_preference_transitions')->count());
        $this->denied(fn () => $scope->preferences($r, $a['business_public_id'], (string) Str::uuid(), ['goals' => [], 'priority' => '', 'results' => []], 1, $selection), '22023');
    }

    private function scopeWait(callable $probe, array $workers): mixed
    {
        $deadline = microtime(true) + 25;
        do {
            if ($value = $probe()) {
                return $value;
            }
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    $this->fail('Participant exited before required observation: '.$worker->getOutput().$worker->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Required actual contention was not observed.');
    }

    public function test_two_real_connections_contend_for_one_context_and_only_one_material_cas_commits(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $r = $this->request($user);
        $this->scopeRace($user, $a, $r, false);
    }

    public function test_first_preference_write_completes_while_context_head_is_locked(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $r = $this->request($user);
        $this->scopeRace($user, $a, $r, true);
    }

    private function scopeRace(User $user, array $a, Request $r, bool $preferences): void
    {
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), 0777, true);
        }
        $run = 'business-race-'.Str::uuid();
        $paths = [];
        $workers = [];
        $evidence = ['run' => $run, 'mode' => $preferences ? 'independent_preferences' : 'context_cas'];
        $release = storage_path('framework/testing/'.$run.'.release');
        $owner = $this->owner();
        try {
            for ($i = 0; $i < ($preferences ? 1 : 2); $i++) {
                $fixture = ['run' => $run, 'actor' => $user->id, 'business' => $a['business_public_id'],
                    'session' => SessionAuthority::sessionKey($r), 'command' => (string) Str::uuid(),
                    'operation' => $preferences ? 'preferences' : 'context', 'release' => $release, 'expected' => 0,
                    'name' => 'Concurrent name '.$i, 'selection' => $this->selection($a, $preferences ? 'business.preferences.manage' : 'business.context.manage'),
                    'value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => ['sales' => ['actual_sales']]]];
                $path = storage_path('framework/testing/'.$run.'-'.$i.'.json');
                file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
                $paths[] = $path;
                $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
                $worker->setTimeout(45);
                $worker->start();
                $workers[] = $worker;
            }
            $this->scopeWait(fn () => count(array_filter($workers, fn ($w) => str_contains($w->getOutput(), 'READY_PID='))) === count($workers), $workers);
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->select('SELECT * FROM business_context_heads FOR UPDATE');
            file_put_contents($release, 'release');
            if ($preferences) {
                $workers[0]->wait();
                $this->assertSame(0, $workers[0]->getExitCode(), $workers[0]->getOutput().$workers[0]->getErrorOutput());
                $this->assertStringContainsString('COMMITTED=', $workers[0]->getOutput());
                $this->assertSame(1, $owner->transactionLevel());
                $this->assertSame(0, $owner->table('business_context_heads')->value('context_version'));
                $this->assertSame(1, $owner->table('business_goal_preferences')->value('preference_version'));
                $owner->commit();
            } else {
                $evidence['waiters'] = $this->scopeWait(function () use ($run, $blocker) {
                    $rows = DB::select('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? ORDER BY pid', [$run]);
                    $waiting = array_filter($rows, fn ($row) => $row->wait_event_type === 'Lock' && $row->blockers !== '{}');

                    return count($waiting) === 2 && count(array_filter($waiting, fn ($row) => str_contains($row->blockers, (string) $blocker))) > 0 ? $rows : false;
                }, $workers);
                $owner->commit();
                foreach ($workers as $worker) {
                    $worker->wait();
                }
                $codes = array_map(fn ($w) => $w->getExitCode(), $workers);
                sort($codes);
                $this->assertSame([0, 2], $codes, implode('\n', array_map(fn ($w) => $w->getOutput().$w->getErrorOutput(), $workers)));
                $this->assertSame(1, count(array_filter($workers, fn ($w) => str_contains($w->getOutput(), 'DENIED_STATE=40001'))));
                $this->assertSame(1, $owner->table('business_context_heads')->value('context_version'));
                $this->assertSame(1, $owner->table('business_context_transitions')->count());
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
                $evidence['workers'][] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            }
            file_put_contents(storage_path('framework/testing/'.$run.'-evidence.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$release, ...$paths] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_http_fact_commands_preserve_json_types_cas_and_actual_readback(): void
    {
        $user = User::factory()->create();
        $a = $this->draft($user);
        $this->actingAs($user);
        $base = '/api/businesses/'.$a['business_public_id'];
        $this->putJson($base.'/context', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, 'business.context.manage'),
            'name' => 'HTTP changed', 'business_type_id' => null, 'expected_context' => 0])->assertOk()->assertJsonPath('data.versions.context', 1);
        $this->putJson($base.'/preferences', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, 'business.preferences.manage'),
            'value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => (object) []], 'expected_preference' => 0])->assertOk()->assertJsonPath('data.versions.preferences', 1);
        $this->putJson($base.'/preferences', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, 'business.preferences.manage'),
            'value' => ['goals' => ['sales'], 'priority' => 'sales', 'results' => []], 'expected_preference' => 1])->assertUnprocessable();
        $this->putJson($base.'/markets', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, 'business.markets.declare'),
            'codes' => ['SA'], 'expected_context' => 0])->assertConflict();
        $this->putJson($base.'/markets', ['command_id' => (string) Str::uuid(), 'authority_selection' => $this->selection($a, 'business.markets.declare'),
            'codes' => ['SA'], 'expected_context' => 1])->assertOk()->assertJsonPath('data.versions.context', 2);
        $this->postJson($base.'/read', ['authority_selection' => $this->selection($a)])->assertOk()->assertJsonPath('data.name', 'HTTP changed')
            ->assertJsonPath('data.declared_markets.0', 'SA')->assertJsonPath('data.preferences.priority', 'sales');
    }

    public function test_current_session_is_locked_before_selected_grants_and_rechecked_before_effect(): void
    {
        $u = User::factory()->create();
        $a = $this->draft($u);
        $request = $this->request($u);
        $owner = $this->owner();
        $run = 'session-before-grant-'.Str::uuid();
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $release = $dir.'/'.$run.'.release';
        $file = $dir.'/'.$run.'.json';
        file_put_contents($file, json_encode(['actor' => $u->id, 'run' => $run, 'session' => SessionAuthority::sessionKey($request),
            'command' => (string) Str::uuid(), 'release' => $release, 'operation' => 'context', 'business' => $a['business_public_id'],
            'name' => 'Session-qualified write', 'expected' => 0, 'selection' => $this->selection($a, 'business.context.manage')], JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $file], base_path());
        $worker->setTimeout(45);
        $observer = DB::build(config('database.connections.pgsql'));
        $evidence = ['run' => $run];
        try {
            $observer->getPdo();
            $worker->start();
            $this->scopeWait(fn () => str_contains($worker->getOutput(), 'READY_PID='), [$worker]);
            $owner->beginTransaction();
            $owner->selectOne('SELECT id FROM security_sessions WHERE user_id=? AND session_key_hash=? FOR UPDATE', [$u->id, SessionAuthority::sessionKey($request)]);
            $holder = $owner->scalar('SELECT pg_backend_pid()');
            file_put_contents($release, 'release');
            $evidence['waiter'] = $this->scopeWait(function () use ($observer, $run, $holder) {
                $row = $observer->selectOne('SELECT pid,backend_xid::text AS xid,wait_event_type,wait_event,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=?', [$run]);

                return $row?->wait_event_type === 'Lock' && str_contains($row->blockers, (string) $holder) ? $row : false;
            }, [$worker]);
            $grant = $owner->selectOne('SELECT id FROM security_action_grants WHERE id=? FOR UPDATE NOWAIT', [$a['grant_refs']['business.context.manage']]);
            $this->assertSame($a['grant_refs']['business.context.manage'], $grant->id);
            $evidence['selected_grant_was_unlocked'] = true;
            // Native session revocation becomes effective before releasing this exact wait.
            $owner->table('security_sessions')->where('user_id', $u->id)->where('session_key_hash', SessionAuthority::sessionKey($request))->update(['revoked_at' => now()]);
            $owner->commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $this->assertSame(0, $owner->table('business_context_transitions')->count());
            $this->assertSame('Independent business', $owner->table('business_context_heads')->value('display_name'));
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['exit' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
            file_put_contents($dir.'/'.$run.'-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $observer->disconnect();
            foreach ([$release, $file] as $ownedFile) {
                if (is_file($ownedFile)) {
                    unlink($ownedFile);
                }
            }
        }
    }
}
