CREATE FUNCTION builderad_private.processing_completion_binding(p_run uuid,p_command uuid,p_effect text,p_outcome jsonb,p_elapsed bigint,p_selection jsonb,p_context_selection jsonb) RETURNS bytea
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT sha256(convert_to(jsonb_build_object('run',p_run,'command',p_command,'effect',p_effect,'outcome',p_outcome,
  'elapsed',p_elapsed,'selection',p_selection,'context_selection',p_context_selection)::text,'UTF8'));
$$;
CREATE FUNCTION builderad_private.processing_completion_ticket(p_token text,p_session text,p_business uuid,r public.market_processing_runs,p_command uuid,p_effect text,p_outcome jsonb,p_elapsed bigint,p_selection jsonb,p_context_selection jsonb)
RETURNS public.market_processing_completion_tickets
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE t public.market_processing_completion_tickets;
BEGIN
 IF p_token IS NULL OR p_token !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Trusted completion attestation required' USING ERRCODE='42501'; END IF;
 SELECT * INTO t FROM public.market_processing_completion_tickets WHERE token_hash=encode(sha256(convert_to(p_token,'UTF8')),'hex') FOR UPDATE;
 IF t.token_hash IS NULL OR t.consumed_at IS NOT NULL OR t.expires_at<=clock_timestamp() OR t.created_at>clock_timestamp()
 OR t.backend_pid<>pg_backend_pid() OR t.transaction_id<>pg_current_xact_id() OR t.run_id<>r.run_id
 OR t.command_id<>p_command OR t.actor_user_id<>r.actor_user_id OR t.generation<>r.generation
 OR t.business_public_id<>p_business OR t.method_ref<>r.method_ref
 OR t.session_digest<>sha256(convert_to(p_session,'UTF8'))
 OR t.binding_digest<>builderad_private.processing_completion_binding(r.run_id,p_command,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection)
 THEN RAISE EXCEPTION 'Exact current single-use trusted completion attestation required' USING ERRCODE='42501'; END IF;
 RETURN t;
END $$;
CREATE FUNCTION builderad_private.processing_consume_completion_ticket(t public.market_processing_completion_tickets) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.market_processing_completion_tickets;
BEGIN
 prior:=t;t.consumed_at:=clock_timestamp();
 IF prior.expires_at<=t.consumed_at THEN RAISE EXCEPTION 'Completion attestation expired' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.permit('public.market_processing_completion_tickets','update',to_jsonb(prior),to_jsonb(t));
 UPDATE public.market_processing_completion_tickets SET consumed_at=t.consumed_at WHERE token_hash=t.token_hash AND consumed_at IS NULL;
 IF NOT FOUND THEN RAISE EXCEPTION 'Completion attestation already consumed' USING ERRCODE='42501'; END IF;
END $$;

CREATE FUNCTION builderad_v2_market_processing_complete(p_session text,p_business uuid,p_command uuid,p_run uuid,p_effect text,p_outcome jsonb,p_elapsed bigint,p_selection jsonb,p_context_selection jsonb,p_ticket text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE q jsonb;r public.market_processing_runs;prior public.market_processing_runs;result jsonb;payload bytea;known jsonb;state text;finding jsonb;reason text;v jsonb;ticket public.market_processing_completion_tickets;
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
 IF p_ticket IS NOT NULL OR r.state='prepared' THEN
  ticket:=builderad_private.processing_completion_ticket(p_ticket,p_session,p_business,r,p_command,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection);
 END IF;
 IF ticket.token_hash IS NOT NULL THEN q:=jsonb_set(q,'{proof}',(q->'proof')||jsonb_build_object('completion_attestation',to_jsonb(ticket)-'token_hash')); END IF;
 IF known IS NOT NULL THEN
  IF ticket.token_hash IS NOT NULL THEN
   PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.'||p_effect,jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
   PERFORM builderad_private.processing_consume_completion_ticket(ticket);
   PERFORM builderad_private.assert_invocation_current();
   PERFORM builderad_private.finish_command();
  END IF;
  RETURN builderad_private.processing_surface(r,p_business);
 END IF;
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
  r.state:=state;r.finding:=finding;r.reason_code:=reason;r.elapsed_microseconds:=p_elapsed;r.completion_attestation_digest:=ticket.binding_digest;
  r.execution_count:=CASE WHEN state IN('cancelled','unavailable') THEN 0 ELSE 1 END;r.completed_at:=clock_timestamp();
 END IF;
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.'||p_effect,jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
 IF ticket.token_hash IS NOT NULL THEN PERFORM builderad_private.processing_consume_completion_ticket(ticket); END IF;
 IF prior.state='prepared' THEN
  PERFORM builderad_private.permit('public.market_processing_runs','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.market_processing_runs SET state=r.state,finding=r.finding,reason_code=r.reason_code,elapsed_microseconds=r.elapsed_microseconds,
   execution_count=r.execution_count,completed_at=r.completed_at,completion_attestation_digest=r.completion_attestation_digest WHERE run_id=r.run_id;
 END IF;
 result:=builderad_private.processing_surface(r,p_business);
 -- Receipts never retain derived/private result fields; delivery requalifies the run.
 PERFORM builderad_private.record_command(r.business_id,r.actor_user_id,p_command,'market.processing.'||p_effect,payload,result-'finding');
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 IF ticket.token_hash IS NOT NULL AND ticket.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Completion attestation expired before final commit' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

-- Existing signature stays callable for qualified terminal read/replay only.
-- A new result cannot be created through it: no execution attestation is supplied.
CREATE OR REPLACE FUNCTION builderad_v2_market_processing_complete(p_session text,p_business uuid,p_command uuid,p_run uuid,p_effect text,p_outcome jsonb,p_elapsed bigint,p_selection jsonb,p_context_selection jsonb) RETURNS jsonb
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT public.builderad_v2_market_processing_complete(p_session,p_business,p_command,p_run,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection,NULL::text);
$$;
REVOKE ALL ON FUNCTION builderad_private.processing_completion_binding(uuid,uuid,text,jsonb,bigint,jsonb,jsonb),
 builderad_private.processing_completion_ticket(text,text,uuid,public.market_processing_runs,uuid,text,jsonb,bigint,jsonb,jsonb),
 builderad_private.processing_consume_completion_ticket(public.market_processing_completion_tickets),
 public.builderad_v2_market_processing_complete(text,uuid,uuid,uuid,text,jsonb,bigint,jsonb,jsonb,text)
 FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_v2_market_processing_complete(text,uuid,uuid,uuid,text,jsonb,bigint,jsonb,jsonb,text) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_private.processing_surface(r public.market_processing_runs,p_business uuid) RETURNS jsonb
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF r.state<>'prepared' AND r.completion_attestation_digest IS NULL THEN RAISE EXCEPTION 'Historical completion lacks trusted execution evidence' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('run_ref',r.run_id,'business_public_id',p_business,'question',r.question,'method_ref',r.method_ref,
  'purpose',r.purpose,'state',r.state,'finding',r.finding,'reason_code',r.reason_code,'material_refs',r.material_refs,
  'capture_ref',r.run_id,'dependency_digest',encode(r.dependency_digest,'hex'),'published',false,
  'usage',jsonb_build_object('executions',r.execution_count,'elapsed_microseconds',r.elapsed_microseconds,'external_cost','NOT_INCURRED','unmeasured_cost','UNKNOWN','billable',false),
  'versions',jsonb_build_object('processing_contract',1));
END $$;
