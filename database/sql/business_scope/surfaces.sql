-- Web and other clients discover the same qualified resources. Candidate metadata is never a read grant.
CREATE FUNCTION builderad_private.business_selection(p_grant bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('realm_kind',g.delegation_realm_kind,'realm_workspace_public_id',w.public_id,
 'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',g.id)))
 FROM public.security_action_grants g LEFT JOIN public.workspaces w ON w.id=g.delegation_workspace_id WHERE g.id=p_grant
$$;

CREATE FUNCTION builderad_v2_business_catalog(p_session text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; result jsonb;
BEGIN
 actor:=builderad_private.principal(p_session);
 SELECT jsonb_build_object('business_types',coalesce(jsonb_agg(jsonb_build_object('id',id,'key',key,'name_ar',name_ar,'name_en',name_en,'active',active,'version',version) ORDER BY id),'[]'::jsonb)) INTO result FROM public.business_types;
 SELECT result||jsonb_build_object('countries',coalesce(jsonb_agg(jsonb_build_object('code',code,'name_ar',name_ar,'name_en',name_en) ORDER BY code COLLATE "C"),'[]'::jsonb)) INTO result FROM public.country_references;
 -- One native catalog scan; the existing pure policy remains the writer's policy too.
 WITH catalog AS MATERIALIZED(SELECT name FROM pg_timezone_names)
 SELECT result||jsonb_build_object('intent_timezones',coalesce(jsonb_agg(name ORDER BY name COLLATE "C"),'[]'::jsonb)) INTO result
 FROM(SELECT DISTINCT name FROM catalog WHERE public.builderad_current_intent_timezone_name_allowed(name)) accepted;
 PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;

CREATE FUNCTION builderad_private.boundary_selection(p_grant bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('realm_kind',g.delegation_realm_kind,'realm_workspace_public_id',w.public_id,
 'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','client_data','leaf_grant_id',g.id)))
 FROM public.security_action_grants g LEFT JOIN public.workspaces w ON w.id=g.delegation_workspace_id WHERE g.id=p_grant
$$;

CREATE FUNCTION builderad_v2_boundary_choices(p_session text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;g record;row_value jsonb;proof jsonb;items jsonb:='[]';requirements jsonb:='[]';ids bigint[]:='{}';boundaries uuid[]:='{}';scanned bigint:=p_after;next_cursor bigint;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded explicit cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 FOR g IN SELECT x.* FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
 WHERE x.subject_user_id=actor AND x.scope_kind='client_data' AND x.action='client_data.manage' AND x.id>p_after
 AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp()) ORDER BY x.id LIMIT 32 LOOP
  scanned:=g.id;
  BEGIN
   PERFORM builderad_private.authority_footprint(actor,builderad_private.boundary_selection(g.id));
   ids:=array_append(ids,g.id);boundaries:=array_append(boundaries,g.boundary_id);
   requirements:=requirements||(builderad_private.boundary_selection(g.id)->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 -- Every material-right fence precedes the complete K4 authority union.
 PERFORM 1 FROM public.resource_right_fences WHERE boundary_id=ANY(boundaries) AND kind='client_boundary' AND adoption_state='v2_adopted' ORDER BY fence_id FOR SHARE;
 IF cardinality(ids)>0 THEN PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);END IF;
 FOR g IN SELECT * FROM public.security_action_grants WHERE id=ANY(ids) ORDER BY id LOOP
  BEGIN
   IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE boundary_id=g.boundary_id AND kind='client_boundary' AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'Adopted boundary required' USING ERRCODE='42501';END IF;
   proof:=builderad_private.require_scope('client_data',g.boundary_id::text,actor,'client_data.manage',builderad_private.boundary_selection(g.id),p_session);
   SELECT jsonb_build_object('boundary_id',b.boundary_id,'state',b.status,'read_grant_id',g.id::text,
    'realm_kind',g.delegation_realm_kind,'realm_workspace_public_id',(builderad_private.boundary_selection(g.id)->'realm_workspace_public_id')) INTO row_value
   FROM public.client_data_boundaries b WHERE boundary_id=g.boundary_id;
   IF row_value IS NOT NULL THEN items:=items||jsonb_build_array(row_value);END IF;
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
 END LOOP;
 FOR row_value IN SELECT value FROM jsonb_array_elements(items) LOOP
  PERFORM builderad_private.require_scope('client_data',row_value->>'boundary_id',actor,'client_data.manage',builderad_private.boundary_selection((row_value->>'read_grant_id')::bigint),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
  WHERE x.subject_user_id=actor AND x.scope_kind='client_data' AND x.action='client_data.manage' AND x.id>scanned AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp())) THEN next_cursor:=scanned;END IF;
 RETURN jsonb_build_object('boundaries',items,'next_cursor',next_cursor::text);
