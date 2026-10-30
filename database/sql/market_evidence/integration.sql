-- D1 typed market branches. Existing client/native contracts remain separate.

CREATE OR REPLACE FUNCTION builderad_private.digest(domain text,value jsonb,tags jsonb DEFAULT '{}') RETURNS bytea
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE encoded text;
BEGIN
 IF domain NOT IN ('data-use-scope:1','row-permit:1','authority-selection:1','dependency-set:1','command-payload:1','lineage-manifest:1','data-use-market-scope:1','market-command:1','market-evidence:1','market-maintenance:1')
 THEN RAISE EXCEPTION 'CB1 domain required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(value); encoded:=builderad_private.fields(value,tags);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'CB1 encoded limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/'||domain,'UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;

CREATE OR REPLACE FUNCTION builderad_private.command_replay(p_business bigint,p_actor bigint,p_command uuid,p_action text,p_payload bytea) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.business_command_receipts;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Explicit command required' USING ERRCODE='22023'; END IF;
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF NOT FOUND THEN RETURN NULL; END IF;
 IF r.business_id IS DISTINCT FROM p_business OR r.actor_user_id IS DISTINCT FROM p_actor OR r.action IS DISTINCT FROM p_action OR r.payload_digest IS DISTINCT FROM p_payload
 THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 RETURN r.result_ref;
END $$;

CREATE OR REPLACE FUNCTION builderad_private.resource_dependency_digest(v jsonb) RETURNS bytea
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE row_value jsonb;payload text;prior_id uuid;current_id uuid;
BEGIN
 IF v IS NULL OR jsonb_typeof(v) IS DISTINCT FROM 'array' OR jsonb_array_length(v)>512 THEN RAISE EXCEPTION 'Bounded resource dependency set required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(v);payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
 FOR row_value IN SELECT value FROM jsonb_array_elements(v) LOOP
  IF jsonb_typeof(row_value) IS DISTINCT FROM 'object' OR coalesce(row_value->>'fence_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Exact dependency fence identity required' USING ERRCODE='22023'; END IF;
  current_id:=(row_value->>'fence_id')::uuid;
  IF prior_id IS NOT NULL AND current_id<=prior_id THEN RAISE EXCEPTION 'Canonical distinct resource dependency set required' USING ERRCODE='22023'; END IF;
  IF row_value->>'data_use_scope_id' IS NULL THEN
   -- Retain the exact v1 fence encoding for existing client/native rights.
   IF (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(row_value-'data_use_scope_id') k)
    IS DISTINCT FROM ARRAY['adoption_state','boundary_id','fence_id','kind','rights_epoch','source_id']::text[]
   THEN RAISE EXCEPTION 'Complete controlled resource row image required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.fields(row_value-'data_use_scope_id','{"fence_id":"U","source_id":"U","boundary_id":"U","rights_epoch":"I"}');
  ELSE payload:=payload||builderad_private.row_image('public.resource_right_fences',row_value); END IF;
  prior_id:=current_id;
 END LOOP;
 payload:=builderad_private.frame('A',payload);
 IF octet_length(payload)>2097152 THEN RAISE EXCEPTION 'Resource dependency byte limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/dependency-set:1','UTF8')||decode('00','hex')||convert_to(payload,'UTF8'));
END $$;

CREATE OR REPLACE FUNCTION builderad_private.guard_effect() RETURNS trigger
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
  IF NOT adopted_source AND NOT(TG_TABLE_NAME='source_registry' AND coalesce(after_row->>'custody_kind',before_row->>'custody_kind')='platform_market') THEN
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

CREATE OR REPLACE FUNCTION builderad_private.require_scope(p_kind text,p_target text,p_actor bigint,p_action text,p_selection jsonb,p_session text,p_extra_users bigint[] DEFAULT '{}',p_extra_workspaces bigint[] DEFAULT '{}') RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE req jsonb; leaf bigint; next_id bigint; ids bigint[]:='{}';
 realms bigint[]:='{}'; link_qualifications uuid[]:='{}'; chain jsonb:='[]'; quals jsonb:='[]'; relationships jsonb:='[]'; relationship_quals jsonb:='[]'; g public.security_action_grants; child public.security_action_grants;
 q public.authority_qualification_receipts; root_q public.authority_qualification_receipts; operation_realm bigint; item record; selected_count integer:=0;
