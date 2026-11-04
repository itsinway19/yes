-- Owner-approved internal record custody. No source, external-client or data-use authority.
CREATE FUNCTION builderad_private.internal_team_admin(p_business bigint,p_workspace bigint,p_link uuid,p_recipient bigint,p_payload bytea) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE q public.authority_qualification_receipts;g public.security_action_grants;action_name text;refs jsonb:='{}';
BEGIN
 IF EXISTS(SELECT 1 FROM public.authority_qualification_receipts prior WHERE prior.policy_id='internal_client_file_admin_v1'
  AND prior.evidence_ref='internal-team:'||p_link::text AND prior.subject_user_id=p_recipient AND prior.status='active')
 THEN RAISE EXCEPTION 'Custodian already appointed; use its existing grants' USING ERRCODE='40001'; END IF;
 q.qualification_id:=uuidv7();q.policy_id:='internal_client_file_admin_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
 q.subject_user_id:=p_recipient;q.target_kind:='business';SELECT public_id INTO q.target_ref FROM public.businesses WHERE id=p_business;
 q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'];
 q.evidence_ref:='internal-team:'||p_link::text;q.evidence_digest:=p_payload;q.status:='active';q.revision:=1;
 q.valid_from:=clock_timestamp();q.provenance:='Explicit internal-file custodian appointment only; no external representation, source, data-use or private-record authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 FOREACH action_name IN ARRAY q.allowed_actions LOOP
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=p_recipient;g.granted_by:=NULL;g.source:='qualified_policy';
  g.action:=action_name;g.scope_kind:='business';g.business_id:=p_business;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=p_recipient;
  g.reason:='Owner-approved internal file team custody';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:='internal_team';g.delegation_workspace_id:=p_workspace;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  refs:=refs||jsonb_build_object(action_name,g.id::text);
 END LOOP;
 RETURN refs;
END $$;

CREATE FUNCTION builderad_v2_internal_team_surface(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;selection jsonb;creator_basis boolean;can_admin boolean;teams jsonb;g public.security_action_grants;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_read;
 creator_basis:=proof#>>'{qualification,policy_id}'='self_declared_draft' AND proof#>>'{qualification,policy_version}'='1'
  AND proof#>>'{qualification,issuer_kind}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b);
 can_admin:=creator_basis OR(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND g.parent_grant_id IS NULL AND g.delegable);
 SELECT coalesce(jsonb_agg(jsonb_build_object('link_ref',l.link_id,'workspace_public_id',w.public_id,'workspace_slug',w.slug,'state',l.status,'revision',l.revision) ORDER BY w.id),'[]')
 INTO teams FROM(SELECT DISTINCT ON(workspace_id) * FROM public.business_workspace_links WHERE business_id=b AND kind='internal_team'
  ORDER BY workspace_id,revision DESC,link_id DESC) l JOIN public.workspaces w ON w.id=l.workspace_id
 WHERE creator_basis OR(selection->>'realm_kind'='internal_team' AND g.delegation_workspace_id=l.workspace_id);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'creator_basis',creator_basis,'can_admin',can_admin,'teams',teams);
END $$;

CREATE FUNCTION builderad_v2_internal_team_command(p_session text,p_business uuid,p_command uuid,p_workspace uuid,p_effect text,p_recipient uuid,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;w bigint;recipient bigint;proof jsonb;payload bytea;result jsonb;prior public.business_workspace_links;l public.business_workspace_links;
 basis public.authority_qualification_receipts;revoked_basis public.authority_qualification_receipts;refs jsonb:='{}';creator_basis boolean;selected public.security_action_grants;authenticated timestamptz;
BEGIN
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
 -- Enrollment is always creator opt-in and current organizational administration.
 -- Later explicit creator recovery may appoint a current member of THIS existing team;
 -- membership or Workspace ownership alone can never invoke this command.
 IF p_effect='enroll' AND(NOT creator_basis OR recipient<>actor OR NOT builderad_private.workspace_allowed(w,actor,true))
 THEN RAISE EXCEPTION 'Qualified original creator and team administrator required' USING ERRCODE='42501'; END IF;
 IF p_effect IN('enroll','appoint') AND NOT EXISTS(SELECT 1 FROM public.workspace_memberships m JOIN public.principal_lifecycles x ON x.user_id=m.user_id
  JOIN public.users u ON u.id=x.user_id WHERE m.workspace_id=w AND m.user_id=recipient AND m.status='active'
  AND x.status='current' AND x.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
 THEN RAISE EXCEPTION 'Current verified team custodian required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'workspace',p_workspace,'effect',p_effect,'recipient',p_recipient,'expected',p_expected),
  '{"business":"U","workspace":"U","recipient":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.internal_team.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO prior FROM public.business_workspace_links WHERE business_id=b AND workspace_id=w AND kind='internal_team' ORDER BY revision DESC,link_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Internal team revision changed' USING ERRCODE='B4090'; END IF;
 IF p_effect='enroll' AND prior.status='active' THEN RAISE EXCEPTION 'File already enrolled' USING ERRCODE='40001'; END IF;
 IF p_effect<>'enroll' AND(prior.link_id IS NULL OR prior.status<>'active') THEN RAISE EXCEPTION 'Current explicit file enrollment required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.internal_team.'||p_effect,jsonb_build_object('business_id',b,'workspace_id',w),proof);
 l:=prior;
 IF p_effect='enroll' THEN
  basis.qualification_id:=uuidv7();basis.policy_id:='internal_client_file_team_basis_v1';basis.policy_version:=1;basis.issuer_kind:='qualified_policy';
  basis.subject_user_id:=actor;basis.target_kind:='business';basis.target_ref:=p_business;basis.allowed_actions:=ARRAY['business.internal_team.enroll'];
  basis.evidence_ref:='command:'||p_command::text;basis.evidence_digest:=payload;basis.status:='active';basis.revision:=1;basis.valid_from:=clock_timestamp();
  basis.provenance:='Creator opted in to organizational management of this internal record; not external client delegation';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(basis));INSERT INTO public.authority_qualification_receipts SELECT(basis).*;
  l.link_id:=uuidv7();l.business_id:=b;l.workspace_id:=w;l.kind:='internal_team';l.status:='active';l.revision:=coalesce(prior.revision,0)+1;
  l.valid_from:=clock_timestamp();l.valid_until:=NULL;l.authority_ref:=basis.qualification_id;
  PERFORM builderad_private.permit('public.business_workspace_links','insert',NULL,to_jsonb(l));INSERT INTO public.business_workspace_links SELECT(l).*;
 ELSE
  l.revision:=prior.revision+1;
  IF p_effect='end' THEN l.status:='ended';l.valid_until:=clock_timestamp(); END IF;
  PERFORM builderad_private.permit('public.business_workspace_links','update',to_jsonb(prior),to_jsonb(l));
  UPDATE public.business_workspace_links SET revision=l.revision,status=l.status,valid_until=l.valid_until WHERE link_id=l.link_id;
 END IF;
 IF p_effect IN('enroll','appoint') THEN refs:=builderad_private.internal_team_admin(b,w,l.link_id,recipient,payload);
 ELSIF p_effect='remove' THEN
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
