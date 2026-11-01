CREATE FUNCTION builderad_private.business_finding_continuation(p_session text,p_actor bigint,p_refs jsonb,p_purpose text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE req jsonb;g public.security_action_grants;s public.data_use_scopes;proof jsonb;policy jsonb;rights jsonb:='[]';ref jsonb;op text;matches integer;
BEGIN
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Explicit direct continuation licence required' USING ERRCODE='42501'; END IF;
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
REVOKE ALL ON FUNCTION builderad_private.business_finding_continuation(text,bigint,jsonb,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_v2_business_finding_capture(p_session text,p_business uuid,p_command uuid,p_run uuid,p_read uuid,p_output uuid,p_process jsonb,p_context jsonb,p_continuation jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;run public.market_processing_runs;r public.market_business_finding_captures;receipt public.business_command_receipts;plan jsonb;extra jsonb;combined jsonb;fences jsonb;q jsonb;rights jsonb;reads jsonb:='[]';item jsonb;right_value jsonb;proof jsonb;result jsonb;payload bytea;old public.business_command_receipts;op text;
BEGIN
 IF p_command IS NULL OR p_run IS NULL OR p_read IS NULL OR p_output IS NULL THEN RAISE EXCEPTION 'Exact private producer command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO run FROM public.market_processing_runs WHERE run_id=p_run AND actor_user_id=actor AND business_id=(SELECT id FROM public.businesses WHERE public_id=p_business);
 IF run.run_id IS NULL OR run.state NOT IN('validated','abstained') OR run.finding IS NULL OR run.completion_attestation_digest IS NULL THEN RAISE EXCEPTION 'Trusted completed private processing required' USING ERRCODE='42501'; END IF;
 plan:=builderad_private.material_use_plan(actor,p_business,run.material_refs,'process',run.purpose,p_process);
 IF p_continuation-'requirements' IS DISTINCT FROM p_process-'requirements' THEN RAISE EXCEPTION 'Same explicit producer authority realm required' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(to_jsonb(s) ORDER BY s.scope_id),'[]') INTO extra FROM public.data_use_scopes s WHERE s.scope_id IN(
  SELECT g.data_use_scope_id FROM public.security_action_grants g JOIN jsonb_array_elements(p_continuation->'requirements') req ON req->>'leaf_grant_id'=g.id::text);
 plan:=jsonb_set(plan,'{lineage_scopes}',(plan->'lineage_scopes')||extra);
 fences:=builderad_private.lock_material_resources(plan,(SELECT to_jsonb(s) FROM public.data_use_scopes s WHERE scope_id=p_output));
 PERFORM 1 FROM public.market_evidence_heads WHERE evidence_id IN(SELECT(value->>'key')::uuid FROM jsonb_array_elements(run.material_refs)) ORDER BY evidence_id FOR SHARE;
 b:=builderad_private.lock_business(p_business);
 combined:=jsonb_set(p_process,'{requirements}',(p_process->'requirements')||(p_continuation->'requirements')||coalesce(p_context->'requirements','[]'));
 PERFORM builderad_private.lock_authority_footprint(actor,combined,p_session);
 q:=builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
 rights:=builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
 SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=p_read;
 IF receipt.action IS DISTINCT FROM 'material.process' OR receipt.actor_user_id IS DISTINCT FROM actor OR receipt.business_id IS DISTINCT FROM b OR receipt.generation IS DISTINCT FROM run.generation
 OR receipt.result_ref->>'purpose' IS DISTINCT FROM run.purpose THEN RAISE EXCEPTION 'Actual same-Business processing read receipt required' USING ERRCODE='42501'; END IF;
 FOR item IN SELECT value FROM jsonb_array_elements(q#>'{proof,material_uses}') LOOP
  IF NOT(receipt.result_ref->'material_uses') @> jsonb_build_array(item) THEN RAISE EXCEPTION 'Original input read receipt mismatch' USING ERRCODE='42501'; END IF;
  FOREACH op IN ARRAY ARRAY['forward','retain'] LOOP
   SELECT value INTO right_value FROM jsonb_array_elements(rights) v WHERE v#>>'{scope,operation}'=op AND(v#>'{scope,selection,native_keys}') ? (item#>>'{ref,key}');
   IF NOT(right_value#>'{scope,selection,field_keys}') @> (SELECT selection->'field_keys' FROM public.data_use_scopes WHERE scope_id=(item->>'data_use_scope_id')::uuid) THEN RAISE EXCEPTION 'Continuing field scope cannot narrow the captured finding licence' USING ERRCODE='42501'; END IF;
  END LOOP;
  reads:=reads||jsonb_build_array(jsonb_build_object('input',item->'ref','data_use_scope_id',item->'data_use_scope_id','policy_id',item->'policy_id','policy_version',item->'policy_version','read_command_id',p_read));
 END LOOP;
 IF NOT EXISTS(SELECT 1 FROM public.data_use_scopes s WHERE scope_id=p_output AND s.rights_domain='market' AND s.resource='market.business_finding' AND s.operation='display'
  AND s.purpose=run.purpose AND s.selection->'native_namespaces'='["market.business_finding"]'::jsonb AND s.selection->'native_keys'=jsonb_build_array(p_command::text) AND s.selection->'field_keys'='["finding"]'::jsonb)
 THEN RAISE EXCEPTION 'Exact independent private output licence required' USING ERRCODE='42501'; END IF;
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'read',p_read,'output',p_output,'process',p_process,'context',p_context,'continuation',p_continuation)::text,'UTF8'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.private.finding.'||p_command,0));
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.action IS DISTINCT FROM 'market.business_finding.capture' OR old.actor_user_id IS DISTINCT FROM actor OR old.business_id IS DISTINCT FROM b OR old.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Private capture command conflict' USING ERRCODE='40001'; END IF;
  -- Replay has the same current processing/continuation contract after a command-lock wait.
  PERFORM builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
  PERFORM builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
  RETURN old.result_ref;
 END IF;
 r.artifact_id:=p_command;r.processing_run_id:=run.run_id;r.business_id:=b;r.processor_user_id:=actor;r.output_scope_id:=p_output;r.purpose:=run.purpose;r.input_reads:=reads;r.continuation_rights:=rights;
 r.context_snapshot:=q->'business_context';r.state:='prepared';r.created_at:=clock_timestamp();
 r.material_digest:=sha256(convert_to(jsonb_build_object('artifact',r.artifact_id,'run',run.run_id,'finding',run.finding,'attestation',encode(run.completion_attestation_digest,'hex'),'business',p_business,'purpose',r.purpose,'reads',reads,'context',r.context_snapshot)::text,'UTF8'));
 proof:=(q->'proof')||jsonb_build_object('continuation_rights',rights,'dependencies',fences);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.business_finding.capture',jsonb_build_object('business_id',b,'artifact',r.artifact_id),proof);
 PERFORM builderad_private.permit('public.market_business_finding_captures','insert',NULL,to_jsonb(r));INSERT INTO public.market_business_finding_captures SELECT(r).*;
 result:=jsonb_build_object('artifact_ref',jsonb_build_object('namespace','market.business_finding','key',r.artifact_id,'version',encode(r.material_digest,'hex')),
  'dependencies',builderad_private.producer_dependencies(jsonb_build_object('input_reads',reads)),'versions',jsonb_build_object('capture',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'market.business_finding.capture',payload,result);
 PERFORM builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
 PERFORM builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_business_finding_capture(text,uuid,uuid,uuid,uuid,uuid,jsonb,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_finding_capture(text,uuid,uuid,uuid,uuid,uuid,jsonb,jsonb,jsonb) TO builderad_app;
