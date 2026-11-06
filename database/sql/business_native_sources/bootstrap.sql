-- Explicit Connect/Select action by a fresh original creator. The existing
-- Source writer, qualifications, permits and Business receipts commit atomically.
CREATE FUNCTION public.builderad_v2_business_source_bootstrap(p_session text,p_business uuid,p_command uuid,p_source uuid,p_payload jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;generation bigint;proof jsonb;payload bytea;result jsonb;native_result jsonb;
 basis public.security_action_grants;basis_q public.authority_qualification_receipts;q public.authority_qualification_receipts;
 g public.security_action_grants;old_g public.security_action_grants;s public.source_registry;f public.resource_right_fences;
 actions text[];action_name text;grant_refs jsonb:='{}';issued_qualification boolean:=false;changed boolean:=false;
BEGIN
 IF p_command IS NULL OR p_source IS NULL OR uuid_extract_version(p_source)<>7
 THEN RAISE EXCEPTION 'Exact server-bound Source bootstrap identity required' USING ERRCODE='22023'; END IF;
 p_payload:=builderad_private.native_source_payload('register',p_payload);
 IF coalesce(p_payload->>'family','') NOT IN('meta','google','tiktok','snapchat','x')
 OR p_payload->>'operation' NOT IN('discovery.read','report.read') OR p_payload->>'purpose' IS DISTINCT FROM 'advertising_analysis'
 OR(p_payload->>'expected_version')::bigint<>0 OR p_payload->>'resource' IS DISTINCT FROM p_payload->>'external_id'
 OR p_payload->>'namespace' IS DISTINCT FROM (CASE WHEN p_payload->>'operation'='report.read' THEN 'ad_account'
  ELSE CASE p_payload->>'family' WHEN 'meta' THEN 'facebook_login_configuration' WHEN 'google' THEN 'oauth_client'
   WHEN 'tiktok' THEN 'developer_app' WHEN 'snapchat' THEN 'developer_app' WHEN 'x' THEN 'developer_app' END END)
 THEN RAISE EXCEPTION 'Implemented native connection scope required' USING ERRCODE='22023'; END IF;
 actions:=CASE WHEN p_payload->>'operation'='discovery.read' THEN ARRAY['source.register','source.read','source.audit.recover','provider.connect']
  ELSE ARRAY['source.register','source.read','source.manage','source.audit.recover','provider.connect','provider.read','provider.revoke'] END;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'source_id',p_source,'payload',p_payload),'{"business":"U","source_id":"U"}');
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.source.command.'||p_command,0));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 IF FOUND AND f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'Protected Business Source custody required' USING ERRCODE='42501'; END IF;
 b:=builderad_private.lock_business(p_business,false);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business_source_creator(b,actor,p_session,p_selection,true);
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 SELECT * INTO STRICT basis_q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 SELECT l.generation INTO STRICT generation FROM public.principal_lifecycles l WHERE user_id=actor;
 result:=builderad_private.command_replay(b,actor,p_command,'source.business.bootstrap',payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'source.business.bootstrap',jsonb_build_object('business_id',b,'source_id',p_source),proof);
 SELECT * INTO s FROM public.source_registry WHERE id=p_source;
 IF FOUND THEN
  IF s.custody_kind<>'business_native' OR s.business_id IS DISTINCT FROM b OR s.workspace_id IS NOT NULL OR s.status='REVOKED'
   OR s.family IS DISTINCT FROM p_payload->>'family' OR s.namespace IS DISTINCT FROM p_payload->>'namespace'
   OR s.declared_external_id IS DISTINCT FROM p_payload->>'external_id'
   OR NOT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=s.id AND workspace_id IS NULL
    AND resource=p_payload->>'resource' AND operation=p_payload->>'operation' AND purpose=p_payload->>'purpose')
  THEN RAISE EXCEPTION 'Exact active Business-native connection identity required' USING ERRCODE='42501'; END IF;
 ELSE
  native_result:=builderad_private.apply_native_source(actor,generation,NULL,p_command,p_source,'register',p_payload);
  SELECT * INTO STRICT s FROM public.source_registry WHERE id=p_source;
  f.fence_id:=uuidv7();f.kind:='source';f.source_id:=p_source;f.boundary_id:=NULL;f.data_use_scope_id:=NULL;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));
  INSERT INTO public.resource_right_fences SELECT(f).*;
  changed:=true;
 END IF;
 FOREACH action_name IN ARRAY actions LOOP
  SELECT * INTO old_g FROM public.security_action_grants
   WHERE scope_kind='source' AND workspace_id IS NULL AND source_target_id=p_source AND subject_user_id=actor AND subject_generation=generation
    AND source_resource=p_payload->>'resource' AND source_operation=p_payload->>'operation' AND source_purpose=p_payload->>'purpose'
    AND action=action_name AND status='active';
  IF FOUND AND builderad_private.business_source_grant_current(old_g.id,actor,p_session,true) THEN
   grant_refs:=grant_refs||jsonb_build_object(action_name,old_g.id::text);CONTINUE;
  END IF;
  IF old_g.id IS NOT NULL THEN
   g:=old_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
   PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
   UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
  END IF;
  IF NOT issued_qualification THEN
   q.qualification_id:=uuidv7();q.policy_id:='business_native_source_custody_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=basis.id;
   q.subject_user_id:=actor;q.target_kind:='source';q.target_ref:=p_source;q.allowed_actions:=actions;
   q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis_q.valid_until;
   q.provenance:='Explicit fresh original-creator Source custody; no external ownership, licence, data use or additional Business/Workspace authority';
   PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));
   INSERT INTO public.authority_qualification_receipts SELECT(q).*;issued_qualification:=true;
  END IF;
  g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=generation;
  g.granted_by:=actor;g.source:='source_owner_request';g.scope_kind:='source';g.business_id:=b;g.qualification_id:=q.qualification_id;
  g.source_target_id:=p_source;g.source_resource:=p_payload->>'resource';g.source_operation:=p_payload->>'operation';g.source_purpose:=p_payload->>'purpose';g.action:=action_name;
  g.reason:='Explicit Business-native Source connection';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.delegable:=false;g.expires_at:=basis.expires_at;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  grant_refs:=grant_refs||jsonb_build_object(action_name,g.id::text);changed:=true;
 END LOOP;
 IF changed THEN PERFORM builderad_private.advance_right_epoch(f.fence_id); END IF;
 result:=jsonb_build_object('business_public_id',p_business,'source_id',p_source,'custody_kind','business_native','registration_state',s.status,
  'grant_refs',grant_refs,'native_receipt',native_result,'versions',jsonb_build_object('source',s.version),'establishes_business_data_use',false);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'source.business.bootstrap',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

