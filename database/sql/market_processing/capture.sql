-- Existing resource K1 -> Business/cohort -> union authority -> run order.
-- No effects or invocation are created by this reusable qualification boundary.
CREATE FUNCTION builderad_private.processing_authorize(p_session text,p_business uuid,p_refs jsonb,p_purpose text,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;plan jsonb;fences jsonb;proof jsonb;combined jsonb;footprint jsonb;item jsonb;fields jsonb;inputs jsonb:='[]';context jsonb;h public.business_context_heads;
BEGIN
 IF p_refs IS NULL OR jsonb_typeof(p_refs)<>'array' OR jsonb_array_length(p_refs) NOT BETWEEN 1 AND 2
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(p_refs) x WHERE x->>'namespace'<>'market.evidence')
 THEN RAISE EXCEPTION 'Finite original market inputs required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 plan:=builderad_private.material_use_plan(actor,p_business,p_refs,'process',p_purpose,p_selection);
 fences:=builderad_private.lock_material_resources(plan);
 b:=builderad_private.lock_business(p_business);
 combined:=p_selection;
 IF p_context_selection IS NOT NULL THEN
  IF p_context_selection-'requirements' IS DISTINCT FROM p_selection-'requirements' THEN RAISE EXCEPTION 'Same explicit authority realm required' USING ERRCODE='42501'; END IF;
  combined:=jsonb_set(p_selection,'{requirements}',(p_selection->'requirements')||(p_context_selection->'requirements'));
 END IF;
 footprint:=builderad_private.authority_footprint(actor,combined);
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND scope_kind='business' AND business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Exact Business required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,combined,p_session);
 IF builderad_private.material_use_plan(actor,p_business,p_refs,'process',p_purpose,p_selection) IS DISTINCT FROM plan THEN RAISE EXCEPTION 'Processing input set changed' USING ERRCODE='40001'; END IF;
 proof:=builderad_private.require_material_use(actor,b,p_session,'process',plan,p_selection)||jsonb_build_object('dependencies',fences);
 IF p_context_selection IS NOT NULL THEN
  proof:=proof||jsonb_build_object('business_context_proof',builderad_private.require_business(b,actor,'business.context.read',p_context_selection,p_session));
  SELECT * INTO STRICT h FROM public.business_context_heads WHERE business_id=b FOR SHARE;
  context:=jsonb_build_object('context_version',h.context_version,'declared_markets',
   (SELECT coalesce(jsonb_agg(country_code::text ORDER BY country_code COLLATE "C"),'[]') FROM public.business_declared_markets WHERE business_id=b));
 END IF;
 -- Preserve caller reference order (before/after is semantic), not closure sort order.
 FOR item IN SELECT x.value FROM jsonb_array_elements(p_refs) WITH ORDINALITY x(value,ord) ORDER BY x.ord LOOP
  SELECT value INTO STRICT item FROM jsonb_array_elements(plan->'items') WHERE value->'ref'=item;
  fields:=builderad_private.market_evidence_fields(item->'ref',item#>'{scope,selection,field_keys}','process');
  IF fields IS NULL OR(SELECT count(*) FROM jsonb_object_keys(fields))<>jsonb_array_length(item#>'{scope,selection,field_keys}') THEN RAISE EXCEPTION 'Exact licensed processing fields required' USING ERRCODE='42501'; END IF;
  inputs:=inputs||jsonb_build_array(jsonb_build_object('ref',item->'ref','fields',fields,'coverage',item#>'{origin,coverage}','evidence',item#>'{origin,evidence}'));
 END LOOP;
 PERFORM builderad_private.require_material_use(actor,b,p_session,'process',plan,p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(b,actor,'business.context.read',p_context_selection,p_session); END IF;
 RETURN jsonb_build_object('actor',actor,'business_id',b,'plan',plan,'proof',proof,'materials',inputs,'business_context',context,
  'input_digest',encode(sha256(convert_to(jsonb_build_object('materials',inputs,'business_context',context)::text,'UTF8')),'hex'));
END $$;

CREATE FUNCTION builderad_private.processing_surface(r public.market_processing_runs,p_business uuid) RETURNS jsonb
LANGUAGE sql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('run_ref',r.run_id,'business_public_id',p_business,'question',r.question,'method_ref',r.method_ref,
  'purpose',r.purpose,'state',r.state,'finding',r.finding,'reason_code',r.reason_code,'material_refs',r.material_refs,
  'capture_ref',r.run_id,'dependency_digest',encode(r.dependency_digest,'hex'),'published',false,
  'usage',jsonb_build_object('executions',r.execution_count,'elapsed_microseconds',r.elapsed_microseconds,'external_cost','NOT_INCURRED','unmeasured_cost','UNKNOWN','billable',false),
  'versions',jsonb_build_object('processing_contract',1));
$$;

CREATE FUNCTION builderad_v2_market_processing_prepare(p_session text,p_business uuid,p_command uuid,p_question text,p_refs jsonb,p_purpose text,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE q jsonb;r public.market_processing_runs;payload bytea;known jsonb;result jsonb;method text;
BEGIN
 method:=CASE p_question WHEN 'compare_offers' THEN 'offer_equivalence:1' WHEN 'compare_interest' THEN 'qualified_interest_change:1' WHEN 'assess_business_feasibility' THEN 'business_feasibility:1' END;
 IF p_command IS NULL OR method IS NULL OR (p_question='assess_business_feasibility')<>(p_context_selection IS NOT NULL)
 OR jsonb_array_length(p_refs)<>(CASE WHEN p_question='assess_business_feasibility' THEN 1 ELSE 2 END) THEN RAISE EXCEPTION 'Exact question and context contract required' USING ERRCODE='22023'; END IF;
 q:=builderad_private.processing_authorize(p_session,p_business,p_refs,p_purpose,p_selection,p_context_selection);
 payload:=sha256(convert_to(jsonb_build_object('question',p_question,'method',method,'refs',p_refs,'purpose',p_purpose,'selection',p_selection,'context_selection',p_context_selection)::text,'UTF8'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.processing.'||p_command::text,0));
 known:=builderad_private.command_replay((q->>'business_id')::bigint,(q->>'actor')::bigint,p_command,'market.processing.prepare',payload);
 SELECT * INTO r FROM public.market_processing_runs WHERE run_id=p_command;
 IF known IS NOT NULL THEN
  PERFORM builderad_private.require_material_use((q->>'actor')::bigint,(q->>'business_id')::bigint,p_session,'process',q->'plan',p_selection);
  IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business((q->>'business_id')::bigint,(q->>'actor')::bigint,'business.context.read',p_context_selection,p_session); END IF;
  IF r.input_digest<>decode(q->>'input_digest','hex') OR r.session_digest<>sha256(convert_to(p_session,'UTF8')) THEN RAISE EXCEPTION 'Captured input changed' USING ERRCODE='40001'; END IF;
  RETURN known;
 END IF;
 r.run_id:=p_command;r.business_id:=(q->>'business_id')::bigint;r.actor_user_id:=(q->>'actor')::bigint;
 SELECT generation INTO r.generation FROM public.principal_lifecycles WHERE user_id=r.actor_user_id;
 r.session_digest:=sha256(convert_to(p_session,'UTF8'));r.question:=p_question;r.method_ref:=method;r.purpose:=p_purpose;r.material_refs:=p_refs;
 r.request_digest:=payload;r.selection_digest:=sha256(convert_to(jsonb_build_array(p_selection,p_context_selection)::text,'UTF8'));
 r.input_digest:=decode(q->>'input_digest','hex');r.dependency_digest:=decode(q#>>'{plan,closure,dependency_digest}','hex');
 r.state:='prepared';r.finding:=NULL;r.reason_code:=NULL;r.elapsed_microseconds:=0;r.execution_count:=0;r.prepared_at:=clock_timestamp();r.completed_at:=NULL;
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.prepare',jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
 PERFORM builderad_private.permit('public.market_processing_runs','insert',NULL,to_jsonb(r));INSERT INTO public.market_processing_runs SELECT(r).*;
 result:=builderad_private.processing_surface(r,p_business);
 PERFORM builderad_private.record_command(r.business_id,r.actor_user_id,p_command,'market.processing.prepare',payload,result);
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

-- Requalification precedes every release, including terminal replay; no stored payload shortcut.
CREATE FUNCTION builderad_private.processing_input(p_session text,p_business uuid,p_run uuid,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_processing_runs;q jsonb;actor bigint;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO r FROM public.market_processing_runs WHERE run_id=p_run AND actor_user_id=actor AND business_id=(SELECT id FROM public.businesses WHERE public_id=p_business);
 IF r.run_id IS NULL OR r.session_digest<>sha256(convert_to(p_session,'UTF8'))
 OR r.generation<>(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 OR r.selection_digest<>sha256(convert_to(jsonb_build_array(p_selection,p_context_selection)::text,'UTF8')) THEN RAISE EXCEPTION 'Exact captured actor, session and selected rights required' USING ERRCODE='42501'; END IF;
 q:=builderad_private.processing_authorize(p_session,p_business,r.material_refs,r.purpose,p_selection,p_context_selection);
 IF r.input_digest<>decode(q->>'input_digest','hex') OR r.dependency_digest<>decode(q#>>'{plan,closure,dependency_digest}','hex') THEN RAISE EXCEPTION 'Captured input or context changed' USING ERRCODE='40001'; END IF;
 RETURN q||jsonb_build_object('run',builderad_private.processing_surface(r,p_business));
END $$;
CREATE FUNCTION builderad_v2_market_processing_input(p_session text,p_business uuid,p_run uuid,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('run',q->'run','materials',q->'materials','business_context',q->'business_context') FROM
  (SELECT builderad_private.processing_input(p_session,p_business,p_run,p_selection,p_context_selection) q) x;
$$;
