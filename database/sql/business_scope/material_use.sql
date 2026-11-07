-- F09: one frozen rights/lineage plan for native, registered and mixed material sets.
-- Only requested roots leave custody; transitive processing rights never imply disclosure of ancestors.
CREATE FUNCTION builderad_private.material_use_plan(p_actor bigint,p_business uuid,p_refs jsonb,p_operation text,p_purpose text,p_selection jsonb) RETURNS jsonb
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
  IF node->>'kind'='original' AND node#>>'{ref,namespace}'='provider_observation_entry' THEN
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
   IF descriptor->>'business_public_id' IS DISTINCT FROM p_business::text OR descriptor->>'purpose' IS DISTINCT FROM p_purpose THEN RAISE EXCEPTION 'Exact producer Business/purpose required' USING ERRCODE='42501'; END IF;
   SELECT count(*),min(g.id) INTO matches,scope_grant FROM public.security_action_grants g
   JOIN jsonb_array_elements(p_selection->'requirements') r ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
   JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id
   WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.action='data_use.'||p_operation
    AND s.source_id=custody.source_id AND s.boundary_id=custody.boundary_id AND s.resource=custody.resource AND s.operation=p_operation AND s.purpose=p_purpose
    AND s.selection->'native_namespaces' ? (node#>>'{ref,namespace}') AND s.selection->'native_keys' ? (node#>>'{ref,key}');
  ELSE RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered owner' USING ERRCODE='42501'; END IF;
  IF matches<>1 THEN RAISE EXCEPTION 'Exactly one selected operation-specific data-use path for each required material' USING ERRCODE='42501'; END IF;
  SELECT s.* INTO STRICT scope_row FROM public.data_use_scopes s JOIN public.security_action_grants g ON g.data_use_scope_id=s.scope_id WHERE g.id=scope_grant;
  required_ids:=array_append(required_ids,scope_grant);
  IF node->>'kind'='original' THEN
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
 WHERE s.scope_id IN(SELECT(e#>>'{edge,data_use_scope_id}')::uuid FROM jsonb_array_elements(closure->'dependencies') e);
 RETURN jsonb_build_object('items',items,'closure',closure,'lineage_scopes',lineage_scopes,'business_grant',business_grant);
END $$;

-- All resource locks are taken once, before Business/cohort and the full K4 authority union.
CREATE FUNCTION builderad_private.lock_material_resources(p_plan jsonb,p_output_scope jsonb DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE scopes jsonb;sources uuid[];boundaries uuid[];gates uuid[];gate record;write_boundary uuid;adoption text;
BEGIN
 SELECT coalesce(jsonb_agg(value->'scope'),'[]') INTO scopes FROM jsonb_array_elements(p_plan->'items');
 scopes:=scopes||(p_plan->'lineage_scopes')||CASE WHEN p_output_scope IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(p_output_scope) END;
 SELECT ARRAY(SELECT DISTINCT(value->>'source_id')::uuid FROM jsonb_array_elements(scopes)),
 ARRAY(SELECT DISTINCT(value->>'boundary_id')::uuid FROM jsonb_array_elements(scopes)) INTO sources,boundaries;
 SELECT array_agg(fence_id ORDER BY fence_id) INTO gates FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(sources)) OR(kind='client_boundary' AND boundary_id=ANY(boundaries));
 IF coalesce(cardinality(gates),0)<>cardinality(sources)+cardinality(boundaries) THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
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

CREATE FUNCTION builderad_private.require_material_use(p_actor bigint,p_business bigint,p_session text,p_operation text,p_plan jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE item jsonb;node jsonb;s public.data_use_scopes;proof jsonb;proofs jsonb:='[]';uses jsonb:='[]';
BEGIN
 proof:=builderad_private.require_scope('business',p_business::text,p_actor,'business.data.use',p_selection,p_session);
 IF proof#>>'{qualification,issuer_kind}'='self_declared_draft' OR(SELECT lifecycle FROM public.businesses WHERE id=p_business) IN('archived','suspended') THEN RAISE EXCEPTION 'Qualified Business use required' USING ERRCODE='42501'; END IF;
 proofs:=jsonb_build_array(proof);
 FOR item IN SELECT value FROM jsonb_array_elements(p_plan->'items') LOOP
  SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(item#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM item->'scope' OR s.status<>'active' OR s.operation<>p_operation
   OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')
   OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=p_business AND branch_id IS NULL AND status='active')
   OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
  THEN RAISE EXCEPTION 'Current exact Business binding and client boundary coverage required' USING ERRCODE='42501'; END IF;
  proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.'||p_operation,p_selection,p_session);
  proofs:=proofs||jsonb_build_array(proof);
  uses:=uses||jsonb_build_array(jsonb_build_object('ref',item->'ref','data_use_scope_id',s.scope_id,'policy_id',proof#>'{qualification,policy_id}','policy_version',proof#>'{qualification,policy_version}'));
  IF item->>'kind'='original' THEN proofs:=proofs||jsonb_build_array(builderad_private.require_native_source_grant((item->>'native_grant')::bigint,p_actor,p_session,p_selection,false,false)); END IF;
 END LOOP;
 -- Every registered ancestor's continuing policy is checked, even when only the independent output is displayed.
 FOR node IN SELECT value FROM jsonb_array_elements(p_plan#>'{closure,materials}') WHERE value->>'kind'='derived' LOOP
  PERFORM builderad_private.registered_output_constraints(node->'ref',p_operation,(SELECT purpose FROM public.data_use_scopes WHERE scope_id=(node#>>'{origin,output_scope_id}')::uuid));
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('actor',p_actor,'proofs',proofs,'material_uses',uses);
END $$;

CREATE FUNCTION builderad_v2_scoped_material_read(p_session text,p_business uuid,p_command uuid,p_refs jsonb,p_operation text,p_purpose text,p_recipient uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;plan jsonb;footprint jsonb;proof jsonb;item jsonb;native_key jsonb;fences jsonb;
 fields jsonb;coverage jsonb;provenance jsonb;results jsonb:='[]';result jsonb;receipt jsonb;payload bytea;known jsonb;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_recipient IS NULL OR p_operation IS NULL OR p_operation NOT IN('retained_read','display','reuse','process','combine','export')
 OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128 OR octet_length(p_purpose)>512 THEN RAISE EXCEPTION 'Exact bounded material operation required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND public_id=p_recipient) THEN RAISE EXCEPTION 'Separate recipient delivery policy unavailable; no forwarding implied' USING ERRCODE='42501'; END IF;
 plan:=builderad_private.material_use_plan(actor,p_business,p_refs,p_operation,p_purpose,p_selection);
 fences:=builderad_private.lock_material_resources(plan);
 b:=builderad_private.lock_business(p_business);
 footprint:=builderad_private.authority_footprint(actor,p_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND scope_kind='business' AND business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Business target expansion denied' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 IF builderad_private.material_use_plan(actor,p_business,p_refs,p_operation,p_purpose,p_selection) IS DISTINCT FROM plan THEN RAISE EXCEPTION 'PREPARATION_CHANGED: material/right set changed; no lock expansion' USING ERRCODE='40001'; END IF;
 proof:=builderad_private.require_material_use(actor,b,p_session,p_operation,plan,p_selection)||jsonb_build_object('dependencies',fences,'material_dependency_digest',plan#>'{closure,dependency_digest}');
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'operation',p_operation,'purpose',p_purpose,'recipient',p_recipient,
 'material_dependency_digest',plan#>'{closure,dependency_digest}','selection',p_selection),'{"business":"U","recipient":"U","material_dependency_digest":"X"}');
 known:=builderad_private.command_replay(b,actor,p_command,'material.'||p_operation,payload);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'material.'||p_operation,jsonb_build_object('business_id',b,'recipient',p_recipient),proof);
 FOR item IN SELECT value FROM jsonb_array_elements(plan->'items') WHERE p_refs @> jsonb_build_array(value->'ref') LOOP
  IF item->>'kind'='original' THEN
   native_key:=(item#>>'{ref,key}')::jsonb;
   SELECT jsonb_object_agg(k.key,e.observation->k.key ORDER BY k.key COLLATE "C") INTO fields FROM public.provider_observation_entries e
   CROSS JOIN LATERAL jsonb_array_elements_text(item#>'{scope,selection,field_keys}') k(key)
   WHERE e.namespace=native_key->>'namespace' AND e.generation=(native_key->>'generation')::bigint AND e.identity=native_key->>'identity' AND e.content_hash=item#>>'{ref,version}' AND e.observation ? k.key;
   coverage:=item#>'{origin,coverage}';provenance:=item#>'{origin,provenance}';
  ELSE
   fields:=builderad_private.registered_producer_fields(item->'ref',item#>'{scope,selection,field_keys}');coverage:=item#>'{descriptor,coverage}';provenance:=item#>'{descriptor,provenance}';
  END IF;
  IF fields IS NULL OR jsonb_typeof(fields) IS DISTINCT FROM 'object'
   OR(SELECT jsonb_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(fields) k) IS DISTINCT FROM(SELECT jsonb_agg(value ORDER BY value#>>'{}' COLLATE "C") FROM jsonb_array_elements(item#>'{scope,selection,field_keys}'))
  THEN RAISE EXCEPTION 'MATERIAL_FIELDS_UNAVAILABLE: exact licensed fields required' USING ERRCODE='42501'; END IF;
  results:=results||jsonb_build_array(jsonb_build_object('ref',item->'ref','fields',fields,'coverage',coverage,'provenance',provenance,'data_use_scope_id',item#>'{scope,scope_id}'));
 END LOOP;
 result:=jsonb_build_object('business_public_id',p_business,'operation',p_operation,'purpose',p_purpose,'recipient_ref',p_recipient,'materials',results,
 'dependency_digest',plan#>'{closure,dependency_digest}','versions',jsonb_build_object('business_authority',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Material result limit; no truncated success' USING ERRCODE='22023'; END IF;
 IF known IS NULL THEN
  receipt:=(result-'materials')||jsonb_build_object('material_refs',(SELECT jsonb_agg(value->'ref') FROM jsonb_array_elements(results)),
   'material_uses',(SELECT jsonb_agg(value) FROM jsonb_array_elements(proof->'material_uses') WHERE p_refs @> jsonb_build_array(value->'ref')));
  PERFORM builderad_private.record_command(b,actor,p_command,'material.'||p_operation,payload,receipt,'no_material_change');
 END IF;
 IF builderad_private.material_use_plan(actor,p_business,p_refs,p_operation,p_purpose,p_selection) IS DISTINCT FROM plan THEN RAISE EXCEPTION 'PREPARATION_CHANGED: material changed before release' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.require_material_use(actor,b,p_session,p_operation,plan,p_selection);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
