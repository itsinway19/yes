<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Predis\Client;
use Tests\TestCase;

class PreBaselineHardeningTest extends TestCase
{
    public function test_builderad_database_and_redis_configuration_declares_safe_fallbacks(): void
    {
        $source = file_get_contents(config_path('database.php'));

        foreach ([
            "'default' => env('DB_CONNECTION', 'pgsql')",
            "'database' => env('DB_DATABASE', 'builderad')",
            "'username' => env('DB_USERNAME', 'builderad_app')",
            "'password' => env('DB_PASSWORD', '')",
            "'database' => env('DB_OWNER_DATABASE', env('DB_DATABASE', 'builderad'))",
            "'username' => env('DB_OWNER_USERNAME', 'builderad_owner')",
            "'password' => env('DB_OWNER_PASSWORD', '')",
            "'client' => env('REDIS_CLIENT', 'predis')",
        ] as $fallback) {
            $this->assertStringContainsString($fallback, $source);
        }
    }

    public function test_configured_predis_connection_can_write_read_and_delete(): void
    {
        $this->assertTrue(class_exists(Client::class));
        $this->assertSame('predis', config('database.redis.client'));

        $key = 'builderad:foundation-proof:'.Str::uuid7();

        try {
            Redis::connection()->set($key, 'verified');
            $this->assertSame('verified', Redis::connection()->get($key));
            $this->assertSame(1, Redis::connection()->del([$key]));
            $this->assertNull(Redis::connection()->get($key));
        } finally {
            Redis::connection()->del([$key]);
        }
    }

    public function test_runtime_user_delete_is_denied_and_preserves_business_creation(): void
    {
        $user = User::factory()->create();
        $business = $this->actingAs($user)->postJson('/api/businesses', [
            'command_id' => (string) Str::uuid7(), 'name' => 'Protected Business',
        ])->assertCreated()->json('data');
        $this->assertFalse(DB::scalar("select has_table_privilege(current_user, 'users', 'DELETE')"));
        try {
            DB::table('users')->where('id', $user->id)->delete();
            $this->fail('Runtime can delete a user.');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
        $owner = DB::connection('pgsql_migrations');
        $this->assertTrue($owner->table('users')->where('id', $user->id)->exists());
        $this->assertTrue($owner->table('businesses')->where('public_id', $business['business_public_id'])->where('created_by', $user->id)->exists());
    }

    public function test_default_database_seeder_creates_no_application_credentials_and_factories_remain_usable(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('users')->count());

        $user = User::factory()->create();
        $this->assertTrue($owner->table('users')->where('id', $user->getKey())->exists());
    }

    public function test_current_business_writer_assigns_distinct_database_qualified_uuidv7_identifiers(): void
    {
        $this->actingAs(User::factory()->create());
        $ids = [];
        foreach (['First Business', 'Second Business'] as $name) {
            $ids[] = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid7(), 'name' => $name])->assertCreated()->json('data.business_public_id');
        }
        $this->assertNotSame(...$ids);
        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(2, $owner->table('businesses')->whereIn('public_id', $ids)->whereRaw('uuid_extract_version(public_id)=7')->count());
        $this->assertTrue($owner->scalar("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='public.businesses'::regclass AND conname='businesses_public_id_check' AND convalidated)"));
    }
}
