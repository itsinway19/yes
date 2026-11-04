<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'PURPOSE_STATEMENT_POLICY_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['scope_authority', 'surfaces', 'purpose_statement_policy'] as $part) {
            DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path("sql/business_scope/{$part}.sql"))));
        }
        DB::statement('REVOKE ALL ON FUNCTION builderad_v2_business_purpose_enroll(text,uuid,uuid,boolean,bigint,jsonb) FROM PUBLIC,builderad_authority');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_v2_business_purpose_enroll(text,uuid,uuid,boolean,bigint,jsonb) TO builderad_app');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Purpose statement qualification and history require forward recovery.');
    }
};
