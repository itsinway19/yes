<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/advertiser_goal_preferences.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Advertiser preferences require a forward migration preserving audit history.');
    }
};
