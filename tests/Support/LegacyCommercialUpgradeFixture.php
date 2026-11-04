<?php

namespace Tests\Support;

use App\Foundation\Tenancy\PrincipalContext;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Historical installation data only. Never re-enables a target-schema writer. */
final class LegacyCommercialUpgradeFixture
{
    public static function install(): void
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        app(DisposableDatabaseReset::class)->clear();
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp(basename($path), '0001_01_01_000600_retire_workspace_commercial_facts.php') < 0));
        if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]) !== 0) {
            throw new \RuntimeException('Historical fixture installation failed: '.Artisan::output());
        }
    }

    public static function write(User $actor, Request $request, object $workspace, string $function, array $parameters): mixed
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $query = match ($function) {
            'context' => 'SELECT builderad_business_context_write(?,?,?,?,?)',
            'markets' => 'SELECT builderad_business_target_markets_write(?,?,ARRAY(SELECT jsonb_array_elements_text(?::jsonb)),?)',
            'intent' => 'SELECT builderad_current_intent_write(?,?,?::jsonb,?::jsonb,?)',
            'preferences' => 'SELECT builderad_advertiser_goals_write(?,?,?::jsonb,?)',
            'purpose' => 'SELECT builderad_object_purpose_write(?,?,?::jsonb)',
            default => throw new \LogicException('Unknown historical writer'),
        };

        return DB::transaction(function () use ($actor, $request, $workspace, $query, $parameters) {
            app(PrincipalContext::class)->activate($actor);
            $session = SessionAuthority::sessionKey($request);
            DB::scalar('SELECT builderad_v2_legacy_enter(?::uuid)', [$workspace->public_id]);
            DB::scalar('SELECT builderad_business_context_begin(?,?)', [$workspace->id, $session]);

            return DB::scalar($query, [$workspace->id, $session, ...$parameters]);
        });
    }

    public static function upgrade(): void
    {
        DB::disconnect('pgsql');
        if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]) !== 0) {
            throw new \RuntimeException('Historical fixture upgrade failed: '.Artisan::output());
        }
    }
}
