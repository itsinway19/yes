CREATE FUNCTION builderad_private.business_file_custody_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_recipient uuid, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;recipient bigint;proof jsonb;footprint jsonb;payload bytea;result jsonb;selected public.security_action_grants;
 basis public.authority_qualification_receipts;old_basis public.authority_qualification_receipts;q public.authority_qualification_receipts;g public.security_action_grants;
 roots bigint[];qualifications uuid[];subjects bigint[];creator_basis boolean;custodian_basis boolean;authenticated timestamptz;epoch bigint;refs jsonb:='{}';action_name text;
BEGIN
 IF p_command IS NULL OR p_expected IS NULL OR p_expected<0 OR p_effect IS NULL OR p_effect NOT IN('appoint','remove')
  OR p_recipient IS NULL
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1
 THEN RAISE EXCEPTION 'Exact Business file custody command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' THEN RAISE EXCEPTION 'Independent Business custody authority required' USING ERRCODE='42501';END IF;
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF recipient IS NULL THEN RAISE EXCEPTION 'Named registered custodian required' USING ERRCODE='42501';END IF;
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
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'effect',p_effect,'recipient',p_recipient,'expected',p_expected),
  '{"business":"U","recipient":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.internal_team.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit custody confirmation required' USING ERRCODE='42501';END IF;
 IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Current file required' USING ERRCODE='42501';END IF;
 SELECT auth_epoch INTO epoch FROM public.business_authority_fences WHERE business_id=b;
 IF epoch<>p_expected THEN RAISE EXCEPTION 'Business custody changed; review current authority' USING ERRCODE='B4090';END IF;
 IF p_effect='appoint' AND NOT EXISTS(SELECT 1 FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
  WHERE u.id=recipient AND u.email_verified_at IS NOT NULL AND l.status='current' AND l.principal_class='advertiser')
 THEN RAISE EXCEPTION 'Current verified custodian required' USING ERRCODE='42501';END IF;
 IF p_effect='appoint' AND EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='internal_client_file_admin_v1' AND policy_version=2
  AND target_ref=p_business AND target_kind='business' AND subject_user_id=recipient AND status='active' AND evidence_ref='business-custody:'||p_business::text)
 THEN RAISE EXCEPTION 'Existing custody must be removed explicitly before replacement' USING ERRCODE='40001';END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.internal_team.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect='appoint' THEN
  q.qualification_id:=uuidv7();q.policy_id:='internal_client_file_admin_v1';q.policy_version:=2;q.issuer_kind:='qualified_policy';
  q.subject_user_id:=recipient;q.target_kind:='business';q.target_ref:=p_business;
  q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'];
  q.evidence_ref:='business-custody:'||p_business::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();
  q.provenance:='Explicit independent Business file custodian; five current declarations only. No source, private history, inquiry, data-use or external representation authority.';
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
   AND subject_user_id=recipient ORDER BY qualification_id LOOP
   basis:=old_basis;basis.status:='revoked';basis.revision:=basis.revision+1;
   PERFORM builderad_private.permit('public.authority_qualification_receipts','update',to_jsonb(old_basis),to_jsonb(basis));
   UPDATE public.authority_qualification_receipts SET status=basis.status,revision=basis.revision WHERE qualification_id=basis.qualification_id;
  END LOOP;
 END IF;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('business_public_id',p_business,'state','active',
  'recipient_public_id',p_recipient,'grant_refs',refs,'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.internal_team.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION builderad_private.business_file_custody_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_recipient uuid, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_team_complete_child(p_actor bigint, p_command uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE child builderad_private.invocations;
BEGIN
 SELECT * INTO child FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id()
  AND actor_user_id=p_actor AND command_id=p_command AND phase='completed' AND action IN('business.grant.issue','business.grant.revoke');
 IF NOT FOUND OR EXISTS(SELECT 1 FROM builderad_private.command_permits WHERE invocation_nonce=child.nonce)
 THEN RAISE EXCEPTION 'Exact finalized child invocation required' USING ERRCODE='42501'; END IF;
 DELETE FROM builderad_private.invocations WHERE nonce=child.nonce AND phase='completed';
 RETURN jsonb_build_object('command_id',child.command_id,'action',child.action,'authority_selection',child.authority_selection,
  'authority_selection_digest',encode(child.authority_selection_digest,'hex'),'dependency_digest',encode(child.dependency_digest,'hex'));
END $$;


ALTER FUNCTION builderad_private.business_team_complete_child(p_actor bigint, p_command uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_team_context(p_session text, p_business uuid, p_read bigint, p_write boolean DEFAULT false, p_recipient bigint DEFAULT NULL::bigint, p_extra_grants bigint[] DEFAULT '{}'::bigint[]) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;roots bigint[];requirements jsonb;footprint jsonb;proof jsonb;root public.security_action_grants;refs jsonb:='{}';children bigint[];qualifications uuid[];custodian_qualification uuid;
BEGIN
 b:=builderad_private.lock_business(p_business,p_write);actor:=builderad_private.principal(p_session,false);
 -- Select the administration family from the exact requested read root before
 -- collecting one combined lock footprint. A staff child is never an admin root.
 SELECT q.qualification_id INTO custodian_qualification FROM public.security_action_grants g
 JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.id=p_read AND g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business' AND g.action='business.context.read'
  AND g.parent_grant_id IS NULL AND g.delegable AND g.delegation_realm_kind='direct'
  AND g.status='active' AND g.revoked_at IS NULL AND g.source='qualified_policy'
  AND q.policy_id='internal_client_file_admin_v1' AND q.policy_version=2 AND q.issuer_kind='qualified_policy'
  AND q.evidence_ref='business-custody:'||p_business::text AND q.agency_business_id IS NULL;
 IF custodian_qualification IS NOT NULL THEN
  SELECT array_agg(g.id ORDER BY g.id) INTO roots FROM public.security_action_grants g
  WHERE g.qualification_id=custodian_qualification AND g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business'
   AND g.parent_grant_id IS NULL AND g.delegable AND g.delegation_realm_kind='direct'
   AND g.status='active' AND g.revoked_at IS NULL AND g.source='qualified_policy'
   AND g.action IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage');
 ELSE
  IF actor<>(SELECT created_by FROM public.businesses WHERE id=b) THEN RAISE EXCEPTION 'Original Business team administrator or explicit direct custodian required' USING ERRCODE='42501'; END IF;
 SELECT array_agg(g.id ORDER BY g.id) INTO roots FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business' AND g.parent_grant_id IS NULL AND g.delegation_realm_kind='direct'
  AND g.status='active' AND g.revoked_at IS NULL AND g.delegable
  AND q.policy_id='business_declaration_team_admin_v1' AND q.agency_business_id IS NULL;
 END IF;
 IF cardinality(roots) IS DISTINCT FROM 5 THEN RAISE EXCEPTION 'Current explicit Business team enrollment required' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg(jsonb_build_object('requirement_kind','business','leaf_grant_id',id) ORDER BY id) INTO requirements FROM(SELECT DISTINCT unnest(array_append(roots,p_read)) id) ids;
 footprint:=builderad_private.authority_footprint(actor,jsonb_build_object('requirements',requirements),CASE WHEN p_recipient IS NULL THEN '{}'::bigint[] ELSE ARRAY[p_recipient] END);
 -- Target before-images are locks, never live authority prerequisites. Expired or
 -- disabled staff must remain removable using the administrator's current roots.
 SELECT coalesce(array_agg(g.id),'{}'),coalesce(array_agg(g.qualification_id),'{}') INTO children,qualifications
 FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=p_recipient AND g.parent_grant_id=ANY(roots) AND g.status='active' AND g.revoked_at IS NULL
  AND g.scope_kind='business' AND g.delegation_realm_kind='direct' AND NOT g.delegable
  AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
 footprint:=jsonb_set(footprint,'{grants}',(footprint->'grants')||to_jsonb(children));
 footprint:=jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||to_jsonb(qualifications));
 footprint:=jsonb_set(footprint,'{grants}',(footprint->'grants')||to_jsonb(p_extra_grants));
 footprint:=jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||coalesce((SELECT jsonb_agg(qualification_id) FROM public.security_action_grants WHERE id=ANY(p_extra_grants)),'[]'::jsonb));
 PERFORM builderad_private.lock_authority_rows(actor,p_session,footprint);
 proof:=builderad_private.require_business(b,actor,'business.context.read',builderad_private.business_selection(p_read),p_session);
 IF custodian_qualification IS NULL THEN
  IF proof#>>'{qualification,policy_id}' NOT IN('self_declared_draft','business_declaration_team_admin_v1') THEN RAISE EXCEPTION 'Independent team read required' USING ERRCODE='42501'; END IF;
 ELSE
  IF proof#>>'{qualification,qualification_id}' IS DISTINCT FROM custodian_qualification::text
   OR proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'internal_client_file_admin_v1'
   OR proof#>>'{qualification,policy_version}' IS DISTINCT FROM '2'
   OR proof#>>'{qualification,issuer_kind}' IS DISTINCT FROM 'qualified_policy'
   OR proof#>>'{qualification,evidence_ref}' IS DISTINCT FROM 'business-custody:'||p_business::text
  THEN RAISE EXCEPTION 'Exact current direct Business custodian required' USING ERRCODE='42501'; END IF;
 END IF;
 FOR root IN SELECT * FROM public.security_action_grants WHERE id=ANY(roots) ORDER BY id LOOP
  PERFORM builderad_private.require_business(b,actor,root.action,builderad_private.business_selection(root.id),p_session);
  refs:=refs||jsonb_build_object(root.action,root.id::text);
 END LOOP;
 RETURN jsonb_build_object('business_id',b,'actor',actor,'roots',refs,'proof',proof);
END $$;


