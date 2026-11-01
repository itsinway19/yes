-- The prepared descriptor comes from its fixed private custodian, never from the publish body.
CREATE OR REPLACE FUNCTION builderad_private.producer_descriptor(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;r jsonb;receipt public.business_command_receipts;ids uuid[]:='{}';
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace'='market.knowledge' THEN RETURN builderad_private.knowledge_descriptor(p_ref); END IF;
 d:=builderad_private.registered_producer(p_ref);
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

CREATE OR REPLACE FUNCTION builderad_private.producer_material(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;m public.material_lineage_manifests;edges jsonb;
BEGIN
 d:=builderad_private.producer_descriptor(p_ref);
 SELECT * INTO m FROM public.material_lineage_manifests WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF NOT FOUND OR m.kind<>'derived' OR d->'publication_manifest_id' IS DISTINCT FROM to_jsonb(m.manifest_id)
 OR m.producer_receipt_ref IS DISTINCT FROM d->>'producer_receipt_ref' OR m.material_digest<>decode(d->>'material_digest','hex') OR m.resolver_contract_version IS DISTINCT FROM(d->>'resolver_contract_version')::bigint
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: registered output is not atomically published' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('input',jsonb_build_object('namespace',input_namespace,'key',input_key,'version',input_version),
  'data_use_scope_id',data_use_scope_id,'policy_id',policy_id,'policy_version',policy_version) ORDER BY input_namespace,input_key,input_version),'[]') INTO edges
 FROM public.material_use_dependencies WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF(SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) FROM jsonb_array_elements(edges)) IS DISTINCT FROM builderad_private.producer_dependencies(d)
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: captured and stored input sets differ' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'kind','derived','material_digest',d->'material_digest','resolver_contract_version',m.resolver_contract_version,'producer_receipt_ref',m.producer_receipt_ref,
 'direct_dependency_count',m.direct_dependency_count,'direct_dependency_digest',encode(m.direct_dependency_digest,'hex'),'closure_digest',encode(m.closure_digest,'hex'),'dependencies',edges,
 'origin',jsonb_build_object('registered_producer',true,'business_public_id',d->'business_public_id','output_scope_id',d->'output_scope_id'));
END $$;

