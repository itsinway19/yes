<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000780_create_content_management/schema.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000780_create_content_management/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000780_create_content_management/teardown.sql')));
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000780_create_content_management/catalog_seal.sql')));
    }
};