ALTER FUNCTION builderad_private.business_team_context(p_session text, p_business uuid, p_read bigint, p_write boolean, p_recipient bigint, p_extra_grants bigint[]) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.finish_fact(p_session text, p_business bigint, p_actor bigint, p_selection jsonb, p_payload bytea, p_result jsonb, p_changed boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE i builderad_private.invocations;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.require_business(p_business,p_actor,i.action,p_selection,p_session);
 PERFORM builderad_private.record_command(p_business,p_actor,i.command_id,i.action,p_payload,p_result,CASE WHEN p_changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();
 RETURN p_result;
END $$;


ALTER FUNCTION builderad_private.finish_fact(p_session text, p_business bigint, p_actor bigint, p_selection jsonb, p_payload bytea, p_result jsonb, p_changed boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.issue_draft_grant(p_business bigint, p_actor bigint, p_qualification uuid, p_action text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE g public.security_action_grants;
BEGIN
 g.id:=nextval('public.security_action_grants_id_seq');
 g.subject_user_id:=p_actor; g.granted_by:=NULL; g.source:='business_draft_creation'; g.action:=p_action; g.scope_kind:='business';
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=p_actor;
 g.reason:='Private self-declared Business statements only'; g.status:='active'; g.version:=1;
 g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
 g.business_id:=p_business;g.qualification_id:=p_qualification;g.delegable:=false;g.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));
 INSERT INTO public.security_action_grants SELECT (g).*;
 RETURN g.id;
END $$;


ALTER FUNCTION builderad_private.issue_draft_grant(p_business bigint, p_actor bigint, p_qualification uuid, p_action text) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_agency_grant(p_session text, p_business uuid, p_command uuid, p_effect text, p_agency uuid, p_recipient uuid, p_membership bigint, p_action text, p_parent bigint, p_expires timestamp with time zone, p_confirmed boolean, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;agency_id bigint;actor bigint;recipient bigint;proof jsonb;eligibility jsonb;lock_selection jsonb;payload bytea;result jsonb;
 parent public.security_action_grants;basis public.authority_qualification_receipts;g public.security_action_grants;q public.authority_qualification_receipts;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('mandate','assign') OR p_confirmed IS DISTINCT FROM true
  OR p_agency IS NULL OR p_recipient IS NULL OR p_membership IS NULL OR p_parent IS NULL OR p_expires IS NULL OR NOT isfinite(p_expires)
  OR p_action IS NULL OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1
  OR p_selection#>>'{requirements,0,leaf_grant_id}' IS DISTINCT FROM p_parent::text
 THEN RAISE EXCEPTION 'Exact finite agency command and confirmation required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT id INTO agency_id FROM public.businesses WHERE public_id=p_agency;
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF agency_id IS NULL OR agency_id=b OR recipient IS NULL OR recipient=actor THEN RAISE EXCEPTION 'Independent named agency recipient required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'effect',p_effect,'agency',p_agency,
  'recipient',p_recipient,'membership',p_membership,'action',p_action,'parent',p_parent,'expires',p_expires,'confirmed',p_confirmed),
  '{"business":"U","agency":"U","recipient":"U","membership":"I","parent":"I","expires":"T"}');
 -- A receipt reveals no new right. Requalify the sender's exact current path,
 -- but do not make recovery depend on the recipient still being eligible.
 -- The Business lock serializes this probe with new effects and receipt writes.
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN
  PERFORM builderad_private.require_scope('business',b::text,actor,p_action,p_selection,p_session,ARRAY[recipient]);
  PERFORM builderad_private.principal(p_session);
  RETURN builderad_private.command_replay(b,actor,p_command,'business.agency.'||p_effect,payload);
 END IF;
 -- Freeze the recipient eligibility alongside the selected client path before K4.
 PERFORM builderad_private.business_agency_eligibility(agency_id,recipient,p_membership);
 PERFORM builderad_private.lock_agency_command(actor,p_selection,p_session,recipient,p_membership,agency_id);
 proof:=builderad_private.require_scope('business',b::text,actor,p_action,p_selection,p_session,ARRAY[recipient]);
 eligibility:=builderad_private.business_agency_eligibility(agency_id,recipient,p_membership);
 SELECT * INTO STRICT parent FROM public.security_action_grants WHERE id=p_parent;
 SELECT * INTO STRICT basis FROM public.authority_qualification_receipts WHERE qualification_id=parent.qualification_id;
 IF NOT parent.delegable OR parent.action<>p_action OR parent.business_id<>b
  OR (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived')
  OR p_expires<=clock_timestamp()
  OR EXISTS(SELECT 1 FROM jsonb_array_elements((proof->'grants')||(eligibility->'grants')) x WHERE x->>'expires_at' IS NOT NULL AND p_expires>(x->>'expires_at')::timestamptz)
  OR EXISTS(SELECT 1 FROM jsonb_array_elements((proof->'qualifications')||(eligibility->'qualifications')) x WHERE x->>'valid_until' IS NOT NULL AND p_expires>(x->>'valid_until')::timestamptz)
 THEN RAISE EXCEPTION 'Agency assignment exceeds current independent bases' USING ERRCODE='42501'; END IF;
 IF p_effect='mandate' THEN
  IF basis.policy_id<>'business_declaration_team_admin_v1' OR parent.parent_grant_id IS NOT NULL
   OR actor<>(SELECT created_by FROM public.businesses WHERE id=b)
   OR recipient<>(SELECT created_by FROM public.businesses WHERE id=agency_id)
  THEN RAISE EXCEPTION 'Explicit client creator and agency representative required' USING ERRCODE='42501'; END IF;
 ELSE
  IF basis.policy_id<>'business_agency_mandate_v1' OR basis.agency_business_id IS DISTINCT FROM agency_id
  THEN RAISE EXCEPTION 'Exact current client mandate required for this agency' USING ERRCODE='42501'; END IF;
 END IF;
 PERFORM builderad_private.principal(p_session);
 proof:=proof||jsonb_build_object('agency_recipient_eligibility',eligibility);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.agency.'||p_effect,jsonb_build_object('business_id',b,'agency_business_id',agency_id),proof);
 q.qualification_id:=uuidv7();q.policy_id:=CASE p_effect WHEN 'mandate' THEN 'business_agency_mandate_v1' ELSE 'business_agency_assignment_v1' END;
 q.policy_version:=1;q.issuer_kind:='existing_grant';q.issuer_grant_id:=parent.id;q.subject_user_id:=recipient;
 q.target_kind:='business';q.target_ref:=p_business;q.allowed_actions:=ARRAY[p_action];q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;
 q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_expires;
 q.provenance:='Explicit Business-to-Business current-declaration mandate and exact employee eligibility; no Source, private-history, client-data or execution rights';
 q.agency_business_id:=agency_id;q.agency_membership_grant_id:=p_membership;
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));
 INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.granted_by:=actor;g.source:='qualified_delegation';
 g.action:=p_action;g.scope_kind:='business';g.business_id:=b;
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
 g.reason:='Explicit Business agency '||p_effect;g.status:='active';g.version:=1;g.expires_at:=p_expires;
 g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;
 g.parent_grant_id:=parent.id;g.delegable:=p_effect='mandate';g.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_scope('business',b::text,actor,p_action,p_selection,p_session,ARRAY[recipient]);
 PERFORM builderad_private.business_agency_eligibility(agency_id,recipient,p_membership);
 result:=jsonb_build_object('business_public_id',p_business,'agency_business_public_id',p_agency,'grant_id',g.id,'status',g.status,
  'qualification_ref',q.qualification_id,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.agency.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_agency_grant(p_session text, p_business uuid, p_command uuid, p_effect text, p_agency uuid, p_recipient uuid, p_membership bigint, p_action text, p_parent bigint, p_expires timestamp with time zone, p_confirmed boolean, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_agency_mandates(p_session text, p_business uuid, p_read bigint, p_after uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE context jsonb;items jsonb:='[]';group_row record;root_ids bigint[];seen integer:=0;more boolean:=false;last_id uuid;next_cursor uuid;
BEGIN
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 FOR group_row IN SELECT ab.public_id,jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id) grants,count(*) n
  FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   JOIN public.businesses ab ON ab.id=q.agency_business_id
  WHERE g.business_id=(context->>'business_id')::bigint AND g.parent_grant_id=ANY(root_ids) AND g.status='active' AND g.revoked_at IS NULL
   AND q.policy_id='business_agency_mandate_v1' AND(p_after IS NULL OR ab.public_id>p_after) GROUP BY ab.public_id ORDER BY ab.public_id LIMIT 65 LOOP
  seen:=seen+1;IF seen>64 THEN more:=true;next_cursor:=last_id;EXIT;END IF;
  items:=items||jsonb_build_array(jsonb_build_object('agency_business_public_id',group_row.public_id,
   'grants',CASE WHEN group_row.n<=256 THEN group_row.grants ELSE '[]'::jsonb END,'complete',group_row.n<=256));last_id:=group_row.public_id;
 END LOOP;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'items',items,'more',more,'next_cursor',next_cursor);
END $$;


ALTER FUNCTION public.builderad_v2_business_agency_mandates(p_session text, p_business uuid, p_read bigint, p_after uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_agency_options(p_session text, p_business uuid, p_read bigint, p_agency uuid, p_recipient uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;agency_id bigint;actor bigint;recipient_id bigint;membership bigint;mode text;selection jsonb;requirements jsonb;
 read_proof jsonb;basis_proof jsonb;eligibility jsonb;options jsonb:='[]';grant_row public.security_action_grants;
 read_qualification public.authority_qualification_receipts;candidate_ids bigint[];deadline timestamptz;
BEGIN
 IF p_read IS NULL OR p_read<1 OR p_agency IS NULL THEN RAISE EXCEPTION 'Exact Business and agency required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,false);actor:=builderad_private.principal(p_session,false);
 selection:=builderad_private.business_selection(p_read);
 SELECT q.* INTO read_qualification FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.id=p_read AND g.subject_user_id=actor AND g.business_id=b AND g.scope_kind='business' AND g.action='business.context.read';
 SELECT id INTO agency_id FROM public.businesses WHERE public_id=p_agency;
 IF NOT FOUND OR agency_id=b OR read_qualification.qualification_id IS NULL THEN RAISE EXCEPTION 'Qualified agency context required' USING ERRCODE='42501'; END IF;
 IF actor=(SELECT created_by FROM public.businesses WHERE id=b)
  AND read_qualification.policy_id IN('self_declared_draft','business_declaration_team_admin_v1') THEN
  mode:='mandate';
  IF p_recipient IS NOT NULL THEN RAISE EXCEPTION 'Client selects an agency Business, not an arbitrary employee' USING ERRCODE='22023'; END IF;
  SELECT created_by INTO recipient_id FROM public.businesses WHERE id=agency_id;
  SELECT g.id INTO membership FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   WHERE g.business_id=agency_id AND g.subject_user_id=recipient_id AND g.scope_kind='business' AND g.action='business.context.read'
    AND g.source='business_draft_creation' AND g.parent_grant_id IS NULL AND q.policy_id='self_declared_draft' ORDER BY g.id LIMIT 1;
 ELSE
  IF p_recipient IS NULL OR read_qualification.policy_id<>'business_agency_mandate_v1' OR read_qualification.agency_business_id IS DISTINCT FROM agency_id
  THEN RAISE EXCEPTION 'Exact current agency mandate and named employee required' USING ERRCODE='42501'; END IF;
  mode:='assign';SELECT id INTO recipient_id FROM public.users WHERE public_id=p_recipient;
  SELECT child.id INTO membership FROM public.security_action_grants child JOIN public.security_action_grants parent ON parent.id=child.parent_grant_id
   JOIN public.authority_qualification_receipts q ON q.qualification_id=parent.qualification_id
   WHERE child.business_id=agency_id AND child.subject_user_id=recipient_id AND child.action='business.context.read' AND child.scope_kind='business'
    AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
    AND q.policy_id='business_declaration_team_admin_v1' AND parent.parent_grant_id IS NULL ORDER BY child.id DESC LIMIT 1;
 END IF;
 IF membership IS NULL OR recipient_id IS NULL OR recipient_id=actor THEN RAISE EXCEPTION 'Current independent named recipient required' USING ERRCODE='42501'; END IF;
 -- No recipient metadata is returned until both client read and employee bases qualify.
 eligibility:=builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 SELECT coalesce(array_agg(g.id ORDER BY g.id),'{}') INTO candidate_ids FROM(
  SELECT x.id FROM public.security_action_grants x JOIN public.authority_qualification_receipts q ON q.qualification_id=x.qualification_id
  WHERE x.business_id=b AND x.scope_kind='business' AND x.subject_user_id=actor AND x.delegable AND x.status='active' AND x.revoked_at IS NULL
   AND x.action IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
   AND((mode='mandate' AND q.policy_id='business_declaration_team_admin_v1' AND x.parent_grant_id IS NULL)
    OR(mode='assign' AND q.policy_id='business_agency_mandate_v1' AND q.agency_business_id=agency_id)) ORDER BY x.id LIMIT 30
 ) g;
 SELECT jsonb_agg(jsonb_build_object('requirement_kind','business','leaf_grant_id',id) ORDER BY id) INTO requirements
  FROM(SELECT DISTINCT unnest(array_append(candidate_ids,p_read)) id) ids;
 PERFORM builderad_private.lock_agency_command(actor,jsonb_build_object('requirements',requirements),p_session,recipient_id,membership,agency_id);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 eligibility:=builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidate_ids) ORDER BY id LOOP
  BEGIN
   basis_proof:=builderad_private.require_business(b,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
   SELECT min(value::timestamptz) INTO deadline FROM(
    SELECT item->>'expires_at' value FROM jsonb_array_elements((basis_proof->'grants')||(eligibility->'grants')) item
    UNION ALL SELECT item->>'valid_until' FROM jsonb_array_elements((basis_proof->'qualifications')||(eligibility->'qualifications')) item
   ) deadlines WHERE value IS NOT NULL;
   options:=options||jsonb_build_array(jsonb_build_object('action',grant_row.action,'parent_grant_id',grant_row.id,
    'authority_selection',builderad_private.business_selection(grant_row.id),'max_expires_at',deadline));
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 PERFORM builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 RETURN jsonb_build_object('business_public_id',p_business,'agency_business_public_id',p_agency,'effect',mode,
  'recipient',jsonb_build_object('public_id',(SELECT public_id FROM public.users WHERE id=recipient_id),'membership_grant_id',membership),
  'options',options,'scope','current_declaration_agency_only');
END $$;


ALTER FUNCTION public.builderad_v2_business_agency_options(p_session text, p_business uuid, p_read bigint, p_agency uuid, p_recipient uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_agency_withdraw(p_session text, p_business uuid, p_command uuid, p_selection jsonb, p_agency uuid, p_expected jsonb, p_confirmed boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE b bigint;actor bigint;recipient bigint;agency bigint;read_id bigint;context jsonb;root_ids bigint[];targets bigint[];expected jsonb;current_set jsonb;payload bytea;result jsonb;mandate_row public.security_action_grants;child_command uuid;children jsonb:='[]';
BEGIN
 IF p_command IS NULL OR p_agency IS NULL OR p_confirmed IS DISTINCT FROM true OR jsonb_typeof(p_expected) IS DISTINCT FROM 'array' OR jsonb_array_length(p_expected)>256
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct'
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,18}$'
 THEN RAISE EXCEPTION 'Exact confirmed mandate withdrawal required' USING ERRCODE='22023';END IF;
 IF (p_selection#>>'{requirements,0,leaf_grant_id}')::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Bounded read reference required' USING ERRCODE='22023';END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_expected) x WHERE jsonb_typeof(x) IS DISTINCT FROM 'object' OR coalesce(x->>'grant_id','') !~ '^[1-9][0-9]{0,17}$'
  OR coalesce(x->>'version','') !~ '^[1-9][0-9]{0,17}$' OR (SELECT count(*) FROM jsonb_object_keys(x))<>2) THEN RAISE EXCEPTION 'Exact mandate grant versions required' USING ERRCODE='22023';END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);read_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 SELECT id,created_by INTO agency,recipient FROM public.businesses WHERE public_id=p_agency;
 IF agency IS NULL OR agency=b THEN RAISE EXCEPTION 'Exact counterparty Business required' USING ERRCODE='42501';END IF;
 SELECT coalesce(array_agg(g.id),'{}') INTO targets FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE g.business_id=b AND q.policy_id='business_agency_mandate_v1' AND q.agency_business_id=agency AND g.status='active' AND g.revoked_at IS NULL;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient,targets);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 SELECT coalesce(jsonb_agg(value ORDER BY (value->>'grant_id')::bigint),'[]') INTO expected FROM jsonb_array_elements(p_expected);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'agency',p_agency,'expected',expected,'confirmed',p_confirmed),'{"business":"U","agency":"U"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.agency.withdraw',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id),'[]') INTO current_set FROM public.security_action_grants g
  WHERE g.id=ANY(targets) AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business' AND g.subject_user_id=recipient;
 IF current_set IS DISTINCT FROM expected OR jsonb_array_length(expected)=0 OR cardinality(targets)<>jsonb_array_length(expected)
 THEN RAISE EXCEPTION 'Complete current mandate set required' USING ERRCODE='40001';END IF;
 FOR mandate_row IN SELECT * FROM public.security_action_grants WHERE id=ANY(targets) ORDER BY id LOOP
  child_command:=md5(p_command::text||':mandate:withdraw:'||mandate_row.id::text)::uuid;
  PERFORM public.builderad_v2_business_grant_command(p_session,p_business,child_command,'revoke',(SELECT public_id FROM public.users WHERE id=recipient),mandate_row.action,NULL,mandate_row.id,mandate_row.version,NULL,false,builderad_private.business_selection(mandate_row.parent_grant_id));
  children:=children||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
 END LOOP;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.agency.withdraw',jsonb_build_object('business_id',b),
  (context->'proof')||jsonb_build_object('completed_children',children));
 result:=jsonb_build_object('business_public_id',p_business,'agency_business_public_id',p_agency,'status','withdrawn',
  'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.agency.withdraw',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_business_agency_withdraw(p_session text, p_business uuid, p_command uuid, p_selection jsonb, p_agency uuid, p_expected jsonb, p_confirmed boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_catalog(p_session text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint; result jsonb;
BEGIN
 actor:=builderad_private.principal(p_session);
 SELECT jsonb_build_object('business_types',coalesce(jsonb_agg(jsonb_build_object('id',id,'key',key,'name_ar',name_ar,'name_en',name_en,'active',active,'version',version) ORDER BY id),'[]'::jsonb)) INTO result FROM public.business_types;
 SELECT result||jsonb_build_object('countries',coalesce(jsonb_agg(jsonb_build_object('code',code,'name_ar',name_ar,'name_en',name_en) ORDER BY code COLLATE "C"),'[]'::jsonb)) INTO result FROM public.country_references;
 SELECT result||jsonb_build_object('intent_timezones',coalesce(jsonb_agg(name ORDER BY name COLLATE "C"),'[]'::jsonb)) INTO result
 FROM(SELECT DISTINCT name FROM public.builderad_current_intent_timezone_names() WHERE public.builderad_current_intent_timezone_name_allowed(name)) accepted;
 PERFORM builderad_private.principal(p_session);
 RETURN result;
END;
$$;


ALTER FUNCTION public.builderad_v2_business_catalog(p_session text) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_choices(p_session text, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint; g record; b record; c uuid; selection jsonb; footprint jsonb; proof jsonb; row_value jsonb;
 candidates bigint[]:='{}'; business_ids bigint[]:='{}'; requirements jsonb:='[]'; items jsonb:='[]'; proofs jsonb:='[]'; scanned bigint:=p_after; next_cursor bigint; mode text;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded explicit cursor required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.requalify_business_identity(p_session);
 actor:=builderad_private.principal(p_session,false);
 FOR g IN SELECT x.* FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
 WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.action='business.context.read' AND x.status='active' AND x.revoked_at IS NULL
 AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp()) AND x.id>p_after ORDER BY x.id LIMIT 32 LOOP
  scanned:=g.id; selection:=builderad_private.business_selection(g.id);
  BEGIN
   footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM g.business_id AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN
    RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501';
   END IF;
   candidates:=array_append(candidates,g.id);business_ids:=array_append(business_ids,g.business_id);
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOR c IN SELECT DISTINCT cohort_id FROM public.migration_cohort_members WHERE business_id=ANY(business_ids) AND kind='business' ORDER BY cohort_id LOOP
  PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=c FOR SHARE;
 END LOOP;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=ANY(business_ids) ORDER BY business_id FOR SHARE;
 IF cardinality(candidates)>0 THEN
  PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);
 END IF;
 FOR g IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidates) ORDER BY id LOOP
  BEGIN
   SELECT x.* INTO STRICT b FROM public.businesses x WHERE id=g.business_id;
   SELECT x.mode INTO mode FROM public.migration_cohort_control x JOIN public.migration_cohort_members m ON m.cohort_id=x.cohort_id WHERE m.business_id=b.id AND m.kind='business';
   IF mode IS DISTINCT FROM 'V2_ACTIVE' THEN RAISE EXCEPTION 'Business not active in this foundation' USING ERRCODE='42501'; END IF;
   selection:=builderad_private.business_selection(g.id);
   proof:=builderad_private.require_business(b.id,actor,'business.context.read',selection,p_session);
   SELECT jsonb_build_object('business_public_id',b.public_id,'name',h.display_name,'state',b.lifecycle,'read_grant_id',g.id::text,
    'realm_kind',selection->'realm_kind','agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=g.qualification_id)) INTO row_value FROM public.business_context_heads h WHERE h.business_id=b.id;
   items:=items||jsonb_build_array(row_value);proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 -- Expiry is checked again after the last potentially waiting authority read, without substituting another path.
 FOR row_value IN SELECT value FROM jsonb_array_elements(items) LOOP
  PERFORM builderad_private.require_business((SELECT id FROM public.businesses WHERE public_id=(row_value->>'business_public_id')::uuid),actor,'business.context.read',builderad_private.business_selection((row_value->>'read_grant_id')::bigint),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.action='business.context.read' AND x.id>scanned AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp())) THEN next_cursor:=scanned; END IF;
 RETURN jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'items',items,'next_cursor',next_cursor::text,'scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('items',items,'proofs',proofs)::text,'UTF8')),'hex')));
END $$;


ALTER FUNCTION public.builderad_v2_business_choices(p_session text, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_context_write(p_session text, p_business uuid, p_command uuid, p_name text, p_type bigint, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint; actor bigint; snapshot jsonb; payload bytea; result jsonb; h public.business_context_heads; prior public.business_context_heads;
 e public.business_context_transitions; changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 OR p_name IS NULL OR length(btrim(p_name)) NOT BETWEEN 2 AND 120 OR octet_length(p_name)>480 THEN RAISE EXCEPTION 'Bounded name and expected version required' USING ERRCODE='22023'; END IF;
 p_name:=regexp_replace(btrim(p_name),'\s+',' ','g');
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 IF p_type IS NOT NULL THEN PERFORM 1 FROM public.business_types WHERE id=p_type FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Known Business type required' USING ERRCODE='22023'; END IF;
 END IF;
 SELECT * INTO prior FROM public.business_context_heads WHERE business_id=b FOR UPDATE;
 IF p_type IS NOT NULL AND p_type IS DISTINCT FROM prior.business_type_id AND NOT (SELECT active FROM public.business_types WHERE id=p_type) THEN RAISE EXCEPTION 'Selectable Business type required' USING ERRCODE='22023'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('name',p_name,'type',p_type,'expected',p_expected),'{"type":"I","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.context.manage',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.context.manage',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.display_name IS DISTINCT FROM p_name OR prior.business_type_id IS DISTINCT FROM p_type;h:=prior;
 IF changed THEN
  IF prior.context_version<>p_expected THEN RAISE EXCEPTION 'Business context changed' USING ERRCODE='40001'; END IF;
  h.display_name:=p_name;h.business_type_id:=p_type;h.context_version:=prior.context_version+1;
  PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior),to_jsonb(h));
  UPDATE public.business_context_heads SET display_name=h.display_name,business_type_id=h.business_type_id,context_version=h.context_version WHERE business_id=b;
  e.event_id:=uuidv7();e.business_id:=b;e.before_context_version:=prior.context_version;e.after_context_version:=h.context_version;
  e.before_value:=jsonb_build_object('name',prior.display_name,'business_type_id',prior.business_type_id);e.after_value:=jsonb_build_object('name',h.display_name,'business_type_id',h.business_type_id);
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_context_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_context_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'name',h.display_name,'business_type_id',h.business_type_id,'versions',jsonb_build_object('context',h.context_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;


ALTER FUNCTION public.builderad_v2_business_context_write(p_session text, p_business uuid, p_command uuid, p_name text, p_type bigint, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_create_draft(p_session text, p_command uuid, p_name text, p_type bigint DEFAULT NULL::bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint; payload bytea; b public.businesses; h public.business_context_heads; n public.business_current_intents;
 f public.business_authority_fences; c public.migration_cohort_control; m public.migration_cohort_members;
 q public.authority_qualification_receipts; result jsonb; grants jsonb:='{}'; a text; existing public.business_command_receipts; snapshot jsonb;
BEGIN
 SELECT x.actor_user_id INTO actor FROM public.builderad_signed_context() x WHERE x.phase='principal';
 IF session_user<>'builderad_app' OR actor IS NULL OR p_command IS NULL OR p_name IS NULL OR length(btrim(p_name)) NOT BETWEEN 2 AND 120 OR octet_length(p_name)>480
 THEN RAISE EXCEPTION 'Bounded explicit draft command required' USING ERRCODE='22023'; END IF;
 p_name:=regexp_replace(btrim(p_name),'\s+',' ','g');
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('name',p_name,'business_type_id',p_type),jsonb_build_object('business_type_id','I'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.create.'||actor::text||':'||p_command::text,0));
 SELECT * INTO existing FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF existing.actor_user_id<>actor OR existing.action<>'business.draft.create' OR existing.payload_digest<>payload
  THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.lock_business((SELECT public_id FROM public.businesses WHERE id=existing.business_id));
  actor:=builderad_private.principal(p_session,false);
  PERFORM builderad_private.require_business(existing.business_id,actor,'business.context.read',
   jsonb_build_object('realm_kind','direct','requirements',
    jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',existing.result_ref#>'{grant_refs,business.context.read}'))),p_session);
  RETURN existing.result_ref;
 END IF;
 actor:=builderad_private.principal(p_session);
 IF p_type IS NOT NULL THEN
  PERFORM 1 FROM public.business_types WHERE id=p_type AND active FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Selectable Business type required' USING ERRCODE='22023'; END IF;
 END IF;
 b.id:=nextval('public.businesses_id_seq');b.public_id:=uuidv7();b.lifecycle:='declared_draft';b.lifecycle_revision:=0;b.origin_kind:='self_declared_draft';b.created_by:=actor;b.created_at:=clock_timestamp();
 snapshot:=jsonb_build_object('policy','self_declared_draft','actor',actor,'business',b.public_id,'command',p_command);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.draft.create',jsonb_build_object('business_id',b.id),snapshot);
 PERFORM builderad_private.permit('public.businesses','insert',NULL,to_jsonb(b));INSERT INTO public.businesses SELECT (b).*;
 h.business_id:=b.id;h.display_name:=p_name;h.business_type_id:=p_type;h.context_version:=0;
 PERFORM builderad_private.permit('public.business_context_heads','insert',NULL,to_jsonb(h));INSERT INTO public.business_context_heads SELECT (h).*;
 n.business_id:=b.id;n.intent_revision:=0;n.value:=NULL;
 PERFORM builderad_private.permit('public.business_current_intents','insert',NULL,to_jsonb(n));INSERT INTO public.business_current_intents SELECT (n).*;
 f.business_id:=b.id;f.auth_epoch:=0;
 PERFORM builderad_private.permit('public.business_authority_fences','insert',NULL,to_jsonb(f));INSERT INTO public.business_authority_fences SELECT (f).*;
 c.cohort_id:=uuidv7();c.mode:='V2_ACTIVE';c.epoch:=0;c.checkpoint_digest:=NULL;
 PERFORM builderad_private.permit('public.migration_cohort_control','insert',NULL,to_jsonb(c));INSERT INTO public.migration_cohort_control SELECT (c).*;
 m.member_id:=uuidv7();m.cohort_id:=c.cohort_id;m.kind:='business';m.business_id:=b.id;
 PERFORM builderad_private.permit('public.migration_cohort_members','insert',NULL,to_jsonb(m));INSERT INTO public.migration_cohort_members SELECT (m).*;
 q.qualification_id:=uuidv7();q.policy_id:='self_declared_draft';q.policy_version:=1;q.issuer_kind:='self_declared_draft';q.issuer_grant_id:=NULL;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=b.public_id;
 q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage','business.link.manage','business.draft.archive'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=b.created_at;q.valid_until:=NULL;q.provenance:='Own statements only; no legal ownership or external-source authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT (q).*;
 FOREACH a IN ARRAY q.allowed_actions LOOP
  grants:=grants||jsonb_build_object(a,builderad_private.issue_draft_grant(b.id,actor,q.qualification_id,a));
 END LOOP;
 result:=jsonb_build_object('business_public_id',b.public_id,'name',h.display_name,'state',b.lifecycle,'grant_refs',grants,'versions',jsonb_build_object('context',0,'intent',0,'preferences',0));
 PERFORM builderad_private.record_command(b.id,actor,p_command,'business.draft.create',payload,result);
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.finish_command();
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_create_draft(p_session text, p_command uuid, p_name text, p_type bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_grant_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_grantee uuid, p_action text, p_parent bigint, p_grant bigint, p_expected bigint, p_expires timestamp with time zone, p_delegable boolean, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;recipient bigint;proof jsonb;payload bytea;result jsonb;selected bigint;
 parent public.security_action_grants;g public.security_action_grants;old_g public.security_action_grants;q public.authority_qualification_receipts;root_q public.authority_qualification_receipts;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('issue','revoke') OR p_grantee IS NULL OR p_expected IS NULL OR p_expected<0 OR p_delegable IS NULL
 OR p_action IS NULL OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage','business.link.manage','business.draft.archive','business.lifecycle.manage','business.branch.manage','business.data.use','business.purpose.declare')
 THEN RAISE EXCEPTION 'Exact bounded Business grant command required' USING ERRCODE='22023'; END IF;
 IF (p_effect='issue' AND(p_parent IS NULL OR p_grant IS NOT NULL OR p_expected<>0)) OR(p_effect='revoke' AND(p_grant IS NULL OR p_expected<1))
 THEN RAISE EXCEPTION 'Issue needs selected parent; revoke needs exact grant and version' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_grantee;
 IF recipient IS NULL THEN RAISE EXCEPTION 'Qualified recipient required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,p_action,p_selection,p_session,ARRAY[recipient]);
 PERFORM builderad_private.principal(p_session);
 IF jsonb_array_length(p_selection->'requirements')<>1 OR(SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Exact current Business authority required' USING ERRCODE='42501'; END IF;
 selected:=(proof#>>'{grants,0,id}')::bigint;SELECT * INTO STRICT parent FROM public.security_action_grants WHERE id=selected;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('effect',p_effect,'grantee',p_grantee,'action',p_action,'parent',p_parent,'grant',p_grant,'expected',p_expected,'expires',p_expires,'delegable',p_delegable),
 '{"grantee":"U","parent":"I","grant":"I","expected":"I","expires":"T"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.grant.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF p_effect='issue' THEN
  -- Recorded receipts above are historical results; only new issuance is closed.
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path_grant(value)
   WHERE path_grant.value->>'delegation_realm_kind' IS DISTINCT FROM 'direct')
  THEN RAISE EXCEPTION 'Business authority requires a direct qualified path' USING ERRCODE='42501'; END IF;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') agency_item(value) WHERE agency_item.value->>'agency_business_id' IS NOT NULL) THEN RAISE EXCEPTION 'Use exact agency assignment command; ordinary delegation cannot strip agency eligibility' USING ERRCODE='42501'; END IF;
  IF jsonb_array_length(proof->'grants')>=16 OR p_parent<>selected OR NOT parent.delegable OR recipient=actor OR(p_expires IS NOT NULL AND p_expires<=clock_timestamp())
   OR(parent.expires_at IS NOT NULL AND(p_expires IS NULL OR p_expires>parent.expires_at)) THEN RAISE EXCEPTION 'Delegation exceeds current parent' USING ERRCODE='42501'; END IF;
  SELECT * INTO STRICT root_q FROM public.authority_qualification_receipts WHERE qualification_id=parent.qualification_id;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qualification_item(value) WHERE qualification_item.value->>'valid_until' IS NOT NULL AND(p_expires IS NULL OR p_expires>(qualification_item.value->>'valid_until')::timestamptz))
   OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') grant_item(value) WHERE grant_item.value->>'expires_at' IS NOT NULL AND(p_expires IS NULL OR p_expires>(grant_item.value->>'expires_at')::timestamptz))
  THEN RAISE EXCEPTION 'Delegation exceeds complete parent validity window' USING ERRCODE='42501'; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=recipient AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL) THEN RAISE EXCEPTION 'Current recipient required' USING ERRCODE='42501'; END IF;
  IF root_q.policy_id='internal_client_file_admin_v1' AND(root_q.policy_version<>2 OR root_q.issuer_kind<>'qualified_policy'
   OR root_q.evidence_ref IS DISTINCT FROM 'business-custody:'||p_business::text OR parent.parent_grant_id IS NOT NULL
   OR parent.source<>'qualified_policy' OR p_delegable
   OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  THEN RAISE EXCEPTION 'Current direct Business custodian may issue only finite nondelegable staff rights' USING ERRCODE='42501'; END IF;
  IF root_q.policy_id='business_declaration_team_admin_v1' AND(p_delegable
   OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  THEN RAISE EXCEPTION 'Independent staff rights are exact, direct and nondelegable' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.start_command(actor,p_session,p_command,'business.grant.issue',jsonb_build_object('business_id',b),proof);
  q.qualification_id:=uuidv7();q.policy_id:='bounded_parent_delegation';q.policy_version:=1;q.issuer_kind:='existing_grant';q.issuer_grant_id:=parent.id;
  q.subject_user_id:=recipient;q.target_kind:='business';q.target_ref:=p_business;q.allowed_actions:=ARRAY[p_action];q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_expires;q.provenance:='Exact existing parent; qualified realm; no new root authority';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.granted_by:=actor;g.source:='qualified_delegation';g.action:=p_action;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
  g.reason:='Bounded explicit parent delegation';g.status:='active';g.version:=1;g.expires_at:=p_expires;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
  g.qualification_id:=q.qualification_id;g.parent_grant_id:=parent.id;g.delegable:=p_delegable;g.delegation_realm_kind:='direct';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 ELSE
  SELECT * INTO old_g FROM public.security_action_grants WHERE id=p_grant AND business_id=b AND scope_kind='business' FOR UPDATE;
  IF NOT FOUND OR old_g.subject_user_id<>recipient OR old_g.action<>p_action OR (old_g.parent_grant_id IS DISTINCT FROM selected AND NOT(old_g.id=selected AND old_g.subject_user_id=actor)) THEN RAISE EXCEPTION 'Exact issuer or own surrender authority required' USING ERRCODE='42501'; END IF;
  IF old_g.version<>p_expected OR old_g.status<>'active' THEN RAISE EXCEPTION 'Grant changed' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.start_command(actor,p_session,p_command,'business.grant.revoke',jsonb_build_object('business_id',b,'grant_id',p_grant),proof);
  g:=old_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
  UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 END IF;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.principal(p_session);
 -- Surrender is authorized by the locked before-image; every other effect requalifies the unchanged issuer path.
 IF NOT(p_effect='revoke' AND p_grant=selected) THEN PERFORM builderad_private.require_business(b,actor,p_action,p_selection,p_session); END IF;
 result:=jsonb_build_object('business_public_id',p_business,'grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.grant.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_grant_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_grantee uuid, p_action text, p_parent bigint, p_grant bigint, p_expected bigint, p_expires timestamp with time zone, p_delegable boolean, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_history(p_session text, p_business uuid, p_read bigint, p_kind text, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;selection jsonb;items jsonb;more boolean;cursor_value bigint;proof jsonb;
BEGIN
 IF p_kind IS NULL OR p_kind NOT IN('context','markets','intent','preferences') OR p_after IS NULL OR p_after<0 THEN
  RAISE EXCEPTION 'Typed history owner and cursor required' USING ERRCODE='22023';
 END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN
  RAISE EXCEPTION 'Explicit own Business read path required' USING ERRCODE='42501';
 END IF;
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 IF proof#>>'{qualification,policy_id}' IN('business_declaration_team_admin_v1','internal_client_file_admin_v1') THEN RAISE EXCEPTION 'Current declaration team access does not release private history' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.principal(p_session);
 WITH transitions AS (
  SELECT event_id,before_context_version prior,after_context_version revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_context_transitions WHERE business_id=b AND p_kind='context' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_context_version,after_context_version,before_context_version,after_context_version,to_jsonb(before_codes),to_jsonb(after_codes),recorded_at
   FROM public.business_market_transitions WHERE business_id=b AND p_kind='markets' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_revision,after_revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_intent_transitions WHERE business_id=b AND p_kind='intent' AND after_revision>p_after
  UNION ALL
  SELECT event_id,before_version,after_version,NULL::bigint,NULL::bigint,before_value,after_value,recorded_at
   FROM public.business_preference_transitions WHERE business_id=b AND p_kind='preferences' AND after_version>p_after
 ), bounded AS (SELECT * FROM transitions ORDER BY revision LIMIT 33), page AS (SELECT * FROM bounded ORDER BY revision LIMIT 32)
 SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('event_id',event_id,'before_version',prior::text,'version',revision::text,
  'before_context_version',before_context_version::text,'context_version',after_context_version::text,
  'before',before_value,'after',after_value,'recorded_at',recorded_at) ORDER BY revision) FROM page),'[]'::jsonb),
  (SELECT count(*)>32 FROM bounded),(SELECT max(revision) FROM page) INTO items,more,cursor_value;
 -- No I/O that might wait is allowed after the final authority qualification.
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'kind',p_kind,'entries',items,'next_cursor',CASE WHEN more THEN cursor_value::text ELSE NULL END,
  'history_scope','native_business_transitions',
  'page_limit',32);
END $$;


ALTER FUNCTION public.builderad_v2_business_history(p_session text, p_business uuid, p_read bigint, p_kind text, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_intent_write(p_session text, p_business uuid, p_command uuid, p_value jsonb, p_confirmations jsonb, p_context bigint, p_intent bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;snapshot jsonb;payload bytea;result jsonb;h public.business_context_heads;prior_h public.business_context_heads;
 r public.business_current_intents;prior public.business_current_intents;e public.business_intent_transitions;next_value jsonb;changed boolean;
BEGIN
 IF p_context IS NULL OR p_context<0 OR p_intent IS NULL OR p_intent<0 OR coalesce(octet_length(p_value::text),0)+coalesce(octet_length(p_confirmations::text),0)>81920
 THEN RAISE EXCEPTION 'Bounded declaration and expected versions required' USING ERRCODE='22023'; END IF;
 next_value:=public.builderad_current_intent_normalize(nullif(p_value,'null'::jsonb));
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.intent.declare',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO prior_h FROM public.business_context_heads WHERE business_id=b FOR UPDATE;
 SELECT * INTO prior FROM public.business_current_intents WHERE business_id=b FOR UPDATE;
 PERFORM builderad_private.intent_confirmations(prior.value,next_value,p_confirmations);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('value',next_value,'confirmations',p_confirmations,'context',p_context,'intent',p_intent),'{"context":"I","intent":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.intent.declare',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.intent.declare',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.value IS DISTINCT FROM next_value;r:=prior;h:=prior_h;
 IF changed THEN
  IF prior_h.context_version<>p_context OR prior.intent_revision<>p_intent THEN RAISE EXCEPTION 'Current Intent or context changed' USING ERRCODE='40001'; END IF;
  IF next_value->'horizon'<>'null'::jsonb AND next_value->'horizon' IS DISTINCT FROM prior.value->'horizon' THEN
   IF NOT p_confirmations @> '{"horizon":true}' THEN RAISE EXCEPTION 'Deadline and timezone confirmation required' USING ERRCODE='22023'; END IF;
   PERFORM public.builderad_current_intent_validate_horizon(next_value->'horizon');
  END IF;
  r.value:=next_value;r.intent_revision:=prior.intent_revision+1;r.last_event_id:=uuidv7();h.context_version:=prior_h.context_version+1;
  PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior_h),to_jsonb(h));UPDATE public.business_context_heads SET context_version=h.context_version WHERE business_id=b;
  PERFORM builderad_private.permit('public.business_current_intents','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.business_current_intents SET value=r.value,intent_revision=r.intent_revision,last_event_id=r.last_event_id WHERE business_id=b;
  e.event_id:=r.last_event_id;e.business_id:=b;e.before_revision:=prior.intent_revision;e.after_revision:=r.intent_revision;
  e.before_context_version:=prior_h.context_version;e.after_context_version:=h.context_version;e.before_value:=prior.value;e.after_value:=r.value;
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_intent_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_intent_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'value',r.value,'versions',jsonb_build_object('context',h.context_version,'intent',r.intent_revision));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;


ALTER FUNCTION public.builderad_v2_business_intent_write(p_session text, p_business uuid, p_command uuid, p_value jsonb, p_confirmations jsonb, p_context bigint, p_intent bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_lifecycle_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_target uuid, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;action_name text;proof jsonb;payload bytea;result jsonb;prior public.businesses;x public.businesses;
 old_branch public.business_branches;r public.business_branches;changed boolean:=false;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('suspend','archive','restore','branch_create','branch_archive') OR p_expected IS NULL OR p_expected<0 OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object' OR octet_length(p_payload::text)>1024 THEN RAISE EXCEPTION 'Typed lifecycle action required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT * INTO STRICT prior FROM public.businesses WHERE id=b;
 action_name:=CASE WHEN p_effect='archive' AND prior.origin_kind='self_declared_draft' THEN 'business.draft.archive'
  WHEN p_effect IN('branch_create','branch_archive') THEN 'business.branch.manage' ELSE 'business.lifecycle.manage' END;
 IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact lifecycle authority selection required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,action_name,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 IF p_effect='branch_create' THEN
  IF p_target IS NOT NULL OR p_expected<>0 OR p_payload-ARRAY['label']<>'{}'::jsonb OR jsonb_typeof(p_payload->'label') IS DISTINCT FROM 'string'
   OR length(btrim(p_payload->>'label')) NOT BETWEEN 1 AND 120 OR octet_length(p_payload->>'label')>480 THEN RAISE EXCEPTION 'Bounded new branch identity required' USING ERRCODE='22023'; END IF;
 ELSE
  IF p_payload<>'{}'::jsonb OR(p_effect='branch_archive')<>(p_target IS NOT NULL) THEN RAISE EXCEPTION 'Exact lifecycle target required' USING ERRCODE='22023'; END IF;
 END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('effect',p_effect,'target',p_target,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.lifecycle.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 -- The Business fence is held by lock_business. Founder status commands take that
 -- same fence before changing the revision, so this decision cannot race them.
 IF prior.lifecycle='suspended' AND EXISTS (
  SELECT 1 FROM public.customer_administration_events e
  WHERE e.business_id=b AND e.decision='suspend' AND e.new_status='suspended'
   AND e.new_revision=prior.lifecycle_revision
 ) THEN RAISE EXCEPTION 'Founder administrative suspension is current' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.lifecycle.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect IN('branch_create','branch_archive') THEN
  IF prior.lifecycle IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required for branch identity' USING ERRCODE='42501'; END IF;
  IF p_effect='branch_create' THEN
   r.id:=nextval('public.business_branches_id_seq');r.public_id:=uuidv7();r.business_id:=b;r.label:=btrim(p_payload->>'label');r.status:='active';r.revision:=1;
   PERFORM builderad_private.permit('public.business_branches','insert',NULL,to_jsonb(r));INSERT INTO public.business_branches SELECT(r).*;changed:=true;
  ELSE
   SELECT * INTO old_branch FROM public.business_branches WHERE public_id=p_target AND business_id=b FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'Branch identity denied' USING ERRCODE='42501'; END IF;
   IF old_branch.revision<>p_expected THEN RAISE EXCEPTION 'Branch changed' USING ERRCODE='40001'; END IF;r:=old_branch;
   IF r.status<>'archived' THEN
    r.status:='archived';r.revision:=r.revision+1;
    PERFORM builderad_private.permit('public.business_branches','update',to_jsonb(old_branch),to_jsonb(r));
    UPDATE public.business_branches SET status=r.status,revision=r.revision WHERE id=r.id;changed:=true;
   END IF;
  END IF;
  result:=jsonb_build_object('business_public_id',p_business,'branch_public_id',r.public_id,'status',r.status,'versions',jsonb_build_object('branch',r.revision));
 ELSE
  IF prior.lifecycle_revision<>p_expected THEN RAISE EXCEPTION 'Business lifecycle changed' USING ERRCODE='40001'; END IF;
  x:=prior;
  IF action_name='business.draft.archive' AND prior.lifecycle NOT IN('declared_draft','archived') THEN RAISE EXCEPTION 'Private draft archive authority only' USING ERRCODE='42501'; END IF;
  x.lifecycle:=CASE p_effect WHEN 'archive' THEN 'archived' WHEN 'suspend' THEN 'suspended' WHEN 'restore' THEN CASE prior.origin_kind WHEN 'self_declared_draft' THEN 'declared_draft' ELSE 'active' END END;
  changed:=x.lifecycle<>prior.lifecycle;
  IF changed THEN
   x.lifecycle_revision:=prior.lifecycle_revision+1;
   PERFORM builderad_private.permit('public.businesses','update',to_jsonb(prior),to_jsonb(x));UPDATE public.businesses SET lifecycle=x.lifecycle,lifecycle_revision=x.lifecycle_revision WHERE id=b;
  END IF;
  result:=jsonb_build_object('business_public_id',p_business,'state',x.lifecycle,'versions',jsonb_build_object('lifecycle',x.lifecycle_revision));
 END IF;
 IF changed THEN PERFORM builderad_private.advance_business_epoch(b); END IF;
 PERFORM builderad_private.require_scope('business',b::text,actor,action_name,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'business.lifecycle.'||p_effect,payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_lifecycle_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_target uuid, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_markets_write(p_session text, p_business uuid, p_command uuid, p_codes text[], p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE b bigint; actor bigint;snapshot jsonb;payload bytea;result jsonb;h public.business_context_heads;prior public.business_context_heads;
 before_codes text[];after_codes text[];e public.business_market_transitions;r public.business_declared_markets;changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 OR p_codes IS NULL OR coalesce(array_ndims(p_codes),1)<>1 OR cardinality(p_codes)>300 OR array_position(p_codes,NULL) IS NOT NULL
 OR EXISTS(SELECT 1 FROM unnest(p_codes) submitted(code) WHERE submitted.code !~ '^[A-Z]{2}$' OR NOT EXISTS(SELECT 1 FROM public.country_references c WHERE c.code=submitted.code))
 THEN RAISE EXCEPTION 'Canonical country list and version required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(array_agg(DISTINCT code COLLATE "C" ORDER BY code COLLATE "C"),'{}'::text[]) INTO after_codes FROM unnest(p_codes) code;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.markets.declare',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO prior FROM public.business_context_heads WHERE business_id=b FOR UPDATE;h:=prior;
 SELECT coalesce(array_agg(country_code::text ORDER BY country_code COLLATE "C"),'{}'::text[]) INTO before_codes FROM public.business_declared_markets WHERE business_id=b;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('codes',after_codes,'expected',p_expected),'{"expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.markets.declare',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.markets.declare',jsonb_build_object('business_id',b),snapshot);changed:=before_codes<>after_codes;
 IF changed THEN
  IF prior.context_version<>p_expected THEN RAISE EXCEPTION 'Business context changed' USING ERRCODE='40001'; END IF;
  FOR r IN SELECT * FROM public.business_declared_markets WHERE business_id=b LOOP
   PERFORM builderad_private.permit('public.business_declared_markets','delete',to_jsonb(r),NULL);
   DELETE FROM public.business_declared_markets WHERE business_id=b AND country_code=r.country_code;
  END LOOP;
  FOR r IN SELECT b,code::char(2) FROM unnest(after_codes) code LOOP
   PERFORM builderad_private.permit('public.business_declared_markets','insert',NULL,to_jsonb(r));INSERT INTO public.business_declared_markets SELECT(r).*;
  END LOOP;
  h.context_version:=prior.context_version+1;PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior),to_jsonb(h));
  UPDATE public.business_context_heads SET context_version=h.context_version WHERE business_id=b;
  e.event_id:=uuidv7();e.business_id:=b;e.before_context_version:=prior.context_version;e.after_context_version:=h.context_version;
  e.before_codes:=before_codes;e.after_codes:=after_codes;e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_market_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_market_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'codes',after_codes,'versions',jsonb_build_object('context',h.context_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $_$;


ALTER FUNCTION public.builderad_v2_business_markets_write(p_session text, p_business uuid, p_command uuid, p_codes text[], p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_operating_mandate(p_session text, p_business uuid, p_command uuid, p_confirmed boolean, p_capacity text, p_reference text, p_until timestamp with time zone, p_previous bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;proof jsonb;payload bytea;result jsonb;authenticated timestamptz;prior public.security_action_grants;
 q public.authority_qualification_receipts;g public.security_action_grants;action_name text;refs jsonb:='{}';
BEGIN
 IF p_command IS NULL OR p_confirmed IS DISTINCT FROM true OR p_capacity IS NULL OR p_capacity NOT IN('responsible_business','client_mandate')
 OR p_reference IS NULL OR length(btrim(p_reference)) NOT BETWEEN 8 AND 512 OR p_until IS NULL OR NOT isfinite(p_until)
 OR p_previous IS NULL OR p_previous<0 THEN RAISE EXCEPTION 'Explicit bounded Business mandate required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF NOT coalesce(builderad_private.operating_mandate_basis(b,actor,proof),false)
 THEN RAISE EXCEPTION 'Responsible actor must explicitly declare a qualified operating mandate' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') AS deadlines(value)
  WHERE value->>'valid_until' IS NOT NULL AND (value->>'valid_until')::timestamptz<p_until)
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') AS deadlines(value)
  WHERE value->>'expires_at' IS NOT NULL AND (value->>'expires_at')::timestamptz<p_until)
 THEN RAISE EXCEPTION 'The operating mandate cannot outlast its admitted basis' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh independent Business responsibility confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','business_operating_mandate','business',p_business,'capacity',p_capacity,
  'confirmed',p_confirmed,'reference',p_reference,'until',p_until,'previous',p_previous,'selection',p_selection),'{"business":"U","until":"T","previous":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.operating_mandate',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 -- Preserve an exact completed result; neither replay nor a historical link issues a new right.
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path_grant(value)
  WHERE path_grant.value->>'delegation_realm_kind' IS DISTINCT FROM 'direct')
 THEN RAISE EXCEPTION 'Business operating mandates require a direct qualified path' USING ERRCODE='42501'; END IF;
 IF p_until<=clock_timestamp() THEN RAISE EXCEPTION 'Explicit bounded Business mandate required' USING ERRCODE='22023'; END IF;
 SELECT x.* INTO prior FROM public.security_action_grants x JOIN public.authority_qualification_receipts receipt ON receipt.qualification_id=x.qualification_id
  WHERE x.business_id=b AND x.subject_user_id=actor AND x.action='business.data.use' AND receipt.policy_id='business_operating_mandate_v1'
  AND x.delegation_realm_kind=p_selection->>'realm_kind'
  ORDER BY x.id DESC LIMIT 1;
 IF coalesce(prior.id,0)<>p_previous THEN RAISE EXCEPTION 'Reviewed Business mandate changed' USING ERRCODE='40001'; END IF;
 IF prior.id IS NOT NULL AND prior.status='active' AND prior.revoked_at IS NULL AND(prior.expires_at IS NULL OR prior.expires_at>clock_timestamp())
 THEN RAISE EXCEPTION 'Reconcile or explicitly surrender the current mandate' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('mandate_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
  'explicit_operating_mandate',jsonb_build_object('confirmed',p_confirmed,'capacity',p_capacity,'authority_reference',p_reference,'assurance','DECLARED_AUTHORITY','valid_until',p_until));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.operating_mandate',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_operating_mandate_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
 q.issuer_grant_id:=(proof#>>'{grants,0,id}')::bigint;q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.data.use','business.link.manage'];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
 q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;
 q.provenance:='Explicit dated '||p_capacity||' operating mandate; declared assurance only';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 FOREACH action_name IN ARRAY q.allowed_actions LOOP
  g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
  g.reason:='Explicit finite Business operating mandate';g.status:='active';g.version:=1;g.expires_at:=p_until;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:=p_selection->>'realm_kind';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  refs:=refs||jsonb_build_object(action_name,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',refs,'qualification_ref',q.qualification_id,'assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('qualification',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.operating_mandate',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_operating_mandate(p_session text, p_business uuid, p_command uuid, p_confirmed boolean, p_capacity text, p_reference text, p_until timestamp with time zone, p_previous bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_practice_write(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_operation text, p_expected bigint, p_value jsonb, p_selection jsonb, p_read jsonb, p_correction_reason text DEFAULT NULL::text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;prior public.business_practice_revisions;r public.business_practice_revisions;
BEGIN
 IF p_command IS NULL OR p_practice IS NULL OR uuid_extract_version(p_practice) IS DISTINCT FROM 7 OR p_expected IS NULL OR p_expected<0
 OR p_operation IS NULL OR p_operation NOT IN('create','update','correct','reclassify','deactivate') THEN RAISE EXCEPTION 'Exact practice command required' USING ERRCODE='22023'; END IF;
 IF (p_operation='correct' AND (p_correction_reason IS NULL OR length(btrim(p_correction_reason)) NOT BETWEEN 3 AND 1000))
 OR (p_operation<>'correct' AND p_correction_reason IS NOT NULL) THEN RAISE EXCEPTION 'Correction requires an explicit bounded explanation' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 -- Idempotency never grants read access to an earlier declaration/receipt.
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
 payload:=sha256(convert_to(jsonb_build_object('practice',p_practice,'operation',p_operation,'expected',p_expected,'value',p_value,'correction_reason',p_correction_reason)::text,'UTF8'));
 result:=builderad_private.command_replay(b,actor,p_command,'business.practice.write',payload);
 IF result IS NOT NULL THEN
  PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
  PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
  RETURN result||jsonb_build_object('historical_receipt',true,'current_revision',(SELECT max(revision) FROM public.business_practice_revisions WHERE business_id=b AND practice_id=p_practice));
 END IF;
 SELECT * INTO prior FROM public.business_practice_revisions WHERE business_id=b AND practice_id=p_practice ORDER BY revision DESC LIMIT 1;
 IF coalesce(prior.revision,0)<>p_expected OR(p_operation='create')<>(prior.revision IS NULL) OR prior.state='inactive'
 THEN RAISE EXCEPTION 'Practice revision or lifecycle changed' USING ERRCODE='B4090'; END IF;
 IF p_operation='create' AND EXISTS(SELECT 1 FROM public.business_practice_revisions WHERE practice_id=p_practice) THEN RAISE EXCEPTION 'Practice identity already used' USING ERRCODE='40001'; END IF;
 r.business_id:=b;r.practice_id:=p_practice;r.revision:=p_expected+1;r.operation:=p_operation;r.state:=CASE p_operation WHEN 'deactivate' THEN 'inactive' ELSE 'active' END;
 IF p_operation='deactivate' THEN
  IF p_value IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Deactivation preserves declaration' USING ERRCODE='22023'; END IF;
  r.value:=prior.value;
 ELSE r.value:=builderad_private.practice_value(p_value); END IF;
 IF p_operation NOT IN('create','deactivate') THEN
  IF r.value=prior.value THEN RAISE EXCEPTION 'A revision requires an actual change' USING ERRCODE='22023'; END IF;
  IF p_operation='update' AND (r.value->'kind' IS DISTINCT FROM prior.value->'kind'
   OR (r.value-'sectors'-'specializations') IS NOT DISTINCT FROM (prior.value-'sectors'-'specializations'))
  THEN RAISE EXCEPTION 'Use classification or explained correction; a different practice needs its own identity' USING ERRCODE='22023'; END IF;
  IF p_operation='reclassify' AND (r.value-'sectors'-'specializations') IS DISTINCT FROM (prior.value-'sectors'-'specializations')
  THEN RAISE EXCEPTION 'Reclassification preserves the economic practice and its context' USING ERRCODE='22023'; END IF;
  IF p_operation='correct' THEN
   IF r.value->'kind' IS DISTINCT FROM prior.value->'kind' AND (nullif(btrim(prior.value->>'offering'),'') IS NULL OR r.value->'offering' IS DISTINCT FROM prior.value->'offering')
   THEN RAISE EXCEPTION 'Correction must retain an economic identity anchor; create a different practice separately' USING ERRCODE='22023'; END IF;
   r.corrects_revision:=prior.revision;r.correction_reason:=btrim(p_correction_reason);
  END IF;
 END IF;
 r.actor_id:=actor;r.command_id:=p_command;r.recorded_at:=clock_timestamp();
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.practice.write',jsonb_build_object('business_id',b),proof);
 PERFORM builderad_private.permit('public.business_practice_revisions','insert',NULL,to_jsonb(r));INSERT INTO public.business_practice_revisions SELECT(r).*;
 result:=jsonb_build_object('business_public_id',p_business,'practice',builderad_private.practice_projection(r),'historical_receipt',false,'current_revision',r.revision,'versions',jsonb_build_object('practice',r.revision));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.practice.write',payload,result);
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_practice_write(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_operation text, p_expected bigint, p_value jsonb, p_selection jsonb, p_read jsonb, p_correction_reason text) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_practices(p_session text, p_business uuid, p_access bigint, p_practice uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;result jsonb;p_selection jsonb;history_allowed boolean;
BEGIN
 p_selection:=builderad_private.business_selection(p_access);
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 history_allowed:=proof#>>'{qualification,policy_id}'<>'business_declaration_team_admin_v1';
 SELECT jsonb_build_object('business_public_id',p_business,'history_available',history_allowed,'practices',coalesce(jsonb_agg(builderad_private.practice_projection(r) ORDER BY r.practice_id),'[]'::jsonb)) INTO result
 FROM(SELECT DISTINCT ON(practice_id) * FROM public.business_practice_revisions WHERE business_id=b AND(p_practice IS NULL OR practice_id=p_practice) ORDER BY practice_id,revision DESC) r;
 IF p_practice IS NOT NULL AND history_allowed THEN
  result:=result||jsonb_build_object('history',coalesce((SELECT jsonb_agg(builderad_private.practice_projection(r) ORDER BY revision DESC) FROM public.business_practice_revisions r WHERE business_id=b AND practice_id=p_practice),'[]'::jsonb));
 END IF;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_practices(p_session text, p_business uuid, p_access bigint, p_practice uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_preferences_write(p_session text, p_business uuid, p_command uuid, p_value jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;snapshot jsonb;payload bytea;result jsonb;prior public.business_goal_preferences;r public.business_goal_preferences;
 e public.business_preference_transitions;next_value jsonb;current_version bigint;changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Expected preference revision required' USING ERRCODE='22023'; END IF;
 next_value:=public.builderad_advertiser_goals_normalize(p_value);
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.preferences.manage',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.preferences.'||b::text,0));
 SELECT * INTO prior FROM public.business_goal_preferences WHERE business_id=b FOR UPDATE;current_version:=coalesce(prior.preference_version,0);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('value',next_value,'expected',p_expected),'{"expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.preferences.manage',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF current_version<>p_expected THEN RAISE EXCEPTION 'Saved preferences changed' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.preferences.manage',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.value IS DISTINCT FROM next_value;r:=prior;
 IF changed THEN
  r.business_id:=b;r.preference_version:=current_version+1;r.value:=next_value;r.actor_id:=actor;r.updated_at:=clock_timestamp();
  IF prior.business_id IS NULL THEN
   PERFORM builderad_private.permit('public.business_goal_preferences','insert',NULL,to_jsonb(r));INSERT INTO public.business_goal_preferences SELECT(r).*;
  ELSE
   PERFORM builderad_private.permit('public.business_goal_preferences','update',to_jsonb(prior),to_jsonb(r));
   UPDATE public.business_goal_preferences SET preference_version=r.preference_version,value=r.value,actor_id=r.actor_id,updated_at=r.updated_at WHERE business_id=b;
  END IF;
  e.event_id:=uuidv7();e.business_id:=b;e.before_version:=current_version;e.after_version:=r.preference_version;e.before_value:=prior.value;e.after_value:=r.value;
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=r.updated_at;e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_preference_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_preference_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'value',r.value,'versions',jsonb_build_object('preferences',r.preference_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;


ALTER FUNCTION public.builderad_v2_business_preferences_write(p_session text, p_business uuid, p_command uuid, p_value jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_read(p_session text, p_business uuid, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint; actor bigint; snapshot jsonb; result jsonb;
BEGIN
 b:=builderad_private.lock_business(p_business);
 actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.start_command(actor,p_session,uuidv7(),'business.context.read',jsonb_build_object('business_id',b),snapshot);
 SELECT jsonb_build_object('business_public_id',x.public_id,'name',h.display_name,'business_type_id',h.business_type_id,'state',x.lifecycle,
  'current_intent',n.value,'preferences',(SELECT value FROM public.business_goal_preferences WHERE business_id=b),
  'declared_markets',coalesce((SELECT jsonb_agg(country_code::text ORDER BY country_code COLLATE "C") FROM public.business_declared_markets WHERE business_id=b),'[]'::jsonb),'versions',jsonb_build_object('context',h.context_version,'intent',n.intent_revision,
   'preferences',coalesce((SELECT preference_version FROM public.business_goal_preferences WHERE business_id=b),0)))
 INTO result FROM public.businesses x JOIN public.business_context_heads h ON h.business_id=x.id
 JOIN public.business_current_intents n ON n.business_id=x.id WHERE x.id=b;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.finish_command();
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_read(p_session text, p_business uuid, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_surface(p_session text, p_business uuid, p_read bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint; b bigint; read_selection jsonb; selection jsonb; proof jsonb; read_proof jsonb; options jsonb:='[]'; proofs jsonb:='[]'; requirements jsonb;
 g record; footprint jsonb; result jsonb;fact_options jsonb:='{"markets":[],"intent":[],"preferences":[],"purpose":[]}';fact_key text;write_action text;
 binding_options jsonb:='[]';
 delegation_options jsonb:='[]';issued_grants jsonb:='[]';expiry timestamptz;parent_row public.security_action_grants;more_grants boolean:=false;
 bindings jsonb:='[]';bindings_more boolean:=false;archive_options jsonb:='[]';
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 read_selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Explicit own read path required' USING ERRCODE='42501'; END IF;
 requirements:=read_selection->'requirements';
 FOR g IN SELECT * FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND id<>p_read AND scope_kind='business' AND status='active' AND revoked_at IS NULL
 AND delegation_realm_kind=read_selection->>'realm_kind' ORDER BY id LIMIT 31 LOOP
  BEGIN
   selection:=builderad_private.business_selection(g.id);footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 footprint:=builderad_private.authority_footprint(actor,read_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  BEGIN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   proof:=builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
   IF write_action='business.draft.archive' AND EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND origin_kind='self_declared_draft' AND lifecycle='declared_draft') THEN
    -- Discover the existing exact action, never infer archive from context editing.
    archive_options:=archive_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.context.manage' THEN options:=options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.link.manage' THEN
    binding_options:=binding_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action IN('business.markets.declare','business.intent.declare','business.preferences.manage','business.purpose.declare') THEN
    fact_key:=CASE write_action WHEN 'business.markets.declare' THEN 'markets' WHEN 'business.intent.declare' THEN 'intent' WHEN 'business.preferences.manage' THEN 'preferences' WHEN 'business.purpose.declare' THEN 'purpose' END;
    fact_options:=jsonb_set(fact_options,ARRAY[fact_key],(fact_options->fact_key)||jsonb_build_array(jsonb_build_object('grant_id',g.id::text)));
   END IF;
   SELECT * INTO STRICT parent_row FROM public.security_action_grants WHERE id=g.id;
   -- Only the holder's currently qualified paths and their immediate issued children.
   -- This is not a user directory or a grant to read another customer's delegation graph.
   IF parent_row.delegable OR EXISTS(SELECT 1 FROM public.security_action_grants child WHERE child.parent_grant_id=g.id AND child.business_id=b) THEN
    SELECT min(value::timestamptz) INTO expiry FROM(
     SELECT x->>'expires_at' value FROM jsonb_array_elements(proof->'grants') x
     UNION ALL SELECT x->>'valid_until' FROM jsonb_array_elements(proof->'qualifications') x
    ) limits WHERE value IS NOT NULL;
    delegation_options:=delegation_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text,'action',write_action,
     'agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=parent_row.qualification_id),'can_issue',parent_row.delegable AND jsonb_array_length(proof->'grants')<16 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path(value) WHERE path.value->>'delegation_realm_kind'<>'direct') AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qa(value) WHERE qa.value->>'agency_business_id' IS NOT NULL),
     'can_delegate_onward',NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qa(value) WHERE qa.value->>'agency_business_id' IS NOT NULL) AND proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1','business_operating_mandate_v1'),
     'can_choose_agency',false,
     'max_expires_at',expiry,'chain_depth',jsonb_array_length(proof->'grants')));
   END IF;
   proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 SELECT count(*)>64 INTO more_grants FROM(SELECT 1 FROM public.security_action_grants child
 WHERE child.scope_kind='business' AND child.business_id=b AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',child.id::text,'parent_grant_id',child.parent_grant_id::text,'recipient_public_id',u.public_id,
  'action',child.action,'recorded_status',child.status,'revision',child.version,'expires_at',child.expires_at,'delegable',child.delegable,
  'realm_kind',child.delegation_realm_kind,'agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=child.qualification_id)) ORDER BY child.id DESC),'[]'::jsonb) INTO issued_grants
 FROM(SELECT * FROM public.security_action_grants child WHERE child.scope_kind='business' AND child.business_id=b
  AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) ORDER BY child.id DESC LIMIT 64) child
 JOIN public.users u ON u.id=child.subject_user_id;
 IF jsonb_array_length(binding_options)>0 THEN
  WITH latest AS MATERIALIZED(SELECT DISTINCT ON(data_use_scope_id) * FROM public.business_data_use_bindings WHERE business_id=b ORDER BY data_use_scope_id,revision DESC,binding_id DESC),
  bounded AS MATERIALIZED(SELECT *,row_number() OVER(ORDER BY binding_id DESC) position FROM latest ORDER BY binding_id DESC LIMIT 65)
  SELECT coalesce(jsonb_agg(jsonb_build_object('binding_id',binding_id,'scope_id',data_use_scope_id,'state',status,'revision',revision) ORDER BY binding_id DESC) FILTER(WHERE position<=64),'[]'),count(*)>64
   INTO bindings,bindings_more FROM bounded;

 END IF;
 result:=jsonb_build_object('business',public.builderad_v2_business_read(p_session,p_business,read_selection),'authority_selection',read_selection,'agency_business_public_id',(SELECT ab.public_id FROM public.security_action_grants rg JOIN public.authority_qualification_receipts aq ON aq.qualification_id=rg.qualification_id JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE rg.id=p_read),
 'private_history_available',read_proof#>>'{qualification,policy_id}' NOT IN('business_declaration_team_admin_v1','internal_client_file_admin_v1'),
 'agency_task',jsonb_build_object(
  'can_mandate',actor=(SELECT created_by FROM public.businesses WHERE id=b)
   AND EXISTS(SELECT 1 FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    WHERE rg.id=p_read AND rq.policy_id IN('self_declared_draft','business_declaration_team_admin_v1'))
   AND EXISTS(SELECT 1 FROM jsonb_array_elements(delegation_options) opt(value)
    JOIN public.security_action_grants og ON og.id=(opt.value->>'grant_id')::bigint
    JOIN public.authority_qualification_receipts oq ON oq.qualification_id=og.qualification_id
    WHERE opt.value->>'can_issue'='true' AND oq.policy_id='business_declaration_team_admin_v1'),
  'can_assign',EXISTS(SELECT 1 FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    WHERE rg.id=p_read AND rg.delegable AND rq.policy_id='business_agency_mandate_v1' AND rq.agency_business_id IS NOT NULL),
  'agency_business_public_id',(SELECT ab.public_id FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    JOIN public.businesses ab ON ab.id=rq.agency_business_id WHERE rg.id=p_read)),
 'direct_team_enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1' AND target_ref=p_business AND target_kind='business' AND subject_user_id=actor AND status='active'),
 'purpose_statement',jsonb_build_object('authority_scope','own_business_statement_only','establishes_external_authority',false,
  'enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b),
  'previous_grant',coalesce((SELECT max(x.id)::text FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'),'0'),
  'current_grant',(SELECT jsonb_build_object('id',x.id::text,'version',x.version) FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
   WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(fact_options->'purpose') admitted WHERE admitted->>'grant_id'=x.id::text) ORDER BY x.id DESC LIMIT 1)),
 'context_write_options',options,'fact_write_options',fact_options,
 'lifecycle',jsonb_build_object('revision',(SELECT lifecycle_revision FROM public.businesses WHERE id=b),'archive_options',archive_options),
 'data_use',jsonb_build_object('binding_options',binding_options,'bindings',bindings,'bindings_limit',64,'bindings_more',bindings_more),
 'delegation',jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'options',delegation_options,'issued',issued_grants,'issued_limit',64,'issued_more',more_grants,
  'option_limit',32,'options_may_be_partial',jsonb_array_length(requirements)=32),'catalog',public.builderad_v2_business_catalog(p_session));
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(options||(fact_options->'markets')||(fact_options->'intent')||(fact_options->'preferences')||(fact_options->'purpose')||binding_options||delegation_options||archive_options) x WHERE(x->>'grant_id')::bigint=g.id) THEN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   PERFORM builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
  END IF;
 END LOOP;

 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result||jsonb_build_object('scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('result',result,'read',read_proof,'write',proofs)::text,'UTF8')),'hex')));
END $$;


ALTER FUNCTION public.builderad_v2_business_surface(p_session text, p_business uuid, p_read bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_team_change(p_session text, p_business uuid, p_command uuid, p_selection jsonb, p_recipient uuid, p_expected jsonb, p_permissions jsonb, p_expires timestamp with time zone, p_confirmed boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE context jsonb;b bigint;actor bigint;recipient bigint;read_id bigint;root_ids bigint[];desired text[];canonical_expected jsonb;current_grants jsonb;
 payload bytea;result jsonb;refs jsonb:='{}';grant_row public.security_action_grants;action_name text;child_command uuid;issued jsonb;child_proofs jsonb:='[]';retained_proofs jsonb:='[]';retained_proof jsonb;
BEGIN
 IF p_command IS NULL OR p_recipient IS NULL OR p_confirmed IS DISTINCT FROM true OR jsonb_typeof(p_expected) IS DISTINCT FROM 'array'
  OR jsonb_array_length(p_expected)>256 OR jsonb_typeof(p_permissions) IS DISTINCT FROM 'array' OR jsonb_array_length(p_permissions)>5
  OR (p_expires IS NOT NULL AND NOT isfinite(p_expires))
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct'
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1 THEN RAISE EXCEPTION 'Exact confirmed employee change required' USING ERRCODE='22023'; END IF;
 SELECT array_agg(value ORDER BY value COLLATE "C") INTO desired FROM jsonb_array_elements_text(p_permissions);
 IF coalesce(cardinality(desired),0)<> (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(p_permissions))
  OR EXISTS(SELECT 1 FROM unnest(desired) a WHERE a NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  OR(cardinality(desired)>0 AND NOT 'business.context.read'=ANY(desired)) THEN RAISE EXCEPTION 'Exact supported employee permissions required' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_expected) x WHERE jsonb_typeof(x) IS DISTINCT FROM 'object' OR x->>'grant_id' !~ '^[1-9][0-9]{0,17}$' OR x->>'version' !~ '^[1-9][0-9]{0,17}$'
  OR (SELECT count(*) FROM jsonb_object_keys(x))<>2) THEN RAISE EXCEPTION 'Exact employee grant versions required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(jsonb_agg(value ORDER BY (value->>'grant_id')::bigint),'[]') INTO canonical_expected FROM jsonb_array_elements(p_expected);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF recipient IS NULL THEN RAISE EXCEPTION 'Current registered recipient required' USING ERRCODE='42501'; END IF;
 IF coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,18}$' THEN RAISE EXCEPTION 'Exact Business read reference required' USING ERRCODE='22023'; END IF;
 IF (p_selection#>>'{requirements,0,leaf_grant_id}')::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Bounded Business read reference required' USING ERRCODE='22023'; END IF;
 read_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 b:=(context->>'business_id')::bigint;actor:=(context->>'actor')::bigint;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF recipient=actor THEN RAISE EXCEPTION 'Employee must be another account' USING ERRCODE='42501'; END IF;
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'recipient',p_recipient,'expected',canonical_expected,'permissions',coalesce(to_jsonb(desired),'[]'),'expires',p_expires,'confirmed',p_confirmed),'{"business":"U","recipient":"U","expires":"T"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.team.change',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 -- Expiry constrains a new effect, never recovery of its exact recorded result.
 IF p_expires IS NOT NULL AND p_expires<=clock_timestamp() THEN RAISE EXCEPTION 'Future employee permission expiry required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id),'[]') INTO current_grants
 FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=recipient AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business'
  AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND NOT g.delegable
  AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
 IF current_grants IS DISTINCT FROM canonical_expected THEN RAISE EXCEPTION 'Employee permissions changed; review current roster' USING ERRCODE='40001'; END IF;
 IF cardinality(desired)>0 AND NOT EXISTS(SELECT 1 FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id WHERE u.id=recipient AND u.email_verified_at IS NOT NULL AND l.status='current' AND l.principal_class='advertiser') THEN RAISE EXCEPTION 'Current registered employee required' USING ERRCODE='42501'; END IF;
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE id IN(SELECT (x->>'grant_id')::bigint FROM jsonb_array_elements(current_grants) x) ORDER BY id LOOP
  IF grant_row.action=ANY(desired) AND grant_row.expires_at IS NOT DISTINCT FROM p_expires AND NOT refs ? grant_row.action THEN
   retained_proof:=NULL;
   BEGIN
    retained_proof:=builderad_private.verify_scope_path('business',b::text,recipient,grant_row.action,builderad_private.business_selection(grant_row.id),'{}','{}',false);
   EXCEPTION WHEN insufficient_privilege THEN retained_proof:=NULL;END;
   IF retained_proof IS NOT NULL THEN
    refs:=refs||jsonb_build_object(grant_row.action,grant_row.id::text);retained_proofs:=retained_proofs||jsonb_build_array(retained_proof);CONTINUE;
   END IF;
  END IF;
  child_command:=md5(p_command::text||':staff:revoke:'||grant_row.id::text)::uuid;
  PERFORM public.builderad_v2_business_grant_command(p_session,p_business,child_command,'revoke',p_recipient,grant_row.action,NULL,grant_row.id,grant_row.version,NULL,false,builderad_private.business_selection(grant_row.parent_grant_id));
  -- A child command has already consumed/cleared every permit and finalized its
  -- receipt. Release only this actor's completed ephemeral transaction slot so
  -- the next canonical child can run. Never replace an authorized invocation.
  child_proofs:=child_proofs||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
 END LOOP;
 FOREACH action_name IN ARRAY coalesce(desired,'{}') LOOP
  IF refs ? action_name THEN CONTINUE;END IF;
  child_command:=md5(p_command::text||':staff:issue:'||action_name)::uuid;
  issued:=public.builderad_v2_business_grant_command(p_session,p_business,child_command,'issue',p_recipient,action_name,(context->'roots'->>action_name)::bigint,NULL,0,p_expires,false,builderad_private.business_selection((context->'roots'->>action_name)::bigint));
  child_proofs:=child_proofs||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
  refs:=refs||jsonb_build_object(action_name,issued->>'grant_id');
 END LOOP;
 -- Fresh owner proof only: target history/expired grants are not prerequisites.
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.team.change',jsonb_build_object('business_id',b),
  (context->'proof')||jsonb_build_object('completed_children',child_proofs,'retained_staff',retained_proofs,
   'requested_staff_window',CASE WHEN cardinality(desired)>0 THEN jsonb_build_object('expires_at',p_expires) ELSE '{}'::jsonb END));
 result:=jsonb_build_object('business_public_id',p_business,'recipient_public_id',p_recipient,'status','recorded','grant_refs',refs,
  'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.team.change',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_business_team_change(p_session text, p_business uuid, p_command uuid, p_selection jsonb, p_recipient uuid, p_expected jsonb, p_permissions jsonb, p_expires timestamp with time zone, p_confirmed boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_team_enroll(p_session text, p_business uuid, p_command uuid, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;q public.authority_qualification_receipts;
 g public.security_action_grants;action_name text;refs jsonb:='{}';authenticated timestamptz;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Exact enrollment command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF actor<>(SELECT created_by FROM public.businesses WHERE id=b) OR p_selection->>'realm_kind'<>'direct'
 OR proof#>>'{qualification,policy_id}'<>'self_declared_draft' OR proof#>>'{qualification,policy_version}'<>'1'
 OR proof#>>'{qualification,issuer_kind}'<>'self_declared_draft'
 THEN RAISE EXCEPTION 'Current original creator declaration basis required' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh independent team confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business),'{"business":"U"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.direct_team.enroll',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1'
  AND target_kind='business' AND target_ref=p_business AND subject_user_id=actor AND status='active')
 THEN RAISE EXCEPTION 'Existing team enrollment must be reconciled, not replaced' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.direct_team.enroll',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_declaration_team_admin_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();
 q.provenance:='Original creator explicit independent declaration-team administration; no external mandate, private history, source or customer-data rights';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 FOREACH action_name IN ARRAY q.allowed_actions LOOP
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
  g.reason:='Explicit independent Business team';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:='direct';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  refs:=refs||jsonb_build_object(action_name,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',refs,'qualification_ref',q.qualification_id,
  'versions',jsonb_build_object('qualification',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.direct_team.enroll',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_team_enroll(p_session text, p_business uuid, p_command uuid, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_team_recipient(p_session text, p_business uuid, p_read bigint, p_email text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE context jsonb;recipient record;
BEGIN
 IF p_email IS NULL OR length(p_email)>254 OR btrim(p_email)='' THEN RAISE EXCEPTION 'Exact registered email required' USING ERRCODE='22023'; END IF;
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 IF (SELECT count(*) FROM public.users u WHERE lower(u.email)=lower(btrim(p_email)))<>1 THEN RAISE EXCEPTION 'Unambiguous registered recipient required' USING ERRCODE='42501'; END IF;
 SELECT u.id,u.public_id,u.name,u.email INTO recipient FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
  WHERE lower(u.email)=lower(btrim(p_email)) AND u.id<>(context->>'actor')::bigint AND u.email_verified_at IS NOT NULL
   AND l.status='current' AND l.principal_class='advertiser';
 IF NOT FOUND THEN RAISE EXCEPTION 'Current registered recipient required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'recipient',jsonb_build_object('public_id',recipient.public_id,'name',recipient.name,'email',recipient.email));
END $$;


ALTER FUNCTION public.builderad_v2_business_team_recipient(p_session text, p_business uuid, p_read bigint, p_email text) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_team_surface(p_session text, p_business uuid, p_read bigint, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE context jsonb;members jsonb:='[]';member record;grants jsonb;root_ids bigint[];next_cursor bigint;seen integer:=0;last_id bigint;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded team cursor required' USING ERRCODE='22023'; END IF;
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 FOR member IN SELECT DISTINCT u.id,u.public_id,u.name,u.email FROM public.users u JOIN public.security_action_grants g ON g.subject_user_id=u.id
  JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE u.id>p_after AND g.business_id=(context->>'business_id')::bigint AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business'
   AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND NOT g.delegable
   AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL ORDER BY u.id LIMIT 65 LOOP
  seen:=seen+1;IF seen>64 THEN next_cursor:=last_id;EXIT;END IF;
  SELECT jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version,'action',g.action,'expires_at',g.expires_at) ORDER BY g.id) INTO grants
   FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   WHERE g.business_id=(context->>'business_id')::bigint AND g.subject_user_id=member.id AND g.parent_grant_id=ANY(root_ids)
    AND g.scope_kind='business' AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND NOT g.delegable
    AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
  members:=members||jsonb_build_array(jsonb_build_object('public_id',member.public_id,'name',member.name,'email',member.email,'grants',grants));last_id:=member.id;
 END LOOP;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'members',members,'next_cursor',next_cursor,
  'actions',jsonb_build_array('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'));
END $$;


ALTER FUNCTION public.builderad_v2_business_team_surface(p_session text, p_business uuid, p_read bigint, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_cohort_transition(p_command uuid, p_cohort uuid, p_mode text, p_epoch bigint, p_requested text, p_checkpoint bytea) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.migration_cohort_control;c public.migration_cohort_control;payload bytea;result jsonb;r public.business_command_receipts;
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Deployment operator only' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_cohort IS NULL OR p_epoch IS NULL OR p_epoch<0 OR p_mode IS NULL OR p_requested IS NULL OR p_checkpoint IS NULL OR octet_length(p_checkpoint)<>32
 OR p_requested NOT IN('V2_ACTIVE','RECOVERY_HOLD') THEN RAISE EXCEPTION 'Exact maintenance transition and checkpoint required' USING ERRCODE='22023'; END IF;
 PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=p_cohort FOR UPDATE;
 SELECT * INTO prior FROM public.migration_cohort_control WHERE cohort_id=p_cohort;
 IF NOT FOUND THEN RAISE EXCEPTION 'Unknown deployment cohort' USING ERRCODE='22023'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('cohort',p_cohort,'mode',p_mode,'epoch',p_epoch,'requested',p_requested,'checkpoint',p_checkpoint),'{"cohort":"U","epoch":"I","checkpoint":"X"}');
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF r.actor_user_id IS NOT NULL OR r.action<>'maintenance.cohort.transition' OR r.payload_digest<>payload THEN RAISE EXCEPTION 'Maintenance command identity conflict' USING ERRCODE='40001'; END IF;
  RETURN r.result_ref;
 END IF;
 IF prior.mode<>p_mode OR prior.epoch<>p_epoch THEN RAISE EXCEPTION 'Cohort changed; reconcile authoritative state' USING ERRCODE='40001'; END IF;
 IF NOT((prior.mode='V2_ACTIVE' AND p_requested='RECOVERY_HOLD') OR(prior.mode='RECOVERY_HOLD' AND p_requested='V2_ACTIVE')) THEN RAISE EXCEPTION 'Forbidden Business recovery transition' USING ERRCODE='42501'; END IF;
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.cohort.transition',
  jsonb_build_object('cohort_id',p_cohort),jsonb_build_object('operator_role',session_user),builderad_private.authority_digest(jsonb_build_object('operator_role',session_user)),
  builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 c:=prior;c.mode:=p_requested;c.epoch:=prior.epoch+1;c.checkpoint_digest:=p_checkpoint;
 PERFORM builderad_private.permit('public.migration_cohort_control','update',to_jsonb(prior),to_jsonb(c));
 UPDATE public.migration_cohort_control SET mode=c.mode,epoch=c.epoch,checkpoint_digest=c.checkpoint_digest WHERE cohort_id=p_cohort;
 result:=jsonb_build_object('cohort_id',p_cohort,'mode',c.mode,'epoch',c.epoch,'checkpoint',encode(c.checkpoint_digest,'hex'));
 r.command_id:=p_command;r.business_id:=NULL;r.actor_user_id:=NULL;r.generation:=0;r.action:='maintenance.cohort.transition';r.payload_digest:=payload;r.state:='committed';r.result_ref:=result;
 r.returned_versions:=jsonb_build_object('epoch',c.epoch);r.recorded_at:=clock_timestamp();r.authority_selection_digest:=builderad_private.authority_digest(jsonb_build_object('operator_role',session_user));r.dependency_digest:=builderad_private.digest('dependency-set:1','[]'::jsonb);
 PERFORM builderad_private.permit('public.business_command_receipts','insert',NULL,to_jsonb(r));INSERT INTO public.business_command_receipts SELECT(r).*;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_cohort_transition(p_command uuid, p_cohort uuid, p_mode text, p_epoch bigint, p_requested text, p_checkpoint bytea) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_internal_team_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_recipient uuid, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT builderad_private.business_file_custody_command(p_session,p_business,p_command,p_effect,p_recipient,p_expected,p_selection)
$$;

ALTER FUNCTION public.builderad_v2_internal_team_command(p_session text, p_business uuid, p_command uuid, p_effect text, p_recipient uuid, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_internal_team_surface(p_session text, p_business uuid, p_read bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;selection jsonb;creator_basis boolean;can_admin boolean;can_appoint boolean;custodians jsonb;g public.security_action_grants;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_read;
 creator_basis:=proof#>>'{qualification,policy_id}'='self_declared_draft' AND proof#>>'{qualification,policy_version}'='1'
  AND proof#>>'{qualification,issuer_kind}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b);
 can_admin:=creator_basis OR(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND g.parent_grant_id IS NULL AND g.delegable);
 can_appoint:=creator_basis OR(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND proof#>>'{qualification,policy_version}'='2' AND selection->>'realm_kind'='direct' AND g.parent_grant_id IS NULL AND g.delegable);
 SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',u.public_id,'name',u.name,'email',u.email,'qualification_ref',q.qualification_id,'status','recorded',
  'grant_refs',(SELECT jsonb_object_agg(sg.action,sg.id::text) FROM public.security_action_grants sg WHERE sg.qualification_id=q.qualification_id AND sg.status='active' AND sg.revoked_at IS NULL)) ORDER BY u.id),'[]') INTO custodians
 FROM public.authority_qualification_receipts q JOIN public.users u ON u.id=q.subject_user_id
 WHERE can_appoint AND q.target_kind='business' AND q.target_ref=p_business AND q.policy_id='internal_client_file_admin_v1' AND q.policy_version=2
  AND q.evidence_ref='business-custody:'||p_business::text AND q.status='active';
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'creator_basis',creator_basis,'can_admin',can_admin,'can_appoint',can_appoint,'custodians',custodians,'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
END $$;


ALTER FUNCTION public.builderad_v2_internal_team_surface(p_session text, p_business uuid, p_read bigint) OWNER TO builderad_scope_executor;
