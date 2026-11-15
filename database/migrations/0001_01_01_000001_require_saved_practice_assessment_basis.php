<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000001_require_saved_practice_assessment_basis/requalify_practice_assessment.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                RAISE EXCEPTION 'Assessment replay rights require forward recovery.';
            END $$;
            SQL);
    }
};
