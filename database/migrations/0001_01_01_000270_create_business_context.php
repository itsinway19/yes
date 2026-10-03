<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/business_context.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Declared business context requires a forward migration; do not erase referenced context.');
    }
};
