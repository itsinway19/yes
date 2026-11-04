<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'COMMERCIAL_RETIREMENT_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        // Current Intent's environmental validator is INVOKER. Business's
        // executor already owns validate_horizon and name policy access; retain
        // the same finite timezone-support helper access previously used by the
        // native owner writer. This grants no fact or source authority.
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_current_intent_timezone_supported(text) TO builderad_scope_executor');
        // Workspace.name remains an organizational label. Preserve the exact
        // commercial-origin name before permitting independent label changes.
        DB::unprepared(<<<'SQL'
            CREATE TABLE legacy_workspace_commercial_names (
              workspace_id bigint PRIMARY KEY REFERENCES workspaces(id) ON DELETE RESTRICT,
              name varchar(255) NOT NULL, captured_at timestamptz NOT NULL DEFAULT clock_timestamp()
            );
            INSERT INTO legacy_workspace_commercial_names(workspace_id,name)
            SELECT w.id,coalesce((SELECT coalesce((SELECT e.before_value->>'name' FROM business_context_transitions e WHERE e.business_id=h.business_id ORDER BY after_context_version LIMIT 1),h.display_name)
              FROM legacy_scope_mappings m JOIN business_context_heads h ON h.import_mapping_id=m.mapping_id
              WHERE m.origin_table='workspaces' AND m.origin_key=jsonb_build_object('id',w.id)::text AND m.status='qualified'),w.name) FROM workspaces w;
            REVOKE ALL ON legacy_workspace_commercial_names FROM PUBLIC,builderad_app,builderad_authority;
            ALTER TABLE legacy_workspace_commercial_names ENABLE ROW LEVEL SECURITY;
            ALTER TABLE legacy_workspace_commercial_names FORCE ROW LEVEL SECURITY;
            CREATE POLICY preserved_name_owner ON legacy_workspace_commercial_names TO builderad_owner USING(true) WITH CHECK(true);
            CREATE POLICY preserved_name_executor ON legacy_workspace_commercial_names FOR SELECT TO builderad_scope_executor USING(true);
            CREATE POLICY preserved_name_read ON legacy_workspace_commercial_names FOR SELECT TO builderad_app USING(
              workspace_id IN(SELECT c.workspace_id FROM builderad_signed_context() c WHERE c.phase='workspace') AND builderad_v2_legacy_workspace_visible(workspace_id));
            GRANT SELECT ON legacy_workspace_commercial_names TO builderad_app,builderad_scope_executor;
            SQL);
        // Native functions are owned by the historical migration owner; drop
        // them there. Executor-owned effect guards remain independently fenced.
        DB::unprepared(file_get_contents(database_path('sql/business_scope/commercial_retirement.sql')));
        DB::statement('CREATE TRIGGER aa_preserved_commercial_name BEFORE INSERT OR UPDATE OR DELETE ON legacy_workspace_commercial_names FOR EACH ROW EXECUTE FUNCTION builderad_private.retired_workspace_fact_effect()');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('GRANT TRIGGER ON legacy_workspace_commercial_names TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::statement('CREATE TRIGGER aa_preserved_commercial_name_truncate BEFORE TRUNCATE ON legacy_workspace_commercial_names FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()');
        DB::unprepared(file_get_contents(database_path('sql/business_scope/retired_legacy_inventory.sql')));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE TRIGGER ON legacy_workspace_commercial_names FROM builderad_scope_executor');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Retired commercial writers must not be reactivated by rollback.');
    }
};
