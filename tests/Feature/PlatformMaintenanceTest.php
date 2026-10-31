<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\PlatformMaintenance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PlatformMaintenanceTest extends TestCase
{
    private const PATH = '/internal/administration/maintenance';

    private function operator(bool $grant = true, string $kind = 'employee'): User
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $db->table('principal_lifecycles')->where('user_id', $user->id)->update(['principal_class' => 'staff']);
        $db->table('internal_personnel')->insert(['user_id' => $user->id, 'kind' => $kind, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(), 'factor_independence_attested_at' => now()]);
        foreach ([1, 2] as $key) {
            $db->table('passkeys')->insert(['user_id' => $user->id, 'name' => 'fixture', 'credential_id' => $user->id.'-'.$key,
                'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($grant) {
            $db->table('security_action_grants')->insert(['subject_user_id' => $user->id, 'subject_generation' => 1,
                'source' => 'founder_bootstrap', 'action' => PlatformMaintenance::ACTION, 'scope_kind' => 'platform',
                'reason' => 'SYNTHETIC maintenance fixture', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function command(array $changes = []): array
    {
        return [...['command_id' => (string) Str::uuid(), 'revision' => 0, 'active' => true,
            'message_ar' => 'نجري صيانة مؤقتة.', 'message_en' => 'Scheduled improvements are in progress.',
            'retry_after' => 60, 'reason' => 'SYNTHETIC operational change'], ...$changes];
    }

    public function test_marketing_homepage_is_localized_public_truthful_and_uses_published_branding(): void
    {
        $this->get('/')->assertOk()->assertSee('dir="rtl"', false)->assertSee('قيد التطوير')
            ->assertSee('href="/register"', false)->assertSee('href="/login"', false)->assertSee('href="/forgot-password"', false)
            ->assertSee('name="description"', false)->assertSee('--ba-brand-primary:#155f53;', false);
        $this->withCookie('builderad_locale', 'en')->get('/')->assertOk()->assertSee('dir="ltr"', false)->assertSee('In development');
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertDontSee('platform.maintenance.manage');
    }

    public function test_only_explicit_maintenance_authority_can_read_or_change_and_freshness_is_required(): void
    {
        $this->actingAsInternal($this->operator(false, 'founder'))->getJson(self::PATH)->assertForbidden();
        $this->postJson(self::PATH, $this->command())->assertForbidden();
        $this->actingAsInternal($user = $this->operator());
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.maintenance.active', false);
        DB::connection('pgsql_migrations')->table('security_sessions')->where('user_id', $user->id)->update(['authenticated_at' => now()->subMinutes(6)]);
        $this->postJson(self::PATH, $this->command())->assertForbidden()->assertJsonPath('code', 'fresh_authentication_required');
        $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
    }

    public function test_maintenance_blocks_direct_advertiser_and_api_requests_but_preserves_public_and_internal_recovery(): void
    {
        $this->actingAsInternal($this->operator())->postJson(self::PATH, $this->command())->assertOk()->assertJsonPath('maintenance.revision', 1);
        $this->get('/', ['X-Builderad-Document' => '', 'X-Builderad-Transition' => '', 'Accept' => 'text/html'])->assertOk()->assertSee('نجري صيانة مؤقتة.');
        $this->getJson(self::PATH)->assertOk()->assertJsonPath('data.maintenance.active', true);
        $this->actingAs($advertiser = User::factory()->create());
        $paused = $this->get('/dashboard')->assertStatus(503)->assertHeader('Retry-After', '60')->assertSee('نعود إليك بعد قليل.');
        $this->assertTrue($paused->headers->hasCacheControlDirective('no-store'));
        foreach (['/api/businesses', '/api/market-evidence/'.Str::uuid(), '/api/businesses/'.Str::uuid().'/market-analysis/prepare'] as $url) {
            $this->postJson($url, [])->assertStatus(503)->assertJsonPath('code', 'platform_maintenance')->assertJsonMissingPath('data');
        }
        $this->get('/providers/google/callback?code=private')->assertStatus(503)->assertDontSee('private');
        $this->postJson('/register', [])->assertStatus(503);
        $this->getJson('/identity/context')->assertStatus(200);
        $this->get('/forgot-password')->assertRedirect();
        $this->getJson(self::PATH)->assertConflict();
    }

    public function test_commands_are_atomic_revision_checked_and_idempotent_even_after_later_changes(): void
    {
        $this->actingAsInternal($this->operator());
        $body = $this->command();
        $result = $this->postJson(self::PATH, $body)->assertOk()->json();
        $this->assertSame($result, $this->postJson(self::PATH, $body)->assertOk()->json());
        $this->postJson(self::PATH, [...$body, 'reason' => 'different'])->assertConflict();
        $this->postJson(self::PATH, $this->command())->assertConflict();
        $this->postJson(self::PATH, $this->command(['revision' => 1, 'active' => false]))->assertOk()->assertJsonPath('maintenance.revision', 2);
        $this->assertSame($result, $this->postJson(self::PATH, $body)->assertOk()->json());
        $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
        $this->assertSame(2, DB::connection('pgsql_migrations')->table('platform_maintenance_commands')->count());
    }

    public function test_revocation_prevents_replay_and_offline_owner_can_restore_service_without_an_admin_session(): void
    {
        $this->actingAsInternal($user = $this->operator());
        $body = $this->command();
        $this->postJson(self::PATH, $body)->assertOk();
        DB::connection('pgsql_migrations')->table('security_action_grants')->where('subject_user_id', $user->id)->update(['status' => 'revoked']);
        $this->postJson(self::PATH, $body)->assertForbidden();
        $command = (string) Str::uuid();
        $this->artisan('platform:maintenance-recover', ['--reason' => 'SYNTHETIC recovery', '--command' => $command])->assertSuccessful();
        $this->artisan('platform:maintenance-recover', ['--reason' => 'SYNTHETIC recovery', '--command' => $command])->assertSuccessful();
        $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
        $this->assertSame(2, app(PlatformMaintenance::class)->current()['revision']);
    }

    public function test_invalid_fields_and_unqualified_runtime_writes_cannot_change_state(): void
    {
        $this->actingAsInternal($this->operator());
        foreach ([['active' => 'true'], ['retry_after' => 0], ['revision' => -1], ['reason' => ''], ['command_id' => 'bad'], ['bypass' => true]] as $bad) {
            $this->postJson(self::PATH, $this->command($bad))->assertUnprocessable();
        }
        try {
            DB::table('platform_maintenance')->where('id', 1)->update(['active' => true]);
            $this->fail('Runtime cannot update platform availability.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }

    public function test_unavailable_authoritative_state_never_opens_advertiser_operations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/businesses')->assertOk();
        $db = DB::connection('pgsql_migrations');
        $db->table('platform_maintenance')->where('id', 1)->update(['active' => true]);
        $db->statement('ALTER TABLE platform_maintenance RENAME TO platform_maintenance_unavailable_fixture');
        try {
            $this->getJson('/api/businesses')->assertStatus(503)->assertJsonPath('code', 'platform_maintenance')->assertJsonMissingPath('data');
        } finally {
            $db->statement('ALTER TABLE platform_maintenance_unavailable_fixture RENAME TO platform_maintenance');
        }
        $db->table('platform_maintenance')->delete();
        try {
            $this->getJson('/api/businesses')->assertStatus(503)->assertJsonPath('code', 'platform_maintenance')->assertJsonMissingPath('data');
        } finally {
            $db->table('platform_maintenance')->insert(['id' => 1]);
        }
    }

    public function test_explicit_offline_founder_provisioning_and_private_receipt_acl(): void
    {
        $this->actingAsInternal($founder = $this->operator(false, 'founder'));
        $this->getJson(self::PATH)->assertForbidden();
        $this->artisan('platform:maintenance-recover', ['--reason' => 'SYNTHETIC explicit provisioning', '--authorize-founder' => $founder->public_id])->assertSuccessful();
        $this->getJson(self::PATH)->assertOk();
        $this->postJson(self::PATH, $this->command())->assertOk();
        try {
            DB::table('platform_maintenance_commands')->get();
            $this->fail('Runtime must not read privileged operational history.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
    }

    public function test_additive_install_on_populated_baseline_preserves_accounts_and_initializes_closed_write_privileges(): void
    {
        $user = User::factory()->create();
        $db = DB::connection('pgsql_migrations');
        $migration = require database_path('migrations/0001_01_01_000470_create_platform_maintenance.php');
        // Commit deployment transactions before reading through the independent runtime connection.
        $db->transaction(fn () => DB::usingConnection('pgsql_migrations', fn () => $migration->down()));
        try {
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
                $db->statement('RESET ROLE');
            });
            $db->statement("CREATE FUNCTION public.maintenance_upgrade_drift() RETURNS integer LANGUAGE sql AS 'SELECT 1'");
            try {
                $db->transaction(fn () => DB::usingConnection('pgsql_migrations', fn () => $migration->up()));
                $this->fail('Unqualified catalog drift must not be resealed by maintenance installation.');
            } catch (QueryException $error) {
                $this->assertSame('42501', $error->errorInfo[0]);
                $this->assertStringContainsString('PLATFORM_MAINTENANCE_UPGRADE_BASELINE_QUALIFICATION_REQUIRED', $error->getMessage());
                $this->assertNull($db->scalar("SELECT to_regclass('public.platform_maintenance')::text"));
            } finally {
                $db->statement('DROP FUNCTION public.maintenance_upgrade_drift()');
            }
            $db->transaction(fn () => DB::usingConnection('pgsql_migrations', fn () => $migration->up()));
            $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
            $this->assertSame(0, app(PlatformMaintenance::class)->current()['revision']);
            $this->assertSame($user->email, $db->table('users')->where('id', $user->id)->value('email'));
            $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('builderad_app','platform_maintenance','UPDATE')"));
            $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('builderad_app','platform_maintenance_commands','SELECT')"));
            $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('builderad_authority','platform_maintenance_commands','DELETE')"));
            $db->transaction(function () use ($db): void {
                $db->statement('SET LOCAL ROLE builderad_scope_executor');
                $this->assertTrue($db->scalar('SELECT builderad_private.legacy_catalog_digest()=builderad_private.legacy_installation_digest()'));
                $db->statement('RESET ROLE');
            });
        } finally {
            if ($db->scalar("SELECT to_regclass('public.platform_maintenance')::text") === null) {
                $db->transaction(fn () => DB::usingConnection('pgsql_migrations', fn () => $migration->up()));
            }
        }
    }

    public function test_independent_connection_observes_real_state_contention_and_rechecks_revision_and_expiry(): void
    {
        foreach (['revision', 'expiry'] as $case) {
            $this->actingAsInternal($user = $this->operator());
            $owner = DB::connection('pgsql_migrations');
            $owner->table('platform_maintenance')->where('id', 1)->update(['active' => false, 'revision' => 0]);
            $directory = storage_path('framework/testing/public-platform');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $file = $directory.'/worker-'.Str::uuid().'.json';
            file_put_contents($file, json_encode(['actor' => $user->id, 'session_id' => app('session')->driver()->getId(), 'body' => $this->command()], JSON_THROW_ON_ERROR));
            $worker = new Process([PHP_BINARY, 'tests/Support/PlatformMaintenanceWorker.php', $file], base_path());
            $worker->setTimeout(25);
            $owner->beginTransaction();
            $blocker = (int) $owner->scalar('SELECT pg_backend_pid()');
            $owner->table('platform_maintenance')->where('id', 1)->lockForUpdate()->sole();
            $worker->start();
            try {
                $deadline = microtime(true) + 15;
                $pid = null;
                do {
                    if (preg_match('/READY_PID=(\d+)/', $worker->getOutput(), $match)) {
                        $pid = (int) $match[1];
                    }
                    $waiting = $pid && $owner->scalar('SELECT ? = ANY(pg_blocking_pids(?))', [$blocker, $pid]);
                    if (! $waiting) {
                        usleep(10000);
                    }
                } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
                $this->assertTrue((bool) $waiting, 'Independent worker must actually wait on the state lock: '.$worker->getOutput().$worker->getErrorOutput());
                $this->assertNotSame($blocker, $pid);
                if ($case === 'revision') {
                    $owner->table('platform_maintenance')->where('id', 1)->update(['revision' => 1]);
                } else {
                    // Expire this exact grant only after real lock contention is observed.
                    // Waiting four seconds exceeded the production role's three-second lock timeout.
                    // Use a full second because the existing PHP grant predicate serializes second precision.
                    $owner->table('security_action_grants')->where('subject_user_id', $user->id)
                        ->where('action', PlatformMaintenance::ACTION)
                        ->update(['expires_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
                }
                $owner->commit();
                $worker->wait();
                file_put_contents($directory.'/contention-'.$case.'.json', json_encode(['case' => $case, 'blocker_pid' => $blocker, 'worker_pid' => $pid, 'real_wait_observed' => (bool) $waiting, 'worker_output' => $worker->getOutput()], JSON_THROW_ON_ERROR));
                $this->assertStringContainsString('"status":'.($case === 'revision' ? 409 : 403), $worker->getOutput());
                $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
                $this->assertSame(0, $owner->table('platform_maintenance_commands')->count());
            } finally {
                if ($owner->transactionLevel() > 0) {
                    $owner->rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
                @unlink($file);
            }
        }
    }

    public function test_audit_failure_rolls_back_state_and_user_message_is_escaped(): void
    {
        $this->actingAsInternal($operator = $this->operator());
        $db = DB::connection('pgsql_migrations');
        $db->unprepared("CREATE FUNCTION maintenance_test_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'SYNTHETIC audit failure'; END $$; CREATE TRIGGER maintenance_test_failure BEFORE INSERT ON platform_maintenance_commands FOR EACH ROW EXECUTE FUNCTION maintenance_test_failure();");
        try {
            $this->postJson(self::PATH, $this->command())->assertStatus(500);
            $this->assertFalse(app(PlatformMaintenance::class)->current()['active']);
        } finally {
            $db->unprepared('DROP TRIGGER maintenance_test_failure ON platform_maintenance_commands; DROP FUNCTION maintenance_test_failure()');
        }
        $this->actingAsInternal($operator);
        $this->postJson(self::PATH, $this->command(['message_ar' => '<script>alert(1)</script>']))->assertOk();
        $this->get('/', ['X-Builderad-Document' => '', 'X-Builderad-Transition' => '', 'Accept' => 'text/html'])->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
