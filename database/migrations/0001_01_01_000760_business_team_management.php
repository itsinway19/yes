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
                IF to_regprocedure('public.builderad_v2_business_agency_grant(text,uuid,uuid,text,uuid,uuid,bigint,text,bigint,timestamptz,boolean,jsonb)') IS NULL
                  OR builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN
                    RAISE EXCEPTION 'BUSINESS_TEAM_BASELINE_REQUIRED' USING ERRCODE='42501';
                END IF;
            END $baseline$;
            SQL);
        DB::statement('RESET ROLE');
        DB::statement('GRANT SELECT(name,email) ON public.users TO builderad_scope_executor');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/team.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/mandates.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/legacy_links.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/grant_commands.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/business_product.sql')));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000760_business_team_management/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$ BEGIN
                RAISE EXCEPTION 'Employee permission history requires forward recovery.';
            END $recovery$;
            SQL);
    }
};
