<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Predis\Client;
use Tests\TestCase;

class PreBaselineHardeningTest extends TestCase
{
    public function test_builderad_database_and_redis_fallbacks_are_coherent_without_environment_overrides(): void
    {
        $keys = [
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_OWNER_DATABASE',
            'DB_OWNER_USERNAME',
            'DB_OWNER_PASSWORD',
            'REDIS_CLIENT',
        ];
        $repository = Env::getRepository();
        $original = [];

        foreach ($keys as $key) {
            $original[$key] = [
                'present' => $repository->has($key),
                'value' => $repository->get($key),
            ];
            $repository->clear($key);
        }

        try {
            $configuration = require config_path('database.php');
        } finally {
            foreach ($original as $key => $state) {
                if ($state['present']) {
                    $repository->set($key, $state['value']);
                } else {
                    $repository->clear($key);
                }
            }
        }

        $this->assertSame('pgsql', $configuration['default']);
        $this->assertSame('builderad', $configuration['connections']['pgsql']['database']);
        $this->assertSame('builderad_app', $configuration['connections']['pgsql']['username']);
        $this->assertSame('', $configuration['connections']['pgsql']['password']);
        $this->assertSame('builderad', $configuration['connections']['pgsql_migrations']['database']);
        $this->assertSame('builderad_owner', $configuration['connections']['pgsql_migrations']['username']);
        $this->assertSame('', $configuration['connections']['pgsql_migrations']['password']);
        $this->assertSame('predis', $configuration['redis']['client']);
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

    public function test_runtime_user_delete_is_denied_and_preserves_user_and_membership(): void
    {
        [$userId, $workspaceId] = $this->fixture();

        $this->assertFalse(DB::scalar("select has_table_privilege(current_user, 'users', 'DELETE')"));

        try {
            DB::table('users')->where('id', $userId)->delete();
            $this->fail('The runtime role must not be able to delete a User.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $owner = DB::connection('pgsql_migrations');
        $this->assertTrue($owner->table('users')->where('id', $userId)->exists());
        $this->assertTrue($owner->table('workspace_memberships')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->exists());
    }

    public function test_user_membership_foreign_key_restricts_owner_side_user_deletion(): void
    {
        [$userId, $workspaceId] = $this->fixture();
        $owner = DB::connection('pgsql_migrations');

        $deleteRule = $owner->table('information_schema.referential_constraints')
            ->where('constraint_schema', 'public')
            ->where('constraint_name', 'workspace_memberships_user_id_foreign')
            ->value('delete_rule');
        $this->assertSame('RESTRICT', $deleteRule);

        try {
            $owner->table('users')->where('id', $userId)->delete();
            $this->fail('The User foreign key must fail closed while a membership exists.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($owner->table('users')->where('id', $userId)->exists());
        $this->assertTrue($owner->table('workspace_memberships')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->exists());
    }

    public function test_default_database_seeder_creates_no_application_credentials_and_factories_remain_usable(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        $owner = DB::connection('pgsql_migrations');
        $this->assertSame(0, $owner->table('users')->count());

        $user = User::factory()->create();
        $this->assertTrue($owner->table('users')->where('id', $user->getKey())->exists());
    }

    public function test_workspace_public_id_catalog_invariant_is_database_owned_uuidv7(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $column = $owner->table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'workspaces')
            ->where('column_name', 'public_id')
            ->first(['data_type', 'is_nullable', 'column_default']);

        $this->assertSame('uuid', $column->data_type);
        $this->assertSame('NO', $column->is_nullable);
        $this->assertSame('uuidv7()', $column->column_default);
        $this->assertTrue($owner->scalar(<<<'SQL'
            select exists (
                select 1
                from pg_index index
                join pg_class table_relation on table_relation.oid = index.indrelid
                join pg_namespace namespace on namespace.oid = table_relation.relnamespace
                where namespace.nspname = 'public'
                  and table_relation.relname = 'workspaces'
                  and index.indisunique
                  and index.indkey::text = (
                      select attnum::text
                      from pg_attribute
                      where attrelid = 'public.workspaces'::regclass
                        and attname = 'public_id'
                  )
            )
            SQL));
    }

    public function test_owner_direct_workspace_insert_omitting_public_id_gets_uuidv7(): void
    {
        $owner = DB::connection('pgsql_migrations');

        try {
            $workspaceId = $owner->table('workspaces')->insertGetId([
                'name' => 'Database UUIDv7',
                'slug' => 'database-uuidv7-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'currency_code' => null,
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $this->fail('A direct owner insert that omits public_id must receive the PostgreSQL default.');
        }

        $identity = $owner->table('workspaces')
            ->where('id', $workspaceId)
            ->selectRaw('public_id, uuid_extract_version(public_id) as version')
            ->first();

        $this->assertNotNull($identity->public_id);
        $this->assertSame(7, $identity->version);
    }

    public function test_workspace_membership_foreign_key_restricts_owner_side_workspace_deletion(): void
    {
        [$userId, $workspaceId] = $this->fixture();
        $owner = DB::connection('pgsql_migrations');

        $deleteRule = $owner->table('information_schema.referential_constraints')
            ->where('constraint_schema', 'public')
            ->where('constraint_name', 'workspace_memberships_workspace_id_foreign')
            ->value('delete_rule');
        $this->assertSame('RESTRICT', $deleteRule);

        try {
            $owner->table('workspaces')->where('id', $workspaceId)->delete();
            $this->fail('A Workspace with a membership must not be physically deleted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($owner->table('workspaces')->where('id', $workspaceId)->exists());
        $this->assertTrue($owner->table('workspace_memberships')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->exists());
    }

    public function test_workspace_public_id_accepts_uuidv7_and_rejects_uuidv4(): void
    {
        $owner = DB::connection('pgsql_migrations');
        $constraint = $owner->table('pg_constraint')
            ->where('conname', 'workspaces_public_id_uuidv7_check')
            ->selectRaw('pg_get_constraintdef(oid) as definition')
            ->first();

        $this->assertNotNull($constraint);
        $this->assertStringContainsString('uuid_extract_version(public_id) = 7', $constraint->definition);

        $this->assertTrue($owner->table('workspaces')->insert([
            'public_id' => (string) Str::uuid7(),
            'name' => 'Explicit UUIDv7',
            'slug' => 'explicit-v7-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'currency_code' => null,
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        try {
            $owner->table('workspaces')->insert([
                'public_id' => (string) Str::uuid(),
                'name' => 'Explicit UUIDv4',
                'slug' => 'explicit-v4-'.Str::lower(Str::random(8)),
                'status' => 'active',
                'currency_code' => null,
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('An explicitly supplied UUIDv4 must violate the Workspace UUIDv7 invariant.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array{0: int, 1: int} */
    private function fixture(): array
    {
        $owner = DB::connection('pgsql_migrations');
        $userId = $owner->table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'name' => 'Lifecycle User',
            'email' => Str::random(12).'@example.test',
            'email_verified_at' => now(),
            'password' => bcrypt('test-only-password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workspaceId = $owner->table('workspaces')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'name' => 'Lifecycle Workspace',
            'slug' => 'lifecycle-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'currency_code' => null,
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner->table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => 'active',
            'created_at' => now(),
            'revoked_at' => null,
        ]);

        return [$userId, $workspaceId];
    }
}
