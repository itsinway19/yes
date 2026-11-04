<?php

namespace Tests\Support;

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Test-only lifecycle; never registered as an application/runtime command. */
final class DisposableDatabaseReset
{
    public function install(): void
    {
        $this->clear();
        if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]) !== 0) {
            throw new RuntimeException('Disposable installation failed.');
        }
    }

    public function clear(): void
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        DB::disconnect('pgsql');
        DB::disconnect('pgsql_authority');
        // This declared read-only projection is owned by the isolated executor;
        // db:wipe's table cleanup does not remove views or executor-owned objects.
        $resetOwner = DB::connection('pgsql_migrations');
        $resetOwner->statement('SET ROLE builderad_scope_executor');
        try {
            $resetOwner->statement('DROP VIEW IF EXISTS public.organization_workspaces RESTRICT');
        } finally {
            $resetOwner->statement('RESET ROLE');
        }
        if (Artisan::call('db:wipe', ['--database' => 'pgsql_migrations', '--force' => true]) !== 0) {
            throw new RuntimeException('Disposable table reset failed.');
        }
        $owner = DB::connection('pgsql_migrations');
        // Only the guarded disposable target is reachable here. Private admission
        // state and non-login-owned functions must not survive a fresh install.
        $owner->statement('DROP SCHEMA IF EXISTS builderad_private CASCADE');
        $scopeFunctions = $owner->select(<<<'SQL'
            SELECT format('%I.%I(%s)', n.nspname, p.proname, pg_get_function_identity_arguments(p.oid)) AS signature
            FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE n.nspname='public' AND p.prokind='f' AND p.proname LIKE 'builderad_%'
              AND p.proowner=(SELECT oid FROM pg_roles WHERE rolname='builderad_scope_executor')
            SQL);
        if ($scopeFunctions !== []) {
            $owner->statement('SET ROLE builderad_scope_executor');
            try {
                $owner->statement('DROP FUNCTION '.implode(', ', array_column($scopeFunctions, 'signature')).' RESTRICT');
            } finally {
                $owner->statement('RESET ROLE');
            }
        }
        $functions = $owner->select(<<<'SQL'
            SELECT format('%I.%I(%s)', n.nspname, p.proname, pg_get_function_identity_arguments(p.oid)) AS signature
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.prokind = 'f'
              AND left(p.proname, 10) = 'builderad_'
              AND p.proowner = (SELECT oid FROM pg_roles WHERE rolname = current_user)
            ORDER BY p.oid
            SQL);
        if ($functions !== []) {
            // Drop the explicit owned set together; RESTRICT prevents collateral cleanup.
            $owner->statement('DROP FUNCTION '.implode(', ', array_column($functions, 'signature')).' RESTRICT');
        }
    }
}
