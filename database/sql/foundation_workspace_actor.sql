-- Neutral Foundation authority predicate; Source retains its own signed context.
CREATE OR REPLACE FUNCTION builderad_current_workspace_actor(p_workspace bigint,p_session text,p_owner boolean,p_fresh boolean)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint; g bigint;
BEGIN
 IF session_user <> 'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id,l.generation INTO a,g FROM builderad_signed_context() c
 JOIN principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='advertiser'
 WHERE c.phase='workspace' AND c.workspace_id=p_workspace;
 IF a IS NULL THEN RAISE EXCEPTION 'Signed current Workspace required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM principal_lifecycles WHERE user_id=a FOR SHARE;
 PERFORM 1 FROM users WHERE id=a FOR SHARE;
 PERFORM 1 FROM workspaces WHERE id=p_workspace FOR SHARE;
 PERFORM 1 FROM workspace_memberships WHERE workspace_id=p_workspace FOR SHARE;
 PERFORM 1 FROM workspace_ownerships WHERE workspace_id=p_workspace FOR SHARE;
 PERFORM 1 FROM security_sessions WHERE user_id=a AND session_key_hash=p_session FOR SHARE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=a AND workspace_id=p_workspace AND action='workspace.view' FOR SHARE;
 IF NOT EXISTS (
  SELECT 1 FROM principal_lifecycles l JOIN users u ON u.id=l.user_id
  JOIN security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  JOIN workspace_memberships m ON m.user_id=l.user_id AND m.workspace_id=p_workspace AND m.status='active'
  JOIN workspaces w ON w.id=m.workspace_id AND w.status='active'
  JOIN workspace_ownerships o ON o.workspace_id=w.id
  JOIN workspace_memberships om ON om.workspace_id=w.id AND om.user_id=o.responsible_owner_user_id AND om.role='owner' AND om.status='active'
  JOIN principal_lifecycles ol ON ol.user_id=om.user_id AND ol.status='current' AND ol.principal_class='advertiser'
  JOIN users ou ON ou.id=ol.user_id AND ou.email_verified_at IS NOT NULL
  JOIN security_action_grants v ON v.subject_user_id=l.user_id AND v.subject_generation=l.generation
   AND v.workspace_id=w.id AND v.scope_kind='workspace' AND v.action='workspace.view'
  WHERE l.user_id=a AND l.generation=g AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL
   AND s.session_key_hash=p_session AND s.revoked_at IS NULL AND s.auth_method <> 'internal_passkey'
   AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
   AND (NOT p_fresh OR s.authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
   AND (NOT p_owner OR o.responsible_owner_user_id=a) AND o.version>0
   AND v.status='active' AND v.revoked_at IS NULL AND (v.expires_at IS NULL OR v.expires_at>clock_timestamp())
 ) THEN RAISE EXCEPTION 'Current Workspace actor denied' USING ERRCODE='42501'; END IF;
  RETURN a;
END $$;
REVOKE ALL ON FUNCTION builderad_current_workspace_actor(bigint,text,boolean,boolean) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_source_actor(p_workspace bigint,p_session text,p_owner boolean,p_fresh boolean)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint;
BEGIN
 a:=builderad_current_workspace_actor(p_workspace,p_session,p_owner,p_fresh);
-- Bind source RLS to this verified server session and the existing signed transaction.
 PERFORM set_config('app.source_session',p_session,true);
 PERFORM set_config('app.source_session_signature',
  (SELECT encode(hmac(convert_to(current_setting('app.signed_context')||'|'||p_session,'UTF8'),secret,'sha256'),'hex')
   FROM builderad_context_secret WHERE singleton),true);
 RETURN a;
END $$;
REVOKE ALL ON FUNCTION builderad_source_actor(bigint,text,boolean,boolean) FROM PUBLIC,builderad_app,builderad_authority;

