<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private static bool $databasePrepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if (! self::$databasePrepared) {
            Artisan::call('migrate:fresh', [
                '--database' => 'pgsql_migrations',
                '--force' => true,
            ]);

            self::$databasePrepared = true;
        }

        DB::connection('pgsql')->disconnect();
        DB::connection('pgsql_migrations')->statement(
            'TRUNCATE TABLE workspace_memberships, workspaces, password_reset_tokens, users RESTART IDENTITY CASCADE'
        );
        DB::purge('pgsql');
    }
}
