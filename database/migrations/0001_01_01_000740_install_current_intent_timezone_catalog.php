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
                IF to_regclass('public.registration_email_proofs') IS NULL
                    OR builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN
                    RAISE EXCEPTION 'TIMEZONE_REFERENCE_BASELINE_REQUIRED' USING ERRCODE='42501';
                END IF;
            END;
            $baseline$;
            SQL);
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public TO builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000740_install_current_intent_timezone_catalog/definition.sql')));
        DB::statement('REVOKE CREATE ON SCHEMA public FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000740_install_current_intent_timezone_catalog/catalog_seal.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000740_install_current_intent_timezone_catalog/refresh.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$
            BEGIN
                RAISE EXCEPTION 'Installed timezone references require forward recovery.';
            END;
            $recovery$;
            SQL);
    }
};
