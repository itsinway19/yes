CREATE OR REPLACE FUNCTION builderad_private.require_output_use(p_actor bigint,p_business bigint,p_session text,p_plan jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;proof jsonb;
BEGIN
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(p_plan#>>'{scope,scope_id}')::uuid;
 IF p_plan#>>'{descriptor,ref,namespace}'='market.business_finding' AND s.rights_domain='market' THEN
  IF to_jsonb(s) IS DISTINCT FROM p_plan->'scope' OR s.status<>'active' OR s.resource<>'market.business_finding'
   OR p_plan#>>'{descriptor,business_public_id}' IS DISTINCT FROM(SELECT public_id::text FROM public.businesses WHERE id=p_business)
   OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
  THEN RAISE EXCEPTION 'Exact private output Business and independent binding required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.market_policy(s.scope_id);
 ELSE
 IF to_jsonb(s) IS DISTINCT FROM p_plan->'scope' OR s.status<>'active' OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=p_business AND branch_id IS NULL AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
 THEN RAISE EXCEPTION 'Current output boundary and use binding required' USING ERRCODE='42501'; END IF;
 END IF;
 proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.display',p_selection,p_session);
 PERFORM builderad_private.registered_output_constraints(p_plan#>'{descriptor,ref}','display',s.purpose);
 RETURN proof;
END $$;

CREATE OR REPLACE FUNCTION builderad_private.lock_material_resources(p_plan jsonb,p_output_scope jsonb DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE scopes jsonb;sources uuid[];boundaries uuid[];market_scopes uuid[];gates uuid[];gate record;write_boundary uuid;adoption text;
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
 write_boundary:=(p_plan->>'write_boundary_id')::uuid;
 IF write_boundary IS NOT NULL AND NOT(write_boundary=ANY(boundaries)) THEN RAISE EXCEPTION 'Write intent must belong to the material boundary set' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE fence_id=ANY(gates) ORDER BY fence_id LOOP
  IF gate.kind='client_boundary' AND gate.boundary_id=write_boundary THEN
   SELECT adoption_state INTO adoption FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;
  ELSE SELECT adoption_state INTO adoption FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE;END IF;
  IF adoption IS DISTINCT FROM 'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 END LOOP;
 RETURN(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE fence_id=ANY(gates));
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
 OR s.scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM public.market_knowledge_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v WHERE c.state='published' AND EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'materials') n WHERE n#>>'{ref,namespace}'='market.knowledge' AND n#>>'{ref,key}'=c.run_id::text) AND v#>>'{scope,operation}' IN('forward','retain'))
 OR s.scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM public.market_business_finding_captures c CROSS JOIN LATERAL jsonb_array_elements(c.continuation_rights) v WHERE c.state='published' AND EXISTS(SELECT 1 FROM jsonb_array_elements(closure->'materials') n WHERE n#>>'{ref,namespace}'='market.business_finding' AND n#>>'{ref,key}'=c.artifact_id::text) AND v#>>'{scope,operation}' IN('forward','retain'));
 RETURN jsonb_build_object('items',items,'closure',closure,'lineage_scopes',lineage_scopes,'business_grant',business_grant);
END $$;
