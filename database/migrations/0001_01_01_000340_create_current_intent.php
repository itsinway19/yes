<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/current_intent.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Current Intent requires forward migration; do not erase declaration history.');
    }
};
