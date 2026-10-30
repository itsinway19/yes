-- Trusted lineage schema supplies CB1 types; callers cannot select tags through JSON annotations.
CREATE FUNCTION builderad_private.lineage_cb1(v jsonb,field_name text DEFAULT '',depth integer DEFAULT 0) RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE payload text;item record;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'Lineage metadata depth limit' USING ERRCODE='22023'; END IF;
 IF v IS NULL OR v='null'::jsonb THEN RETURN 'N0:'; END IF;
 IF field_name IN('data_use_scope_id','source_id','boundary_id','command_id') THEN RETURN builderad_private.atom('U',v); END IF;
 IF field_name IN('material_digest','direct_dependency_digest','closure_digest','dependency_digest','from','to') THEN RETURN builderad_private.atom('X',v); END IF;
 IF field_name='recorded_at' THEN RETURN builderad_private.atom('T',v); END IF;
 IF field_name='provenance' THEN RETURN builderad_private.cb1(v); END IF;
 IF jsonb_typeof(v)='number' AND field_name IN('resolver_contract_version','policy_version','direct_dependency_count','generation','workspace_id','distinct_nodes','edge_count') THEN RETURN builderad_private.atom('I',v); END IF;
 IF jsonb_typeof(v)='array' THEN
  payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
  FOR item IN SELECT value FROM jsonb_array_elements(v) LOOP payload:=payload||builderad_private.lineage_cb1(item.value,'',depth+1); END LOOP;
  RETURN builderad_private.frame('A',payload);
 ELSIF jsonb_typeof(v)='object' THEN
  payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(v)));
  FOR item IN SELECT key,value FROM jsonb_each(v) ORDER BY convert_to(key,'UTF8') LOOP
   IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'Lineage schema key required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.frame('S',item.key)||builderad_private.lineage_cb1(item.value,item.key,depth+1);
  END LOOP;
  RETURN builderad_private.frame('O',payload);
 END IF;
 RETURN builderad_private.cb1(v);
END $$;
CREATE FUNCTION builderad_private.lineage_digest(domain text,value jsonb) RETURNS bytea
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE encoded text;
BEGIN
 IF domain IS NULL OR domain NOT IN('lineage-manifest:1','dependency-set:1') THEN RAISE EXCEPTION 'Lineage domain required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(value);encoded:=builderad_private.lineage_cb1(value);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'Lineage encoded byte limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/'||domain,'UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;
-- Private metadata resolver. It never returns payloads, credentials, progress or an authorization decision.
CREATE FUNCTION builderad_private.material_reference(v jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF v IS NULL OR jsonb_typeof(v) IS DISTINCT FROM 'object' OR octet_length(v::text)>8192
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k) IS DISTINCT FROM ARRAY['key','namespace','version']::text[]
 OR jsonb_typeof(v->'namespace') IS DISTINCT FROM 'string' OR(v->>'namespace') !~ '^[a-z][a-z0-9_.]{0,79}$'
 OR jsonb_typeof(v->'key') IS DISTINCT FROM 'string' OR octet_length(v->>'key') NOT BETWEEN 1 AND 4096
 OR jsonb_typeof(v->'version') IS DISTINCT FROM 'string' OR octet_length(v->>'version') NOT BETWEEN 1 AND 720
 THEN RAISE EXCEPTION 'Exact bounded material reference required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $$;

