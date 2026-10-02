<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/snapchat_renewal_failures.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Snapchat renewal evidence requires a governed forward migration.');
    }
};
