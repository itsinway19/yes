<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/pre_schema.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/schema.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/foundation_functions.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/authority.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/business.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/purpose.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/providers.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/source_material.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/data_market.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/commercial.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/customer_administration.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/seeds.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/catalog_constraints.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/identity_continuity.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/services.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/source_http.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/boundaries.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000000_business_native_baseline/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                RAISE EXCEPTION 'The Business-native installation requires forward recovery.';
            END $$;
            SQL);
    }
};
