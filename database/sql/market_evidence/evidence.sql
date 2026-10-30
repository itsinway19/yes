CREATE FUNCTION builderad_private.market_keys(v jsonb,keys text[]) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF jsonb_typeof(v) IS DISTINCT FROM 'object' OR
 (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k) IS DISTINCT FROM
 (SELECT array_agg(k ORDER BY k COLLATE "C") FROM unnest(keys) k)
 THEN RAISE EXCEPTION 'Exact market contract keys required' USING ERRCODE='22023'; END IF;
END $$;

CREATE FUNCTION builderad_private.market_scope_digest(p_source uuid,p_resource text,p_operation text,p_purpose text,p_selection jsonb) RETURNS bytea
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_source IS NULL OR p_resource IS NULL OR length(p_resource) NOT BETWEEN 1 AND 180
 OR p_operation IS NULL OR p_operation NOT IN('retained_read','display','reuse','process','combine','export','train','retain','delete','forward','ingest','correct')
 OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128
 THEN RAISE EXCEPTION 'Typed market scope required' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.digest('data-use-market-scope:1',jsonb_build_object('rights_domain','market','source_id',p_source,'resource',p_resource,
 'operation',p_operation,'purpose',p_purpose,'selection',builderad_private.canonical_data_selection(p_selection)),'{"source_id":"U"}');
END $$;

-- CHECK validation on a populated upgrade must execute as the bounded function
-- owner, not require the deployer to execute every private codec helper.
CREATE FUNCTION builderad_private.market_scope_identity(p_domain text,p_boundary uuid,p_source uuid,p_resource text,p_operation text,p_purpose text,p_selection jsonb,p_digest bytea) RETURNS boolean
LANGUAGE sql IMMUTABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT p_selection=builderad_private.canonical_data_selection(p_selection) AND CASE p_domain
 WHEN 'client' THEN p_digest=builderad_private.data_scope_digest(p_boundary,p_source,p_resource,p_operation,p_purpose,p_selection)
 WHEN 'market' THEN p_digest=builderad_private.market_scope_digest(p_source,p_resource,p_operation,p_purpose,p_selection)
 ELSE false END
$$;

CREATE FUNCTION builderad_private.market_policy(p_scope uuid) RETURNS jsonb
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
 OR s.selection->'native_namespaces' IS DISTINCT FROM '["market.evidence"]'::jsonb
 THEN RAISE EXCEPTION 'Current exact market source policy required' USING ERRCODE='42501'; END IF;
 RETURN to_jsonb(c);
END $$;

