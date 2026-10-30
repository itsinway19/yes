<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $role = DB::selectOne("SELECT rolcanlogin, rolsuper, rolbypassrls, rolinherit, rolcreaterole, rolcreatedb, pg_has_role('builderad_app', oid, 'MEMBER') OR pg_has_role('builderad_authority', oid, 'MEMBER') AS runtime_member, pg_has_role('builderad_owner', oid, 'SET') AS deployer_can_set FROM pg_roles WHERE rolname = 'builderad_scope_executor'");
        if (! $role || $role->rolcanlogin || $role->rolsuper || $role->rolbypassrls || $role->rolinherit || $role->rolcreaterole || $role->rolcreatedb || $role->runtime_member || ! $role->deployer_can_set) {
            throw new RuntimeException('Qualified non-login Business executor must be provisioned by administrative bootstrap.');
        }
        foreach (['schema', 'codec', 'authority', 'scope_authority', 'commands', 'facts', 'grant_commands', 'resource_rights', 'source_rights', 'data_use', 'producer_contracts', 'material_lineage', 'material_use', 'material_publish', 'lifecycle', 'cohort', 'legacy_fences', 'purpose', 'source_adoption', 'source_facts', 'legacy_import', 'surfaces', 'history', 'legacy_history', 'permissions', 'legacy_install_seal'] as $part) {
            DB::unprepared(file_get_contents(database_path("sql/business_scope/{$part}.sql")));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Business truth and rights require a qualified forward recovery, not a destructive down migration.');
    }
};
