<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/provider_access.sql')));
        DB::unprepared(file_get_contents(database_path('sql/provider_runtime/provider_access.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Provider access history requires a governed forward migration.');
    }
};
