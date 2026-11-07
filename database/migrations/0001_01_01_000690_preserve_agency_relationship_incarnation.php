<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'AGENCY_INCARNATION_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        // Keep earlier stored instants exact and preserve future command ordering.
        // Second rounding can otherwise merge a departed membership with its replacement.
        DB::statement('ALTER TABLE security_action_grants ALTER COLUMN created_at TYPE timestamptz(6)');
        DB::statement('ALTER TABLE workspace_memberships ALTER COLUMN created_at TYPE timestamptz(6)');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        $authority = file_get_contents(database_path('sql/business_scope/scope_authority.sql'));
        $journey = file_get_contents(database_path('sql/data_license_journey.sql'));
        $verifier = strpos($authority, 'CREATE FUNCTION builderad_private.verify_scope_path(');
        $wrapper = strpos($authority, '-- Public commands enter through');
        $mandate = strpos($journey, 'CREATE FUNCTION builderad_v2_business_operating_mandate(');
        $inventory = strpos($journey, 'CREATE FUNCTION builderad_v2_data_license_journey(');
        if ($verifier === false || $wrapper === false || $wrapper <= $verifier
            || $mandate === false || $inventory === false || $inventory <= $mandate) {
            throw new RuntimeException('The canonical agency verification and mandate definitions are required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($authority, $verifier, $wrapper - $verifier)));
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($journey, $mandate)));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Agency relationship history requires forward recovery.');
    }
};