END $$;

CREATE FUNCTION builderad_v2_boundary_surface(p_session text,p_boundary uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;selection jsonb;proof jsonb;result jsonb;rows_value jsonb;has_more boolean;
BEGIN
 PERFORM builderad_private.lock_boundary(p_boundary,false);actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND scope_kind='client_data' AND boundary_id=p_boundary AND subject_user_id=actor AND action='client_data.manage')
 THEN RAISE EXCEPTION 'Explicit own boundary management path required' USING ERRCODE='42501';END IF;
 selection:=builderad_private.boundary_selection(p_read);
 proof:=builderad_private.require_scope('client_data',p_boundary::text,actor,'client_data.manage',selection,p_session);
 -- Exact IDs are administrative references. Business names/profile facts remain under Business reading.
 WITH latest AS MATERIALIZED(SELECT DISTINCT ON(business_id,branch_id) * FROM public.boundary_subjects WHERE boundary_id=p_boundary ORDER BY business_id,branch_id,revision DESC,membership_id DESC),
 page AS MATERIALIZED(SELECT * FROM latest ORDER BY membership_id DESC LIMIT 65)
 SELECT coalesce(jsonb_agg(jsonb_build_object('membership_id',x.membership_id,'business_public_id',b.public_id,'branch_public_id',br.public_id,
  'state',x.status,'revision',x.revision) ORDER BY x.membership_id DESC) FILTER(WHERE x.position<=64),'[]'::jsonb),count(*)>64 INTO rows_value,has_more
 FROM(SELECT *,row_number() OVER(ORDER BY membership_id DESC) position FROM page) x
 JOIN public.businesses b ON b.id=x.business_id LEFT JOIN public.business_branches br ON br.id=x.branch_id AND br.business_id=x.business_id;
 SELECT jsonb_build_object('boundary',jsonb_build_object('boundary_id',b.boundary_id,'state',b.status,'revision',b.revision),
  'authority_selection',selection,'memberships',rows_value,'memberships_limit',64,'memberships_more',has_more) INTO result FROM public.client_data_boundaries b WHERE boundary_id=p_boundary;
 PERFORM builderad_private.require_scope('client_data',p_boundary::text,actor,'client_data.manage',selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;

-- Freeze every K2/K3 resource before the ordered union of K4 authority rows.
-- A page scans at most 32 own leaf references; an empty page with a cursor is not a claim of no activities.
CREATE FUNCTION builderad_v2_business_choices(p_session text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; g record; b record; c uuid; selection jsonb; footprint jsonb; proof jsonb; row_value jsonb;
 candidates bigint[]:='{}'; business_ids bigint[]:='{}'; requirements jsonb:='[]'; items jsonb:='[]'; proofs jsonb:='[]'; scanned bigint:=p_after; next_cursor bigint; mode text;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded explicit cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 FOR g IN SELECT x.* FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
 WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.action='business.context.read' AND x.status='active' AND x.revoked_at IS NULL
 AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp()) AND x.id>p_after ORDER BY x.id LIMIT 32 LOOP
  scanned:=g.id; selection:=builderad_private.business_selection(g.id);
  BEGIN
   footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM g.business_id) THEN
    RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501';
   END IF;
   candidates:=array_append(candidates,g.id);business_ids:=array_append(business_ids,g.business_id);
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOR c IN SELECT DISTINCT cohort_id FROM public.migration_cohort_members WHERE business_id=ANY(business_ids) AND kind='business' ORDER BY cohort_id LOOP
  PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=c FOR SHARE;
 END LOOP;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=ANY(business_ids) ORDER BY business_id FOR SHARE;
 IF cardinality(candidates)>0 THEN
  PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);
 END IF;
 FOR g IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidates) ORDER BY id LOOP
  BEGIN
   SELECT x.* INTO STRICT b FROM public.businesses x WHERE id=g.business_id;
   SELECT x.mode INTO mode FROM public.migration_cohort_control x JOIN public.migration_cohort_members m ON m.cohort_id=x.cohort_id WHERE m.business_id=b.id AND m.kind='business';
   IF mode IS DISTINCT FROM 'V2_ACTIVE' THEN RAISE EXCEPTION 'Business not active in this foundation' USING ERRCODE='42501'; END IF;
   selection:=builderad_private.business_selection(g.id);
   proof:=builderad_private.require_business(b.id,actor,'business.context.read',selection,p_session);
   SELECT jsonb_build_object('business_public_id',b.public_id,'name',h.display_name,'state',b.lifecycle,'read_grant_id',g.id::text,
    'realm_kind',selection->'realm_kind','realm_workspace_public_id',selection->'realm_workspace_public_id') INTO row_value FROM public.business_context_heads h WHERE h.business_id=b.id;
   items:=items||jsonb_build_array(row_value);proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 -- Expiry is checked again after the last potentially waiting authority read, without substituting another path.
 FOR row_value IN SELECT value FROM jsonb_array_elements(items) LOOP
  PERFORM builderad_private.require_business((SELECT id FROM public.businesses WHERE public_id=(row_value->>'business_public_id')::uuid),actor,'business.context.read',builderad_private.business_selection((row_value->>'read_grant_id')::bigint),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.action='business.context.read' AND x.id>scanned AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp())) THEN next_cursor:=scanned; END IF;
 RETURN jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'items',items,'next_cursor',next_cursor::text,'scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('items',items,'proofs',proofs)::text,'UTF8')),'hex')));
