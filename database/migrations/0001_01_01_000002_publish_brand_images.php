<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000002_publish_brand_images/images.sql')));
    }

    public function down(): void
    {
        DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'Published brand images require forward recovery.'; END $$;");
    }
};
