-- Business Context mutations serialize BEFORE the canonical selector's SHARE locks.
-- This entry is deliberately local to writers sharing business_context_version.
CREATE FUNCTION builderad_business_context_begin(p_workspace bigint,p_session text)
RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint; prepared text;
BEGIN
 IF session_user <> 'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM builderad_signed_context() c
  WHERE c.phase='principal' AND c.workspace_id IS NULL;
 -- Minimum non-mutating signed scope proof, not an alternative authority resolver.
 IF actor IS NULL OR NOT EXISTS (SELECT 1 FROM workspace_memberships
   WHERE user_id=actor AND workspace_id=p_workspace AND status='active') THEN
  RAISE EXCEPTION 'Signed member scope required before mutation serialization' USING ERRCODE='42501';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.context.'||p_workspace::text,0));
 -- All authority is acquired/re-evaluated AFTER the serialization wait.
 PERFORM builderad_select_workspace_context(p_workspace);
 PERFORM builderad_source_actor(p_workspace,p_session,false,false);
 SELECT encode(hmac(convert_to('business-context|'||current_setting('app.signed_context'),'UTF8'),secret,'sha256'),'hex')
  INTO prepared FROM builderad_context_secret WHERE singleton;
 PERFORM set_config('app.business_context_prepared',prepared,true);
END $$;
REVOKE ALL ON FUNCTION builderad_business_context_begin(bigint,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_business_context_begin(bigint,text) TO builderad_app;

CREATE FUNCTION builderad_business_context_require_prepared(p_workspace bigint)
RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE expected text;
BEGIN
 IF session_user <> 'builderad_app' OR NOT EXISTS (SELECT 1 FROM builderad_signed_context() c
  WHERE c.phase='workspace' AND c.workspace_id=p_workspace) THEN
  RAISE EXCEPTION 'Signed Workspace required' USING ERRCODE='42501';
 END IF;
 SELECT encode(hmac(convert_to('business-context|'||current_setting('app.signed_context'),'UTF8'),secret,'sha256'),'hex')
  INTO expected FROM builderad_context_secret WHERE singleton;
 IF expected IS NULL OR expected IS DISTINCT FROM current_setting('app.business_context_prepared',true) THEN
  RAISE EXCEPTION 'Serialized Business Context entry required' USING ERRCODE='42501';
 END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_business_context_require_prepared(bigint) FROM PUBLIC,builderad_app,builderad_authority;
CREATE OR REPLACE FUNCTION builderad_business_context_write(p_workspace bigint,p_session text,p_type bigint,p_name text,p_version bigint)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint; current_workspace workspaces; type_active boolean;
BEGIN
 PERFORM builderad_business_context_require_prepared(p_workspace);
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 SELECT * INTO current_workspace FROM workspaces WHERE id=p_workspace FOR UPDATE;
 SELECT active INTO type_active FROM business_types WHERE id=p_type FOR SHARE;
 IF NOT FOUND OR (NOT type_active AND current_workspace.business_type_id IS DISTINCT FROM p_type) THEN
  RAISE EXCEPTION 'Selectable business type required' USING ERRCODE='22023';
 END IF;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=actor AND workspace_id=p_workspace
  AND action='business.context.manage' AND scope_kind='workspace' FOR SHARE;
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 IF NOT EXISTS (SELECT 1 FROM security_action_grants g JOIN principal_lifecycles l ON l.user_id=g.subject_user_id
  WHERE g.subject_user_id=actor AND g.subject_generation=l.generation AND g.workspace_id=p_workspace
  AND g.action='business.context.manage' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Explicit business context mutation grant required' USING ERRCODE='42501';
 END IF;
 IF length(trim(p_name)) NOT BETWEEN 2 AND 120 THEN RAISE EXCEPTION 'Valid name required' USING ERRCODE='22023'; END IF;
 IF current_workspace.business_type_id=p_type AND current_workspace.name=p_name THEN
  RETURN current_workspace.business_context_version;
 END IF;
 IF current_workspace.business_context_version<>p_version THEN
  RAISE EXCEPTION 'Business context changed; reload before retry' USING ERRCODE='40001';
 END IF;
 UPDATE workspaces SET business_type_id=p_type,name=p_name,business_context_version=p_version+1,updated_at=clock_timestamp()
  WHERE id=p_workspace;
 INSERT INTO business_context_events(workspace_id,actor_user_id,prior_type_id,new_type_id,prior_name,new_name,version)
 VALUES(p_workspace,actor,current_workspace.business_type_id,p_type,current_workspace.name,p_name,p_version+1);
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 IF NOT EXISTS (SELECT 1 FROM security_action_grants g JOIN principal_lifecycles l ON l.user_id=g.subject_user_id
  WHERE g.subject_user_id=actor AND g.subject_generation=l.generation AND g.workspace_id=p_workspace
  AND g.action='business.context.manage' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Business context authority expired before effect' USING ERRCODE='42501';
 END IF;
 RETURN p_version+1;
END $$;
REVOKE ALL ON FUNCTION builderad_business_context_write(bigint,text,bigint,text,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_business_context_write(bigint,text,bigint,text,bigint) TO builderad_app;