END $$;

CREATE FUNCTION builderad_v2_business_surface(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; b bigint; read_selection jsonb; selection jsonb; proof jsonb; read_proof jsonb; options jsonb:='[]'; proofs jsonb:='[]'; requirements jsonb;
 g record; footprint jsonb; result jsonb;fact_options jsonb:='{"markets":[],"intent":[],"preferences":[],"purpose":[]}';fact_key text;write_action text;
 workspace_ids bigint[]:='{}';workspace_candidates bigint[]:='{}';link_options jsonb:='[]';workspace_choices jsonb:='[]';relationships jsonb:='[]';
 delegation_options jsonb:='[]';issued_grants jsonb:='[]';expiry timestamptz;parent_row public.security_action_grants;more_grants boolean:=false;
 bindings jsonb:='[]';bindings_more boolean:=false;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 read_selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Explicit own read path required' USING ERRCODE='42501'; END IF;
 requirements:=read_selection->'requirements';
 FOR g IN SELECT * FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND id<>p_read AND scope_kind='business' AND status='active' AND revoked_at IS NULL
 AND delegation_realm_kind=read_selection->>'realm_kind' AND delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid) ORDER BY id LIMIT 31 LOOP
  BEGIN
   selection:=builderad_private.business_selection(g.id);footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 footprint:=builderad_private.authority_footprint(actor,read_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
 -- Discover only own current native workspace-view candidates. No raw Workspace profile is released.
 -- Freeze their entire existing authority footprint together with the Business paths, before K4.
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(requirements) r JOIN public.security_action_grants candidate ON candidate.id=(r->>'leaf_grant_id')::bigint WHERE candidate.action='business.link.manage') THEN
  SELECT coalesce(array_agg(id ORDER BY id),'{}'::bigint[]) INTO workspace_candidates FROM(
   SELECT DISTINCT g0.workspace_id id FROM public.security_action_grants g0 JOIN public.principal_lifecycles l ON l.user_id=g0.subject_user_id AND l.generation=g0.subject_generation
   JOIN public.workspace_ownerships o ON o.workspace_id=g0.workspace_id AND o.responsible_owner_user_id=actor
   WHERE g0.subject_user_id=actor AND g0.scope_kind='workspace' AND g0.action='workspace.view' AND g0.status='active' AND g0.revoked_at IS NULL
    AND(g0.expires_at IS NULL OR g0.expires_at>clock_timestamp()) AND builderad_private.workspace_allowed(g0.workspace_id,actor,true) ORDER BY g0.workspace_id LIMIT 33
  ) qualified_candidates;
  workspace_ids:=workspace_candidates[1:32];
 END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session,'{}',workspace_ids);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  BEGIN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   proof:=builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
   IF write_action='business.context.manage' THEN options:=options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.link.manage' THEN
    link_options:=link_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text,'allows_agency',proof#>>'{qualification,issuer_kind}'<>'self_declared_draft'));
   ELSIF write_action IN('business.markets.declare','business.intent.declare','business.preferences.manage','business.purpose.declare') THEN
    fact_key:=CASE write_action WHEN 'business.markets.declare' THEN 'markets' WHEN 'business.intent.declare' THEN 'intent' WHEN 'business.preferences.manage' THEN 'preferences' WHEN 'business.purpose.declare' THEN 'purpose' END;
    fact_options:=jsonb_set(fact_options,ARRAY[fact_key],(fact_options->fact_key)||jsonb_build_array(jsonb_build_object('grant_id',g.id::text)));
   END IF;
   SELECT * INTO STRICT parent_row FROM public.security_action_grants WHERE id=g.id;
   -- Only the holder's currently qualified paths and their immediate issued children.
   -- This is not a user directory or a grant to read another customer's delegation graph.
   IF parent_row.delegable OR EXISTS(SELECT 1 FROM public.security_action_grants child WHERE child.parent_grant_id=g.id AND child.business_id=b) THEN
    SELECT min(value::timestamptz) INTO expiry FROM(
     SELECT x->>'expires_at' value FROM jsonb_array_elements(proof->'grants') x
     UNION ALL SELECT x->>'valid_until' FROM jsonb_array_elements(proof->'qualifications') x
    ) limits WHERE value IS NOT NULL;
    delegation_options:=delegation_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text,'action',write_action,
     'can_issue',parent_row.delegable AND jsonb_array_length(proof->'grants')<16,
     'can_delegate_onward',proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1'),
     'can_choose_agency',read_selection->>'realm_kind'='direct' AND proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1'),
     'max_expires_at',expiry,'chain_depth',jsonb_array_length(proof->'grants')));
   END IF;
   proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 SELECT count(*)>64 INTO more_grants FROM(SELECT 1 FROM public.security_action_grants child
 WHERE child.scope_kind='business' AND child.business_id=b AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',child.id::text,'parent_grant_id',child.parent_grant_id::text,'recipient_public_id',u.public_id,
  'action',child.action,'recorded_status',child.status,'revision',child.version,'expires_at',child.expires_at,'delegable',child.delegable,
  'realm_kind',child.delegation_realm_kind,'realm_workspace_public_id',w.public_id) ORDER BY child.id DESC),'[]'::jsonb) INTO issued_grants
 FROM(SELECT * FROM public.security_action_grants child WHERE child.scope_kind='business' AND child.business_id=b
  AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) ORDER BY child.id DESC LIMIT 64) child
 JOIN public.users u ON u.id=child.subject_user_id LEFT JOIN public.workspaces w ON w.id=child.delegation_workspace_id;
 IF jsonb_array_length(link_options)>0 THEN
  WITH latest AS MATERIALIZED(SELECT DISTINCT ON(data_use_scope_id) * FROM public.business_data_use_bindings WHERE business_id=b ORDER BY data_use_scope_id,revision DESC,binding_id DESC),
  bounded AS MATERIALIZED(SELECT *,row_number() OVER(ORDER BY binding_id DESC) position FROM latest ORDER BY binding_id DESC LIMIT 65)
  SELECT coalesce(jsonb_agg(jsonb_build_object('binding_id',binding_id,'scope_id',data_use_scope_id,'state',status,'revision',revision) ORDER BY binding_id DESC) FILTER(WHERE position<=64),'[]'),count(*)>64
   INTO bindings,bindings_more FROM bounded;
  -- The legacy name can itself be an unqualified old Business fact. Only the independently
  -- owned collaboration identifier is released; never relabel that old name as safe metadata.
  SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',w.public_id,'slug',w.slug) ORDER BY w.id),'[]'::jsonb)
   INTO workspace_choices FROM public.workspaces w WHERE w.id=ANY(workspace_ids) AND builderad_private.workspace_allowed(w.id,actor,true);
  SELECT coalesce(jsonb_agg(jsonb_build_object('link_ref',x.link_id,'workspace_public_id',w.public_id,'workspace_slug',w.slug,'kind',x.kind,'state',x.status,'revision',x.revision) ORDER BY x.link_id),'[]'::jsonb)
   INTO relationships FROM(SELECT DISTINCT ON(workspace_id,kind) * FROM public.business_workspace_links WHERE business_id=b AND workspace_id=ANY(workspace_ids) ORDER BY workspace_id,kind,revision DESC,link_id DESC) x
   JOIN public.workspaces w ON w.id=x.workspace_id WHERE w.id=ANY(workspace_ids) AND builderad_private.workspace_allowed(w.id,actor,true);
 END IF;
 result:=jsonb_build_object('business',public.builderad_v2_business_read(p_session,p_business,read_selection),'authority_selection',read_selection,
 'private_history_available',read_selection->>'realm_kind'<>'internal_team' AND read_proof#>>'{qualification,policy_id}'<>'business_declaration_team_admin_v1',
 'direct_team_enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1' AND target_ref=p_business AND target_kind='business' AND subject_user_id=actor AND status='active'),
 'purpose_statement',jsonb_build_object('authority_scope','own_business_statement_only','establishes_external_authority',false,
  'enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b),
  'previous_grant',coalesce((SELECT max(x.id)::text FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'),'0'),
  'current_grant',(SELECT jsonb_build_object('id',x.id::text,'version',x.version) FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
   WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(fact_options->'purpose') admitted WHERE admitted->>'grant_id'=x.id::text) ORDER BY x.id DESC LIMIT 1)),
 'context_write_options',options,'fact_write_options',fact_options,'data_use',jsonb_build_object('bindings',bindings,'bindings_limit',64,'bindings_more',bindings_more),
 'collaboration',jsonb_build_object('options',link_options,'workspaces',workspace_choices,'relationships',relationships,
  'workspace_limit',32,'workspace_more',cardinality(workspace_candidates)>32,'relationship_limit',64),
 'delegation',jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'options',delegation_options,'issued',issued_grants,'issued_limit',64,'issued_more',more_grants,
  'option_limit',32,'options_may_be_partial',jsonb_array_length(requirements)=32),'catalog',public.builderad_v2_business_catalog(p_session));
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(options||(fact_options->'markets')||(fact_options->'intent')||(fact_options->'preferences')||(fact_options->'purpose')||link_options||delegation_options) x WHERE(x->>'grant_id')::bigint=g.id) THEN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   PERFORM builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
  END IF;
 END LOOP;
 FOR g IN SELECT value FROM jsonb_array_elements(workspace_choices) LOOP
  IF NOT builderad_private.workspace_allowed((SELECT id FROM public.workspaces WHERE public_id=(g.value->>'public_id')::uuid),actor,true)
   THEN RAISE EXCEPTION 'Collaboration metadata authority changed after wait' USING ERRCODE='42501'; END IF;
 END LOOP;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result||jsonb_build_object('scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('result',result,'read',read_proof,'write',proofs)::text,'UTF8')),'hex')));
END $$;
-- Native source administration projects only the exact current owner's management
-- tuple. It grants no Business, client boundary, data-use or provider permission.
CREATE FUNCTION builderad_private.native_source_selection(p_grant bigint) RETURNS jsonb
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('realm_kind','direct','realm_workspace_public_id',NULL,'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',p_grant)));
$$;

