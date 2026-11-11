<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(<<<'SQL'
            DO $baseline$
            BEGIN
                IF to_regclass('public.current_intent_timezone_catalog') IS NULL
                    OR builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN
                    RAISE EXCEPTION 'BUSINESS_AGENCY_BASELINE_REQUIRED' USING ERRCODE='42501';
                END IF;
            END;
            $baseline$;
            SQL);
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/schema.sql')));
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/locks.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/agency.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/authority.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/grant_commands.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/surfaces.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/projection.sql')));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000750_business_agency_ownership/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$
            BEGIN
                RAISE EXCEPTION 'Agency qualification history requires forward recovery.';
            END;
            $recovery$;
            SQL);
    }
};
