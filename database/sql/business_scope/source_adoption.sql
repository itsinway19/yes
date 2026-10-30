-- One admission mutex drains native fact/grant writers before the persistent K1 fence is adopted.
-- It conveys no authority. Native commands still qualify their original signed owner/capability contract.
CREATE FUNCTION builderad_v2_native_source_writable(p_workspace bigint,p_source uuid,p_resource text,p_operation text,p_purpose text) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 -- Presentation eligibility only. Every write still performs its independent current admission/authority checks.
 SELECT session_user='builderad_app' AND public.builderad_source_has('source.read',p_source,p_workspace,p_resource,p_operation,p_purpose)
  AND EXISTS(SELECT 1 FROM public.source_registry s JOIN public.source_capabilities c ON c.source_id=s.id AND c.workspace_id=s.workspace_id
   WHERE s.id=p_source AND s.workspace_id=p_workspace AND c.resource=p_resource AND c.operation=p_operation AND c.purpose=p_purpose)
  AND NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted');
$$;

CREATE FUNCTION builderad_private.native_source_gate_held(p_source uuid) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted
  AND classid=((hashtextextended('builderad.source.admission.'||p_source,0)>>32)&4294967295)::oid
  AND objid=(hashtextextended('builderad.source.admission.'||p_source,0)&4294967295)::oid AND objsubid=1
  AND mode IN('ShareLock','ExclusiveLock'));
$$;

CREATE FUNCTION builderad_v2_native_source_enter(p_workspace uuid,p_source uuid) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF session_user<>'builderad_app' OR p_source IS NULL OR NOT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
  JOIN public.workspaces w ON w.public_id=p_workspace JOIN public.workspace_memberships m ON m.workspace_id=w.id AND m.user_id=l.user_id
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND l.status='current' AND m.status='active')
 OR EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id())
 THEN RAISE EXCEPTION 'Clean native source admission required before selectors' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock_shared(hashtextextended('builderad.source.admission.'||p_source,0));
 PERFORM builderad_private.assert_native_source_write(p_source);
END $$;