CREATE FUNCTION builderad_private.native_source_read_lock(p_sources uuid[]) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE source_ref uuid;
BEGIN
 -- Adoption's admission gate precedes K1/K4. Take the complete sorted union first.
 FOR source_ref IN SELECT DISTINCT unnest(p_sources) ORDER BY 1 LOOP
  PERFORM pg_advisory_xact_lock_shared(hashtextextended('builderad.source.admission.'||source_ref,0));
 END LOOP;
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=ANY(p_sources) ORDER BY source_id FOR SHARE;
END $$;

CREATE FUNCTION builderad_v2_source_choices(p_session text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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
   IF NOT EXISTS(SELECT 1 FROM public.source_registry s WHERE s.id=g.source_target_id AND s.workspace_id=g.workspace_id) THEN CONTINUE; END IF;
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

CREATE FUNCTION builderad_v2_source_surface(p_session text,p_source uuid,p_access bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;g public.security_action_grants;s public.source_registry;selection jsonb;f public.resource_right_fences;rights jsonb;more boolean;fresh boolean;declared boolean;
BEGIN
 actor:=builderad_private.principal(p_session,false);selection:=builderad_private.native_source_selection(p_access);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_access AND subject_user_id=actor AND scope_kind='source' AND action='source.manage' AND source_target_id=p_source)
 THEN RAISE EXCEPTION 'Exact native source manager preflight required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.native_source_read_lock(ARRAY[p_source]);
 PERFORM builderad_private.require_native_source_grant(p_access,actor,p_session,selection,true,false);
 SELECT * INTO STRICT g FROM public.security_action_grants WHERE id=p_access;
 IF g.action<>'source.manage' OR g.source_target_id IS DISTINCT FROM p_source THEN RAISE EXCEPTION 'Source manager target changed' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.source_registry WHERE id=p_source AND workspace_id=g.workspace_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'Exact native source required' USING ERRCODE='42501'; END IF;
 SELECT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=p_source AND workspace_id=g.workspace_id AND resource=g.source_resource AND operation=g.source_operation AND purpose=g.source_purpose) INTO declared;
 SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source;
 SELECT count(*)>64 INTO more FROM(SELECT 1 FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND source_target_id=p_source AND workspace_id=g.workspace_id
  AND source='source_owner_request' AND source_resource=g.source_resource AND source_operation=g.source_operation AND source_purpose=g.source_purpose LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',id::text,'action',action,'recorded_status',status,'revision',version,'expires_at',expires_at) ORDER BY id DESC),'[]') INTO rights
 FROM(SELECT * FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND source_target_id=p_source AND workspace_id=g.workspace_id
  AND source='source_owner_request' AND source_resource=g.source_resource AND source_operation=g.source_operation AND source_purpose=g.source_purpose ORDER BY id DESC LIMIT 64) bounded;
 fresh:=EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp());
 PERFORM builderad_private.require_native_source_grant(p_access,actor,p_session,selection,true,false);
 RETURN jsonb_build_object('source',jsonb_build_object('source_id',s.id,'label',s.label,'family',s.family,'namespace',s.namespace,'status',s.status,'revision',s.version,
  'adopted',coalesce(f.adoption_state='v2_adopted',false),'scope_declared',declared,'resource',g.source_resource,'operation',g.source_operation,'purpose',g.source_purpose),
  'authority_selection',selection,'can_command',fresh,'rights',rights,'rights_limit',64,'rights_more',more);
