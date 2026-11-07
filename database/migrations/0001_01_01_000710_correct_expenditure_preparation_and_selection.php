<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'EXPENDITURE_CORRECTION_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        // Compose the final (520+) canonical resource owner, retaining its
        // private-finding continuation set rather than replaying older bundles.
        $integration = file_get_contents(database_path('sql/market_knowledge/private_integration.sql'));
        $start = strpos($integration, 'CREATE OR REPLACE FUNCTION builderad_private.lock_material_resources(');
        $end = strpos($integration, 'CREATE OR REPLACE FUNCTION builderad_private.material_use_plan(');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Canonical final material resource locks required.');
        }
        DB::unprepared(substr($integration, $start, $end - $start));
        $summary = file_get_contents(database_path('sql/expenditure_summary.sql'));
        $start = strpos($summary, 'CREATE FUNCTION builderad_v2_expenditure_summary(');
        if ($start === false) {
            throw new RuntimeException('Canonical expenditure preparation required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($summary, $start)));
        $journey = file_get_contents(database_path('sql/data_license_journey.sql'));
        $start = strpos($journey, 'CREATE FUNCTION builderad_v2_data_license_journey(');
        if ($start === false) {
            throw new RuntimeException('Canonical Source-qualified selection required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($journey, $start)));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Historical licensing and command receipts require forward recovery.');
    }
};
