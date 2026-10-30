CREATE FUNCTION builderad_private.principal(p_session text,p_lock boolean DEFAULT true) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;
BEGIN
 IF session_user<>'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal' AND c.workspace_id IS NULL;
 IF actor IS NULL THEN RAISE EXCEPTION 'Clean signed principal required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=actor FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=actor FOR SHARE;
 PERFORM 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
 END IF;
 IF NOT EXISTS (
  SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id
  JOIN public.security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='advertiser'
   AND u.email_verified_at IS NOT NULL AND s.session_key_hash=p_session AND s.revoked_at IS NULL
   AND s.auth_method<>'internal_passkey' AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
 ) THEN RAISE EXCEPTION 'Current Business actor required' USING ERRCODE='42501'; END IF;
 RETURN actor;
END $$;

-- This encoder receives only server-built qualification snapshots, not request JSON schemas.
CREATE FUNCTION builderad_private.authority_cb1(v jsonb,field_name text DEFAULT '',depth integer DEFAULT 0) RETURNS text
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE payload text;item record;child_field text;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'Authority snapshot limit' USING ERRCODE='22023'; END IF;
 IF v IS NULL OR v='null'::jsonb THEN RETURN 'N0:'; END IF;
 IF field_name='relationship_row' THEN RETURN builderad_private.row_image('public.business_workspace_links',v); END IF;
 IF field_name='grant_row' THEN RETURN builderad_private.row_image('public.security_action_grants',v); END IF;
 IF field_name IN('qualification','qualification_row') THEN RETURN builderad_private.row_image('public.authority_qualification_receipts',v); END IF;
 IF jsonb_typeof(v)='number' THEN RETURN builderad_private.atom('I',v); END IF;
 IF jsonb_typeof(v)='string' AND field_name IN('realm_workspace_public_id','workspace_public_id','business','command','boundary_id','source_id','scope_id','fence_id','cohort_id') THEN RETURN builderad_private.atom('U',v); END IF;
 IF jsonb_typeof(v)='array' THEN
  payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
  child_field:=CASE field_name WHEN 'grants' THEN 'grant_row' WHEN 'qualifications' THEN 'qualification_row' WHEN 'relationship_qualifications' THEN 'qualification_row' WHEN 'relationships' THEN 'relationship_row' ELSE field_name END;
  FOR item IN SELECT value FROM jsonb_array_elements(v) LOOP payload:=payload||builderad_private.authority_cb1(item.value,child_field,depth+1); END LOOP;
  RETURN builderad_private.frame('A',payload);
 END IF;
 IF jsonb_typeof(v)='object' THEN
  payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(v)));
  FOR item IN SELECT key,value FROM jsonb_each(v) ORDER BY convert_to(key,'UTF8') LOOP
   IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'Typed authority schema required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.frame('S',item.key)||builderad_private.authority_cb1(item.value,item.key,depth+1);
  END LOOP;
  RETURN builderad_private.frame('O',payload);
 END IF;
 RETURN builderad_private.cb1(v);
END $$;

CREATE FUNCTION builderad_private.authority_digest(v jsonb) RETURNS bytea
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE encoded text;
BEGIN
 PERFORM builderad_private.bounded(v);encoded:=builderad_private.authority_cb1(v);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'Authority envelope limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/authority-selection:1','UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;
-- Current dependency snapshots contain server-selected resource fence rows. Their SQL schema owns CB1 types.
CREATE FUNCTION builderad_private.resource_dependency_digest(v jsonb) RETURNS bytea
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE row_value jsonb;payload text;prior_id uuid;current_id uuid;
BEGIN
 IF v IS NULL OR jsonb_typeof(v) IS DISTINCT FROM 'array' OR jsonb_array_length(v)>512 THEN RAISE EXCEPTION 'Bounded resource dependency set required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(v);payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
 FOR row_value IN SELECT value FROM jsonb_array_elements(v) LOOP
  IF jsonb_typeof(row_value) IS DISTINCT FROM 'object' OR coalesce(row_value->>'fence_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Exact dependency fence identity required' USING ERRCODE='22023'; END IF;
  current_id:=(row_value->>'fence_id')::uuid;
  IF prior_id IS NOT NULL AND current_id<=prior_id THEN RAISE EXCEPTION 'Canonical distinct resource dependency set required' USING ERRCODE='22023'; END IF;
  payload:=payload||builderad_private.row_image('public.resource_right_fences',row_value);prior_id:=current_id;
 END LOOP;
 payload:=builderad_private.frame('A',payload);
 IF octet_length(payload)>2097152 THEN RAISE EXCEPTION 'Resource dependency byte limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/dependency-set:1','UTF8')||decode('00','hex')||convert_to(payload,'UTF8'));
