<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/customer_directory_witness.sql')));
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION builderad_customer_directory_witness()');
    }
};
