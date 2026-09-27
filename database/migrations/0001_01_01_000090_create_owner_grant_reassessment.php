<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS builderad_owner_reassessment_candidates(bigint, text)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_owner_reassessment_evidence(bigint, text, uuid)');
        DB::statement('ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_source_check');
        DB::statement(<<<'SQL'
            ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_source_check CHECK (
                (source = 'system_registration' AND granted_by IS NULL AND action = 'workspace.create' AND scope_kind = 'platform')
                OR (source = 'system_workspace_creation' AND granted_by IS NULL AND action IN ('workspace.view', 'service.scope') AND scope_kind = 'workspace')
                OR (source = 'system_owner_reassessment' AND granted_by IS NULL AND action IN ('workspace.view', 'service.scope') AND scope_kind = 'workspace')
                OR (source = 'founder_bootstrap' AND granted_by IS NULL AND scope_kind = 'platform')
                OR (source = 'human' AND granted_by IS NOT NULL AND granted_by <> subject_user_id)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION builderad_owner_reassessment_evidence(
                p_user_id bigint, p_session_key_hash text, p_workspace_public_id uuid
            ) RETURNS TABLE (workspace_id bigint, prior_generation bigint, view_expires_at timestamptz,
                             restore_service_scope boolean, scope_expires_at timestamptz)
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_generation bigint;
                v_change_at timestamptz;
                v_workspace_id bigint;
                v_view_generation bigint;
                v_view_expires_at timestamptz;
                v_scope_generation bigint;
                v_scope_expires_at timestamptz;
                v_restore_scope boolean := false;
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    WHERE context.actor_user_id = p_user_id
                      AND context.phase = 'principal' AND context.workspace_id IS NULL
                ) THEN
                    RAISE EXCEPTION 'Signed principal authority required' USING ERRCODE = '42501';
                END IF;
                SELECT lifecycle.generation INTO v_generation
                FROM principal_lifecycles lifecycle
                JOIN users identity ON identity.id = lifecycle.user_id
                WHERE lifecycle.user_id = p_user_id
                  AND lifecycle.principal_class = 'advertiser'
                  AND lifecycle.status = 'current'
                  AND lifecycle.cause IS NULL
                  AND lifecycle.generation > 1
                  AND identity.email_verified_at IS NOT NULL
                FOR SHARE OF lifecycle, identity;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current verified advertiser required' USING ERRCODE = '42501';
                END IF;

                SELECT email_change.occurred_at INTO v_change_at
                FROM security_audit_events email_change
                WHERE email_change.subject_user_id = p_user_id
                  AND email_change.event = 'primary_email_changed'
                  AND email_change.prior_generation < v_generation
                ORDER BY email_change.occurred_at DESC LIMIT 1;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Verified account continuity required' USING ERRCODE = '42501';
                END IF;

                PERFORM 1 FROM security_sessions session
                WHERE session.user_id = p_user_id
                  AND session.session_key_hash = p_session_key_hash
                  AND session.lifecycle_generation = v_generation
                  AND session.auth_method IN ('password', 'passkey')
                  AND session.revoked_at IS NULL
                  AND session.idle_expires_at > clock_timestamp()
                  AND session.absolute_expires_at > clock_timestamp()
                  AND session.authenticated_at >= v_change_at
                  AND session.authenticated_at >= clock_timestamp() - interval '15 minutes'
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Fresh post-change authentication required' USING ERRCODE = '42501';
                END IF;

                SELECT id INTO v_workspace_id FROM workspaces
                WHERE public_id = p_workspace_public_id AND status = 'active' FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Active Workspace required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspace_ownerships
                WHERE workspace_ownerships.workspace_id = v_workspace_id
                  AND responsible_owner_user_id = p_user_id AND version >= 1 FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current responsible Owner required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspace_memberships
                WHERE workspace_memberships.workspace_id = v_workspace_id
                  AND user_id = p_user_id AND role = 'owner' AND status = 'active' FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current Owner membership required' USING ERRCODE = '42501';
                END IF;

                SELECT subject_generation, expires_at INTO v_view_generation, v_view_expires_at
                FROM security_action_grants grant_record
                WHERE grant_record.subject_user_id = p_user_id
                  AND grant_record.workspace_id = v_workspace_id
                  AND grant_record.action = 'workspace.view'
                  AND grant_record.scope_kind = 'workspace'
                  AND grant_record.subject_generation = v_generation - 1
                  AND grant_record.status = 'active' AND grant_record.revoked_at IS NULL
                  AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                ORDER BY subject_generation DESC LIMIT 1 FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Previously valid Owner grant required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM security_action_grants revoked_grant
                WHERE revoked_grant.subject_user_id = p_user_id
                  AND revoked_grant.workspace_id = v_workspace_id
                  AND revoked_grant.action = 'workspace.view'
                  AND revoked_grant.subject_generation >= v_view_generation
                  AND revoked_grant.status = 'revoked' FOR SHARE;
                IF FOUND THEN
                    RAISE EXCEPTION 'Revoked Workspace grant cannot be restored' USING ERRCODE = '42501';
                END IF;

                SELECT subject_generation, expires_at INTO v_scope_generation, v_scope_expires_at
                FROM security_action_grants grant_record
                WHERE grant_record.subject_user_id = p_user_id
                  AND grant_record.workspace_id = v_workspace_id
                  AND grant_record.action = 'service.scope'
                  AND grant_record.scope_kind = 'workspace'
                  AND grant_record.subject_generation = v_generation - 1
                  AND grant_record.status = 'active' AND grant_record.revoked_at IS NULL
                  AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                ORDER BY subject_generation DESC LIMIT 1 FOR SHARE;
                IF FOUND THEN
                    SELECT NOT EXISTS (
                        SELECT 1 FROM security_action_grants revoked_grant
                        WHERE revoked_grant.subject_user_id = p_user_id
                          AND revoked_grant.workspace_id = v_workspace_id
                          AND revoked_grant.action = 'service.scope'
                          AND revoked_grant.subject_generation >= v_scope_generation
                          AND revoked_grant.status = 'revoked'
                    ) INTO v_restore_scope;
                END IF;

                RETURN QUERY SELECT v_workspace_id, v_view_generation, v_view_expires_at,
                                    v_restore_scope, v_scope_expires_at;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_owner_reassessment_evidence(bigint, text, uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_owner_reassessment_evidence(bigint, text, uuid) TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE FUNCTION builderad_owner_reassessment_candidates(p_user_id bigint, p_session_key_hash text)
            RETURNS TABLE (public_id uuid, name varchar, slug varchar)
            LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            BEGIN
                RETURN QUERY SELECT DISTINCT workspace.public_id, workspace.name, workspace.slug
                FROM principal_lifecycles lifecycle
                JOIN users identity ON identity.id = lifecycle.user_id
                JOIN security_sessions session ON session.user_id = lifecycle.user_id
                JOIN LATERAL (
                    SELECT occurred_at FROM security_audit_events
                    WHERE subject_user_id = lifecycle.user_id
                      AND event = 'primary_email_changed'
                      AND prior_generation < lifecycle.generation
                    ORDER BY occurred_at DESC LIMIT 1
                ) email_change ON true
                JOIN workspace_ownerships ownership ON ownership.responsible_owner_user_id = lifecycle.user_id
                JOIN workspace_memberships membership ON membership.workspace_id = ownership.workspace_id
                  AND membership.user_id = lifecycle.user_id AND membership.role = 'owner'
                  AND membership.status = 'active'
                JOIN workspaces workspace ON workspace.id = ownership.workspace_id
                JOIN LATERAL (
                    SELECT subject_generation FROM security_action_grants grant_record
                    WHERE grant_record.subject_user_id = lifecycle.user_id
                      AND grant_record.workspace_id = workspace.id
                      AND grant_record.action = 'workspace.view'
                      AND grant_record.scope_kind = 'workspace'
                      AND grant_record.subject_generation = lifecycle.generation - 1
                      AND grant_record.status = 'active' AND grant_record.revoked_at IS NULL
                      AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                    ORDER BY subject_generation DESC LIMIT 1
                ) prior_grant ON true
                WHERE lifecycle.user_id = p_user_id
                  AND EXISTS (
                      SELECT 1 FROM builderad_signed_context() context
                      WHERE context.actor_user_id = p_user_id
                        AND context.phase = 'principal' AND context.workspace_id IS NULL
                  )
                  AND lifecycle.principal_class = 'advertiser'
                  AND lifecycle.status = 'current' AND lifecycle.cause IS NULL
                  AND identity.email_verified_at IS NOT NULL
                  AND session.session_key_hash = p_session_key_hash
                  AND session.lifecycle_generation = lifecycle.generation
                  AND session.auth_method IN ('password', 'passkey')
                  AND session.revoked_at IS NULL
                  AND session.idle_expires_at > clock_timestamp()
                  AND session.absolute_expires_at > clock_timestamp()
                  AND session.authenticated_at >= email_change.occurred_at
                  AND session.authenticated_at >= clock_timestamp() - interval '15 minutes'
                  AND workspace.status = 'active' AND ownership.version >= 1
                  AND NOT EXISTS (
                      SELECT 1 FROM security_action_grants revoked_grant
                      WHERE revoked_grant.subject_user_id = lifecycle.user_id
                        AND revoked_grant.workspace_id = workspace.id
                        AND revoked_grant.action = 'workspace.view'
                        AND revoked_grant.subject_generation >= prior_grant.subject_generation
                        AND revoked_grant.status = 'revoked'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM security_action_grants current_grant
                      WHERE current_grant.subject_user_id = lifecycle.user_id
                        AND current_grant.workspace_id = workspace.id
                        AND current_grant.action = 'workspace.view'
                        AND current_grant.subject_generation = lifecycle.generation
                      AND current_grant.status = 'active'
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM security_action_grants current_grant
                      WHERE current_grant.subject_user_id = lifecycle.user_id
                        AND current_grant.workspace_id = workspace.id
                        AND current_grant.action = 'workspace.view'
                        AND current_grant.subject_generation = lifecycle.generation
                        AND current_grant.status = 'revoked'
                  );
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_owner_reassessment_candidates(bigint, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_owner_reassessment_candidates(bigint, text) TO builderad_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS builderad_owner_reassessment_candidates(bigint, text)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_owner_reassessment_evidence(bigint, text, uuid)');
        DB::statement('ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_source_check');
        DB::statement(<<<'SQL'
            ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_source_check CHECK (
                (source = 'system_registration' AND granted_by IS NULL AND action = 'workspace.create' AND scope_kind = 'platform')
                OR (source = 'system_workspace_creation' AND granted_by IS NULL AND action IN ('workspace.view', 'service.scope') AND scope_kind = 'workspace')
                OR (source = 'founder_bootstrap' AND granted_by IS NULL AND scope_kind = 'platform')
                OR (source = 'human' AND granted_by IS NOT NULL AND granted_by <> subject_user_id)
            )
            SQL);
    }
};
