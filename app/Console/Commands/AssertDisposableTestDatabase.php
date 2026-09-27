<?php

namespace App\Console\Commands;

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Console\Command;

final class AssertDisposableTestDatabase extends Command
{
    protected $signature = 'security:assert-disposable-test-db';

    protected $description = 'Read-only identity check before destructive local tests';

    public function handle(DisposableTestDatabaseGuard $guard): int
    {
        $guard->assertSafe();
        $this->info('Disposable PostgreSQL test database and all three roles verified.');

        return self::SUCCESS;
    }
}
