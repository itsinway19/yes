<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL checks EXECUTE when attaching a trigger. Grant only for this
        // atomic installation, then close it again; runtime ACLs never change.
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        // Never seal unrelated catalog drift as part of this approved upgrade.
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest()
                THEN RAISE EXCEPTION 'MARKET_UPGRADE_BASELINE_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
            END $$;
            SQL);
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_private.guard_effect() TO builderad_owner');
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/schema.sql')));
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['integration', 'evidence', 'admission'] as $part) {
            DB::unprepared(file_get_contents(database_path("sql/market_evidence/{$part}.sql")));
        }
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/permissions.sql')));
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::statement('REVOKE EXECUTE ON FUNCTION builderad_private.guard_effect() FROM builderad_owner');
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Market evidence, rights and history require qualified forward recovery.');
    }
};
