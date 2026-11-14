<?php

namespace Tests\Feature\Security;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\LifecycleAuthority;
use App\Security\SessionAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationContractAssertions;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

class CustomerAdministrationTest extends TestCase
{
    use ApplicationContractAssertions;

    public function test_services_read_requires_existing_grant_and_uses_its_signed_principal(): void
    {
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->getJson('/internal/services')->assertForbidden();
        $db = DB::connection('pgsql_migrations');
        $db->table('security_action_grants')->insert([
            'subject_user_id' => $founder->id, 'source' => 'founder_bootstrap', 'action' => 'service.manage',
            'scope_kind' => 'platform', 'subject_generation' => 1, 'reason' => 'Services directory fixture',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->getJson('/internal/services')->assertOk()->assertJsonPath('data.services', [])
            ->assertJsonPath('data.scopes', [])->assertJsonPath('data.grants', []);
        $this->assertApplicationContract('InternalServicesResource', $response->json());
        $this->actingAsInternal($this->staff())->getJson('/internal/services')->assertForbidden();
    }

    public function test_business_directory_filters_and_details_match_the_contract(): void
    {
        [, $business] = $this->business();
        $this->actingAsInternal($this->staff('founder'));
        $url = '/internal/administration/customers?kind=businesses&status=declared_draft&search=Customer%20Business';
        $response = $this->getJson($url.'&selected='.$business['business_public_id'])->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.public_id', $business['business_public_id'])
            ->assertJsonPath('data.details.name', 'Customer Business');
        $this->assertApplicationContract('InternalCustomersResource', $response->json());
        $this->getJson('/internal/administration/customers?kind=workspaces')->assertUnprocessable();
        $this->actingAsInternal($this->staff())->getJson($url)->assertForbidden();
    }

    public function test_directory_is_founder_only_searchable_and_excludes_internal_personnel(): void
    {
        $customer = User::factory()->create(['name' => 'Customer Proof']);
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->getJson('/internal/administration/customers?search=Customer%20Proof')
            ->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.public_id', $customer->public_id)
            ->assertJsonPath('data.total', 1)->assertJsonMissingPath('data.rows.0.password');
        $this->getJson('/internal/administration/customers?selected='.$customer->public_id)->assertOk()
            ->assertJsonPath('data.details.name', 'Customer Proof');
        $this->actingAsInternal($this->staff())->get('/internal/administration/customers')->assertForbidden();
    }

    public function test_account_suspension_is_atomic_replayable_and_reactivation_requires_new_session_for_original_rights(): void
    {
        [$customer, $business] = $this->business();
        $db = DB::connection('pgsql_migrations');
        $sessions = $db->table('security_sessions')->where('user_id', $customer->id)->count();
        $this->assertGreaterThan(0, $sessions);
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $input = $this->decision('users', $customer->public_id, 'current', 1);
        $this->postJson('/internal/administration/customers/status', $input)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/internal/administration/customers/status', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/internal/administration/customers/status', [...$input, 'reason' => 'changed'])->assertConflict();
        $this->assertSame('declared_draft', $db->table('businesses')->where('public_id', $business['business_public_id'])->value('lifecycle'));
        $this->assertSame(0, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(1, $db->table('customer_administration_events')->count());
        $this->assertFalse(app(LifecycleAuthority::class)->isCurrent($customer, 1));
        $restore = $this->decision('users', $customer->public_id, 'disabled', 2, 'reactivate');
        $this->postJson('/internal/administration/customers/status', $restore)->assertOk()->assertJsonPath('revision', 3);
        $this->assertSame(0, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $customer->id)->where('subject_generation', 3)->count());
        $this->assertFalse(app(LifecycleAuthority::class)->isCurrent($customer, 1));
    }

    public function test_business_suspension_restores_exact_prior_draft_state_and_keeps_content_private(): void
    {
        $this->usePersistentTestSessions();
        [$customer, $business] = $this->business();
        $target = $business['business_public_id'];
        $surface = '/api/businesses/'.$target.'/surface?access='.$business['grant_refs']['business.context.read'];
        $this->getJson($surface)->assertOk();
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        $suspend = $this->decision('businesses', $target, 'declared_draft', 0);
        $this->postJson('/internal/administration/customers/status', $suspend)->assertOk()->assertJsonPath('status', 'suspended');
        $this->postJson('/internal/administration/customers/status', $suspend)->assertOk()->assertJsonPath('replayed', true);
        $this->getJson('/internal/administration/customers?kind=businesses&selected='.$target)->assertOk()
            ->assertJsonPath('data.rows.0.status', 'suspended')->assertJsonCount(1, 'data.details.history')
            ->assertJsonMissingPath('data.details.sources')->assertJsonMissingPath('data.details.content');
        $this->actingAs($customer)->getJson($surface)->assertForbidden();
        $this->actingAsInternal($founder)->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('businesses', $target, 'suspended', 0, 'reactivate'))->assertConflict();
        $this->postJson('/internal/administration/customers/status', $this->decision('businesses', $target, 'suspended', 1, 'reactivate'))
            ->assertOk()->assertJsonPath('status', 'declared_draft');
        $this->actingAs($customer)->getJson($surface)->assertOk();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $founder->id)->where('scope_kind', 'business')->count());
    }

    public function test_founder_suspension_blocks_business_lifecycle_commands_until_founder_restores(): void
    {
        [$customer, $business] = $this->business();
        $target = $business['business_public_id'];
        $businessId = DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $target)->value('id');
        $lifecycle = BusinessAuthorityFixture::root($businessId, $customer->id, 'business.lifecycle.manage');
        $selection = ['realm_kind' => 'direct', 'requirements' => [[
            'requirement_kind' => 'business', 'leaf_grant_id' => (int) $lifecycle->id,
        ]]];
        $command = static fn (string $effect, int $revision, ?string $id = null) => [
            'command_id' => $id ?? (string) Str::uuid7(), 'effect' => $effect,
            'target_ref' => null, 'payload' => (object) [], 'expected_revision' => $revision,
            'authority_selection' => $selection,
        ];
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->postJson('/internal/administration/customers/status',
            $this->decision('businesses', $target, 'declared_draft', 0))->assertOk();
        $this->actingAs($customer);
        $path = '/api/businesses/'.$target.'/lifecycle';
        $db = DB::connection('pgsql_migrations');
        $before = [$db->table('businesses')->where('public_id', $target)->sole(), $db->table('business_command_receipts')->count(), $db->table('customer_administration_events')->count()];
        $this->postJson($path, $command('restore', 1))->assertForbidden();
        $this->postJson($path, $command('archive', 1))->assertForbidden();
        $this->postJson($path, $command('restore', 0))->assertForbidden();
        $this->assertSame('suspended', DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $target)->value('lifecycle'));
        $this->assertEquals($before, [$db->table('businesses')->where('public_id', $target)->sole(), $db->table('business_command_receipts')->count(), $db->table('customer_administration_events')->count()]);
        $this->actingAsInternal($founder)->postJson('/internal/administration/customers/status', $this->decision('businesses', $target, 'suspended', 0, 'reactivate'))->assertConflict();
        $this->actingAsInternal($founder)->postJson('/internal/administration/customers/status',
            $this->decision('businesses', $target, 'suspended', 1, 'reactivate'))->assertOk()->assertJsonPath('status', 'declared_draft');
        $this->actingAs($customer);
        $this->postJson($path, $command('suspend', 1))->assertConflict();
        $accepted = $command('suspend', 2);
        $this->postJson($path, $accepted)->assertOk()->assertJsonPath('data.state', 'suspended');
        $this->postJson($path, $accepted)->assertOk()->assertJsonPath('data.state', 'suspended');
    }

    public function test_founder_suspension_commits_before_waiting_ordinary_lifecycle_rechecks_current_revision(): void
    {
        [$customer, $business] = $this->business();
        $db = DB::connection('pgsql_migrations');
        $id = $db->table('businesses')->where('public_id', $business['business_public_id'])->value('id');
        $grant = BusinessAuthorityFixture::root($id, $customer->id, 'business.lifecycle.manage');
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'founder-wait-'.Str::uuid7();
        $path = storage_path('framework/testing/'.$run.'.json');
        $input = ['run' => $run, 'ready' => $path.'.ready', 'release' => $path.'.release',
            'operation' => 'lifecycle', 'actor' => $customer->id, 'business' => $business['business_public_id'],
            'session' => SessionAuthority::sessionKey($request), 'command' => (string) Str::uuid7(),
            'effect' => 'archive', 'expected' => 1, 'selection' => ['realm_kind' => 'direct', 'requirements' => [
                ['requirement_kind' => 'business', 'leaf_grant_id' => $grant->id]]]];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
        $worker->setTimeout(45);
        $wait = function (callable $probe) use ($worker) {
            $until = microtime(true) + 15;
            do {
                if ($value = $probe()) {
                    return $value;
                }
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getOutput().$worker->getErrorOutput());
                }
                usleep(10000);
            } while (microtime(true) < $until);
            throw new \RuntimeException('Required Founder row contention was not observed.');
        };
        $evidence = [];
        try {
            $worker->start();
            $ready = $wait(function () use ($input) {
                clearstatcache(true, $input['ready']);

                return is_file($input['ready']) ? json_decode(file_get_contents($input['ready']), true) : false;
            });
            $founder = $this->staff('founder');
            $this->actingAsInternal($founder);
            DB::beginTransaction();
            $blocker = (int) DB::scalar('SELECT pg_backend_pid()');
            app(PrincipalContext::class)->activate($founder);
            $founderRequest = Request::create('/internal/administration/customers/status');
            $founderRequest->setLaravelSession(app('session')->driver());
            $result = json_decode(DB::scalar('SELECT builderad_customer_status(?,?::uuid,?,?::uuid,?,?,?,?)', [
                SessionAuthority::sessionKey($founderRequest), (string) Str::uuid7(), 'businesses',
                $business['business_public_id'], 'suspend', 'concurrent_founder_decision', 'declared_draft', 0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('suspended', $result['status']);
            file_put_contents($input['release'], 'go');
            $evidence['wait'] = $wait(fn () => $db->selectOne('SELECT pid,wait_event,pg_blocking_pids(pid)::text blockers FROM pg_stat_activity WHERE pid=? AND ?=ANY(pg_blocking_pids(pid))', [$ready['pid'], $blocker]));
            $this->assertSame((int) $ready['pid'], (int) $evidence['wait']->pid);
            DB::commit();
            $worker->wait();
            $this->assertSame(2, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('DENIED_STATE=42501', $worker->getOutput());
            $row = $db->table('businesses')->where('id', $id)->sole();
            $this->assertSame('suspended', $row->lifecycle);
            $this->assertSame(1, (int) $row->lifecycle_revision);
            $this->assertSame(0, $db->table('business_command_receipts')->where('command_id', $input['command'])->count());
            $this->assertSame(1, $db->table('customer_administration_events')->where('business_id', $id)->count());
            $this->assertSame('active', $db->table('security_action_grants')->where('id', $grant->id)->value('status'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $evidence['worker'] = ['stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput(), 'exit' => $worker->getExitCode()];
            file_put_contents($path.'.evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ([$path, $input['ready'], $input['release']] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_active_business_restores_exact_prior_state_without_granting_founder_content(): void
    {
        [$customer, $business] = $this->business();
        $target = $business['business_public_id'];
        $db = DB::connection('pgsql_migrations');
        // Model the supported active state through an exact permit in the disposable fixture.
        $db->transaction(function () use ($db, $customer, $target): void {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $db->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.fixture','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$customer->id, (string) Str::uuid7()]);
            $db->selectOne(<<<'SQL'
                SELECT builderad_private.permit('public.businesses','update',to_jsonb(b),
                    to_jsonb(b)||'{"lifecycle":"active","lifecycle_revision":1}'::jsonb)
                FROM businesses b WHERE public_id=?::uuid
                SQL, [$target]);
            $db->table('businesses')->where('public_id', $target)->update(['lifecycle' => 'active', 'lifecycle_revision' => 1]);
            $db->selectOne('SELECT builderad_private.finish_command()');
        });
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder);
        $url = '/internal/administration/customers/status';
        $this->postJson($url, $this->decision('businesses', $target, 'active', 1))->assertOk()->assertJsonPath('status', 'suspended');
        $this->postJson($url, $this->decision('businesses', $target, 'suspended', 2, 'reactivate'))->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame(['active', 'suspended'], $db->table('customer_administration_events')->orderBy('new_revision')->pluck('prior_status')->all());
        $this->assertSame(0, $db->table('security_action_grants')->where('subject_user_id', $founder->id)->where('scope_kind', 'business')->count());
    }

    public function test_internal_accounts_and_security_disabled_accounts_cannot_be_reactivated_by_customer_administration(): void
    {
        $db = DB::connection('pgsql_migrations');
        $customer = User::factory()->create();
        $db->table('principal_lifecycles')->where('user_id', $customer->id)->update(['status' => 'disabled', 'generation' => 2]);
        $employee = $this->staff();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $employee->public_id, 'current', 1))->assertNotFound();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', 2, 'reactivate'))->assertUnprocessable();
        $this->assertSame('disabled', $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('status'));
    }

    public function test_failed_audit_rolls_back_lifecycle_generation_and_session_revocation(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION customer_test_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END \$\$; CREATE TRIGGER customer_test_failure BEFORE INSERT ON customer_administration_events FOR EACH ROW EXECUTE FUNCTION customer_test_failure()");
        try {
            $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'current', 1))->assertStatus(500);
        } finally {
            $db->unprepared('DROP TRIGGER customer_test_failure ON customer_administration_events; DROP FUNCTION customer_test_failure()');
        }
        $this->assertSame('current', $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('status'));
        $this->assertSame(1, $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('generation'));
        $this->assertSame(1, $db->table('security_sessions')->where('user_id', $customer->id)->whereNull('revoked_at')->count());
        $this->assertSame(0, $db->table('customer_administration_events')->count());
    }

    public function test_employee_and_stale_founder_cannot_execute_status_changes(): void
    {
        $customer = User::factory()->create();
        $input = $this->decision('users', $customer->public_id, 'current', 1);
        $this->actingAsInternal($this->staff())->withCredentials()->postJson('/internal/administration/customers/status', $input)->assertForbidden();
        $founder = $this->staff('founder');
        $this->actingAsInternal($founder)->withCredentials();
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $founder->id)->update(['authenticated_at' => now()->subMinutes(6)]);
        $this->postJson('/internal/administration/customers/status', $input)->assertForbidden();
    }

    public function test_pending_account_reactivation_does_not_bypass_email_verification(): void
    {
        $customer = User::factory()->unverified()->create();
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'pending', 0))->assertOk();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', 1, 'reactivate'))->assertOk()->assertJsonPath('status', 'pending');
        $this->assertNull($customer->fresh()->email_verified_at);
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $customer->id)->where('action', 'workspace.create')->count());
    }

    public function test_original_business_rights_requalify_after_account_reactivation(): void
    {
        $this->usePersistentTestSessions();
        [$customer, $first] = $this->business();
        [, $second] = $this->business($customer);
        $issuer = User::factory()->create();
        [, $delegated] = $this->business($issuer);
        $base = '/api/businesses/'.$delegated['business_public_id'];
        $select = static fn ($grant) => ['realm_kind' => 'direct', 'requirements' => [
            ['requirement_kind' => 'business', 'leaf_grant_id' => (int) $grant],
        ]];
        $roots = $this->postJson($base.'/direct-team', ['command_id' => (string) Str::uuid7(),
            'authority_selection' => $select($delegated['grant_refs']['business.context.read'])])->assertOk()->json('data.grant_refs');
        $issue = fn (string $action) => ['command_id' => (string) Str::uuid7(), 'authority_selection' => $select($roots[$action]),
            'effect' => 'issue', 'action' => $action, 'grantee_public_id' => $customer->public_id,
            'parent_grant_id' => (int) $roots[$action], 'grant_id' => null, 'expected_grant' => 0, 'expires_at' => null, 'delegable' => false];
        $read = $this->postJson($base.'/grants', $issue('business.context.read'))->assertOk()->json('data.grant_id');
        $manageIssue = $issue('business.context.manage');
        $revoked = $this->postJson($base.'/grants', $manageIssue)->assertOk()->json('data.grant_id');
        $this->postJson($base.'/grants', [...$manageIssue, 'command_id' => (string) Str::uuid7(), 'effect' => 'revoke',
            'grant_id' => (int) $revoked, 'expected_grant' => 1])->assertOk();
        $db = DB::connection('pgsql_migrations');
        $rights = $db->table('security_action_grants')->where('subject_user_id', $customer->id)->orderBy('id');
        $original = $rights->pluck('qualification_id', 'id')->all();
        $invalid = $db->table('security_action_grants')->where('id', $revoked)->sole();

        $this->actingAs($customer);
        $request = Request::create('/profile', 'PATCH');
        $request->setUserResolver(fn () => $customer);
        $request->setLaravelSession(app('session')->driver());
        app(LifecycleAuthority::class)->changeProfile($request, $customer->name, 'reverified-'.$customer->id.'@example.test');
        app(LifecycleAuthority::class)->verifyPrimaryEmail($customer->fresh());
        $this->app['session']->driver()->regenerate();
        $customer->refresh();
        $this->actingAs($customer)->getJson('/api/businesses')->assertOk();
        $this->getJson($base.'/surface?access='.$read)->assertOk()->assertJsonPath('data.context_write_options', []);

        $generation = (int) $db->table('principal_lifecycles')->where('user_id', $customer->id)->value('generation');
        $this->actingAsInternal($this->staff('founder'))->withCredentials();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'current', $generation))->assertOk();
        $this->postJson('/internal/administration/customers/status', $this->decision('users', $customer->public_id, 'disabled', $generation + 1, 'reactivate'))->assertOk();
        $this->actingAs($customer)->getJson('/api/businesses')->assertOk();
        foreach ([$first, $second] as $business) {
            $this->getJson('/api/businesses/'.$business['business_public_id'].'/surface?access='.$business['grant_refs']['business.context.read'])->assertOk();
        }
        $this->getJson('/api/businesses/'.$first['business_public_id'].'/surface?access='.$second['grant_refs']['business.context.read'])->assertForbidden();
        $this->getJson($base.'/surface?access='.$read)->assertOk()->assertJsonPath('data.context_write_options', []);
        $this->assertSame($original, $rights->pluck('qualification_id', 'id')->all());
        $this->assertEquals($invalid, $db->table('security_action_grants')->where('id', $revoked)->sole());
    }

    private function decision(string $kind, string $target, string $status, int $revision, string $decision = 'suspend'): array
    {
        return ['command_id' => (string) Str::uuid7(), 'kind' => $kind, 'target' => $target, 'decision' => $decision,
            'reason' => 'Owner administrative review', 'expected_status' => $status, 'expected_revision' => $revision];
    }

    private function staff(string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active', 'attested_by' => $user->id,
            'attested_at' => now(), 'factor_independence_attested_at' => $kind === 'founder' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, $kind === 'founder' ? 2 : 1) as $factor) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Synthetic factor', 'credential_id' => 'customer-proof-'.$user->id.'-'.$factor, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function business(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $business = $this->actingAs($owner)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Customer Business',
        ])->assertCreated()->json('data');

        return [$owner, $business];
    }

    public function test_shell_and_witness_use_the_same_founder_authority_without_directory_projection(): void
    {
        $this->actingAsInternal($this->staff('founder'));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->get('/internal/administration/customers')->assertOk()->assertViewIs('spa');
        $this->getJson('/internal/administration/customers', ['X-Builderad-Witness' => '1'])
            ->assertOk()->assertJsonMissingPath('data')->assertJsonPath('visibility.protected', true);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'builderad_customer_directory_witness()')));
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'builderad_customer_directory(')));
        $this->actingAsInternal($this->staff())->getJson('/internal/administration/customers', ['X-Builderad-Witness' => '1'])->assertForbidden();
    }

    public function test_repeated_baseline_preserves_directory_witness_and_private_authority(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $before = $owner->table('users')->count();
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        app(DisposableDatabaseReset::class)->assertInstalledCatalog();
        $this->assertSame($before, $owner->table('users')->count());
        foreach (['builderad_app' => true, 'builderad_authority' => false] as $role => $allowed) {
            $this->assertSame($allowed, $owner->scalar("SELECT has_function_privilege(?, 'builderad_customer_directory_witness()', 'EXECUTE')", [$role]));
            $this->assertFalse($owner->scalar("SELECT has_function_privilege(?, 'builderad_customer_administrator(text)', 'EXECUTE')", [$role]));
        }
    }

    public function test_witness_function_has_exact_owner_search_path_and_effective_privileges(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $function = $owner->selectOne("SELECT r.rolname AS owner, p.prosecdef, p.proconfig::text AS settings, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='public.builderad_customer_directory_witness()'::regprocedure");
        $this->assertSame('builderad_owner', $function->owner);
        $this->assertTrue($function->prosecdef);
        $this->assertStringContainsString('search_path=public, pg_temp', $function->settings);
        $this->assertStringContainsString('PERFORM builderad_customer_administrator()', $function->definition);
        $this->assertFalse($owner->scalar("SELECT EXISTS(SELECT 1 FROM pg_proc p, LATERAL aclexplode(p.proacl) a WHERE p.oid='public.builderad_customer_directory_witness()'::regprocedure AND a.grantee=0 AND a.privilege_type='EXECUTE')"));
        foreach (['builderad_app', 'builderad_authority'] as $role) {
            $this->assertFalse($owner->scalar("SELECT has_schema_privilege(?, 'public', 'CREATE')", [$role]));
            $this->assertFalse($owner->scalar("SELECT has_function_privilege(?, 'public.builderad_customer_administrator(text)', 'EXECUTE')", [$role]));
        }
        $this->assertTrue($owner->scalar("SELECT has_function_privilege('builderad_app', 'public.builderad_customer_directory_witness()', 'EXECUTE')"));
        $this->assertFalse($owner->scalar("SELECT has_function_privilege('builderad_authority', 'public.builderad_customer_directory_witness()', 'EXECUTE')"));
    }

    public function test_witness_and_resource_both_deny_invalid_current_founder_authority(): void
    {
        $owner = DB::connection('pgsql_migrations');
        foreach (['idle-expired', 'revoked', 'inactive-founder', 'employee', 'advertiser'] as $scenario) {
            $principal = $scenario === 'advertiser' ? User::factory()->create() : $this->staff($scenario === 'employee' ? 'employee' : 'founder');
            if ($scenario === 'advertiser') {
                $this->actingAs($principal)->withCredentials();
            } else {
                $this->actingAsInternal($principal)->withCredentials();
            }
            if ($scenario === 'idle-expired') {
                $owner->table('security_sessions')->where('user_id', $principal->id)->update(['idle_expires_at' => now()->subMinute()]);
            } elseif ($scenario === 'revoked') {
                $owner->table('security_sessions')->where('user_id', $principal->id)->update(['revoked_at' => now()]);
            } elseif ($scenario === 'inactive-founder') {
                $owner->table('internal_personnel')->where('user_id', $principal->id)->update(['status' => 'revoked']);
            }
            foreach ([['X-Builderad-Witness' => '1'], []] as $headers) {
                $response = $this->getJson('/internal/administration/customers', $headers);
                $this->assertContains($response->getStatusCode(), [403, 409], $scenario.' must deny both representations: '.$response->getContent());
                $response->assertJsonMissingPath('data.rows');
            }
        }
    }

    public function test_witness_execute_permission_alone_never_supplies_principal_authority(): void
    {
        try {
            DB::transaction(fn () => DB::selectOne('SELECT public.builderad_customer_directory_witness()'));
            $this->fail('An unsigned runtime connection must not obtain a witness decision.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }
}
