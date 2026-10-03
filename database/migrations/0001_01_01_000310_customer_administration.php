<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/customer_administration.sql')));
        DB::unprepared(file_get_contents(database_path('sql/customer_owner_reassessment.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Customer administration audit history must be preserved; use a forward migration.');
    }
};
