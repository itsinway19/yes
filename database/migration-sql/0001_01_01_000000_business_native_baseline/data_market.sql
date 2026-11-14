-- Independent data licences, beneficiary rights, and platform-market operations.

CREATE FUNCTION builderad_private.business_finding_continuation(p_session text, p_actor bigint, p_refs jsonb, p_purpose text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE req jsonb;g public.security_action_grants;s public.data_use_scopes;proof jsonb;policy jsonb;rights jsonb:='[]';ref jsonb;op text;matches integer;
BEGIN
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' THEN RAISE EXCEPTION 'Explicit direct continuation licence required' USING ERRCODE='42501'; END IF;
 FOR req IN SELECT value FROM jsonb_array_elements(p_selection->'requirements') LOOP
  IF req->>'requirement_kind' IS DISTINCT FROM 'data_use' THEN RAISE EXCEPTION 'Exact continuation data-use requirements' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(req->>'leaf_grant_id')::bigint;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=g.data_use_scope_id;
  IF s.scope_id IS NULL OR s.resource IS DISTINCT FROM 'market.evidence' OR s.operation NOT IN('forward','retain') OR s.purpose IS DISTINCT FROM p_purpose
   OR g.parent_grant_id IS NOT NULL OR g.source IS DISTINCT FROM 'qualified_policy' THEN RAISE EXCEPTION 'Qualified non-delegated continuation licence required' USING ERRCODE='42501'; END IF;
  policy:=builderad_private.market_policy(s.scope_id);
  proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.'||s.operation,p_selection,p_session);
  IF proof#>>'{qualification,policy_id}' IS DISTINCT FROM policy->>'policy_id' OR proof#>'{qualification,policy_version}' IS DISTINCT FROM policy->'policy_version'
   OR(s.operation='retain' AND(g.expires_at IS NOT NULL OR proof#>>'{qualification,valid_until}' IS NOT NULL OR policy->>'valid_until' IS NOT NULL))
  THEN RAISE EXCEPTION 'Unsupported continuing retention contract' USING ERRCODE='42501'; END IF;
  rights:=rights||jsonb_build_array(jsonb_build_object('grant_row',to_jsonb(g),'qualification_row',proof->'qualification','scope',to_jsonb(s),'policy',policy));
 END LOOP;
 FOR ref IN SELECT value FROM jsonb_array_elements(p_refs) LOOP
  FOREACH op IN ARRAY ARRAY['forward','retain'] LOOP
   SELECT count(*) INTO matches FROM jsonb_array_elements(rights) v WHERE v#>>'{scope,operation}'=op
    AND(v#>'{scope,selection,native_keys}') ? (ref->>'key') AND v#>>'{scope,source_id}'=(builderad_private.market_evidence_resolve(ref)#>>'{origin,source_id}');
   IF matches<>1 THEN RAISE EXCEPTION 'Complete continuing licence per original required' USING ERRCODE='42501'; END IF;
  END LOOP;
 END LOOP;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(rights) v WHERE NOT EXISTS(SELECT 1 FROM jsonb_array_elements(p_refs) selected_ref WHERE(v#>'{scope,selection,native_keys}') ? (selected_ref->>'key'))) THEN RAISE EXCEPTION 'No unrelated continuation licence' USING ERRCODE='42501'; END IF;
 RETURN rights;
END $$;


ALTER FUNCTION builderad_private.business_finding_continuation(p_session text, p_actor bigint, p_refs jsonb, p_purpose text, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.data_use_right_command(p_session text, p_command uuid, p_scope uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE s public.data_use_scopes;gate public.resource_right_fences;gate_count integer:=0;boundary_gate uuid;prior public.security_action_grants;g public.security_action_grants;
 actor bigint;proof jsonb;selected bigint;payload bytea;result jsonb;
BEGIN
 IF p_effect IS DISTINCT FROM 'revoke' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
 OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$' OR p_expected<1 THEN RAISE EXCEPTION 'Exact data-use revoke command required' USING ERRCODE='22023'; END IF;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants candidate ON candidate.subject_user_id=l.user_id AND candidate.subject_generation=l.generation
  WHERE c.phase='principal' AND candidate.scope_kind='data_use' AND candidate.data_use_scope_id=p_scope
  AND candidate.status='active' AND candidate.revoked_at IS NULL AND(candidate.expires_at IS NULL OR candidate.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Current data-use issuer required' USING ERRCODE='42501'; END IF;
 -- Choose the final lock mode before waiting: no SHARE->UPDATE conversion and no late expansion.
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND s.rights_domain='client' AND boundary_id=s.boundary_id) OR(kind='data_use' AND s.rights_domain='market' AND data_use_scope_id=s.scope_id) ORDER BY fence_id LOOP
  IF gate.kind IN('client_boundary','data_use') THEN
   PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;boundary_gate:=gate.fence_id;
  ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  gate_count:=gate_count+1;
 END LOOP;
 IF gate_count<>2 THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact selected data-use issuer required' USING ERRCODE='42501'; END IF;
 selected:=(proof#>>'{grants,0,id}')::bigint;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','data_use','target',p_scope,'effect',p_effect,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.revoke' AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 SELECT * INTO prior FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='data_use' AND data_use_scope_id=p_scope FOR UPDATE;
 IF NOT FOUND OR prior.action<>'data_use.'||s.operation OR(prior.parent_grant_id IS DISTINCT FROM selected AND NOT(prior.id=selected AND prior.subject_user_id=actor)) THEN RAISE EXCEPTION 'Exact data-use issuer or own surrender required' USING ERRCODE='42501'; END IF;
 IF prior.version<>p_expected OR prior.status<>'active' THEN RAISE EXCEPTION 'Data-use grant changed' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR(s.rights_domain='client' AND f.boundary_id=s.boundary_id) OR(s.rights_domain='market' AND f.data_use_scope_id=s.scope_id)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.revoke',jsonb_build_object('scope_id',p_scope,'grant_id',prior.id),proof);
 g:=prior;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_right_epoch(boundary_gate);PERFORM builderad_private.principal(p_session);
 IF prior.id<>selected THEN PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session); END IF;
 result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.revoke',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION builderad_private.data_use_right_command(p_session text, p_command uuid, p_scope uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.guard_operating_beneficiary_delegation() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE root_grant record;
BEGIN
 IF NEW.scope_kind='business' AND NEW.parent_grant_id IS NOT NULL THEN
  -- A child of this policy is never delegable, so only its immediate parent can issue it.
  SELECT q.policy_id,g.delegation_realm_kind INTO root_grant
  FROM public.security_action_grants g JOIN public.authority_qualification_receipts q USING(qualification_id)
  WHERE g.id=NEW.parent_grant_id;
  IF root_grant.policy_id='business_operating_mandate_v1' AND(NEW.delegable
   OR NEW.delegation_realm_kind IS DISTINCT FROM root_grant.delegation_realm_kind)
  THEN RAISE EXCEPTION 'Operating beneficiaries must be nondelegable and stay in the admitted realm' USING ERRCODE='42501';END IF;
 END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.guard_operating_beneficiary_delegation() OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.knowledge_inputs(p_session text, p_action text, p_refs jsonb, p_purpose text, p_selection jsonb, p_additional_selection jsonb DEFAULT NULL::jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE ref jsonb;proof jsonb;right_value jsonb;s public.data_use_scopes;materials jsonb:='[]';reads jsonb:='[]';m jsonb;fields jsonb;matches integer;f record;all_selection jsonb;actor bigint;
BEGIN
 IF p_refs IS NULL OR jsonb_typeof(p_refs) IS DISTINCT FROM 'array' OR jsonb_array_length(p_refs) NOT BETWEEN 1 AND 2 OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128 THEN RAISE EXCEPTION 'Bounded explicit market inputs required' USING ERRCODE='22023'; END IF;
 FOR ref IN SELECT value FROM jsonb_array_elements(p_refs) LOOP
  PERFORM builderad_private.material_reference(ref);
  IF ref->>'namespace' IS DISTINCT FROM 'market.evidence' OR coalesce(ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR coalesce(ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact market original reference required' USING ERRCODE='22023'; END IF;
 END LOOP;
 PERFORM builderad_private.bounded(p_selection);
 all_selection:=p_selection;
 IF p_additional_selection IS NOT NULL THEN
  all_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(v ORDER BY v::text) FROM(SELECT DISTINCT value v FROM jsonb_array_elements((p_selection->'requirements')||(p_additional_selection->'requirements'))) x));
 END IF;
 -- Discover only the selected exact resource footprint before K4. Metadata lookup does not deliver evidence.
 IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 16 THEN RAISE EXCEPTION 'Explicit rights required' USING ERRCODE='42501'; END IF;
 -- Nonlocking preparation must reject a foreign leaf/reference before entering any resource lock.
 actor:=builderad_private.market_operator(p_session,p_action,false);
 PERFORM builderad_private.authority_footprint(actor,all_selection);
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(all_selection->'requirements') req WHERE req->>'requirement_kind' IS DISTINCT FROM 'data_use') THEN RAISE EXCEPTION 'Only selected market use roots are admitted' USING ERRCODE='42501'; END IF;
 FOR ref IN SELECT value FROM jsonb_array_elements(p_refs) LOOP
  m:=builderad_private.market_evidence_resolve(ref);
  SELECT count(*) INTO matches FROM public.security_action_grants g JOIN public.data_use_scopes ds ON ds.scope_id=g.data_use_scope_id
   JOIN jsonb_array_elements(p_selection->'requirements') req ON req->>'leaf_grant_id'=g.id::text
   WHERE g.subject_user_id=actor AND g.scope_kind='data_use' AND g.action='data_use.process' AND ds.operation='process' AND ds.resource='market.evidence'
    AND ds.rights_domain='market' AND ds.source_id=(m#>>'{origin,source_id}')::uuid AND ds.purpose=p_purpose AND ds.selection->'native_keys' ? (ref->>'key');
  IF matches<>1 THEN RAISE EXCEPTION 'Own exact original processing path required before resource locking' USING ERRCODE='42501'; END IF;
 END LOOP;
 FOR f IN SELECT rf.fence_id,rf.adoption_state FROM public.resource_right_fences rf WHERE
 (rf.kind='source' AND rf.source_id IN(SELECT ds.source_id FROM public.data_use_scopes ds JOIN public.security_action_grants g ON g.data_use_scope_id=ds.scope_id
 JOIN jsonb_array_elements(all_selection->'requirements') req ON req->>'leaf_grant_id'=g.id::text))
 OR(rf.kind='data_use' AND rf.data_use_scope_id IN(SELECT g.data_use_scope_id FROM public.security_action_grants g
 JOIN jsonb_array_elements(all_selection->'requirements') req ON req->>'leaf_grant_id'=g.id::text)) ORDER BY rf.fence_id FOR SHARE LOOP
  IF f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'Qualified market resource fence required' USING ERRCODE='42501'; END IF;
 END LOOP;
 -- Original heads are K2 inputs: freeze their complete sorted set before any authority lock.
 PERFORM 1 FROM public.market_evidence_heads WHERE evidence_id IN(SELECT (value->>'key')::uuid FROM jsonb_array_elements(p_refs) WHERE value->>'namespace'='market.evidence') ORDER BY evidence_id FOR SHARE;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal';
 IF actor IS NULL OR session_user<>'builderad_app' THEN RAISE EXCEPTION 'Signed market operator required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,all_selection,p_session);
 proof:=builderad_private.market_operator_authorize(p_session,p_action,p_selection);
 FOR ref IN SELECT value FROM jsonb_array_elements(p_refs) LOOP
  PERFORM builderad_private.material_reference(ref);
  IF ref->>'namespace' IS DISTINCT FROM 'market.evidence' THEN RAISE EXCEPTION 'Shared capture accepts qualified original market evidence' USING ERRCODE='42501'; END IF;
  m:=builderad_private.market_evidence_resolve(ref);
  SELECT count(*) INTO matches FROM jsonb_array_elements(proof->'rights') v WHERE v#>>'{scope,operation}'='process' AND v#>>'{scope,purpose}'=p_purpose
   AND v#>>'{scope,resource}'='market.evidence' AND v#>>'{scope,source_id}'=m#>>'{origin,source_id}' AND(v#>'{scope,selection,native_keys}') ? (ref->>'key');
  IF matches<>1 THEN RAISE EXCEPTION 'Exactly one purpose-qualified process right per input required' USING ERRCODE='42501'; END IF;
  SELECT value INTO right_value FROM jsonb_array_elements(proof->'rights') v(value) WHERE value#>>'{scope,operation}'='process' AND value#>>'{scope,purpose}'=p_purpose
   AND value#>>'{scope,resource}'='market.evidence' AND value#>>'{scope,source_id}'=m#>>'{origin,source_id}' AND(value#>'{scope,selection,native_keys}') ? (ref->>'key');
  SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(right_value#>>'{scope,scope_id}')::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=s.source_id AND adoption_state='v2_adopted')
   OR NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='data_use' AND data_use_scope_id=s.scope_id AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'Complete adopted source and use gates required' USING ERRCODE='42501'; END IF;
  fields:=builderad_private.market_evidence_fields(ref,s.selection->'field_keys','process');
  IF fields IS NULL OR jsonb_typeof(fields) IS DISTINCT FROM 'object' OR(SELECT count(*) FROM jsonb_object_keys(fields))<>jsonb_array_length(s.selection->'field_keys') THEN RAISE EXCEPTION 'Exact licensed input fields unavailable' USING ERRCODE='42501'; END IF;
  materials:=materials||jsonb_build_array(jsonb_build_object('ref',ref,'fields',fields,'evidence',m#>'{origin,evidence}'));
  reads:=reads||jsonb_build_array(jsonb_build_object('input',ref,'data_use_scope_id',s.scope_id,'policy_id',right_value#>>'{policy,policy_id}',
    'policy_version',(right_value#>>'{policy,policy_version}')::bigint,'field_keys',s.selection->'field_keys'));
 END LOOP;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'rights') v WHERE v#>>'{scope,operation}'<>'process'
 OR NOT EXISTS(SELECT 1 FROM jsonb_array_elements(reads) e WHERE e->'data_use_scope_id'=v#>'{scope,scope_id}')) THEN RAISE EXCEPTION 'No unrelated rights in capture selection' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('materials',materials,'reads',reads,'proof',proof,'digest',encode(sha256(convert_to(materials::text,'UTF8')),'hex'));
END $_$;


ALTER FUNCTION builderad_private.knowledge_inputs(p_session text, p_action text, p_refs jsonb, p_purpose text, p_selection jsonb, p_additional_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.knowledge_publication_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_knowledge_captures;inputs jsonb;proof jsonb;entry jsonb;op text;right_value jsonb;matches integer;
BEGIN
 SELECT * INTO r FROM public.market_knowledge_captures WHERE run_id=p_run;
 IF r.run_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c WHERE c.actor_user_id=r.actor_user_id AND c.phase='principal')
 OR r.session_digest IS DISTINCT FROM sha256(convert_to(p_session,'UTF8')) OR r.generation IS DISTINCT FROM(SELECT generation FROM public.principal_lifecycles WHERE user_id=r.actor_user_id)
 THEN RAISE EXCEPTION 'Qualified own shared capture required' USING ERRCODE='42501'; END IF;
 inputs:=builderad_private.knowledge_inputs(p_session,'market.knowledge.publish',r.material_refs,r.purpose,p_process,p_publication);
 IF r.input_digest IS DISTINCT FROM decode(inputs->>'digest','hex') OR r.input_reads IS DISTINCT FROM inputs->'reads' THEN RAISE EXCEPTION 'Captured knowledge inputs changed; recapture explicitly' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.market_operator_authorize(p_session,'market.knowledge.publish',p_publication);
 FOR entry IN SELECT value FROM jsonb_array_elements(r.input_reads) LOOP
  FOREACH op IN ARRAY ARRAY['forward','retain'] LOOP
   SELECT count(*) INTO matches FROM jsonb_array_elements(proof->'rights') v
   WHERE v#>>'{scope,resource}'='market.evidence' AND v#>>'{scope,operation}'=op AND v#>>'{scope,purpose}'=r.purpose
    AND v#>>'{scope,source_id}'=(SELECT source_id::text FROM public.data_use_scopes WHERE scope_id=(entry->>'data_use_scope_id')::uuid)
    AND(v#>'{scope,selection,native_keys}') ? (entry#>>'{input,key}') AND(v#>'{scope,selection,field_keys}') @> (entry->'field_keys');
   IF matches<>1 THEN RAISE EXCEPTION 'Explicit continuing transformation and retention licence required' USING ERRCODE='42501'; END IF;
  END LOOP;
 END LOOP;
 SELECT count(*) INTO matches FROM jsonb_array_elements(proof->'rights') v WHERE v#>>'{scope,scope_id}'=p_output::text
  AND v#>>'{scope,resource}'='market.knowledge' AND v#>>'{scope,operation}'='display' AND v#>>'{scope,purpose}'=r.purpose
  AND v#>'{scope,selection,field_keys}'='["finding"]'::jsonb AND(v#>'{scope,selection,native_keys}') ? r.run_id::text;
 IF matches<>1 THEN RAISE EXCEPTION 'Explicit independent output delivery licence required' USING ERRCODE='42501'; END IF;
 -- No scheduler is part of this producer. It accepts unbounded retained-storage authority with explicit withdrawal,
 -- and erases its result payload atomically on that withdrawal. A timed erasure obligation is unsupported,
 -- so reject it before copying a result rather than confusing access denial with physical erasure.
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'rights') v WHERE v#>>'{scope,operation}'='retain'
  AND(v#>>'{grant_row,expires_at}' IS NOT NULL OR v#>>'{qualification_row,valid_until}' IS NOT NULL OR v#>>'{policy,valid_until}' IS NOT NULL))
 THEN RAISE EXCEPTION 'Timed retention requires an implemented erasure executor' USING ERRCODE='22023'; END IF;

 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'rights') v WHERE v#>>'{scope,scope_id}'<>p_output::text AND NOT EXISTS(
  SELECT 1 FROM jsonb_array_elements(r.input_reads) e WHERE v#>>'{scope,resource}'='market.evidence' AND v#>>'{scope,operation}' IN('forward','retain')
   AND v#>>'{scope,purpose}'=r.purpose AND(v#>'{scope,selection,native_keys}') ? (e#>>'{input,key}'))) THEN RAISE EXCEPTION 'No unrelated publication rights' USING ERRCODE='42501'; END IF;
 RETURN inputs||jsonb_build_object('publication_proof',proof,'run',jsonb_build_object('run_ref',r.run_id,'method_ref',r.method_ref,'question',r.question,'state',r.state,'purpose',r.purpose));
END $$;


ALTER FUNCTION builderad_private.knowledge_publication_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_binding_withdrawal(p_business uuid, p_scope uuid) RETURNS public.data_use_scopes
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;b bigint;count_gates integer:=0;gate record;
BEGIN
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF b IS NULL OR s.scope_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=p_scope)
 OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND g.scope_kind='business' AND g.business_id=b AND g.action='business.link.manage'
  AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Existing own Business binding required' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT fence_id FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND s.rights_domain='client' AND boundary_id=s.boundary_id) OR(kind='data_use' AND s.rights_domain='market' AND data_use_scope_id=s.scope_id) ORDER BY fence_id FOR SHARE LOOP count_gates:=count_gates+1;END LOOP;
 IF count_gates<>2 THEN RAISE EXCEPTION 'Existing binding resource gates required' USING ERRCODE='42501'; END IF;
 RETURN s;
END $$;


ALTER FUNCTION builderad_private.lock_binding_withdrawal(p_business uuid, p_scope uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.market_operator(p_session text, p_action text, p_lock boolean DEFAULT true) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;g public.security_action_grants;
BEGIN
 IF session_user<>'builderad_app' OR (p_action IS NULL OR p_action NOT IN('market.knowledge.observe','market.knowledge.capture','market.knowledge.publish')) THEN RAISE EXCEPTION 'Qualified operational reader required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal';
 IF actor IS NULL THEN RAISE EXCEPTION 'Signed operational principal required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN
  PERFORM 1 FROM public.principal_lifecycles WHERE user_id=actor FOR SHARE;
  PERFORM 1 FROM public.users WHERE id=actor FOR SHARE;
  PERFORM 1 FROM public.internal_personnel WHERE user_id=actor FOR SHARE;
  PERFORM 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
 END IF;
 SELECT a.* INTO g FROM public.security_action_grants a JOIN public.principal_lifecycles l ON l.user_id=a.subject_user_id AND l.generation=a.subject_generation
 WHERE a.subject_user_id=actor AND a.action=p_action AND a.scope_kind='platform'
  AND a.status='active' AND a.revoked_at IS NULL AND(a.expires_at IS NULL OR a.expires_at>clock_timestamp()) ORDER BY a.id LIMIT 1;
 IF g.id IS NULL THEN RAISE EXCEPTION 'Explicit operational entitlement required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN PERFORM 1 FROM public.security_action_grants WHERE id=g.id FOR SHARE; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id
  JOIN public.internal_personnel p ON p.user_id=l.user_id JOIN public.security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  JOIN public.security_action_grants a ON a.subject_user_id=l.user_id AND a.subject_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='staff' AND u.email_verified_at IS NOT NULL AND p.status='active'
   AND s.session_key_hash=p_session AND s.auth_method='internal_passkey' AND s.revoked_at IS NULL AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
   AND a.id=g.id AND a.action=p_action AND a.scope_kind='platform' AND a.status='active' AND a.revoked_at IS NULL AND(a.expires_at IS NULL OR a.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Current qualified staff entitlement and session required' USING ERRCODE='42501'; END IF;
 RETURN actor;
END $$;


ALTER FUNCTION builderad_private.market_operator(p_session text, p_action text, p_lock boolean) OWNER TO builderad_owner;

CREATE FUNCTION builderad_private.market_operator_authorize(p_session text, p_action text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE actor bigint;req jsonb;g public.security_action_grants;q public.authority_qualification_receipts;
 s public.data_use_scopes;c public.market_source_custody;proofs jsonb:='[]';
BEGIN
 IF p_action IS NULL OR p_action NOT IN('market.knowledge.capture','market.knowledge.publish') THEN RAISE EXCEPTION 'Explicit market effect required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.market_keys(p_selection,ARRAY['realm_kind','requirements']);
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct'
 OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 16
 OR (SELECT count(*)<>count(DISTINCT value->>'leaf_grant_id') FROM jsonb_array_elements(p_selection->'requirements'))
 THEN RAISE EXCEPTION 'Exact bounded direct market authority required' USING ERRCODE='42501'; END IF;
 SELECT x.actor_user_id INTO actor FROM public.builderad_signed_context() x WHERE x.phase='principal';
 IF session_user<>'builderad_app' OR actor IS NULL THEN RAISE EXCEPTION 'Signed market operator required' USING ERRCODE='42501'; END IF;
 -- Caller has frozen the complete source/data-use resource union at K1, before this K4 footprint.
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 IF builderad_private.market_operator(p_session,p_action) IS DISTINCT FROM actor THEN RAISE EXCEPTION 'Market actor mismatch' USING ERRCODE='42501'; END IF;
 FOR req IN SELECT value FROM jsonb_array_elements(p_selection->'requirements') LOOP
  PERFORM builderad_private.market_keys(req,ARRAY['leaf_grant_id','requirement_kind']);
  IF req->>'requirement_kind' IS DISTINCT FROM 'data_use' OR coalesce(req->>'leaf_grant_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Exact data-use root required' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(req->>'leaf_grant_id')::bigint;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=g.data_use_scope_id;
  SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
  IF g.id IS NULL OR g.subject_user_id IS DISTINCT FROM actor OR g.subject_generation IS DISTINCT FROM (SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
  OR g.scope_kind IS DISTINCT FROM 'data_use' OR g.parent_grant_id IS NOT NULL OR g.delegable IS DISTINCT FROM false OR g.source IS DISTINCT FROM 'qualified_policy'
  OR g.delegation_realm_kind IS DISTINCT FROM 'direct' OR g.status IS DISTINCT FROM 'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
  OR q.qualification_id IS NULL OR q.issuer_kind IS DISTINCT FROM 'qualified_policy' OR q.status IS DISTINCT FROM 'active' OR q.subject_user_id IS DISTINCT FROM actor
  OR q.target_kind IS DISTINCT FROM 'data_use' OR q.target_ref IS DISTINCT FROM s.scope_id OR NOT g.action=ANY(q.allowed_actions)
  OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
  OR s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL OR s.status IS DISTINCT FROM 'active'
  OR s.resource NOT IN('market.evidence','market.knowledge','market.business_finding') OR g.action IS DISTINCT FROM 'data_use.'||s.operation
  OR s.selection->'native_namespaces' IS DISTINCT FROM jsonb_build_array(s.resource)
  OR c.source_id IS NULL OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
  OR q.policy_id IS DISTINCT FROM c.policy_id OR q.policy_version IS DISTINCT FROM c.policy_version
  OR NOT s.operation=ANY(c.allowed_operations) OR NOT s.purpose=ANY(c.allowed_purposes)
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
  THEN RAISE EXCEPTION 'Current qualified market operation root required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.market_policy(s.scope_id);
  proofs:=proofs||jsonb_build_array(jsonb_build_object('grant_row',to_jsonb(g),'qualification_row',to_jsonb(q),'scope',to_jsonb(s),'policy',to_jsonb(c)));
 END LOOP;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proofs) v WHERE
  (v#>>'{grant_row,expires_at}' IS NOT NULL AND(v#>>'{grant_row,expires_at}')::timestamptz<=clock_timestamp())
  OR(v#>>'{qualification_row,valid_until}' IS NOT NULL AND(v#>>'{qualification_row,valid_until}')::timestamptz<=clock_timestamp())
  OR(v#>>'{policy,valid_until}' IS NOT NULL AND(v#>>'{policy,valid_until}')::timestamptz<=clock_timestamp()))
 THEN RAISE EXCEPTION 'Selected market licence expired before qualification release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.market_operator(p_session,p_action,false);
 RETURN jsonb_build_object('actor',actor,'effect',p_action,'rights',proofs,'dependencies',coalesce((SELECT jsonb_agg(to_jsonb(f) ORDER BY f.fence_id)
 FROM public.resource_right_fences f WHERE (f.kind='source' AND f.source_id IN(SELECT (v#>>'{scope,source_id}')::uuid FROM jsonb_array_elements(proofs) v))
 OR(f.kind='data_use' AND f.data_use_scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM jsonb_array_elements(proofs) v))),'[]'::jsonb));
END $_$;


ALTER FUNCTION builderad_private.market_operator_authorize(p_session text, p_action text, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.market_policy(p_scope uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;c public.market_source_custody;
BEGIN
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
 IF s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL OR s.status IS DISTINCT FROM 'active'
 OR NOT EXISTS(SELECT 1 FROM public.source_registry WHERE id=s.source_id AND custody_kind='platform_market' AND status<>'REVOKED')
 OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
 OR NOT s.operation=ANY(c.allowed_operations) OR NOT s.purpose=ANY(c.allowed_purposes)
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
 OR s.resource NOT IN('market.evidence','market.knowledge','market.business_finding') OR s.selection->'native_namespaces' IS DISTINCT FROM jsonb_build_array(s.resource)
 THEN RAISE EXCEPTION 'Current exact market source policy required' USING ERRCODE='42501'; END IF;
 RETURN to_jsonb(c);
END $$;


ALTER FUNCTION builderad_private.market_policy(p_scope uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_boundary_choices(p_session text, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;g record;row_value jsonb;proof jsonb;items jsonb:='[]';requirements jsonb:='[]';ids bigint[]:='{}';boundaries uuid[]:='{}';scanned bigint:=p_after;next_cursor bigint;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded explicit cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 FOR g IN SELECT x.* FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
 WHERE x.subject_user_id=actor AND x.scope_kind='client_data' AND x.action='client_data.manage' AND x.id>p_after
 AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp()) ORDER BY x.id LIMIT 32 LOOP
  scanned:=g.id;
  BEGIN
   PERFORM builderad_private.authority_footprint(actor,builderad_private.boundary_selection(g.id));
   ids:=array_append(ids,g.id);boundaries:=array_append(boundaries,g.boundary_id);
   requirements:=requirements||(builderad_private.boundary_selection(g.id)->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 -- Every material-right fence precedes the complete K4 authority union.
 PERFORM 1 FROM public.resource_right_fences WHERE boundary_id=ANY(boundaries) AND kind='client_boundary' AND adoption_state='v2_adopted' ORDER BY fence_id FOR SHARE;
 IF cardinality(ids)>0 THEN PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);END IF;
 FOR g IN SELECT * FROM public.security_action_grants WHERE id=ANY(ids) ORDER BY id LOOP
  BEGIN
   IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE boundary_id=g.boundary_id AND kind='client_boundary' AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'Adopted boundary required' USING ERRCODE='42501';END IF;
   proof:=builderad_private.require_scope('client_data',g.boundary_id::text,actor,'client_data.manage',builderad_private.boundary_selection(g.id),p_session);
   SELECT jsonb_build_object('boundary_id',b.boundary_id,'state',b.status,'read_grant_id',g.id::text,
    'realm_kind',g.delegation_realm_kind) INTO row_value
   FROM public.client_data_boundaries b WHERE boundary_id=g.boundary_id;
   IF row_value IS NOT NULL THEN items:=items||jsonb_build_array(row_value);END IF;
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
 END LOOP;
 FOR row_value IN SELECT value FROM jsonb_array_elements(items) LOOP
  PERFORM builderad_private.require_scope('client_data',row_value->>'boundary_id',actor,'client_data.manage',builderad_private.boundary_selection((row_value->>'read_grant_id')::bigint),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
  WHERE x.subject_user_id=actor AND x.scope_kind='client_data' AND x.action='client_data.manage' AND x.id>scanned AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp())) THEN next_cursor:=scanned;END IF;
 RETURN jsonb_build_object('boundaries',items,'next_cursor',next_cursor::text);
END $$;


ALTER FUNCTION public.builderad_v2_boundary_choices(p_session text, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_data_use_beneficiary(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_recipient uuid, p_until timestamp with time zone, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;recipient bigint;b bigint;s public.data_use_scopes;boundary_row public.client_data_boundaries;
 business_proof jsonb;boundary_proof jsonb;proof jsonb;payload bytea;result jsonb;parent public.security_action_grants;recipient_business public.security_action_grants;
 prior public.security_action_grants;g public.security_action_grants;q public.authority_qualification_receipts;prior_q public.authority_qualification_receipts;
 gate record;gate_count integer:=0;expiry timestamptz;authenticated timestamptz;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_license IS NULL OR p_recipient IS NULL OR p_expected IS NULL OR p_expected<0
 OR p_effect IS NULL OR p_effect NOT IN('assign','revoke') OR p_until IS NULL OR NOT isfinite(p_until)
 THEN RAISE EXCEPTION 'Exact beneficiary command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_license;
 SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
 IF recipient IS NULL OR recipient=actor OR b IS NULL OR boundary_row.descriptor->>'policy_id' IS DISTINCT FROM 'declared_data_license_v1'
 OR boundary_row.descriptor->>'business_public_id' IS DISTINCT FROM p_business::text OR boundary_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text
 OR boundary_row.descriptor->>'declarant_generation' IS DISTINCT FROM(SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)
 THEN RAISE EXCEPTION 'Original qualified data authority and exact Business required' USING ERRCODE='42501'; END IF;
 IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>2
 THEN RAISE EXCEPTION 'Independent Business use and data authority required' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id LOOP
  IF gate.kind='client_boundary' THEN PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;
  ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE;END IF;
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  gate_count:=gate_count+1;
 END LOOP;
 IF gate_count<>2 THEN RAISE EXCEPTION 'Existing licence resource gates required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_business(p_business,false);
 business_proof:=builderad_private.require_scope('business',b::text,actor,'business.data.use',p_selection,p_session,ARRAY[recipient]);
 boundary_proof:=builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session,ARRAY[recipient]);
 SELECT * INTO STRICT parent FROM public.security_action_grants WHERE id=(business_proof#>>'{grants,0,id}')::bigint;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit beneficiary confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','data_license_beneficiary','business',p_business,'license',p_license,'effect',p_effect,
  'recipient',p_recipient,'until',p_until,'expected',p_expected,'selection',p_selection),'{"business":"U","license":"U","recipient":"U","until":"T","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'data_license.beneficiary.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT grant_row.* INTO prior FROM public.security_action_grants grant_row JOIN public.authority_qualification_receipts qualification ON qualification.qualification_id=grant_row.qualification_id
  WHERE grant_row.scope_kind='data_use' AND grant_row.data_use_scope_id=p_license AND grant_row.subject_user_id=recipient
  AND qualification.policy_id='declared_data_beneficiary_v1' ORDER BY grant_row.id DESC LIMIT 1 FOR UPDATE OF grant_row;
 IF coalesce(prior.version,0)<>p_expected THEN RAISE EXCEPTION 'Reviewed beneficiary assignment changed' USING ERRCODE='B4090'; END IF;
 IF p_effect='assign' THEN
  IF prior.id IS NOT NULL AND prior.status='active' AND prior.revoked_at IS NULL
  THEN RAISE EXCEPTION 'Reconcile or explicitly revoke existing beneficiary' USING ERRCODE='40001'; END IF;
  SELECT child.* INTO recipient_business FROM public.security_action_grants child JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=child.qualification_id
   JOIN public.principal_lifecycles lifecycle ON lifecycle.user_id=child.subject_user_id AND lifecycle.generation=child.subject_generation AND lifecycle.status='current' AND lifecycle.principal_class='advertiser'
   JOIN public.users u ON u.id=child.subject_user_id AND u.email_verified_at IS NOT NULL
   WHERE child.parent_grant_id=parent.id AND child.business_id=b AND child.scope_kind='business' AND child.action='business.data.use'
   AND child.subject_user_id=recipient AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
   AND child.delegation_realm_kind IS NOT DISTINCT FROM p_selection->>'realm_kind'
   AND qualified.status='active' AND qualified.issuer_kind='existing_grant' AND qualified.issuer_grant_id=parent.id
   AND qualified.valid_from<=clock_timestamp() AND(qualified.valid_until IS NULL OR qualified.valid_until>clock_timestamp()) ORDER BY child.id DESC LIMIT 1 FOR SHARE OF child;
  IF recipient_business.id IS NULL OR NOT parent.delegable OR s.status<>'active' OR boundary_row.status<>'active'
   OR s.operation NOT IN('retained_read','display')
  THEN RAISE EXCEPTION 'Explicit current Business-qualified beneficiary required' USING ERRCODE='42501'; END IF;
  expiry:=least((boundary_row.descriptor#>>'{declaration,valid_until}')::timestamptz,parent.expires_at,recipient_business.expires_at);
  IF s.resource='business.expenditure_summary' THEN
   SELECT least(expiry,valid_until) INTO expiry FROM public.business_expenditure_summaries WHERE output_scope_id=s.scope_id AND publication_manifest_id IS NOT NULL;
   IF expiry IS NULL THEN RAISE EXCEPTION 'Published finite expenditure summary required' USING ERRCODE='42501'; END IF;
  END IF;
  IF p_until<=clock_timestamp() OR p_until>expiry OR EXISTS(SELECT 1 FROM jsonb_array_elements(business_proof->'qualifications') row_value
   WHERE row_value->>'valid_until' IS NOT NULL AND p_until>(row_value->>'valid_until')::timestamptz)
  THEN RAISE EXCEPTION 'Beneficiary cannot exceed original authority or licence duration' USING ERRCODE='42501'; END IF;
 ELSE
  IF prior.id IS NULL OR prior.status<>'active' THEN RAISE EXCEPTION 'Exact active beneficiary required' USING ERRCODE='40001'; END IF;
 END IF;
 proof:=jsonb_build_object('business_authority',business_proof,'data_authority',boundary_proof,'recipient_business',to_jsonb(recipient_business),
  'confirmation_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
  'dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR f.boundary_id=s.boundary_id));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.beneficiary.'||p_effect,jsonb_build_object('scope_id',p_license,'recipient',recipient),proof);
 IF p_effect='assign' THEN
  q.qualification_id:=uuidv7();q.policy_id:='declared_data_beneficiary_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=recipient_business.id;
  q.subject_user_id:=recipient;q.target_kind:='data_use';q.target_ref:=p_license;q.allowed_actions:=ARRAY['data_use.'||s.operation];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;q.provenance:='Explicit named beneficiary of existing finite consent; no change to original grants, Source or Business authority; independent reader requalification required';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.source:='qualified_policy';g.action:='data_use.'||s.operation;g.scope_kind:='data_use';g.data_use_scope_id:=p_license;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
  g.reason:='Explicit finite named data beneficiary';g.status:='active';g.version:=p_expected+1;g.expires_at:=p_until;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=false;g.delegation_realm_kind:=recipient_business.delegation_realm_kind;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 ELSE
  g:=prior;g.status:='revoked';g.version:=prior.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 END IF;
 PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.data.use',p_selection,p_session,ARRAY[recipient]);
 PERFORM builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session,ARRAY[recipient]);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('license_id',p_license,'recipient_public_id',p_recipient,'grant_id',g.id::text,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'data_license.beneficiary.'||p_effect,payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_data_use_beneficiary(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_recipient uuid, p_until timestamp with time zone, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_data_use_choices(p_session text, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;g record;s public.data_use_scopes;candidate jsonb;selection jsonb;requirements jsonb:='[]';prepared jsonb:='[]';accepted jsonb:='[]';items jsonb:='[]';
 sources uuid[]:='{}';boundaries uuid[]:='{}';last_id bigint:=p_after;scanned integer:=0;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Invalid data-use cursor' USING ERRCODE='22023'; END IF;
 FOR g IN SELECT id,data_use_scope_id FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='data_use' AND id>p_after
  AND status='active' AND revoked_at IS NULL AND(expires_at IS NULL OR expires_at>clock_timestamp()) ORDER BY id LIMIT 32 LOOP
  last_id:=g.id;scanned:=scanned+1;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=g.data_use_scope_id;
  IF NOT FOUND THEN CONTINUE; END IF;
  -- Fixed output licences are exposed through their Business journey. The generic
  -- source-rights picker would disclose their internal Source/boundary pointers.
  IF s.resource='business.expenditure_summary' THEN CONTINUE; END IF;
  prepared:=prepared||jsonb_build_array(jsonb_build_object('grant_id',g.id,'scope',to_jsonb(s)));
  sources:=array_append(sources,s.source_id);boundaries:=array_append(boundaries,s.boundary_id);
  requirements:=requirements||jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',g.id));
 END LOOP;
 -- Complete K1 union before K4. Preflight principal and scope reads above take no locks.
 PERFORM 1 FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(sources)) OR(kind='client_boundary' AND boundary_id=ANY(boundaries)) ORDER BY fence_id FOR SHARE;
 IF jsonb_array_length(requirements)>0 THEN PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);END IF;
 FOR candidate IN SELECT value FROM jsonb_array_elements(prepared) LOOP
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(candidate#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM candidate->'scope' OR s.status<>'active' THEN CONTINUE; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=s.source_id AND adoption_state='v2_adopted')
   OR NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id AND adoption_state='v2_adopted')
   OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active') THEN CONTINUE; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(candidate->>'grant_id')::bigint;
  IF g.subject_user_id IS DISTINCT FROM actor OR g.scope_kind<>'data_use' OR g.data_use_scope_id IS DISTINCT FROM s.scope_id OR g.action IS DISTINCT FROM 'data_use.'||s.operation THEN CONTINUE; END IF;
  selection:=jsonb_build_object('realm_kind',g.delegation_realm_kind,
   'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',g.id)));
  BEGIN
   PERFORM builderad_private.require_scope('data_use',s.scope_id::text,actor,'data_use.'||s.operation,selection,p_session);
   items:=items||jsonb_build_array(jsonb_build_object('scope_id',s.scope_id,'grant_id',g.id::text,'grant_revision',g.version,'operation',s.operation,'purpose',s.purpose,'resource',s.resource,
    'source_ref',s.source_id,'boundary_ref',s.boundary_id,'field_keys',s.selection->'field_keys','record_count',jsonb_array_length(s.selection->'native_keys'),
    'namespace_count',jsonb_array_length(s.selection->'native_namespaces'),'authority_selection',selection));
   accepted:=accepted||jsonb_build_array(jsonb_build_object('scope',to_jsonb(s),'selection',selection));
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOR candidate IN SELECT value FROM jsonb_array_elements(accepted) LOOP
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(candidate#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM candidate->'scope' THEN RAISE EXCEPTION 'Data scope changed after wait' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.require_scope('data_use',s.scope_id::text,actor,'data_use.'||s.operation,candidate->'selection',p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('scopes',items,'next_cursor',CASE WHEN scanned=32 THEN last_id::text ELSE NULL END);
END $$;


ALTER FUNCTION public.builderad_v2_data_use_choices(p_session text, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_data_use_license(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_expected bigint, p_declaration jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE actor bigint;b bigint;manager public.security_action_grants;source_row public.source_registry;
 s public.data_use_scopes;boundary_row public.client_data_boundaries;f public.resource_right_fences;
 q public.authority_qualification_receipts;g public.security_action_grants;prior_q public.authority_qualification_receipts;prior_g public.security_action_grants;
 proof jsonb;business_proof jsonb;source_proof jsonb;payload bytea;result jsonb;selected bigint;matches integer;
 expiry timestamptz;authenticated timestamptz;boundary_ref uuid;boundary_grant bigint;use_grant bigint;actor_public uuid;
 grant_kind text;target_ref uuid;action_name text;gate record;gate_count integer:=0;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_license IS NULL OR uuid_extract_version(p_license) IS DISTINCT FROM 7 OR p_expected IS NULL
 OR p_effect IS NULL OR p_effect NOT IN('issue','withdraw') OR p_declaration IS NULL OR jsonb_typeof(p_declaration)<>'object'
 OR octet_length(p_declaration::text)>65536 THEN RAISE EXCEPTION 'Exact bounded data licence command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT public_id INTO actor_public FROM public.users WHERE id=actor;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','declared_data_license','business',p_business,'license',p_license,'effect',p_effect,
  'expected',p_expected,'declaration',p_declaration,'selection',p_selection),'{"business":"U","license":"U","expected":"I"}');
 IF p_effect='issue' THEN
  IF p_expected<>0 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_declaration) k) IS DISTINCT FROM
   ARRAY['authority_reference','capacity','confirmed','holder_name','operation','purpose','recipient_public_id','requires_review','resource','selection','source_id','valid_until']::text[]
   OR p_declaration->'confirmed' IS DISTINCT FROM 'true'::jsonb OR p_declaration->'requires_review' IS DISTINCT FROM 'false'::jsonb
   OR jsonb_typeof(p_declaration->'capacity') IS DISTINCT FROM 'string' OR p_declaration->>'capacity' NOT IN('data_holder','authorized_representative')
   OR jsonb_typeof(p_declaration->'holder_name') IS DISTINCT FROM 'string' OR length(btrim(p_declaration->>'holder_name')) NOT BETWEEN 2 AND 160
   OR jsonb_typeof(p_declaration->'authority_reference') IS DISTINCT FROM 'string' OR length(btrim(p_declaration->>'authority_reference')) NOT BETWEEN 8 AND 512
   OR p_declaration->>'operation' IS NULL OR p_declaration->>'operation' NOT IN('retained_read','display','process','reuse','combine','export')
   OR jsonb_typeof(p_declaration->'recipient_public_id') IS DISTINCT FROM 'string' OR coalesce(p_declaration->>'recipient_public_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR jsonb_typeof(p_declaration->'resource') IS DISTINCT FROM 'string' OR jsonb_typeof(p_declaration->'purpose') IS DISTINCT FROM 'string'
   OR jsonb_typeof(p_declaration->'source_id') IS DISTINCT FROM 'string' OR coalesce(p_declaration->>'source_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR jsonb_typeof(p_declaration->'valid_until') IS DISTINCT FROM 'string'
  THEN RAISE EXCEPTION 'Explicit complete declared authority required; ambiguous or unsupported recipient requires review' USING ERRCODE='22023'; END IF;
  BEGIN expiry:=(p_declaration->>'valid_until')::timestamptz;
  EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN RAISE EXCEPTION 'Finite future licence expiry required' USING ERRCODE='22023'; END;
  IF NOT isfinite(expiry) OR expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Finite future licence expiry required' USING ERRCODE='22023'; END IF;
  -- Canonical bounded selectors are reused; no new data-selection codec.
  PERFORM builderad_private.canonical_data_selection(p_declaration->'selection');
  IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>2
  THEN RAISE EXCEPTION 'Independent Business context and Source manager required' USING ERRCODE='42501'; END IF;
  SELECT count(*),min(grant_row.id) INTO matches,selected FROM public.security_action_grants grant_row
   JOIN jsonb_array_elements(p_selection->'requirements') selected_path ON selected_path->>'leaf_grant_id'=grant_row.id::text
   AND selected_path->>'requirement_kind'='native_source'
   WHERE grant_row.subject_user_id=actor AND grant_row.scope_kind='source' AND grant_row.action='source.manage'
   AND grant_row.source_target_id=(p_declaration->>'source_id')::uuid;
  IF matches<>1 THEN RAISE EXCEPTION 'Exact selected Source manager required' USING ERRCODE='42501'; END IF;
  SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=(p_declaration->>'source_id')::uuid FOR UPDATE;
  IF NOT FOUND OR f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  SELECT * INTO source_row FROM public.source_registry WHERE id=f.source_id;
  SELECT * INTO manager FROM public.security_action_grants WHERE id=selected;
  SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
  IF source_row.custody_kind='business_native' AND source_row.business_id IS DISTINCT FROM b
  THEN RAISE EXCEPTION 'Exact native source Business required' USING ERRCODE='42501'; END IF;
  b:=builderad_private.lock_business(p_business,false);
  PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
  business_proof:=builderad_private.require_scope('business',b::text,actor,'business.context.read',p_selection,p_session);
  source_proof:=builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
  IF p_declaration->>'recipient_public_id' IS DISTINCT FROM actor_public::text
  THEN RAISE EXCEPTION 'Explicit recipient delegation is not inferred by declaration' USING ERRCODE='22023'; END IF;
  IF manager.source_resource IS DISTINCT FROM p_declaration->>'resource' OR (SELECT lifecycle FROM public.businesses WHERE id=b) IN('archived','suspended')
  THEN RAISE EXCEPTION 'Exact current source resource and Business required' USING ERRCODE='42501'; END IF;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(p_declaration#>'{selection,native_namespaces}') selected(namespace)
   WHERE NOT EXISTS(SELECT 1 FROM public.provider_observation_streams stream WHERE stream.namespace=selected.namespace AND stream.source_id=source_row.id
    AND stream.resource=manager.source_resource AND stream.operation=manager.source_operation AND stream.purpose=manager.source_purpose))
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(p_declaration#>'{selection,native_keys}') selected(identity)
   WHERE NOT EXISTS(SELECT 1 FROM public.provider_observation_entries entry JOIN public.provider_observation_streams stream USING(namespace)
    WHERE stream.source_id=source_row.id AND stream.resource=manager.source_resource AND stream.operation=manager.source_operation AND stream.purpose=manager.source_purpose
    AND p_declaration#>'{selection,native_namespaces}' ? stream.namespace AND entry.identity=selected.identity))
  THEN RAISE EXCEPTION 'Licence selector must identify the exact qualified native report scope' USING ERRCODE='42501'; END IF;
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
  IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
  THEN RAISE EXCEPTION 'Fresh explicit data authority declaration required' USING ERRCODE='42501'; END IF;
  result:=builderad_private.command_replay(b,actor,p_command,'data_license.issue',payload);
  IF result IS NOT NULL THEN RETURN result; END IF;
  IF EXISTS(SELECT 1 FROM public.data_use_scopes WHERE scope_id=p_license) THEN RAISE EXCEPTION 'Reconcile the existing immutable licence' USING ERRCODE='40001'; END IF;
  proof:=jsonb_build_object('business_context',business_proof,'source_custody',source_proof,'data_authority_declaration',p_declaration,
   'dependencies',jsonb_build_array(to_jsonb(f)),'declaration_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
  PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.issue',jsonb_build_object('business_id',b,'scope_id',p_license),proof);
  boundary_ref:=uuidv7();
  FOREACH grant_kind IN ARRAY ARRAY['client_data','data_use'] LOOP
   target_ref:=CASE grant_kind WHEN 'client_data' THEN boundary_ref ELSE p_license END;
   action_name:=CASE grant_kind WHEN 'client_data' THEN 'client_data.manage' ELSE 'data_use.'||(p_declaration->>'operation') END;
   q:=NULL;q.qualification_id:=uuidv7();q.policy_id:='declared_data_license_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
   q.subject_user_id:=actor;q.target_kind:=grant_kind;q.target_ref:=target_ref;q.allowed_actions:=ARRAY[action_name];
   q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=expiry;
   q.provenance:='Explicit declared data-holder/representative authority; not verified legal ownership, Business use, Source delegation, external AI/training or ad-action authority';
   PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
   IF grant_kind='client_data' THEN
    boundary_row.boundary_id:=boundary_ref;boundary_row.status:='active';boundary_row.revision:=1;boundary_row.descriptor_schema_version:=1;boundary_row.qualification_ref:=q.qualification_id;
    boundary_row.descriptor:=jsonb_build_object('policy_id','declared_data_license_v1','policy_version',1,'business_public_id',p_business,'license_id',p_license,
     'declarant_user_id',actor,'declarant_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
     'assurance','DECLARED_AUTHORITY','declaration',p_declaration,'command_id',p_command);
    PERFORM builderad_private.permit('public.client_data_boundaries','insert',NULL,to_jsonb(boundary_row));INSERT INTO public.client_data_boundaries SELECT(boundary_row).*;
    f:=NULL;f.fence_id:=uuidv7();f.kind:='client_boundary';f.boundary_id:=boundary_ref;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
    PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
   ELSE
    s.scope_id:=p_license;s.boundary_id:=boundary_ref;s.source_id:=source_row.id;s.resource:=p_declaration->>'resource';
    s.selection:=builderad_private.canonical_data_selection(p_declaration->'selection');s.selection_schema_version:=1;s.operation:=p_declaration->>'operation';s.purpose:=p_declaration->>'purpose';
    s.scope_digest:=builderad_private.data_scope_digest(boundary_ref,source_row.id,s.resource,s.operation,s.purpose,s.selection);s.status:='active';s.revision:=1;s.rights_domain:='client';
    PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
   END IF;
   g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.granted_by:=NULL;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:=grant_kind;
   IF grant_kind='client_data' THEN g.boundary_id:=boundary_ref;boundary_grant:=g.id;ELSE g.data_use_scope_id:=p_license;use_grant:=g.id;END IF;
   SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
   g.reason:='Explicit bounded declared data licence';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
   g.qualification_id:=q.qualification_id;g.expires_at:=expiry;g.delegable:=false;g.delegation_realm_kind:=p_selection->>'realm_kind';
   PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  END LOOP;
  PERFORM builderad_private.require_scope('business',b::text,actor,'business.context.read',p_selection,p_session);
  PERFORM builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
  IF expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Licence expired before effect' USING ERRCODE='42501'; END IF;
  result:=jsonb_build_object('license_id',p_license,'boundary_id',boundary_ref,'boundary_grant_id',boundary_grant::text,'use_grant_id',use_grant::text,
   'status','active','assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('license',1));
 ELSE
  IF p_expected<>1 OR p_declaration<>'{}'::jsonb THEN RAISE EXCEPTION 'Exact original licence withdrawal required' USING ERRCODE='22023'; END IF;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_license;
  SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
  IF boundary_row.descriptor->>'policy_id' IS DISTINCT FROM 'declared_data_license_v1'
   OR boundary_row.descriptor->>'license_id' IS DISTINCT FROM p_license::text
   OR boundary_row.descriptor->>'business_public_id' IS DISTINCT FROM p_business::text
   OR boundary_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text
   OR boundary_row.descriptor->>'declarant_generation' IS DISTINCT FROM (SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)
  THEN RAISE EXCEPTION 'Original current data authority declarant required' USING ERRCODE='42501'; END IF;
  FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id LOOP
   IF gate.kind='client_boundary' THEN PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;
   ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE;END IF;
   gate_count:=gate_count+1;
  END LOOP;
  IF gate_count<>2 THEN RAISE EXCEPTION 'Existing licence resource gates required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.principal(p_session);
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
  IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
  THEN RAISE EXCEPTION 'Fresh consent withdrawal required' USING ERRCODE='42501'; END IF;
  SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
  result:=builderad_private.command_replay(b,actor,p_command,'data_license.withdraw',payload);IF result IS NOT NULL THEN RETURN result; END IF;
  SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
  IF boundary_row.status<>'active' OR boundary_row.revision<>p_expected THEN RAISE EXCEPTION 'Licence already withdrawn or changed' USING ERRCODE='40001'; END IF;
  -- Historical licence expiry is not current authority to withdraw consent.
  -- Preserve the complete declaration in its immutable row; bind its identity/digest here.
  proof:=jsonb_build_object('declaration_identity',jsonb_build_object('boundary_id',s.boundary_id,'license_id',p_license,'declarant_user_id',actor,
   'descriptor_digest',encode(builderad_private.digest('command-payload:1',boundary_row.descriptor),'hex')),
   'withdrawal_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
   'dependencies',(SELECT jsonb_agg(to_jsonb(x) ORDER BY fence_id) FROM public.resource_right_fences x WHERE x.source_id=s.source_id OR x.boundary_id=s.boundary_id));
  PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.withdraw',jsonb_build_object('scope_id',p_license),proof);
  FOR prior_g IN SELECT * FROM public.security_action_grants WHERE(scope_kind='client_data' AND boundary_id=s.boundary_id) OR(scope_kind='data_use' AND data_use_scope_id=p_license) ORDER BY id FOR UPDATE LOOP
   SELECT * INTO prior_q FROM public.authority_qualification_receipts WHERE qualification_id=prior_g.qualification_id;
   IF prior_q.status='active' THEN
    q:=prior_q;q.status:='revoked';q.revision:=q.revision+1;
    PERFORM builderad_private.permit('public.authority_qualification_receipts','update',to_jsonb(prior_q),to_jsonb(q));UPDATE public.authority_qualification_receipts SET status=q.status,revision=q.revision WHERE qualification_id=q.qualification_id;
   END IF;
   IF prior_g.status='active' THEN
    g:=prior_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
    PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior_g),to_jsonb(g));UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
   END IF;
   IF prior_g.subject_user_id=actor THEN
    IF prior_g.scope_kind='client_data' THEN boundary_grant:=prior_g.id;ELSE use_grant:=prior_g.id;END IF;
   END IF;
  END LOOP;
  PERFORM builderad_private.permit('public.client_data_boundaries','update',to_jsonb(boundary_row),to_jsonb(boundary_row)||jsonb_build_object('status','archived','revision',2));
  UPDATE public.client_data_boundaries SET status='archived',revision=2 WHERE boundary_id=s.boundary_id;
  PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
  result:=jsonb_build_object('license_id',p_license,'boundary_id',s.boundary_id,'boundary_grant_id',boundary_grant::text,'use_grant_id',use_grant::text,
   'status','withdrawn','assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('license',2));
 END IF;
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'data_license.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_data_use_license(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_expected bigint, p_declaration jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_inquiry_revoke(p_session text, p_business uuid, p_command uuid, p_action text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
  basis_selection:=jsonb_build_object('realm_kind','direct','requirements',jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',basis_grant)));
  PERFORM builderad_private.lock_authority_footprint(actor,basis_selection,p_session,'{}',ARRAY[en.qualification_id]);
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
END $_$;


ALTER FUNCTION public.builderad_v2_inquiry_revoke(p_session text, p_business uuid, p_command uuid, p_action text, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_market_admit(p_command uuid, p_action text, p_source uuid, p_expected bigint, p_payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE c public.market_source_custody;prior public.market_source_custody;src public.source_registry;f public.resource_right_fences;
 s public.data_use_scopes;q public.authority_qualification_receipts;g public.security_action_grants;
 proof jsonb;payload bytea;result jsonb;old public.business_command_receipts;actor bigint;generation bigint;existing_scope uuid;op text;purpose text;
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Isolated deployment operator required' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_source IS NULL OR uuid_extract_version(p_source)<>7 OR p_expected IS NULL OR p_expected<0
 OR p_action IS NULL OR p_action NOT IN('provision_source','restrict_source','revoke_source','enroll_data_use') THEN RAISE EXCEPTION 'Typed market maintenance command required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(p_payload);
 -- Same admission mutex as native custody; no per-source lock can be acquired later out of rank.
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 payload:=builderad_private.digest('market-maintenance:1',jsonb_build_object('action',p_action,'source',p_source,'expected',p_expected,'payload',p_payload),'{}');
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.actor_user_id IS NOT NULL OR old.action<>'maintenance.market.'||p_action OR old.payload_digest<>payload THEN RAISE EXCEPTION 'Maintenance command conflict' USING ERRCODE='40001'; END IF;
  RETURN old.result_ref;
 END IF;
 SELECT * INTO prior FROM public.market_source_custody WHERE source_id=p_source;
 IF coalesce(prior.revision,0)<>p_expected OR(p_action<>'provision_source' AND prior.source_id IS NULL) THEN RAISE EXCEPTION 'Market custody revision changed' USING ERRCODE='40001'; END IF;
 proof:=jsonb_build_object('operator_role',session_user,'source_id',p_source);
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.market.'||p_action,
  jsonb_build_object('source_id',p_source),proof,builderad_private.authority_digest(proof),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 IF p_action='provision_source' THEN
  IF p_expected<>0 THEN RAISE EXCEPTION 'Existing source cannot be reprovisioned' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.market_keys(p_payload,ARRAY['family','namespace','external_id','label','policy_id','policy_version','evidence_ref','evidence_digest','allowed_operations','allowed_purposes','allowed_fields','valid_until']);
  c.source_id:=p_source;c.revision:=1;c.status:='active';c.policy_id:=p_payload->>'policy_id';c.policy_version:=(p_payload->>'policy_version')::bigint;
  c.evidence_ref:=p_payload->>'evidence_ref';
  IF coalesce(p_payload->>'evidence_digest','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Explicit rights evidence digest required' USING ERRCODE='22023'; END IF;
  c.evidence_digest:=decode(p_payload->>'evidence_digest','hex');c.valid_from:=clock_timestamp();c.valid_until:=(p_payload->>'valid_until')::timestamptz;
  SELECT array_agg(v) INTO c.allowed_operations FROM jsonb_array_elements_text(p_payload->'allowed_operations') v;
  SELECT array_agg(v) INTO c.allowed_purposes FROM jsonb_array_elements_text(p_payload->'allowed_purposes') v;
  SELECT array_agg(v) INTO c.allowed_fields FROM jsonb_array_elements_text(p_payload->'allowed_fields') v;
  IF coalesce(cardinality(c.allowed_operations),0) NOT BETWEEN 1 AND 12 OR coalesce(cardinality(c.allowed_purposes),0) NOT BETWEEN 1 AND 32
  OR coalesce(cardinality(c.allowed_fields),0) NOT BETWEEN 1 AND 100
  OR EXISTS(SELECT 1 FROM unnest(c.allowed_operations) v WHERE v IS NULL OR v NOT IN('ingest','correct','retained_read','display','process','combine','reuse','export','train','retain','delete','forward'))
  OR EXISTS(SELECT 1 FROM unnest(c.allowed_purposes||c.allowed_fields) v WHERE v IS NULL OR length(v) NOT BETWEEN 1 AND 128 OR v LIKE '%*%')
  THEN RAISE EXCEPTION 'Finite rights policy required' USING ERRCODE='22023'; END IF;
  src.id:=p_source;src.family:=p_payload->>'family';src.namespace:=p_payload->>'namespace';src.declared_external_id:=p_payload->>'external_id';
  src.label:=p_payload->>'label';src.version:=1;src.status:='UNVERIFIED';src.created_at:=clock_timestamp();src.scope_lock_marker:=false;src.custody_kind:='platform_market';
  PERFORM builderad_private.permit('public.source_registry','insert',NULL,to_jsonb(src));INSERT INTO public.source_registry SELECT(src).*;
  PERFORM builderad_private.permit('public.market_source_custody','insert',NULL,to_jsonb(c));INSERT INTO public.market_source_custody SELECT(c).*;
  f.fence_id:=uuidv7();f.kind:='source';f.source_id:=p_source;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
  result:=jsonb_build_object('source_ref',p_source,'versions',jsonb_build_object('custody',1));
 ELSIF p_action IN('restrict_source','revoke_source') THEN
  PERFORM builderad_private.market_keys(p_payload,ARRAY['reason']);
  IF jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR length(p_payload->>'reason') NOT BETWEEN 1 AND 600 THEN RAISE EXCEPTION 'Restriction reason required' USING ERRCODE='22023'; END IF;
  c:=prior;c.revision:=c.revision+1;c.status:=CASE WHEN p_action='restrict_source' THEN 'restricted' ELSE 'revoked' END;
  IF prior.status='revoked' THEN RAISE EXCEPTION 'Revoked source cannot be revived' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.permit('public.market_source_custody','update',to_jsonb(prior),to_jsonb(c));
  UPDATE public.market_source_custody SET revision=c.revision,status=c.status WHERE source_id=p_source;
  PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source));
  result:=jsonb_build_object('source_ref',p_source,'status',c.status,'reason',p_payload->>'reason','versions',jsonb_build_object('custody',c.revision));
 ELSE
  PERFORM builderad_private.market_keys(p_payload,ARRAY['scope_id','subject_ref','subject_generation','operation','purpose','selection','valid_until','evidence_ref','evidence_digest']);
  s.scope_id:=(p_payload->>'scope_id')::uuid;op:=p_payload->>'operation';purpose:=p_payload->>'purpose';
  SELECT u.id,l.generation INTO actor,generation FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
   WHERE u.public_id=(p_payload->>'subject_ref')::uuid AND l.principal_class='advertiser' AND l.status='current' AND u.email_verified_at IS NOT NULL FOR SHARE OF u,l;
  IF actor IS NULL OR generation IS DISTINCT FROM (p_payload->>'subject_generation')::bigint OR prior.status<>'active' OR prior.valid_from>clock_timestamp()
  OR(prior.valid_until IS NOT NULL AND prior.valid_until<=clock_timestamp()) OR NOT op=ANY(prior.allowed_operations) OR NOT purpose=ANY(prior.allowed_purposes)
  OR coalesce(p_payload->>'evidence_digest','') !~ '^[0-9a-f]{64}$'
  THEN RAISE EXCEPTION 'Qualified current recipient and policy required' USING ERRCODE='42501'; END IF;
  s.source_id:=p_source;s.resource:='market.evidence';s.selection:=builderad_private.canonical_data_selection(p_payload->'selection');s.selection_schema_version:=1;
  s.operation:=op;s.purpose:=purpose;s.scope_digest:=builderad_private.market_scope_digest(p_source,s.resource,op,purpose,s.selection);s.status:='active';s.revision:=1;s.rights_domain:='market';
  IF s.selection->'native_namespaces' IS DISTINCT FROM '["market.evidence"]'::jsonb
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') k WHERE NOT k=ANY(prior.allowed_fields))
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'native_keys') k WHERE k !~ '^[0-9a-f-]{36}$')
  THEN RAISE EXCEPTION 'Exact market selection required' USING ERRCODE='42501'; END IF;
  -- A scope is a resource contract, not an individual's grant. Independently licensed
  -- recipients may use the same contract; their grants remain separate.
  SELECT existing.scope_id INTO existing_scope FROM public.data_use_scopes existing WHERE existing.scope_digest=s.scope_digest;
  IF existing_scope IS NULL THEN
   PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
   f.fence_id:=uuidv7();f.kind:='data_use';f.source_id:=NULL;f.boundary_id:=NULL;f.data_use_scope_id:=s.scope_id;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
   PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
  ELSE s.scope_id:=existing_scope;END IF;
  q.qualification_id:=uuidv7();q.policy_id:=prior.policy_id;q.policy_version:=prior.policy_version;q.issuer_kind:='qualified_policy';q.subject_user_id:=actor;
  q.target_kind:='data_use';q.target_ref:=s.scope_id;q.allowed_actions:=ARRAY['data_use.'||op];q.evidence_ref:=p_payload->>'evidence_ref';q.evidence_digest:=decode(p_payload->>'evidence_digest','hex');
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=(p_payload->>'valid_until')::timestamptz;q.provenance:='Explicit isolated market licence enrollment; not a legal verifier';
  IF prior.valid_until IS NOT NULL AND(q.valid_until IS NULL OR q.valid_until>prior.valid_until) THEN RAISE EXCEPTION 'Enrollment cannot outlive source rights' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=generation;g.source:='qualified_policy';g.scope_kind:='data_use';g.data_use_scope_id:=s.scope_id;
  g.qualification_id:=q.qualification_id;g.action:='data_use.'||op;g.reason:='Qualified market source operation';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.expires_at:=q.valid_until;g.delegable:=false;g.delegation_realm_kind:='direct';g.runtime_lock_marker:=false;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  result:=jsonb_build_object('scope_id',s.scope_id,'grant_id',g.id,'qualification_id',q.qualification_id,'versions',jsonb_build_object('grant',1));
 END IF;
 PERFORM builderad_private.record_command(NULL,NULL,p_command,'maintenance.market.'||p_action,payload,result);
 -- A final receipt lock can outlive the source or recipient's qualified window.
 IF p_action IN('provision_source','enroll_data_use') AND
 ((coalesce(c.valid_until,prior.valid_until) IS NOT NULL AND coalesce(c.valid_until,prior.valid_until)<=clock_timestamp())
 OR(q.qualification_id IS NOT NULL AND q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp()))
 THEN RAISE EXCEPTION 'Market enrollment expired before completion' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_market_admit(p_command uuid, p_action text, p_source uuid, p_expected bigint, p_payload jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_market_operations_read(p_session text, p_cursor uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE result jsonb;after_ref uuid;actor bigint;item record;summary jsonb:='[]';validity text;artifact jsonb;outcome jsonb;
BEGIN
 actor:=builderad_private.market_operator(p_session,'market.knowledge.observe');
 -- The entitlement covers only these operational source-custody fields. It is not data-use, processing or publication authority.
 SELECT coalesce(jsonb_agg(jsonb_build_object('source_ref',source_id,'custody_revision',revision,'state',status,
  'rights_window',CASE WHEN valid_from>clock_timestamp() THEN 'not_started' WHEN valid_until<=clock_timestamp() THEN 'expired' ELSE 'current' END,
  'valid_until',valid_until) ORDER BY source_id),'[]') INTO result FROM(
  SELECT c.source_id,c.revision,CASE WHEN s.status='REVOKED' THEN 'revoked' ELSE c.status END AS status,c.valid_from,c.valid_until
  FROM public.market_source_custody c JOIN public.source_registry s ON s.id=c.source_id
  WHERE s.custody_kind='platform_market' AND(p_cursor IS NULL OR c.source_id>p_cursor) ORDER BY c.source_id LIMIT 50
 ) allowed_metadata;
 IF jsonb_array_length(result)=50 THEN
  after_ref:=(result->49->>'source_ref')::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.market_source_custody c JOIN public.source_registry s ON s.id=c.source_id WHERE s.custody_kind='platform_market' AND c.source_id>after_ref) THEN after_ref:=NULL;END IF;
 END IF;
 -- Bounded operational window over the shared owner only; private producer/runs never participate.
 FOR item IN SELECT run_id,method_ref,state,purpose,material_digest FROM public.market_knowledge_captures
  WHERE state IN('prepared','published','retention_removed') ORDER BY created_at DESC,run_id DESC LIMIT 100 LOOP
  validity:=CASE item.state WHEN 'prepared' THEN 'not_published' WHEN 'retention_removed' THEN 'unavailable' ELSE 'unknown' END;
  outcome:=CASE item.state WHEN 'prepared' THEN jsonb_build_object('processing_outcome','unknown','reason_category','none')
   WHEN 'retention_removed' THEN jsonb_build_object('processing_outcome','unavailable','reason_category','unavailable')
   ELSE jsonb_build_object('processing_outcome','unknown','reason_category','unknown') END;
  IF item.state='published' THEN
   artifact:=jsonb_build_object('namespace','market.knowledge','key',item.run_id,'version',encode(item.material_digest,'hex'));
   BEGIN
    PERFORM builderad_private.registered_output_constraints(artifact,'display',item.purpose);
    validity:=builderad_private.knowledge_validity(artifact)->>'status';
    -- Do not load a finding until the existing source/output continuation constraints qualify it.
    IF validity IN('current','review_required') THEN
     SELECT builderad_private.market_knowledge_operational_outcome(item.method_ref,c.finding) INTO outcome
      FROM public.market_knowledge_captures c WHERE c.run_id=item.run_id;
    END IF;
   EXCEPTION WHEN insufficient_privilege THEN validity:='unavailable';
    outcome:=jsonb_build_object('processing_outcome','unavailable','reason_category','unavailable'); END;
  END IF;
  summary:=summary||jsonb_build_array(jsonb_build_object('method_ref',item.method_ref,'publication_state',item.state,'validity',validity)||outcome);
 END LOOP;
 SELECT coalesce(jsonb_agg(jsonb_build_object('method_ref',method_ref,'publication_state',publication_state,'validity',validity_state,'processing_outcome',processing_outcome,'reason_category',reason_category,'count',n)
  ORDER BY method_ref,publication_state,validity_state,processing_outcome,reason_category),'[]') INTO summary FROM(
  SELECT value->>'method_ref' method_ref,value->>'publication_state' publication_state,value->>'validity' validity_state,value->>'processing_outcome' processing_outcome,value->>'reason_category' reason_category,count(*) n
  FROM jsonb_array_elements(summary) GROUP BY value->>'method_ref',value->>'publication_state',value->>'validity',value->>'processing_outcome',value->>'reason_category'
 ) grouped_shared_operations;
 PERFORM builderad_private.market_operator(p_session,'market.knowledge.observe',false);
 RETURN jsonb_build_object('sources',result,'next_cursor',after_ref,'shared_knowledge',summary,'shared_knowledge_window',100);
END $$;


ALTER FUNCTION public.builderad_v2_market_operations_read(p_session text, p_cursor uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_practice_knowledge_options(p_session text, p_business uuid, p_access bigint, p_after integer DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;candidate record;ref jsonb;selection jsonb;read_selection jsonb;material jsonb;recipient uuid;result jsonb;saved jsonb;
BEGIN
 IF p_after IS NULL OR p_after<0 OR p_after>10000 THEN RAISE EXCEPTION 'Bounded cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 read_selection:=builderad_private.business_selection(p_access);
 -- No locks before the material owner's K1 resource preparation.
 SELECT bg.id business_grant,dg.id data_grant,s.purpose,s.resource,k.key INTO candidate
 FROM public.security_action_grants bg JOIN public.security_action_grants dg ON dg.subject_user_id=bg.subject_user_id
 JOIN public.data_use_scopes s ON s.scope_id=dg.data_use_scope_id
 CROSS JOIN LATERAL jsonb_array_elements_text(s.selection->'native_keys') k(key)
 WHERE bg.subject_user_id=actor AND bg.business_id=b AND bg.action='business.data.use' AND bg.scope_kind='business' AND bg.status='active' AND bg.revoked_at IS NULL
 AND dg.action='data_use.display' AND dg.scope_kind='data_use' AND dg.status='active' AND dg.revoked_at IS NULL
 AND bg.delegation_realm_kind=read_selection->>'realm_kind' AND dg.delegation_realm_kind=bg.delegation_realm_kind
 AND s.resource IN('market.knowledge','market.business_finding') AND s.status='active'
 AND s.selection->'field_keys'='["finding","practice_basis"]'::jsonb
 ORDER BY bg.id,dg.id,k.key LIMIT 1 OFFSET p_after;
 result:=jsonb_build_object('business_public_id',p_business,'options','[]'::jsonb,'next_cursor',NULL);
 IF candidate.data_grant IS NOT NULL THEN
  result:=result||jsonb_build_object('next_cursor',p_after+1);
  IF candidate.resource='market.knowledge' THEN SELECT jsonb_build_object('namespace',candidate.resource,'key',run_id,'version',encode(material_digest,'hex')) INTO ref FROM public.market_knowledge_captures WHERE run_id::text=candidate.key AND state='published';
  ELSE SELECT jsonb_build_object('namespace',candidate.resource,'key',artifact_id,'version',encode(material_digest,'hex')) INTO ref FROM public.market_business_finding_captures WHERE artifact_id::text=candidate.key AND state='published' AND business_id=b; END IF;
  IF ref IS NOT NULL THEN
   selection:=builderad_private.business_selection(candidate.business_grant);
   selection:=jsonb_set(selection,'{requirements}',(selection->'requirements')||jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',candidate.data_grant)));
   SELECT public_id INTO recipient FROM public.users WHERE id=actor;
   BEGIN
    material:=public.builderad_v2_scoped_material_read(p_session,p_business,uuidv7(),jsonb_build_array(ref),'display',candidate.purpose,recipient,selection);
    SELECT coalesce(jsonb_agg(x.item),'[]'::jsonb) INTO saved FROM(
     SELECT DISTINCT ON(a.practice_id) jsonb_build_object('practice_id',a.practice_id,'practice_revision',current_practice.revision,'assessment_id',a.assessment_id,
      'relation',(a.result->'relation')-'subject_basis'-'subject_binding','question',a.result->'question') item
     FROM public.business_practice_assessments a
     CROSS JOIN LATERAL(SELECT p.* FROM public.business_practice_revisions p WHERE p.business_id=b AND p.practice_id=a.practice_id ORDER BY p.revision DESC LIMIT 1) current_practice
     WHERE a.business_id=b AND a.actor_id=actor AND a.material_ref=ref AND a.practice_digest=builderad_private.practice_dependency(current_practice)
      AND a.result#>>'{relation,subject_binding}'=encode(sha256(convert_to((material#>'{materials,0,fields,practice_basis}')::text,'UTF8')),'hex')
     ORDER BY a.practice_id,a.recorded_at DESC LIMIT 32
    ) x;
    result:=result||jsonb_build_object('options',jsonb_build_array(jsonb_build_object('material_ref',ref,'purpose',candidate.purpose,'authority_selection',selection,
     'finding',material#>'{materials,0,fields,finding}','practice_basis',material#>'{materials,0,fields,practice_basis}','saved_relations',saved)));
   EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  END IF;
 END IF;
 PERFORM builderad_private.lock_business(p_business);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_practice_knowledge_options(p_session text, p_business uuid, p_access bigint, p_after integer) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_resource_right_command(p_session text, p_command uuid, p_kind text, p_target uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE boundary_ref uuid;fence uuid;b bigint;cohort uuid;mode text;actor bigint;snapshot jsonb;business_proof jsonb;payload bytea;result jsonb;
 old_m public.boundary_subjects;m public.boundary_subjects;prior_g public.security_action_grants;g public.security_action_grants;issuer bigint;action text;
 boundary_row public.client_data_boundaries;branch bigint;
BEGIN
 IF p_command IS NULL OR p_target IS NULL OR p_expected IS NULL OR p_expected<0 OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object' OR octet_length(p_payload::text)>65536
 OR p_kind IS NULL OR p_kind NOT IN('client_boundary','boundary_subject','data_use','source') THEN RAISE EXCEPTION 'Typed resource right command required' USING ERRCODE='22023'; END IF;
 IF p_kind='source' THEN RETURN builderad_private.source_right_command(p_session,p_command,p_target,p_effect,p_payload,p_expected,p_selection); END IF;
 IF p_kind='data_use' THEN RETURN builderad_private.data_use_right_command(p_session,p_command,p_target,p_effect,p_payload,p_expected,p_selection); END IF;
 -- Root creation/qualification has no enabled issuer policy. No caller may bootstrap a right.
 IF p_effect IS NULL OR p_effect NOT IN('attach_subject','detach_subject','revoke') THEN RAISE EXCEPTION 'Qualified resource issuer required; unsupported root operation' USING ERRCODE='42501'; END IF;
 IF p_kind='boundary_subject' THEN
  IF p_effect='attach_subject' THEN
   IF (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['branch_id','business_public_id']::text[]
    OR coalesce(p_payload->>'business_public_id','') !~ '^[0-9a-fA-F-]{36}$' THEN RAISE EXCEPTION 'Exact boundary membership command required' USING ERRCODE='22023'; END IF;
   boundary_ref:=p_target;SELECT id INTO b FROM public.businesses WHERE public_id=(p_payload->>'business_public_id')::uuid;
   IF b IS NULL THEN RAISE EXCEPTION 'Boundary membership denied' USING ERRCODE='42501'; END IF;
   IF p_payload->'branch_id'<>'null'::jsonb THEN
    IF coalesce(p_payload->>'branch_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Typed branch identity required' USING ERRCODE='22023'; END IF;
    branch:=(p_payload->>'branch_id')::bigint;
   END IF;
  ELSIF p_effect='detach_subject' THEN
   IF p_payload<>'{}'::jsonb THEN RAISE EXCEPTION 'Detach selects an exact existing membership, not replacement targets' USING ERRCODE='22023'; END IF;
   SELECT * INTO old_m FROM public.boundary_subjects WHERE membership_id=p_target;
   IF NOT FOUND THEN RAISE EXCEPTION 'Boundary membership denied' USING ERRCODE='42501'; END IF;
   boundary_ref:=old_m.boundary_id;b:=old_m.business_id;branch:=old_m.branch_id;
  ELSE RAISE EXCEPTION 'Typed boundary membership effect required' USING ERRCODE='22023'; END IF;
  action:='client_data.manage';
 ELSE
  IF p_effect<>'revoke' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
   OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Exact boundary grant revoke required' USING ERRCODE='22023'; END IF;
  boundary_ref:=p_target;SELECT * INTO prior_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='client_data' AND boundary_id=boundary_ref;
  IF NOT FOUND THEN RAISE EXCEPTION 'Boundary grant denied' USING ERRCODE='42501'; END IF;action:=prior_g.action;
 END IF;
 IF p_effect='attach_subject' AND NOT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants candidate ON candidate.subject_user_id=l.user_id AND candidate.subject_generation=l.generation
  JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(p_selection->'requirements')='array' THEN p_selection->'requirements' ELSE '[]'::jsonb END) selected(v)
   ON selected.v->>'leaf_grant_id'=candidate.id::text AND selected.v->>'requirement_kind'='business'
  WHERE c.phase='principal' AND candidate.scope_kind='business' AND candidate.business_id=b AND candidate.action='business.link.manage'
   AND candidate.status='active' AND candidate.revoked_at IS NULL AND(candidate.expires_at IS NULL OR candidate.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Business relationship preflight denied' USING ERRCODE='42501'; END IF;
 fence:=builderad_private.lock_boundary(boundary_ref,true);
 -- A boundary owner can withdraw coverage without a Business grant, but must serialize with that Business.
 IF b IS NOT NULL THEN
  SELECT cohort_id INTO cohort FROM public.migration_cohort_members WHERE business_id=b AND kind='business';
  SELECT c.mode INTO mode FROM public.migration_cohort_control c WHERE c.cohort_id=cohort FOR SHARE;
  IF mode IS DISTINCT FROM 'V2_ACTIVE' THEN RAISE EXCEPTION 'Business transition unavailable' USING ERRCODE='42501'; END IF;
  PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR UPDATE;
 END IF;
 actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_scope('client_data',boundary_ref::text,actor,action,p_selection,p_session);
 IF p_effect='attach_subject' THEN
  IF jsonb_array_length(p_selection->'requirements')<>2 THEN RAISE EXCEPTION 'Both boundary and Business authority required' USING ERRCODE='42501'; END IF;
  business_proof:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
  IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required' USING ERRCODE='42501'; END IF;
  snapshot:=snapshot||jsonb_build_object('business_authority',business_proof);
 ELSE
  IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact boundary authority required' USING ERRCODE='42501'; END IF;
 END IF;
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=boundary_ref FOR SHARE;
 IF boundary_row.status<>'active' AND p_effect='attach_subject' THEN RAISE EXCEPTION 'Boundary coverage unavailable' USING ERRCODE='42501'; END IF;
 IF branch IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.business_branches WHERE id=branch AND business_id=b AND status='active') THEN RAISE EXCEPTION 'Qualified branch required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind',p_kind,'target',p_target,'effect',p_effect,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.'||p_effect AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.'||p_effect,jsonb_build_object('boundary_id',boundary_ref,'business_id',b),snapshot);
 IF p_kind='boundary_subject' THEN
  SELECT * INTO old_m FROM public.boundary_subjects WHERE boundary_id=boundary_ref AND business_id=b AND branch_id IS NOT DISTINCT FROM branch ORDER BY revision DESC,membership_id DESC LIMIT 1 FOR UPDATE;
  IF p_effect='detach_subject' AND old_m.membership_id IS DISTINCT FROM p_target THEN RAISE EXCEPTION 'Exact current boundary membership changed before effect' USING ERRCODE='B4090'; END IF;
  IF coalesce(old_m.revision,0)<>p_expected THEN RAISE EXCEPTION 'Boundary membership changed before effect' USING ERRCODE='B4090'; END IF;
  m:=old_m;
  IF p_effect='attach_subject' THEN
   IF old_m.status='active' THEN RAISE EXCEPTION 'Coverage already attached' USING ERRCODE='40001'; END IF;
   m.membership_id:=uuidv7();m.boundary_id:=boundary_ref;m.business_id:=b;m.branch_id:=branch;m.status:='active';m.revision:=coalesce(old_m.revision,0)+1;
   PERFORM builderad_private.permit('public.boundary_subjects','insert',NULL,to_jsonb(m));INSERT INTO public.boundary_subjects SELECT(m).*;
  ELSE
   IF old_m.membership_id IS NULL OR old_m.status<>'active' THEN RAISE EXCEPTION 'Current coverage required' USING ERRCODE='40001'; END IF;
   m.status:='ended';m.revision:=old_m.revision+1;
   PERFORM builderad_private.permit('public.boundary_subjects','update',to_jsonb(old_m),to_jsonb(m));UPDATE public.boundary_subjects SET status=m.status,revision=m.revision WHERE membership_id=m.membership_id;
  END IF;
  PERFORM builderad_private.advance_business_epoch(b);
  result:=jsonb_build_object('membership_id',m.membership_id,'status',m.status,'versions',jsonb_build_object('membership',m.revision));
 ELSE
  issuer:=(snapshot#>>'{grants,0,id}')::bigint;
  SELECT * INTO prior_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint FOR UPDATE;
  IF prior_g.version<>p_expected OR prior_g.status<>'active' THEN RAISE EXCEPTION 'Boundary grant changed' USING ERRCODE='40001'; END IF;
  IF prior_g.parent_grant_id IS DISTINCT FROM issuer AND NOT(prior_g.id=issuer AND prior_g.subject_user_id=actor) THEN RAISE EXCEPTION 'Exact issuer or surrender required' USING ERRCODE='42501'; END IF;
  g:=prior_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior_g),to_jsonb(g));
  UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
  result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 END IF;
 PERFORM builderad_private.advance_right_epoch(fence);PERFORM builderad_private.principal(p_session);
 IF NOT(p_effect='revoke' AND prior_g.id=issuer) THEN PERFORM builderad_private.require_scope('client_data',boundary_ref::text,actor,action,p_selection,p_session); END IF;
 IF p_effect='attach_subject' THEN PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session); END IF;
 PERFORM builderad_private.record_command(b,actor,p_command,'resource.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_resource_right_command(p_session text, p_command uuid, p_kind text, p_target uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

-- Preserve accepted function privileges.
REVOKE ALL ON FUNCTION builderad_private.business_finding_continuation(p_session text, p_actor bigint, p_refs jsonb, p_purpose text, p_selection jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.data_use_right_command(p_session text, p_command uuid, p_scope uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.guard_operating_beneficiary_delegation() FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.knowledge_inputs(p_session text, p_action text, p_refs jsonb, p_purpose text, p_selection jsonb, p_additional_selection jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.knowledge_publication_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.lock_binding_withdrawal(p_business uuid, p_scope uuid) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.market_operator(p_session text, p_action text, p_lock boolean) FROM PUBLIC;
GRANT ALL ON FUNCTION builderad_private.market_operator(p_session text, p_action text, p_lock boolean) TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.market_operator_authorize(p_session text, p_action text, p_selection jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION builderad_private.market_policy(p_scope uuid) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.builderad_v2_boundary_choices(p_session text, p_after bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_boundary_choices(p_session text, p_after bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_data_use_beneficiary(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_recipient uuid, p_until timestamp with time zone, p_expected bigint, p_selection jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_beneficiary(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_recipient uuid, p_until timestamp with time zone, p_expected bigint, p_selection jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_data_use_choices(p_session text, p_after bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_choices(p_session text, p_after bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_data_use_license(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_expected bigint, p_declaration jsonb, p_selection jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_license(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_expected bigint, p_declaration jsonb, p_selection jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_inquiry_revoke(p_session text, p_business uuid, p_command uuid, p_action text, p_selection jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_revoke(p_session text, p_business uuid, p_command uuid, p_action text, p_selection jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_market_admit(p_command uuid, p_action text, p_source uuid, p_expected bigint, p_payload jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_market_admit(p_command uuid, p_action text, p_source uuid, p_expected bigint, p_payload jsonb) TO builderad_owner;
REVOKE ALL ON FUNCTION public.builderad_v2_market_operations_read(p_session text, p_cursor uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_market_operations_read(p_session text, p_cursor uuid) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_practice_knowledge_options(p_session text, p_business uuid, p_access bigint, p_after integer) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_practice_knowledge_options(p_session text, p_business uuid, p_access bigint, p_after integer) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_resource_right_command(p_session text, p_command uuid, p_kind text, p_target uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_v2_resource_right_command(p_session text, p_command uuid, p_kind text, p_target uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) TO builderad_app;
