CREATE FUNCTION builderad_private.canonical_data_selection(v jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE k text;item jsonb;out_value jsonb:='{}';limit_count integer;
BEGIN
 IF v IS NULL OR jsonb_typeof(v)<>'object' OR octet_length(v::text)>262144 OR
 (SELECT array_agg(key ORDER BY key COLLATE "C") FROM jsonb_object_keys(v) key) IS DISTINCT FROM ARRAY['field_keys','native_keys','native_namespaces','partition_ref']::text[]
 THEN RAISE EXCEPTION 'Exact bounded data selection required' USING ERRCODE='22023'; END IF;
 -- No registered source resolver currently attests a partition selector.
 IF v->'partition_ref' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Partition selector requires a registered resolver' USING ERRCODE='22023'; END IF;
 FOREACH k IN ARRAY ARRAY['native_namespaces','native_keys','field_keys'] LOOP
  limit_count:=CASE k WHEN 'native_namespaces' THEN 50 WHEN 'native_keys' THEN 1000 ELSE 100 END;
  IF jsonb_typeof(v->k) IS DISTINCT FROM 'array' OR jsonb_array_length(v->k) NOT BETWEEN 1 AND limit_count THEN RAISE EXCEPTION 'Explicit bounded selector set required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(v->k) LOOP
   IF jsonb_typeof(item)<>'string' OR length(item#>>'{}') NOT BETWEEN 1 AND 180 OR octet_length(item#>>'{}')>720
    OR(k='native_namespaces' AND((item#>>'{}') !~ '^[\x21-\x7e]{1,80}$')) THEN RAISE EXCEPTION 'Bounded opaque selector identifier required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF (SELECT count(*)<>count(DISTINCT value) FROM jsonb_array_elements(v->k)) THEN RAISE EXCEPTION 'Duplicate selector identifiers' USING ERRCODE='22023'; END IF;
  SELECT out_value||jsonb_build_object(k,jsonb_agg(value ORDER BY convert_to(value#>>'{}','UTF8'))) INTO out_value FROM jsonb_array_elements(v->k);
 END LOOP;
 RETURN out_value||jsonb_build_object('partition_ref',NULL);
END $$;

CREATE FUNCTION builderad_private.data_scope_digest(boundary_ref uuid,source_ref uuid,resource_name text,operation_name text,purpose_name text,selection_value jsonb) RETURNS bytea
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE value jsonb;encoded text;
BEGIN
 IF boundary_ref IS NULL OR source_ref IS NULL OR resource_name IS NULL OR length(resource_name) NOT BETWEEN 1 AND 180 OR octet_length(resource_name)>720
 OR operation_name NOT IN('retained_read','process','combine','display','reuse','export','train','retain','delete','forward')
 OR purpose_name IS NULL OR length(purpose_name) NOT BETWEEN 1 AND 128 OR octet_length(purpose_name)>512 THEN RAISE EXCEPTION 'Bounded typed data scope required' USING ERRCODE='22023'; END IF;
 value:=jsonb_build_object('codec_version',1,'selection_schema_version',1,'boundary_id',boundary_ref,'source_id',source_ref,
  'resource',resource_name,'operation',operation_name,'purpose',purpose_name,'selection',builderad_private.canonical_data_selection(selection_value));
 encoded:=builderad_private.fields(value,'{"codec_version":"I","selection_schema_version":"I","boundary_id":"U","source_id":"U"}');
 IF octet_length(value::text)>262144 OR octet_length(encoded)>524288 THEN RAISE EXCEPTION 'Aggregate data scope limit' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.digest('data-use-scope:1',value,'{"codec_version":"I","selection_schema_version":"I","boundary_id":"U","source_id":"U"}');
END $$;
ALTER TABLE data_use_scopes ADD CONSTRAINT scope_canonical_identity CHECK(
 selection=builderad_private.canonical_data_selection(selection) AND scope_digest=builderad_private.data_scope_digest(boundary_id,source_id,resource,operation,purpose,selection));

CREATE FUNCTION builderad_private.lock_data_scope(p_scope uuid) RETURNS public.data_use_scopes
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;gate record;count_gates integer:=0;
BEGIN
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='data_use' AND g.data_use_scope_id=p_scope AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Data use scope denied' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE (kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id FOR SHARE LOOP
  count_gates:=count_gates+1;
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 END LOOP;
 IF count_gates<>2 THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF s.status<>'active' OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active') THEN RAISE EXCEPTION 'Current data use scope required' USING ERRCODE='42501'; END IF;
 RETURN s;
END $$;

-- Ending an existing relationship is not a new use of client data and does not need the withdrawn use grant.
CREATE FUNCTION builderad_private.lock_binding_withdrawal(p_business uuid,p_scope uuid) RETURNS public.data_use_scopes
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;b bigint;count_gates integer:=0;gate record;
BEGIN
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF b IS NULL OR s.scope_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=p_scope)
 OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='business' AND g.business_id=b AND g.action='business.link.manage'
  AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Existing own Business binding required' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT fence_id FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id FOR SHARE LOOP count_gates:=count_gates+1;END LOOP;
 IF count_gates<>2 THEN RAISE EXCEPTION 'Existing binding resource gates required' USING ERRCODE='42501'; END IF;
 RETURN s;
END $$;
CREATE FUNCTION builderad_v2_business_data_use_binding(p_session text,p_business uuid,p_command uuid,p_scope uuid,p_effect text,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;b bigint;actor bigint;proof jsonb;use_proof jsonb;payload bytea;result jsonb;prior public.business_data_use_bindings;r public.business_data_use_bindings;changed boolean;
BEGIN
 IF p_effect NOT IN('add','end') OR p_command IS NULL OR p_scope IS NULL OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed binding command required' USING ERRCODE='22023'; END IF;
 IF p_effect='add' THEN s:=builderad_private.lock_data_scope(p_scope);ELSE s:=builderad_private.lock_binding_withdrawal(p_business,p_scope);END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 IF jsonb_array_length(p_selection->'requirements')<>(CASE WHEN p_effect='add' THEN 2 ELSE 1 END) THEN RAISE EXCEPTION 'Exact rights for binding effect required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
 IF p_effect='add' THEN use_proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);END IF;PERFORM builderad_private.principal(p_session);
 IF p_effect='add' AND((SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=b AND branch_id IS NULL AND status='active')) THEN RAISE EXCEPTION 'Qualified Business boundary coverage required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('data_use_authority',use_proof,'dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR f.boundary_id=s.boundary_id));
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('scope',p_scope,'effect',p_effect,'expected',p_expected),'{"scope":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.binding.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO prior FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=p_scope ORDER BY revision DESC,binding_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Binding changed before effect' USING ERRCODE='B4090'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.binding.'||p_effect,jsonb_build_object('business_id',b,'scope_id',p_scope),proof);
 r:=prior;changed:=false;
 IF p_effect='add' AND(prior.binding_id IS NULL OR prior.status='ended') THEN
  r.binding_id:=uuidv7();r.business_id:=b;r.data_use_scope_id:=p_scope;r.status:='active';r.revision:=coalesce(prior.revision,0)+1;r.qualification_ref:=(use_proof#>>'{qualification,qualification_id}')::uuid;
  PERFORM builderad_private.permit('public.business_data_use_bindings','insert',NULL,to_jsonb(r));INSERT INTO public.business_data_use_bindings SELECT(r).*;changed:=true;
 ELSIF p_effect='end' AND prior.status='active' THEN
  r.status:='ended';r.revision:=prior.revision+1;PERFORM builderad_private.permit('public.business_data_use_bindings','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.business_data_use_bindings SET status=r.status,revision=r.revision WHERE binding_id=r.binding_id;changed:=true;
 END IF;
 IF changed THEN PERFORM builderad_private.advance_business_epoch(b); END IF;
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
 IF p_effect='add' THEN PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);END IF;PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('binding_id',r.binding_id,'status',CASE WHEN p_effect='end' THEN 'ended' ELSE 'active' END,'versions',jsonb_build_object('binding',coalesce(r.revision,0)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.binding.'||p_effect,payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

-- The resource owner revokes an exact data-use grant without taking any Business/cohort lock.
CREATE FUNCTION builderad_private.data_use_right_command(p_session text,p_command uuid,p_scope uuid,p_effect text,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;gate public.resource_right_fences;gate_count integer:=0;boundary_gate uuid;prior public.security_action_grants;g public.security_action_grants;
 actor bigint;proof jsonb;selected bigint;payload bytea;result jsonb;
BEGIN
 IF p_effect IS DISTINCT FROM 'revoke' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
 OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$' OR p_expected<1 THEN RAISE EXCEPTION 'Exact data-use revoke command required' USING ERRCODE='22023'; END IF;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants candidate ON candidate.subject_user_id=l.user_id AND candidate.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND candidate.scope_kind='data_use' AND candidate.data_use_scope_id=p_scope
  AND candidate.status='active' AND candidate.revoked_at IS NULL AND(candidate.expires_at IS NULL OR candidate.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Current data-use issuer required' USING ERRCODE='42501'; END IF;
 -- Choose the final lock mode before waiting: no SHARE->UPDATE conversion and no late expansion.
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id LOOP
  IF gate.kind='client_boundary' THEN
   PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;boundary_gate:=gate.fence_id;
  ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  gate_count:=gate_count+1;
 END LOOP;
 IF gate_count<>2 THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact selected data-use issuer required' USING ERRCODE='42501'; END IF;
 selected:=(proof#>>'{grants,0,id}')::bigint;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','data_use','target',p_scope,'effect',p_effect,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.revoke' AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 SELECT * INTO prior FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='data_use' AND data_use_scope_id=p_scope FOR UPDATE;
 IF NOT FOUND OR prior.action<>'data_use.'||s.operation OR(prior.parent_grant_id IS DISTINCT FROM selected AND NOT(prior.id=selected AND prior.subject_user_id=actor)) THEN RAISE EXCEPTION 'Exact data-use issuer or own surrender required' USING ERRCODE='42501'; END IF;
 IF prior.version<>p_expected OR prior.status<>'active' THEN RAISE EXCEPTION 'Data-use grant changed' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR f.boundary_id=s.boundary_id));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.revoke',jsonb_build_object('scope_id',p_scope,'grant_id',prior.id),proof);
 g:=prior;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_right_epoch(boundary_gate);PERFORM builderad_private.principal(p_session);
 IF prior.id<>selected THEN PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session); END IF;
 result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.revoke',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;