-- The original native owner may issue only to their own current identity and the selected exact capability.
-- This is not qualified_policy root issuance for Business, CDB or data-use scopes.
CREATE FUNCTION builderad_private.source_grant_issue(p_session text,p_command uuid,p_source uuid,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;selected_id bigint;proof jsonb;manager public.security_action_grants;g public.security_action_grants;
 f public.resource_right_fences;result jsonb;payload bytea;expiry timestamptz;reason text;
BEGIN
 IF p_expected<>0 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['action','expires_at','reason']::text[]
 OR jsonb_typeof(p_payload->'action') IS DISTINCT FROM 'string' OR p_payload->>'action' NOT IN('source.register','source.read','source.manage','source.audit.recover','provider.connect','provider.read','provider.revoke')
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 OR (p_payload->'expires_at'<>'null'::jsonb AND jsonb_typeof(p_payload->'expires_at') IS DISTINCT FROM 'string')
 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array'
 OR jsonb_array_length(p_selection->'requirements')<>1 OR p_selection#>>'{requirements,0,requirement_kind}' IS DISTINCT FROM 'native_source'
 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact native owner grant issuance required' USING ERRCODE='22023'; END IF;
 expiry:=(p_payload->>'expires_at')::timestamptz;reason:=p_payload->>'reason';
 IF expiry IS NOT NULL AND expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Future native grant expiry required' USING ERRCODE='22023'; END IF;
 selected_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c JOIN public.security_action_grants own ON own.subject_user_id=c.actor_user_id
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND own.id=selected_id AND own.scope_kind='source' AND own.source_target_id=p_source AND own.action='source.manage';
 IF actor IS NULL THEN RAISE EXCEPTION 'Native manager preflight denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 SELECT * INTO STRICT manager FROM public.security_action_grants WHERE id=selected_id;
 IF manager.source_target_id IS DISTINCT FROM p_source OR manager.action IS DISTINCT FROM 'source.manage'
 THEN RAISE EXCEPTION 'Exact current native source manager required after admission wait' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.source_registry s JOIN public.source_capabilities c ON c.source_id=s.id AND c.workspace_id=s.workspace_id
  WHERE s.id=p_source AND s.workspace_id=manager.workspace_id AND c.resource=manager.source_resource AND c.operation=manager.source_operation AND c.purpose=manager.source_purpose)
 THEN RAISE EXCEPTION 'Current exact native capability required' USING ERRCODE='42501'; END IF;
 -- The selected grant is authority; its exact capability is part of the command's
 -- meaning. An existing receipt must not acknowledge a different capability.
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','source','target',p_source,'effect','qualify_issue','payload',p_payload,'expected',p_expected,
  'native_scope',jsonb_build_array(manager.source_resource,manager.source_operation,manager.source_purpose)),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.qualify_issue' AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE scope_kind='source' AND subject_user_id=actor AND subject_generation=manager.subject_generation
  AND workspace_id=manager.workspace_id AND source_target_id=p_source AND source_resource=manager.source_resource AND source_operation=manager.source_operation AND source_purpose=manager.source_purpose AND action=p_payload->>'action' AND status='active')
 THEN RAISE EXCEPTION 'Existing native right must be explicitly selected or revoked' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('dependencies',jsonb_build_array(to_jsonb(f)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.qualify_issue',jsonb_build_object('source_id',p_source),proof);
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=manager.subject_generation;g.granted_by:=actor;
 g.source:='source_owner_request';g.scope_kind:='source';g.workspace_id:=manager.workspace_id;g.source_target_id:=p_source;
 g.source_resource:=manager.source_resource;g.source_operation:=manager.source_operation;g.source_purpose:=manager.source_purpose;g.action:=p_payload->>'action';
 g.reason:=reason;g.status:='active';g.version:=1;g.expires_at:=expiry;g.delegable:=false;g.runtime_lock_marker:=false;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 PERFORM builderad_private.advance_right_epoch(f.fence_id);
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 result:=jsonb_build_object('grant_id',g.id,'status','active','versions',jsonb_build_object('grant',1));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.qualify_issue',payload,result);
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 IF expiry IS NOT NULL AND expiry<=clock_timestamp() THEN RAISE EXCEPTION 'New native grant expired after final wait' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

CREATE FUNCTION builderad_v2_native_source_authority_enter(p_source uuid) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF session_user<>'builderad_authority' OR p_source IS NULL THEN RAISE EXCEPTION 'Native authority connection required' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock_shared(hashtextextended('builderad.source.admission.'||p_source,0));
 PERFORM builderad_private.assert_native_source_write(p_source);
END $$;

CREATE FUNCTION builderad_private.source_adopt(p_session text,p_command uuid,p_source uuid,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;selected_id bigint;proof jsonb;g public.security_action_grants;s public.source_registry;
 f public.resource_right_fences;prior public.resource_right_fences;result jsonb;payload bytea;
BEGIN
 IF p_payload<>'{}'::jsonb OR p_expected<1 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct'
 OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>1
 OR p_selection#>>'{requirements,0,requirement_kind}' IS DISTINCT FROM 'native_source'
 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact native-owner adoption command required' USING ERRCODE='22023'; END IF;
 selected_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 -- Minimal own target preflight; no source existence oracle and no selected authority locks yet.
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c JOIN public.security_action_grants own ON own.subject_user_id=c.actor_user_id
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND own.id=selected_id AND own.scope_kind='source' AND own.source_target_id=p_source AND own.action='source.manage';
 IF actor IS NULL THEN RAISE EXCEPTION 'Native source owner preflight denied' USING ERRCODE='42501'; END IF;
 -- Writers that entered before this exclusive wait must finish first. New legacy versions without admission fail closed.
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 SELECT * INTO prior FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 proof:=builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 SELECT * INTO STRICT g FROM public.security_action_grants WHERE id=selected_id;
 IF g.source_target_id IS DISTINCT FROM p_source OR g.action IS DISTINCT FROM 'source.manage'
 THEN RAISE EXCEPTION 'Exact current native source manager required after admission wait' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.source_registry WHERE id=p_source AND workspace_id=g.workspace_id;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=s.id AND workspace_id=s.workspace_id
  AND resource=g.source_resource AND operation=g.source_operation AND purpose=g.source_purpose)
 THEN RAISE EXCEPTION 'Current declared native source capability required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','source','target',p_source,'effect','adopt','payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.adopt' AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) OR s.version<>p_expected OR prior.adoption_state='v2_adopted'
 THEN RAISE EXCEPTION 'Source adoption or command version conflict' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.adopt',jsonb_build_object('source_id',p_source),proof);
 f:=prior;
 IF prior.fence_id IS NULL THEN
  f.fence_id:=uuidv7();f.kind:='source';f.source_id:=p_source;f.boundary_id:=NULL;f.rights_epoch:=1;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
 ELSE
  f.rights_epoch:=prior.rights_epoch+1;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','update',to_jsonb(prior),to_jsonb(f));
  UPDATE public.resource_right_fences SET rights_epoch=f.rights_epoch,adoption_state=f.adoption_state WHERE fence_id=f.fence_id;
 END IF;
 result:=jsonb_build_object('source_id',p_source,'fence_id',f.fence_id,'adoption_state',f.adoption_state,'versions',jsonb_build_object('source',s.version,'rights_epoch',f.rights_epoch));
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.adopt',payload,result);
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