CREATE FUNCTION builderad_private.market_evidence_resolve(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;empty_hash bytea;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace'<>'market.evidence' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
 OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact market evidence reference required' USING ERRCODE='22023'; END IF;
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 IF e.evidence_id IS NULL THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: market original unavailable' USING ERRCODE='42501'; END IF;
 empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
 RETURN jsonb_build_object('ref',p_ref,'kind','original','resolver_contract_version',1,'material_digest',p_ref->>'version',
 'producer_receipt_ref','market_evidence:'||e.command_id,'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),
 'closure_digest',NULL,'dependencies','[]'::jsonb,'origin',jsonb_build_object('source_id',h.source_id,'resource','market.evidence',
 'operation','retained_read','purpose','market_research','coverage',e.coverage,'provenance',e.provenance,
 'evidence',jsonb_build_object('source_ref',h.source_id,'schema',e.evidence_type,'revision',e.revision,
 'observed_at',e.observed_at,'effective_at',e.effective_at,'valid_until',e.valid_until)));
END $$;

CREATE FUNCTION builderad_private.market_evidence_fields(p_ref jsonb,p_keys jsonb,p_operation text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;result jsonb;
BEGIN
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 IF e.evidence_id IS NULL OR (p_operation NOT IN('retained_read','display') AND
 (e.revision<>h.current_revision OR e.effective_at>clock_timestamp() OR e.valid_until IS NULL OR e.valid_until<=clock_timestamp() OR e.coverage NOT IN('COMPLETE','PARTIAL')))
 THEN RAISE EXCEPTION 'Current qualified market evidence required for processing' USING ERRCODE='42501'; END IF;
 SELECT jsonb_object_agg(k,e.fields->k ORDER BY k COLLATE "C") INTO result FROM jsonb_array_elements_text(p_keys) k WHERE e.fields ? k;
 RETURN result;
END $$;

CREATE FUNCTION builderad_private.market_validity(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;v text;
BEGIN
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 v:=CASE WHEN e.revision<>h.current_revision THEN 'historical' WHEN e.valid_until IS NULL THEN 'unknown'
 WHEN e.effective_at>clock_timestamp() OR e.valid_until<=clock_timestamp() OR e.coverage NOT IN('COMPLETE','PARTIAL') THEN 'review_required' ELSE 'current' END;
 RETURN jsonb_build_object('status',v,'currentness_revision',h.current_revision,'checked_at',clock_timestamp());
END $$;

CREATE FUNCTION builderad_private.market_validate_evidence(p_payload jsonb) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE f jsonb;p text;
BEGIN
 PERFORM builderad_private.bounded(p_payload);
 IF octet_length(p_payload::text)>262144 THEN RAISE EXCEPTION 'Market evidence byte limit' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_payload,ARRAY['record_key','evidence_type','fields','coverage','provenance','effective_at','observed_at','valid_until','reason']);
 f:=p_payload->'fields';
 IF p_payload->>'evidence_type'='market.offer:1' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','currency','price','inclusions']);
  IF jsonb_typeof(f->'currency') IS DISTINCT FROM 'string' OR f->>'currency' !~ '^[A-Z]{3}$'
  OR(f->'price'<>'null'::jsonb AND(jsonb_typeof(f->'price') IS DISTINCT FROM 'number' OR(f->>'price')::numeric<0))
 OR jsonb_typeof(f->'inclusions') IS DISTINCT FROM 'array'
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(f->'inclusions')='array' THEN f->'inclusions' ELSE '[]'::jsonb END) v WHERE jsonb_typeof(v)<>'string')
 THEN RAISE EXCEPTION 'Typed offer fields required' USING ERRCODE='22023'; END IF;
 ELSIF p_payload->>'evidence_type'='market.interest_signal:1' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','metric','value']);
  PERFORM builderad_private.market_keys(f->'metric',ARRAY['name','unit','definition_version']);
  FOREACH p IN ARRAY ARRAY['name','unit','definition_version'] LOOP
   IF jsonb_typeof(f->'metric'->p) IS DISTINCT FROM 'string' OR length(f->'metric'->>p) NOT BETWEEN 1 AND 180 THEN RAISE EXCEPTION 'Explicit metric meaning required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF f->'value'<>'null'::jsonb AND jsonb_typeof(f->'value') IS DISTINCT FROM 'number' THEN RAISE EXCEPTION 'Numeric or unknown signal required' USING ERRCODE='22023'; END IF;
 ELSE RAISE EXCEPTION 'Unsupported market evidence schema' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(f->'subject',ARRAY['key','label','identity_state']);
 PERFORM builderad_private.market_keys(f->'geography',ARRAY['ref','level']);
 IF jsonb_typeof(f#>'{subject,identity_state}') IS DISTINCT FROM 'string' OR f#>>'{subject,identity_state}' NOT IN('unresolved','source_asserted') OR jsonb_typeof(f#>'{subject,label}') IS DISTINCT FROM 'string'
 OR length(f#>>'{subject,label}') NOT BETWEEN 1 AND 180
 OR(f#>'{subject,key}'<>'null'::jsonb AND jsonb_typeof(f#>'{subject,key}') IS DISTINCT FROM 'string')
 OR(f#>>'{subject,identity_state}'='source_asserted' AND jsonb_typeof(f#>'{subject,key}') IS DISTINCT FROM 'string')
 OR jsonb_typeof(f#>'{geography,level}') IS DISTINCT FROM 'string' OR f#>>'{geography,level}' NOT IN('unknown','country','region','city','district')
 OR(f#>>'{geography,level}'='unknown' AND f#>'{geography,ref}'<>'null'::jsonb)
 OR(f#>>'{geography,level}'<>'unknown' AND jsonb_typeof(f#>'{geography,ref}') IS DISTINCT FROM 'string')
 OR jsonb_typeof(p_payload->'coverage') IS DISTINCT FROM 'string' OR p_payload->>'coverage' NOT IN('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')
 OR jsonb_typeof(p_payload->'provenance') IS DISTINCT FROM 'object'
 OR jsonb_typeof(p_payload->'record_key') IS DISTINCT FROM 'string' OR length(p_payload->>'record_key') NOT BETWEEN 1 AND 180
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR length(p_payload->>'reason') NOT BETWEEN 1 AND 600
 THEN RAISE EXCEPTION 'Qualified market meaning required' USING ERRCODE='22023'; END IF;
 FOREACH p IN ARRAY ARRAY['observed_at','effective_at'] LOOP
  IF jsonb_typeof(p_payload->p) IS DISTINCT FROM 'string' OR (p_payload->>p) !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$' THEN RAISE EXCEPTION 'Explicit timezone required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF p_payload->'valid_until'<>'null'::jsonb AND(jsonb_typeof(p_payload->'valid_until') IS DISTINCT FROM 'string' OR (p_payload->>'valid_until') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$') THEN RAISE EXCEPTION 'Explicit expiry timezone required' USING ERRCODE='22023'; END IF;
END $$;

CREATE FUNCTION builderad_v2_market_evidence_write(p_session text,p_command uuid,p_evidence uuid,p_scope uuid,p_expected bigint,p_payload jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;h public.market_evidence_heads;e public.market_evidence_revisions;t public.market_evidence_transitions;
 actor bigint;proof jsonb;policy jsonb;payload bytea;result jsonb;prior_fields jsonb;prior_meta jsonb;next_meta jsonb;unchanged boolean;operation text;f record;stamp timestamptz;
BEGIN
 IF p_evidence IS NULL OR uuid_extract_version(p_evidence)<>7 OR p_command IS NULL OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed market command required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_validate_evidence(p_payload);
 actor:=builderad_private.principal(p_session,false);
 operation:=CASE WHEN p_expected=0 THEN 'ingest' ELSE 'correct' END;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF s.rights_domain IS DISTINCT FROM 'market' OR s.operation IS DISTINCT FROM operation
 OR NOT(s.selection->'native_keys' ? p_evidence::text) OR s.resource<>'market.evidence'
 OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1
 OR NOT EXISTS(SELECT 1 FROM public.security_action_grants g WHERE g.subject_user_id=actor
  AND g.scope_kind='data_use' AND g.data_use_scope_id=p_scope AND g.status='active' AND g.revoked_at IS NULL
  AND g.action='data_use.'||operation AND g.id::text=p_selection#>>'{requirements,0,leaf_grant_id}'
  AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Exact market write scope required' USING ERRCODE='42501'; END IF;
 -- Freeze source and exact use scope before any authority or evidence-head lock.
 FOR f IN SELECT fence_id FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id) ORDER BY fence_id LOOP
  PERFORM 1 FROM public.resource_right_fences WHERE fence_id=f.fence_id FOR UPDATE;
 END LOOP;
 IF (SELECT count(*) FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id))<>2 THEN RAISE EXCEPTION 'Market resource fences required' USING ERRCODE='42501'; END IF;
 actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||operation,p_selection,p_session);
 policy:=builderad_private.market_policy(p_scope);
 IF (SELECT to_jsonb(x) FROM public.data_use_scopes x WHERE scope_id=p_scope) IS DISTINCT FROM to_jsonb(s)
 OR EXISTS(SELECT 1 FROM jsonb_object_keys(p_payload->'fields') k WHERE NOT(s.selection->'field_keys' ? k))
 THEN RAISE EXCEPTION 'Exact licensed write fields required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('market-command:1',jsonb_build_object('evidence',p_evidence,'scope',p_scope,'expected',p_expected,'payload',p_payload,'selection',p_selection),'{}');
 result:=builderad_private.command_replay(NULL,actor,p_command,'market.'||operation,payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=p_evidence FOR UPDATE;
 IF coalesce(h.current_revision,0)<>p_expected OR(h.evidence_id IS NOT NULL AND(h.source_id<>s.source_id OR h.record_key<>p_payload->>'record_key'))
 THEN RAISE EXCEPTION 'Market head changed' USING ERRCODE='40001'; END IF;
 stamp:=clock_timestamp();
 e.evidence_id:=p_evidence;e.revision:=p_expected+1;e.prior_revision:=nullif(p_expected,0);e.evidence_type:=p_payload->>'evidence_type';
 e.fields:=p_payload->'fields';e.coverage:=p_payload->>'coverage';e.provenance:=p_payload->'provenance';
 e.observed_at:=(p_payload->>'observed_at')::timestamptz;e.effective_at:=(p_payload->>'effective_at')::timestamptz;e.valid_until:=(p_payload->>'valid_until')::timestamptz;
 IF e.observed_at IS NULL OR e.effective_at IS NULL OR NOT isfinite(e.observed_at) OR NOT isfinite(e.effective_at) OR e.observed_at>stamp
 OR(e.valid_until IS NOT NULL AND(NOT isfinite(e.valid_until) OR e.valid_until<=e.effective_at)) THEN RAISE EXCEPTION 'Explicit finite evidence times required' USING ERRCODE='22023'; END IF;
 e.command_id:=p_command;e.recorded_at:=stamp;
 SELECT fields,jsonb_build_object('evidence_type',evidence_type,'coverage',coverage,'provenance',provenance,
  'observed_at',observed_at,'effective_at',effective_at,'valid_until',valid_until) INTO prior_fields,prior_meta
  FROM public.market_evidence_revisions WHERE evidence_id=p_evidence AND revision=p_expected;
 next_meta:=jsonb_build_object('evidence_type',e.evidence_type,'coverage',e.coverage,'provenance',e.provenance,
  'observed_at',e.observed_at,'effective_at',e.effective_at,'valid_until',e.valid_until);
 unchanged:=p_expected>0 AND e.fields IS NOT DISTINCT FROM prior_fields AND next_meta IS NOT DISTINCT FROM prior_meta;
 e.content_digest:=builderad_private.digest('market-evidence:1',jsonb_build_object('source',s.source_id,'evidence',p_evidence,'revision',e.revision,'content',p_payload),'{}');
 proof:=jsonb_build_object('proofs',jsonb_build_array(proof),'market_policy',policy,'dependencies',(SELECT jsonb_agg(to_jsonb(x) ORDER BY fence_id) FROM public.resource_right_fences x WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.'||operation,jsonb_build_object('source_id',s.source_id,'evidence_id',p_evidence),proof);
 IF unchanged THEN
  e.revision:=p_expected;
  SELECT content_digest INTO e.content_digest FROM public.market_evidence_revisions WHERE evidence_id=p_evidence AND revision=p_expected;
 ELSE
 IF h.evidence_id IS NULL THEN
  h.evidence_id:=p_evidence;h.source_id:=s.source_id;h.record_key:=p_payload->>'record_key';h.current_revision:=1;
  PERFORM builderad_private.permit('public.market_evidence_heads','insert',NULL,to_jsonb(h));INSERT INTO public.market_evidence_heads SELECT(h).*;
 ELSE
  PERFORM builderad_private.permit('public.market_evidence_heads','update',to_jsonb(h),jsonb_set(to_jsonb(h),'{current_revision}',to_jsonb(e.revision)));
  UPDATE public.market_evidence_heads SET current_revision=e.revision WHERE evidence_id=p_evidence;
 END IF;
 PERFORM builderad_private.permit('public.market_evidence_revisions','insert',NULL,to_jsonb(e));INSERT INTO public.market_evidence_revisions SELECT(e).*;
 t.command_id:=p_command;t.evidence_id:=p_evidence;t.from_revision:=nullif(p_expected,0);t.to_revision:=e.revision;t.reason:=p_payload->>'reason';t.recorded_at:=stamp;
 SELECT array_agg(k ORDER BY k COLLATE "C") INTO t.changed_fields FROM(SELECT jsonb_object_keys(e.fields||coalesce(prior_fields,'{}')) k) keys WHERE e.fields->k IS DISTINCT FROM prior_fields->k;
 t.changed_fields:=coalesce(t.changed_fields,'{}');
 -- Metadata corrections also invalidate an inference even when field values did not change.
 SELECT t.changed_fields||coalesce(array_agg('@'||k ORDER BY k COLLATE "C"),'{}') INTO t.changed_fields
  FROM jsonb_object_keys(next_meta) k WHERE next_meta->k IS DISTINCT FROM prior_meta->k;
 PERFORM builderad_private.permit('public.market_evidence_transitions','insert',NULL,to_jsonb(t));INSERT INTO public.market_evidence_transitions SELECT(t).*;
 END IF;
 result:=jsonb_build_object('material_ref',jsonb_build_object('namespace','market.evidence','key',p_evidence,'version',encode(e.content_digest,'hex')),'versions',jsonb_build_object('evidence',e.revision));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'market.'||operation,payload,result,CASE WHEN unchanged THEN 'no_material_change' ELSE 'committed' END);
 PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||operation,p_selection,p_session);
 PERFORM builderad_private.market_policy(p_scope);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