BEGIN
 IF session_user<>'builderad_app' OR p_kind IS NULL OR p_kind NOT IN('business','client_data','data_use') OR p_target IS NULL OR p_action IS NULL OR p_actor IS NULL OR NOT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c WHERE c.actor_user_id=p_actor AND c.phase='principal' AND c.workspace_id IS NULL)
 THEN RAISE EXCEPTION 'Signed scoped actor required' USING ERRCODE='42501'; END IF;
 IF p_selection IS NULL OR jsonb_typeof(p_selection)<>'object' OR octet_length(p_selection::text)>65536
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_selection) k) IS DISTINCT FROM ARRAY['realm_kind','realm_workspace_public_id','requirements']::text[]
 OR jsonb_typeof(p_selection->'requirements')<>'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 32
 OR jsonb_typeof(p_selection->'realm_kind') IS DISTINCT FROM 'string' OR p_selection->>'realm_kind' NOT IN('direct','agency') THEN RAISE EXCEPTION 'Explicit complete authority selection required' USING ERRCODE='42501'; END IF;
 IF p_selection->>'realm_kind'='direct' THEN
  IF p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Direct realm cannot infer a workspace' USING ERRCODE='42501'; END IF;
 ELSE
  IF jsonb_typeof(p_selection->'realm_workspace_public_id') IS DISTINCT FROM 'string' OR (p_selection->>'realm_workspace_public_id') !~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$' THEN RAISE EXCEPTION 'Explicit agency realm required' USING ERRCODE='42501'; END IF;
  SELECT id INTO operation_realm FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid;
  IF operation_realm IS NULL THEN RAISE EXCEPTION 'Agency realm denied' USING ERRCODE='42501'; END IF;
 END IF;
 FOR req IN SELECT v FROM jsonb_array_elements(p_selection->'requirements') x(v) LOOP
  IF jsonb_typeof(req)<>'object' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(req) k) IS DISTINCT FROM ARRAY['leaf_grant_id','requirement_kind']::text[]
  OR coalesce(req->>'leaf_grant_id','') !~ '^[1-9][0-9]{0,18}$' THEN RAISE EXCEPTION 'Typed grant references required' USING ERRCODE='42501'; END IF;
  IF (req->>'leaf_grant_id')::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Grant identifier out of range' USING ERRCODE='42501'; END IF;
  IF req->>'requirement_kind'=p_kind THEN
   SELECT * INTO g FROM public.security_action_grants WHERE id=(req->>'leaf_grant_id')::bigint;
   IF g.scope_kind=p_kind AND (CASE p_kind WHEN 'business' THEN g.business_id::text WHEN 'client_data' THEN g.boundary_id::text ELSE g.data_use_scope_id::text END)=p_target THEN
    leaf:=g.id;selected_count:=selected_count+1;
   END IF;
  END IF;
 END LOOP;
 IF selected_count<>1 THEN RAISE EXCEPTION 'Exactly one selected complete path for this right required' USING ERRCODE='42501'; END IF;
 next_id:=leaf;
 WHILE next_id IS NOT NULL LOOP
  IF next_id=ANY(ids) OR cardinality(ids)>=16 THEN RAISE EXCEPTION 'Cyclic or excessive authority chain' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=next_id;
  IF NOT FOUND OR g.scope_kind<>p_kind OR (CASE p_kind WHEN 'business' THEN g.business_id::text WHEN 'client_data' THEN g.boundary_id::text ELSE g.data_use_scope_id::text END) IS DISTINCT FROM p_target
  THEN RAISE EXCEPTION 'Incomplete authority chain or target expansion' USING ERRCODE='42501'; END IF;
  ids:=array_append(ids,g.id);
  IF g.delegation_workspace_id IS NOT NULL THEN realms:=array_append(realms,g.delegation_workspace_id); END IF;
  next_id:=g.parent_grant_id;
 END LOOP;
 -- Business relationships are frozen by its K3 SHARE/UPDATE gate before this helper.
 IF p_kind='business' THEN SELECT realms||coalesce(array_agg(workspace_id),'{}'::bigint[]),coalesce(array_agg(authority_ref),'{}'::uuid[]),coalesce(jsonb_agg(to_jsonb(relationship_row) ORDER BY link_id),'[]'::jsonb) INTO realms,link_qualifications,relationships FROM public.business_workspace_links relationship_row WHERE business_id=p_target::bigint AND status='active'; END IF;
 PERFORM builderad_private.lock_authority_footprint(p_actor,p_selection,p_session,p_extra_users,p_extra_workspaces);
 SELECT coalesce(jsonb_agg(to_jsonb(qualified_relationship) ORDER BY qualification_id),'[]'::jsonb) INTO relationship_quals FROM public.authority_qualification_receipts qualified_relationship WHERE qualification_id=ANY(link_qualifications);
 FOREACH next_id IN ARRAY ids LOOP
  SELECT * INTO g FROM public.security_action_grants WHERE id=next_id;
  IF g.status<>'active' OR g.revoked_at IS NOT NULL OR (g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp()) OR g.action<>p_action
  OR NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=g.subject_user_id AND l.generation=g.subject_generation AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
  THEN RAISE EXCEPTION 'Current complete authority chain required' USING ERRCODE='42501'; END IF;
  IF next_id=leaf AND(g.subject_user_id<>p_actor OR g.delegation_realm_kind IS DISTINCT FROM p_selection->>'realm_kind' OR g.delegation_workspace_id IS DISTINCT FROM operation_realm) THEN RAISE EXCEPTION 'Selected subject/action/realm denied' USING ERRCODE='42501'; END IF;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  IF NOT FOUND OR q.status<>'active' OR q.target_kind<>p_kind OR q.subject_user_id<>g.subject_user_id
  OR q.target_ref IS DISTINCT FROM (CASE p_kind WHEN 'business' THEN (SELECT public_id FROM public.businesses WHERE id=p_target::bigint) ELSE p_target::uuid END)
  OR NOT p_action=ANY(q.allowed_actions) OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
  THEN RAISE EXCEPTION 'Current qualified authority edge required' USING ERRCODE='42501'; END IF;
  IF child.id IS NOT NULL AND (NOT g.delegable OR child.granted_by<>g.subject_user_id OR child.parent_grant_id<>g.id
   OR(g.expires_at IS NOT NULL AND(child.expires_at IS NULL OR child.expires_at>g.expires_at))) THEN RAISE EXCEPTION 'Delegation exceeds its parent' USING ERRCODE='42501'; END IF;
  IF g.parent_grant_id IS NULL THEN
   root_q:=q;
   IF q.issuer_kind='existing_grant' OR g.source NOT IN('business_draft_creation','qualified_policy') THEN RAISE EXCEPTION 'Complete root qualification required' USING ERRCODE='42501'; END IF;
   IF q.issuer_kind='self_declared_draft' AND(p_kind<>'business' OR g.source<>'business_draft_creation' OR g.delegable OR g.delegation_realm_kind<>'direct'
    OR q.subject_user_id<>(SELECT created_by FROM public.businesses WHERE id=p_target::bigint)) THEN RAISE EXCEPTION 'Own declarations only' USING ERRCODE='42501'; END IF;
  ELSIF q.issuer_kind<>'existing_grant' OR q.issuer_grant_id IS DISTINCT FROM g.parent_grant_id OR g.source<>'qualified_delegation' THEN RAISE EXCEPTION 'Qualified parent edge required' USING ERRCODE='42501'; END IF;
  IF g.delegation_realm_kind='agency' THEN
   IF NOT EXISTS(SELECT 1 FROM public.workspaces w JOIN public.workspace_memberships m ON m.workspace_id=w.id AND m.user_id=g.subject_user_id AND m.status='active'
    JOIN public.workspace_ownerships o ON o.workspace_id=w.id JOIN public.principal_lifecycles l ON l.user_id=o.responsible_owner_user_id AND l.status='current' AND l.principal_class='advertiser'
    JOIN public.users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
    JOIN public.workspace_memberships om ON om.workspace_id=w.id AND om.user_id=o.responsible_owner_user_id AND om.role='owner' AND om.status='active'
    WHERE w.id=g.delegation_workspace_id AND w.status='active') THEN RAISE EXCEPTION 'Current agency team required' USING ERRCODE='42501'; END IF;
   IF p_kind='business' AND NOT EXISTS(SELECT 1 FROM public.business_workspace_links relationship JOIN public.authority_qualification_receipts qualified_link ON qualified_link.qualification_id=relationship.authority_ref WHERE relationship.business_id=p_target::bigint AND relationship.workspace_id=g.delegation_workspace_id AND relationship.kind='agency' AND relationship.status='active' AND relationship.valid_from<=clock_timestamp() AND(relationship.valid_until IS NULL OR relationship.valid_until>clock_timestamp()) AND qualified_link.status='active' AND qualified_link.target_kind='business' AND qualified_link.target_ref=(SELECT public_id FROM public.businesses WHERE id=p_target::bigint) AND qualified_link.issuer_kind<>'self_declared_draft' AND 'business.link.manage'=ANY(qualified_link.allowed_actions) AND qualified_link.valid_from<=clock_timestamp() AND(qualified_link.valid_until IS NULL OR qualified_link.valid_until>clock_timestamp()))
   THEN RAISE EXCEPTION 'Qualified agency relationship required' USING ERRCODE='42501'; END IF;
  END IF;
  child:=g;chain:=chain||jsonb_build_array(to_jsonb(g));quals:=quals||jsonb_build_array(to_jsonb(q));
 END LOOP;
 RETURN jsonb_build_object('codec_version',1,'actor',p_actor,'generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=p_actor),'action',p_action,'realm',p_selection-'requirements','grants',chain,'qualifications',quals,'qualification',to_jsonb(root_q),'relationships',relationships,'relationship_qualifications',relationship_quals,'epochs',CASE WHEN p_kind='business' THEN (SELECT jsonb_build_object('business_id',p_target::bigint,'auth_epoch',f.auth_epoch,'cohort_id',c.cohort_id,'cohort_epoch',c.epoch) FROM public.business_authority_fences f JOIN public.migration_cohort_members m ON m.business_id=f.business_id JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id WHERE f.business_id=p_target::bigint) ELSE (SELECT jsonb_agg(to_jsonb(f) ORDER BY f.fence_id) FROM public.resource_right_fences f WHERE f.boundary_id=CASE p_kind WHEN 'client_data' THEN p_target::uuid ELSE (SELECT boundary_id FROM public.data_use_scopes WHERE scope_id=p_target::uuid) END OR f.source_id=(SELECT source_id FROM public.data_use_scopes WHERE p_kind='data_use' AND scope_id=p_target::uuid) OR(f.kind='data_use' AND p_kind='data_use' AND f.data_use_scope_id=p_target::uuid)) END);
END $$;

CREATE OR REPLACE FUNCTION builderad_private.lock_data_scope(p_scope uuid) RETURNS public.data_use_scopes
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;gate record;count_gates integer:=0;
BEGIN
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='data_use' AND g.data_use_scope_id=p_scope AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Data use scope denied' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE (kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND s.rights_domain='client' AND boundary_id=s.boundary_id) OR(kind='data_use' AND s.rights_domain='market' AND data_use_scope_id=s.scope_id) ORDER BY fence_id FOR SHARE LOOP
  count_gates:=count_gates+1;
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 END LOOP;
 IF count_gates<>2 THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF s.status<>'active' OR(s.rights_domain='client' AND NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')) THEN RAISE EXCEPTION 'Current data use scope required' USING ERRCODE='42501'; END IF;
 IF s.rights_domain='market' THEN PERFORM builderad_private.market_policy(s.scope_id); END IF;
 RETURN s;
END $$;

CREATE OR REPLACE FUNCTION builderad_private.lock_binding_withdrawal(p_business uuid,p_scope uuid) RETURNS public.data_use_scopes
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
 FOR gate IN SELECT fence_id FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND s.rights_domain='client' AND boundary_id=s.boundary_id) OR(kind='data_use' AND s.rights_domain='market' AND data_use_scope_id=s.scope_id) ORDER BY fence_id FOR SHARE LOOP count_gates:=count_gates+1;END LOOP;
 IF count_gates<>2 THEN RAISE EXCEPTION 'Existing binding resource gates required' USING ERRCODE='42501'; END IF;
 RETURN s;
END $$;

CREATE OR REPLACE FUNCTION builderad_v2_business_data_use_binding(p_session text,p_business uuid,p_command uuid,p_scope uuid,p_effect text,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;b bigint;actor bigint;proof jsonb;use_proof jsonb;payload bytea;result jsonb;prior public.business_data_use_bindings;r public.business_data_use_bindings;changed boolean;
BEGIN
 IF p_effect NOT IN('add','end') OR p_command IS NULL OR p_scope IS NULL OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed binding command required' USING ERRCODE='22023'; END IF;
 IF p_effect='add' THEN s:=builderad_private.lock_data_scope(p_scope);ELSE s:=builderad_private.lock_binding_withdrawal(p_business,p_scope);END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 IF jsonb_array_length(p_selection->'requirements')<>(CASE WHEN p_effect='add' THEN 2 ELSE 1 END) THEN RAISE EXCEPTION 'Exact rights for binding effect required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
 IF p_effect='add' AND s.rights_domain='market' THEN PERFORM builderad_private.market_policy(s.scope_id); END IF;
 IF p_effect='add' THEN use_proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);END IF;PERFORM builderad_private.principal(p_session);
 IF p_effect='add' AND((SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') OR(s.rights_domain='client' AND NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=b AND branch_id IS NULL AND status='active'))) THEN RAISE EXCEPTION 'Qualified Business boundary coverage required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('data_use_authority',use_proof,'dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR(s.rights_domain='client' AND f.boundary_id=s.boundary_id) OR(s.rights_domain='market' AND f.data_use_scope_id=s.scope_id)));
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

CREATE OR REPLACE FUNCTION builderad_private.data_use_right_command(p_session text,p_command uuid,p_scope uuid,p_effect text,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
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
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND s.rights_domain='client' AND boundary_id=s.boundary_id) OR(kind='data_use' AND s.rights_domain='market' AND data_use_scope_id=s.scope_id) ORDER BY fence_id LOOP
  IF gate.kind IN('client_boundary','data_use') THEN
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
 proof:=proof||jsonb_build_object('dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR(s.rights_domain='client' AND f.boundary_id=s.boundary_id) OR(s.rights_domain='market' AND f.data_use_scope_id=s.scope_id)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.revoke',jsonb_build_object('scope_id',p_scope,'grant_id',prior.id),proof);
 g:=prior;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_right_epoch(boundary_gate);PERFORM builderad_private.principal(p_session);
 IF prior.id<>selected THEN PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session); END IF;
 result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.revoke',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;

CREATE OR REPLACE FUNCTION builderad_private.resolve_material(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE ref jsonb;native_key jsonb;entry_row record;native_generation bigint;empty_hash bytea;
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
  empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
  RETURN jsonb_build_object('ref',ref,'kind','original','resolver_contract_version',1,'material_digest',entry_row.content_hash,
   'producer_receipt_ref','provider_observation_runs:'||(native_key->>'namespace')||':'||native_generation,
   'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),'closure_digest',NULL,'dependencies','[]'::jsonb,
   'origin',to_jsonb(entry_row)-'content_hash');
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
 WHERE s.scope_id IN(SELECT(e#>>'{edge,data_use_scope_id}')::uuid FROM jsonb_array_elements(closure->'dependencies') e);
 RETURN jsonb_build_object('items',items,'closure',closure,'lineage_scopes',lineage_scopes,'business_grant',business_grant);
END $$;

CREATE OR REPLACE FUNCTION builderad_private.lock_material_resources(p_plan jsonb,p_output_scope jsonb DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE scopes jsonb;sources uuid[];boundaries uuid[];market_scopes uuid[];gates uuid[];gate record;
BEGIN
 SELECT coalesce(jsonb_agg(value->'scope'),'[]') INTO scopes FROM jsonb_array_elements(p_plan->'items');
 scopes:=scopes||(p_plan->'lineage_scopes')||CASE WHEN p_output_scope IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(p_output_scope) END;
 SELECT ARRAY(SELECT DISTINCT(value->>'source_id')::uuid FROM jsonb_array_elements(scopes)),
 ARRAY(SELECT DISTINCT(value->>'boundary_id')::uuid FROM jsonb_array_elements(scopes) WHERE value->>'boundary_id' IS NOT NULL),
 ARRAY(SELECT DISTINCT(value->>'scope_id')::uuid FROM jsonb_array_elements(scopes) WHERE value->>'rights_domain'='market') INTO sources,boundaries,market_scopes;
 SELECT array_agg(fence_id ORDER BY fence_id) INTO gates FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(sources)) OR(kind='client_boundary' AND boundary_id=ANY(boundaries)) OR(kind='data_use' AND data_use_scope_id=ANY(market_scopes));
 IF coalesce(cardinality(gates),0)<>cardinality(sources)+cardinality(boundaries)+cardinality(market_scopes) THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE fence_id=ANY(gates) ORDER BY fence_id FOR SHARE LOOP
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 END LOOP;
 RETURN(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE fence_id=ANY(gates));
END $$;

CREATE OR REPLACE FUNCTION builderad_private.require_material_use(p_actor bigint,p_business bigint,p_session text,p_operation text,p_plan jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE item jsonb;node jsonb;s public.data_use_scopes;proof jsonb;proofs jsonb:='[]';uses jsonb:='[]';
BEGIN
 proof:=builderad_private.require_scope('business',p_business::text,p_actor,'business.data.use',p_selection,p_session);
 IF proof#>>'{qualification,issuer_kind}'='self_declared_draft' OR(SELECT lifecycle FROM public.businesses WHERE id=p_business) IN('archived','suspended') THEN RAISE EXCEPTION 'Qualified Business use required' USING ERRCODE='42501'; END IF;
 proofs:=jsonb_build_array(proof);
 FOR item IN SELECT value FROM jsonb_array_elements(p_plan->'items') LOOP
  SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(item#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM item->'scope' OR s.status<>'active' OR s.operation<>p_operation
   OR(s.rights_domain='client' AND NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active'))
   OR(s.rights_domain='client' AND NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=p_business AND branch_id IS NULL AND status='active'))
   OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
  THEN RAISE EXCEPTION 'Current exact Business binding and client boundary coverage required' USING ERRCODE='42501'; END IF;
  IF s.rights_domain='market' THEN
   PERFORM builderad_private.market_policy(s.scope_id);
   IF item->>'kind'='original' THEN
    -- Recheck epistemic freshness too, including after the final receipt wait.
    PERFORM builderad_private.market_evidence_fields(item->'ref',s.selection->'field_keys',p_operation);
   END IF;
  END IF;
  proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.'||p_operation,p_selection,p_session);
  proofs:=proofs||jsonb_build_array(proof);
  uses:=uses||jsonb_build_array(jsonb_build_object('ref',item->'ref','data_use_scope_id',s.scope_id,'policy_id',proof#>'{qualification,policy_id}','policy_version',proof#>'{qualification,policy_version}'));
  IF item->>'kind'='original' AND item#>>'{ref,namespace}'='provider_observation_entry' THEN proofs:=proofs||jsonb_build_array(builderad_private.require_native_source_grant((item->>'native_grant')::bigint,p_actor,p_session,p_selection,false,false)); END IF;
 END LOOP;
 -- Every registered ancestor's continuing policy is checked, even when only the independent output is displayed.
 FOR node IN SELECT value FROM jsonb_array_elements(p_plan#>'{closure,materials}') WHERE value->>'kind'='derived' LOOP
  PERFORM builderad_private.registered_output_constraints(node->'ref',p_operation,(SELECT purpose FROM public.data_use_scopes WHERE scope_id=(node#>>'{origin,output_scope_id}')::uuid));
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('actor',p_actor,'proofs',proofs,'material_uses',uses);
END $$;

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
   coverage:=item#>'{origin,coverage}';provenance:=item#>'{origin,provenance}';
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
  THEN jsonb_set(value,'{validity}',builderad_private.market_validity(value->'ref')) ELSE value END ORDER BY ord)
 INTO results FROM jsonb_array_elements(results) WITH ORDINALITY x(value,ord);
 result:=jsonb_set(result,'{materials}',coalesce(results,'[]'::jsonb));
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
