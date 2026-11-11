<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(<<<'SQL'
            DO $baseline$ BEGIN
                IF to_regprocedure('public.builderad_v2_business_team_change(text,uuid,uuid,jsonb,uuid,jsonb,jsonb,timestamptz,boolean)') IS NULL
                    OR builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN
                    RAISE EXCEPTION 'BUSINESS_AUTHORITY_CLOSURE_BASELINE_REQUIRED' USING ERRCODE='42501';
                END IF;
            END $baseline$;
            SQL);
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/authority.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/grant_commands.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/custody.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/custody_surface.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/team_context.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/operating_mandate.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/journey_projection.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/privacy.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/business_product.sql')));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000770_business_workspace_authority_closure/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$ BEGIN
                RAISE EXCEPTION 'Business custody and historical authority require forward recovery.';
            END $recovery$;
            SQL);
    }
};
