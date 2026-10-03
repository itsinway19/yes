-- Explicit Owner reassessment after a proven administrative suspension cycle.
-- No old session or grant is revived automatically. Arbitrary generation gaps remain denied.
CREATE OR REPLACE FUNCTION builderad_customer_owner_continuity(p_user bigint,p_generation bigint)
RETURNS TABLE(prior_generation bigint,occurred_at timestamptz)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE cursor_generation bigint:=p_generation; latest_restore timestamptz; restore customer_administration_events%ROWTYPE; suspension customer_administration_events%ROWTYPE;
BEGIN
 LOOP
  SELECT * INTO restore FROM customer_administration_events WHERE subject_user_id=p_user
   AND decision='reactivate' AND new_status='current' AND new_revision=cursor_generation;
  EXIT WHEN NOT FOUND;
  SELECT * INTO suspension FROM customer_administration_events WHERE subject_user_id=p_user
   AND decision='suspend' AND prior_status='current' AND new_status='disabled'
   AND new_revision=restore.expected_revision AND expected_revision=new_revision-1;
  EXIT WHEN NOT FOUND OR restore.new_revision<>restore.expected_revision+1;
  latest_restore:=coalesce(latest_restore,restore.occurred_at);
  cursor_generation:=suspension.expected_revision;
 END LOOP;
 IF latest_restore IS NOT NULL THEN
  -- SessionAuthority records authentication with second precision. The current
  -- generation and revoked-session fences still exclude every pre-restore session.
  RETURN QUERY SELECT cursor_generation,date_trunc('second',latest_restore);
 ELSE
  RETURN QUERY SELECT p_generation-1,e.occurred_at FROM security_audit_events e
   WHERE e.subject_user_id=p_user AND e.event='primary_email_changed' AND e.prior_generation<p_generation
   ORDER BY e.occurred_at DESC LIMIT 1;
 END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_customer_owner_continuity(bigint,bigint) FROM PUBLIC,builderad_app,builderad_authority;
CREATE OR REPLACE FUNCTION builderad_owner_reassessment_evidence(
                p_user_id bigint, p_session_key_hash text, p_workspace_public_id uuid
            ) RETURNS TABLE (workspace_id bigint, prior_generation bigint, view_expires_at timestamptz,
                             restore_service_scope boolean, scope_expires_at timestamptz)
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_generation bigint;
                v_prior_generation bigint;
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

                SELECT continuity.prior_generation, continuity.occurred_at INTO v_prior_generation, v_change_at FROM builderad_customer_owner_continuity(p_user_id,v_generation) continuity;
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
                  AND grant_record.subject_generation = v_prior_generation
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
                  AND grant_record.subject_generation = v_prior_generation
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
            $function$;

CREATE OR REPLACE FUNCTION builderad_owner_reassessment_candidates(p_user_id bigint, p_session_key_hash text)
            RETURNS TABLE (public_id uuid, name varchar, slug varchar)
            LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            BEGIN
                RETURN QUERY SELECT DISTINCT workspace.public_id, workspace.name, workspace.slug
                FROM principal_lifecycles lifecycle
                JOIN users identity ON identity.id = lifecycle.user_id
                JOIN security_sessions session ON session.user_id = lifecycle.user_id
                JOIN LATERAL builderad_customer_owner_continuity(lifecycle.user_id,lifecycle.generation) email_change ON true
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
                      AND grant_record.subject_generation = email_change.prior_generation
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
            $function$;
