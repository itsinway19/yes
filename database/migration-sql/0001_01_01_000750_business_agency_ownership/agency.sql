CREATE FUNCTION builderad_private.business_agency_eligibility(p_agency bigint,p_subject bigint,p_membership bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE g public.security_action_grants;proof jsonb;
BEGIN
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_membership;
 IF NOT FOUND OR g.business_id IS DISTINCT FROM p_agency OR g.subject_user_id IS DISTINCT FROM p_subject
  OR g.scope_kind<>'business' OR g.action<>'business.context.read' OR g.delegation_realm_kind<>'direct' OR g.delegation_workspace_id IS NOT NULL
  OR EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id AND agency_business_id IS NOT NULL)
  OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=p_agency AND lifecycle NOT IN('suspended','archived'))
  OR NOT EXISTS(SELECT 1 FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
   WHERE m.kind='business' AND m.business_id=p_agency AND c.mode='V2_ACTIVE')
 THEN RAISE EXCEPTION 'Current independent agency employee eligibility required' USING ERRCODE='42501'; END IF;
 -- The only admitted forms are original agency creator or its explicitly assigned
 -- direct employee. Incidental read access and another client mandate do not qualify.
 IF NOT((g.source='business_draft_creation' AND g.parent_grant_id IS NULL
   AND p_subject=(SELECT created_by FROM public.businesses WHERE id=p_agency))
  OR(g.source='qualified_delegation' AND NOT g.delegable AND EXISTS(
   SELECT 1 FROM public.security_action_grants parent JOIN public.authority_qualification_receipts q ON q.qualification_id=parent.qualification_id
   WHERE parent.id=g.parent_grant_id AND parent.business_id=p_agency AND parent.parent_grant_id IS NULL
    AND parent.action='business.context.read' AND parent.subject_user_id=(SELECT created_by FROM public.businesses WHERE id=p_agency)
    AND q.policy_id='business_declaration_team_admin_v1' AND q.agency_business_id IS NULL)))
 THEN RAISE EXCEPTION 'Exact Business team eligibility policy required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.verify_scope_path('business',p_agency::text,p_subject,'business.context.read',
  builderad_private.business_selection(p_membership),NULL,'{}','{}',false);
 IF proof#>>'{qualification,policy_id}' NOT IN('self_declared_draft','business_declaration_team_admin_v1')
 THEN RAISE EXCEPTION 'Agency employee cannot inherit eligibility through a client' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('agency_control',(SELECT jsonb_build_object('cohort_id',c.cohort_id,'epoch',c.epoch,'mode',c.mode)
  FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id WHERE m.kind='business' AND m.business_id=p_agency));
END $$;

CREATE FUNCTION builderad_private.lock_agency_command(p_actor bigint,p_selection jsonb,p_session text,p_recipient bigint,p_membership bigint,p_agency bigint) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE own_set jsonb;employee_set jsonb;combined jsonb:='{}';key_name text;
BEGIN
 own_set:=builderad_private.authority_footprint(p_actor,p_selection,ARRAY[p_recipient]);
 employee_set:=builderad_private.authority_footprint(p_recipient,builderad_private.business_selection(p_membership));
 FOREACH key_name IN ARRAY ARRAY['users','workspaces','grants','qualifications','agency_businesses'] LOOP
  combined:=combined||jsonb_build_object(key_name,coalesce(own_set->key_name,'[]'::jsonb)||coalesce(employee_set->key_name,'[]'::jsonb));
 END LOOP;
 combined:=jsonb_set(combined,'{agency_businesses}',(combined->'agency_businesses')||jsonb_build_array(p_agency));
 PERFORM builderad_private.lock_authority_rows(p_actor,p_session,combined);
 IF own_set IS DISTINCT FROM builderad_private.authority_footprint(p_actor,p_selection,ARRAY[p_recipient])
  OR employee_set IS DISTINCT FROM builderad_private.authority_footprint(p_recipient,builderad_private.business_selection(p_membership))
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: agency eligibility changed' USING ERRCODE='40001'; END IF;
END $$;

CREATE FUNCTION builderad_v2_business_agency_grant(p_session text,p_business uuid,p_command uuid,p_effect text,p_agency uuid,
 p_recipient uuid,p_membership bigint,p_action text,p_parent bigint,p_expires timestamptz,p_confirmed boolean,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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
REVOKE ALL ON FUNCTION builderad_private.business_agency_eligibility(bigint,bigint,bigint) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_private.lock_agency_command(bigint,jsonb,text,bigint,bigint,bigint) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_v2_business_agency_grant(text,uuid,uuid,text,uuid,uuid,bigint,text,bigint,timestamptz,boolean,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_agency_grant(text,uuid,uuid,text,uuid,uuid,bigint,text,bigint,timestamptz,boolean,jsonb) TO builderad_app;
