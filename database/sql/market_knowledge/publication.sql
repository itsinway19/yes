-- Both selections are frozen as one resource/authority footprint before any effect.
CREATE FUNCTION builderad_private.knowledge_publication_input(p_session text,p_run uuid,p_output uuid,p_process jsonb,p_publication jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_knowledge_captures;inputs jsonb;proof jsonb;entry jsonb;op text;right_value jsonb;matches integer;
BEGIN
 SELECT * INTO r FROM public.market_knowledge_captures WHERE run_id=p_run;
 IF r.run_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c WHERE c.actor_user_id=r.actor_user_id AND c.phase='principal' AND c.workspace_id IS NULL)
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
REVOKE ALL ON FUNCTION builderad_private.knowledge_publication_input(text,uuid,uuid,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_v2_market_knowledge_input(p_session text,p_run uuid,p_output uuid,p_process jsonb,p_publication jsonb) RETURNS jsonb
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('run',q->'run','materials',q->'materials') FROM
  (SELECT builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication) q) qualified_input;
$$;
REVOKE ALL ON FUNCTION builderad_v2_market_knowledge_input(text,uuid,uuid,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_knowledge_input(text,uuid,uuid,jsonb,jsonb) TO builderad_app;

CREATE FUNCTION builderad_private.knowledge_completion_binding(p_run uuid,p_command uuid,p_output uuid,p_finding jsonb,p_process jsonb,p_publication jsonb) RETURNS bytea
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT sha256(convert_to(jsonb_build_object('run',p_run,'command',p_command,'output',p_output,'finding',p_finding,'process',p_process,'publication',p_publication)::text,'UTF8'));
$$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_completion_binding(uuid,uuid,uuid,jsonb,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_private.knowledge_completion_ticket(p_ticket text,r public.market_knowledge_captures,p_command uuid,p_output uuid,p_finding jsonb,p_process jsonb,p_publication jsonb) RETURNS public.market_knowledge_completion_tickets
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE t public.market_knowledge_completion_tickets;
BEGIN
 IF p_ticket IS NULL OR p_ticket !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Independent execution attestation required' USING ERRCODE='42501'; END IF;
 SELECT * INTO t FROM public.market_knowledge_completion_tickets WHERE token_hash=encode(sha256(convert_to(p_ticket,'UTF8')),'hex') FOR UPDATE;
 IF t.token_hash IS NULL OR t.consumed_at IS NOT NULL OR t.expires_at<=clock_timestamp() OR t.created_at>clock_timestamp()
 OR t.run_id IS DISTINCT FROM r.run_id OR t.command_id IS DISTINCT FROM p_command OR t.actor_user_id IS DISTINCT FROM r.actor_user_id OR t.generation IS DISTINCT FROM r.generation
 OR t.method_ref IS DISTINCT FROM r.method_ref OR t.session_digest IS DISTINCT FROM r.session_digest OR t.backend_pid IS DISTINCT FROM pg_backend_pid() OR t.transaction_id IS DISTINCT FROM pg_current_xact_id()
 OR t.binding_digest IS DISTINCT FROM builderad_private.knowledge_completion_binding(r.run_id,p_command,p_output,p_finding,p_process,p_publication)
 THEN RAISE EXCEPTION 'Exact current one-use shared execution attestation required' USING ERRCODE='42501'; END IF;
 RETURN t;
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_completion_ticket(text,public.market_knowledge_captures,uuid,uuid,jsonb,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_private.knowledge_consume_ticket(t public.market_knowledge_completion_tickets) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.market_knowledge_completion_tickets;
BEGIN
 prior:=t;t.consumed_at:=clock_timestamp();
 IF t.consumed_at>=t.expires_at THEN RAISE EXCEPTION 'Shared completion attestation expired' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.permit('public.market_knowledge_completion_tickets','update',to_jsonb(prior),to_jsonb(t));
 UPDATE public.market_knowledge_completion_tickets SET consumed_at=t.consumed_at WHERE token_hash=t.token_hash AND consumed_at IS NULL;
 IF NOT FOUND THEN RAISE EXCEPTION 'Shared completion attestation already consumed' USING ERRCODE='42501'; END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_consume_ticket(public.market_knowledge_completion_tickets) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_v2_market_knowledge_publish(p_session text,p_command uuid,p_run uuid,p_output uuid,p_finding jsonb,p_process jsonb,p_publication jsonb,p_ticket text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE inputs jsonb;prior public.market_knowledge_captures;r public.market_knowledge_captures;t public.market_knowledge_completion_tickets;old_ticket public.market_knowledge_completion_tickets;
 old public.business_command_receipts;payload bytea;result jsonb;ref jsonb;expected_edges jsonb;input_closure jsonb;root_key text;nodes jsonb;edges jsonb;closure bytea;m public.material_lineage_manifests;dep public.material_use_dependencies;edge jsonb;proof jsonb;
BEGIN
 IF p_command IS NULL OR p_run IS NULL OR p_output IS NULL THEN RAISE EXCEPTION 'Typed publication identity required' USING ERRCODE='22023'; END IF;
 inputs:=builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 SELECT * INTO STRICT prior FROM public.market_knowledge_captures WHERE run_id=p_run FOR UPDATE;r:=prior;
 -- The run-row wait can outlive process authority even when publication rights remain current.
 -- The complete K1/K2/K4 footprint is already frozen; requalification cannot expand it.
 inputs:=builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.publish',p_publication);
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'output',p_output,'process',p_process,'publication',p_publication)::text,'UTF8'));
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF p_ticket IS NOT NULL OR r.state='prepared' THEN t:=builderad_private.knowledge_completion_ticket(p_ticket,r,p_command,p_output,p_finding,p_process,p_publication); END IF;
 IF old.command_id IS NOT NULL THEN
  IF old.action IS DISTINCT FROM 'market.knowledge.publish' OR old.actor_user_id IS DISTINCT FROM r.actor_user_id OR old.payload_digest IS DISTINCT FROM payload OR r.state<>'published' THEN RAISE EXCEPTION 'Publication command conflict' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.material_closure(jsonb_build_array(old.result_ref->'artifact_ref'));
  IF t.token_hash IS NOT NULL THEN
   proof:=(inputs->'publication_proof')||jsonb_build_object('processing',inputs->'proof','completion_attestation',to_jsonb(t)-'token_hash');
   PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.knowledge.publish',jsonb_build_object('run',r.run_id,'artifact',old.result_ref->'artifact_ref'),proof);
   PERFORM builderad_private.knowledge_consume_ticket(t);
   PERFORM builderad_private.assert_invocation_current();
   IF t.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Shared replay attestation expired' USING ERRCODE='42501'; END IF;
   PERFORM builderad_private.finish_command();
  END IF;
  RETURN old.result_ref;
 END IF;
 IF r.state<>'prepared' THEN RAISE EXCEPTION 'Capture already completed; original publication command required' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.market_keys(p_finding,ARRAY['method_ref','status','reason_codes','values','limitations','evidence_refs']);
 IF p_finding->>'method_ref' IS DISTINCT FROM r.method_ref OR p_finding->'evidence_refs' IS DISTINCT FROM r.material_refs OR octet_length(p_finding::text)>65536
 OR NOT(CASE r.method_ref WHEN 'offer_equivalence:1' THEN p_finding->>'status' IN('equivalent','different','unresolved') WHEN 'qualified_interest_change:1' THEN p_finding->>'status' IN('comparable','not_comparable') WHEN 'qualified_price_observation:1' THEN p_finding->>'status' IN('observed','requires_information') WHEN 'qualified_geography_observation:1' THEN p_finding->>'status' IN('observed','requires_information') ELSE false END)
 THEN RAISE EXCEPTION 'Exact validated method output required' USING ERRCODE='22023'; END IF;
 r.continuation_rights:=(inputs#>'{publication_proof,rights}');r.finding:=p_finding;r.completed_at:=clock_timestamp();r.completion_attestation_digest:=t.binding_digest;r.output_scope_id:=p_output;
 r.material_digest:=sha256(convert_to(jsonb_build_object('method',r.method_ref,'purpose',r.purpose,'finding',p_finding,'capture',r.run_id,'inputs',r.input_reads)::text,'UTF8'));
 ref:=jsonb_build_object('namespace','market.knowledge','key',r.run_id,'version',encode(r.material_digest,'hex'));
 SELECT jsonb_agg(v ORDER BY convert_to(v::text,'UTF8')) INTO expected_edges FROM(SELECT DISTINCT value-'field_keys' v FROM jsonb_array_elements(r.input_reads)) x;
 input_closure:=builderad_private.material_closure(r.material_refs);root_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',ref),'hex');
 SELECT jsonb_agg(v ORDER BY k) INTO nodes FROM(
  SELECT encode(builderad_private.lineage_digest('lineage-manifest:1',value->'ref'),'hex') k,jsonb_build_object('ref',value->'ref','kind',value->'kind','material_digest',value->'material_digest','resolver_contract_version',value->'resolver_contract_version') v FROM jsonb_array_elements(input_closure->'materials')
  UNION ALL SELECT root_key,jsonb_build_object('ref',ref,'kind','derived','material_digest',ref->'version','resolver_contract_version',2)
 ) x;
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO edges FROM jsonb_array_elements((input_closure->'dependencies')||
  (SELECT jsonb_agg(jsonb_build_object('from',root_key,'to',encode(builderad_private.lineage_digest('lineage-manifest:1',value->'input'),'hex'),'edge',value)) FROM jsonb_array_elements(expected_edges)));
 closure:=builderad_private.lineage_digest('lineage-manifest:1',jsonb_build_object('materials',nodes,'dependencies',edges));
 proof:=inputs->'publication_proof';proof:=proof||jsonb_build_object('processing',inputs->'proof','completion_attestation',to_jsonb(t)-'token_hash');
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.knowledge.publish',jsonb_build_object('run',r.run_id,'artifact',ref),proof);
 PERFORM builderad_private.knowledge_consume_ticket(t);
 m.manifest_id:=uuidv7();m.material_namespace:='market.knowledge';m.material_key:=r.run_id::text;m.material_version:=ref->>'version';m.kind:='derived';m.producer_receipt_ref:='market_knowledge:'||r.run_id;
 m.resolver_contract_version:=2;m.material_digest:=r.material_digest;m.direct_dependency_count:=jsonb_array_length(expected_edges);m.direct_dependency_digest:=builderad_private.lineage_digest('dependency-set:1',expected_edges);m.closure_digest:=closure;m.state:='sealed';m.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.material_lineage_manifests','insert',NULL,to_jsonb(m));INSERT INTO public.material_lineage_manifests SELECT(m).*;
 FOR edge IN SELECT value FROM jsonb_array_elements(expected_edges) LOOP
  dep.dependency_id:=uuidv7();dep.material_namespace:=m.material_namespace;dep.material_key:=m.material_key;dep.material_version:=m.material_version;
  dep.input_namespace:=edge#>>'{input,namespace}';dep.input_key:=edge#>>'{input,key}';dep.input_version:=edge#>>'{input,version}';dep.data_use_scope_id:=(edge->>'data_use_scope_id')::uuid;
  dep.policy_id:=edge->>'policy_id';dep.policy_version:=(edge->>'policy_version')::bigint;dep.lineage_digest:=closure;
  PERFORM builderad_private.permit('public.material_use_dependencies','insert',NULL,to_jsonb(dep));INSERT INTO public.material_use_dependencies SELECT(dep).*;
 END LOOP;
 r.state:='published';r.publication_manifest_id:=m.manifest_id;
 PERFORM builderad_private.permit('public.market_knowledge_captures','update',to_jsonb(prior),to_jsonb(r));
 UPDATE public.market_knowledge_captures SET state=r.state,finding=r.finding,continuation_rights=r.continuation_rights,completed_at=r.completed_at,completion_attestation_digest=r.completion_attestation_digest,
  output_scope_id=r.output_scope_id,publication_manifest_id=r.publication_manifest_id,material_digest=r.material_digest WHERE run_id=r.run_id;
 result:=jsonb_build_object('artifact_ref',ref,'manifest_id',m.manifest_id,'state','published','versions',jsonb_build_object('publication',1));
 PERFORM builderad_private.record_command(NULL,r.actor_user_id,p_command,'market.knowledge.publish',payload,result);
 PERFORM builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 PERFORM builderad_private.material_closure(jsonb_build_array(ref));
 IF t.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Trusted shared execution expired before release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_knowledge_publish(text,uuid,uuid,uuid,jsonb,jsonb,jsonb,text) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_knowledge_publish(text,uuid,uuid,uuid,jsonb,jsonb,jsonb,text) TO builderad_app;
