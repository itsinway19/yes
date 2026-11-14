-- Business-native authority paths and ordered lock footprint.
-- Preserves the final accepted agency qualification and finite mandate policies.

CREATE FUNCTION builderad_private.authority_footprint(p_actor bigint, p_selection jsonb, p_extra_users bigint[] DEFAULT '{}'::bigint[]) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE req jsonb;g public.security_action_grants;basis_g public.security_action_grants;basis_id bigint;basis_path bigint[];follow_basis_chain boolean;cursor_id bigint;path bigint[];grant_ids bigint[]:='{}';user_ids bigint[]:=ARRAY[p_actor]||p_extra_users;
 agency_ids bigint[]:='{}';agency_q public.authority_qualification_receipts;eligibility public.security_action_grants;
 qualification_ids uuid[]:='{}';producer_basis record;producer_footprint jsonb;
BEGIN
 IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 32 THEN RAISE EXCEPTION 'Bounded selected authority set required' USING ERRCODE='42501'; END IF;
 FOR req IN SELECT value FROM jsonb_array_elements(p_selection->'requirements') LOOP
  IF coalesce(req->>'leaf_grant_id','') !~ '^[1-9][0-9]{0,18}$' OR(req->>'leaf_grant_id')::numeric>9223372036854775807
   OR coalesce(req->>'requirement_kind','') NOT IN('business','client_data','data_use','native_source') THEN RAISE EXCEPTION 'Typed selected authority set required' USING ERRCODE='42501'; END IF;
  cursor_id:=(req->>'leaf_grant_id')::bigint;path:='{}';
  WHILE cursor_id IS NOT NULL LOOP
   IF cursor_id=ANY(path) OR cardinality(path)>=16 THEN RAISE EXCEPTION 'Bounded acyclic authority set required' USING ERRCODE='42501'; END IF;
   SELECT * INTO g FROM public.security_action_grants WHERE id=cursor_id;
   IF NOT FOUND OR g.scope_kind<>(CASE WHEN req->>'requirement_kind'='native_source' THEN 'source' ELSE req->>'requirement_kind' END) OR(cardinality(path)=0 AND(g.subject_user_id<>p_actor OR g.status<>'active' OR g.revoked_at IS NOT NULL)) THEN RAISE EXCEPTION 'Own selected authority metadata required' USING ERRCODE='42501'; END IF;
   IF req->>'requirement_kind'='native_source' THEN
    IF g.parent_grant_id IS NOT NULL THEN RAISE EXCEPTION 'Native source provenance required' USING ERRCODE='42501'; END IF;
     IF g.business_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts q
      WHERE q.qualification_id=g.qualification_id AND q.policy_id='business_native_source_custody_v1'
      AND q.target_kind='source' AND q.target_ref=g.source_target_id AND q.issuer_grant_id IS NOT NULL)
     THEN RAISE EXCEPTION 'Explicit Business-native Source provenance required' USING ERRCODE='42501'; END IF;
   END IF;
   path:=array_append(path,g.id);grant_ids:=array_append(grant_ids,g.id);user_ids:=array_append(user_ids,g.subject_user_id);
   IF g.qualification_id IS NOT NULL THEN qualification_ids:=array_append(qualification_ids,g.qualification_id); END IF;
   SELECT * INTO agency_q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
   IF agency_q.agency_business_id IS NOT NULL THEN
    SELECT * INTO eligibility FROM public.security_action_grants WHERE id=agency_q.agency_membership_grant_id;
    IF eligibility.scope_kind IS DISTINCT FROM 'business' OR eligibility.business_id IS DISTINCT FROM agency_q.agency_business_id
     OR eligibility.subject_user_id IS DISTINCT FROM g.subject_user_id OR eligibility.action IS DISTINCT FROM 'business.context.read'
     OR EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=eligibility.qualification_id AND agency_business_id IS NOT NULL)
     OR NOT((eligibility.source='business_draft_creation' AND eligibility.parent_grant_id IS NULL)
      OR(eligibility.source='qualified_delegation' AND EXISTS(SELECT 1 FROM public.security_action_grants ep
       JOIN public.authority_qualification_receipts eq ON eq.qualification_id=ep.qualification_id
       WHERE ep.id=eligibility.parent_grant_id AND ep.parent_grant_id IS NULL AND eq.policy_id='business_declaration_team_admin_v1' AND eq.agency_business_id IS NULL)))
    THEN RAISE EXCEPTION 'Exact independent agency eligibility basis required' USING ERRCODE='42501'; END IF;
    agency_ids:=array_append(agency_ids,agency_q.agency_business_id);
    producer_footprint:=builderad_private.authority_footprint(g.subject_user_id,jsonb_build_object('realm_kind','direct',
     'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',eligibility.id))));
    IF producer_footprint->'agency_businesses'<>'[]'::jsonb THEN RAISE EXCEPTION 'Agency eligibility cannot be another client assignment' USING ERRCODE='42501'; END IF;
    grant_ids:=grant_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'grants'));
    user_ids:=user_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'users'));
    qualification_ids:=qualification_ids||ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(producer_footprint->'qualifications'));
   END IF;
   -- A fixed registered output keeps its exact original consent/authority basis
   -- in this same sorted lock union. Original inputs cannot be summary outputs.
   IF g.scope_kind='data_use' THEN
    FOR producer_basis IN SELECT processor_user_id,input_selection FROM public.business_expenditure_summaries WHERE output_scope_id=g.data_use_scope_id LOOP
     producer_footprint:=builderad_private.authority_footprint(producer_basis.processor_user_id,producer_basis.input_selection);
     grant_ids:=grant_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'grants'));
     user_ids:=user_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'users'));
     qualification_ids:=qualification_ids||ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(producer_footprint->'qualifications'));
    END LOOP;
   END IF;
   -- Freeze the issuer basis into the same sorted K4 footprint. An operating
   -- mandate can rely on an existing delegated client relationship.
   SELECT issuer_grant_id,policy_id='business_operating_mandate_v1' INTO basis_id,follow_basis_chain
    FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id
    AND policy_id IN('business_purpose_statement_v1','business_native_source_custody_v1','business_operating_mandate_v1','business_service_approval_v1');
   IF agency_q.policy_id='business_service_approval_v1' THEN
    IF basis_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.security_action_grants m JOIN public.authority_qualification_receipts q
      ON q.qualification_id=m.qualification_id WHERE m.id=basis_id AND m.business_id=g.business_id
      AND m.subject_user_id=g.subject_user_id AND m.action='business.data.use' AND q.policy_id='business_operating_mandate_v1') THEN
     RAISE EXCEPTION 'Exact issuer of service approval required' USING ERRCODE='42501';
    END IF;
    producer_footprint:=builderad_private.authority_footprint(g.subject_user_id,builderad_private.business_selection(basis_id));
    grant_ids:=grant_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'grants'));
    user_ids:=user_ids||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(producer_footprint->'users'));
    qualification_ids:=qualification_ids||ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(producer_footprint->'qualifications'));
   END IF;
   basis_path:='{}';
   WHILE basis_id IS NOT NULL LOOP
    IF basis_id=ANY(basis_path) OR cardinality(basis_path)>=16 THEN RAISE EXCEPTION 'Bounded acyclic issuer basis required' USING ERRCODE='42501'; END IF;
    SELECT * INTO STRICT basis_g FROM public.security_action_grants WHERE id=basis_id;
    grant_ids:=array_append(grant_ids,basis_g.id);user_ids:=array_append(user_ids,basis_g.subject_user_id);
    qualification_ids:=array_append(qualification_ids,basis_g.qualification_id);
    IF NOT follow_basis_chain THEN EXIT; END IF;
    basis_path:=array_append(basis_path,basis_id);basis_id:=basis_g.parent_grant_id;
   END LOOP;
   cursor_id:=g.parent_grant_id;
  END LOOP;
 END LOOP;
 RETURN jsonb_build_object('agency_cohorts',(SELECT coalesce(jsonb_agg(jsonb_build_object('cohort_id',c.cohort_id,'epoch',c.epoch,'mode',c.mode) ORDER BY c.cohort_id),'[]') FROM public.migration_cohort_control c JOIN public.migration_cohort_members m ON m.cohort_id=c.cohort_id WHERE m.business_id=ANY(agency_ids)),'agency_businesses',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(agency_ids) id) x),'users',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(user_ids) id) x),
  'grants',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(grant_ids) id) x),
  'qualifications',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(qualification_ids) id) x));
