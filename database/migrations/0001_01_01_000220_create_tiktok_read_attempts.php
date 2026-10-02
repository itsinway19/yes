<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/tiktok_read_attempts.sql')));
    }

    public function down(): void
    {
        throw new LogicException('TikTok execution history requires a governed forward migration.');
    }
};
