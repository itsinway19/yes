-- Public release projection only. Internal provenance and lineage remain unchanged.
CREATE OR REPLACE FUNCTION builderad_v2_scoped_material_read(p_session text,p_business uuid,p_command uuid,p_refs jsonb,p_operation text,p_purpose text,p_recipient uuid,p_selection jsonb) RETURNS jsonb
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
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_refs) x WHERE x->>'namespace'='market.knowledge') THEN
  PERFORM 1 FROM public.market_evidence_heads WHERE evidence_id IN(SELECT (n#>>'{ref,key}')::uuid FROM jsonb_array_elements(plan#>'{closure,materials}') n WHERE n#>>'{ref,namespace}'='market.evidence') ORDER BY evidence_id FOR SHARE;
 END IF;
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
  IF item->>'kind'='original' AND item#>>'{ref,namespace}'='market.evidence' THEN
   fields:=builderad_private.market_evidence_fields(item->'ref',item#>'{scope,selection,field_keys}',p_operation);
   coverage:=item#>'{origin,coverage}';
   -- Source-supplied provenance is private custody, not a field entitlement.
   -- Release only typed server-owned trace references; retain resolver/digest truth.
   provenance:=jsonb_build_object('kind','market_evidence_revision','source_ref',item#>'{origin,source_id}',
    'revision',item#>'{origin,evidence,revision}');
  ELSIF item->>'kind'='original' THEN
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
  results:=results||jsonb_build_array(jsonb_build_object('ref',item->'ref','fields',fields,'coverage',coverage,'provenance',provenance,'data_use_scope_id',item#>'{scope,scope_id}')||CASE WHEN item#>>'{ref,namespace}'='market.evidence' THEN jsonb_build_object('validity',builderad_private.market_validity(item->'ref'),'evidence',item#>'{origin,evidence}') ELSE '{}'::jsonb END);
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
 -- Retained historical access can remain licensed after expiry; its currentness
 -- must describe release time, not the instant before a receipt lock waited.
 SELECT jsonb_agg(CASE WHEN value#>>'{ref,namespace}'='market.evidence'
  THEN jsonb_set(value,'{validity}',builderad_private.market_validity(value->'ref')) WHEN value#>>'{ref,namespace}'='market.knowledge' THEN jsonb_set(value,'{fields,finding,validity}',builderad_private.knowledge_validity(value->'ref')) ELSE value END ORDER BY ord)
 INTO results FROM jsonb_array_elements(results) WITH ORDINALITY x(value,ord);
 result:=jsonb_set(result,'{materials}',coalesce(results,'[]'::jsonb));
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
