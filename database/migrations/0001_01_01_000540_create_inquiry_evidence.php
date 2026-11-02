<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'INQUIRY_UPGRADE_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/inquiry_evidence/schema.sql')));
        DB::statement('GRANT TRIGGER ON inquiry_evidence_enrollments,inquiry_evidence_heads,inquiry_evidence_revisions TO builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/inquiry_evidence/table_permissions.sql')));
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['validation', 'authority', 'declarations', 'permissions'] as $file) {
            DB::unprepared(file_get_contents(database_path('sql/inquiry_evidence/'.$file.'.sql')));
        }
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection() ?? 'pgsql_migrations');
        // Never erase accepted source assertions, audit receipts or policy roots to roll back code.
        if ($db->table('inquiry_evidence_enrollments')->exists() || $db->table('inquiry_evidence_heads')->exists()) {
            throw new RuntimeException('Populated inquiry evidence requires qualified forward recovery.');
        }
        $db->statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        $db->statement('SET LOCAL ROLE builderad_scope_executor');
        $db->unprepared(<<<'SQL'
            DO $$ DECLARE f record; BEGIN
              FOR f IN SELECT p.oid::regprocedure AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
              WHERE (n.nspname='builderad_private' AND p.proname LIKE 'inquiry_%') OR (n.nspname='public' AND p.proname LIKE 'builderad_v2_inquiry_%') LOOP
                EXECUTE format('DROP FUNCTION %s',f.signature);
              END LOOP;
            END $$;
            SQL);
        $db->statement('RESET ROLE');
        $db->statement('DROP TABLE inquiry_evidence_revisions,inquiry_evidence_heads,inquiry_evidence_enrollments');
        $db->statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        $db->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }
};
