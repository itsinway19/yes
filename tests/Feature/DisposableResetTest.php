<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

class DisposableResetTest extends TestCase
{
    public static function cleanupOutcomes(): array
    {
        return ['success' => [false], 'exception after reinstall' => [true]];
    }

    #[DataProvider('cleanupOutcomes')]
    public function test_populated_disposable_install_can_be_reset_repeatedly(bool $interrupt): void
    {
        $owner = DB::connection('pgsql_migrations');
        $lifecycle = app(DisposableDatabaseReset::class);
        $exception = new \RuntimeException('Synthetic exception after disposable reinstall');
        $caught = false;
        $owner->statement('CREATE FUNCTION disposable_unrelated_helper() RETURNS integer LANGUAGE sql AS $$ SELECT 1 $$');
        try {
            try {
                for ($reset = 0; $reset < 2; $reset++) {
                    $this->assertGreaterThan(0, DB::connection('pgsql_migrations')->table('migrations')->count());
                    User::factory()->create();
                    $this->assertGreaterThan(0, $owner->table('users')->count());
                    $lifecycle->install();
                    $this->assertSame(0, $owner->table('users')->count());
                    $this->assertSame(1, (int) $owner->scalar('SELECT disposable_unrelated_helper()'));
                    $this->assertGreaterThan(0, DB::connection('pgsql_migrations')->table('business_types')->count());
                    if ($interrupt) {
                        throw $exception;
                    }
                }
            } finally {
                $owner->statement('DROP FUNCTION disposable_unrelated_helper() RESTRICT');
                // Removing a catalog fixture invalidates the seal captured during install.
                // Rebuild via the existing guarded owner; never reseal arbitrary leftover state.
                $lifecycle->install();
            }
        } catch (\RuntimeException $error) {
            $this->assertSame($exception, $error);
            $caught = true;
        }
        $this->assertSame($interrupt, $caught);
        $this->assertNull($owner->scalar("SELECT to_regprocedure('public.disposable_unrelated_helper()')"));
        $this->assertSame(0, $owner->table('users')->count());
        $lifecycle->assertInstalledCatalog();
        $owner->transaction(function () use ($owner): void {
            $owner->statement('SET LOCAL ROLE builderad_scope_executor');
            $this->assertTrue((bool) $owner->scalar('SELECT builderad_private.legacy_catalog_digest() = builderad_private.legacy_installation_digest()'), 'Fixture cleanup left the installed catalog seal stale.');
        });
    }
}
