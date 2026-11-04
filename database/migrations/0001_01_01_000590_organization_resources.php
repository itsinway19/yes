<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'ORGANIZATION_RESOURCES_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT SELECT(currency_code,created_at,updated_at) ON workspaces TO builderad_scope_executor');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_signed_service_context() TO builderad_scope_executor');
        // Remove only the commercial-cohort prerequisite injected into the original
        // selector. Its signed principal, membership, grant and owner locks remain.
        DB::unprepared(<<<'SQL'
            DO $adapt$ DECLARE body text;
            BEGIN
             SELECT pg_get_functiondef('builderad_select_workspace_context(bigint)'::regprocedure) INTO body;
             IF position('PERFORM builderad_private.legacy_admitted(p_workspace_id);' in body)=0 THEN RAISE EXCEPTION 'Expected selector prerequisite missing'; END IF;
             EXECUTE replace(body,'PERFORM builderad_private.legacy_admitted(p_workspace_id);','');
            END $adapt$;
            SQL);
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/business_scope/organization_resources.sql')));
        DB::unprepared(<<<'SQL'
            DO $adapt$ DECLARE body text;
            BEGIN
             SELECT pg_get_functiondef('builderad_v2_legacy_source_grant_guard(uuid)'::regprocedure) INTO body;
             IF position('PERFORM builderad_private.legacy_admitted(w);' in body)=0 THEN RAISE EXCEPTION 'Expected source prerequisite missing'; END IF;
             EXECUTE replace(body,'PERFORM builderad_private.legacy_admitted(w);','');
            END $adapt$;
            SQL);
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Organizational resource separation requires forward recovery.');
    }
};
