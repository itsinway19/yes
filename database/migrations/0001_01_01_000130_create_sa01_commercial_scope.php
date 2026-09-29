<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/sa01.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Commercial history is preserved; reversal requires a governed forward migration.');
    }
};