END $$;


-- Scope discovery is independent of Business/CDB/source administration. It releases
-- bounded permission metadata, never native customer keys or source contents.
CREATE FUNCTION builderad_v2_data_use_choices(p_session text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;g record;s public.data_use_scopes;candidate jsonb;selection jsonb;requirements jsonb:='[]';prepared jsonb:='[]';accepted jsonb:='[]';items jsonb:='[]';
 sources uuid[]:='{}';boundaries uuid[]:='{}';last_id bigint:=p_after;scanned integer:=0;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Invalid data-use cursor' USING ERRCODE='22023'; END IF;
 FOR g IN SELECT id,data_use_scope_id FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='data_use' AND id>p_after
  AND status='active' AND revoked_at IS NULL AND(expires_at IS NULL OR expires_at>clock_timestamp()) ORDER BY id LIMIT 32 LOOP
  last_id:=g.id;scanned:=scanned+1;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=g.data_use_scope_id;
  IF NOT FOUND THEN CONTINUE; END IF;
  prepared:=prepared||jsonb_build_array(jsonb_build_object('grant_id',g.id,'scope',to_jsonb(s)));
  sources:=array_append(sources,s.source_id);boundaries:=array_append(boundaries,s.boundary_id);
  requirements:=requirements||jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',g.id));
 END LOOP;
 -- Complete K1 union before K4. Preflight principal and scope reads above take no locks.
 PERFORM 1 FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(sources)) OR(kind='client_boundary' AND boundary_id=ANY(boundaries)) ORDER BY fence_id FOR SHARE;
 IF jsonb_array_length(requirements)>0 THEN PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);END IF;
 FOR candidate IN SELECT value FROM jsonb_array_elements(prepared) LOOP
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(candidate#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM candidate->'scope' OR s.status<>'active' THEN CONTINUE; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=s.source_id AND adoption_state='v2_adopted')
   OR NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id AND adoption_state='v2_adopted')
   OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active') THEN CONTINUE; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(candidate->>'grant_id')::bigint;
  IF g.subject_user_id IS DISTINCT FROM actor OR g.scope_kind<>'data_use' OR g.data_use_scope_id IS DISTINCT FROM s.scope_id OR g.action IS DISTINCT FROM 'data_use.'||s.operation THEN CONTINUE; END IF;
  selection:=jsonb_build_object('realm_kind',g.delegation_realm_kind,'realm_workspace_public_id',(SELECT public_id FROM public.workspaces WHERE id=g.delegation_workspace_id),
   'requirements',jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',g.id)));
  BEGIN
   PERFORM builderad_private.require_scope('data_use',s.scope_id::text,actor,'data_use.'||s.operation,selection,p_session);
   items:=items||jsonb_build_array(jsonb_build_object('scope_id',s.scope_id,'grant_id',g.id::text,'grant_revision',g.version,'operation',s.operation,'purpose',s.purpose,'resource',s.resource,
    'source_ref',s.source_id,'boundary_ref',s.boundary_id,'field_keys',s.selection->'field_keys','record_count',jsonb_array_length(s.selection->'native_keys'),
    'namespace_count',jsonb_array_length(s.selection->'native_namespaces'),'authority_selection',selection));
   accepted:=accepted||jsonb_build_array(jsonb_build_object('scope',to_jsonb(s),'selection',selection));
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOR candidate IN SELECT value FROM jsonb_array_elements(accepted) LOOP
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(candidate#>>'{scope,scope_id}')::uuid;
  IF to_jsonb(s) IS DISTINCT FROM candidate->'scope' THEN RAISE EXCEPTION 'Data scope changed after wait' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.require_scope('data_use',s.scope_id::text,actor,'data_use.'||s.operation,candidate->'selection',p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('scopes',items,'next_cursor',CASE WHEN scanned=32 THEN last_id::text ELSE NULL END);
END $$;
