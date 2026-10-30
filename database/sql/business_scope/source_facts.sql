-- Canonical native fact semantics shared by the retiring Workspace entry and protected source retirement.
-- Rename below reuses exactly the same fact/history/audit writer; no second source truth.
CREATE FUNCTION builderad_private.native_source_payload(p_effect text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_effect IS NULL OR p_effect NOT IN('register','rename','declare_scope','revoke') OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object'
 OR octet_length(p_payload::text)>65536
 OR p_payload-(ARRAY['resource','operation','purpose','expected_version','reason']||CASE WHEN p_effect='register' THEN ARRAY['family','namespace','external_id','label'] WHEN p_effect='rename' THEN ARRAY['label'] ELSE ARRAY[]::text[] END)<>'{}'::jsonb
 OR jsonb_typeof(p_payload->'resource') IS DISTINCT FROM 'string' OR length(p_payload->>'resource') NOT BETWEEN 1 AND 180 OR p_payload->>'resource'<>trim(p_payload->>'resource')
 OR jsonb_typeof(p_payload->'operation') IS DISTINCT FROM 'string' OR length(p_payload->>'operation') NOT BETWEEN 1 AND 80 OR p_payload->>'operation'<>trim(p_payload->>'operation')
 OR jsonb_typeof(p_payload->'purpose') IS DISTINCT FROM 'string' OR length(p_payload->>'purpose') NOT BETWEEN 1 AND 128 OR p_payload->>'purpose'<>trim(p_payload->>'purpose')
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 OR coalesce(p_payload->>'expected_version','') !~ '^[0-9]{1,18}$'
 THEN RAISE EXCEPTION 'Invalid bounded native source command' USING ERRCODE='22023'; END IF;
 RETURN jsonb_set(p_payload,'{expected_version}',to_jsonb((p_payload->>'expected_version')::bigint));
END $$;

CREATE FUNCTION builderad_private.source_fact_command(p_session text,p_command uuid,p_source uuid,p_effect text,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;selected bigint;manager public.security_action_grants;f public.resource_right_fences;proof jsonb;payload bytea;result jsonb;
BEGIN
 IF p_effect NOT IN('rename','declare_scope','retire') OR p_expected<1
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM (CASE WHEN p_effect='rename' THEN ARRAY['label','reason']::text[] ELSE ARRAY['reason']::text[] END)
 OR (p_effect='rename' AND(jsonb_typeof(p_payload->'label') IS DISTINCT FROM 'string' OR length(p_payload->>'label') NOT BETWEEN 1 AND 120 OR p_payload->>'label'<>btrim(p_payload->>'label')))
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string'
 OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array'
 OR jsonb_array_length(p_selection->'requirements')<>1 OR p_selection#>>'{requirements,0,requirement_kind}' IS DISTINCT FROM 'native_source'
 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact native source fact command required' USING ERRCODE='22023'; END IF;
 selected:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c JOIN public.security_action_grants own ON own.subject_user_id=c.actor_user_id
 WHERE c.phase='principal' AND c.workspace_id IS NULL AND own.id=selected AND own.scope_kind='source' AND own.source_target_id=p_source AND own.action='source.manage';
 IF actor IS NULL THEN RAISE EXCEPTION 'Native manager preflight denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
 SELECT * INTO STRICT manager FROM public.security_action_grants WHERE id=selected;
 IF manager.source_target_id IS DISTINCT FROM p_source OR manager.action IS DISTINCT FROM 'source.manage'
 THEN RAISE EXCEPTION 'Exact current native source manager required after admission wait' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','source','target',p_source,'effect',p_effect,'payload',p_payload,'expected',p_expected,'native_scope',jsonb_build_array(manager.source_resource,manager.source_operation,manager.source_purpose)),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.'||p_effect AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('dependencies',jsonb_build_array(to_jsonb(f)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.'||p_effect,jsonb_build_object('source_id',p_source),proof);
 result:=builderad_private.apply_native_source(actor,manager.subject_generation,manager.workspace_id,p_command,p_source,CASE WHEN p_effect='retire' THEN 'revoke' ELSE p_effect END,
  p_payload||jsonb_build_object('resource',manager.source_resource,'operation',manager.source_operation,'purpose',manager.source_purpose,'expected_version',p_expected));
 result:=jsonb_build_object('source_id',p_source,'status',result->>'registration_state','versions',jsonb_build_object('source',(result->>'version')::bigint),'native_receipt',result);
 PERFORM builderad_private.advance_right_epoch(f.fence_id);
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.'||p_effect,payload,result);
 PERFORM builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
 PERFORM builderad_private.finish_command();RETURN result;
EXCEPTION WHEN unique_violation THEN RAISE EXCEPTION 'Source fact version conflict' USING ERRCODE='40001';
END $$;

-- Fixed native append catalog; no arbitrary relation or caller-provided SQL. Source guards consume exact permits.
CREATE FUNCTION builderad_private.native_source_append(p_relation regclass,p_row jsonb,p_adopted boolean) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_relation NOT IN('public.source_command_receipts'::regclass,'public.source_versions'::regclass,'public.source_audit_obligations'::regclass,'public.source_audit_delivery'::regclass)
 THEN RAISE EXCEPTION 'Unregistered native source append relation' USING ERRCODE='42501'; END IF;
 IF p_adopted THEN PERFORM builderad_private.permit(p_relation,'insert',NULL,p_row); END IF;
 EXECUTE format('INSERT INTO %s SELECT r.* FROM jsonb_populate_record(NULL::%s,$1) r',p_relation,p_relation) USING p_row;
END $$;

CREATE FUNCTION builderad_private.apply_native_source(p_actor bigint,p_generation bigint,p_workspace bigint,p_command uuid,p_source uuid,p_effect text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.source_registry;before_s public.source_registry;cap public.source_capabilities;prior public.source_command_receipts;fp text;result jsonb;env jsonb;obligation uuid;decision timestamptz;
 adopted boolean;r text;op text;purpose_name text;
BEGIN
 p_payload:=builderad_private.native_source_payload(p_effect,p_payload);r:=p_payload->>'resource';op:=p_payload->>'operation';purpose_name:=p_payload->>'purpose';
 fp:=encode(sha256(convert_to(jsonb_build_object('effect',p_effect,'source',p_source,'payload',p_payload)::text,'UTF8')),'hex');
 SELECT * INTO prior FROM public.source_command_receipts WHERE workspace_id=p_workspace AND command_id=p_command;
 IF FOUND THEN
  IF prior.fingerprint<>fp OR prior.actor_user_id<>p_actor OR prior.actor_generation<>p_generation THEN RAISE EXCEPTION 'Source command identity conflict' USING ERRCODE='23505'; END IF;
  RETURN prior.result;
 END IF;
 SELECT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted') INTO adopted;
 SELECT * INTO before_s FROM public.source_registry WHERE id=p_source AND workspace_id=p_workspace FOR UPDATE;
 IF p_effect='register' THEN
  IF FOUND THEN RAISE EXCEPTION 'Source already registered' USING ERRCODE='23505'; END IF;
  IF adopted OR (p_payload->>'expected_version')::bigint<>0 THEN RAISE EXCEPTION 'Initial native source version required' USING ERRCODE='22023'; END IF;
  INSERT INTO public.source_registry(id,workspace_id,family,namespace,declared_external_id,label,version,status)
  VALUES(p_source,p_workspace,p_payload->>'family',p_payload->>'namespace',p_payload->>'external_id',p_payload->>'label',1,'UNVERIFIED') RETURNING * INTO s;
 ELSE
  IF NOT FOUND THEN RAISE EXCEPTION 'Source unavailable' USING ERRCODE='42501'; END IF;
  IF before_s.version<>(p_payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Source version conflict' USING ERRCODE='23505'; END IF;
  IF before_s.status='REVOKED' THEN RAISE EXCEPTION 'Source revoked' USING ERRCODE='22023'; END IF;
  IF p_effect<>'declare_scope' AND NOT EXISTS(SELECT 1 FROM public.source_capabilities WHERE workspace_id=p_workspace AND source_id=p_source AND resource=r AND operation=op AND purpose=purpose_name)
  THEN RAISE EXCEPTION 'Exact declared native scope required' USING ERRCODE='42501'; END IF;
  s:=before_s;s.version:=s.version+1;
  IF p_effect='rename' THEN s.label:=p_payload->>'label'; END IF;
  IF p_effect='revoke' THEN s.status:='REVOKED'; END IF;
  IF adopted THEN PERFORM builderad_private.permit('public.source_registry','update',to_jsonb(before_s),to_jsonb(s)); END IF;
  UPDATE public.source_registry SET version=s.version,label=s.label,status=s.status WHERE id=s.id AND workspace_id=s.workspace_id;
 END IF;
 IF p_effect IN('register','declare_scope') THEN
  cap.workspace_id:=p_workspace;cap.source_id:=p_source;cap.resource:=r;cap.operation:=op;cap.purpose:=purpose_name;cap.declared_at:=clock_timestamp();
  IF adopted THEN PERFORM builderad_private.permit('public.source_capabilities','insert',NULL,to_jsonb(cap)); END IF;
  INSERT INTO public.source_capabilities SELECT(cap).*;
 END IF;
 decision:=clock_timestamp();obligation:=uuidv7();
 result:=jsonb_build_object('command_id',p_command,'source_id',p_source,'version',s.version,'registration_state',s.status,'obligation_id',obligation,'local_audit','DURABLE','reason',p_payload->>'reason');
 PERFORM builderad_private.native_source_append('public.source_command_receipts',jsonb_build_object('workspace_id',p_workspace,'command_id',p_command,'source_id',p_source,'effect',p_effect,'resource',r,'operation',op,'purpose',purpose_name,
  'fingerprint',fp,'result',result,'actor_user_id',p_actor,'actor_generation',p_generation,'decided_at',decision),adopted);
 PERFORM builderad_private.native_source_append('public.source_versions',jsonb_build_object('workspace_id',p_workspace,'source_id',p_source,'version',s.version,'prior_version',nullif(s.version-1,0),'command_id',p_command,
  'snapshot',jsonb_build_object('label',s.label,'status',s.status,'resource',r,'operation',op,'purpose',purpose_name)),adopted);
 env:=jsonb_build_object('policy','BB1-V1','workspace_id',p_workspace,'source_id',p_source,'command_id',p_command,'effect',p_effect,'resource',r,'operation',op,'purpose',purpose_name,
  'actor_user_id',p_actor,'actor_generation',p_generation,'fingerprint',fp,'prior_version',nullif(s.version-1,0),'result_version',s.version,'reason',p_payload->>'reason','decided_at',decision);
 PERFORM builderad_private.native_source_append('public.source_audit_obligations',jsonb_build_object('id',obligation,'workspace_id',p_workspace,'command_id',p_command,'source_id',p_source,'envelope',env,
  'fingerprint',encode(sha256(convert_to(env::text,'UTF8')),'hex'),'recorded_at',decision),adopted);
 PERFORM builderad_private.native_source_append('public.source_audit_delivery',jsonb_build_object('obligation_id',obligation,'workspace_id',p_workspace,'state','PENDING','acknowledgment_id',NULL,'attempts',0,'updated_at',decision),adopted);
 RETURN result;
END $$;

-- Preserve the original public native entry, with one shared mutation/audit implementation.
CREATE OR REPLACE FUNCTION public.builderad_source_command(p_workspace bigint,p_session text,p_command uuid,p_source uuid,p_effect text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;generation bigint;act text;result jsonb;
BEGIN
 IF p_command IS NULL OR p_source IS NULL THEN RAISE EXCEPTION 'Typed native source command required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.assert_native_source_write(p_source);
 p_payload:=builderad_private.native_source_payload(p_effect,p_payload);
 act:=CASE WHEN p_effect='register' THEN 'source.register' ELSE 'source.manage' END;
 PERFORM pg_advisory_xact_lock(hashtextextended('bb1.command.'||p_workspace||'.'||p_command,0));
 PERFORM pg_advisory_xact_lock(hashtextextended('bb1.source.'||p_workspace||'.'||p_source,0));
 actor:=public.builderad_source_require(act,p_source,p_workspace,p_payload->>'resource',p_payload->>'operation',p_payload->>'purpose',p_session);
 SELECT l.generation INTO generation FROM public.principal_lifecycles l WHERE user_id=actor;
 result:=builderad_private.apply_native_source(actor,generation,p_workspace,p_command,p_source,p_effect,p_payload);
 PERFORM public.builderad_source_require(act,p_source,p_workspace,p_payload->>'resource',p_payload->>'operation',p_payload->>'purpose',p_session);
 RETURN result;
END $$;

