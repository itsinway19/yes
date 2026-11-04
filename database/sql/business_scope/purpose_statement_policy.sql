-- OWNER_APPROVED / AMEND_AND_ACCEPT_AS_AMENDED, 2026-11-04.
-- One own-Business statement capability. No provider/object/data-use authority.
CREATE FUNCTION builderad_v2_business_purpose_enroll(p_session text,p_business uuid,p_command uuid,p_confirmed boolean,p_expected_previous bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;q public.authority_qualification_receipts;
 g public.security_action_grants;prior_g public.security_action_grants;prior_q public.authority_qualification_receipts;
 basis public.security_action_grants;basis_q public.authority_qualification_receipts;authenticated timestamptz;
BEGIN
 IF p_command IS NULL OR p_confirmed IS DISTINCT FROM true OR p_expected_previous IS NULL OR p_expected_previous<0
 THEN RAISE EXCEPTION 'Explicit confirmed Purpose statement enrollment required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 SELECT * INTO STRICT basis_q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR basis_q.policy_id<>'self_declared_draft' OR basis_q.policy_version<>1
 OR basis_q.issuer_kind<>'self_declared_draft' OR basis.parent_grant_id IS NOT NULL OR basis.source<>'business_draft_creation'
 OR basis.delegable OR actor<>(SELECT created_by FROM public.businesses WHERE id=b AND origin_kind='self_declared_draft')
 THEN RAISE EXCEPTION 'Current original Business creator basis required' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh Purpose statement confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'confirmed',p_confirmed,'previous',p_expected_previous),' {"business":"U","previous":"I"}'::jsonb);
 result:=builderad_private.command_replay(b,actor,p_command,'business.purpose.enroll',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT x.* INTO prior_g FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
 WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1' ORDER BY x.id DESC LIMIT 1;
 IF coalesce(prior_g.id,0)<>p_expected_previous THEN RAISE EXCEPTION 'Reviewed Purpose qualification changed' USING ERRCODE='40001'; END IF;
 IF prior_g.id IS NOT NULL THEN
  SELECT * INTO STRICT prior_q FROM public.authority_qualification_receipts WHERE qualification_id=prior_g.qualification_id;
  IF prior_g.status='active' AND prior_g.revoked_at IS NULL AND(prior_g.expires_at IS NULL OR prior_g.expires_at>clock_timestamp())
   AND prior_q.status='active' AND prior_q.valid_from<=clock_timestamp() AND(prior_q.valid_until IS NULL OR prior_q.valid_until>clock_timestamp())
  THEN RAISE EXCEPTION 'Reconcile existing Purpose enrollment' USING ERRCODE='40001'; END IF;
 END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.purpose.enroll',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_purpose_statement_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=basis.id;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;q.allowed_actions:=ARRAY['business.purpose.declare'];
 q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis_q.valid_until;
 q.provenance:='Original creator explicitly opts into own Business Purpose statements; no external object ownership, provider/source access, data use, representation or onward delegation';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:='business.purpose.declare';g.scope_kind:='business';g.business_id:=b;
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
 g.reason:='Explicit own Business Purpose statement qualification';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
 g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.expires_at:=basis.expires_at;g.delegable:=false;g.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',jsonb_build_object('business.purpose.declare',g.id::text),'qualification_ref',q.qualification_id,
  'authority_scope','own_business_statement_only','establishes_external_authority',false,'versions',jsonb_build_object('qualification',1,'grant',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.purpose.enroll',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;