END $_$;


ALTER FUNCTION builderad_private.authority_footprint(p_actor bigint, p_selection jsonb, p_extra_users bigint[]) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_selection(p_grant bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('realm_kind','direct',
 'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',g.id)))
 FROM public.security_action_grants g WHERE g.id=p_grant
$$;


ALTER FUNCTION builderad_private.business_selection(p_grant bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.boundary_selection(p_grant bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('realm_kind','direct',
 'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','client_data','leaf_grant_id',g.id)))
 FROM public.security_action_grants g WHERE g.id=p_grant
$$;


ALTER FUNCTION builderad_private.boundary_selection(p_grant bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.business_agency_eligibility(p_agency bigint, p_subject bigint, p_membership bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE g public.security_action_grants;proof jsonb;
BEGIN
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_membership;
 IF NOT FOUND OR g.business_id IS DISTINCT FROM p_agency OR g.subject_user_id IS DISTINCT FROM p_subject
  OR g.scope_kind<>'business' OR g.action<>'business.context.read' OR g.delegation_realm_kind<>'direct'
  OR EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id AND agency_business_id IS NOT NULL)
  OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=p_agency AND lifecycle NOT IN('suspended','archived'))
  OR NOT EXISTS(SELECT 1 FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
   WHERE m.kind='business' AND m.business_id=p_agency AND c.mode='V2_ACTIVE')
 THEN RAISE EXCEPTION 'Current independent agency employee eligibility required' USING ERRCODE='42501'; END IF;
 -- The only admitted forms are original agency creator or its explicitly assigned
 -- direct employee. Incidental read access and another client mandate do not qualify.
 IF NOT((g.source='business_draft_creation' AND g.parent_grant_id IS NULL
   AND p_subject=(SELECT created_by FROM public.businesses WHERE id=p_agency))
  OR(g.source='qualified_delegation' AND NOT g.delegable AND EXISTS(
   SELECT 1 FROM public.security_action_grants parent JOIN public.authority_qualification_receipts q ON q.qualification_id=parent.qualification_id
   WHERE parent.id=g.parent_grant_id AND parent.business_id=p_agency AND parent.parent_grant_id IS NULL
    AND parent.action='business.context.read' AND parent.subject_user_id=(SELECT created_by FROM public.businesses WHERE id=p_agency)
    AND q.policy_id='business_declaration_team_admin_v1' AND q.agency_business_id IS NULL)))
 THEN RAISE EXCEPTION 'Exact Business team eligibility policy required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.verify_scope_path('business',p_agency::text,p_subject,'business.context.read',
  builderad_private.business_selection(p_membership),NULL,'{}',false);
 IF proof#>>'{qualification,policy_id}' NOT IN('self_declared_draft','business_declaration_team_admin_v1')
 THEN RAISE EXCEPTION 'Agency employee cannot inherit eligibility through a client' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('agency_control',(SELECT jsonb_build_object('cohort_id',c.cohort_id,'epoch',c.epoch,'mode',c.mode)
  FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id WHERE m.kind='business' AND m.business_id=p_agency));
