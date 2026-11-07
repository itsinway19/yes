-- Final native-material identity composition. Existing resolver/planner owners only.
-- Preserve all D1/D2/D3 licences, legacy Workspace wires, receipts and final qualification.
-- Business custody does not issue Business/data-use rights or enable another Business.

CREATE OR REPLACE FUNCTION builderad_private.resolve_material(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE ref jsonb;native_key jsonb;entry_row record;native_generation bigint;empty_hash bytea;source_row public.source_registry;origin jsonb;
BEGIN
 ref:=builderad_private.material_reference(p_ref);
 IF ref->>'namespace'='market.evidence' THEN RETURN builderad_private.market_evidence_resolve(ref); END IF;
 IF ref->>'namespace'='provider_observation_entry' THEN
  BEGIN native_key:=(ref->>'key')::jsonb;
  EXCEPTION WHEN invalid_text_representation THEN RAISE EXCEPTION 'Exact native observation key required' USING ERRCODE='22023'; END;
  IF jsonb_typeof(native_key) IS DISTINCT FROM 'object'
  OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(native_key) k) IS DISTINCT FROM ARRAY['generation','identity','namespace']::text[]
  OR jsonb_typeof(native_key->'generation') IS DISTINCT FROM 'number' OR(native_key->>'generation') !~ '^[1-9][0-9]{0,17}$'
  OR jsonb_typeof(native_key->'namespace') IS DISTINCT FROM 'string' OR(native_key->>'namespace') !~ '^[0-9a-f]{64}$'
  OR jsonb_typeof(native_key->'identity') IS DISTINCT FROM 'string' OR(native_key->>'identity') !~ '^bb2:[0-9a-f]{64}$'
  OR ref->>'version' !~ '^[0-9a-f]{64}$' OR native_key::text IS DISTINCT FROM ref->>'key'
  THEN RAISE EXCEPTION 'Canonical native observation key required' USING ERRCODE='22023'; END IF;
  native_generation:=(native_key->>'generation')::bigint;
  SELECT e.content_hash,s.workspace_id,s.source_id,s.provider,s.resource,s.operation,s.purpose,s.stream,r.command_id,r.coverage,r.provenance,r.recorded_at
  INTO entry_row FROM public.provider_observation_entries e JOIN public.provider_observation_streams s USING(namespace)
  JOIN public.provider_observation_runs r ON r.namespace=e.namespace AND r.generation=e.generation
  WHERE e.namespace=native_key->>'namespace' AND e.generation=native_generation AND e.identity=native_key->>'identity';
  IF NOT FOUND OR entry_row.content_hash IS DISTINCT FROM ref->>'version' THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact native observation unavailable' USING ERRCODE='42501'; END IF;
  -- Custody comes from the immutable canonical Source, never NULL matching or a caller hint.
  SELECT * INTO source_row FROM public.source_registry WHERE id=entry_row.source_id;
  IF NOT FOUND OR source_row.workspace_id IS DISTINCT FROM entry_row.workspace_id
   OR source_row.custody_kind NOT IN('workspace_native','business_native')
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact native Source custody required' USING ERRCODE='42501'; END IF;
  origin:=to_jsonb(entry_row)-'content_hash';
  -- Keep the historical Workspace origin wire and digest inputs byte-for-byte.
  IF source_row.custody_kind='business_native' THEN
   origin:=origin||jsonb_build_object('custody_kind','business_native','business_id',source_row.business_id);
  END IF;
  empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
  RETURN jsonb_build_object('ref',ref,'kind','original','resolver_contract_version',1,'material_digest',entry_row.content_hash,
   'producer_receipt_ref','provider_observation_runs:'||(native_key->>'namespace')||':'||native_generation,
   'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),'closure_digest',NULL,'dependencies','[]'::jsonb,
   'origin',origin);
 END IF;
 -- Fixed registered-owner resolver only. Its production dispatcher remains unavailable until a canonical producer is installed.
 RETURN builderad_private.producer_material(ref);
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
    AND g.source_target_id=scope_row.source_id
    AND((g.workspace_id IS NOT NULL AND g.business_id IS NULL
      AND g.workspace_id=(node#>>'{origin,workspace_id}')::bigint AND NOT(node->'origin' ? 'business_id'))
     OR(g.workspace_id IS NULL AND g.business_id=b AND g.business_id=(node#>>'{origin,business_id}')::bigint
      AND node#>>'{origin,custody_kind}'='business_native' AND node#>'{origin,workspace_id}'='null'::jsonb))
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
 OR s.scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM public.market_knowledge_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v WHERE c.state='published' AND EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'materials') n WHERE n#>>'{ref,namespace}'='market.knowledge' AND n#>>'{ref,key}'=c.run_id::text) AND v#>>'{scope,operation}' IN('forward','retain'))
 OR s.scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM public.market_business_finding_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v WHERE c.state='published' AND EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'materials') n WHERE n#>>'{ref,namespace}'='market.business_finding' AND n#>>'{ref,key}'=c.artifact_id::text) AND v#>>'{scope,operation}' IN('forward','retain'));
 RETURN jsonb_build_object('items',items,'closure',closure,'lineage_scopes',lineage_scopes,'business_grant',business_grant);
END $$;