END $$;
CREATE FUNCTION builderad_private.start_command(p_actor bigint,p_session text,p_command uuid,p_action text,p_target jsonb,p_selection jsonb) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE g bigint; sd bytea;
BEGIN
 IF p_command IS NULL OR p_action IS NULL OR p_selection IS NULL THEN RAISE EXCEPTION 'Explicit bounded command required' USING ERRCODE='22023'; END IF;
 SELECT generation INTO g FROM public.principal_lifecycles WHERE user_id=p_actor;
 sd:=builderad_private.authority_digest(p_selection);
 INSERT INTO builderad_private.invocations VALUES (
  pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,p_actor,g,sha256(convert_to(p_session,'UTF8')),
  p_action,p_target,p_selection,sd,builderad_private.resource_dependency_digest(coalesce(p_selection->'dependencies','[]'::jsonb)),'authorized'
 );
END $$;

-- All mutable authority rows were locked before effects. After the last receipt wait, their time windows
-- and the bound current session must still qualify. Own surrender uses its authorized before-image;
-- this check never tries to revive/reselect a grant or acquire an earlier-rank resource lock.
CREATE FUNCTION builderad_private.assert_invocation_current() RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE i builderad_private.invocations;session_ref text;expired boolean;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 IF i.actor_user_id IS NOT NULL THEN
  SELECT session_key_hash INTO session_ref FROM public.security_sessions WHERE user_id=i.actor_user_id AND lifecycle_generation=i.generation
   AND sha256(convert_to(session_key_hash,'UTF8'))=i.session_ref_digest;
  IF session_ref IS NULL THEN RAISE EXCEPTION 'Final bound session unavailable' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.principal(session_ref,false);
 END IF;
 WITH RECURSIVE walk(k,v,d) AS(
  SELECT ''::text,i.authority_selection,0 UNION ALL
  SELECT child.k,child.v,walk.d+1 FROM walk CROSS JOIN LATERAL(
   SELECT key k,value v FROM jsonb_each(CASE WHEN jsonb_typeof(walk.v)='object' THEN walk.v ELSE '{}'::jsonb END)
   UNION ALL SELECT ''::text,value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(walk.v)='array' THEN walk.v ELSE '[]'::jsonb END)
  ) child WHERE walk.d<33
 ) SELECT EXISTS(SELECT 1 FROM walk WHERE CASE
  WHEN k IN('expires_at','valid_until','valid_from') AND v<>'null'::jsonb THEN CASE
   WHEN jsonb_typeof(v)<>'string' THEN true
   WHEN k='valid_from' THEN(v#>>'{}')::timestamptz>clock_timestamp()
   ELSE(v#>>'{}')::timestamptz<=clock_timestamp() END
  ELSE false END) INTO expired;
 IF expired THEN RAISE EXCEPTION 'Selected authority expired after final receipt wait' USING ERRCODE='42501'; END IF;
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN
 RAISE EXCEPTION 'Typed final authority deadline unavailable' USING ERRCODE='42501';
END $$;

CREATE FUNCTION builderad_private.image_digest(relation regclass,image jsonb,operation text) RETURNS bytea
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE inv builderad_private.invocations; encoded jsonb; payload text; item record; schema_name text; relation_name text;
BEGIN
 IF image IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO STRICT inv FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 SELECT n.nspname,c.relname INTO schema_name,relation_name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=relation;
 encoded:=jsonb_build_object(
  'codec_version',builderad_private.frame('I','1'),
  'object_schema',builderad_private.frame('S',schema_name),'object_name',builderad_private.frame('S',relation_name),
  'dml_kind',builderad_private.frame('S',operation),'command_id',builderad_private.atom('U',to_jsonb(inv.command_id)),
  'invocation_nonce',builderad_private.atom('U',to_jsonb(inv.nonce)),
  'backend_pid',builderad_private.atom('I',to_jsonb(inv.backend_pid)),'transaction_id',builderad_private.frame('S',inv.transaction_id::text),
  'actor_user_id',builderad_private.atom('I',to_jsonb(inv.actor_user_id)),'generation',builderad_private.atom('I',to_jsonb(inv.generation)),
  'action',builderad_private.frame('S',inv.action),'target_tuple',builderad_private.authority_cb1(inv.target_tuple),
  'authority_selection_digest',builderad_private.atom('X',to_jsonb(inv.authority_selection_digest)),
  'dependency_digest',builderad_private.atom('X',to_jsonb(inv.dependency_digest)),
  'image',builderad_private.row_image(relation,image)
 );
 payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(encoded)));
 FOR item IN SELECT key,v FROM jsonb_each_text(encoded) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
  payload:=payload||builderad_private.frame('S',item.key)||item.v;
 END LOOP;
 payload:=builderad_private.frame('O',payload);
 IF octet_length(payload)>2097152 THEN RAISE EXCEPTION 'Permit image limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/row-permit:1','UTF8')||decode('00','hex')||convert_to(payload,'UTF8'));
