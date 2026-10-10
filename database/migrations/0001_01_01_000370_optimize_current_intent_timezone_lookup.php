<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reuse the fresh-install definition; replace only this function on upgrades.
        $sql = file_get_contents(database_path('sql/current_intent.sql'));
        if (! preg_match('/CREATE FUNCTION builderad_current_intent_timezone_supported\(p_zone text\) RETURNS boolean\R.*?\R\$\$;/s', $sql, $definition)) {
            throw new LogicException('Current Intent timezone function definition not found.');
        }
        DB::unprepared(preg_replace('/^CREATE FUNCTION/', 'CREATE OR REPLACE FUNCTION', $definition[0], 1));
    }

    public function down(): void
    {
        throw new LogicException('Current Intent timezone optimization requires a forward migration.');
    }
};
