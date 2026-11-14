<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkspaceRetirementTest extends TestCase
{
    public function test_retired_paths_have_no_laravel_route_or_compatibility_writer(): void
    {
        $this->actingAs(User::factory()->create());
        $id = (string) Str::uuid7();
        foreach (['/workspaces', '/workspaces/'.$id, '/workspaces/'.$id.'/providers/google', '/organizations/new', '/api/organizations/'.$id.'/team'] as $path) {
            $this->get($path)->assertNotFound();
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/workspaces', ['name' => 'Retired creation'])->assertNotFound();
        $this->postJson('/workspaces/'.$id.'/reassess-owner-grant')->assertNotFound();
        $this->assertSame(0, DB::connection('pgsql_migrations')->table('businesses')->count());
    }

    public function test_fresh_installation_contains_no_workspace_model_or_authority(): void
    {
        $objects = DB::connection('pgsql_migrations')->select(<<<'SQL'
            WITH application_schema AS (
                SELECT oid FROM pg_namespace WHERE nspname IN ('public','builderad_private')
            )
            SELECT 'relation' AS kind, c.relname::text AS object_name
              FROM pg_class c WHERE c.relnamespace IN (SELECT oid FROM application_schema) AND c.relname ILIKE '%workspace%'
            UNION ALL
            SELECT 'column', c.relname||'.'||a.attname FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid
              WHERE c.relnamespace IN (SELECT oid FROM application_schema) AND a.attnum>0 AND NOT a.attisdropped AND a.attname ILIKE '%workspace%'
            UNION ALL
            SELECT 'function', p.proname::text FROM pg_proc p WHERE p.pronamespace IN (SELECT oid FROM application_schema)
              AND p.prokind='f' AND pg_get_functiondef(p.oid) ILIKE '%workspace%'
            UNION ALL
            SELECT 'policy', tablename||'.'||policyname FROM pg_policies
              WHERE schemaname IN ('public','builderad_private') AND concat_ws(' ',policyname,qual,with_check) ILIKE '%workspace%'
            UNION ALL
            SELECT 'constraint', conname::text FROM pg_constraint WHERE connamespace IN (SELECT oid FROM application_schema)
              AND concat_ws(' ',conname,pg_get_constraintdef(oid)) ILIKE '%workspace%'
            ORDER BY 1,2
            SQL);
        $this->assertSame([], $objects, 'A clean installation must never contain the retired Workspace model.');
    }
}
