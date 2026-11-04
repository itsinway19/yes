<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'ORGANIZATION_TEAM_UPGRADE_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::unprepared(<<<'SQL'
            ALTER TABLE workspace_memberships ADD COLUMN joined_revision bigint NOT NULL DEFAULT 0 CHECK(joined_revision>=0);
            CREATE TABLE organization_team_revisions (
              workspace_id bigint PRIMARY KEY REFERENCES workspaces(id) ON DELETE RESTRICT,
              revision bigint NOT NULL CHECK(revision>0)
            );
            REVOKE ALL ON organization_team_revisions FROM PUBLIC,builderad_app,builderad_authority;
            ALTER TABLE organization_team_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_team_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY organization_revision_owner ON organization_team_revisions TO builderad_owner USING(true) WITH CHECK(true);
            CREATE POLICY organization_revision_read ON organization_team_revisions FOR SELECT TO builderad_scope_executor USING(true);
            CREATE POLICY organization_revision_insert ON organization_team_revisions FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed('public.organization_team_revisions','insert',to_jsonb(organization_team_revisions.*)));
            CREATE POLICY organization_revision_update ON organization_team_revisions FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.organization_team_revisions','update',to_jsonb(organization_team_revisions.*)));
            GRANT SELECT,INSERT,UPDATE ON organization_team_revisions TO builderad_scope_executor;
            GRANT INSERT,UPDATE(role,status,created_at,revoked_at,joined_revision) ON workspace_memberships TO builderad_scope_executor;
            CREATE POLICY organization_member_insert ON workspace_memberships FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed('public.workspace_memberships','insert',to_jsonb(workspace_memberships.*)));
            CREATE POLICY organization_member_update ON workspace_memberships FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.workspace_memberships','update',to_jsonb(workspace_memberships.*)));
            CREATE POLICY organization_view_insert ON security_action_grants FOR INSERT TO builderad_scope_executor WITH CHECK(scope_kind='workspace' AND action='workspace.view' AND source='human' AND builderad_private.effect_allowed('public.security_action_grants','insert',to_jsonb(security_action_grants.*)));
            SQL);
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('GRANT TRIGGER ON organization_team_revisions,workspace_memberships TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER zz_organization_revision_effect BEFORE INSERT OR UPDATE OR DELETE ON organization_team_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
            CREATE TRIGGER zz_organization_revision_truncate BEFORE TRUNCATE ON organization_team_revisions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
            CREATE TRIGGER yy_organization_membership_effect BEFORE INSERT OR UPDATE OR DELETE ON workspace_memberships FOR EACH ROW WHEN(current_user='builderad_scope_executor') EXECUTE FUNCTION builderad_private.guard_effect();
            SQL);
        foreach (['authority', 'scope_authority', 'organization_team'] as $part) {
            DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path("sql/business_scope/{$part}.sql"))));
        }
        DB::statement('REVOKE ALL ON FUNCTION builderad_private.organization_actor(text,uuid,uuid,boolean,boolean) FROM PUBLIC,builderad_app,builderad_authority');
        DB::statement('REVOKE ALL ON FUNCTION builderad_v2_organization_team(text,uuid),builderad_v2_organization_team_command(text,uuid,uuid,text,uuid,bigint) FROM PUBLIC,builderad_authority');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_v2_organization_team(text,uuid),builderad_v2_organization_team_command(text,uuid,uuid,text,uuid,bigint) TO builderad_app');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Organizational membership and command history require forward recovery.');
    }
};
