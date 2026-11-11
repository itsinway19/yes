<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Populate the real former schema, then test consumers on the final schema. */
trait Pre770BusinessAuthority
{
    private bool $historicalBusinessAuthorityInstallation = false;

    private function installPre770BusinessAuthority(): void
    {
        app(DisposableDatabaseReset::class)->clear();
        $this->historicalBusinessAuthorityInstallation = true;
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => strcmp(basename($path), '0001_01_01_000770_') < 0));
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]), Artisan::output());
    }

    private function upgradeBusinessAuthority(): void
    {
        DB::disconnect('pgsql');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]), Artisan::output());
        $this->historicalBusinessAuthorityInstallation = false;
    }

    protected function tearDown(): void
    {
        try {
            // A failed fixture must never leave the shared disposable database
            // with a former issuer installed for the next test.
            if ($this->historicalBusinessAuthorityInstallation) {
                $this->upgradeBusinessAuthority();
            }
        } finally {
            parent::tearDown();
        }
    }
}
