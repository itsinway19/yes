<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/commercial_delivery_effect_time.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Effect-time authority corrections require a forward migration.');
    }
};