-- Admission for the Business's native connection surface. This returns only a
-- typed locator and its original basis. Source actions still qualify separately.
CREATE FUNCTION public.builderad_v2_business_source_owner(p_session text,p_business uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;selected bigint;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 -- Locator preflight only: taking Business/K4 locks here would precede the
 -- Source K1 fence in the actual operation. Each Source action qualifies and
 -- locks its complete authority again in the established order.
 SELECT business.id,g.id INTO b,selected FROM public.businesses business
 JOIN public.migration_cohort_members member ON member.business_id=business.id AND member.kind='business'
 JOIN public.migration_cohort_control control ON control.cohort_id=member.cohort_id AND control.mode='V2_ACTIVE'
 JOIN public.security_action_grants g ON g.business_id=business.id
 JOIN public.principal_lifecycles lifecycle ON lifecycle.user_id=actor AND lifecycle.generation=g.subject_generation
 JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE business.public_id=p_business AND business.created_by=actor AND business.origin_kind='self_declared_draft'
  AND business.lifecycle IN('declared_draft','active') AND g.scope_kind='business' AND g.subject_user_id=actor
  AND g.action='business.context.read' AND g.source='business_draft_creation' AND g.parent_grant_id IS NULL AND NOT g.delegable
  AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL
  AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
  AND q.policy_id='self_declared_draft' AND q.policy_version=1 AND q.issuer_kind='self_declared_draft'
  AND q.subject_user_id=actor AND q.target_kind='business' AND q.target_ref=p_business AND g.action=ANY(q.allowed_actions)
  AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp());
 IF selected IS NULL THEN RAISE EXCEPTION 'Exact native Source administration basis required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('business_id',b,'business_public_id',p_business,'read_grant_ref',selected);
END $$;
