-- Exact C03 preparation and final resource owner, extracted from the verified C03 candidate before C05 edits.
CREATE OR REPLACE FUNCTION builderad_private.lock_material_resources(p_plan jsonb,p_output_scope jsonb DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE scopes jsonb;sources uuid[];boundaries uuid[];market_scopes uuid[];gates uuid[];gate record;
BEGIN
 SELECT coalesce(jsonb_agg(value->'scope'),'[]') INTO scopes FROM jsonb_array_elements(p_plan->'items');
 scopes:=scopes||(p_plan->'lineage_scopes')||CASE WHEN p_output_scope IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(p_output_scope) END;
 IF p_output_scope->>'resource'='market.business_finding' THEN
  scopes:=scopes||coalesce((SELECT jsonb_agg(v->'scope') FROM public.market_business_finding_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v
   WHERE c.output_scope_id=(p_output_scope->>'scope_id')::uuid AND c.state IN('prepared','published')),'[]'::jsonb);
 END IF;
 SELECT ARRAY(SELECT DISTINCT(value->>'source_id')::uuid FROM jsonb_array_elements(scopes)),
 ARRAY(SELECT DISTINCT(value->>'boundary_id')::uuid FROM jsonb_array_elements(scopes) WHERE value->>'boundary_id' IS NOT NULL),
 ARRAY(SELECT DISTINCT(value->>'scope_id')::uuid FROM jsonb_array_elements(scopes) WHERE value->>'rights_domain'='market') INTO sources,boundaries,market_scopes;
 SELECT array_agg(fence_id ORDER BY fence_id) INTO gates FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(sources)) OR(kind='client_boundary' AND boundary_id=ANY(boundaries)) OR(kind='data_use' AND data_use_scope_id=ANY(market_scopes));
 IF coalesce(cardinality(gates),0)<>cardinality(sources)+cardinality(boundaries)+cardinality(market_scopes) THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE fence_id=ANY(gates) ORDER BY fence_id FOR SHARE LOOP
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 END LOOP;
 RETURN(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE fence_id=ANY(gates));
END $$;

