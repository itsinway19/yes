<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'DATA_JOURNEY_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('GRANT TRIGGER ON builderad_private.invocations TO builderad_scope_executor');
        DB::statement('GRANT TRIGGER ON public.security_action_grants TO builderad_scope_executor');
        DB::statement('GRANT SELECT(generation) ON public.provider_observation_streams TO builderad_scope_executor');
        DB::statement('GRANT SELECT(name) ON public.users TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/data_license_journey.sql')));
        DB::unprepared(file_get_contents(database_path('sql/data_license_beneficiaries.sql')));
        // Use the same final verifier on upgrades as on fresh installations.
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path('sql/business_scope/scope_authority.sql'))));
        // Compose just the changed entry points from the other owners.
        $surfaces = file_get_contents(database_path('sql/business_scope/surfaces.sql'));
        $start = strpos($surfaces, 'CREATE FUNCTION builderad_v2_business_surface(');
        $end = strpos($surfaces, 'CREATE FUNCTION builderad_private.native_source_selection(');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Exact current Business surface definition required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($surfaces, $start, $end - $start)));
        $licensing = file_get_contents(database_path('sql/data_use_licensing.sql'));
        $start = strpos($licensing, 'CREATE FUNCTION builderad_v2_data_use_license(');
        if ($start === false) {
            throw new RuntimeException('Exact current data licence command definition required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($licensing, $start)));
        $integration = file_get_contents(database_path('sql/market_evidence/integration.sql'));
        $start = strpos($integration, 'CREATE OR REPLACE FUNCTION builderad_private.require_material_use(');
        $end = strpos($integration, 'CREATE OR REPLACE FUNCTION builderad_v2_scoped_material_read(');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Exact current material use definition required.');
        }
        DB::unprepared(substr($integration, $start, $end - $start));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE TRIGGER ON builderad_private.invocations FROM builderad_scope_executor');
        DB::statement('REVOKE TRIGGER ON public.security_action_grants FROM builderad_scope_executor');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Business operating mandates and consent history require forward recovery.');
    }
};
