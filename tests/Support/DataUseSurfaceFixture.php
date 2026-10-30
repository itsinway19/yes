<?php

namespace Tests\Support;

use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Prequalified synthetic scopes only; no operating root issuance or provider claim. */
final class DataUseSurfaceFixture
{
    public static function scope(User $actor, ?string $source = null): array
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        if ($source === null) {
            $workspace = $db->table('workspaces')->insertGetId(['name' => 'Synthetic data custody', 'slug' => 'data-custody-'.Str::uuid(),
                'status' => 'active', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
            $source = (string) Str::uuid7();
            $db->table('source_registry')->insert(['id' => $source, 'workspace_id' => $workspace, 'family' => 'business', 'namespace' => 'test.synthetic',
                'declared_external_id' => 'private-source-identifier', 'label' => 'Private source display name', 'version' => 1, 'status' => 'UNVERIFIED']);
            BusinessAuthorityFixture::insert('resource_right_fences', ['fence_id' => (string) Str::uuid7(), 'kind' => 'source',
                'source_id' => $source, 'boundary_id' => null, 'rights_epoch' => 0, 'adoption_state' => 'v2_adopted'], $actor->id);
        }
        $boundary = BusinessAuthorityFixture::boundary($actor->id);
        $scope = (string) Str::uuid7();
        $selector = ['native_namespaces' => ['test.private'], 'native_keys' => ['private-customer-key'], 'field_keys' => ['city'], 'partition_ref' => null];
        $digest = $db->transaction(function () use ($db, $boundary, $source, $selector) {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');

            return $db->scalar("SELECT encode(builderad_private.data_scope_digest(?::uuid,?::uuid,'qualified_records','process','market_research',?::jsonb),'hex')",
                [$boundary['boundary_id'], $source, json_encode($selector)]);
        });
        BusinessAuthorityFixture::insert('data_use_scopes', ['scope_id' => $scope, 'source_id' => $source, 'boundary_id' => $boundary['boundary_id'],
            'resource' => 'qualified_records', 'operation' => 'process', 'purpose' => 'market_research', 'selection' => $selector,
            'selection_schema_version' => 1, 'scope_digest' => '\\x'.$digest, 'status' => 'active', 'revision' => 1], $actor->id);
        $grant = BusinessAuthorityFixture::root($scope, $actor->id, 'data_use.process', kind: 'data_use');

        return ['scope_id' => $scope, 'source' => $source, 'boundary' => $boundary, 'grant_id' => $grant->id, 'qualification' => $grant->qualification_id];
    }

    public static function requalify(array $scope, User $actor): int
    {
        // Models a new independently qualified policy receipt, never revives the old grant.
        return BusinessAuthorityFixture::root($scope['scope_id'], $actor->id, 'data_use.process', kind: 'data_use')->id;
    }

    public static function retire(array $scope, User $actor): void
    {
        app(DisposableTestDatabaseGuard::class)->assertSafe();
        $db = DB::connection('pgsql_migrations');
        $db->transaction(function () use ($db, $scope, $actor): void {
            $db->statement('SET LOCAL ROLE builderad_scope_executor');
            $db->selectOne("SELECT builderad_private.start_command(?,'synthetic-session',?::uuid,'test.synthetic.fixture','{}'::jsonb,'{\"fixture\":true}'::jsonb)", [$actor->id, (string) Str::uuid()]);
            $prior = $db->scalar('SELECT to_jsonb(s)::text FROM data_use_scopes s WHERE scope_id=?::uuid', [$scope['scope_id']]);
            $next = json_decode($prior, true, flags: JSON_THROW_ON_ERROR);
            $next['status'] = 'retired';
            $next['revision']++;
            $db->selectOne("SELECT builderad_private.permit('public.data_use_scopes','update',?::jsonb,?::jsonb)", [$prior, json_encode($next)]);
            $db->table('data_use_scopes')->where('scope_id', $scope['scope_id'])->update(['status' => 'retired', 'revision' => $next['revision']]);
            $db->selectOne('SELECT builderad_private.finish_command()');
        });
    }
}
