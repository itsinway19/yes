<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest()
                THEN RAISE EXCEPTION 'PRIVATE_MARKET_FINDING_UPGRADE_BASELINE_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
            END $$;
            SQL);
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_private.guard_effect() TO builderad_owner');
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_knowledge/private_schema.sql')));
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['private_capture', 'private_producer', 'private_integration', 'dispatch', 'private_read', 'private_retention'] as $part) {
            DB::unprepared(file_get_contents(database_path("sql/market_knowledge/{$part}.sql")));
        }
        DB::statement('REVOKE EXECUTE ON FUNCTION builderad_private.guard_effect() FROM builderad_owner');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Market operational authority requires qualified forward recovery.');
    }
};
