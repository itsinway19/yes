-- The prepared descriptor comes from its fixed private custodian, never from the publish body.
CREATE FUNCTION builderad_private.producer_descriptor(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;r jsonb;receipt public.business_command_receipts;ids uuid[]:='{}';
BEGIN
 PERFORM builderad_private.material_reference(p_ref);d:=builderad_private.registered_producer(p_ref);
 PERFORM builderad_private.bounded(d);
 IF jsonb_typeof(d) IS DISTINCT FROM 'object' OR d->'ref' IS DISTINCT FROM p_ref
 OR d->>'material_digest' IS DISTINCT FROM p_ref->>'version' OR coalesce(d->>'material_digest','') !~ '^[0-9a-f]{64}$'
 OR d->'resolver_contract_version' IS DISTINCT FROM '1'::jsonb OR d->>'processing_operation' IS DISTINCT FROM 'process'
 OR jsonb_typeof(d->'coverage') IS DISTINCT FROM 'string' OR d->>'coverage' NOT IN('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')
 OR jsonb_typeof(d->'provenance') IS DISTINCT FROM 'object'
 OR octet_length(coalesce(d->>'producer_receipt_ref','')) NOT BETWEEN 1 AND 4096
 OR coalesce(d->>'business_public_id','') !~ '^[0-9a-f-]{36}$' OR coalesce(d->>'output_scope_id','') !~ '^[0-9a-f-]{36}$'
 OR coalesce(d->>'processor_user_id','') !~ '^[1-9][0-9]{0,17}$' OR coalesce(d->>'purpose','')='' OR length(d->>'purpose')>128
 OR jsonb_typeof(d->'input_reads') IS DISTINCT FROM 'array' OR jsonb_array_length(d->'input_reads') NOT BETWEEN 1 AND 64
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: incomplete prepared producer record' USING ERRCODE='42501'; END IF;
 FOR r IN SELECT value FROM jsonb_array_elements(d->'input_reads') LOOP
  IF jsonb_typeof(r) IS DISTINCT FROM 'object' OR
   (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(r) k) IS DISTINCT FROM ARRAY['data_use_scope_id','input','policy_id','policy_version','read_command_id']::text[]

   OR coalesce(r->>'data_use_scope_id','') !~ '^[0-9a-f-]{36}$' OR coalesce(r->>'read_command_id','') !~ '^[0-9a-f-]{36}$'
   OR octet_length(coalesce(r->>'policy_id','')) NOT BETWEEN 1 AND 180 OR jsonb_typeof(r->'policy_version') IS DISTINCT FROM 'number' OR coalesce(r->>'policy_version','') !~ '^[1-9][0-9]{0,17}$'
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact captured input required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.material_reference(r->'input');
  SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=(r->>'read_command_id')::uuid;
  IF NOT FOUND OR receipt.actor_user_id<>(d->>'processor_user_id')::bigint OR receipt.action<>'material.process'
   OR receipt.business_id IS DISTINCT FROM(SELECT id FROM public.businesses WHERE public_id=(d->>'business_public_id')::uuid)
   OR receipt.result_ref->>'purpose' IS DISTINCT FROM d->>'purpose' OR (receipt.result_ref->'material_uses' @> jsonb_build_array(jsonb_build_object('ref',r->'input','data_use_scope_id',r->'data_use_scope_id','policy_id',r->'policy_id','policy_version',r->'policy_version'))) IS DISTINCT FROM true
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: missing original authorized read receipt' USING ERRCODE='42501'; END IF;
 END LOOP;
 RETURN d;
END $$;

CREATE FUNCTION builderad_private.producer_dependencies(p_descriptor jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_agg(edge ORDER BY convert_to(edge::text,'UTF8')) FROM(
  SELECT DISTINCT value-'read_command_id' edge FROM jsonb_array_elements(p_descriptor->'input_reads')
 ) x
$$;

CREATE FUNCTION builderad_private.output_use_plan(p_actor bigint,p_business uuid,p_ref jsonb,p_purpose text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;s public.data_use_scopes;matches integer;leaf bigint;
BEGIN
 d:=builderad_private.producer_descriptor(p_ref);
 IF d->>'business_public_id' IS DISTINCT FROM p_business::text OR d->>'purpose' IS DISTINCT FROM p_purpose THEN RAISE EXCEPTION 'Exact producer Business/purpose required' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(d->>'output_scope_id')::uuid;
 IF NOT FOUND OR s.operation<>'display' OR s.purpose<>p_purpose
 OR NOT(s.selection->'native_namespaces' ? (p_ref->>'namespace')) OR NOT(s.selection->'native_keys' ? (p_ref->>'key'))
 THEN RAISE EXCEPTION 'Exact independent output-display scope required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.authority_footprint(p_actor,p_selection);
 SELECT count(*),min(g.id) INTO matches,leaf FROM public.security_action_grants g JOIN jsonb_array_elements(p_selection->'requirements') r
 ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
 WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.data_use_scope_id=s.scope_id AND g.action='data_use.display';
 IF matches<>1 THEN RAISE EXCEPTION 'Exactly one explicitly selected output entitlement required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('descriptor',d,'scope',to_jsonb(s),'grant_id',leaf);
END $$;

CREATE FUNCTION builderad_private.require_output_use(p_actor bigint,p_business bigint,p_session text,p_plan jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;proof jsonb;
BEGIN
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(p_plan#>>'{scope,scope_id}')::uuid;
 IF to_jsonb(s) IS DISTINCT FROM p_plan->'scope' OR s.status<>'active' OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=p_business AND branch_id IS NULL AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
 THEN RAISE EXCEPTION 'Current output boundary and use binding required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.display',p_selection,p_session);
 PERFORM builderad_private.registered_output_constraints(p_plan#>'{descriptor,ref}','display',s.purpose);
 RETURN proof;
END $$;

CREATE FUNCTION builderad_private.producer_material(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;m public.material_lineage_manifests;edges jsonb;
BEGIN
 d:=builderad_private.producer_descriptor(p_ref);
 SELECT * INTO m FROM public.material_lineage_manifests WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF NOT FOUND OR m.kind<>'derived' OR d->'publication_manifest_id' IS DISTINCT FROM to_jsonb(m.manifest_id)
 OR m.producer_receipt_ref IS DISTINCT FROM d->>'producer_receipt_ref' OR m.material_digest<>decode(d->>'material_digest','hex') OR m.resolver_contract_version<>1
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: registered output is not atomically published' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('input',jsonb_build_object('namespace',input_namespace,'key',input_key,'version',input_version),
  'data_use_scope_id',data_use_scope_id,'policy_id',policy_id,'policy_version',policy_version) ORDER BY input_namespace,input_key,input_version),'[]') INTO edges
 FROM public.material_use_dependencies WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF(SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) FROM jsonb_array_elements(edges)) IS DISTINCT FROM builderad_private.producer_dependencies(d)
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: captured and stored input sets differ' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'kind','derived','material_digest',d->'material_digest','resolver_contract_version',1,'producer_receipt_ref',m.producer_receipt_ref,
 'direct_dependency_count',m.direct_dependency_count,'direct_dependency_digest',encode(m.direct_dependency_digest,'hex'),'closure_digest',encode(m.closure_digest,'hex'),'dependencies',edges,
 'origin',jsonb_build_object('registered_producer',true,'business_public_id',d->'business_public_id','output_scope_id',d->'output_scope_id'));
END $$;

CREATE FUNCTION builderad_private.require_publish_use(p_actor bigint,p_business bigint,p_session text,p_plan jsonb,p_input_selection jsonb,p_output jsonb,p_full_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE proof jsonb;item jsonb;edge jsonb;policy jsonb;
BEGIN
 proof:=builderad_private.require_material_use(p_actor,p_business,p_session,'process',p_plan,p_input_selection);
 FOR edge IN SELECT value FROM jsonb_array_elements(builderad_private.producer_dependencies(p_output->'descriptor')) LOOP
  SELECT value INTO item FROM jsonb_array_elements(p_plan->'items') WHERE value->'ref'=edge->'input';
  IF item IS NULL OR item#>'{scope,scope_id}' IS DISTINCT FROM edge->'data_use_scope_id' THEN RAISE EXCEPTION 'Captured input license cannot be silently replaced' USING ERRCODE='42501'; END IF;
  policy:=builderad_private.require_scope('data_use',edge->>'data_use_scope_id',p_actor,'data_use.process',p_input_selection,p_session);
  IF policy#>'{qualification,policy_id}' IS DISTINCT FROM edge->'policy_id' OR policy#>'{qualification,policy_version}' IS DISTINCT FROM edge->'policy_version'
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: captured processing policy changed' USING ERRCODE='42501'; END IF;
 END LOOP;
 proof:=proof||jsonb_build_object('output',builderad_private.require_output_use(p_actor,p_business,p_session,p_output,p_full_selection));
 PERFORM builderad_private.principal(p_session);RETURN proof;
END $$;

CREATE FUNCTION builderad_v2_scoped_material_publish(p_session text,p_business uuid,p_command uuid,p_ref jsonb,p_dependencies jsonb,p_purpose text,p_recipient uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;output_plan jsonb;input_plan jsonb;input_selection jsonb;refs jsonb;expected_edges jsonb;proposed jsonb;proof jsonb;footprint jsonb;
 fences jsonb;payload bytea;known jsonb;result jsonb;nodes jsonb;edges jsonb;root_key text;closure bytea;direct_hash bytea;
 m public.material_lineage_manifests;dep public.material_use_dependencies;edge jsonb;
BEGIN
 IF p_business IS NULL OR p_command IS NULL OR p_recipient IS NULL OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128
 OR p_dependencies IS NULL OR jsonb_typeof(p_dependencies) IS DISTINCT FROM 'array' OR jsonb_array_length(p_dependencies) NOT BETWEEN 1 AND 64
 THEN RAISE EXCEPTION 'Bounded explicit publication command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND public_id=p_recipient) THEN RAISE EXCEPTION 'Separate recipient publication is not inferred' USING ERRCODE='42501'; END IF;
 output_plan:=builderad_private.output_use_plan(actor,p_business,p_ref,p_purpose,p_selection);
 IF(output_plan#>>'{descriptor,processor_user_id}')::bigint<>actor THEN RAISE EXCEPTION 'Only the captured producer principal can publish this output' USING ERRCODE='42501'; END IF;
 expected_edges:=builderad_private.producer_dependencies(output_plan->'descriptor');
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO proposed FROM jsonb_array_elements(p_dependencies);
 IF proposed IS DISTINCT FROM expected_edges THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: proposed inputs differ from the complete producer capture' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg(value->'input') INTO refs FROM jsonb_array_elements(expected_edges);
 input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(value) FROM jsonb_array_elements(p_selection->'requirements') WHERE value->>'leaf_grant_id'<>output_plan->>'grant_id'));
 input_plan:=builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection);
 fences:=builderad_private.lock_material_resources(input_plan,output_plan->'scope');
 b:=builderad_private.lock_business(p_business);
 footprint:=builderad_private.authority_footprint(actor,p_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND scope_kind='business' AND business_id IS DISTINCT FROM b)
 THEN RAISE EXCEPTION 'Cross-Business authority expansion denied' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 IF builderad_private.output_use_plan(actor,p_business,p_ref,p_purpose,p_selection) IS DISTINCT FROM output_plan
 OR builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection) IS DISTINCT FROM input_plan
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: publication inputs/rights changed' USING ERRCODE='40001'; END IF;
 proof:=builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);
 proof:=proof||jsonb_build_object('dependencies',fences,
 'captured_dependency_digest',input_plan#>'{closure,dependency_digest}');
 direct_hash:=builderad_private.lineage_digest('dependency-set:1',expected_edges);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'artifact',p_ref,'direct_dependency_digest',encode(direct_hash,'hex'),'purpose',p_purpose,'recipient',p_recipient,'selection',p_selection),'{"business":"U","recipient":"U","direct_dependency_digest":"X"}');
 known:=builderad_private.command_replay(b,actor,p_command,'material.publish',payload);
 IF known IS NOT NULL THEN
  PERFORM builderad_private.material_closure(jsonb_build_array(p_ref));
  PERFORM builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);RETURN known;
 END IF;
 IF EXISTS(SELECT 1 FROM public.material_lineage_manifests WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version')
 THEN RAISE EXCEPTION 'Artifact version already published; original command required' USING ERRCODE='40001'; END IF;
 root_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',p_ref),'hex');
 SELECT jsonb_agg(v ORDER BY k) INTO nodes FROM(
  SELECT encode(builderad_private.lineage_digest('lineage-manifest:1',value->'ref'),'hex') k,
   jsonb_build_object('ref',value->'ref','kind',value->'kind','material_digest',value->'material_digest','resolver_contract_version',value->'resolver_contract_version') v
  FROM jsonb_array_elements(input_plan#>'{closure,materials}')
  UNION ALL SELECT root_key,jsonb_build_object('ref',p_ref,'kind','derived','material_digest',p_ref->'version','resolver_contract_version',1)
 ) x;
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO edges FROM jsonb_array_elements((input_plan#>'{closure,dependencies}')||
  (SELECT jsonb_agg(jsonb_build_object('from',root_key,'to',encode(builderad_private.lineage_digest('lineage-manifest:1',value->'input'),'hex'),'edge',value)) FROM jsonb_array_elements(expected_edges)));
 closure:=builderad_private.lineage_digest('lineage-manifest:1',jsonb_build_object('materials',nodes,'dependencies',edges));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'material.publish',jsonb_build_object('business_id',b,'artifact',p_ref),proof);
 m.manifest_id:=uuidv7();m.material_namespace:=p_ref->>'namespace';m.material_key:=p_ref->>'key';m.material_version:=p_ref->>'version';m.kind:='derived';
 m.producer_receipt_ref:=output_plan#>>'{descriptor,producer_receipt_ref}';m.resolver_contract_version:=1;m.material_digest:=decode(p_ref->>'version','hex');
 m.direct_dependency_count:=jsonb_array_length(expected_edges);m.direct_dependency_digest:=direct_hash;m.closure_digest:=closure;m.state:='sealed';m.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.material_lineage_manifests','insert',NULL,to_jsonb(m));INSERT INTO public.material_lineage_manifests SELECT(m).*;
 FOR edge IN SELECT value FROM jsonb_array_elements(expected_edges) LOOP
  dep.dependency_id:=uuidv7();dep.material_namespace:=m.material_namespace;dep.material_key:=m.material_key;dep.material_version:=m.material_version;
  dep.input_namespace:=edge#>>'{input,namespace}';dep.input_key:=edge#>>'{input,key}';dep.input_version:=edge#>>'{input,version}';
  dep.data_use_scope_id:=(edge->>'data_use_scope_id')::uuid;dep.policy_id:=edge->>'policy_id';dep.policy_version:=(edge->>'policy_version')::bigint;dep.lineage_digest:=closure;
  PERFORM builderad_private.permit('public.material_use_dependencies','insert',NULL,to_jsonb(dep));INSERT INTO public.material_use_dependencies SELECT(dep).*;
 END LOOP;
 PERFORM builderad_private.registered_producer_publish(p_ref,m.manifest_id,closure);
 result:=jsonb_build_object('business_public_id',p_business,'artifact_ref',p_ref,'manifest_id',m.manifest_id,'closure_digest',encode(closure,'hex'),'versions',jsonb_build_object('manifest',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'material.publish',payload,result);
 IF(builderad_private.producer_descriptor(p_ref)-'publication_manifest_id') IS DISTINCT FROM((output_plan->'descriptor')-'publication_manifest_id')
 OR builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection) IS DISTINCT FROM input_plan
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: producer capture changed before publication release' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.material_closure(jsonb_build_array(p_ref));
 PERFORM builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