END $$;

CREATE FUNCTION builderad_private.permit(relation regclass,operation text,old_image jsonb,new_image jsonb) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE inv builderad_private.invocations; schema_name text; relation_name text;
BEGIN
 SELECT * INTO STRICT inv FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 SELECT n.nspname,c.relname INTO schema_name,relation_name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=relation;
 INSERT INTO builderad_private.command_permits VALUES (
  uuidv7(),pg_backend_pid(),pg_current_xact_id(),inv.nonce,inv.action,schema_name,relation_name,operation,inv.target_tuple,
  builderad_private.image_digest(relation,old_image,operation),builderad_private.image_digest(relation,new_image,operation),1,inv.authority_selection_digest,NULL
 );
END $$;

CREATE FUNCTION builderad_private.guard_effect() RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE before_row jsonb; after_row jsonb; op text:=lower(TG_OP); affected integer; adopted_source boolean;
BEGIN
 IF TG_OP='TRUNCATE' THEN
  IF session_user='builderad_owner' AND current_database()='builderad_test_disposable'
   AND (SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()) LIKE 'builderad-disposable-test:v1:%'
  THEN RETURN NULL; END IF;
  RAISE EXCEPTION 'Protected Business truth cannot be truncated' USING ERRCODE='42501';
 END IF;
 IF TG_OP<>'INSERT' THEN before_row:=to_jsonb(OLD); END IF;
 IF TG_OP<>'DELETE' THEN after_row:=to_jsonb(NEW); END IF;
 IF TG_TABLE_NAME IN('object_purposes','object_purpose_revisions','object_purpose_command_receipts') AND coalesce(after_row->>'business_id',before_row->>'business_id') IS NULL THEN
  IF TG_OP='DELETE' THEN RETURN OLD;ELSE RETURN NEW;END IF;
 END IF;
 IF TG_TABLE_NAME IN('source_command_receipts','source_versions','source_audit_obligations','source_audit_delivery') THEN
  IF TG_TABLE_NAME='source_audit_delivery' THEN
   SELECT EXISTS(SELECT 1 FROM public.source_audit_obligations o JOIN public.resource_right_fences f ON f.source_id=o.source_id
    WHERE o.id=(after_row->>'obligation_id')::uuid AND f.kind='source' AND f.adoption_state='v2_adopted') INTO adopted_source;
  ELSE
   SELECT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE source_id=(after_row->>'source_id')::uuid AND kind='source' AND adoption_state='v2_adopted') INTO adopted_source;
  END IF;
  IF NOT adopted_source THEN RETURN NEW; END IF;
 END IF;
 IF TG_TABLE_NAME IN('source_registry','source_capabilities') THEN
  IF TG_TABLE_NAME='source_registry' AND TG_OP='UPDATE' AND before_row-'scope_lock_marker'=after_row-'scope_lock_marker' THEN RETURN NEW; END IF;
  SELECT EXISTS(SELECT 1 FROM public.resource_right_fences f WHERE f.kind='source' AND f.adoption_state='v2_adopted'
   AND f.source_id IN((before_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid,
    (after_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid)) INTO adopted_source;
  IF NOT adopted_source THEN
   IF session_user='builderad_app' AND NOT builderad_private.native_source_gate_held(coalesce((after_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid,(before_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid))
   THEN RAISE EXCEPTION 'Native source admission required' USING ERRCODE='42501'; END IF;
   IF TG_OP='DELETE' THEN RETURN OLD;ELSE RETURN NEW;END IF;
  END IF;
 END IF;
 IF TG_TABLE_NAME='security_action_grants' THEN
  SELECT EXISTS(SELECT 1 FROM public.resource_right_fences f WHERE f.kind='source' AND f.adoption_state='v2_adopted' AND f.source_id IN((before_row->>'source_target_id')::uuid,(after_row->>'source_target_id')::uuid)) INTO adopted_source;
  IF coalesce(before_row->>'scope_kind','') NOT IN ('business','client_data','data_use')
   AND coalesce(after_row->>'scope_kind','') NOT IN ('business','client_data','data_use') THEN
   IF NOT adopted_source AND session_user='builderad_authority' AND (before_row->>'scope_kind'='source' OR after_row->>'scope_kind'='source')
    AND (((before_row->>'source_target_id') IS NOT NULL AND NOT builderad_private.native_source_gate_held((before_row->>'source_target_id')::uuid))
      OR((after_row->>'source_target_id') IS NOT NULL AND NOT builderad_private.native_source_gate_held((after_row->>'source_target_id')::uuid)))
   THEN RAISE EXCEPTION 'Native authority source admission required' USING ERRCODE='42501'; END IF;
   IF adopted_source THEN
    -- No late acquisition of K1 here: only an earlier protected writer can issue this exact row permit.
    NULL;
   ELSIF (coalesce(before_row->>'scope_kind','')='workspace' AND before_row->>'action'='business.context.manage')
    OR(coalesce(after_row->>'scope_kind','')='workspace' AND after_row->>'action'='business.context.manage') THEN
    IF session_user='builderad_owner' THEN
     IF EXISTS(SELECT 1 FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
      WHERE m.workspace_id=coalesce((after_row->>'workspace_id')::bigint,(before_row->>'workspace_id')::bigint) AND c.mode='LEGACY_ACTIVE') THEN
      IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
     END IF;
    END IF;
    PERFORM builderad_private.legacy_admitted(coalesce((after_row->>'workspace_id')::bigint,(before_row->>'workspace_id')::bigint));
   ELSE
    IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
   END IF;
  END IF;
  IF TG_OP='UPDATE' AND before_row-'runtime_lock_marker'=after_row-'runtime_lock_marker' THEN RETURN NEW; END IF;
  IF TG_OP='UPDATE' AND (
   before_row->>'id' IS DISTINCT FROM after_row->>'id' OR
   (before_row-ARRAY['status','version','revoked_at','updated_at','runtime_lock_marker']) IS DISTINCT FROM
   (after_row-ARRAY['status','version','revoked_at','updated_at','runtime_lock_marker']) OR
   before_row->>'status'='revoked' OR (after_row->>'version')::bigint<>(before_row->>'version')::bigint+1
  ) THEN RAISE EXCEPTION 'Immutable grant identity and monotone revocation required' USING ERRCODE='42501'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized')
 THEN RAISE EXCEPTION 'Authorized Business invocation required' USING ERRCODE='42501'; END IF;
 UPDATE builderad_private.command_permits p SET uses_left=0,consumed_at=clock_timestamp()
 WHERE p.backend_pid=pg_backend_pid() AND p.transaction_id=pg_current_xact_id() AND p.uses_left=1
 AND p.object_schema=TG_TABLE_SCHEMA AND p.object_name=TG_TABLE_NAME AND p.dml_kind=op
 AND p.old_digest IS NOT DISTINCT FROM builderad_private.image_digest(TG_RELID,before_row,op)
 AND p.new_digest IS NOT DISTINCT FROM builderad_private.image_digest(TG_RELID,after_row,op)
 AND EXISTS(SELECT 1 FROM builderad_private.invocations i WHERE i.nonce=p.invocation_nonce AND i.phase='authorized'
  AND i.authority_selection_digest=p.authority_selection_digest);
 GET DIAGNOSTICS affected=ROW_COUNT;
 IF affected<>1 THEN RAISE EXCEPTION 'Exact single-use Business effect permit required' USING ERRCODE='42501'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END $$;

CREATE FUNCTION builderad_private.finish_command() RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 DELETE FROM builderad_private.command_permits WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id();
 UPDATE builderad_private.invocations SET phase='completed' WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 IF NOT FOUND THEN RAISE EXCEPTION 'Owned Business command required' USING ERRCODE='42501'; END IF;
END $$;

-- Pure post-trigger RLS check. The row trigger consumes a permit; policies never do.
CREATE FUNCTION builderad_private.effect_allowed(relation regclass,operation text,image jsonb) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized') THEN RETURN false; END IF;
 RETURN EXISTS(SELECT 1 FROM builderad_private.command_permits p
  JOIN pg_class c ON c.oid=relation JOIN pg_namespace n ON n.oid=c.relnamespace
  JOIN builderad_private.invocations i ON i.nonce=p.invocation_nonce
  WHERE p.backend_pid=pg_backend_pid() AND p.transaction_id=pg_current_xact_id()
   AND p.object_schema=n.nspname AND p.object_name=c.relname AND p.dml_kind=operation
   AND p.uses_left=0 AND p.consumed_at IS NOT NULL AND i.phase='authorized'
   AND i.authority_selection_digest=p.authority_selection_digest
   AND p.new_digest IS NOT DISTINCT FROM builderad_private.image_digest(relation,image,operation));
END $$;
