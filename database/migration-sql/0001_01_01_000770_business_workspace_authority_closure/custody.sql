-- Explicit Business-scoped file custody over the existing qualification/grant graph.
-- A custodian administers only this file's five current-declaration actions.
CREATE FUNCTION builderad_private.business_file_custody_command(p_session text,p_business uuid,p_command uuid,p_effect text,p_recipient uuid,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;recipient bigint;proof jsonb;footprint jsonb;payload bytea;result jsonb;selected public.security_action_grants;
 basis public.authority_qualification_receipts;old_basis public.authority_qualification_receipts;q public.authority_qualification_receipts;g public.security_action_grants;
 roots bigint[];qualifications uuid[];subjects bigint[];creator_basis boolean;custodian_basis boolean;authenticated timestamptz;epoch bigint;refs jsonb:='{}';action_name text;
BEGIN
 IF p_command IS NULL OR p_expected IS NULL OR p_expected<0 OR p_effect IS NULL OR p_effect NOT IN('enroll','appoint','remove','end')
  OR(p_effect='end' AND p_recipient IS NOT NULL) OR(p_effect<>'end' AND p_recipient IS NULL)
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1
 THEN RAISE EXCEPTION 'Exact Business file custody command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Independent Business custody authority required' USING ERRCODE='42501';END IF;
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF p_effect<>'end' AND recipient IS NULL THEN RAISE EXCEPTION 'Named registered custodian required' USING ERRCODE='42501';END IF;
 -- Before-images are locks, not live recipient prerequisites: disabled or expired
 -- custodians remain removable. All K4 targets are collected before requiring scope.
 SELECT coalesce(array_agg(id),'{}'),coalesce(array_agg(qualification_id),'{}'),coalesce(array_agg(subject_user_id),'{}') INTO roots,qualifications,subjects
 FROM public.security_action_grants WHERE scope_kind='business' AND business_id=b AND qualification_id IN(
  SELECT qualification_id FROM public.authority_qualification_receipts WHERE policy_id='internal_client_file_admin_v1' AND policy_version=2
   AND target_kind='business' AND target_ref=p_business AND evidence_ref='business-custody:'||p_business::text AND status='active');
 IF recipient IS NOT NULL THEN subjects:=array_append(subjects,recipient);END IF;
 footprint:=builderad_private.authority_footprint(actor,p_selection,subjects);
 footprint:=jsonb_set(footprint,'{grants}',(footprint->'grants')||to_jsonb(roots));
 footprint:=jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||to_jsonb(qualifications));
 PERFORM builderad_private.lock_authority_rows(actor,p_session,footprint);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 SELECT * INTO STRICT selected FROM public.security_action_grants WHERE id=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 creator_basis:=proof#>>'{qualification,policy_id}'='self_declared_draft' AND proof#>>'{qualification,issuer_kind}'='self_declared_draft'
  AND selected.parent_grant_id IS NULL AND actor=(SELECT created_by FROM public.businesses WHERE id=b);
 custodian_basis:=proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND proof#>>'{qualification,policy_version}'='2'
  AND proof#>>'{qualification,issuer_kind}'='qualified_policy' AND proof#>>'{qualification,evidence_ref}'='business-custody:'||p_business::text
  AND selected.parent_grant_id IS NULL AND selected.delegable;
 IF NOT(creator_basis OR custodian_basis) THEN RAISE EXCEPTION 'Independent creator or explicit Business file custodian required' USING ERRCODE='42501';END IF;
 PERFORM builderad_private.principal(p_session);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'workspace',NULL,'effect',p_effect,'recipient',p_recipient,'expected',p_expected),
  '{"business":"U","workspace":"U","recipient":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.internal_team.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit custody confirmation required' USING ERRCODE='42501';END IF;
 IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Current file required' USING ERRCODE='42501';END IF;
 SELECT auth_epoch INTO epoch FROM public.business_authority_fences WHERE business_id=b;
 IF epoch<>p_expected THEN RAISE EXCEPTION 'Business custody changed; review current authority' USING ERRCODE='B4090';END IF;
 IF p_effect='enroll' AND(NOT creator_basis OR recipient<>actor) THEN RAISE EXCEPTION 'Only independent creator may enroll itself' USING ERRCODE='42501';END IF;
 IF p_effect IN('enroll','appoint') AND NOT EXISTS(SELECT 1 FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
  WHERE u.id=recipient AND u.email_verified_at IS NOT NULL AND l.status='current' AND l.principal_class='advertiser')
 THEN RAISE EXCEPTION 'Current verified custodian required' USING ERRCODE='42501';END IF;
 IF p_effect IN('enroll','appoint') AND EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='internal_client_file_admin_v1' AND policy_version=2
  AND target_ref=p_business AND target_kind='business' AND subject_user_id=recipient AND status='active' AND evidence_ref='business-custody:'||p_business::text)
 THEN RAISE EXCEPTION 'Existing custody must be removed explicitly before replacement' USING ERRCODE='40001';END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.internal_team.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect IN('enroll','appoint') THEN
  q.qualification_id:=uuidv7();q.policy_id:='internal_client_file_admin_v1';q.policy_version:=2;q.issuer_kind:='qualified_policy';
  q.subject_user_id:=recipient;q.target_kind:='business';q.target_ref:=p_business;
  q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'];
  q.evidence_ref:='business-custody:'||p_business::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();
  q.provenance:='Explicit independent Business file custodian; five current declarations only. No Workspace, source, private history, inquiry, data-use or external representation authority.';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  FOREACH action_name IN ARRAY q.allowed_actions LOOP
   g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.granted_by:=NULL;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:='business';g.business_id:=b;
   SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
   g.reason:='Explicit bounded Business file custody';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
   g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:='direct';
   PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
   refs:=refs||jsonb_build_object(action_name,g.id::text);
  END LOOP;
 ELSE
  IF p_effect='remove' AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qualifications) AND subject_user_id=recipient AND status='active')
  THEN RAISE EXCEPTION 'Exact current custodian required' USING ERRCODE='40001';END IF;
  FOR old_basis IN SELECT * FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qualifications) AND status='active'
   AND(p_effect='end' OR subject_user_id=recipient) ORDER BY qualification_id LOOP
   basis:=old_basis;basis.status:='revoked';basis.revision:=basis.revision+1;
   PERFORM builderad_private.permit('public.authority_qualification_receipts','update',to_jsonb(old_basis),to_jsonb(basis));
   UPDATE public.authority_qualification_receipts SET status=basis.status,revision=basis.revision WHERE qualification_id=basis.qualification_id;
  END LOOP;
 END IF;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('business_public_id',p_business,'workspace_public_id',NULL,'link_ref',NULL,'state',CASE WHEN p_effect='end' THEN 'ended' ELSE 'active' END,
  'recipient_public_id',p_recipient,'grant_refs',refs,'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.internal_team.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_file_custody_command(text,uuid,uuid,text,uuid,bigint,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_v2_internal_team_command(p_session text,p_business uuid,p_command uuid,p_workspace uuid,p_effect text,p_recipient uuid,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;w bigint;recipient bigint;proof jsonb;payload bytea;result jsonb;prior public.business_workspace_links;l public.business_workspace_links;
 basis public.authority_qualification_receipts;revoked_basis public.authority_qualification_receipts;refs jsonb:='{}';creator_basis boolean;selected public.security_action_grants;authenticated timestamptz;
BEGIN
 IF p_workspace IS NULL THEN RETURN builderad_private.business_file_custody_command(p_session,p_business,p_command,p_effect,p_recipient,p_expected,p_selection);END IF;
 IF p_command IS NULL OR p_workspace IS NULL OR p_expected IS NULL OR p_expected<0 OR p_effect IS NULL OR p_effect NOT IN('enroll','appoint','remove','end')
  OR(p_effect='end' AND p_recipient IS NOT NULL) OR(p_effect<>'end' AND p_recipient IS NULL)
 THEN RAISE EXCEPTION 'Exact internal file custody command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT id INTO w FROM public.workspaces WHERE public_id=p_workspace;
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF w IS NULL OR(p_effect<>'end' AND recipient IS NULL) THEN RAISE EXCEPTION 'Qualified custody target required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,'business.context.read',p_selection,p_session,
  CASE WHEN recipient IS NULL THEN '{}'::bigint[] ELSE ARRAY[recipient] END,ARRAY[w]);
 IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'One custody authority required' USING ERRCODE='42501'; END IF;
 SELECT * INTO selected FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 creator_basis:=proof#>>'{qualification,policy_id}'='self_declared_draft'
  AND proof#>>'{qualification,policy_version}'='1' AND proof#>>'{qualification,issuer_kind}'='self_declared_draft'
  AND p_selection->>'realm_kind'='direct' AND actor=(SELECT created_by FROM public.businesses WHERE id=b);
 IF NOT creator_basis AND NOT(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1'
  AND selected.parent_grant_id IS NULL AND selected.delegable AND selected.delegation_workspace_id=w)
 THEN RAISE EXCEPTION 'Creator or explicitly appointed custodian required' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh custody confirmation required' USING ERRCODE='42501'; END IF;
 IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Current file required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'workspace',p_workspace,'effect',p_effect,'recipient',p_recipient,'expected',p_expected),
  '{"business":"U","workspace":"U","recipient":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.internal_team.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF p_effect IN('enroll','appoint') THEN RAISE EXCEPTION 'Workspace-based Business custody issuance retired' USING ERRCODE='42501';END IF;
 SELECT * INTO prior FROM public.business_workspace_links WHERE business_id=b AND workspace_id=w AND kind='internal_team' ORDER BY revision DESC,link_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Internal team revision changed' USING ERRCODE='B4090'; END IF;
 IF p_effect='enroll' AND prior.status='active' THEN RAISE EXCEPTION 'File already enrolled' USING ERRCODE='40001'; END IF;
 IF p_effect<>'enroll' AND(prior.link_id IS NULL OR prior.status<>'active') THEN RAISE EXCEPTION 'Current explicit file enrollment required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.internal_team.'||p_effect,jsonb_build_object('business_id',b,'workspace_id',w),proof);
 l:=prior;
 l.revision:=prior.revision+1;
 IF p_effect='end' THEN l.status:='ended';l.valid_until:=clock_timestamp();END IF;
 PERFORM builderad_private.permit('public.business_workspace_links','update',to_jsonb(prior),to_jsonb(l));
 UPDATE public.business_workspace_links SET revision=l.revision,status=l.status,valid_until=l.valid_until WHERE link_id=l.link_id;
 IF p_effect='remove' THEN
  SELECT * INTO basis FROM public.authority_qualification_receipts WHERE policy_id='internal_client_file_admin_v1'
   AND evidence_ref='internal-team:'||l.link_id::text AND target_kind='business' AND target_ref=p_business
   AND subject_user_id=recipient AND status='active' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Exact current appointed custodian required' USING ERRCODE='40001'; END IF;
  revoked_basis:=basis;revoked_basis.status:='revoked';revoked_basis.revision:=basis.revision+1;
  PERFORM builderad_private.permit('public.authority_qualification_receipts','update',to_jsonb(basis),to_jsonb(revoked_basis));
  UPDATE public.authority_qualification_receipts SET status=revoked_basis.status,revision=revoked_basis.revision WHERE qualification_id=basis.qualification_id;
 END IF;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('business_public_id',p_business,'workspace_public_id',p_workspace,'link_ref',l.link_id,'state',l.status,
  'recipient_public_id',p_recipient,'grant_refs',refs,'versions',jsonb_build_object('link',l.revision));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.internal_team.'||p_effect,payload,result);
 -- Ending one's own enrollment is authorized by the locked before-image; no fallback path is selected.
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
