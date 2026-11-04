<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'INTERNAL_TEAM_UPGRADE_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('ALTER TABLE business_workspace_links DROP CONSTRAINT business_workspace_links_kind_check');
        DB::statement("ALTER TABLE business_workspace_links ADD CONSTRAINT business_workspace_links_kind_check CHECK(kind IN('collaboration','agency','internal_team'))");
        DB::statement('ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_delegation_realm_kind_check');
        DB::statement("ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_delegation_realm_kind_check CHECK(delegation_realm_kind IN('direct','agency','internal_team'))");
        DB::unprepared(<<<'SQL'
            DO $$ DECLARE original text; expanded text; BEGIN
              SELECT pg_get_constraintdef(oid) INTO original FROM pg_constraint
                WHERE conrelid='security_action_grants'::regclass AND conname='security_action_grants_scope_check';
              original:=substring(original FROM 8 FOR length(original)-8);
              expanded:=replace(original,'''agency''::text','''internal_team''::text');
              IF original IS NULL OR expanded=original THEN RAISE EXCEPTION 'Expected scope constraint required'; END IF;
              ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_scope_check;
              EXECUTE 'ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_scope_check CHECK (('
                ||original||') OR (('||expanded||') AND scope_kind=''business''))';
            END $$;
            SQL);
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['scope_authority', 'grant_commands', 'history', 'legacy_history', 'internal_team'] as $part) {
            DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path("sql/business_scope/{$part}.sql"))));
        }
        DB::statement('REVOKE ALL ON FUNCTION builderad_private.internal_team_admin(bigint,bigint,uuid,bigint,bytea) FROM PUBLIC,builderad_app,builderad_authority');
        DB::statement('REVOKE ALL ON FUNCTION builderad_v2_internal_team_command(text,uuid,uuid,uuid,text,uuid,bigint,jsonb) FROM PUBLIC,builderad_authority');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_v2_internal_team_command(text,uuid,uuid,uuid,text,uuid,bigint,jsonb) TO builderad_app');
        DB::statement('REVOKE ALL ON FUNCTION builderad_v2_internal_team_surface(text,uuid,bigint) FROM PUBLIC,builderad_authority');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_v2_internal_team_surface(text,uuid,bigint) TO builderad_app');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Internal file custody and receipts require forward recovery; do not erase accepted authority history.');
    }
};
