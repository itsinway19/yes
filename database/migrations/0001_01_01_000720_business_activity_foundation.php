<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('GRANT REFERENCES ON public.businesses,public.users TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000720_business_activity_foundation/definition.sql')));
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::statement('REVOKE REFERENCES ON public.businesses,public.users FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000720_business_activity_foundation/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$
            BEGIN
                RAISE EXCEPTION 'Practice and assessment history require forward recovery.';
            END;
            $recovery$;
            SQL);
    }
};
