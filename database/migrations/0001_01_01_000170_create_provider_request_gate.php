<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/provider_request_gate.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Provider execution authority requires a governed forward migration.');
    }
};
