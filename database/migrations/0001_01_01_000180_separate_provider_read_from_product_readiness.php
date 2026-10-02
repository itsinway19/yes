<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/provider_read_prerequisites.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Provider read prerequisites require a governed forward migration.');
    }
};
