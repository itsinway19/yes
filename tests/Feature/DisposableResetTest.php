<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

class DisposableResetTest extends TestCase
{
    public function test_populated_disposable_install_can_be_reset_repeatedly(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $owner->statement('CREATE FUNCTION disposable_unrelated_helper() RETURNS integer LANGUAGE sql AS $$ SELECT 1 $$');
        try {
            for ($reset = 0; $reset < 2; $reset++) {
                $this->assertGreaterThan(0, DB::connection('pgsql_migrations')->table('migrations')->count());
                User::factory()->create();
                $this->assertGreaterThan(0, $owner->table('users')->count());
                app(DisposableDatabaseReset::class)->install();
                $this->assertSame(0, $owner->table('users')->count());
                $this->assertSame(1, (int) $owner->scalar('SELECT disposable_unrelated_helper()'));
                $this->assertGreaterThan(0, DB::connection('pgsql_migrations')->table('business_types')->count());
            }
        } finally {
            $owner->statement('DROP FUNCTION disposable_unrelated_helper() RESTRICT');
        }
    }
}