CREATE FUNCTION builderad_private.resolve_material(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE ref jsonb;native_key jsonb;entry_row record;native_generation bigint;empty_hash bytea;
BEGIN
 ref:=builderad_private.material_reference(p_ref);
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
  empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
  RETURN jsonb_build_object('ref',ref,'kind','original','resolver_contract_version',1,'material_digest',entry_row.content_hash,
   'producer_receipt_ref','provider_observation_runs:'||(native_key->>'namespace')||':'||native_generation,
   'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),'closure_digest',NULL,'dependencies','[]'::jsonb,
   'origin',to_jsonb(entry_row)-'content_hash');
 END IF;
 -- Fixed registered-owner resolver only. Its production dispatcher remains unavailable until a canonical producer is installed.
 RETURN builderad_private.producer_material(ref);
END $$;

CREATE FUNCTION builderad_private.material_closure(p_refs jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE stack jsonb:='[]';seen jsonb:='{}';nodes jsonb:='{}';edges jsonb:='[]';frame jsonb;ref jsonb;node jsonb;item jsonb;
 identity_key text;child_key text;depth integer;edge_count integer:=0;node_count integer:=0;bytes_used bigint:=0;
 ordered_nodes jsonb;ordered_edges jsonb;direct_edges jsonb;expected bytea;reachable text[];todo text[];cursor_key text;current_node jsonb;
BEGIN
 IF p_refs IS NULL OR jsonb_typeof(p_refs) IS DISTINCT FROM 'array' OR jsonb_array_length(p_refs) NOT BETWEEN 1 AND 64 OR octet_length(p_refs::text)>262144
 THEN RAISE EXCEPTION 'Bounded material set required' USING ERRCODE='22023'; END IF;
 FOR ref IN SELECT value FROM jsonb_array_elements(p_refs) LOOP
  PERFORM builderad_private.material_reference(ref);stack:=stack||jsonb_build_array(jsonb_build_object('ref',ref,'depth',0,'exit',false));
 END LOOP;
 WHILE jsonb_array_length(stack)>0 LOOP
  frame:=stack->-1;stack:=stack-(jsonb_array_length(stack)-1);ref:=frame->'ref';depth:=(frame->>'depth')::integer;
  IF depth>32 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: depth limit' USING ERRCODE='42501'; END IF;
  identity_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',ref),'hex');
  IF (frame->>'exit')::boolean THEN seen:=jsonb_set(seen,ARRAY[identity_key],'2');CONTINUE; END IF;
  IF seen->>identity_key='1' THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: dependency cycle' USING ERRCODE='42501'; END IF;
  IF seen->>identity_key='2' THEN
   IF nodes->identity_key->'ref' IS DISTINCT FROM ref THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: identity collision' USING ERRCODE='42501'; END IF;
   CONTINUE;
  END IF;
  node:=builderad_private.resolve_material(ref);
  IF node->'ref' IS DISTINCT FROM ref OR node->>'kind' IS NULL OR node->>'kind' NOT IN('original','derived')
   OR jsonb_typeof(node->'dependencies') IS DISTINCT FROM 'array' OR coalesce(node->>'material_digest','') !~ '^[0-9a-f]{64}$'
   OR coalesce(node->>'resolver_contract_version','') !~ '^[1-9][0-9]{0,17}$'
   OR jsonb_typeof(node->'resolver_contract_version') IS DISTINCT FROM 'number' OR jsonb_typeof(node->'direct_dependency_count') IS DISTINCT FROM 'number'
   OR octet_length(coalesce(node->>'producer_receipt_ref','')) NOT BETWEEN 1 AND 4096 OR coalesce(node->>'direct_dependency_count','') !~ '^[0-9]{1,4}$'
   OR coalesce(node->>'direct_dependency_digest','') !~ '^[0-9a-f]{64}$'
   OR (node->>'direct_dependency_count')::integer<>jsonb_array_length(node->'dependencies')
   OR(node->>'kind'='original' AND jsonb_array_length(node->'dependencies')<>0)
   OR(node->>'kind'='derived' AND(jsonb_array_length(node->'dependencies')=0 OR coalesce(node->>'closure_digest','') !~ '^[0-9a-f]{64}$'))
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: incomplete sealed manifest' USING ERRCODE='42501'; END IF;
  SELECT coalesce(jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')),'[]') INTO direct_edges FROM jsonb_array_elements(node->'dependencies');
  IF (SELECT count(*)<>count(DISTINCT value) FROM jsonb_array_elements(direct_edges))
   OR builderad_private.lineage_digest('dependency-set:1',direct_edges)<>decode(node->>'direct_dependency_digest','hex')
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: direct dependency set mismatch' USING ERRCODE='42501'; END IF;
  node_count:=node_count+1;edge_count:=edge_count+jsonb_array_length(direct_edges);bytes_used:=bytes_used+octet_length(node::text);
  IF node_count>512 OR edge_count>2048 OR bytes_used>2097152 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: closure limit' USING ERRCODE='42501'; END IF;
  nodes:=jsonb_set(nodes,ARRAY[identity_key],node);seen:=jsonb_set(seen,ARRAY[identity_key],'1');
  stack:=stack||jsonb_build_array(jsonb_build_object('ref',ref,'depth',depth,'exit',true));
  FOR item IN SELECT value FROM jsonb_array_elements(direct_edges) LOOP
   IF jsonb_typeof(item) IS DISTINCT FROM 'object'
    OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(item) k) IS DISTINCT FROM ARRAY['data_use_scope_id','input','policy_id','policy_version']::text[]
    OR jsonb_typeof(item->'data_use_scope_id') IS DISTINCT FROM 'string' OR(item->>'data_use_scope_id') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
    OR jsonb_typeof(item->'policy_id') IS DISTINCT FROM 'string' OR octet_length(item->>'policy_id') NOT BETWEEN 1 AND 180
    OR jsonb_typeof(item->'policy_version') IS DISTINCT FROM 'number' OR(item->>'policy_version') !~ '^[1-9][0-9]{0,17}$'
   THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact dependency policy required' USING ERRCODE='42501'; END IF;
   PERFORM builderad_private.material_reference(item->'input');
   child_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',item->'input'),'hex');
   edges:=edges||jsonb_build_array(jsonb_build_object('from',identity_key,'to',child_key,'edge',item));
   stack:=stack||jsonb_build_array(jsonb_build_object('ref',item->'input','depth',depth+1,'exit',false));
  END LOOP;
 END LOOP;
 -- Verify each derived node's entire subtree, not only the supplied direct list.
 FOR identity_key,current_node IN SELECT key,value FROM jsonb_each(nodes) LOOP
  IF current_node->>'kind'<>'derived' THEN CONTINUE; END IF;
  reachable:='{}';todo:=ARRAY[identity_key];
  WHILE cardinality(todo)>0 LOOP
   cursor_key:=todo[1];todo:=todo[2:];IF cursor_key=ANY(reachable) THEN CONTINUE; END IF;
   reachable:=array_append(reachable,cursor_key);
   SELECT todo||coalesce(array_agg(value->>'to'),'{}'::text[]) INTO todo FROM jsonb_array_elements(edges) WHERE value->>'from'=cursor_key;
  END LOOP;
  SELECT jsonb_agg(jsonb_build_object('ref',value->'ref','kind',value->>'kind','material_digest',value->>'material_digest','resolver_contract_version',value->'resolver_contract_version') ORDER BY key)
   INTO ordered_nodes FROM jsonb_each(nodes) WHERE key=ANY(reachable);
  SELECT coalesce(jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')),'[]') INTO ordered_edges FROM jsonb_array_elements(edges) WHERE value->>'from'=ANY(reachable);
  expected:=builderad_private.lineage_digest('lineage-manifest:1',jsonb_build_object('materials',ordered_nodes,'dependencies',ordered_edges));
  IF expected<>decode(current_node->>'closure_digest','hex') THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: transitive seal mismatch' USING ERRCODE='42501'; END IF;
 END LOOP;
 SELECT jsonb_agg(value ORDER BY key) INTO ordered_nodes FROM jsonb_each(nodes);
 SELECT coalesce(jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')),'[]') INTO ordered_edges FROM jsonb_array_elements(edges);
 RETURN jsonb_build_object('materials',ordered_nodes,'dependencies',ordered_edges,'distinct_nodes',node_count,'edge_count',edge_count,
  'dependency_digest',encode(builderad_private.lineage_digest('dependency-set:1',jsonb_build_object('materials',ordered_nodes,'dependencies',ordered_edges)),'hex'));
END $$;
