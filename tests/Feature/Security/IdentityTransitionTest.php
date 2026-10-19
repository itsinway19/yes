<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\IdentitySessionPrepared;
use App\Security\IdentityTransition;
use App\Security\IdentityTransitionConflict;
use App\Security\IdentityTransitionUnknown;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Connection\Resource\Exception\StreamInitException;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityTransitionFixture;
use Tests\TestCase;

class IdentityTransitionTest extends TestCase
{
    private IdentityTransition $transitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transitions = app(IdentityTransition::class);
        config(['session.driver' => 'redis', 'session.connection' => 'default',
            'database.redis.p2a_unavailable' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.1, 'read_timeout' => 0.1]]);
        // Real Redis is required; absent infrastructure is a failure, not a mocked PASS.
        $this->assertSame('PONG', (string) Redis::connection('default')->ping());
    }

    public function test_real_guard_regeneration_save_commit_and_current_admission(): void
    {
        $user = User::factory()->create();
        $lineage = $this->transitions->create('advertiser');
        $request = IdentityTransitionFixture::request();
        $oldHash = SessionAuthority::sessionKey($request);
        $sequence = [];
        Event::listen(IdentitySessionPrepared::class, function () use ($request, $lineage, &$sequence): void {
            $sequence[] = 'S1';
            $this->assertNotEmpty($request->session()->getHandler()->read($request->session()->getId()));
            $this->assertSame(0, (int) DB::table('browser_identity_transitions')->where('lineage_id', $lineage)->value('epoch'));
            $this->assertSame(0, DB::table('security_sessions')->count());
            $this->assertDenied($request, $lineage);
        });
        $receipt = $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), function () use ($request, $user, &$sequence): void {
            $sequence[] = 'authentication';
            IdentityTransitionFixture::authenticate($request, $user->email);
            $sequence[] = 'regeneration';
        });
        $sequence[] = 'S2';
        $this->assertSame(['authentication', 'regeneration', 'S1', 'S2'], $sequence);
        $this->assertNotSame($oldHash, SessionAuthority::sessionKey($request));
        $this->assertSame(1, $receipt['epoch']);
        $received = IdentityTransitionFixture::request($request->session()->getId());
        $this->transitions->admit($received, $lineage, 'advertiser');
        $this->assertSame($user->id, $received->user()->id);
        $this->assertNull(DB::table('security_sessions')->value('revoked_at'));
        $this->evidence('vertical-slice', ['sequence' => $sequence, 'precommit_admission' => 'denied', 'postcommit_admission' => 'accepted', 'epoch' => $receipt['epoch']]);
    }

    public function test_stale_precondition_cannot_authenticate_or_regenerate_and_nonce_is_idempotent(): void
    {
        [$request, $lineage, $receipt] = $this->login();
        $calls = 0;
        $hash = SessionAuthority::sessionKey($request);
        $again = $this->transitions->login($request, $lineage, 'advertiser', 0, $receipt['nonce'], function () use (&$calls): void {
            $calls++;
        });
        $this->assertSame($receipt, $again);
        $this->assertSame($receipt, $this->transitions->resolve($lineage, 'advertiser', $receipt['nonce']));
        try {
            $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), function () use (&$calls): void {
                $calls++;
            });
            $this->fail('Stale transition accepted.');
        } catch (IdentityTransitionConflict) {
            $this->assertSame(0, $calls);
            $this->assertSame($hash, SessionAuthority::sessionKey($request));
        }
        $this->assertSame(1, DB::table('security_sessions')->count());
    }

    public function test_replacement_revokes_captured_previous_not_new_and_old_retry_is_stale(): void
    {
        [$request, $lineage, $first] = $this->login();
        $oldId = $request->session()->getId();
        $oldHash = SessionAuthority::sessionKey($request);
        $other = User::factory()->create();
        $next = $this->transitions->login($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)), fn () => IdentityTransitionFixture::authenticate($request, $other->email));
        $this->assertSame(2, $next['epoch']);
        $this->assertNotNull(DB::table('security_sessions')->where('session_key_hash', $oldHash)->value('revoked_at'));
        $this->assertNull(DB::table('security_sessions')->where('session_key_hash', SessionAuthority::sessionKey($request))->value('revoked_at'));
        $this->transitions->admit($request, $lineage, 'advertiser');
        $this->assertDenied(IdentityTransitionFixture::request($oldId), $lineage);
        $this->assertNull($this->transitions->resolve($lineage, 'advertiser', $first['nonce']));
        $this->expectException(IdentityTransitionConflict::class);
        $this->transitions->login($request, $lineage, 'advertiser', 0, $first['nonce'], fn () => $this->fail('Old nonce replayed authentication.'));
    }

    public function test_corrupt_server_binding_and_nonce_from_another_lineage_are_rejected_before_authentication(): void
    {
        [$request, $lineage, $receipt] = $this->login();
        $other = $this->transitions->create('advertiser');
        foreach ([[$other, 0, $receipt['nonce']], [$lineage, 1, bin2hex(random_bytes(32))]] as [$target, $epoch, $nonce]) {
            if ($target === $lineage) {
                DB::connection('pgsql_migrations')->table('browser_identity_transitions')->where('lineage_id', $lineage)->update([
                    'epoch' => 2, 'last_nonce' => bin2hex(random_bytes(32)), 'current_session_key_hash' => str_repeat('a', 64),
                ]);
                $epoch = 2;
            }
            try {
                $this->transitions->login($request, $target, 'advertiser', $epoch, $nonce, fn () => $this->fail('Invalid ownership authenticated.'));
                $this->fail('Invalid ownership was accepted.');
            } catch (IdentityTransitionConflict) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rebind_preserves_all_canonical_deadlines_and_authenticated_at(): void
    {
        [$request, $lineage] = $this->login();
        $before = (array) DB::table('security_sessions')->first();
        $oldId = $request->session()->getId();
        $next = $this->transitions->rebind($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)));
        $after = (array) DB::table('security_sessions')->first();
        unset($before['session_key_hash'], $after['session_key_hash']);
        $this->assertSame($before, $after);
        $this->assertSame(2, $next['epoch']);
        $this->transitions->admit($request, $lineage, 'advertiser');
        $this->assertDenied(IdentityTransitionFixture::request($oldId), $lineage);
    }

    public function test_redis_save_failure_rolls_back_publication_and_authority(): void
    {
        $user = User::factory()->create();
        $request = IdentityTransitionFixture::request();
        $lineage = $this->transitions->create('advertiser');
        try {
            $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), function () use ($request, $user): void {
                IdentityTransitionFixture::authenticate($request, $user->email);
                $request->session()->getHandler()->getCache()->getStore()->setConnection('p2a_unavailable');
            });
            $this->fail('Unavailable Redis accepted.');
        } catch (StreamInitException) {
            $this->assertSame(0, DB::table('security_sessions')->count());
            $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
        }
    }

    public function test_real_redis_prepare_survives_sql_rollback_but_cannot_be_admitted(): void
    {
        $user = User::factory()->create();
        $request = IdentityTransitionFixture::request();
        $lineage = $this->transitions->create('advertiser');
        Event::listen(IdentitySessionPrepared::class, fn () => throw new RuntimeException('Synthetic S1/S2 interruption'));
        try {
            $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), fn () => IdentityTransitionFixture::authenticate($request, $user->email));
            $this->fail('Interruption committed.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic S1/S2 interruption', $error->getMessage());
        }
        $prepared = IdentityTransitionFixture::request($request->session()->getId());
        $this->assertSame($user->id, $prepared->user()->id); // Laravel guard alone is not transport authority.
        $this->assertDenied($prepared, $lineage);
        $this->assertSame(0, DB::table('security_sessions')->count());
        $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
    }

    public function test_uncertain_delivery_resolves_committed_nonce_without_authentication_replay(): void
    {
        [$request, $lineage, $receipt] = $this->login();
        // S2 really committed; deliberately discard the caller's response/credential handoff.
        $resolved = $this->transitions->resolve($lineage, 'advertiser', $receipt['nonce']);
        $this->assertSame($receipt, $resolved);
        $this->assertSame(1, DB::table('security_sessions')->count());
        $this->assertDenied(IdentityTransitionFixture::request(), $lineage);
        $this->transitions->admit(IdentityTransitionFixture::request($request->session()->getId()), $lineage, 'advertiser');
    }

    public function test_real_postgresql_commit_ack_loss_is_resolved_by_nonce_without_reauthentication(): void
    {
        $user = User::factory()->create();
        $lineage = $this->transitions->create('advertiser');
        $request = IdentityTransitionFixture::request();
        $nonce = bin2hex(random_bytes(32));
        $proxy = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', base_path('tests/Support/identity-commit-loss-proxy.py')], base_path());
        $proxy->setTimeout(25);
        $proxy->start();
        $calls = 0;
        try {
            $this->waitOutput($proxy, 'BOOTED ');
            preg_match('/BOOTED (\d+)/', $proxy->getOutput(), $match);
            config(['database.connections.pgsql_authority.port' => $match[1]]);
            DB::purge('pgsql_authority');
            try {
                $this->transitions->login($request, $lineage, 'advertiser', 0, $nonce, function () use ($request, $user, &$calls): void {
                    $calls++;
                    IdentityTransitionFixture::authenticate($request, $user->email);
                });
                $this->fail('Lost acknowledgement appeared certain.');
            } catch (IdentityTransitionUnknown $unknown) {
                $this->assertSame($nonce, $unknown->nonce);
                $this->assertTrue($request->attributes->get('builderad.identity_uncertain'));
            }
            $this->waitOutput($proxy, 'COMMIT_ACK_DROPPED');
            $this->assertSame(1, $this->transitions->resolve($lineage, 'advertiser', $nonce)['epoch']);
            $this->assertSame(1, DB::table('security_sessions')->count());
            $this->assertSame(1, $calls);
            $this->evidence('commit-ack-loss', ['proxy' => $proxy->getOutput(), 'resolved_epoch' => 1, 'authentication_calls' => $calls]);
        } finally {
            config(['database.connections.pgsql_authority.port' => '5433']);
            DB::purge('pgsql_authority');
            $proxy->stop();
        }
        $this->transitions->admit(IdentityTransitionFixture::request($request->session()->getId()), $lineage, 'advertiser');
    }

    public function test_redis_unavailable_after_commit_and_expiry_before_delivery_fail_closed(): void
    {
        [$request, $lineage] = $this->login();
        $id = $request->session()->getId();
        try {
            IdentityTransitionFixture::request($id, 'advertiser', 'p2a_unavailable');
            $this->fail('Unavailable Redis fell back to authority.');
        } catch (StreamInitException) {
            $this->assertSame(1, (int) DB::table('browser_identity_transitions')->value('epoch'));
        }
        $handler = $request->session()->getHandler();
        $handler->getCache()->put($id, $handler->read($id), 1);
        usleep(1100000);
        $this->assertSame('', $handler->read($id));
        $this->assertDenied(IdentityTransitionFixture::request($id), $lineage);
    }

    public function test_removed_prepared_session_and_redis_unavailability_after_commit_do_not_restore_old_authority(): void
    {
        [$request, $lineage] = $this->login();
        $id = $request->session()->getId();
        $request->session()->getHandler()->destroy($id);
        $this->assertDenied(IdentityTransitionFixture::request($id), $lineage);
        $this->assertSame(1, (int) DB::table('browser_identity_transitions')->value('epoch'));
        $this->assertNull(DB::table('security_sessions')->value('revoked_at'));
    }

    public static function invalidStates(): array
    {
        return [['missing'], ['corrupt'], ['boundary'], ['expired'], ['revoked']];
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_binding_and_canonical_authority_fail_closed(string $state): void
    {
        [$request, $lineage] = $this->login();
        $owner = DB::connection('pgsql_migrations');
        if ($state === 'missing') {
            $owner->table('browser_identity_transitions')->delete();
        } elseif ($state === 'corrupt') {
            $request->session()->put('builderad.identity_epoch', 99);
        } elseif ($state === 'boundary') {
            $this->expectException(IdentityTransitionConflict::class);
            $this->transitions->admit($request, $lineage, 'internal');

            return;
        } else {
            $owner->table('security_sessions')->update($state === 'expired' ? ['idle_expires_at' => now()->subSecond()] : ['revoked_at' => now()]);
        }
        $this->assertDenied($request, $lineage);
    }

    public function test_terminal_transition_is_conditional_and_does_not_publish_guest_authority(): void
    {
        [$request, $lineage] = $this->login();
        $receipt = $this->transitions->close($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)));
        $this->assertSame('closed', $receipt['status']);
        $this->assertSame(2, $receipt['epoch']);
        $this->assertNotNull(DB::table('security_sessions')->value('revoked_at'));
        $this->assertDenied($request, $lineage);
        $this->expectException(IdentityTransitionConflict::class);
        $this->transitions->close($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)));
    }

    public function test_runtime_cannot_publish_and_database_rejects_epoch_regression(): void
    {
        [$request, $lineage] = $this->login();
        foreach (['pgsql', 'pgsql_authority'] as $connection) {
            try {
                DB::connection($connection)->table('browser_identity_transitions')->where('lineage_id', $lineage)->update(['epoch' => 0]);
                $this->fail('Unauthorized/regressive publication accepted.');
            } catch (QueryException $error) {
                $this->assertSame($connection === 'pgsql' ? '42501' : '23514', $error->errorInfo[0]);
            }
        }
        $this->transitions->admit($request, $lineage, 'advertiser');
    }

    public function test_migration_runner_is_repeatable_and_permissions_are_scoped(): void
    {
        $fingerprint = $this->canonicalFingerprint();
        // Upgrade an existing schema, then confirm a second migration run is a no-op.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'pgsql_migrations', '--path' => 'database/migrations/0001_01_01_000380_create_browser_identity_transitions.php', '--step' => 1, '--force' => true]));
        $this->assertSame($fingerprint, $this->canonicalFingerprint());
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
        $this->assertSame($fingerprint, $this->canonicalFingerprint());
        $grants = DB::connection('pgsql_migrations')->selectOne("SELECT has_table_privilege('builderad_app', 'browser_identity_transitions', 'SELECT') AS readable, has_table_privilege('builderad_app', 'browser_identity_transitions', 'INSERT,UPDATE,DELETE') AS publishable, has_table_privilege('builderad_authority', 'browser_identity_transitions', 'DELETE') AS deletable");
        $this->assertTrue($grants->readable);
        $this->assertFalse($grants->publishable);
        $this->assertFalse($grants->deletable);
        $this->evidence('migration-permissions', ['canonical_fingerprint_before' => $fingerprint, 'canonical_fingerprint_after' => $this->canonicalFingerprint(), 'runtime_publication' => false, 'authority_delete' => false]);
    }

    public static function workerPreparationDelays(): array
    {
        return ['immediate' => [0], 'beyond transaction idle limit' => [16000]];
    }

    #[DataProvider('workerPreparationDelays')]
    public function test_same_base_race_observes_real_row_blocker_then_rejects_loser_before_authentication(int $preparationDelayMilliseconds): void
    {
        $user = User::factory()->create();
        $lineage = $this->transitions->create('advertiser');
        $request = IdentityTransitionFixture::request();
        $input = new InputStream;
        $preparationStarted = hrtime(true);
        $worker = $this->worker($lineage, $user->email, 'race', $input, $preparationDelayMilliseconds);
        $proof = ['worker_preparation_delay_ms' => $preparationDelayMilliseconds];
        try {
            $this->waitOutput($worker, 'READY');
            $proof['worker_preparation_elapsed_ms'] = (hrtime(true) - $preparationStarted) / 1000000;
            $this->assertGreaterThanOrEqual($preparationDelayMilliseconds, $proof['worker_preparation_elapsed_ms']);
            $this->assertSame(0, DB::connection('pgsql_authority')->transactionLevel());
            $proof['worker_ready_before_publish'] = true;
            Event::listen(IdentitySessionPrepared::class, function () use ($worker, $input, &$proof): void {
                $holder = (int) DB::connection('pgsql_authority')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                $input->write("CONTEND\n");
                $input->close();
                $this->waitOutput($worker, 'ATTEMPT');
                preg_match('/BOOTED (\d+)/', $worker->getOutput(), $match);
                $pid = (int) $match[1];
                $deadline = microtime(true) + 10;
                do {
                    $blocked = DB::connection('pgsql_migrations')->selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS observed', [$holder, $pid])->observed;
                    if ($blocked) {
                        break;
                    }
                    $this->assertTrue($worker->isRunning(), 'Worker exited before contention.');
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'The real lineage row blocker was not observed.');
                $proof += ['holder_pid' => $holder, 'waiter_pid' => $pid, 'actual_blocker_observed' => true];
            });
            $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), fn () => IdentityTransitionFixture::authenticate($request, $user->email));
            $this->assertSame(0, $worker->wait());
            $this->assertStringContainsString('STALE AUTH_CALLS=0', $worker->getOutput());
            $this->assertSame(1, DB::table('security_sessions')->count());
            $proof['pass'] = true;
        } finally {
            $input->close();
            $this->evidence('same-epoch-race-'.$preparationDelayMilliseconds, [...$proof, 'worker_output' => $worker->getOutput()]);
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_killed_worker_after_s1_has_no_published_authority(): void
    {
        $user = User::factory()->create();
        $lineage = $this->transitions->create('advertiser');
        $worker = $this->worker($lineage, $user->email, 'interrupt');
        try {
            $this->waitOutput($worker, 'PREPARED ');
            $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
            $this->assertSame(0, DB::table('security_sessions')->count());
            $worker->stop(0.2);
            $this->assertSame(0, DB::table('security_sessions')->count());
            $this->assertSame(0, (int) DB::table('browser_identity_transitions')->value('epoch'));
            preg_match('/PREPARED ([a-f0-9]{64})/', $worker->getOutput(), $match);
            $id = null;
            foreach (Redis::connection('default')->keys((config('session.prefix') ?: config('cache.prefix')).'*') as $key) {
                $candidate = substr($key, -40);
                if (hash('sha256', $candidate) === $match[1]) {
                    $id = $candidate;
                    break;
                }
            }
            $this->assertTrue(is_string($id), 'Prepared Redis session not found by its hash.');
            $this->assertDenied(IdentityTransitionFixture::request($id), $lineage);
            $this->evidence('interrupted-after-save', ['worker_output' => $worker->getOutput(), 'epoch' => 0, 'committed_security_sessions' => 0, 'prepared_session_denied' => true]);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    private function login(): array
    {
        $user = User::factory()->create();
        $request = IdentityTransitionFixture::request();
        $lineage = $this->transitions->create('advertiser');
        $receipt = $this->transitions->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)), fn () => IdentityTransitionFixture::authenticate($request, $user->email));

        return [$request, $lineage, $receipt];
    }

    private function assertDenied(Request $request, string $lineage): void
    {
        try {
            $this->transitions->admit($request, $lineage, 'advertiser');
            $this->fail('Invalid credential was admitted.');
        } catch (IdentityTransitionConflict|AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function worker(string $lineage, string $email, string $mode = 'race', ?InputStream $input = null, int $preparationDelayMilliseconds = 0): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/IdentityTransitionWorker.php')], base_path(), [
            'P2A_LINEAGE' => $lineage, 'P2A_NONCE' => bin2hex(random_bytes(32)),
            'P2A_EMAIL' => $email, 'P2A_WORKER_MODE' => $mode,
            'P2A_PREPARATION_DELAY_MS' => (string) $preparationDelayMilliseconds,
        ]);
        $worker->setTimeout(25);
        if ($input) {
            $worker->setInput($input);
        }
        $worker->start();

        return $worker;
    }

    private function waitOutput(Process $worker, string $needle): void
    {
        while (! str_contains($worker->getOutput(), $needle)) {
            $worker->checkTimeout();
            $this->assertTrue($worker->isRunning(), 'Worker exited: '.$worker->getOutput().$worker->getErrorOutput());
            usleep(10000);
        }
    }

    private function canonicalFingerprint(): array
    {
        $owner = DB::connection('pgsql_migrations');
        $functions = $owner->selectOne("SELECT md5(string_agg(pg_get_functiondef(p.oid), E'\\n' ORDER BY p.oid)) AS hash FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prokind='f' AND p.proname <> 'builderad_guard_identity_transition'")->hash;
        $policies = $owner->selectOne("SELECT md5(string_agg(p::text, E'\\n' ORDER BY p.oid)) AS hash FROM pg_policy p")->hash;
        $columns = $owner->selectOne("SELECT md5(string_agg(c::text, E'\\n' ORDER BY c.table_name,c.ordinal_position)) AS hash FROM information_schema.columns c WHERE table_schema='public' AND table_name IN ('security_sessions','principal_lifecycles','security_action_grants')")->hash;

        return [$functions, $policies, $columns];
    }

    private function evidence(string $name, array $data): void
    {
        $directory = storage_path('framework/testing/identity-transition-evidence');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Unable to retain sanitized transition evidence.');
        }
        file_put_contents($directory.'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}
