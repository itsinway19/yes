<?php

namespace Tests\Feature\Sources;

use App\Models\User;
use App\Security\SessionAuthority;
use App\Security\SourceAuditCustody;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\BusinessAuthorityFixture;
use Tests\Support\RowLockReadiness;
use Tests\TestCase;

/** Native authority, isolation and provider coverage also live in BusinessNativeSourceTest. */
final class SourceRegistryTest extends TestCase
{
    private User $actor;

    private string $base;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $business = $this->actingAs($this->actor)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Native registry contract',
        ])->assertCreated()->json('data');
        $this->base = '/businesses/'.$business['business_public_id'];
        $this->input = ['source_id' => (string) Str::uuid7(), 'command_id' => (string) Str::uuid7(), 'effect' => 'register',
            'family' => 'meta', 'namespace' => 'ad_account', 'external_id' => 'synthetic-account', 'label' => 'Declared account',
            'resource' => 'synthetic-account', 'operation' => 'report.read', 'purpose' => 'advertising_analysis',
            'expected_version' => 0, 'reason' => 'explicit_registry_declaration'];
    }

    private function command(array $input)
    {
        return $this->postJson($this->base.'/sources', $input);
    }

    private function transition(string $effect, int $version, array $extra = []): array
    {
        return [...array_intersect_key($this->input, array_flip(['source_id', 'resource', 'operation', 'purpose', 'reason'])),
            'command_id' => (string) Str::uuid7(), 'effect' => $effect, 'expected_version' => $version, ...$extra];
    }

    public function test_versioned_registry_corrections_replay_exactly_and_retirement_never_revives_the_source(): void
    {
        $this->command($this->input)->assertOk();
        $rename = $this->transition('rename', 1, ['label' => 'Corrected label']);
        $receipt = $this->command($rename)->assertOk()->assertJsonPath('version', 2)->json();
        $this->command($rename)->assertOk()->assertExactJson($receipt);
        $this->command([...$rename, 'label' => 'Changed under the same key'])->assertConflict();
        $this->command($this->transition('rename', 1, ['label' => 'Stale revision']))->assertConflict();
        $retire = $this->transition('revoke', 2);
        $retirement = $this->command($retire)->assertOk()->assertJsonPath('version', 3)->json();
        $this->command($retire)->assertOk()->assertExactJson($retirement);
        $this->command($this->transition('rename', 3, ['label' => 'Do not revive']))->assertUnprocessable();
        $this->getJson($this->base.'/source-registry')->assertOk()
            ->assertJsonPath('data.sources.items.0.capability.state', 'REVOKED')->assertJsonPath('data.sources.items.0.can_manage', false);
        $db = DB::connection('pgsql_migrations');
        foreach (['source_versions', 'source_command_receipts', 'source_audit_obligations'] as $table) {
            self::assertSame(3, $db->table($table)->count(), $table);
        }
    }

    public function test_undelegated_scope_and_untrusted_provider_claims_cannot_change_registry_facts(): void
    {
        $this->command($this->input)->assertOk();
        foreach (['resource' => 'different-resource', 'operation' => 'discovery.read', 'purpose' => 'different_purpose'] as $field => $value) {
            $this->command($this->transition('declare_scope', 1, [$field => $value]))->assertForbidden();
        }
        foreach (['provider_permission' => 'VERIFIED', 'token' => 'never_persist', 'unexpected_sql' => 'SELECT payload'] as $field => $value) {
            $this->command([...$this->input, $field => $value])->assertUnprocessable();
        }
        $db = DB::connection('pgsql_migrations');
        foreach (['source_registry', 'source_capabilities', 'source_versions', 'source_command_receipts', 'source_audit_obligations'] as $table) {
            self::assertSame(1, $db->table($table)->count(), $table);
        }
        foreach (['provider_access', 'data_use_scopes', 'business_data_use_bindings'] as $table) {
            self::assertSame(0, $db->table($table)->count(), $table);
        }
    }

    public function test_custody_outage_and_lost_ack_preserve_one_receipt_and_require_current_recovery_right(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $url = $this->base.'/source-audit/'.$receipt['obligation_id'].'/recover';
        $this->app->instance(SourceAuditCustody::class, new class extends SourceAuditCustody
        {
            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                throw new RuntimeException('Synthetic custody outage');
            }
        });
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->app->instance(SourceAuditCustody::class, new class extends SourceAuditCustody
        {
            public function accept(string $obligation, string $fingerprint, string $envelope): string
            {
                parent::accept($obligation, $fingerprint, $envelope);
                throw new RuntimeException('Synthetic lost custody response');
            }
        });
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'FAILED');
        $this->app->instance(SourceAuditCustody::class, new SourceAuditCustody);
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->postJson($url)->assertOk()->assertJsonPath('delivery_state', 'ACKNOWLEDGED');
        $this->command($this->input)->assertOk()->assertExactJson($receipt);
        $db = DB::connection('pgsql_migrations');
        foreach (['source_command_receipts', 'source_audit_obligations', 'security_source_audit_custody'] as $table) {
            self::assertSame(1, $db->table($table)->count(), $table);
        }
        $this->postJson($this->base.'/source-grants', [...array_intersect_key($this->input, array_flip(['source_id', 'resource', 'operation', 'purpose', 'reason'])),
            'command_id' => (string) Str::uuid7(), 'action' => 'source.audit.recover', 'decision' => 'revoke'])->assertOk();
        $before = $db->table('source_audit_delivery')->sole();
        $this->postJson($url)->assertForbidden();
        self::assertEquals($before, $db->table('source_audit_delivery')->sole());
    }

    public function test_recovery_expiring_during_observed_delivery_wait_preserves_custody_without_local_ack(): void
    {
        $receipt = $this->command($this->input)->assertOk()->json();
        $db = DB::connection('pgsql_migrations');
        $qualification = $db->table('security_action_grants')->where('source_target_id', $this->input['source_id'])
            ->where('action', 'source.audit.recover')->value('qualification_id');
        self::assertNotNull($qualification);
        $code = <<<'PHP'
        require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        app(App\Security\DisposableTestDatabaseGuard::class)->assertSafe();
        $db=Illuminate\Support\Facades\DB::connection('pgsql_migrations')->getPdo(); $db->beginTransaction();
        $q=$db->prepare('SELECT obligation_id FROM source_audit_delivery WHERE obligation_id=?::uuid FOR UPDATE');$q->execute([$argv[1]]);
        $xid=$db->query('SELECT pg_current_xact_id()::text')->fetchColumn();echo 'LOCK_READY';fflush(STDOUT);
        $q=$db->prepare("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='transactionid' AND transactionid=?::xid AND pid=? AND NOT granted)");
        $until=microtime(true)+25;$observed=false;
        do{$q->execute([$xid,$argv[2]]);$observed=in_array($q->fetchColumn(),[true,1,'1','t'],true);if($observed)break;usleep(20000);}while(microtime(true)<$until);
        if(!$observed){$db->rollBack();exit(2);}echo 'WAIT_OBSERVED';fflush(STDOUT);
        $expiry=$db->prepare('SELECT valid_until<=clock_timestamp() FROM authority_qualification_receipts WHERE qualification_id=?::uuid');
        $until=microtime(true)+6;$expired=false;
        do{$expiry->execute([$argv[3]]);$expired=in_array($expiry->fetchColumn(),[true,1,'1','t'],true);if($expired)break;usleep(20000);}while(microtime(true)<$until);
        if(!$expired){$db->rollBack();exit(3);}echo 'EXPIRED_BEFORE_RELEASE';fflush(STDOUT);$db->commit();
        PHP;
        $holder = new Process([PHP_BINARY, '-r', $code, $receipt['obligation_id'], (string) DB::scalar('SELECT pg_backend_pid()'), $qualification], base_path());
        $holder->setTimeout(40);
        $holder->start();
        try {
            RowLockReadiness::await($holder);
            BusinessAuthorityFixture::expireQualificationSoon($qualification, $this->actor->id);
            $this->postJson($this->base.'/source-audit/'.$receipt['obligation_id'].'/recover')->assertForbidden();
            $holder->wait();
            self::assertTrue($holder->isSuccessful(), $holder->getOutput().' '.$holder->getErrorOutput());
            self::assertStringContainsString('WAIT_OBSERVED', $holder->getOutput());
            self::assertStringContainsString('EXPIRED_BEFORE_RELEASE', $holder->getOutput());
        } finally {
            if ($holder->isRunning()) {
                $holder->stop();
            }
        }
        self::assertSame('PENDING', $db->table('source_audit_delivery')->value('state'));
        self::assertSame(0, $db->table('source_audit_delivery')->value('attempts'));
        self::assertSame(1, $db->table('security_source_audit_custody')->count());
        self::assertSame(1, $db->table('source_command_receipts')->count());
    }

    public function test_two_real_source_writers_reconcile_one_exact_command_after_observed_contention(): void
    {
        $this->sourceRace(false);
    }

    public function test_two_real_source_writers_cannot_reinterpret_one_command_with_conflicting_payloads(): void
    {
        $this->sourceRace(true);
    }

    private function sourceRace(bool $conflicting): void
    {
        $this->command($this->input)->assertOk();
        $db = DB::connection('pgsql_migrations');
        $manager = $db->table('security_action_grants')->where('source_target_id', $this->input['source_id'])
            ->where('action', 'source.manage')->value('id');
        $request = Request::create('/api/businesses');
        $request->setLaravelSession(app('session')->driver());
        $run = 'native-registry-race-'.Str::uuid7();
        $command = (string) Str::uuid7();
        $release = storage_path('framework/testing/'.$run.'.release');
        $workers = $paths = $ready = [];
        $evidence = [];
        try {
            foreach ([0, 1] as $index) {
                $path = storage_path('framework/testing/'.$run.'-'.$index.'.json');
                $paths[] = $path;
                file_put_contents($path, json_encode(['run' => $run, 'ready' => $path.'.ready', 'release' => $release,
                    'operation' => 'resource', 'actor' => $this->actor->id, 'kind' => 'source', 'target' => $this->input['source_id'],
                    'effect' => 'rename', 'command' => $command, 'session' => SessionAuthority::sessionKey($request), 'expected' => 1,
                    'payload' => ['label' => $conflicting && $index === 1 ? 'Conflicting label' : 'Exact concurrent label', 'reason' => 'explicit_concurrent_rename'],
                    'selection' => ['realm_kind' => 'direct', 'requirements' => [['requirement_kind' => 'native_source', 'leaf_grant_id' => (int) $manager]]],
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/BusinessScopeCommandWorker.php'), $path], base_path());
                $worker->setTimeout(45);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            do {
                $ready = [];
                foreach ($paths as $path) {
                    clearstatcache(true, $path.'.ready');
                    if (is_file($path.'.ready')) {
                        $ready[] = json_decode(file_get_contents($path.'.ready'), true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                if (count($ready) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertCount(2, $ready, implode("\n", array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers)));
            $db->beginTransaction();
            $blocker = (int) $db->scalar('SELECT pg_backend_pid()');
            $db->select('SELECT fence_id FROM resource_right_fences WHERE kind=\'source\' AND source_id=?::uuid FOR UPDATE', [$this->input['source_id']]);
            file_put_contents($release, 'go');
            $deadline = microtime(true) + 25;
            do {
                // Observe outside the holder transaction: its statistics snapshot
                // can predate the workers' lock attempts and remain cached.
                $evidence = DB::select("SELECT pid,wait_event_type,pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$run]);
                if (count($evidence) === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertCount(2, $evidence, 'Both ready transactions must overlap under actual database locks.');
            self::assertTrue((bool) array_filter($evidence, fn ($row) => in_array($blocker,
                json_decode(str_replace(['{', '}'], ['[', ']'], $row->blockers), true), true)), 'Observe the held Source fence in the wait chain.');
            $db->commit();
            foreach ($workers as $worker) {
                $worker->wait();
            }
            $codes = array_map(fn ($p) => $p->getExitCode(), $workers);
            sort($codes);
            self::assertSame($conflicting ? [0, 2] : [0, 0], $codes, implode("\n", array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers)));
            if ($conflicting) {
                self::assertStringContainsString('DENIED_STATE=40001', implode("\n", array_map(fn ($p) => $p->getOutput(), $workers)));
            } else {
                self::assertSame($this->workerResult($workers[0]), $this->workerResult($workers[1]));
            }
            self::assertSame(2, $db->table('source_versions')->count());
            self::assertSame(2, $db->table('source_command_receipts')->count());
            self::assertSame(2, $db->table('source_audit_obligations')->count());
            self::assertSame(1, $db->table('business_command_receipts')->where('command_id', $command)->count());
        } finally {
            if ($db->transactionLevel()) {
                $db->rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            file_put_contents(storage_path('framework/testing/'.$run.'.evidence.json'), json_encode(['conflicting' => $conflicting, 'ready' => $ready, 'waits' => $evidence,
                'workers' => array_map(fn ($p) => ['exit' => $p->getExitCode(), 'output' => $p->getOutput(), 'error' => $p->getErrorOutput()], $workers)], JSON_THROW_ON_ERROR));
            foreach ($paths as $path) {
                foreach ([$path, $path.'.ready'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            if (is_file($release)) {
                unlink($release);
            }
        }
    }

    private function workerResult(Process $worker): array
    {
        self::assertMatchesRegularExpression('/COMMITTED=(.+)/', $worker->getOutput());
        preg_match('/COMMITTED=(.+)/', $worker->getOutput(), $matches);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
