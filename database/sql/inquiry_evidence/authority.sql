-- No global Business resolver changes: only these policy entry points admit this new root.
CREATE FUNCTION builderad_private.inquiry_basis(b bigint,actor bigint,selection jsonb,session_ref text,expected bigint DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE proof jsonb;q public.authority_qualification_receipts;
BEGIN
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,session_ref);
 SELECT * INTO STRICT q FROM public.authority_qualification_receipts WHERE qualification_id=(proof#>>'{qualification,qualification_id}')::uuid;
 IF selection->>'realm_kind' IS DISTINCT FROM 'direct' OR q.policy_id<>'self_declared_draft' OR q.policy_version<>1 OR q.issuer_kind<>'self_declared_draft'
 OR q.subject_user_id<>actor OR q.revision IS DISTINCT FROM coalesce(expected,q.revision)
 OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND created_by=actor AND origin_kind='self_declared_draft')
 OR NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint AND parent_grant_id IS NULL AND source='business_draft_creation' AND NOT delegable)
 THEN RAISE EXCEPTION 'Original own-statement basis required' USING ERRCODE='42501'; END IF;
 RETURN proof;
END $$;
CREATE FUNCTION builderad_private.inquiry_require(b bigint,actor bigint,action_name text,selection jsonb,session_ref text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE enrolled public.inquiry_evidence_enrollments;basis public.authority_qualification_receipts;proof jsonb;g public.security_action_grants;
BEGIN
 IF action_name NOT IN('business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read')
 OR selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(selection->'requirements')<>1
 OR coalesce(selection#>>'{requirements,0,leaf_grant_id}','')!~'^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact inquiry action required' USING ERRCODE='42501'; END IF;
 SELECT * INTO g FROM public.security_action_grants WHERE id=(selection#>>'{requirements,0,leaf_grant_id}')::bigint AND subject_user_id=actor AND business_id=b AND scope_kind='business' AND action=action_name;
 SELECT * INTO enrolled FROM public.inquiry_evidence_enrollments WHERE qualification_id=g.qualification_id AND business_id=b AND actor_user_id=actor AND status='active';
 IF enrolled.qualification_id IS NULL THEN RAISE EXCEPTION 'Inquiry access unavailable' USING ERRCODE='42501'; END IF;
 -- Include the original qualification in the initial sorted K4 footprint. Never acquire it after effects.
 PERFORM builderad_private.lock_authority_footprint(actor,selection,session_ref,'{}','{}',ARRAY[enrolled.basis_qualification_id]);
 proof:=builderad_private.require_business(b,actor,action_name,selection,session_ref);
 SELECT * INTO STRICT basis FROM public.authority_qualification_receipts WHERE qualification_id=enrolled.basis_qualification_id;
 IF basis.status<>'active' OR basis.policy_id<>'self_declared_draft' OR basis.policy_version<>1 OR basis.issuer_kind<>'self_declared_draft'
 OR basis.revision<>enrolled.basis_revision OR basis.subject_user_id<>actor OR basis.target_kind<>'business'
 OR basis.target_ref IS DISTINCT FROM(SELECT public_id FROM public.businesses WHERE id=b)
 OR basis.valid_from>clock_timestamp() OR(basis.valid_until IS NOT NULL AND basis.valid_until<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND created_by=actor AND origin_kind='self_declared_draft')
 OR enrolled.generation<>(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 OR proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'inquiry_evidence_custody_v1'
 OR proof#>>'{qualification,policy_version}' IS DISTINCT FROM '1' OR proof#>>'{qualification,issuer_kind}' IS DISTINCT FROM 'qualified_policy'
 OR g.source<>'qualified_policy' OR g.parent_grant_id IS NOT NULL OR g.delegable OR g.delegation_realm_kind<>'direct'
 THEN RAISE EXCEPTION 'Current original inquiry basis required' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('inquiry_basis',jsonb_build_object('qualification_row',to_jsonb(basis),'policy_version',1));
END $$;
CREATE FUNCTION builderad_private.inquiry_fresh(proof jsonb,actor bigint,session_ref text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE authenticated timestamptz;
BEGIN
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=session_ref;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit inquiry confirmation required' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('inquiry_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
END $$;
CREATE FUNCTION builderad_v2_inquiry_eligibility(p_session text,p_business uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;basis uuid;rev bigint;rights jsonb;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_basis(b,actor,p_selection,p_session);
 basis:=(proof#>>'{qualification,qualification_id}')::uuid;rev:=(proof#>>'{qualification,revision}')::bigint;
 SELECT coalesce(jsonb_object_agg(g.action,g.id::text),'{}'::jsonb) INTO rights
 FROM public.inquiry_evidence_enrollments en JOIN public.authority_qualification_receipts q ON q.qualification_id=en.qualification_id
 JOIN public.security_action_grants g ON g.qualification_id=en.qualification_id
 WHERE en.business_id=b AND en.actor_user_id=actor AND en.generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 AND en.basis_qualification_id=basis AND en.basis_revision=rev AND en.status='active'
 AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp())
 AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp());
 PERFORM builderad_private.principal(p_session,false);
 RETURN jsonb_build_object('business',p_business,'basis_qualification',basis,'basis_revision',rev,'policy','inquiry_evidence_custody_v1','policy_version',1,'mode','own_private_statement','grants',rights);
END $$;
CREATE FUNCTION builderad_v2_inquiry_enroll(p_session text,p_business uuid,p_command uuid,p_expected_basis bigint,p_confirm boolean,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;basis public.authority_qualification_receipts;q public.authority_qualification_receipts;
 en public.inquiry_evidence_enrollments;prior_en public.inquiry_evidence_enrollments;new_en public.inquiry_evidence_enrollments;
 g public.security_action_grants;old_g public.security_action_grants;payload bytea;result jsonb;rights jsonb:='{}';a text;
BEGIN
 IF p_command IS NULL OR p_expected_basis IS NULL OR p_expected_basis<1 OR p_confirm IS DISTINCT FROM true THEN RAISE EXCEPTION 'Explicit inquiry opt-in required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_basis(b,actor,p_selection,p_session,p_expected_basis),actor,p_session);
 SELECT * INTO STRICT basis FROM public.authority_qualification_receipts WHERE qualification_id=(proof#>>'{qualification,qualification_id}')::uuid;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'basis',basis.qualification_id,'basis_revision',p_expected_basis,'confirm',true,'policy_version',1),'{"business":"U","basis":"U","basis_revision":"I","policy_version":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'inquiry.enroll',payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 -- Old enrollments are serialized by the existing Business K3 write gate; their original actor is the only eligible issuer.
 PERFORM builderad_private.start_command(actor,p_session,p_command,'inquiry.enroll',jsonb_build_object('business_id',b),proof);
 FOR prior_en IN SELECT * FROM public.inquiry_evidence_enrollments WHERE business_id=b AND actor_user_id=actor AND status='active' LOOP
  new_en:=prior_en;new_en.status:='withdrawn';
  PERFORM builderad_private.permit('public.inquiry_evidence_enrollments','update',to_jsonb(prior_en),to_jsonb(new_en));
  UPDATE public.inquiry_evidence_enrollments SET status='withdrawn' WHERE qualification_id=prior_en.qualification_id;
 END LOOP;
 q.qualification_id:=uuidv7();q.policy_id:='inquiry_evidence_custody_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=NULL;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis.valid_until;
 q.provenance:='Own private source assertions only; exact original qualification dependency; no analytical or external-source authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 en.qualification_id:=q.qualification_id;en.business_id:=b;en.actor_user_id:=actor;en.generation:=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor);
 en.basis_qualification_id:=basis.qualification_id;en.basis_revision:=basis.revision;en.policy_version:=1;en.status:='active';en.created_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.inquiry_evidence_enrollments','insert',NULL,to_jsonb(en));INSERT INTO public.inquiry_evidence_enrollments SELECT(en).*;
 FOREACH a IN ARRAY q.allowed_actions LOOP
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.granted_by:=NULL;g.source:='qualified_policy';g.action:=a;g.scope_kind:='business';g.business_id:=b;
  g.subject_generation:=en.generation;g.reason:='Explicit private inquiry statement opt-in';g.status:='active';g.version:=1;g.expires_at:=basis.valid_until;
  g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.parent_grant_id:=NULL;
  g.delegable:=false;g.delegation_realm_kind:='direct';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  rights:=rights||jsonb_build_object(a,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('command_id',p_command,'policy','inquiry_evidence_custody_v1','qualification',q.qualification_id,'grants',rights,'versions',jsonb_build_object('policy',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'inquiry.enroll',payload,result);
 PERFORM builderad_private.inquiry_fresh(proof,actor,p_session);PERFORM builderad_private.principal(p_session,false);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
CREATE FUNCTION builderad_v2_inquiry_revoke(p_session text,p_business uuid,p_command uuid,p_action text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;g public.security_action_grants;old_g public.security_action_grants;payload bytea;result jsonb;en public.inquiry_evidence_enrollments;basis_selection jsonb;basis_grant bigint;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Exact command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 -- Exact committed own-surrender replay is authorized by CURRENT original basis, not the revoked right.
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command AND actor_user_id=actor AND business_id=b AND action='inquiry.revoke') THEN
  IF p_action NOT IN('business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read')
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>1
  OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','')!~'^[1-9][0-9]{0,17}$'
  THEN RAISE EXCEPTION 'Exact own revocation replay required' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint
   AND subject_user_id=actor AND business_id=b AND scope_kind='business' AND action=p_action AND source='qualified_policy' AND NOT delegable AND parent_grant_id IS NULL;
  SELECT * INTO en FROM public.inquiry_evidence_enrollments WHERE qualification_id=g.qualification_id AND business_id=b AND actor_user_id=actor AND status='active'
   AND generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor);
  IF en.qualification_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=en.qualification_id AND status='active'
   AND valid_from<=clock_timestamp() AND(valid_until IS NULL OR valid_until>clock_timestamp())) THEN RAISE EXCEPTION 'Current own revocation basis required' USING ERRCODE='42501'; END IF;
  SELECT id INTO basis_grant FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND qualification_id=en.basis_qualification_id
   AND action='business.context.read' AND source='business_draft_creation' AND parent_grant_id IS NULL;
  basis_selection:=jsonb_build_object('realm_kind','direct','realm_workspace_public_id',NULL,'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',basis_grant)));
  PERFORM builderad_private.lock_authority_footprint(actor,basis_selection,p_session,'{}','{}',ARRAY[en.qualification_id]);
  proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_basis(b,actor,basis_selection,p_session,en.basis_revision),actor,p_session);
  IF NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=en.qualification_id AND status='active' AND valid_from<=clock_timestamp() AND(valid_until IS NULL OR valid_until>clock_timestamp())) THEN RAISE EXCEPTION 'Current enrollment required after wait' USING ERRCODE='42501'; END IF;
  payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'action',p_action,'grant',g.id),'{"business":"U","grant":"I"}');
  result:=builderad_private.command_replay(b,actor,p_command,'inquiry.revoke',payload);
  PERFORM builderad_private.inquiry_fresh(builderad_private.inquiry_basis(b,actor,basis_selection,p_session,en.basis_revision),actor,p_session);RETURN result;
 END IF;
 proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_require(b,actor,p_action,p_selection,p_session),actor,p_session);
 SELECT * INTO STRICT old_g FROM public.security_action_grants WHERE id=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;g:=old_g;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'action',p_action,'grant',g.id),'{"business":"U","grant":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'inquiry.revoke',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'inquiry.revoke',jsonb_build_object('business_id',b),proof);
 g.status:='revoked';g.revoked_at:=clock_timestamp();g.version:=g.version+1;g.updated_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,revoked_at=g.revoked_at,version=g.version,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('command_id',p_command,'revoked_action',p_action,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'inquiry.revoke',payload,result);
 PERFORM builderad_private.inquiry_fresh(proof,actor,p_session);PERFORM builderad_private.principal(p_session,false);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
