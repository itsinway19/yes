<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'FINAL_SCOPE_CONTRACT_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        // Compose the current shared authority contracts, then apply the
        // deliberate terminal Purpose retirement guard. Never replay an entire
        // historical market integration over the newer Business/team policies.
        foreach (['authority', 'scope_authority', 'purpose_retirement', 'purpose_snapshot'] as $part) {
            DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path("sql/business_scope/{$part}.sql"))));
        }
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Private history and historical authority contracts require forward recovery.');
    }
};
