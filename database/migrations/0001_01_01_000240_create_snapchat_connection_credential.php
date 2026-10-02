<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/snapchat_connection_credential.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Snapchat connection custody requires a governed forward migration.');
    }
};
