<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/foundation_workspace_actor.sql')));
        DB::unprepared(file_get_contents(database_path('sql/object_purpose.sql')));
    }

    public function down(): void
    {
        throw new LogicException('Object Purpose requires a forward migration; retained declarations cannot be erased.');
    }
};