CREATE OR REPLACE FUNCTION builderad_v2_expenditure_summary(p_session text,p_business uuid,p_command uuid,p_summary uuid,p_input jsonb,p_license uuid,p_read uuid,p_confirmed boolean,p_until timestamptz,p_consent jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;input_selection jsonb;plan jsonb;proof jsonb;fences jsonb;
 payload bytea;known jsonb;result jsonb;artifact jsonb;authenticated timestamptz;manager_id bigint;
 input_scope public.data_use_scopes;s public.data_use_scopes;q public.authority_qualification_receipts;g public.security_action_grants;
 r public.business_expenditure_summaries;binding public.business_data_use_bindings;read_receipt public.business_command_receipts;
BEGIN
 IF p_command IS NULL OR p_summary IS NULL OR uuid_extract_version(p_summary) IS DISTINCT FROM 7 OR p_license IS NULL OR p_read IS NULL
 OR p_confirmed IS DISTINCT FROM true OR p_until IS NULL OR NOT isfinite(p_until) OR p_until<=clock_timestamp()
 OR p_consent IS DISTINCT FROM '{"derive":true,"deliver_summary":true,"retain_payload":false}'::jsonb
 THEN RAISE EXCEPTION 'Explicit finite derive and summary-delivery consent required; no retained payload' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO input_scope FROM public.data_use_scopes WHERE scope_id=p_license;
 IF input_scope.scope_id IS NULL OR input_scope.rights_domain<>'client' OR input_scope.operation<>'process'
 OR input_scope.selection->'field_keys' IS DISTINCT FROM '["spend"]'::jsonb OR p_input->>'namespace' IS DISTINCT FROM 'provider_observation_entry'
 THEN RAISE EXCEPTION 'Existing exact expenditure-processing licence required; display is not derivation' USING ERRCODE='42501'; END IF;
 IF jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 5 THEN RAISE EXCEPTION 'Exact independent use, binding, data-authority, process and Source paths required' USING ERRCODE='42501'; END IF;
 SELECT min(g1.id) INTO manager_id FROM public.security_action_grants g1 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text
 WHERE x->>'requirement_kind'='business' AND g1.action='business.link.manage';
 input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(x) FROM jsonb_array_elements(p_selection->'requirements') x
 WHERE x->>'requirement_kind'<>'client_data' AND x->>'leaf_grant_id' IS DISTINCT FROM manager_id::text));
 plan:=builderad_private.material_use_plan(actor,p_business,jsonb_build_array(p_input),'process',input_scope.purpose,input_selection);
 IF plan#>>'{items,0,scope,scope_id}' IS DISTINCT FROM p_license::text THEN RAISE EXCEPTION 'Exact input licence required' USING ERRCODE='42501'; END IF;
 fences:=builderad_private.lock_material_resources(plan);b:=builderad_private.lock_business(p_business);
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 proof:=builderad_private.require_material_use(actor,b,p_session,'process',plan,input_selection);
 proof:=proof||jsonb_build_object('data_authority',builderad_private.require_scope('client_data',input_scope.boundary_id::text,actor,'client_data.manage',p_selection,p_session),
  'binding_authority',builderad_private.require_scope('business',b::text,actor,'business.link.manage',builderad_private.business_selection(manager_id),p_session),'dependencies',fences);
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit summary consent required' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.security_action_grants g1 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text
 WHERE g1.expires_at IS NOT NULL AND p_until>g1.expires_at)
 OR EXISTS(SELECT 1 FROM public.authority_qualification_receipts q1 JOIN public.security_action_grants g1 USING(qualification_id)
 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text WHERE q1.valid_until IS NOT NULL AND p_until>q1.valid_until)
 THEN RAISE EXCEPTION 'Summary cannot outlast its independent authority' USING ERRCODE='42501'; END IF;
 SELECT * INTO read_receipt FROM public.business_command_receipts WHERE command_id=p_read;
 IF read_receipt.actor_user_id IS DISTINCT FROM actor OR read_receipt.business_id IS DISTINCT FROM b
 OR read_receipt.action IS DISTINCT FROM 'material.process' OR read_receipt.result_ref->>'purpose' IS DISTINCT FROM input_scope.purpose
 OR NOT(read_receipt.result_ref->'material_refs' @> jsonb_build_array(p_input))
 THEN RAISE EXCEPTION 'Existing exact authorized processing receipt required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'summary',p_summary,'input',p_input,'license',p_license,'read',p_read,'until',p_until,'consent',p_consent,'selection',p_selection),'{"business":"U","summary":"U","license":"U","read":"U","until":"T"}');
 known:=builderad_private.command_replay(b,actor,p_command,'expenditure_summary.issue',payload);
 IF known IS NOT NULL THEN
  PERFORM builderad_private.expenditure_summary_constraints(known->'artifact_ref','display',input_scope.purpose);RETURN known;
 END IF;
 IF EXISTS(SELECT 1 FROM public.business_expenditure_summaries WHERE summary_id=p_summary) THEN RAISE EXCEPTION 'Reconcile original summary command' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('summary_consent',p_consent,'summary_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',least(p_until,authenticated+interval '15 minutes')));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'expenditure_summary.issue',jsonb_build_object('business_id',b,'scope_id',p_summary),proof);
 s:=input_scope;s.scope_id:=p_summary;s.resource:='business.expenditure_summary';s.operation:='display';s.revision:=1;
 s.selection:=builderad_private.canonical_data_selection(jsonb_build_object('native_namespaces',jsonb_build_array('business.expenditure_summary'),'native_keys',jsonb_build_array(p_summary::text),'field_keys',jsonb_build_array('spend'),'partition_ref',NULL));
 s.scope_digest:=builderad_private.data_scope_digest(s.boundary_id,s.source_id,s.resource,s.operation,s.purpose,s.selection);
 PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
 q.qualification_id:=uuidv7();q.policy_id:='declared_data_license_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.subject_user_id:=actor;
 q.target_kind:='data_use';q.target_ref:=s.scope_id;q.allowed_actions:=ARRAY['data_use.display'];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
 q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;q.provenance:='Explicit finite same-Business expenditure derivation and summary delivery; no retained derived payload, original forwarding, Source or advertising authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:='data_use.display';g.scope_kind:='data_use';g.data_use_scope_id:=s.scope_id;
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
 g.reason:='Explicit finite expenditure summary';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
 g.qualification_id:=q.qualification_id;g.expires_at:=p_until;g.delegable:=false;g.delegation_realm_kind:=p_selection->>'realm_kind';
 SELECT id INTO g.delegation_workspace_id FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid;
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 binding.binding_id:=uuidv7();binding.business_id:=b;binding.data_use_scope_id:=s.scope_id;binding.status:='active';binding.revision:=1;binding.qualification_ref:=q.qualification_id;
 PERFORM builderad_private.permit('public.business_data_use_bindings','insert',NULL,to_jsonb(binding));INSERT INTO public.business_data_use_bindings SELECT(binding).*;
 r.summary_id:=p_summary;r.business_id:=b;r.processor_user_id:=actor;r.output_scope_id:=s.scope_id;r.purpose:=s.purpose;
 r.material_digest:=builderad_private.digest('command-payload:1',builderad_private.expenditure_summary_value(p_input));
 SELECT jsonb_build_array(jsonb_build_object('input',p_input,'read_command_id',p_read,'data_use_scope_id',p_license,
  'policy_id',value->'policy_id','policy_version',value->'policy_version')) INTO r.input_reads
 FROM jsonb_array_elements(read_receipt.result_ref->'material_uses') value WHERE value->'ref'=p_input AND value->>'data_use_scope_id'=p_license::text;
 IF r.input_reads IS NULL THEN RAISE EXCEPTION 'Exact processing licence receipt required' USING ERRCODE='42501'; END IF;
 r.input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(x) FROM jsonb_array_elements(p_selection->'requirements') x WHERE x->>'leaf_grant_id' IS DISTINCT FROM manager_id::text));
 r.valid_until:=p_until;r.consent:=p_consent;r.created_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.business_expenditure_summaries','insert',NULL,to_jsonb(r));INSERT INTO public.business_expenditure_summaries SELECT(r).*;
 -- Use the established scope/binding invalidation clocks. Replay returns above.
 PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
 PERFORM builderad_private.advance_business_epoch(b);
 artifact:=jsonb_build_object('namespace','business.expenditure_summary','key',p_summary::text,'version',encode(r.material_digest,'hex'));
 result:=jsonb_build_object('business_public_id',p_business,'artifact_ref',artifact,'dependencies',builderad_private.producer_dependencies(builderad_private.producer_descriptor(artifact)),
  'license_id',s.scope_id,'use_grant_id',g.id::text,'status','prepared','versions',jsonb_build_object('summary',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'expenditure_summary.issue',payload,result);
 PERFORM builderad_private.expenditure_summary_constraints(artifact,'display',s.purpose);
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',builderad_private.business_selection(manager_id),p_session);
 PERFORM builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session);
 IF authenticated+interval '15 minutes'<=clock_timestamp() THEN RAISE EXCEPTION 'Summary confirmation expired before release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_private.expenditure_summary_value(jsonb),builderad_private.expenditure_summary_descriptor(jsonb),builderad_private.expenditure_summary_constraints(jsonb,text,text),builderad_private.expenditure_summary_publish(jsonb,uuid,bytea) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_v2_expenditure_summary(text,uuid,uuid,uuid,jsonb,uuid,uuid,boolean,timestamptz,jsonb,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_expenditure_summary(text,uuid,uuid,uuid,jsonb,uuid,uuid,boolean,timestamptz,jsonb,jsonb) TO builderad_app;