CREATE OR REPLACE FUNCTION builderad_private.market_policy(p_scope uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;c public.market_source_custody;
BEGIN
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
 IF s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL OR s.status IS DISTINCT FROM 'active'
 OR NOT EXISTS(SELECT 1 FROM public.source_registry WHERE id=s.source_id AND custody_kind='platform_market' AND workspace_id IS NULL AND status<>'REVOKED')
 OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
 OR NOT s.operation=ANY(c.allowed_operations) OR NOT s.purpose=ANY(c.allowed_purposes)
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
 OR s.resource NOT IN('market.evidence','market.knowledge','market.business_finding') OR s.selection->'native_namespaces' IS DISTINCT FROM jsonb_build_array(s.resource)
 THEN RAISE EXCEPTION 'Current exact market source policy required' USING ERRCODE='42501'; END IF;
 RETURN to_jsonb(c);
END $$;

CREATE OR REPLACE FUNCTION builderad_private.material_use_plan(p_actor bigint,p_business uuid,p_refs jsonb,p_operation text,p_purpose text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE closure jsonb;node jsonb;descriptor jsonb;scope_row public.data_use_scopes;native_grant bigint;scope_grant bigint;matches integer;
 items jsonb:='[]';required_ids bigint[]:='{}';business_grant bigint;b bigint;custody public.data_use_scopes;lineage_scopes jsonb;
BEGIN
 PERFORM builderad_private.authority_footprint(p_actor,p_selection);
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 SELECT count(*),min(g.id) INTO matches,business_grant FROM public.security_action_grants g
 JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='business' AND r->>'leaf_grant_id'=g.id::text
 WHERE g.scope_kind='business' AND g.business_id=b AND g.subject_user_id=p_actor AND g.action='business.data.use';
 IF matches<>1 THEN RAISE EXCEPTION 'Explicit Business data-use path required; declarations are not source rights' USING ERRCODE='42501'; END IF;
 required_ids:=ARRAY[business_grant];closure:=builderad_private.material_closure(p_refs);
 FOR node IN SELECT value FROM jsonb_array_elements(closure->'materials') LOOP
  -- Retained/display delivery needs only current root licences and continuing ancestral policy.
  IF p_operation IN('display','retained_read') AND NOT(p_refs @> jsonb_build_array(node->'ref')) THEN CONTINUE; END IF;
  descriptor:=NULL;native_grant:=NULL;
  IF node->>'kind'='original' AND node#>>'{ref,namespace}'='market.evidence' THEN
   SELECT count(*),min(g.id) INTO matches,scope_grant FROM public.security_action_grants g
   JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
   JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id
   WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.action='data_use.'||p_operation
   AND s.rights_domain='market' AND s.source_id=(node#>>'{origin,source_id}')::uuid AND s.resource='market.evidence'
   AND s.operation=p_operation AND s.purpose=p_purpose AND s.selection->'native_namespaces' ? 'market.evidence'
   AND s.selection->'native_keys' ? (node#>>'{ref,key}');
  ELSIF node->>'kind'='original' AND node#>>'{ref,namespace}'='provider_observation_entry' THEN
   SELECT count(*),min(g.id) INTO matches,scope_grant FROM public.security_action_grants g
   JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
   JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id
   WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.action='data_use.'||p_operation
    AND s.source_id=(node#>>'{origin,source_id}')::uuid AND s.resource=node#>>'{origin,resource}' AND s.operation=p_operation AND s.purpose=p_purpose
    AND s.selection->'native_namespaces' ? ((node#>>'{ref,key}')::jsonb->>'namespace')
    AND s.selection->'native_keys' ? ((node#>>'{ref,key}')::jsonb->>'identity');
  ELSIF node->>'kind'='derived' THEN
   descriptor:=builderad_private.producer_descriptor(node->'ref');
   SELECT * INTO STRICT custody FROM public.data_use_scopes WHERE scope_id=(descriptor->>'output_scope_id')::uuid;
   IF (descriptor->'resolver_contract_version'<>'2'::jsonb AND descriptor->>'business_public_id' IS DISTINCT FROM p_business::text) OR descriptor->>'purpose' IS DISTINCT FROM p_purpose THEN RAISE EXCEPTION 'Exact producer Business/purpose required' USING ERRCODE='42501'; END IF;
   SELECT count(*),min(g.id) INTO matches,scope_grant FROM public.security_action_grants g
   JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
   JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id
   WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.action='data_use.'||p_operation
    AND s.source_id=custody.source_id AND s.boundary_id IS NOT DISTINCT FROM custody.boundary_id AND s.resource=custody.resource AND s.operation=p_operation AND s.purpose=p_purpose
    AND s.selection->'native_namespaces' ? (node#>>'{ref,namespace}') AND s.selection->'native_keys' ? (node#>>'{ref,key}');
  ELSE RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered owner' USING ERRCODE='42501'; END IF;
  IF matches<>1 THEN RAISE EXCEPTION 'Exactly one selected operation-specific data-use path for each required material' USING ERRCODE='42501'; END IF;
  SELECT s.* INTO STRICT scope_row FROM public.data_use_scopes s JOIN public.security_action_grants g ON g.data_use_scope_id=s.scope_id WHERE g.id=scope_grant;
  required_ids:=array_append(required_ids,scope_grant);
  IF node->>'kind'='original' AND node#>>'{ref,namespace}'='provider_observation_entry' THEN
   SELECT count(*),min(g.id) INTO matches,native_grant FROM public.security_action_grants g
   JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='native_source' AND r->>'leaf_grant_id'=g.id::text
   WHERE g.scope_kind='source' AND g.subject_user_id=p_actor AND g.action='source.read'
    AND g.workspace_id=(node#>>'{origin,workspace_id}')::bigint AND g.source_target_id=scope_row.source_id
    AND g.source_resource=node#>>'{origin,resource}' AND g.source_operation=node#>>'{origin,operation}' AND g.source_purpose=node#>>'{origin,purpose}';
   IF matches<>1 THEN RAISE EXCEPTION 'Exactly one selected native retained-read path required' USING ERRCODE='42501'; END IF;
   required_ids:=array_append(required_ids,native_grant);
  END IF;
  items:=items||jsonb_build_array(jsonb_build_object('ref',node->'ref','kind',node->'kind','origin',node->'origin','descriptor',descriptor,
   'scope',to_jsonb(scope_row),'scope_grant',scope_grant,'native_grant',native_grant));
 END LOOP;
 IF(SELECT count(*)<>count(DISTINCT value) FROM jsonb_array_elements(p_selection->'requirements'))
 OR(SELECT count(*) FROM jsonb_array_elements(p_selection->'requirements'))<>(SELECT count(DISTINCT x) FROM unnest(required_ids) x)
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(p_selection->'requirements') r WHERE NOT(r->>'leaf_grant_id')::bigint=ANY(required_ids))
 THEN RAISE EXCEPTION 'Exact complete selected right set required; no extra or duplicate paths' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'dependencies') e WHERE NOT EXISTS(SELECT 1 FROM public.data_use_scopes s WHERE s.scope_id=(e#>>'{edge,data_use_scope_id}')::uuid))
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: continuing source policy missing' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(to_jsonb(s) ORDER BY s.scope_id),'[]') INTO lineage_scopes FROM public.data_use_scopes s
 WHERE s.scope_id IN(SELECT(e#>>'{edge,data_use_scope_id}')::uuid FROM jsonb_array_elements(closure->'dependencies') e)
 OR s.scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM public.market_knowledge_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v WHERE c.state='published' AND EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'materials') n WHERE n#>>'{ref,namespace}'='market.knowledge' AND n#>>'{ref,key}'=c.run_id::text) AND v#>>'{scope,operation}' IN('forward','retain'));
 RETURN jsonb_build_object('items',items,'closure',closure,'lineage_scopes',lineage_scopes,'business_grant',business_grant);
END $$;
