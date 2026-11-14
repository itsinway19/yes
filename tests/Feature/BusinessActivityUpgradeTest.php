<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\TestCase;

final class BusinessActivityUpgradeTest extends TestCase
{
    public function test_forward_only_recovery_refuses_down_and_preserves_history_and_catalog(): void
    {
        $this->actingAs(User::factory()->create());
        $business = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Forward recovery client'])->assertCreated()->json('data');
        $selection = fn ($grant) => ['realm_kind' => 'direct',
            'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => $grant]]];
        $body = ['command_id' => (string) Str::uuid(), 'practice_id' => (string) Str::uuid7(), 'operation' => 'create', 'expected_revision' => 0,
            'value' => ['name' => 'بيع الأجهزة', 'description' => '', 'kind' => 'sale', 'sectors' => [], 'specializations' => [], 'offering' => null, 'commercial_model' => null, 'channels' => [],
                'target_markets' => ['status' => 'unspecified', 'areas' => []], 'fulfillment' => ['status' => 'unspecified', 'areas' => []]],
            'authority_selection' => $selection($business['grant_refs']['business.context.manage']), 'read_selection' => $selection($business['grant_refs']['business.context.read'])];
        $this->postJson('/api/businesses/'.$business['business_public_id'].'/practices', $body)->assertOk();
        $owner = DB::connection('pgsql_migrations');
        $snapshot = $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY revision)::text FROM business_practice_revisions r');
        $migration = require database_path('migrations/0001_01_01_000000_business_native_baseline.php');
        $default = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_migrations');
            try {
                $migration->down();
                $this->fail('History-bearing installation must require forward recovery.');
            } catch (QueryException $error) {
                $this->assertSame('P0001', $error->errorInfo[0]);
                $this->assertStringContainsString('forward recovery', $error->getMessage());
            }
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertSame($snapshot, $owner->scalar('SELECT jsonb_agg(to_jsonb(r) ORDER BY revision)::text FROM business_practice_revisions r'));
        $this->assertNotNull($owner->scalar("SELECT to_regclass('public.business_practice_assessments')"));
        app(DisposableDatabaseReset::class)->assertInstalledCatalog();
    }

}
