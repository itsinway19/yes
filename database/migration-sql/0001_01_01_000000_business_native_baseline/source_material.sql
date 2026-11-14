-- Business-native Source qualification and independent data-use/material lineage.

CREATE FUNCTION builderad_private.business_source_audit_delivery(p_session text, p_obligation uuid, p_ack uuid) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE o public.source_audit_obligations;r public.source_command_receipts;prior public.source_audit_delivery;next_row public.source_audit_delivery;
 actor bigint;selected bigint;proof jsonb;
BEGIN
 SELECT obligation.* INTO o FROM public.source_audit_obligations obligation JOIN public.source_registry s ON s.id=obligation.source_id
  WHERE obligation.id=p_obligation AND s.custody_kind='business_native';
 IF NOT FOUND THEN RAISE EXCEPTION 'Business Source obligation unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.source_command_receipts WHERE source_id=o.source_id AND command_id=o.command_id;
 actor:=builderad_private.business_source_require('source.audit.recover',o.source_id,r.resource,r.operation,r.purpose,p_session);
 SELECT id INTO STRICT selected FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source'
  AND source_target_id=o.source_id AND source_resource=r.resource AND source_operation=r.operation AND source_purpose=r.purpose
  AND action='source.audit.recover' AND status='active' AND revoked_at IS NULL;
 proof:=builderad_private.business_source_proof(selected,actor,p_session,true);
 SELECT * INTO STRICT prior FROM public.source_audit_delivery WHERE obligation_id=o.id FOR UPDATE;
 IF p_ack IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.security_source_audit_custody c WHERE c.obligation_id=o.id AND c.acknowledgment_id=p_ack AND c.fingerprint=o.fingerprint AND c.envelope=o.envelope)
 THEN RAISE EXCEPTION 'Independent matching custody required' USING ERRCODE='42501'; END IF;
 IF prior.state<>'ACKNOWLEDGED' THEN
  PERFORM builderad_private.start_command(actor,p_session,uuidv7(),'source.audit.recover',jsonb_build_object('source_id',o.source_id,'obligation_id',o.id),proof);
  next_row:=prior;next_row.state:=CASE WHEN p_ack IS NULL THEN 'FAILED' ELSE 'ACKNOWLEDGED' END;
  next_row.acknowledgment_id:=p_ack;next_row.attempts:=prior.attempts+1;next_row.updated_at:=clock_timestamp();
  PERFORM builderad_private.permit('public.source_audit_delivery','update',to_jsonb(prior),to_jsonb(next_row));
  UPDATE public.source_audit_delivery SET state=next_row.state,acknowledgment_id=next_row.acknowledgment_id,attempts=next_row.attempts,updated_at=next_row.updated_at WHERE obligation_id=o.id;
  PERFORM builderad_private.business_source_proof(selected,actor,p_session,true);
  PERFORM builderad_private.finish_command();
 END IF;
 PERFORM builderad_private.business_source_require('source.audit.recover',o.source_id,r.resource,r.operation,r.purpose,p_session);
 RETURN (SELECT state FROM public.source_audit_delivery WHERE obligation_id=o.id);
END $$;


ALTER FUNCTION builderad_private.business_source_audit_delivery(p_session text, p_obligation uuid, p_ack uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_source_basis_current(p_grant bigint, p_actor bigint) RETURNS boolean
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT EXISTS(
  SELECT 1 FROM public.principal_lifecycles l
  JOIN public.users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
  JOIN public.security_action_grants g ON g.id=p_grant AND g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  JOIN public.source_registry s ON s.id=g.source_target_id AND s.custody_kind='business_native' AND s.business_id=g.business_id
  JOIN public.businesses b ON b.id=s.business_id AND b.created_by=p_actor AND b.origin_kind='self_declared_draft' AND b.lifecycle IN('declared_draft','active')
  JOIN public.migration_cohort_members member ON member.business_id=b.id AND member.kind='business'
  JOIN public.migration_cohort_control control ON control.cohort_id=member.cohort_id AND control.mode='V2_ACTIVE'
  JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  JOIN public.security_action_grants basis ON basis.id=q.issuer_grant_id AND basis.subject_user_id=l.user_id AND basis.subject_generation=l.generation
  JOIN public.authority_qualification_receipts basis_q ON basis_q.qualification_id=basis.qualification_id
  WHERE l.user_id=p_actor AND l.status='current' AND l.principal_class='advertiser' AND g.scope_kind='source'
   AND g.source='source_owner_request' AND g.granted_by=p_actor AND g.parent_grant_id IS NULL AND NOT g.delegable
   AND g.boundary_id IS NULL AND g.data_use_scope_id IS NULL AND g.delegation_realm_kind IS NULL
   AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
   AND q.policy_id='business_native_source_custody_v1' AND q.policy_version=1 AND q.issuer_kind='qualified_policy'
   AND q.subject_user_id=p_actor AND q.target_kind='source' AND q.target_ref=s.id AND g.action=ANY(q.allowed_actions)
   AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp())
   AND basis.business_id=b.id AND basis.scope_kind='business' AND basis.action='business.context.read'
   AND basis.source='business_draft_creation' AND basis.parent_grant_id IS NULL AND NOT basis.delegable
   AND basis.delegation_realm_kind='direct' AND basis.status='active' AND basis.revoked_at IS NULL
   AND(basis.expires_at IS NULL OR basis.expires_at>clock_timestamp())
   AND basis_q.policy_id='self_declared_draft' AND basis_q.policy_version=1 AND basis_q.issuer_kind='self_declared_draft'
   AND basis_q.subject_user_id=p_actor AND basis_q.target_kind='business' AND basis_q.target_ref=b.public_id
   AND basis.action=ANY(basis_q.allowed_actions) AND basis_q.status='active' AND basis_q.valid_from<=clock_timestamp()
   AND(basis_q.valid_until IS NULL OR basis_q.valid_until>clock_timestamp())
 );
$$;


