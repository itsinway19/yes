CREATE FUNCTION builderad_v2_market_processing_complete(p_session text,p_business uuid,p_command uuid,p_run uuid,p_effect text,p_outcome jsonb,p_elapsed bigint,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE q jsonb;r public.market_processing_runs;prior public.market_processing_runs;result jsonb;payload bytea;known jsonb;state text;finding jsonb;reason text;v jsonb;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('complete','cancel') OR p_elapsed IS NULL OR p_elapsed NOT BETWEEN 0 AND 30000000 THEN RAISE EXCEPTION 'Bounded completion required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_outcome,ARRAY['state','finding','reason_code']);
 state:=p_outcome->>'state';finding:=nullif(p_outcome->'finding','null');reason:=p_outcome->>'reason_code';
 IF state IS NULL OR state NOT IN('validated','abstained','failed','cancelled','unavailable')
 OR (state IN('validated','abstained'))<>(finding IS NOT NULL) OR (p_effect='cancel' AND state<>'cancelled')
 OR(state IN('failed','cancelled','unavailable') AND coalesce(reason,'') !~ '^[A-Z][A-Z0-9_]{1,100}$')
 OR octet_length(p_outcome::text)>65536 THEN RAISE EXCEPTION 'Typed bounded processing outcome required' USING ERRCODE='22023'; END IF;
 q:=builderad_private.processing_input(p_session,p_business,p_run,p_selection,p_context_selection);
 SELECT * INTO STRICT prior FROM public.market_processing_runs WHERE run_id=p_run FOR UPDATE;r:=prior;
 -- A concurrent completion may have held the run row until selected rights expired.
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'effect',p_effect)::text,'UTF8'));
 known:=builderad_private.command_replay(r.business_id,r.actor_user_id,p_command,'market.processing.'||p_effect,payload);
 IF known IS NOT NULL THEN RETURN builderad_private.processing_surface(r,p_business); END IF;
 IF r.state='prepared' THEN
  IF p_effect='complete' AND state='cancelled' THEN RAISE EXCEPTION 'Cancellation requires its explicit effect' USING ERRCODE='22023'; END IF;
  IF finding IS NOT NULL THEN
   PERFORM builderad_private.market_keys(finding,ARRAY['method_ref','status','reason_codes','values','limitations','evidence_refs']);
   IF finding->>'method_ref' IS DISTINCT FROM r.method_ref OR finding->'evidence_refs' IS DISTINCT FROM r.material_refs
   OR jsonb_typeof(finding->'values') IS DISTINCT FROM 'object' OR jsonb_typeof(finding->'reason_codes') IS DISTINCT FROM 'array'
   OR jsonb_typeof(finding->'limitations') IS DISTINCT FROM 'array'
   OR EXISTS(SELECT 1 FROM jsonb_array_elements((finding->'reason_codes')||(finding->'limitations')) x WHERE jsonb_typeof(x)<>'string' OR x#>>'{}' !~ '^[A-Z][A-Z0-9_]{1,100}$')
   OR NOT(CASE r.method_ref WHEN 'offer_equivalence:1' THEN finding->>'status' IN('equivalent','different','unresolved')
    WHEN 'qualified_interest_change:1' THEN finding->>'status' IN('comparable','not_comparable')
    WHEN 'business_feasibility:1' THEN finding->>'status' IN('within_declared_market','outside_declared_market','requires_information') ELSE false END)
   THEN RAISE EXCEPTION 'Exact typed method handoff required' USING ERRCODE='22023'; END IF;
   IF finding->>'status'='comparable' THEN
    PERFORM builderad_private.market_keys(finding->'values',ARRAY['delta','percent_change']);
    IF jsonb_typeof(finding#>'{values,delta}') IS DISTINCT FROM 'number'
    OR(finding#>'{values,percent_change}'<>'null'::jsonb AND jsonb_typeof(finding#>'{values,percent_change}') IS DISTINCT FROM 'number') THEN RAISE EXCEPTION 'Typed numeric handoff required' USING ERRCODE='22023'; END IF;
   ELSE PERFORM builderad_private.market_keys(finding->'values','{}'); END IF;
   IF(state='abstained') IS DISTINCT FROM(finding->>'status' IN('unresolved','not_comparable','requires_information')) THEN RAISE EXCEPTION 'Finding and terminal state must agree' USING ERRCODE='22023'; END IF;
  END IF;
  r.state:=state;r.finding:=finding;r.reason_code:=reason;r.elapsed_microseconds:=p_elapsed;
  r.execution_count:=CASE WHEN state IN('cancelled','unavailable') THEN 0 ELSE 1 END;r.completed_at:=clock_timestamp();
 END IF;
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.'||p_effect,jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
 IF prior.state='prepared' THEN
  PERFORM builderad_private.permit('public.market_processing_runs','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.market_processing_runs SET state=r.state,finding=r.finding,reason_code=r.reason_code,elapsed_microseconds=r.elapsed_microseconds,
   execution_count=r.execution_count,completed_at=r.completed_at WHERE run_id=r.run_id;
 END IF;
 result:=builderad_private.processing_surface(r,p_business);
 -- Receipts never retain derived/private result fields; delivery requalifies the run.
 PERFORM builderad_private.record_command(r.business_id,r.actor_user_id,p_command,'market.processing.'||p_effect,payload,result-'finding');
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
