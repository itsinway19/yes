<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'SOURCE_VISIBILITY_UPGRADE_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/business_scope/business_source_visibility.sql')));
        DB::statement('REVOKE ALL ON FUNCTION builderad_v2_business_source_choices(text,uuid,bigint,bigint) FROM PUBLIC,builderad_authority');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_v2_business_source_choices(text,uuid,bigint,bigint) TO builderad_app');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'SOURCE_VISIBILITY_REVERSAL_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('DROP FUNCTION builderad_v2_business_source_choices(text,uuid,bigint,bigint)');
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }
};