ALTER FUNCTION builderad_private.business_source_basis_current(p_grant bigint, p_actor bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_source_grant_current(p_grant bigint, p_actor bigint, p_session text, p_fresh boolean) RETURNS boolean
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT builderad_private.business_source_basis_current(p_grant,p_actor) AND EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.user_id=p_actor AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.security_sessions ss ON ss.user_id=l.user_id AND ss.lifecycle_generation=l.generation AND ss.session_key_hash=p_session
  WHERE c.phase='principal' AND ss.revoked_at IS NULL AND ss.auth_method<>'internal_passkey'
   AND ss.idle_expires_at>clock_timestamp() AND ss.absolute_expires_at>clock_timestamp()
   AND(NOT p_fresh OR ss.authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 );
$$;


ALTER FUNCTION builderad_private.business_source_grant_current(p_grant bigint, p_actor bigint, p_session text, p_fresh boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_source_proof(p_grant bigint, p_actor bigint, p_session text, p_fresh boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE g public.security_action_grants;q public.authority_qualification_receipts;basis public.security_action_grants;basis_q public.authority_qualification_receipts;proof jsonb;authenticated timestamptz;
BEGIN
 IF NOT builderad_private.business_source_grant_current(p_grant,p_actor,p_session,p_fresh)
 THEN RAISE EXCEPTION 'Exact current Business-native Source qualification required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT g FROM public.security_action_grants WHERE id=p_grant;
 SELECT * INTO STRICT q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=q.issuer_grant_id;
 SELECT * INTO STRICT basis_q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 proof:=jsonb_build_object('codec_version',2,'actor',p_actor,'generation',g.subject_generation,'action',g.action,
  'native_realm',jsonb_build_object('custody_kind','business_native','business_id',g.business_id,
   'business',(SELECT public_id FROM public.businesses WHERE id=g.business_id),'source_id',g.source_target_id,
   'resource',g.source_resource,'operation',g.source_operation,'purpose',g.source_purpose),
  'grants',jsonb_build_array(to_jsonb(g),to_jsonb(basis)),'qualifications',jsonb_build_array(to_jsonb(q),to_jsonb(basis_q)));
 IF p_fresh THEN
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session;
  proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 END IF;
 RETURN proof;
END $$;


ALTER FUNCTION builderad_private.business_source_proof(p_grant bigint, p_actor bigint, p_session text, p_fresh boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_source_require(p_action text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_session text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;selected bigint;selected_read bigint;b_public uuid;selection jsonb;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 -- Own metadata is a preflight only. K1 and Business fences precede K4 locks;
 -- every current/time-dependent qualification is checked after those waits.
 SELECT g.id,b.public_id INTO selected,b_public FROM public.security_action_grants g
 JOIN public.source_registry s ON s.id=g.source_target_id AND s.custody_kind='business_native' AND s.business_id=g.business_id
 JOIN public.businesses b ON b.id=s.business_id
 WHERE g.subject_user_id=actor AND g.scope_kind='source' AND g.source_target_id=p_source
  AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose AND g.action=p_action
  AND g.status='active' AND g.revoked_at IS NULL;
 IF selected IS NULL THEN RAISE EXCEPTION 'Exact Business-native Source preflight denied' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Protected Source custody fence required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_business(b_public,false);
 selection:=jsonb_build_object('realm_kind','direct','requirements',
  jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',selected)));
 IF p_action='provider.read' THEN
  SELECT id INTO selected_read FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source'
   AND source_target_id=p_source AND source_resource=p_resource AND source_operation=p_operation AND source_purpose=p_purpose
   AND action='source.read' AND status='active' AND revoked_at IS NULL;
  IF selected_read IS NULL THEN RAISE EXCEPTION 'Independent Source read authority required' USING ERRCODE='42501'; END IF;
  selection:=jsonb_set(selection,'{requirements}',(selection->'requirements')||
   jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',selected_read)));
 END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,selection,p_session);
 PERFORM builderad_private.business_source_proof(selected,actor,p_session,p_action NOT IN('source.read','provider.read'));
 IF selected_read IS NOT NULL THEN PERFORM builderad_private.business_source_proof(selected_read,actor,p_session,false); END IF;
 RETURN actor;
END $$;


ALTER FUNCTION builderad_private.business_source_require(p_action text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_session text) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.require_business_source_creator(p_business bigint, p_actor bigint, p_session text, p_selection jsonb, p_fresh boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE proof jsonb;basis public.security_action_grants;q public.authority_qualification_receipts;authenticated timestamptz;
BEGIN
 proof:=builderad_private.require_business(p_business,p_actor,'business.context.read',p_selection,p_session);
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 SELECT * INTO STRICT q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR q.policy_id<>'self_declared_draft' OR q.policy_version<>1
 OR q.issuer_kind<>'self_declared_draft' OR basis.parent_grant_id IS NOT NULL OR basis.source<>'business_draft_creation'
 OR basis.delegable OR p_actor<>(SELECT created_by FROM public.businesses WHERE id=p_business AND origin_kind='self_declared_draft')
 THEN RAISE EXCEPTION 'Current original Business creator basis required for Source custody' USING ERRCODE='42501'; END IF;
 IF p_fresh THEN
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session;
  IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
  THEN RAISE EXCEPTION 'Fresh explicit Source connection confirmation required' USING ERRCODE='42501'; END IF;
  proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 END IF;
 RETURN proof;
END $$;


ALTER FUNCTION builderad_private.require_business_source_creator(p_business bigint, p_actor bigint, p_session text, p_selection jsonb, p_fresh boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.native_source_read_lock(p_sources uuid[]) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE source_ref uuid;business_ref uuid;
BEGIN
 -- Adoption's admission gate precedes K1/K4. Take the complete sorted union first.
 FOR source_ref IN SELECT DISTINCT unnest(p_sources) ORDER BY 1 LOOP
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('builderad.source.admission.'||source_ref,0));
 END LOOP;
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=ANY(p_sources) ORDER BY source_id FOR SHARE;
 FOR business_ref IN SELECT DISTINCT b.public_id FROM public.source_registry s JOIN public.businesses b ON b.id=(to_jsonb(s)->>'business_id')::bigint
  WHERE s.id=ANY(p_sources) AND to_jsonb(s)->>'custody_kind'='business_native' ORDER BY b.public_id LOOP
  PERFORM builderad_private.lock_business(business_ref,false);
 END LOOP;
END $$;


ALTER FUNCTION builderad_private.native_source_read_lock(p_sources uuid[]) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.native_source_selection(p_grant bigint) RETURNS jsonb
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('realm_kind','direct','requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',p_grant)));
$$;


ALTER FUNCTION builderad_private.native_source_selection(p_grant bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.material_reference(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
BEGIN
 IF v IS NULL OR jsonb_typeof(v) IS DISTINCT FROM 'object' OR octet_length(v::text)>8192
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k) IS DISTINCT FROM ARRAY['key','namespace','version']::text[]
 OR jsonb_typeof(v->'namespace') IS DISTINCT FROM 'string' OR(v->>'namespace') !~ '^[a-z][a-z0-9_.]{0,79}$'
 OR jsonb_typeof(v->'key') IS DISTINCT FROM 'string' OR octet_length(v->>'key') NOT BETWEEN 1 AND 4096
 OR jsonb_typeof(v->'version') IS DISTINCT FROM 'string' OR octet_length(v->>'version') NOT BETWEEN 1 AND 720
 THEN RAISE EXCEPTION 'Exact bounded material reference required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $_$;


ALTER FUNCTION builderad_private.material_reference(v jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.material_closure(p_refs jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
END $_$;


ALTER FUNCTION builderad_private.material_closure(p_refs jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.material_use_plan(p_actor bigint, p_business uuid, p_refs jsonb, p_operation text, p_purpose text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
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
    AND g.business_id=b AND g.business_id=(node#>>'{origin,business_id}')::bigint AND node#>>'{origin,custody_kind}'='business_native'
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


ALTER FUNCTION builderad_private.material_use_plan(p_actor bigint, p_business uuid, p_refs jsonb, p_operation text, p_purpose text, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.require_material_use(p_actor bigint, p_business bigint, p_session text, p_operation text, p_plan jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE item jsonb;node jsonb;s public.data_use_scopes;proof jsonb;proofs jsonb:='[]';uses jsonb:='[]';business_leaf bigint;
BEGIN
 proof:=builderad_private.require_scope('business',p_business::text,p_actor,'business.data.use',p_selection,p_session);
 IF proof#>>'{qualification,issuer_kind}'='self_declared_draft' OR(SELECT lifecycle FROM public.businesses WHERE id=p_business) IN('archived','suspended') THEN RAISE EXCEPTION 'Qualified Business use required' USING ERRCODE='42501'; END IF;
 business_leaf:=(proof#>>'{grants,0,id}')::bigint;
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
  IF proof#>>'{qualification,policy_id}'='declared_data_beneficiary_v1'
   AND (proof#>>'{qualification,issuer_grant_id}')::bigint IS DISTINCT FROM business_leaf
  THEN RAISE EXCEPTION 'Beneficiary licence requires its original current Business assignment' USING ERRCODE='42501'; END IF;
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


ALTER FUNCTION builderad_private.require_material_use(p_actor bigint, p_business bigint, p_session text, p_operation text, p_plan jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_material_resources(p_plan jsonb, p_output_scope jsonb DEFAULT NULL::jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
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


ALTER FUNCTION builderad_private.lock_material_resources(p_plan jsonb, p_output_scope jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.resolve_material(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
  SELECT e.content_hash,s.source_id,s.provider,s.resource,s.operation,s.purpose,s.stream,r.command_id,r.coverage,r.provenance,r.recorded_at
  INTO entry_row FROM public.provider_observation_entries e JOIN public.provider_observation_streams s USING(namespace)
  JOIN public.provider_observation_runs r ON r.namespace=e.namespace AND r.generation=e.generation
  WHERE e.namespace=native_key->>'namespace' AND e.generation=native_generation AND e.identity=native_key->>'identity';
  IF NOT FOUND OR entry_row.content_hash IS DISTINCT FROM ref->>'version' THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact native observation unavailable' USING ERRCODE='42501'; END IF;
  -- Custody comes from the immutable canonical Source, never NULL matching or a caller hint.
  SELECT * INTO source_row FROM public.source_registry WHERE id=entry_row.source_id;
  IF NOT FOUND OR source_row.custody_kind<>'business_native'
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact native Source custody required' USING ERRCODE='42501'; END IF;
  origin:=to_jsonb(entry_row)-'content_hash';
  origin:=origin||jsonb_build_object('custody_kind','business_native','business_id',source_row.business_id);
  empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
  RETURN jsonb_build_object('ref',ref,'kind','original','resolver_contract_version',1,'material_digest',entry_row.content_hash,
   'producer_receipt_ref','provider_observation_runs:'||(native_key->>'namespace')||':'||native_generation,
   'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),'closure_digest',NULL,'dependencies','[]'::jsonb,
   'origin',origin);
 END IF;
 -- Fixed registered-owner resolver only. Its production dispatcher remains unavailable until a canonical producer is installed.
 RETURN builderad_private.producer_material(ref);
END $_$;


ALTER FUNCTION builderad_private.resolve_material(p_ref jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.producer_dependencies(p_descriptor jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_agg(edge ORDER BY convert_to(edge::text,'UTF8')) FROM(
  SELECT DISTINCT value-'read_command_id' edge FROM jsonb_array_elements(p_descriptor->'input_reads')
 ) x
$$;


ALTER FUNCTION builderad_private.producer_dependencies(p_descriptor jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.producer_descriptor(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE d jsonb;r jsonb;receipt public.business_command_receipts;ids uuid[]:='{}';
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace'='market.knowledge' THEN RETURN builderad_private.knowledge_descriptor(p_ref); END IF;
 d:=builderad_private.registered_producer(p_ref);
 PERFORM builderad_private.bounded(d);
 IF jsonb_typeof(d) IS DISTINCT FROM 'object' OR d->'ref' IS DISTINCT FROM p_ref
 OR d->>'material_digest' IS DISTINCT FROM p_ref->>'version' OR coalesce(d->>'material_digest','') !~ '^[0-9a-f]{64}$'
 OR d->'resolver_contract_version' IS DISTINCT FROM '1'::jsonb OR d->>'processing_operation' IS DISTINCT FROM 'process'
 OR jsonb_typeof(d->'coverage') IS DISTINCT FROM 'string' OR d->>'coverage' NOT IN('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')
 OR jsonb_typeof(d->'provenance') IS DISTINCT FROM 'object'
 OR octet_length(coalesce(d->>'producer_receipt_ref','')) NOT BETWEEN 1 AND 4096
 OR coalesce(d->>'business_public_id','') !~ '^[0-9a-f-]{36}$' OR coalesce(d->>'output_scope_id','') !~ '^[0-9a-f-]{36}$'
 OR coalesce(d->>'processor_user_id','') !~ '^[1-9][0-9]{0,17}$' OR coalesce(d->>'purpose','')='' OR length(d->>'purpose')>128
 OR jsonb_typeof(d->'input_reads') IS DISTINCT FROM 'array' OR jsonb_array_length(d->'input_reads') NOT BETWEEN 1 AND 64
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: incomplete prepared producer record' USING ERRCODE='42501'; END IF;
 FOR r IN SELECT value FROM jsonb_array_elements(d->'input_reads') LOOP
  IF jsonb_typeof(r) IS DISTINCT FROM 'object' OR
   (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(r) k) IS DISTINCT FROM ARRAY['data_use_scope_id','input','policy_id','policy_version','read_command_id']::text[]

   OR coalesce(r->>'data_use_scope_id','') !~ '^[0-9a-f-]{36}$' OR coalesce(r->>'read_command_id','') !~ '^[0-9a-f-]{36}$'
   OR octet_length(coalesce(r->>'policy_id','')) NOT BETWEEN 1 AND 180 OR jsonb_typeof(r->'policy_version') IS DISTINCT FROM 'number' OR coalesce(r->>'policy_version','') !~ '^[1-9][0-9]{0,17}$'
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact captured input required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.material_reference(r->'input');
  SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=(r->>'read_command_id')::uuid;
  IF NOT FOUND OR receipt.actor_user_id<>(d->>'processor_user_id')::bigint OR receipt.action<>'material.process'
   OR receipt.business_id IS DISTINCT FROM(SELECT id FROM public.businesses WHERE public_id=(d->>'business_public_id')::uuid)
   OR receipt.result_ref->>'purpose' IS DISTINCT FROM d->>'purpose' OR (receipt.result_ref->'material_uses' @> jsonb_build_array(jsonb_build_object('ref',r->'input','data_use_scope_id',r->'data_use_scope_id','policy_id',r->'policy_id','policy_version',r->'policy_version'))) IS DISTINCT FROM true
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: missing original authorized read receipt' USING ERRCODE='42501'; END IF;
 END LOOP;
 RETURN d;
END $_$;


ALTER FUNCTION builderad_private.producer_descriptor(p_ref jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.producer_material(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE d jsonb;m public.material_lineage_manifests;edges jsonb;
BEGIN
 d:=builderad_private.producer_descriptor(p_ref);
 SELECT * INTO m FROM public.material_lineage_manifests WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF NOT FOUND OR m.kind<>'derived' OR d->'publication_manifest_id' IS DISTINCT FROM to_jsonb(m.manifest_id)
 OR m.producer_receipt_ref IS DISTINCT FROM d->>'producer_receipt_ref' OR m.material_digest<>decode(d->>'material_digest','hex') OR m.resolver_contract_version IS DISTINCT FROM(d->>'resolver_contract_version')::bigint
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: registered output is not atomically published' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('input',jsonb_build_object('namespace',input_namespace,'key',input_key,'version',input_version),
  'data_use_scope_id',data_use_scope_id,'policy_id',policy_id,'policy_version',policy_version) ORDER BY input_namespace,input_key,input_version),'[]') INTO edges
 FROM public.material_use_dependencies WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version';
 IF(SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) FROM jsonb_array_elements(edges)) IS DISTINCT FROM builderad_private.producer_dependencies(d)
 THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: captured and stored input sets differ' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'kind','derived','material_digest',d->'material_digest','resolver_contract_version',m.resolver_contract_version,'producer_receipt_ref',m.producer_receipt_ref,
 'direct_dependency_count',m.direct_dependency_count,'direct_dependency_digest',encode(m.direct_dependency_digest,'hex'),'closure_digest',encode(m.closure_digest,'hex'),'dependencies',edges,
 'origin',jsonb_build_object('registered_producer',true,'business_public_id',d->'business_public_id','output_scope_id',d->'output_scope_id'));
END $$;


ALTER FUNCTION builderad_private.producer_material(p_ref jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.registered_producer(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF p_ref->>'namespace'='business.expenditure_summary' THEN RETURN builderad_private.expenditure_summary_descriptor(p_ref); END IF;
 IF p_ref->>'namespace'='market.business_finding' THEN RETURN builderad_private.business_finding_descriptor(p_ref); END IF;
 IF p_ref->>'namespace'='market.knowledge' THEN RETURN builderad_private.knowledge_descriptor(p_ref); END IF;
 RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered canonical producer' USING ERRCODE='42501';
END $$;


ALTER FUNCTION builderad_private.registered_producer(p_ref jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.registered_producer_fields(p_ref jsonb, p_fields jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE reads jsonb;edge jsonb;row_value public.market_evidence_revisions;source uuid;items jsonb:='[]';result jsonb:='{}';fields text[]:=ARRAY['subject','geography','metric','comparison_basis'];
BEGIN
 IF NOT p_fields ? 'practice_basis' THEN RETURN builderad_private.registered_producer_fields_before_activity(p_ref,p_fields); END IF;
 IF p_ref->>'namespace' NOT IN('market.knowledge','market.business_finding') OR p_fields IS DISTINCT FROM '["finding","practice_basis"]'::jsonb
 THEN RAISE EXCEPTION 'Exact separately licensed practice projection required' USING ERRCODE='42501'; END IF;
 result:=builderad_private.registered_producer_fields_before_activity(p_ref,'["finding"]');
 IF p_ref->>'namespace'='market.knowledge' THEN
  SELECT input_reads INTO reads FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 ELSE SELECT input_reads INTO reads FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid; END IF;
 FOR edge IN SELECT value FROM jsonb_array_elements(reads) LOOP
  SELECT r.* INTO row_value FROM public.market_evidence_revisions r WHERE r.evidence_id=(edge#>>'{input,key}')::uuid AND r.content_digest=decode(edge#>>'{input,version}','hex');
  SELECT source_id INTO source FROM public.market_evidence_heads WHERE evidence_id=row_value.evidence_id;
  -- The projection has meaning only when the captured method actually consumed these fields.
  IF NOT coalesce(edge->'field_keys','[]'::jsonb) @> to_jsonb(fields) OR row_value.evidence_type<>'market.interest_signal:2' THEN CONTINUE; END IF;
  items:=items||jsonb_build_array(jsonb_build_object('source_ref',source,'subject',row_value.fields->'subject','geography',
    (row_value.fields->'geography')||CASE WHEN row_value.fields#>>'{geography,level}'='country' AND EXISTS(SELECT 1 FROM public.country_references WHERE code::text=row_value.fields#>>'{geography,ref}') THEN '{"authority":"iso3166-1"}'::jsonb ELSE '{}'::jsonb END,
    'metric',row_value.fields->'metric','period',jsonb_build_object('from',row_value.fields#>'{comparison_basis,period_start}','until',row_value.fields#>'{comparison_basis,period_end}'),
    'measurement',(row_value.fields->'comparison_basis')-'period_start'-'period_end','coverage',row_value.coverage));
 END LOOP;
 RETURN result||jsonb_build_object('practice_basis',jsonb_build_object('schema','market.practice_basis:1','signals',items));
END $$;


ALTER FUNCTION builderad_private.registered_producer_fields(p_ref jsonb, p_fields jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.registered_producer_fields_before_activity(p_ref jsonb, p_fields jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_knowledge_captures;
BEGIN
 IF p_ref->>'namespace'='business.expenditure_summary' THEN
  PERFORM builderad_private.expenditure_summary_descriptor(p_ref);
  IF p_fields IS DISTINCT FROM '["spend"]'::jsonb THEN RAISE EXCEPTION 'Exact expenditure-only field licence required' USING ERRCODE='42501'; END IF;
  RETURN builderad_private.expenditure_summary_value((SELECT input_reads#>'{0,input}' FROM public.business_expenditure_summaries WHERE summary_id=(p_ref->>'key')::uuid));
 END IF;
 IF p_ref->>'namespace'='market.business_finding' THEN RETURN builderad_private.business_finding_fields(p_ref,p_fields); END IF;
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 IF p_fields IS DISTINCT FROM '["finding"]'::jsonb THEN RAISE EXCEPTION 'Exact registered output fields required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 -- Original references remain internal lineage; a derived field licence does not imply their disclosure.
 RETURN jsonb_build_object('finding',(r.finding-'evidence_refs')||jsonb_build_object('validity',builderad_private.knowledge_validity(p_ref)));
END $$;


ALTER FUNCTION builderad_private.registered_producer_fields_before_activity(p_ref jsonb, p_fields jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.registered_producer_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF p_ref->>'namespace'='business.expenditure_summary' THEN PERFORM builderad_private.expenditure_summary_publish(p_ref,p_manifest,p_closure);RETURN; END IF;
 IF p_ref->>'namespace'='market.business_finding' THEN PERFORM builderad_private.business_finding_publish(p_ref,p_manifest,p_closure);RETURN; END IF;
 RAISE EXCEPTION 'Qualified producer coordinator owns atomic publication' USING ERRCODE='42501';
END $$;


ALTER FUNCTION builderad_private.registered_producer_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lineage_cb1(v jsonb, field_name text DEFAULT ''::text, depth integer DEFAULT 0) RETURNS text
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE payload text;item record;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'Lineage metadata depth limit' USING ERRCODE='22023'; END IF;
 IF v IS NULL OR v='null'::jsonb THEN RETURN 'N0:'; END IF;
 IF field_name IN('data_use_scope_id','source_id','boundary_id','command_id') THEN RETURN builderad_private.atom('U',v); END IF;
 IF field_name IN('material_digest','direct_dependency_digest','closure_digest','dependency_digest','from','to') THEN RETURN builderad_private.atom('X',v); END IF;
 IF field_name='recorded_at' THEN RETURN builderad_private.atom('T',v); END IF;
 IF field_name='provenance' THEN RETURN builderad_private.cb1(v); END IF;
 IF jsonb_typeof(v)='number' AND field_name IN('resolver_contract_version','policy_version','direct_dependency_count','generation','distinct_nodes','edge_count') THEN RETURN builderad_private.atom('I',v); END IF;
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
END $_$;


ALTER FUNCTION builderad_private.lineage_cb1(v jsonb, field_name text, depth integer) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lineage_digest(domain text, value jsonb) RETURNS bytea
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE encoded text;
BEGIN
 IF domain IS NULL OR domain NOT IN('lineage-manifest:1','dependency-set:1') THEN RAISE EXCEPTION 'Lineage domain required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(value);encoded:=builderad_private.lineage_cb1(value);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'Lineage encoded byte limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/'||domain,'UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;


ALTER FUNCTION builderad_private.lineage_digest(domain text, value jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.resource_dependency_digest(v jsonb) RETURNS bytea
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE row_value jsonb;payload text;prior_id uuid;current_id uuid;
BEGIN
 IF v IS NULL OR jsonb_typeof(v) IS DISTINCT FROM 'array' OR jsonb_array_length(v)>512 THEN RAISE EXCEPTION 'Bounded resource dependency set required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(v);payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
 FOR row_value IN SELECT value FROM jsonb_array_elements(v) LOOP
  IF jsonb_typeof(row_value) IS DISTINCT FROM 'object' OR coalesce(row_value->>'fence_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Exact dependency fence identity required' USING ERRCODE='22023'; END IF;
  current_id:=(row_value->>'fence_id')::uuid;
  IF prior_id IS NOT NULL AND current_id<=prior_id THEN RAISE EXCEPTION 'Canonical distinct resource dependency set required' USING ERRCODE='22023'; END IF;
  IF row_value->>'data_use_scope_id' IS NULL THEN
   -- v1 receipts predate the market data-use column. Its NULL must not
   -- change the encoded historical six-field dependency image.
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
END $_$;


ALTER FUNCTION builderad_private.resource_dependency_digest(v jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.expenditure_summary_constraints(p_ref jsonb, p_operation text, p_purpose text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.business_expenditure_summaries;s public.data_use_scopes;g public.security_action_grants;native_id bigint;
BEGIN
 PERFORM builderad_private.expenditure_summary_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.business_expenditure_summaries WHERE summary_id=(p_ref->>'key')::uuid;
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(r.input_reads#>>'{0,data_use_scope_id}')::uuid;
 IF p_operation NOT IN('display','retained_read') OR p_purpose IS DISTINCT FROM r.purpose OR r.valid_until<=clock_timestamp()
 OR r.consent IS DISTINCT FROM '{"derive":true,"deliver_summary":true,"retain_payload":false}'::jsonb
 OR s.status<>'active' OR s.operation<>'process' OR s.selection->'field_keys' IS DISTINCT FROM '["spend"]'::jsonb
 OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=r.business_id AND branch_id IS NULL AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE data_use_scope_id=s.scope_id AND business_id=r.business_id AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.source_registry WHERE id=s.source_id AND status<>'REVOKED')
 OR EXISTS(SELECT 1 FROM public.businesses WHERE id=r.business_id AND lifecycle IN('archived','suspended'))
 THEN RAISE EXCEPTION 'Continuing finite expenditure consent unavailable' USING ERRCODE='42501'; END IF;
 -- The canonical consumer freezes these producer-basis paths in its existing
 -- complete K4 footprint; recheck after waits without impersonating an issuer session.
 PERFORM builderad_private.verify_scope_path('business',r.business_id::text,r.processor_user_id,'business.data.use',r.input_selection,'{}','{}',false);
 PERFORM builderad_private.verify_scope_path('data_use',s.scope_id::text,r.processor_user_id,'data_use.process',r.input_selection,'{}','{}',false);
 PERFORM builderad_private.verify_scope_path('client_data',s.boundary_id::text,r.processor_user_id,'client_data.manage',r.input_selection,'{}','{}',false);
 SELECT g1.* INTO g FROM public.security_action_grants g1 JOIN jsonb_array_elements(r.input_selection->'requirements') req
 ON req->>'requirement_kind'='native_source' AND req->>'leaf_grant_id'=g1.id::text;
 IF g.id IS NULL OR g.status<>'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.principal_lifecycles WHERE user_id=g.subject_user_id AND generation=g.subject_generation AND status='current')
 THEN RAISE EXCEPTION 'Continuing native input basis unavailable' USING ERRCODE='42501'; END IF;
 IF NOT builderad_private.business_source_basis_current(g.id,r.processor_user_id)
 THEN RAISE EXCEPTION 'Continuing Business-native custody qualification unavailable' USING ERRCODE='42501'; END IF;
END $$;


ALTER FUNCTION builderad_private.expenditure_summary_constraints(p_ref jsonb, p_operation text, p_purpose text) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.expenditure_summary_descriptor(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE r public.business_expenditure_summaries;origin jsonb;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace' IS DISTINCT FROM 'business.expenditure_summary' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
 OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact expenditure projection required' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM public.business_expenditure_summaries WHERE summary_id=(p_ref->>'key')::uuid AND material_digest=decode(p_ref->>'version','hex');
 IF r.summary_id IS NULL THEN RAISE EXCEPTION 'Registered expenditure projection unavailable' USING ERRCODE='42501'; END IF;
 origin:=builderad_private.resolve_material(r.input_reads#>'{0,input}');
 IF builderad_private.digest('command-payload:1',builderad_private.expenditure_summary_value(r.input_reads#>'{0,input}')) IS DISTINCT FROM r.material_digest
 THEN RAISE EXCEPTION 'Immutable expenditure projection changed' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'resolver_contract_version',1,'business_public_id',(SELECT public_id FROM public.businesses WHERE id=r.business_id),
  'processor_user_id',r.processor_user_id,'output_scope_id',r.output_scope_id,'purpose',r.purpose,'processing_operation','process',
  'material_digest',p_ref->'version','producer_receipt_ref','business_expenditure_summary:'||r.summary_id,
  'coverage',origin#>'{origin,coverage}','provenance',jsonb_build_object('kind','licensed_expenditure_projection'),
  'input_reads',r.input_reads,'publication_manifest_id',r.publication_manifest_id);
END $_$;


ALTER FUNCTION builderad_private.expenditure_summary_descriptor(p_ref jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.expenditure_summary_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.business_expenditure_summaries;r public.business_expenditure_summaries;
BEGIN
 PERFORM builderad_private.expenditure_summary_constraints(p_ref,'display',(SELECT purpose FROM public.business_expenditure_summaries WHERE summary_id=(p_ref->>'key')::uuid));
 SELECT * INTO STRICT prior FROM public.business_expenditure_summaries WHERE summary_id=(p_ref->>'key')::uuid FOR UPDATE;r:=prior;
 IF prior.publication_manifest_id IS NOT NULL OR NOT EXISTS(SELECT 1 FROM public.material_lineage_manifests m
 WHERE m.manifest_id=p_manifest AND m.material_namespace='business.expenditure_summary' AND m.material_key=r.summary_id::text
 AND m.material_digest=r.material_digest AND m.closure_digest=p_closure AND m.state='sealed')
 THEN RAISE EXCEPTION 'Exact atomic expenditure publication required' USING ERRCODE='42501'; END IF;
 r.publication_manifest_id:=p_manifest;
 PERFORM builderad_private.permit('public.business_expenditure_summaries','update',to_jsonb(prior),to_jsonb(r));
 UPDATE public.business_expenditure_summaries SET publication_manifest_id=p_manifest WHERE summary_id=r.summary_id;
END $$;


ALTER FUNCTION builderad_private.expenditure_summary_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.expenditure_summary_value(p_input jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE k jsonb;amount jsonb;major text;
BEGIN
 PERFORM builderad_private.resolve_material(p_input);
 IF p_input->>'namespace' IS DISTINCT FROM 'provider_observation_entry' THEN RAISE EXCEPTION 'Exact native expenditure input required' USING ERRCODE='42501'; END IF;
 k:=(p_input->>'key')::jsonb;
 SELECT observation->'spend' INTO amount FROM public.provider_observation_entries
 WHERE namespace=k->>'namespace' AND generation=(k->>'generation')::bigint AND identity=k->>'identity' AND content_hash=p_input->>'version';
 IF jsonb_typeof(amount) IS DISTINCT FROM 'object'
 OR coalesce(jsonb_typeof(amount->'major_units'),'missing') NOT IN('string','null')
 OR coalesce(jsonb_typeof(amount->'native_value'),'missing') NOT IN('string','null')
 OR coalesce(jsonb_typeof(amount->'currency'),'missing') NOT IN('string','null')
 OR coalesce(jsonb_typeof(amount->'decimal_scale'),'missing') NOT IN('number','null')
 THEN RAISE EXCEPTION 'Canonical monetary observation required' USING ERRCODE='42501'; END IF;
 major:=amount->>'major_units';
 IF(major IS NOT NULL AND major !~ '^-?[0-9]{1,60}(\.[0-9]{1,48})?$') OR(amount->>'currency' IS NOT NULL AND amount->>'currency' !~ '^[A-Z]{3}$')
 OR(amount->>'native_value' IS NOT NULL AND amount->>'native_value' !~ '^-?[0-9]{1,60}(\.[0-9]{1,30})?$')
 OR(amount->>'decimal_scale' IS NOT NULL AND amount->>'decimal_scale' !~ '^(0|[1-9]|1[0-8])$')
 THEN RAISE EXCEPTION 'Exact monetary interpretation required' USING ERRCODE='42501'; END IF;
 IF major IS NOT NULL AND(amount->>'native_value' IS NULL OR amount->>'decimal_scale' IS NULL
  OR jsonb_typeof(amount->'scale_basis') IS DISTINCT FROM 'string' OR length(btrim(amount->>'scale_basis'))=0)
 THEN RAISE EXCEPTION 'Known monetary scale required for a known amount' USING ERRCODE='42501'; END IF;
 IF major IS NOT NULL AND major::numeric * power(10::numeric,(amount->>'decimal_scale')::integer) IS DISTINCT FROM(amount->>'native_value')::numeric
 THEN RAISE EXCEPTION 'Canonical native and major amounts must agree exactly' USING ERRCODE='42501'; END IF;
 -- Preserve the exact bounded native value and its known decimal scale, including
 -- a major-unit fraction up to48 places. No conversion, rounding or vendor text.
 RETURN jsonb_build_object('spend',jsonb_build_object('native_value',CASE WHEN major IS NULL THEN 'null'::jsonb ELSE amount->'native_value' END,'major_units',amount->'major_units','currency',amount->'currency',
  'native_unit','SCALED_CURRENCY_UNIT','decimal_scale',amount->'decimal_scale',
  'scale_basis',CASE WHEN amount->>'decimal_scale' IS NULL THEN NULL ELSE 'canonical_native_amount_scale:1' END));
END $_$;


ALTER FUNCTION builderad_private.expenditure_summary_value(p_input jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.guard_expenditure_summary() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF (to_jsonb(OLD)-'publication_manifest_id') IS DISTINCT FROM(to_jsonb(NEW)-'publication_manifest_id')
 OR OLD.publication_manifest_id IS NOT NULL OR NEW.publication_manifest_id IS NULL
 THEN RAISE EXCEPTION 'Expenditure capture and consent are immutable; only initial atomic publication is allowed' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.guard_expenditure_summary() OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_source_bootstrap(p_session text, p_business uuid, p_command uuid, p_source uuid, p_payload jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;generation bigint;proof jsonb;payload bytea;result jsonb;native_result jsonb;
 basis public.security_action_grants;basis_q public.authority_qualification_receipts;q public.authority_qualification_receipts;
 g public.security_action_grants;old_g public.security_action_grants;s public.source_registry;f public.resource_right_fences;
 actions text[];action_name text;grant_refs jsonb:='{}';issued_qualification boolean:=false;changed boolean:=false;
BEGIN
 IF p_command IS NULL OR p_source IS NULL OR uuid_extract_version(p_source)<>7
 THEN RAISE EXCEPTION 'Exact server-bound Source bootstrap identity required' USING ERRCODE='22023'; END IF;
 p_payload:=builderad_private.native_source_payload('register',p_payload);
 IF (p_payload->>'expected_version')::bigint<>0
 OR coalesce(p_payload->>'family','') !~ '^[a-z][a-z0-9_]{0,39}$'
 OR length(btrim(coalesce(p_payload->>'namespace',''))) NOT BETWEEN 1 AND 80
 OR length(btrim(coalesce(p_payload->>'external_id',''))) NOT BETWEEN 1 AND 180
 OR length(btrim(coalesce(p_payload->>'label',''))) NOT BETWEEN 1 AND 120
 THEN RAISE EXCEPTION 'Exact initial Source declaration required' USING ERRCODE='22023'; END IF;
 actions:=ARRAY['source.register','source.read','source.manage','source.audit.recover'];
 IF p_payload->>'family' IN('meta','google','tiktok','snapchat','x')
 AND p_payload->>'purpose'='advertising_analysis' AND p_payload->>'resource'=p_payload->>'external_id' THEN
  IF p_payload->>'operation'='report.read' AND p_payload->>'namespace'='ad_account' THEN
   actions:=actions||ARRAY['provider.connect','provider.read','provider.revoke'];
  ELSIF p_payload->>'operation'='discovery.read' AND p_payload->>'namespace'=(CASE p_payload->>'family'
    WHEN 'meta' THEN 'facebook_login_configuration' WHEN 'google' THEN 'oauth_client' ELSE 'developer_app' END) THEN
   actions:=ARRAY['source.register','source.read','source.audit.recover','provider.connect'];
  END IF;
 END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'source_id',p_source,'payload',p_payload),'{"business":"U","source_id":"U"}');
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.source.command.'||p_command,0));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 IF FOUND AND f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'Protected Business Source custody required' USING ERRCODE='42501'; END IF;
 b:=builderad_private.lock_business(p_business,false);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business_source_creator(b,actor,p_session,p_selection,true);
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 SELECT * INTO STRICT basis_q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 SELECT l.generation INTO STRICT generation FROM public.principal_lifecycles l WHERE user_id=actor;
 result:=builderad_private.command_replay(b,actor,p_command,'source.business.bootstrap',payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'source.business.bootstrap',jsonb_build_object('business_id',b,'source_id',p_source),proof);
 SELECT * INTO s FROM public.source_registry WHERE id=p_source;
 IF FOUND THEN
  IF s.custody_kind<>'business_native' OR s.business_id IS DISTINCT FROM b OR s.status='REVOKED'
   OR s.family IS DISTINCT FROM p_payload->>'family' OR s.namespace IS DISTINCT FROM p_payload->>'namespace'
   OR s.declared_external_id IS DISTINCT FROM p_payload->>'external_id'
   OR NOT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=s.id
    AND resource=p_payload->>'resource' AND operation=p_payload->>'operation' AND purpose=p_payload->>'purpose')
  THEN RAISE EXCEPTION 'Exact active Business-native connection identity required' USING ERRCODE='42501'; END IF;
 ELSE
  native_result:=builderad_private.apply_native_source(actor,generation,b,p_command,p_source,'register',p_payload);
  SELECT * INTO STRICT s FROM public.source_registry WHERE id=p_source;
  f.fence_id:=uuidv7();f.kind:='source';f.source_id:=p_source;f.boundary_id:=NULL;f.data_use_scope_id:=NULL;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));
  INSERT INTO public.resource_right_fences SELECT(f).*;
  changed:=true;
 END IF;
 FOREACH action_name IN ARRAY actions LOOP
  SELECT * INTO old_g FROM public.security_action_grants
   WHERE scope_kind='source' AND source_target_id=p_source AND subject_user_id=actor AND subject_generation=generation
    AND source_resource=p_payload->>'resource' AND source_operation=p_payload->>'operation' AND source_purpose=p_payload->>'purpose'
    AND action=action_name AND status='active';
  IF FOUND AND builderad_private.business_source_grant_current(old_g.id,actor,p_session,true) THEN
   grant_refs:=grant_refs||jsonb_build_object(action_name,old_g.id::text);CONTINUE;
  END IF;
  IF old_g.id IS NOT NULL THEN
   g:=old_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
   PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
   UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
  END IF;
  IF NOT issued_qualification THEN
   q.qualification_id:=uuidv7();q.policy_id:='business_native_source_custody_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=basis.id;
   q.subject_user_id:=actor;q.target_kind:='source';q.target_ref:=p_source;q.allowed_actions:=actions;
   q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis_q.valid_until;
   q.provenance:='Explicit fresh original-creator Source custody; no external ownership, licence, data use or additional Business authority';
   PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));
   INSERT INTO public.authority_qualification_receipts SELECT(q).*;issued_qualification:=true;
  END IF;
  g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=generation;
  g.granted_by:=actor;g.source:='source_owner_request';g.scope_kind:='source';g.business_id:=b;g.qualification_id:=q.qualification_id;
  g.source_target_id:=p_source;g.source_resource:=p_payload->>'resource';g.source_operation:=p_payload->>'operation';g.source_purpose:=p_payload->>'purpose';g.action:=action_name;
  g.reason:='Explicit Business-native Source connection';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.delegable:=false;g.expires_at:=basis.expires_at;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  grant_refs:=grant_refs||jsonb_build_object(action_name,g.id::text);changed:=true;
 END LOOP;
 IF changed THEN PERFORM builderad_private.advance_right_epoch(f.fence_id); END IF;
 result:=jsonb_build_object('business_public_id',p_business,'source_id',p_source,'custody_kind','business_native','registration_state',s.status,
  'grant_refs',grant_refs,'native_receipt',native_result,'versions',jsonb_build_object('source',s.version),'establishes_business_data_use',false);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'source.business.bootstrap',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_source_bootstrap(p_session text, p_business uuid, p_command uuid, p_source uuid, p_payload jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_source_choices(p_session text, p_business uuid, p_read bigint, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE native_choices jsonb;business_projection jsonb;item jsonb;matched jsonb:='[]'::jsonb;source_row public.source_registry;b bigint;
BEGIN
 IF p_after IS NULL OR p_after<0 OR p_read IS NULL OR p_read<1 THEN RAISE EXCEPTION 'Exact Business source read required' USING ERRCODE='22023'; END IF;
 -- Source gates precede the Business authority fence; both paths qualify before release.
 native_choices:=public.builderad_v2_source_choices(p_session,p_after);
 business_projection:=public.builderad_v2_business_read(p_session,p_business,builderad_private.business_selection(p_read));
 SELECT id INTO STRICT b FROM public.businesses WHERE public_id=p_business;
 FOR item IN SELECT value FROM jsonb_array_elements(native_choices->'sources') LOOP
  SELECT * INTO source_row FROM public.source_registry WHERE id=(item->>'source_id')::uuid AND custody_kind='business_native' AND business_id=b;
  IF NOT FOUND THEN CONTINUE; END IF;
  matched:=matched||jsonb_build_array(item||jsonb_build_object('family',source_row.family,'namespace',source_row.namespace));
 END LOOP;
 RETURN jsonb_build_object('business_public_id',p_business,'sources',matched,'next_cursor',native_choices->'next_cursor',
  'scope','current_operator_in_business','inventory_kind','manageable_sources','establishes_business_data_use',false);
END
$$;


ALTER FUNCTION public.builderad_v2_business_source_choices(p_session text, p_business uuid, p_read bigint, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_source_owner(p_session text, p_business uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;selected bigint;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 -- Locator preflight only: taking Business/K4 locks here would precede the
 -- Source K1 fence in the actual operation. Each Source action qualifies and
 -- locks its complete authority again in the established order.
 SELECT business.id,g.id INTO b,selected FROM public.businesses business
 JOIN public.migration_cohort_members member ON member.business_id=business.id AND member.kind='business'
 JOIN public.migration_cohort_control control ON control.cohort_id=member.cohort_id AND control.mode='V2_ACTIVE'
 JOIN public.security_action_grants g ON g.business_id=business.id
 JOIN public.principal_lifecycles lifecycle ON lifecycle.user_id=actor AND lifecycle.generation=g.subject_generation
 JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE business.public_id=p_business AND business.created_by=actor AND business.origin_kind='self_declared_draft'
  AND business.lifecycle IN('declared_draft','active') AND g.scope_kind='business' AND g.subject_user_id=actor
  AND g.action='business.context.read' AND g.source='business_draft_creation' AND g.parent_grant_id IS NULL AND NOT g.delegable
  AND g.delegation_realm_kind='direct'
  AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
  AND q.policy_id='self_declared_draft' AND q.policy_version=1 AND q.issuer_kind='self_declared_draft'
  AND q.subject_user_id=actor AND q.target_kind='business' AND q.target_ref=p_business AND g.action=ANY(q.allowed_actions)
  AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp());
 IF selected IS NULL THEN RAISE EXCEPTION 'Exact native Source administration basis required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('business_id',b,'business_public_id',p_business,'read_grant_ref',selected);
END $$;


ALTER FUNCTION public.builderad_v2_business_source_owner(p_session text, p_business uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_source_choices(p_session text, p_after bigint DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;ids bigint[];sources uuid[];requirements jsonb;g public.security_action_grants;items jsonb:='[]';accepted bigint[]:='{}';selection jsonb;cursor_id bigint;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Invalid source cursor' USING ERRCODE='22023'; END IF;
 SELECT array_agg(id ORDER BY id),array_agg(source_target_id ORDER BY id) INTO ids,sources FROM(
  SELECT id,source_target_id FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND action='source.manage'
  AND id>p_after AND status='active' AND revoked_at IS NULL AND(expires_at IS NULL OR expires_at>clock_timestamp()) ORDER BY id LIMIT 32) bounded;
 PERFORM builderad_private.native_source_read_lock(coalesce(sources,'{}'));
 SELECT coalesce(jsonb_agg(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',id)),'[]') INTO requirements FROM unnest(ids) id;
 IF cardinality(ids)>0 THEN PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session); END IF;
 FOREACH cursor_id IN ARRAY coalesce(ids,'{}') LOOP
  SELECT * INTO g FROM public.security_action_grants WHERE id=cursor_id;
  -- A preflight row cannot be retargeted to an unlocked source while we wait.
  IF g.source_target_id IS DISTINCT FROM sources[array_position(ids,cursor_id)] THEN CONTINUE; END IF;
  selection:=builderad_private.native_source_selection(g.id);
  BEGIN
   PERFORM builderad_private.require_native_source_grant(g.id,actor,p_session,selection,true,false);
   IF g.action<>'source.manage' THEN CONTINUE; END IF;
   IF NOT EXISTS(SELECT 1 FROM public.source_registry s WHERE s.id=g.source_target_id AND s.custody_kind='business_native' AND s.business_id=g.business_id) THEN CONTINUE; END IF;
   items:=items||jsonb_build_array((SELECT jsonb_build_object('source_id',s.id,'label',s.label,'status',s.status,'access_grant_id',g.id::text,
    'resource',g.source_resource,'operation',g.source_operation,'purpose',g.source_purpose) FROM public.source_registry s WHERE s.id=g.source_target_id));
   accepted:=array_append(accepted,g.id);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOREACH cursor_id IN ARRAY accepted LOOP
  PERFORM builderad_private.require_native_source_grant(cursor_id,actor,p_session,builderad_private.native_source_selection(cursor_id),true,false);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('sources',items,'next_cursor',CASE WHEN cardinality(ids)=32 THEN ids[32]::text ELSE NULL END);
END $$;


ALTER FUNCTION public.builderad_v2_source_choices(p_session text, p_after bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_source_surface(p_session text, p_source uuid, p_access bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;g public.security_action_grants;s public.source_registry;selection jsonb;f public.resource_right_fences;rights jsonb;more boolean;fresh boolean;declared boolean;
BEGIN
 actor:=builderad_private.principal(p_session,false);selection:=builderad_private.native_source_selection(p_access);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_access AND subject_user_id=actor AND scope_kind='source' AND action='source.manage' AND source_target_id=p_source)
 THEN RAISE EXCEPTION 'Exact native source manager preflight required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.native_source_read_lock(ARRAY[p_source]);
 PERFORM builderad_private.require_native_source_grant(p_access,actor,p_session,selection,true,false);
 SELECT * INTO STRICT g FROM public.security_action_grants WHERE id=p_access;
 IF g.action<>'source.manage' OR g.source_target_id IS DISTINCT FROM p_source THEN RAISE EXCEPTION 'Source manager target changed' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.source_registry WHERE id=p_source AND business_id=g.business_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'Exact native source required' USING ERRCODE='42501'; END IF;
 SELECT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=p_source AND resource=g.source_resource AND operation=g.source_operation AND purpose=g.source_purpose) INTO declared;
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source;
 SELECT count(*)>64 INTO more FROM(SELECT 1 FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND source_target_id=p_source AND business_id=g.business_id
  AND source='source_owner_request' AND source_resource=g.source_resource AND source_operation=g.source_operation AND source_purpose=g.source_purpose LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',id::text,'action',action,'recorded_status',status,'revision',version,'expires_at',expires_at) ORDER BY id DESC),'[]') INTO rights
 FROM(SELECT * FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND source_target_id=p_source AND business_id=g.business_id
  AND source='source_owner_request' AND source_resource=g.source_resource AND source_operation=g.source_operation AND source_purpose=g.source_purpose ORDER BY id DESC LIMIT 64) bounded;
 fresh:=EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp());
 PERFORM builderad_private.require_native_source_grant(p_access,actor,p_session,selection,true,false);
 RETURN jsonb_build_object('source',jsonb_build_object('source_id',s.id,'label',s.label,'family',s.family,'namespace',s.namespace,'status',s.status,'revision',s.version,
  'adopted',coalesce(f.adoption_state='v2_adopted',false),'scope_declared',declared,'resource',g.source_resource,'operation',g.source_operation,'purpose',g.source_purpose),
  'authority_selection',selection,'can_command',fresh,'rights',rights,'rights_limit',64,'rights_more',more);
END $$;


ALTER FUNCTION public.builderad_v2_source_surface(p_session text, p_source uuid, p_access bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_data_license_journey(p_session text, p_business uuid, p_access bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;read_selection jsonb;proof jsonb;selection jsonb;paths jsonb;all_paths jsonb;
 source_ids uuid[];boundary_ids uuid[];sources jsonb:='[]';licenses jsonb:='[]';uses jsonb:='[]';bindings jsonb:='[]';
 source_row record;dataset_row record;license_row record;grant_row public.security_action_grants;manager_id bigint;reader_id bigint;datasets jsonb;refs jsonb;
 record_metadata jsonb;records_complete boolean;datasets_more boolean;
 row_value jsonb;coverage_row public.boundary_subjects;binding_row public.business_data_use_bindings;previous_grant bigint;can_enroll boolean;sources_more boolean;licenses_more boolean;
 beneficiaries jsonb:='[]';assignments jsonb:='[]';assigned_licenses jsonb:='[]';beneficiary_row record;
 licensed_grant bigint;licence_until timestamptz;reader_operation text;reader_purpose text;summary_info jsonb;summary_ref jsonb;
BEGIN
 actor:=builderad_private.principal(p_session,false);SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 read_selection:=builderad_private.business_selection(p_access);
 -- Candidate selection is not authority. Complete K1 set precedes Business/K4 locks.
 SELECT coalesce(array_agg(candidate.id ORDER BY candidate.id),'{}'::uuid[]) INTO source_ids FROM(SELECT DISTINCT sr.id FROM public.source_registry sr
  JOIN public.security_action_grants g ON g.source_target_id=sr.id AND g.scope_kind='source' AND g.subject_user_id=actor AND g.action IN('source.manage','source.read')
  WHERE sr.status<>'REVOKED' AND sr.namespace='ad_account' AND sr.custody_kind='business_native' AND sr.business_id=b
  ORDER BY sr.id LIMIT 33) candidate;
 sources_more:=cardinality(source_ids)>32;
 IF sources_more THEN source_ids:=source_ids[1:32];END IF;
 SELECT coalesce(array_agg(c.boundary_id),'{}'::uuid[]) INTO boundary_ids FROM(
  SELECT boundary_id FROM public.client_data_boundaries WHERE descriptor->>'policy_id'='declared_data_license_v1'
   AND descriptor->>'business_public_id'=p_business::text AND((descriptor->>'declarant_user_id'=actor::text
    AND descriptor->>'declarant_generation'=(SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)) OR EXISTS(
    SELECT 1 FROM public.data_use_scopes s JOIN public.security_action_grants assigned ON assigned.data_use_scope_id=s.scope_id
    JOIN public.authority_qualification_receipts assigned_q ON assigned_q.qualification_id=assigned.qualification_id
    WHERE s.boundary_id=client_data_boundaries.boundary_id AND assigned.subject_user_id=actor AND assigned_q.policy_id='declared_data_beneficiary_v1'
    AND assigned.status='active' AND assigned.revoked_at IS NULL AND assigned_q.status='active' AND assigned.expires_at>clock_timestamp()
    AND assigned.subject_generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor))) ORDER BY boundary_id DESC LIMIT 32
 ) c;
 SELECT count(*)>32 INTO licenses_more FROM public.client_data_boundaries WHERE descriptor->>'policy_id'='declared_data_license_v1'
  AND descriptor->>'business_public_id'=p_business::text AND descriptor->>'declarant_user_id'=actor::text;
 PERFORM 1 FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(source_ids)) OR(kind='client_boundary' AND boundary_id=ANY(boundary_ids)) ORDER BY fence_id FOR SHARE;
 b:=builderad_private.lock_business(p_business,false);
 SELECT coalesce(jsonb_agg(jsonb_build_object('requirement_kind',CASE WHEN scope_kind='source' THEN 'native_source' ELSE scope_kind END,'leaf_grant_id',id)
  ORDER BY(id=p_access) DESC,(scope_kind='business') DESC,(scope_kind='source') DESC,id DESC),'[]') INTO all_paths
 FROM(SELECT id,scope_kind FROM public.security_action_grants WHERE subject_user_id=actor AND status='active' AND revoked_at IS NULL
  AND(expires_at IS NULL OR expires_at>clock_timestamp()) AND((scope_kind='source' AND source_target_id=ANY(source_ids) AND action IN('source.manage','source.read'))
  OR(scope_kind='business' AND business_id=b AND(action IN('business.data.use','business.link.manage') OR id=p_access))
  OR(scope_kind='data_use' AND data_use_scope_id IN(SELECT scope_id FROM public.data_use_scopes WHERE boundary_id=ANY(boundary_ids))))
  ORDER BY (id=p_access) DESC,(scope_kind='business') DESC,(scope_kind='source') DESC,id DESC LIMIT 33) candidates;
 IF jsonb_array_length(all_paths)>32 THEN
  licenses_more:=true;
  IF all_paths#>>'{32,requirement_kind}'<>'data_use' THEN sources_more:=true;END IF;
  all_paths:=all_paths-32;
 END IF;
 -- This inventory admits candidates, not an operation. A withdrawn producer
 -- basis removes that output candidate; it must not deny independent Business read.
 paths:='[]';
 FOR row_value IN SELECT value FROM jsonb_array_elements(all_paths) LOOP
  IF row_value->>'requirement_kind'='data_use' AND to_regclass('public.business_expenditure_summaries') IS NOT NULL THEN
   SELECT jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')) INTO summary_ref
    FROM public.business_expenditure_summaries WHERE output_scope_id=(SELECT data_use_scope_id FROM public.security_action_grants WHERE id=(row_value->>'leaf_grant_id')::bigint);
   IF summary_ref IS NOT NULL THEN
    BEGIN PERFORM builderad_private.expenditure_summary_constraints(summary_ref,'display',(SELECT purpose FROM public.business_expenditure_summaries WHERE summary_id=(summary_ref->>'key')::uuid));
    EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
   END IF;
  END IF;
  paths:=paths||jsonb_build_array(row_value);
 END LOOP;
 all_paths:=paths;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',all_paths),p_session);
 licenses_more:=licenses_more OR EXISTS(SELECT 1 FROM public.data_use_scopes s JOIN public.client_data_boundaries boundary ON boundary.boundary_id=s.boundary_id
  WHERE boundary.boundary_id=ANY(boundary_ids) AND(boundary.descriptor->>'declarant_user_id'=actor::text OR EXISTS(
   SELECT 1 FROM public.security_action_grants own WHERE own.subject_user_id=actor AND own.data_use_scope_id=s.scope_id
    AND own.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)))) OFFSET 32 LIMIT 1);
 proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 -- Availability describes new issuance, independently of historical mandate validation.
 can_enroll:=coalesce(builderad_private.operating_mandate_basis(b,actor,proof),false)
  AND read_selection->>'realm_kind'='direct' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path_grant(value)
   WHERE path_grant.value->>'delegation_realm_kind' IS DISTINCT FROM 'direct');
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='business' AND business_id=b
  AND action IN('business.data.use','business.link.manage') AND id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)) ORDER BY id LOOP
  IF grant_row.delegation_realm_kind IS DISTINCT FROM read_selection->>'realm_kind' THEN CONTINUE;END IF;
  BEGIN
   PERFORM builderad_private.require_scope('business',b::text,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
   row_value:=jsonb_build_object('grant_id',grant_row.id::text);
   IF grant_row.action='business.data.use' THEN uses:=uses||jsonb_build_array(row_value);ELSE bindings:=bindings||jsonb_build_array(row_value);END IF;
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
 END LOOP;
 FOR source_row IN SELECT * FROM public.source_registry WHERE id=ANY(source_ids) ORDER BY id LOOP
  manager_id:=NULL;reader_id:=NULL;reader_operation:=NULL;reader_purpose:=NULL;datasets:='[]';datasets_more:=false;
  FOR grant_row IN SELECT * FROM public.security_action_grants WHERE scope_kind='source' AND source_target_id=source_row.id AND subject_user_id=actor
   AND source_resource=source_row.declared_external_id AND action IN('source.manage','source.read')
   AND id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)) ORDER BY id LOOP
   selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',grant_row.id)));
   BEGIN
    PERFORM builderad_private.require_native_source_grant(grant_row.id,actor,p_session,selection,grant_row.action='source.manage',false);
    IF grant_row.action='source.manage' THEN manager_id:=grant_row.id;ELSE reader_id:=grant_row.id;reader_operation:=grant_row.source_operation;reader_purpose:=grant_row.source_purpose;END IF;
   EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
  END LOOP;
  IF manager_id IS NULL AND reader_id IS NULL THEN CONTINUE;END IF;
  IF reader_id IS NOT NULL THEN
   FOR dataset_row IN SELECT stream.namespace,stream.generation,run.coverage FROM public.provider_observation_streams stream
    JOIN public.provider_observation_runs run ON run.namespace=stream.namespace AND run.generation=stream.generation
    WHERE stream.source_id=source_row.id AND stream.resource=source_row.declared_external_id
    AND stream.operation=reader_operation AND stream.purpose=reader_purpose
    AND EXISTS(SELECT 1 FROM public.provider_observation_entries entry WHERE entry.namespace=stream.namespace AND entry.generation=stream.generation AND entry.observation ? 'spend')
    ORDER BY stream.namespace LIMIT 9 LOOP
    IF jsonb_array_length(datasets)=8 THEN datasets_more:=true;EXIT;END IF;
    SELECT coalesce(jsonb_agg(e.ref ORDER BY e.identity) FILTER(WHERE e.ordinal<=32),'[]'),count(*)<=32,
     coalesce(jsonb_agg(jsonb_build_object('ref',e.ref,
      'campaign_id',CASE WHEN jsonb_typeof(e.observation->'campaign_id')='string' THEN left(e.observation->>'campaign_id',180) END,
      'start',CASE WHEN jsonb_typeof(e.observation#>'{window,start}')='string' THEN left(e.observation#>>'{window,start}',64) END,
      'end',CASE WHEN jsonb_typeof(e.observation#>'{window,end}')='string' THEN left(e.observation#>>'{window,end}',64) END,
      'boundary',CASE WHEN e.observation#>>'{window,boundary}' IN('INCLUSIVE_DATES','HALF_OPEN_INSTANTS') THEN e.observation#>>'{window,boundary}' END,
      'grain',CASE WHEN e.observation#>>'{window,grain}' IN('TOTAL','DAY','HOUR') THEN e.observation#>>'{window,grain}' END,
      'timezone',CASE WHEN jsonb_typeof(e.observation#>'{window,timezone}')='string' THEN left(e.observation#>>'{window,timezone}',64) END) ORDER BY e.identity) FILTER(WHERE e.ordinal<=32),'[]')
     INTO refs,records_complete,record_metadata FROM(
      SELECT identity,observation,row_number() OVER(ORDER BY identity) ordinal,
       jsonb_build_object('namespace','provider_observation_entry','key',jsonb_build_object('namespace',namespace,'generation',generation,'identity',identity)::text,'version',content_hash) ref
      FROM public.provider_observation_entries WHERE namespace=dataset_row.namespace AND generation=dataset_row.generation AND observation ? 'spend' ORDER BY identity LIMIT 33) e;
    IF jsonb_array_length(refs)=0 THEN CONTINUE;END IF;
    datasets:=datasets||jsonb_build_array(jsonb_build_object('dataset_id',dataset_row.namespace||':'||dataset_row.generation,'coverage',dataset_row.coverage,
     'records_completeness',CASE WHEN records_complete THEN 'COMPLETE' ELSE 'PARTIAL' END,'record_metadata',record_metadata,
     'record_count',jsonb_array_length(refs),'material_refs',refs,'selection',jsonb_build_object('native_namespaces',jsonb_build_array(dataset_row.namespace),
      'native_keys',(SELECT jsonb_agg((value->>'key')::jsonb->>'identity') FROM jsonb_array_elements(refs)),'field_keys',jsonb_build_array('spend'),'partition_ref',NULL)));
   END LOOP;
  END IF;
  sources:=sources||jsonb_build_array(jsonb_build_object('source_id',source_row.id,'label',source_row.label,'family',source_row.family,'resource',source_row.declared_external_id,
   'manager_grant_id',manager_id::text,'read_grant_id',reader_id::text,'datasets',datasets,
   'datasets_completeness',CASE WHEN reader_id IS NULL THEN 'UNKNOWN' WHEN datasets_more THEN 'PARTIAL' ELSE 'COMPLETE' END));
 END LOOP;
 FOR license_row IN SELECT scope.*,c.descriptor,c.status boundary_status FROM public.data_use_scopes scope JOIN public.client_data_boundaries c ON c.boundary_id=scope.boundary_id
  WHERE c.boundary_id=ANY(boundary_ids) AND(c.descriptor->>'declarant_user_id'=actor::text OR EXISTS(
   SELECT 1 FROM public.security_action_grants own WHERE own.subject_user_id=actor AND own.data_use_scope_id=scope.scope_id
    AND own.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths))))
  ORDER BY scope.scope_id DESC LIMIT 32 LOOP
  licensed_grant:=NULL;licence_until:=(license_row.descriptor#>>'{declaration,valid_until}')::timestamptz;
  IF license_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text THEN
   IF jsonb_array_length(uses)=0 OR license_row.status<>'active' OR license_row.boundary_status<>'active' THEN CONTINUE;END IF;
   SELECT min(g.id) INTO licensed_grant FROM public.security_action_grants g
    JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
    WHERE g.scope_kind='data_use' AND g.data_use_scope_id=license_row.scope_id AND g.subject_user_id=actor
    AND g.status='active' AND g.revoked_at IS NULL AND g.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths))
    AND q.issuer_grant_id IN(SELECT(value->>'grant_id')::bigint FROM jsonb_array_elements(uses));
   IF licensed_grant IS NULL THEN CONTINUE;END IF;
   BEGIN
    selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',licensed_grant)));
    PERFORM builderad_private.require_scope('data_use',license_row.scope_id::text,actor,'data_use.'||license_row.operation,selection,p_session);
   EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
   licence_until:=least(licence_until,(SELECT expires_at FROM public.security_action_grants WHERE id=licensed_grant));
   IF NOT EXISTS(SELECT 1 FROM jsonb_array_elements(sources) src WHERE src->>'source_id'=license_row.source_id::text AND src->>'read_grant_id' IS NOT NULL) THEN
    summary_info:='{}';
    IF license_row.resource='business.expenditure_summary' THEN
     SELECT jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')) INTO summary_ref
      FROM public.business_expenditure_summaries WHERE output_scope_id=license_row.scope_id AND publication_manifest_id IS NOT NULL;
     BEGIN
      IF summary_ref IS NOT NULL AND EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=license_row.scope_id AND status='active')
       AND EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=license_row.boundary_id AND business_id=b AND branch_id IS NULL AND status='active') THEN
       PERFORM builderad_private.expenditure_summary_constraints(summary_ref,'display',license_row.purpose);
       summary_info:=jsonb_build_object('artifact_ref',summary_ref,'use_grant_id',licensed_grant::text);
      END IF;
     EXCEPTION WHEN insufficient_privilege THEN summary_info:='{}';END;
    END IF;
    assigned_licenses:=assigned_licenses||jsonb_build_array(jsonb_build_object('license_id',license_row.scope_id,
     'operation',license_row.operation,'purpose',license_row.purpose,'valid_until',licence_until)||summary_info);
    CONTINUE;
   END IF;
  ELSE
   SELECT min(id) INTO licensed_grant FROM public.security_action_grants WHERE scope_kind='data_use' AND data_use_scope_id=license_row.scope_id AND subject_user_id=actor;
  END IF;
  SELECT * INTO coverage_row FROM public.boundary_subjects WHERE boundary_id=license_row.boundary_id AND business_id=b AND branch_id IS NULL ORDER BY revision DESC LIMIT 1;
  SELECT * INTO binding_row FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=license_row.scope_id ORDER BY revision DESC LIMIT 1;
  SELECT coalesce(jsonb_agg(ref),'[]') INTO refs FROM jsonb_array_elements(sources) src CROSS JOIN LATERAL jsonb_array_elements(src->'datasets') ds
   CROSS JOIN LATERAL jsonb_array_elements(ds->'material_refs') ref WHERE src->>'source_id'=license_row.source_id::text
   AND license_row.selection->'native_namespaces' ? ((ref->>'key')::jsonb->>'namespace') AND license_row.selection->'native_keys' ? ((ref->>'key')::jsonb->>'identity');
  SELECT coalesce(jsonb_agg(metadata),'[]') INTO record_metadata FROM jsonb_array_elements(sources) src CROSS JOIN LATERAL jsonb_array_elements(src->'datasets') ds
   CROSS JOIN LATERAL jsonb_array_elements(ds->'record_metadata') metadata WHERE src->>'source_id'=license_row.source_id::text AND refs @> jsonb_build_array(metadata->'ref');
  summary_info:=NULL;
  IF license_row.resource='business.expenditure_summary' THEN
   record_metadata:='[]';
   SELECT jsonb_build_array(jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex'))),
    jsonb_build_object('state',CASE WHEN publication_manifest_id IS NULL THEN 'prepared' ELSE 'published' END,
     'dependencies',builderad_private.producer_dependencies(builderad_private.expenditure_summary_descriptor(jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')))),
     'input_license_id',input_reads#>'{0,data_use_scope_id}',
     'process_grant_id',(SELECT x->>'leaf_grant_id' FROM jsonb_array_elements(input_selection->'requirements') x WHERE x->>'requirement_kind'='data_use'))
   INTO refs,summary_info FROM public.business_expenditure_summaries WHERE output_scope_id=license_row.scope_id
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(sources) src WHERE src->>'source_id'=license_row.source_id::text AND src->>'read_grant_id' IS NOT NULL);
   refs:=coalesce(refs,'[]');
  END IF;
  licence_until:=least(licence_until,(SELECT expires_at FROM public.security_action_grants WHERE id=licensed_grant));
  licenses:=licenses||jsonb_build_array(jsonb_build_object('license_id',license_row.scope_id,'boundary_id',license_row.boundary_id,
   'boundary_grant_id',(SELECT min(id)::text FROM public.security_action_grants WHERE scope_kind='client_data' AND boundary_id=license_row.boundary_id AND subject_user_id=actor),
   'use_grant_id',licensed_grant::text,
   'source_id',license_row.source_id,'resource',license_row.resource,'operation',license_row.operation,'purpose',license_row.purpose,'valid_until',licence_until,
   'status',CASE WHEN license_row.boundary_status<>'active' THEN 'withdrawn' WHEN licence_until<=clock_timestamp() THEN 'expired' ELSE 'active' END,
   'selection',license_row.selection,'recipient_public_id',(SELECT public_id FROM public.users WHERE id=actor),'material_refs',refs,'record_metadata',record_metadata,'summary',summary_info,
   'is_declarant',license_row.descriptor->>'declarant_user_id'=actor::text,
   'covered',coalesce(coverage_row.status='active',false),'bound',coalesce(binding_row.status='active',false),
   'coverage_revision',coalesce(coverage_row.revision,0),'binding_revision',coalesce(binding_row.revision,0)));
 END LOOP;
 FOR beneficiary_row IN SELECT DISTINCT ON(child.subject_user_id) child.*,u.public_id,u.name
  FROM public.security_action_grants child JOIN public.security_action_grants parent ON parent.id=child.parent_grant_id
  JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=child.qualification_id
  JOIN public.principal_lifecycles l ON l.user_id=child.subject_user_id AND l.generation=child.subject_generation AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.users u ON u.id=child.subject_user_id AND u.email_verified_at IS NOT NULL
  WHERE child.business_id=b AND child.scope_kind='business' AND child.action='business.data.use' AND child.subject_user_id<>actor
  AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
  AND parent.subject_user_id=actor AND parent.id IN(SELECT(value->>'grant_id')::bigint FROM jsonb_array_elements(uses)) AND parent.delegable
  AND qualified.status='active' AND qualified.valid_from<=clock_timestamp() AND(qualified.valid_until IS NULL OR qualified.valid_until>clock_timestamp())
  AND child.delegation_realm_kind IS NOT DISTINCT FROM read_selection->>'realm_kind'
  ORDER BY child.subject_user_id,child.id DESC LIMIT 32 LOOP
  beneficiaries:=beneficiaries||jsonb_build_array(jsonb_build_object('public_id',beneficiary_row.public_id,'name',beneficiary_row.name,
   'realm_kind',beneficiary_row.delegation_realm_kind));
 END LOOP;
 SELECT coalesce(jsonb_agg(to_jsonb(a)),'[]') INTO assignments FROM(
  SELECT DISTINCT ON(g.data_use_scope_id,g.subject_user_id) g.data_use_scope_id license_id,u.public_id recipient_public_id,g.id::text grant_id,g.status,g.version revision,g.expires_at valid_until
  FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id JOIN public.client_data_boundaries boundary ON boundary.boundary_id=s.boundary_id
  JOIN public.users u ON u.id=g.subject_user_id
  WHERE q.policy_id='declared_data_beneficiary_v1' AND boundary.boundary_id=ANY(boundary_ids) AND boundary.descriptor->>'declarant_user_id'=actor::text
  ORDER BY g.data_use_scope_id,g.subject_user_id,g.id DESC LIMIT 64
 ) a;
 SELECT coalesce(max(g.id),0) INTO previous_grant FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE g.business_id=b AND g.subject_user_id=actor AND g.action='business.data.use' AND q.policy_id='business_operating_mandate_v1'
  AND g.delegation_realm_kind=read_selection->>'realm_kind';
 -- Requalification after the final metadata wait, without substituting a new grant.
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR row_value IN SELECT value FROM jsonb_array_elements(sources) LOOP
  FOREACH manager_id IN ARRAY ARRAY[(row_value->>'manager_grant_id')::bigint,(row_value->>'read_grant_id')::bigint] LOOP
   IF manager_id IS NOT NULL THEN
    selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',manager_id)));
    PERFORM builderad_private.require_native_source_grant(manager_id,actor,p_session,selection,false,false);
   END IF;
  END LOOP;
 END LOOP;
 FOR row_value IN SELECT value FROM jsonb_array_elements(uses||bindings) LOOP
  SELECT * INTO STRICT grant_row FROM public.security_action_grants WHERE id=(row_value->>'grant_id')::bigint;
  PERFORM builderad_private.require_scope('business',b::text,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),
  'sources',sources,'sources_more',sources_more,'licenses',licenses,'licenses_more',licenses_more,'business_use_options',uses,'binding_options',bindings,
  'beneficiaries',beneficiaries,'assignments',assignments,'assigned_licenses',assigned_licenses,
  'operating_mandate',jsonb_build_object('available',can_enroll AND jsonb_array_length(uses)=0,'previous_grant',previous_grant::text));
END $$;


ALTER FUNCTION public.builderad_v2_data_license_journey(p_session text, p_business uuid, p_access bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_expenditure_summary(p_session text, p_business uuid, p_command uuid, p_summary uuid, p_input jsonb, p_license uuid, p_read uuid, p_confirmed boolean, p_until timestamp with time zone, p_consent jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;input_selection jsonb;plan jsonb;proof jsonb;fences jsonb;
 payload bytea;known jsonb;result jsonb;artifact jsonb;authenticated timestamptz;manager_id bigint;
 input_scope public.data_use_scopes;s public.data_use_scopes;q public.authority_qualification_receipts;g public.security_action_grants;
 r public.business_expenditure_summaries;binding public.business_data_use_bindings;read_receipt public.business_command_receipts;
BEGIN
 IF p_command IS NULL OR p_summary IS NULL OR uuid_extract_version(p_summary) IS DISTINCT FROM 7 OR p_license IS NULL OR p_read IS NULL
 OR p_confirmed IS DISTINCT FROM true OR p_until IS NULL OR NOT isfinite(p_until) OR p_until<=clock_timestamp()
 OR p_consent IS DISTINCT FROM '{"derive":true,"deliver_summary":true,"retain_payload":false}'::jsonb
 THEN RAISE EXCEPTION 'Explicit finite derive and summary-delivery consent required; no retained payload' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO input_scope FROM public.data_use_scopes WHERE scope_id=p_license;
 IF input_scope.scope_id IS NULL OR input_scope.rights_domain<>'client' OR input_scope.operation<>'process'
 OR input_scope.selection->'field_keys' IS DISTINCT FROM '["spend"]'::jsonb OR p_input->>'namespace' IS DISTINCT FROM 'provider_observation_entry'
 THEN RAISE EXCEPTION 'Existing exact expenditure-processing licence required; display is not derivation' USING ERRCODE='42501'; END IF;
 IF jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 5 THEN RAISE EXCEPTION 'Exact independent use, binding, data-authority, process and Source paths required' USING ERRCODE='42501'; END IF;
 SELECT min(g1.id) INTO manager_id FROM public.security_action_grants g1 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text
 WHERE x->>'requirement_kind'='business' AND g1.action='business.link.manage';
 input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(x) FROM jsonb_array_elements(p_selection->'requirements') x
 WHERE x->>'requirement_kind'<>'client_data' AND x->>'leaf_grant_id' IS DISTINCT FROM manager_id::text));
 plan:=builderad_private.material_use_plan(actor,p_business,jsonb_build_array(p_input),'process',input_scope.purpose,input_selection);
 IF plan#>>'{items,0,scope,scope_id}' IS DISTINCT FROM p_license::text THEN RAISE EXCEPTION 'Exact input licence required' USING ERRCODE='42501'; END IF;
 -- This command mutates the boundary and Business invalidation epochs. Declare
 -- write intent before K4/effects; native Source custody remains a shared read.
 fences:=builderad_private.lock_material_resources(plan||jsonb_build_object('write_boundary_id',input_scope.boundary_id));
 b:=builderad_private.lock_business(p_business,true);
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 proof:=builderad_private.require_material_use(actor,b,p_session,'process',plan,input_selection);
 proof:=proof||jsonb_build_object('data_authority',builderad_private.require_scope('client_data',input_scope.boundary_id::text,actor,'client_data.manage',p_selection,p_session),
  'binding_authority',builderad_private.require_scope('business',b::text,actor,'business.link.manage',builderad_private.business_selection(manager_id),p_session),'dependencies',fences);
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit summary consent required' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.security_action_grants g1 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text
 WHERE g1.expires_at IS NOT NULL AND p_until>g1.expires_at)
 OR EXISTS(SELECT 1 FROM public.authority_qualification_receipts q1 JOIN public.security_action_grants g1 USING(qualification_id)
 JOIN jsonb_array_elements(p_selection->'requirements') x ON x->>'leaf_grant_id'=g1.id::text WHERE q1.valid_until IS NOT NULL AND p_until>q1.valid_until)
 THEN RAISE EXCEPTION 'Summary cannot outlast its independent authority' USING ERRCODE='42501'; END IF;
 SELECT * INTO read_receipt FROM public.business_command_receipts WHERE command_id=p_read;
 IF read_receipt.actor_user_id IS DISTINCT FROM actor OR read_receipt.business_id IS DISTINCT FROM b
 OR read_receipt.action IS DISTINCT FROM 'material.process' OR read_receipt.result_ref->>'purpose' IS DISTINCT FROM input_scope.purpose
 OR NOT(read_receipt.result_ref->'material_refs' @> jsonb_build_array(p_input))
 THEN RAISE EXCEPTION 'Existing exact authorized processing receipt required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'summary',p_summary,'input',p_input,'license',p_license,'read',p_read,'until',p_until,'consent',p_consent,'selection',p_selection),'{"business":"U","summary":"U","license":"U","read":"U","until":"T"}');
 known:=builderad_private.command_replay(b,actor,p_command,'expenditure_summary.issue',payload);
 IF known IS NOT NULL THEN
  PERFORM builderad_private.expenditure_summary_constraints(known->'artifact_ref','display',input_scope.purpose);RETURN known;
 END IF;
 IF EXISTS(SELECT 1 FROM public.business_expenditure_summaries WHERE summary_id=p_summary) THEN RAISE EXCEPTION 'Reconcile original summary command' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('summary_consent',p_consent,'summary_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',least(p_until,authenticated+interval '15 minutes')));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'expenditure_summary.issue',jsonb_build_object('business_id',b,'scope_id',p_summary),proof);
 s:=input_scope;s.scope_id:=p_summary;s.resource:='business.expenditure_summary';s.operation:='display';s.revision:=1;
 s.selection:=builderad_private.canonical_data_selection(jsonb_build_object('native_namespaces',jsonb_build_array('business.expenditure_summary'),'native_keys',jsonb_build_array(p_summary::text),'field_keys',jsonb_build_array('spend'),'partition_ref',NULL));
 s.scope_digest:=builderad_private.data_scope_digest(s.boundary_id,s.source_id,s.resource,s.operation,s.purpose,s.selection);
 PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
 q.qualification_id:=uuidv7();q.policy_id:='declared_data_license_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.subject_user_id:=actor;
 q.target_kind:='data_use';q.target_ref:=s.scope_id;q.allowed_actions:=ARRAY['data_use.display'];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
 q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;q.provenance:='Explicit finite same-Business expenditure derivation and summary delivery; no retained derived payload, original forwarding, Source or advertising authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:='data_use.display';g.scope_kind:='data_use';g.data_use_scope_id:=s.scope_id;
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
 g.reason:='Explicit finite expenditure summary';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
 g.qualification_id:=q.qualification_id;g.expires_at:=p_until;g.delegable:=false;g.delegation_realm_kind:=p_selection->>'realm_kind';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 binding.binding_id:=uuidv7();binding.business_id:=b;binding.data_use_scope_id:=s.scope_id;binding.status:='active';binding.revision:=1;binding.qualification_ref:=q.qualification_id;
 PERFORM builderad_private.permit('public.business_data_use_bindings','insert',NULL,to_jsonb(binding));INSERT INTO public.business_data_use_bindings SELECT(binding).*;
 r.summary_id:=p_summary;r.business_id:=b;r.processor_user_id:=actor;r.output_scope_id:=s.scope_id;r.purpose:=s.purpose;
 r.material_digest:=builderad_private.digest('command-payload:1',builderad_private.expenditure_summary_value(p_input));
 SELECT jsonb_build_array(jsonb_build_object('input',p_input,'read_command_id',p_read,'data_use_scope_id',p_license,
  'policy_id',value->'policy_id','policy_version',value->'policy_version')) INTO r.input_reads
 FROM jsonb_array_elements(read_receipt.result_ref->'material_uses') value WHERE value->'ref'=p_input AND value->>'data_use_scope_id'=p_license::text;
 IF r.input_reads IS NULL THEN RAISE EXCEPTION 'Exact processing licence receipt required' USING ERRCODE='42501'; END IF;
 r.input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(x) FROM jsonb_array_elements(p_selection->'requirements') x WHERE x->>'leaf_grant_id' IS DISTINCT FROM manager_id::text));
 r.valid_until:=p_until;r.consent:=p_consent;r.created_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.business_expenditure_summaries','insert',NULL,to_jsonb(r));INSERT INTO public.business_expenditure_summaries SELECT(r).*;
 -- Use the established scope/binding invalidation clocks. Replay returns above.
 PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
 PERFORM builderad_private.advance_business_epoch(b);
 artifact:=jsonb_build_object('namespace','business.expenditure_summary','key',p_summary::text,'version',encode(r.material_digest,'hex'));
 result:=jsonb_build_object('business_public_id',p_business,'artifact_ref',artifact,'dependencies',builderad_private.producer_dependencies(builderad_private.producer_descriptor(artifact)),
  'license_id',s.scope_id,'use_grant_id',g.id::text,'status','prepared','versions',jsonb_build_object('summary',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'expenditure_summary.issue',payload,result);
 PERFORM builderad_private.expenditure_summary_constraints(artifact,'display',s.purpose);
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',builderad_private.business_selection(manager_id),p_session);
 PERFORM builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session);
 IF authenticated+interval '15 minutes'<=clock_timestamp() THEN RAISE EXCEPTION 'Summary confirmation expired before release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_expenditure_summary(p_session text, p_business uuid, p_command uuid, p_summary uuid, p_input jsonb, p_license uuid, p_read uuid, p_confirmed boolean, p_until timestamp with time zone, p_consent jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_scoped_material_publish(p_session text, p_business uuid, p_command uuid, p_ref jsonb, p_dependencies jsonb, p_purpose text, p_recipient uuid, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;output_plan jsonb;input_plan jsonb;input_selection jsonb;refs jsonb;expected_edges jsonb;proposed jsonb;proof jsonb;footprint jsonb;
 fences jsonb;payload bytea;known jsonb;result jsonb;nodes jsonb;edges jsonb;root_key text;closure bytea;direct_hash bytea;
 m public.material_lineage_manifests;dep public.material_use_dependencies;edge jsonb;
BEGIN
 IF p_business IS NULL OR p_command IS NULL OR p_recipient IS NULL OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128
 OR p_dependencies IS NULL OR jsonb_typeof(p_dependencies) IS DISTINCT FROM 'array' OR jsonb_array_length(p_dependencies) NOT BETWEEN 1 AND 64
 THEN RAISE EXCEPTION 'Bounded explicit publication command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND public_id=p_recipient) THEN RAISE EXCEPTION 'Separate recipient publication is not inferred' USING ERRCODE='42501'; END IF;
 output_plan:=builderad_private.output_use_plan(actor,p_business,p_ref,p_purpose,p_selection);
 IF(output_plan#>>'{descriptor,processor_user_id}')::bigint<>actor THEN RAISE EXCEPTION 'Only the captured producer principal can publish this output' USING ERRCODE='42501'; END IF;
 expected_edges:=builderad_private.producer_dependencies(output_plan->'descriptor');
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO proposed FROM jsonb_array_elements(p_dependencies);
 IF proposed IS DISTINCT FROM expected_edges THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: proposed inputs differ from the complete producer capture' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg(value->'input') INTO refs FROM jsonb_array_elements(expected_edges);
 input_selection:=jsonb_set(p_selection,'{requirements}',(SELECT jsonb_agg(value) FROM jsonb_array_elements(p_selection->'requirements') WHERE value->>'leaf_grant_id'<>output_plan->>'grant_id'));
 input_plan:=builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection);
 fences:=builderad_private.lock_material_resources(input_plan,output_plan->'scope');
 b:=builderad_private.lock_business(p_business);
 footprint:=builderad_private.authority_footprint(actor,p_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND scope_kind='business' AND business_id IS DISTINCT FROM b)
 THEN RAISE EXCEPTION 'Cross-Business authority expansion denied' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 IF builderad_private.output_use_plan(actor,p_business,p_ref,p_purpose,p_selection) IS DISTINCT FROM output_plan
 OR builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection) IS DISTINCT FROM input_plan
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: publication inputs/rights changed' USING ERRCODE='40001'; END IF;
 proof:=builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);
 proof:=proof||jsonb_build_object('dependencies',fences,
 'captured_dependency_digest',input_plan#>'{closure,dependency_digest}');
 direct_hash:=builderad_private.lineage_digest('dependency-set:1',expected_edges);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'artifact',p_ref,'direct_dependency_digest',encode(direct_hash,'hex'),'purpose',p_purpose,'recipient',p_recipient,'selection',p_selection),'{"business":"U","recipient":"U","direct_dependency_digest":"X"}');
 known:=builderad_private.command_replay(b,actor,p_command,'material.publish',payload);
 IF known IS NOT NULL THEN
  PERFORM builderad_private.material_closure(jsonb_build_array(p_ref));
  PERFORM builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);RETURN known;
 END IF;
 IF EXISTS(SELECT 1 FROM public.material_lineage_manifests WHERE material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version')
 THEN RAISE EXCEPTION 'Artifact version already published; original command required' USING ERRCODE='40001'; END IF;
 root_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',p_ref),'hex');
 SELECT jsonb_agg(v ORDER BY k) INTO nodes FROM(
  SELECT encode(builderad_private.lineage_digest('lineage-manifest:1',value->'ref'),'hex') k,
   jsonb_build_object('ref',value->'ref','kind',value->'kind','material_digest',value->'material_digest','resolver_contract_version',value->'resolver_contract_version') v
  FROM jsonb_array_elements(input_plan#>'{closure,materials}')
  UNION ALL SELECT root_key,jsonb_build_object('ref',p_ref,'kind','derived','material_digest',p_ref->'version','resolver_contract_version',1)
 ) x;
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO edges FROM jsonb_array_elements((input_plan#>'{closure,dependencies}')||
  (SELECT jsonb_agg(jsonb_build_object('from',root_key,'to',encode(builderad_private.lineage_digest('lineage-manifest:1',value->'input'),'hex'),'edge',value)) FROM jsonb_array_elements(expected_edges)));
 closure:=builderad_private.lineage_digest('lineage-manifest:1',jsonb_build_object('materials',nodes,'dependencies',edges));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'material.publish',jsonb_build_object('business_id',b,'artifact',p_ref),proof);
 m.manifest_id:=uuidv7();m.material_namespace:=p_ref->>'namespace';m.material_key:=p_ref->>'key';m.material_version:=p_ref->>'version';m.kind:='derived';
 m.producer_receipt_ref:=output_plan#>>'{descriptor,producer_receipt_ref}';m.resolver_contract_version:=1;m.material_digest:=decode(p_ref->>'version','hex');
 m.direct_dependency_count:=jsonb_array_length(expected_edges);m.direct_dependency_digest:=direct_hash;m.closure_digest:=closure;m.state:='sealed';m.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.material_lineage_manifests','insert',NULL,to_jsonb(m));INSERT INTO public.material_lineage_manifests SELECT(m).*;
 FOR edge IN SELECT value FROM jsonb_array_elements(expected_edges) LOOP
  dep.dependency_id:=uuidv7();dep.material_namespace:=m.material_namespace;dep.material_key:=m.material_key;dep.material_version:=m.material_version;
  dep.input_namespace:=edge#>>'{input,namespace}';dep.input_key:=edge#>>'{input,key}';dep.input_version:=edge#>>'{input,version}';
  dep.data_use_scope_id:=(edge->>'data_use_scope_id')::uuid;dep.policy_id:=edge->>'policy_id';dep.policy_version:=(edge->>'policy_version')::bigint;dep.lineage_digest:=closure;
  PERFORM builderad_private.permit('public.material_use_dependencies','insert',NULL,to_jsonb(dep));INSERT INTO public.material_use_dependencies SELECT(dep).*;
 END LOOP;
 PERFORM builderad_private.registered_producer_publish(p_ref,m.manifest_id,closure);
 result:=jsonb_build_object('business_public_id',p_business,'artifact_ref',p_ref,'manifest_id',m.manifest_id,'closure_digest',encode(closure,'hex'),'versions',jsonb_build_object('manifest',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'material.publish',payload,result);
 IF(builderad_private.producer_descriptor(p_ref)-'publication_manifest_id') IS DISTINCT FROM((output_plan->'descriptor')-'publication_manifest_id')
 OR builderad_private.material_use_plan(actor,p_business,refs,'process',p_purpose,input_selection) IS DISTINCT FROM input_plan
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: producer capture changed before publication release' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.material_closure(jsonb_build_array(p_ref));
 PERFORM builderad_private.require_publish_use(actor,b,p_session,input_plan,input_selection,output_plan,p_selection);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_scoped_material_publish(p_session text, p_business uuid, p_command uuid, p_ref jsonb, p_dependencies jsonb, p_purpose text, p_recipient uuid, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_scoped_material_read(p_session text, p_business uuid, p_command uuid, p_refs jsonb, p_operation text, p_purpose text, p_recipient uuid, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;plan jsonb;footprint jsonb;proof jsonb;item jsonb;native_key jsonb;fences jsonb;
 fields jsonb;coverage jsonb;provenance jsonb;results jsonb:='[]';result jsonb;receipt jsonb;payload bytea;known jsonb;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_recipient IS NULL OR p_operation IS NULL OR p_operation NOT IN('retained_read','display','reuse','process','combine','export')
 OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128 OR octet_length(p_purpose)>512 THEN RAISE EXCEPTION 'Exact bounded material operation required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.users WHERE id=actor AND public_id=p_recipient) THEN RAISE EXCEPTION 'Separate recipient delivery policy unavailable; no forwarding implied' USING ERRCODE='42501'; END IF;
 plan:=builderad_private.material_use_plan(actor,p_business,p_refs,p_operation,p_purpose,p_selection);
 fences:=builderad_private.lock_material_resources(plan);
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_refs) x WHERE x->>'namespace' IN('market.knowledge','market.business_finding')) THEN
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
  THEN jsonb_set(value,'{validity}',builderad_private.market_validity(value->'ref')) WHEN value#>>'{ref,namespace}'='market.knowledge' THEN jsonb_set(value,'{fields,finding,validity}',builderad_private.knowledge_validity(value->'ref')) WHEN value#>>'{ref,namespace}'='market.business_finding' THEN jsonb_set(value,'{fields,finding,validity}',builderad_private.business_finding_validity(value->'ref')) ELSE value END ORDER BY ord)
 INTO results FROM jsonb_array_elements(results) WITH ORDINALITY x(value,ord);
 result:=jsonb_set(result,'{materials}',coalesce(results,'[]'::jsonb));
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_scoped_material_read(p_session text, p_business uuid, p_command uuid, p_refs jsonb, p_operation text, p_purpose text, p_recipient uuid, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.require_native_source_grant(p_grant bigint, p_actor bigint, p_session text, p_selection jsonb, p_owner boolean, p_fresh boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE g public.security_action_grants;selected_count integer;business_public uuid;
BEGIN
 IF session_user<>'builderad_app' OR p_actor IS NULL OR p_grant IS NULL OR p_session IS NULL OR p_owner IS NULL OR p_fresh IS NULL
 OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() WHERE actor_user_id=p_actor AND phase='principal')
 THEN RAISE EXCEPTION 'Clean signed native source actor required' USING ERRCODE='42501'; END IF;
 IF p_selection IS NULL OR jsonb_typeof(p_selection) IS DISTINCT FROM 'object' OR octet_length(p_selection::text)>65536
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_selection) k) IS DISTINCT FROM ARRAY['realm_kind','requirements']::text[]
 OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array'
 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct'
 THEN RAISE EXCEPTION 'Explicit processing and native source selection required' USING ERRCODE='42501'; END IF;
 SELECT count(*) INTO selected_count FROM jsonb_array_elements(p_selection->'requirements') x(v) WHERE v->>'requirement_kind'='native_source' AND v->>'leaf_grant_id'=p_grant::text;
 IF selected_count<>1 THEN RAISE EXCEPTION 'Exact native source grant selection required' USING ERRCODE='42501'; END IF;
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_grant AND subject_user_id=p_actor AND scope_kind='source';
 IF NOT FOUND OR g.business_id IS NULL THEN RAISE EXCEPTION 'Exact Business Source grant required' USING ERRCODE='42501'; END IF;
 SELECT b.public_id INTO business_public FROM public.source_registry s JOIN public.businesses b ON b.id=s.business_id
  WHERE s.id=g.source_target_id AND s.custody_kind='business_native' AND b.id=g.business_id;
 IF business_public IS NULL THEN RAISE EXCEPTION 'Exact Business Source custody required' USING ERRCODE='42501'; END IF;
 -- Source K1 precedes the Business fence and complete K4 union.
 PERFORM builderad_private.lock_business(business_public,false);
 PERFORM builderad_private.lock_authority_footprint(p_actor,p_selection,p_session);
 RETURN builderad_private.business_source_proof(p_grant,p_actor,p_session,p_fresh)||jsonb_build_object('realm',p_selection-'requirements');
END
$_$;


ALTER FUNCTION builderad_private.require_native_source_grant(p_grant bigint, p_actor bigint, p_session text, p_selection jsonb, p_owner boolean, p_fresh boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.source_fact_command(p_session text, p_command uuid, p_source uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
 WHERE c.phase='principal' AND own.id=selected AND own.scope_kind='source' AND own.source_target_id=p_source AND own.action='source.manage';
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
 result:=builderad_private.apply_native_source(actor,manager.subject_generation,manager.business_id,p_command,p_source,CASE WHEN p_effect='retire' THEN 'revoke' ELSE p_effect END,
  p_payload||jsonb_build_object('resource',manager.source_resource,'operation',manager.source_operation,'purpose',manager.source_purpose,'expected_version',p_expected));
 result:=jsonb_build_object('source_id',p_source,'status',result->>'registration_state','versions',jsonb_build_object('source',(result->>'version')::bigint),'native_receipt',result);
 PERFORM builderad_private.advance_right_epoch(f.fence_id);
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.'||p_effect,payload,result);
 PERFORM builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
 PERFORM builderad_private.finish_command();RETURN result;
EXCEPTION WHEN unique_violation THEN RAISE EXCEPTION 'Source fact version conflict' USING ERRCODE='40001';
END $_$;


ALTER FUNCTION builderad_private.source_fact_command(p_session text, p_command uuid, p_source uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.source_right_command(p_session text, p_command uuid, p_source uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE old_g public.security_action_grants;g public.security_action_grants;admission public.security_action_grants;selected_id bigint;selected_ref text;lock_selection jsonb;known_command boolean;actor bigint;fence public.resource_right_fences;proof jsonb;payload bytea;result jsonb;
BEGIN
 IF p_effect='qualify_issue' THEN RETURN builderad_private.source_grant_issue(p_session,p_command,p_source,p_payload,p_expected,p_selection); END IF;
 IF p_effect IN('retire','rename','declare_scope') THEN RETURN builderad_private.source_fact_command(p_session,p_command,p_source,p_effect,p_payload,p_expected,p_selection); END IF;
 IF p_effect IS DISTINCT FROM 'revoke' OR p_expected<1
 OR ((SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
  AND (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id','reason']::text[])
 OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$'
 OR (p_payload ? 'reason' AND (jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'))
 THEN RAISE EXCEPTION 'Exact adopted-source grant revoke required; root/adoption issuer not enabled here' USING ERRCODE='22023'; END IF;
 -- Minimal own-grant preflight precedes the K1 wait; it is not the authorization decision.
 SELECT * INTO old_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='source' AND source_target_id=p_source;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
  WHERE c.phase='principal' AND l.status='current' AND old_g.subject_user_id=l.user_id AND old_g.subject_generation=l.generation)
 THEN RAISE EXCEPTION 'Own exact native source grant required' USING ERRCODE='42501'; END IF;
 SELECT * INTO fence FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO old_g FROM public.security_action_grants WHERE id=old_g.id;
 IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Exact native selection required' USING ERRCODE='42501'; END IF;
 IF jsonb_array_length(p_selection->'requirements')<>1 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' THEN RAISE EXCEPTION 'Source-only command requires its exact native selection' USING ERRCODE='42501'; END IF;
 SELECT value->>'leaf_grant_id' INTO selected_ref FROM jsonb_array_elements(p_selection->'requirements') WHERE value->>'requirement_kind'='native_source';
 IF coalesce(selected_ref,'') !~ '^[1-9][0-9]{0,18}$' THEN RAISE EXCEPTION 'Typed native grant identity required' USING ERRCODE='42501'; END IF;
 IF selected_ref::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Native grant identity out of range' USING ERRCODE='42501'; END IF;
 selected_id:=selected_ref::bigint;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','source','target',p_source,'effect',p_effect,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) INTO known_command;
 lock_selection:=p_selection;
 IF NOT known_command AND selected_id<>old_g.id AND old_g.status='active' AND old_g.version=p_expected THEN
  -- Server-selected target metadata joins the K4 lock union before any selected grant is locked.
  -- It is not a replacement issuer path; the explicit manager still supplies the effect authority.
  lock_selection:=jsonb_set(p_selection,'{requirements}',(p_selection->'requirements')||jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',old_g.id)));
 END IF;
 proof:=builderad_private.require_native_source_grant(selected_id,actor,p_session,lock_selection,true,true);
 SELECT * INTO STRICT admission FROM public.security_action_grants WHERE id=selected_id;
 IF admission.source_target_id IS DISTINCT FROM old_g.source_target_id OR admission.business_id IS DISTINCT FROM old_g.business_id
 OR admission.source_resource IS DISTINCT FROM old_g.source_resource OR admission.source_operation IS DISTINCT FROM old_g.source_operation OR admission.source_purpose IS DISTINCT FROM old_g.source_purpose
 THEN RAISE EXCEPTION 'Exact native capability and source required' USING ERRCODE='42501'; END IF;
 SELECT * INTO old_g FROM public.security_action_grants WHERE id=old_g.id;
 IF old_g.source_target_id IS DISTINCT FROM p_source THEN RAISE EXCEPTION 'PREPARATION_CHANGED: native source target changed' USING ERRCODE='40001'; END IF;

 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.revoke' AND r.payload_digest=payload;
 IF FOUND THEN
  IF admission.id<>old_g.id AND admission.action NOT IN('source.read','source.manage','source.audit.recover') THEN RAISE EXCEPTION 'Current native receipt-read authority required' USING ERRCODE='42501'; END IF;
  RETURN result;
 END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 IF admission.id<>old_g.id AND admission.action<>'source.manage' THEN RAISE EXCEPTION 'Exact native manager or own surrender required' USING ERRCODE='42501'; END IF;
 IF old_g.version<>p_expected OR old_g.status<>'active' THEN RAISE EXCEPTION 'Native source grant changed' USING ERRCODE='40001'; END IF;
 -- Its own SHARE lock is already held and K1 excludes competing adopted writers. Conversion cannot interleave.
 PERFORM 1 FROM public.security_action_grants WHERE id=old_g.id FOR UPDATE;
 proof:=proof||jsonb_build_object('dependencies',jsonb_build_array(to_jsonb(fence)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.revoke',jsonb_build_object('source_id',p_source,'grant_id',old_g.id),proof);
 g:=old_g;g.status:='revoked';g.version:=old_g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_right_epoch(fence.fence_id);PERFORM builderad_private.principal(p_session);
 -- Surrender uses the locked before-image; the native responsible-owner/session contract still holds at effect.
  PERFORM builderad_private.require_business_source_creator(old_g.business_id,actor,p_session,
   builderad_private.business_selection((SELECT issuer_grant_id FROM public.authority_qualification_receipts WHERE qualification_id=old_g.qualification_id)),true);
 IF NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 THEN RAISE EXCEPTION 'Native source owner evidence expired before effect' USING ERRCODE='42501'; END IF;
 result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 IF admission.id<>old_g.id THEN PERFORM builderad_private.require_native_source_grant(admission.id,actor,p_session,p_selection,true,true); END IF;
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.revoke',payload,result);
 -- The receipt insert can wait after the mutation. Eligibility must still hold at this final local release boundary.
 -- Own surrender deliberately uses the locked, previously qualified before-image, never a resurrected revoked grant.
 PERFORM builderad_private.principal(p_session);
  PERFORM builderad_private.require_business_source_creator(old_g.business_id,actor,p_session,
   builderad_private.business_selection((SELECT issuer_grant_id FROM public.authority_qualification_receipts WHERE qualification_id=old_g.qualification_id)),true);
 IF (admission.expires_at IS NOT NULL AND admission.expires_at<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 THEN RAISE EXCEPTION 'Native source eligibility expired after receipt wait' USING ERRCODE='42501'; END IF;
 IF admission.id<>old_g.id THEN PERFORM builderad_private.require_native_source_grant(admission.id,actor,p_session,p_selection,true,true); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION builderad_private.source_right_command(p_session text, p_command uuid, p_source uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.source_grant_issue(p_session text, p_command uuid, p_source uuid, p_payload jsonb, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE actor bigint;selected_id bigint;proof jsonb;manager public.security_action_grants;g public.security_action_grants;
 f public.resource_right_fences;q public.authority_qualification_receipts;result jsonb;payload bytea;expiry timestamptz;reason text;enroll boolean;
BEGIN
 IF p_expected<>0 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['action','expires_at','reason']::text[]
 OR jsonb_typeof(p_payload->'action') IS DISTINCT FROM 'string' OR p_payload->>'action' NOT IN('source.register','source.read','source.manage','source.audit.recover','provider.connect','provider.read','provider.revoke')
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 OR (p_payload->'expires_at'<>'null'::jsonb AND jsonb_typeof(p_payload->'expires_at') IS DISTINCT FROM 'string')
 OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array'
 OR jsonb_array_length(p_selection->'requirements')<>1 OR p_selection#>>'{requirements,0,requirement_kind}' IS DISTINCT FROM 'native_source'
 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact native owner grant issuance required' USING ERRCODE='22023'; END IF;
 expiry:=(p_payload->>'expires_at')::timestamptz;reason:=p_payload->>'reason';
 selected_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c JOIN public.security_action_grants own ON own.subject_user_id=c.actor_user_id
  WHERE c.phase='principal' AND own.id=selected_id AND own.scope_kind='source' AND own.source_target_id=p_source
   AND (own.action='source.manage' OR (own.action='source.register' AND p_payload->>'action'='source.manage'));
 IF actor IS NULL THEN RAISE EXCEPTION 'Native manager preflight denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 SELECT * INTO STRICT manager FROM public.security_action_grants WHERE id=selected_id;
 enroll:=manager.action='source.register' AND p_payload->>'action'='source.manage';
 IF manager.source_target_id IS DISTINCT FROM p_source OR (manager.action<>'source.manage' AND NOT enroll)
 THEN RAISE EXCEPTION 'Exact current native source issuer required after admission wait' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.source_registry s JOIN public.source_capabilities c ON c.source_id=s.id
  WHERE s.id=p_source AND s.custody_kind='business_native' AND s.business_id=manager.business_id
   AND c.resource=manager.source_resource AND c.operation=manager.source_operation AND c.purpose=manager.source_purpose
   AND (NOT enroll OR (s.family='meta' AND s.namespace='facebook_login_configuration' AND s.declared_external_id=c.resource
    AND c.operation='discovery.read' AND c.purpose='advertising_analysis')))
 THEN RAISE EXCEPTION 'Current exact native capability required' USING ERRCODE='42501'; END IF;
 -- The selected grant is authority; its exact capability is part of the command's
 -- meaning. An existing receipt must not acknowledge a different capability.
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','source','target',p_source,'effect','qualify_issue','payload',p_payload,'expected',p_expected,
  'native_scope',jsonb_build_array(manager.source_resource,manager.source_operation,manager.source_purpose)),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.qualify_issue' AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.source_registry WHERE id=p_source AND status<>'REVOKED')
 THEN RAISE EXCEPTION 'A retired Source cannot issue rights' USING ERRCODE='42501'; END IF;
 IF enroll AND EXISTS(SELECT 1 FROM public.security_action_grants old_manager
  WHERE old_manager.scope_kind='source' AND old_manager.source_target_id=p_source AND old_manager.action='source.manage')
 THEN RAISE EXCEPTION 'A previous Source manager must not be replaced by enrollment' USING ERRCODE='42501'; END IF;
 IF expiry IS NOT NULL AND expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Future native grant expiry required' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE scope_kind='source' AND subject_user_id=actor AND subject_generation=manager.subject_generation
  AND business_id=manager.business_id AND source_target_id=p_source AND source_resource=manager.source_resource AND source_operation=manager.source_operation AND source_purpose=manager.source_purpose AND action=p_payload->>'action' AND status='active')
 THEN RAISE EXCEPTION 'Existing native right must be explicitly selected or revoked' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('dependencies',jsonb_build_array(to_jsonb(f)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.qualify_issue',jsonb_build_object('source_id',p_source),proof);
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=manager.subject_generation;g.granted_by:=actor;
 g.source:='source_owner_request';g.scope_kind:='source';g.source_target_id:=p_source;
 SELECT * INTO STRICT q FROM public.authority_qualification_receipts WHERE qualification_id=manager.qualification_id;
 IF (NOT enroll AND NOT (p_payload->>'action')=ANY(q.allowed_actions))
  OR(manager.expires_at IS NOT NULL AND(expiry IS NULL OR expiry>manager.expires_at))
 THEN RAISE EXCEPTION 'Source right cannot exceed its qualified issuer' USING ERRCODE='42501'; END IF;
 IF enroll THEN
  q.qualification_id:=uuidv7();q.allowed_actions:=ARRAY['source.manage'];
  q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.revision:=1;q.valid_from:=clock_timestamp();
  q.provenance:='Explicit original Business creator decision to manage this exact Meta login Source';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));
  INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 END IF;
 g.business_id:=manager.business_id;g.qualification_id:=q.qualification_id;
 g.source_resource:=manager.source_resource;g.source_operation:=manager.source_operation;g.source_purpose:=manager.source_purpose;g.action:=p_payload->>'action';
 g.reason:=reason;g.status:='active';g.version:=1;g.expires_at:=expiry;g.delegable:=false;g.runtime_lock_marker:=false;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 PERFORM builderad_private.advance_right_epoch(f.fence_id);
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 result:=jsonb_build_object('grant_id',g.id,'status','active','versions',jsonb_build_object('grant',1));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.qualify_issue',payload,result);
 PERFORM builderad_private.require_native_source_grant(selected_id,actor,p_session,p_selection,true,true);
 IF expiry IS NOT NULL AND expiry<=clock_timestamp() THEN RAISE EXCEPTION 'New native grant expired after final wait' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION builderad_private.source_grant_issue(p_session text, p_command uuid, p_source uuid, p_payload jsonb, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

-- Preserve accepted function privileges.
