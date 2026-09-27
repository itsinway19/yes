<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ownerships', function (Blueprint $table) {
            $table->foreignId('workspace_id')->primary()->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('responsible_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE workspace_ownerships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE workspace_ownerships FORCE ROW LEVEL SECURITY');
        DB::statement('CREATE POLICY workspace_ownerships_owner_all ON workspace_ownerships FOR ALL TO builderad_owner USING (true) WITH CHECK (true)');
        DB::statement(<<<'SQL'
            CREATE POLICY workspace_ownerships_select ON workspace_ownerships FOR SELECT TO builderad_app
            USING (
                current_setting('app.authorization_phase', true) = 'workspace'
                AND workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                AND EXISTS (
                    SELECT 1 FROM workspace_memberships membership
                    WHERE membership.workspace_id = workspace_ownerships.workspace_id
                      AND membership.user_id = NULLIF(current_setting('app.principal_user_id', true), '')::bigint
                      AND membership.status = 'active'
                )
            )
            SQL);
        DB::statement('GRANT SELECT ON workspace_ownerships TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_create_workspace(
                p_actor_user_id bigint,
                p_session_key_hash text,
                p_name text,
                p_slug text
            ) RETURNS uuid
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $function$
            DECLARE
                v_generation bigint;
                v_workspace_id bigint;
                v_public_id uuid;
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    WHERE context.actor_user_id = p_actor_user_id
                      AND context.phase = 'principal' AND context.workspace_id IS NULL
                ) THEN
                    RAISE EXCEPTION 'Signed principal authority required' USING ERRCODE = '42501';
                END IF;
                SELECT lifecycle.generation INTO v_generation
                FROM principal_lifecycles lifecycle
                JOIN users identity ON identity.id = lifecycle.user_id
                WHERE lifecycle.user_id = p_actor_user_id
                  AND lifecycle.principal_class = 'advertiser'
                  AND lifecycle.status = 'current'
                  AND identity.email_verified_at IS NOT NULL
                FOR SHARE OF lifecycle, identity;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current advertiser authority required' USING ERRCODE = '42501';
                END IF;

                PERFORM 1 FROM security_sessions session
                WHERE session.user_id = p_actor_user_id
                  AND session.session_key_hash = p_session_key_hash
                  AND session.lifecycle_generation = v_generation
                  AND session.revoked_at IS NULL
                  AND session.idle_expires_at > clock_timestamp()
                  AND session.absolute_expires_at > clock_timestamp()
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current session required' USING ERRCODE = '42501';
                END IF;

                PERFORM 1 FROM security_action_grants grant_record
                WHERE grant_record.subject_user_id = p_actor_user_id
                  AND grant_record.subject_generation = v_generation
                  AND grant_record.action = 'workspace.create'
                  AND grant_record.scope_kind = 'platform'
                  AND grant_record.workspace_id IS NULL
                  AND grant_record.status = 'active'
                  AND grant_record.revoked_at IS NULL
                  AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Explicit workspace creation grant required' USING ERRCODE = '42501';
                END IF;

                INSERT INTO workspaces (name, slug, status, timezone, created_at, updated_at)
                VALUES (p_name, p_slug, 'active', 'UTC', clock_timestamp(), clock_timestamp())
                ON CONFLICT (slug) DO NOTHING
                RETURNING id, public_id INTO v_workspace_id, v_public_id;

                IF v_workspace_id IS NULL THEN
                    SELECT workspace.public_id INTO v_public_id
                    FROM workspaces workspace
                    JOIN workspace_ownerships ownership ON ownership.workspace_id = workspace.id
                    JOIN workspace_memberships membership
                      ON membership.workspace_id = workspace.id
                     AND membership.user_id = p_actor_user_id
                     AND membership.role = 'owner'
                     AND membership.status = 'active'
                    WHERE workspace.slug = p_slug
                      AND workspace.name = p_name
                      AND workspace.status = 'active'
                      AND ownership.responsible_owner_user_id = p_actor_user_id
                      AND ownership.version >= 1;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Workspace slug unavailable' USING ERRCODE = '23505';
                    END IF;
                    PERFORM 1 FROM security_action_grants view_grant
                    JOIN workspaces workspace ON workspace.id = view_grant.workspace_id
                    WHERE workspace.slug = p_slug
                      AND view_grant.subject_user_id = p_actor_user_id
                      AND view_grant.subject_generation = v_generation
                      AND view_grant.action = 'workspace.view'
                      AND view_grant.scope_kind = 'workspace'
                      AND view_grant.status = 'active'
                      AND view_grant.revoked_at IS NULL
                      AND (view_grant.expires_at IS NULL OR view_grant.expires_at > clock_timestamp())
                    FOR SHARE OF view_grant;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Workspace view grant required' USING ERRCODE = '42501';
                    END IF;
                    RETURN v_public_id;
                END IF;

                INSERT INTO workspace_memberships (workspace_id, user_id, role, status, created_at)
                VALUES (v_workspace_id, p_actor_user_id, 'owner', 'active', clock_timestamp());

                INSERT INTO workspace_ownerships (workspace_id, responsible_owner_user_id, version, created_at, updated_at)
                VALUES (v_workspace_id, p_actor_user_id, 1, clock_timestamp(), clock_timestamp());

                INSERT INTO security_action_grants
                    (subject_user_id, granted_by, source, action, scope_kind, workspace_id,
                     subject_generation, reason, status, version, created_at, updated_at)
                SELECT p_actor_user_id, NULL, 'system_workspace_creation', granted_action.action,
                       'workspace', v_workspace_id, v_generation, 'initial_responsible_owner',
                       'active', 1, clock_timestamp(), clock_timestamp()
                FROM (VALUES ('workspace.view'), ('service.scope')) AS granted_action(action);

                INSERT INTO security_audit_events
                    (actor_user_id, subject_user_id, workspace_id, event, reason, prior_status, new_status,
                     prior_generation, new_generation, occurred_at)
                SELECT p_actor_user_id, p_actor_user_id, v_workspace_id,
                       CASE WHEN granted_action.action = 'workspace.view'
                           THEN 'workspace_view_granted' ELSE 'service_scope_granted' END,
                       'initial_responsible_owner', NULL, 'active', NULL, v_generation, clock_timestamp()
                FROM (VALUES ('workspace.view'), ('service.scope')) AS granted_action(action);

                INSERT INTO security_audit_events
                    (actor_user_id, subject_user_id, workspace_id, event, reason, prior_status, new_status,
                     prior_generation, new_generation, occurred_at)
                VALUES (p_actor_user_id, p_actor_user_id, v_workspace_id, 'workspace_created', 'explicit_workspace_create_grant',
                        NULL, 'active', NULL, v_generation, clock_timestamp());

                RETURN v_public_id;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_create_workspace(bigint, text, text, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_create_workspace(bigint, text, text, text) TO builderad_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS builderad_create_workspace(bigint, text, text, text)');
        Schema::dropIfExists('workspace_ownerships');
    }
};
