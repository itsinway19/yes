CREATE FUNCTION builderad_private.knowledge_inputs(p_session text,p_action text,p_refs jsonb,p_purpose text,p_selection jsonb,p_additional_selection jsonb DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal' AND c.workspace_id IS NULL;
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
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_inputs(text,text,jsonb,text,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_v2_market_knowledge_capture(p_session text,p_command uuid,p_question text,p_refs jsonb,p_purpose text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE inputs jsonb;r public.market_knowledge_captures;actor bigint;payload bytea;prior public.business_command_receipts;result jsonb;
BEGIN
 IF p_command IS NULL OR p_question IS NULL OR p_question NOT IN('compare_offers','compare_interest','record_price','record_geography') THEN RAISE EXCEPTION 'Registered shared market method required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(p_refs) IS DISTINCT FROM(CASE WHEN p_question IN('compare_offers','compare_interest') THEN 2 ELSE 1 END) THEN RAISE EXCEPTION 'Exact registered method input count required' USING ERRCODE='22023'; END IF;
 inputs:=builderad_private.knowledge_inputs(p_session,'market.knowledge.capture',p_refs,p_purpose,p_selection);actor:=(inputs#>>'{proof,actor}')::bigint;
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.market.knowledge.capture.'||p_command,0));
 payload:=sha256(convert_to(jsonb_build_object('question',p_question,'refs',p_refs,'purpose',p_purpose,'selection',p_selection)::text,'UTF8'));
 SELECT * INTO prior FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF prior.action IS DISTINCT FROM 'market.knowledge.capture' OR prior.actor_user_id IS DISTINCT FROM actor OR prior.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Capture command conflict' USING ERRCODE='40001'; END IF;
  SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=p_command;
  IF r.generation IS DISTINCT FROM (SELECT generation FROM public.principal_lifecycles WHERE user_id=actor) OR r.session_digest IS DISTINCT FROM sha256(convert_to(p_session,'UTF8'))
   OR r.input_digest IS DISTINCT FROM decode(inputs->>'digest','hex') THEN RAISE EXCEPTION 'Capture input or actor changed' USING ERRCODE='42501'; END IF;
  -- The command lock can outlive a previously qualified licence or session.
  -- Recheck within the already frozen footprint before disclosing a durable receipt.
  PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.capture',p_selection);
  RETURN prior.result_ref;
 END IF;
 r.run_id:=p_command;r.actor_user_id:=actor;SELECT generation INTO r.generation FROM public.principal_lifecycles WHERE user_id=actor;
 r.session_digest:=sha256(convert_to(p_session,'UTF8'));r.question:=p_question;r.method_ref:=CASE p_question WHEN 'compare_offers' THEN 'offer_equivalence:1' WHEN 'compare_interest' THEN 'qualified_interest_change:1' WHEN 'record_price' THEN 'qualified_price_observation:1' ELSE 'qualified_geography_observation:1' END;
 r.purpose:=p_purpose;r.material_refs:=p_refs;r.input_digest:=decode(inputs->>'digest','hex');r.input_reads:=inputs->'reads';r.state:='prepared';r.created_at:=clock_timestamp();
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.knowledge.capture',jsonb_build_object('run',r.run_id),inputs->'proof');
 PERFORM builderad_private.permit('public.market_knowledge_captures','insert',NULL,to_jsonb(r));INSERT INTO public.market_knowledge_captures SELECT(r).*;
 result:=jsonb_build_object('run_ref',r.run_id,'state',r.state,'method_ref',r.method_ref,'versions',jsonb_build_object('capture',1));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'market.knowledge.capture',payload,result);
 PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.capture',p_selection);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_knowledge_capture(text,uuid,text,jsonb,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_knowledge_capture(text,uuid,text,jsonb,text,jsonb) TO builderad_app;