END $$;


ALTER FUNCTION builderad_private.business_agency_eligibility(p_agency bigint, p_subject bigint, p_membership bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_agency_command(p_actor bigint, p_selection jsonb, p_session text, p_recipient bigint, p_membership bigint, p_agency bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE own_set jsonb;employee_set jsonb;combined jsonb:='{}';key_name text;
BEGIN
 own_set:=builderad_private.authority_footprint(p_actor,p_selection,ARRAY[p_recipient]);
 employee_set:=builderad_private.authority_footprint(p_recipient,builderad_private.business_selection(p_membership));
 FOREACH key_name IN ARRAY ARRAY['users','grants','qualifications','agency_businesses'] LOOP
  combined:=combined||jsonb_build_object(key_name,coalesce(own_set->key_name,'[]'::jsonb)||coalesce(employee_set->key_name,'[]'::jsonb));
 END LOOP;
 combined:=jsonb_set(combined,'{agency_businesses}',(combined->'agency_businesses')||jsonb_build_array(p_agency));
 PERFORM builderad_private.lock_authority_rows(p_actor,p_session,combined);
 IF own_set IS DISTINCT FROM builderad_private.authority_footprint(p_actor,p_selection,ARRAY[p_recipient])
  OR employee_set IS DISTINCT FROM builderad_private.authority_footprint(p_recipient,builderad_private.business_selection(p_membership))
 THEN RAISE EXCEPTION 'PREPARATION_CHANGED: agency eligibility changed' USING ERRCODE='40001'; END IF;
END $$;


ALTER FUNCTION builderad_private.lock_agency_command(p_actor bigint, p_selection jsonb, p_session text, p_recipient bigint, p_membership bigint, p_agency bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_authority_footprint(p_actor bigint, p_selection jsonb, p_session text, p_extra_users bigint[] DEFAULT '{}'::bigint[], p_extra_qualifications uuid[] DEFAULT '{}'::uuid[]) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE agency_ids bigint[];footprint jsonb;user_ids bigint[];grant_ids bigint[];qualification_ids uuid[];
BEGIN
 footprint:=builderad_private.authority_footprint(p_actor,p_selection,p_extra_users);
 PERFORM builderad_private.lock_authority_rows(p_actor,p_session,jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||to_jsonb(p_extra_qualifications)));
 IF builderad_private.authority_footprint(p_actor,p_selection,p_extra_users) IS DISTINCT FROM footprint THEN RAISE EXCEPTION 'PREPARATION_CHANGED: authority footprint changed' USING ERRCODE='40001'; END IF;
END $$;


ALTER FUNCTION builderad_private.lock_authority_footprint(p_actor bigint, p_selection jsonb, p_session text, p_extra_users bigint[], p_extra_qualifications uuid[]) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_authority_rows(p_actor bigint, p_session text, footprint jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE agency_ids bigint[];user_ids bigint[];grant_ids bigint[];qualification_ids uuid[];
BEGIN
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'users')) INTO user_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) INTO grant_ids;
 SELECT ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(footprint->'qualifications'))||'{}'::uuid[] INTO qualification_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')) INTO agency_ids;
 PERFORM 1 FROM public.migration_cohort_control c WHERE c.cohort_id IN(SELECT m.cohort_id FROM public.migration_cohort_members m WHERE m.business_id=ANY(agency_ids)) ORDER BY c.cohort_id FOR SHARE;
 PERFORM 1 FROM public.businesses WHERE id=ANY(agency_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=ANY(user_ids) ORDER BY user_id FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=ANY(user_ids) ORDER BY id FOR SHARE;
 IF p_session IS NULL THEN RAISE EXCEPTION 'Explicit current session required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session ORDER BY session_key_hash FOR SHARE;
 PERFORM 1 FROM public.security_action_grants WHERE id=ANY(grant_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qualification_ids) ORDER BY qualification_id FOR SHARE;

END $$;


ALTER FUNCTION builderad_private.lock_authority_rows(p_actor bigint, p_session text, footprint jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_business(p_public uuid, p_write boolean DEFAULT false) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint; c uuid; mode text;
BEGIN
 SELECT id INTO b FROM public.businesses WHERE public_id=p_public;
 IF b IS NULL OR session_user<>'builderad_app' OR NOT EXISTS (
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND g.scope_kind='business' AND g.business_id=b
   AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
 ) THEN RAISE EXCEPTION 'Business scope denied' USING ERRCODE='42501'; END IF;
 SELECT cohort_id INTO c FROM public.migration_cohort_members WHERE business_id=b AND kind='business';
 IF c IS NULL THEN RAISE EXCEPTION 'Business control required' USING ERRCODE='42501'; END IF;
 SELECT x.mode INTO mode FROM public.migration_cohort_control x WHERE cohort_id=c FOR SHARE;
 IF mode<>'V2_ACTIVE' THEN RAISE EXCEPTION 'Business scope unavailable' USING ERRCODE='42501'; END IF;
 IF p_write THEN PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR UPDATE;
 ELSE PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR SHARE; END IF;
 RETURN b;
END $$;


ALTER FUNCTION builderad_private.lock_business(p_public uuid, p_write boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_boundary(p_boundary uuid, p_write boolean) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE f uuid;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND g.scope_kind='client_data' AND g.boundary_id=p_boundary AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Boundary authority preflight denied' USING ERRCODE='42501'; END IF;
 IF p_write THEN SELECT fence_id INTO f FROM public.resource_right_fences WHERE boundary_id=p_boundary AND kind='client_boundary' AND adoption_state='v2_adopted' FOR UPDATE;
 ELSE SELECT fence_id INTO f FROM public.resource_right_fences WHERE boundary_id=p_boundary AND kind='client_boundary' AND adoption_state='v2_adopted' FOR SHARE; END IF;
 IF f IS NULL THEN RAISE EXCEPTION 'Qualified boundary gate required' USING ERRCODE='42501'; END IF;
 RETURN f;
END $$;


ALTER FUNCTION builderad_private.lock_boundary(p_boundary uuid, p_write boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.lock_data_scope(p_scope uuid) RETURNS public.data_use_scopes
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;gate record;count_gates integer:=0;
BEGIN
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND g.scope_kind='data_use' AND g.data_use_scope_id=p_scope AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
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


ALTER FUNCTION builderad_private.lock_data_scope(p_scope uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.verify_scope_path(p_kind text, p_target text, p_actor bigint, p_action text, p_selection jsonb, p_session text, p_extra_users bigint[], p_lock boolean) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE req jsonb; leaf bigint; next_id bigint; ids bigint[]:='{}';
 chain jsonb:='[]'; quals jsonb:='[]'; g public.security_action_grants; child public.security_action_grants;
 q public.authority_qualification_receipts; root_q public.authority_qualification_receipts; selected_count integer:=0;
 basis_proof jsonb; basis_selection jsonb;agency_proofs jsonb:='[]';eligibility_proof jsonb;
BEGIN
 IF p_selection IS NULL OR jsonb_typeof(p_selection)<>'object' OR octet_length(p_selection::text)>65536
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_selection) k) IS DISTINCT FROM ARRAY['realm_kind','requirements']::text[]
 OR jsonb_typeof(p_selection->'requirements')<>'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 32
 OR jsonb_typeof(p_selection->'realm_kind') IS DISTINCT FROM 'string' OR p_selection->>'realm_kind'<>'direct' THEN RAISE EXCEPTION 'Explicit complete authority selection required' USING ERRCODE='42501'; END IF;
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
  next_id:=g.parent_grant_id;
 END LOOP;
 IF p_lock THEN PERFORM builderad_private.lock_authority_footprint(p_actor,p_selection,p_session,p_extra_users); END IF;
 FOREACH next_id IN ARRAY ids LOOP
  SELECT * INTO g FROM public.security_action_grants WHERE id=next_id;
  IF g.status<>'active' OR g.revoked_at IS NOT NULL OR (g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp()) OR g.action<>p_action
  OR NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=g.subject_user_id AND l.generation=g.subject_generation AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
  THEN RAISE EXCEPTION 'Current complete authority chain required' USING ERRCODE='42501'; END IF;
  IF next_id=leaf AND(g.subject_user_id<>p_actor OR g.delegation_realm_kind IS DISTINCT FROM p_selection->>'realm_kind') THEN RAISE EXCEPTION 'Selected subject/action/realm denied' USING ERRCODE='42501'; END IF;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  IF NOT FOUND OR q.status<>'active' OR q.target_kind<>p_kind OR q.subject_user_id<>g.subject_user_id
  OR q.target_ref IS DISTINCT FROM (CASE p_kind WHEN 'business' THEN (SELECT public_id FROM public.businesses WHERE id=p_target::bigint) ELSE p_target::uuid END)
  OR NOT p_action=ANY(q.allowed_actions) OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
  THEN RAISE EXCEPTION 'Current qualified authority edge required' USING ERRCODE='42501'; END IF;
  IF q.agency_business_id IS NOT NULL THEN
   IF p_kind<>'business' OR q.policy_id NOT IN('business_agency_mandate_v1','business_agency_assignment_v1')
    OR q.policy_version<>1 OR q.issuer_kind<>'existing_grant' OR g.delegation_realm_kind<>'direct'
    OR g.action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
    OR(q.policy_id='business_agency_mandate_v1' AND NOT g.delegable)
    OR(q.policy_id='business_agency_assignment_v1' AND g.delegable)
    OR EXISTS(SELECT 1 FROM public.businesses WHERE id=q.agency_business_id AND lifecycle IN('suspended','archived'))
   THEN RAISE EXCEPTION 'Exact finite agency authority required' USING ERRCODE='42501'; END IF;
   eligibility_proof:=builderad_private.business_agency_eligibility(q.agency_business_id,g.subject_user_id,q.agency_membership_grant_id);
   agency_proofs:=agency_proofs||jsonb_build_array(eligibility_proof);
  ELSIF q.policy_id IN('business_agency_mandate_v1','business_agency_assignment_v1') THEN
   RAISE EXCEPTION 'Typed agency qualification required' USING ERRCODE='42501';
  END IF;
  IF child.id IS NOT NULL AND (NOT g.delegable OR child.granted_by<>g.subject_user_id OR child.parent_grant_id<>g.id
   OR(g.expires_at IS NOT NULL AND(child.expires_at IS NULL OR child.expires_at>g.expires_at))) THEN RAISE EXCEPTION 'Delegation exceeds its parent' USING ERRCODE='42501'; END IF;
  IF g.parent_grant_id IS NULL THEN
   root_q:=q;
   IF q.issuer_kind='existing_grant' OR g.source NOT IN('business_draft_creation','qualified_policy') THEN RAISE EXCEPTION 'Complete root qualification required' USING ERRCODE='42501'; END IF;
   IF q.issuer_kind='self_declared_draft' AND(p_kind<>'business' OR g.source<>'business_draft_creation' OR g.delegable OR g.delegation_realm_kind<>'direct'
    OR q.subject_user_id<>(SELECT created_by FROM public.businesses WHERE id=p_target::bigint)) THEN RAISE EXCEPTION 'Own declarations only' USING ERRCODE='42501'; END IF;
  ELSIF q.issuer_kind<>'existing_grant' OR q.issuer_grant_id IS DISTINCT FROM g.parent_grant_id OR g.source<>'qualified_delegation' THEN RAISE EXCEPTION 'Qualified parent edge required' USING ERRCODE='42501'; END IF;
  IF child.id IS NOT NULL THEN
   IF (SELECT agency_business_id FROM public.authority_qualification_receipts WHERE qualification_id=child.qualification_id) IS NOT NULL
    AND (SELECT policy_id FROM public.authority_qualification_receipts WHERE qualification_id=child.qualification_id)='business_agency_assignment_v1'
    AND(q.policy_id<>'business_agency_mandate_v1' OR q.agency_business_id IS DISTINCT FROM
     (SELECT agency_business_id FROM public.authority_qualification_receipts WHERE qualification_id=child.qualification_id))
   THEN RAISE EXCEPTION 'Employee assignment needs its exact client mandate' USING ERRCODE='42501'; END IF;
   IF q.agency_business_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts cq
     WHERE cq.qualification_id=child.qualification_id AND cq.policy_id='business_agency_assignment_v1' AND cq.agency_business_id=q.agency_business_id)
   THEN RAISE EXCEPTION 'Agency authority cannot escape its membership basis' USING ERRCODE='42501'; END IF;
  END IF;
  child:=g;chain:=chain||jsonb_build_array(to_jsonb(g));quals:=quals||jsonb_build_array(to_jsonb(q));
 END LOOP;
 IF root_q.policy_id='internal_client_file_admin_v1' THEN
  IF root_q.policy_version<>2 OR root_q.issuer_kind<>'qualified_policy' OR p_kind<>'business'
   OR root_q.evidence_ref IS DISTINCT FROM 'business-custody:'||root_q.target_ref::text
   OR root_q.allowed_actions<>ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage']::text[]
   OR p_selection->>'realm_kind'<>'direct' OR cardinality(ids)>2
   OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
   OR g.parent_grant_id IS NOT NULL OR g.source<>'qualified_policy' OR NOT g.delegable
   OR EXISTS(SELECT 1 FROM public.security_action_grants path WHERE path.id=ANY(ids)
    AND(path.delegation_realm_kind<>'direct' OR(path.parent_grant_id IS NOT NULL AND path.delegable)))
  THEN RAISE EXCEPTION 'Exact limited Business file custody required' USING ERRCODE='42501';END IF;
 END IF;
 IF root_q.policy_id='business_declaration_team_admin_v1' AND(root_q.policy_version<>1 OR p_kind<>'business'
  OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
  OR EXISTS(SELECT 1 FROM public.security_action_grants path WHERE path.id=ANY(ids) AND(path.delegation_realm_kind<>'direct')))
 THEN RAISE EXCEPTION 'Independent declaration team cannot acquire external or organizational authority' USING ERRCODE='42501'; END IF;
 IF root_q.policy_id='business_purpose_statement_v1' THEN
  IF root_q.policy_version<>1 OR root_q.issuer_kind<>'qualified_policy' OR root_q.allowed_actions<>ARRAY['business.purpose.declare']::text[]
   OR p_kind<>'business' OR p_action<>'business.purpose.declare' OR cardinality(ids)<>1
   OR g.delegable OR g.parent_grant_id IS NOT NULL OR g.source<>'qualified_policy' OR g.delegation_realm_kind<>'direct'
   OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=p_target::bigint AND created_by=p_actor AND origin_kind='self_declared_draft')
  THEN RAISE EXCEPTION 'Own Business Purpose statements cannot delegate or supply other authority' USING ERRCODE='42501'; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.security_action_grants basis JOIN public.authority_qualification_receipts original ON original.qualification_id=basis.qualification_id
   WHERE basis.id=root_q.issuer_grant_id AND basis.business_id=p_target::bigint AND basis.scope_kind='business' AND basis.action='business.context.read'
    AND basis.subject_user_id=p_actor AND basis.subject_generation=g.subject_generation AND basis.source='business_draft_creation' AND basis.parent_grant_id IS NULL
    AND NOT basis.delegable AND basis.delegation_realm_kind='direct'
    AND basis.status='active' AND basis.revoked_at IS NULL AND(basis.expires_at IS NULL OR basis.expires_at>clock_timestamp())
    AND original.policy_id='self_declared_draft' AND original.policy_version=1 AND original.issuer_kind='self_declared_draft'
    AND original.target_kind='business' AND original.target_ref=root_q.target_ref AND original.subject_user_id=p_actor AND original.status='active'
    AND original.valid_from<=clock_timestamp() AND(original.valid_until IS NULL OR original.valid_until>clock_timestamp()))
  THEN RAISE EXCEPTION 'Current original Purpose statement basis withdrawn' USING ERRCODE='42501'; END IF;
  quals:=quals||jsonb_build_array((SELECT to_jsonb(original) FROM public.authority_qualification_receipts original JOIN public.security_action_grants basis ON basis.qualification_id=original.qualification_id WHERE basis.id=root_q.issuer_grant_id));
 END IF;
 IF root_q.policy_id='business_operating_mandate_v1' THEN
  IF p_kind<>'business' OR p_action NOT IN('business.data.use','business.link.manage') OR root_q.policy_version<>1
   OR root_q.issuer_kind<>'qualified_policy' OR root_q.allowed_actions<>ARRAY['business.data.use','business.link.manage']::text[]
   OR g.delegation_realm_kind<>'direct' OR g.expires_at IS NULL OR root_q.valid_until IS DISTINCT FROM g.expires_at
  THEN RAISE EXCEPTION 'Exact operating mandate authority required' USING ERRCODE='42501'; END IF;
  basis_selection:=builderad_private.business_selection(root_q.issuer_grant_id);
  IF (basis_selection-'requirements') IS DISTINCT FROM (builderad_private.business_selection(g.id)-'requirements')
  THEN RAISE EXCEPTION 'Operating mandate basis realm changed' USING ERRCODE='42501'; END IF;
  basis_proof:=builderad_private.verify_scope_path('business',p_target,g.subject_user_id,'business.context.read',basis_selection,NULL,'{}',false);
  IF NOT coalesce(builderad_private.operating_mandate_basis(p_target::bigint,g.subject_user_id,basis_proof),false)
  THEN RAISE EXCEPTION 'Current operating mandate basis required' USING ERRCODE='42501'; END IF;
  quals:=quals||(basis_proof->'qualifications');
 END IF;
 IF p_action='business.service.approve' OR root_q.policy_id='business_service_approval_v1' THEN
  IF p_kind<>'business' OR p_action<>'business.service.approve' OR root_q.policy_id<>'business_service_approval_v1'
   OR root_q.policy_version<>1 OR root_q.issuer_kind<>'qualified_policy' OR cardinality(ids)<>1 OR g.delegable
   OR root_q.allowed_actions IS DISTINCT FROM ARRAY['business.service.approve']::text[]
   OR g.expires_at IS NULL OR root_q.valid_until IS DISTINCT FROM g.expires_at THEN
   RAISE EXCEPTION 'Independent service approval qualification required' USING ERRCODE='42501';
  END IF;
  basis_proof:=builderad_private.service_mandate(p_target::bigint,p_actor,root_q.issuer_grant_id);
  quals:=quals||(basis_proof->'qualifications');
 END IF;
 RETURN jsonb_build_object('codec_version',1,'agency_eligibility',agency_proofs,'actor',p_actor,'generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=p_actor),'action',p_action,'realm',p_selection-'requirements','grants',chain,'qualifications',quals,'qualification',to_jsonb(root_q),'epochs',CASE WHEN p_kind='business' THEN (SELECT jsonb_build_object('business_id',p_target::bigint,'auth_epoch',f.auth_epoch,'cohort_id',c.cohort_id,'cohort_epoch',c.epoch) FROM public.business_authority_fences f JOIN public.migration_cohort_members m ON m.business_id=f.business_id JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id WHERE f.business_id=p_target::bigint) ELSE (SELECT jsonb_agg(to_jsonb(f) ORDER BY f.fence_id) FROM public.resource_right_fences f WHERE f.boundary_id=CASE p_kind WHEN 'client_data' THEN p_target::uuid ELSE (SELECT boundary_id FROM public.data_use_scopes WHERE scope_id=p_target::uuid) END OR f.source_id=(SELECT source_id FROM public.data_use_scopes WHERE p_kind='data_use' AND scope_id=p_target::uuid) OR(f.kind='data_use' AND p_kind='data_use' AND f.data_use_scope_id=p_target::uuid)) END);
END $_$;


ALTER FUNCTION builderad_private.verify_scope_path(p_kind text, p_target text, p_actor bigint, p_action text, p_selection jsonb, p_session text, p_extra_users bigint[], p_lock boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.require_scope(p_kind text, p_target text, p_actor bigint, p_action text, p_selection jsonb, p_session text, p_extra_users bigint[] DEFAULT '{}'::bigint[]) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF session_user<>'builderad_app' OR p_kind IS NULL OR p_kind NOT IN('business','client_data','data_use') OR p_target IS NULL OR p_action IS NULL OR p_actor IS NULL OR NOT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c WHERE c.actor_user_id=p_actor AND c.phase='principal')
 THEN RAISE EXCEPTION 'Signed scoped actor required' USING ERRCODE='42501'; END IF;
 RETURN builderad_private.verify_scope_path(p_kind,p_target,p_actor,p_action,p_selection,p_session,p_extra_users,true);
END $$;


ALTER FUNCTION builderad_private.require_scope(p_kind text, p_target text, p_actor bigint, p_action text, p_selection jsonb, p_session text, p_extra_users bigint[]) OWNER TO builderad_scope_executor;
