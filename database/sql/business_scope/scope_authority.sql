-- Freeze the union for every selected right before the first K4 lock. Per-right validation never expands it.
CREATE FUNCTION builderad_private.authority_footprint(p_actor bigint,p_selection jsonb,p_extra_users bigint[] DEFAULT '{}',p_extra_workspaces bigint[] DEFAULT '{}') RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE req jsonb;g public.security_action_grants;cursor_id bigint;path bigint[];grant_ids bigint[]:='{}';user_ids bigint[]:=ARRAY[p_actor]||p_extra_users;
 workspace_ids bigint[]:=p_extra_workspaces;business_ids bigint[]:='{}';qualification_ids uuid[]:='{}';owners bigint[];links jsonb;
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
    IF g.parent_grant_id IS NOT NULL OR g.workspace_id IS NULL THEN RAISE EXCEPTION 'Native source provenance required' USING ERRCODE='42501'; END IF;
    workspace_ids:=array_append(workspace_ids,g.workspace_id);
   END IF;
   path:=array_append(path,g.id);grant_ids:=array_append(grant_ids,g.id);user_ids:=array_append(user_ids,g.subject_user_id);
   IF g.qualification_id IS NOT NULL THEN qualification_ids:=array_append(qualification_ids,g.qualification_id); END IF;
   IF g.delegation_workspace_id IS NOT NULL THEN workspace_ids:=array_append(workspace_ids,g.delegation_workspace_id); END IF;
   IF g.business_id IS NOT NULL THEN business_ids:=array_append(business_ids,g.business_id); END IF;
   cursor_id:=g.parent_grant_id;
  END LOOP;
 END LOOP;
 SELECT workspace_ids||coalesce(array_agg(workspace_id),'{}'::bigint[]),qualification_ids||coalesce(array_agg(authority_ref),'{}'::uuid[]),
  coalesce(jsonb_agg(jsonb_build_object('link_id',link_id,'revision',revision,'workspace_id',workspace_id,'authority_ref',authority_ref) ORDER BY link_id),'[]')
 INTO workspace_ids,qualification_ids,links FROM public.business_workspace_links WHERE business_id=ANY(business_ids) AND status='active';
 SELECT coalesce(array_agg(responsible_owner_user_id),'{}'::bigint[]) INTO owners FROM public.workspace_ownerships WHERE workspace_id=ANY(workspace_ids);
 user_ids:=user_ids||owners;
 SELECT grant_ids||coalesce(array_agg(id),'{}'::bigint[]) INTO grant_ids FROM public.security_action_grants WHERE workspace_id=ANY(workspace_ids) AND subject_user_id=p_actor AND scope_kind='workspace' AND action='workspace.view';
 RETURN jsonb_build_object('users',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(user_ids) id) x),
  'workspaces',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(workspace_ids) id) x),
  'grants',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(grant_ids) id) x),
  'qualifications',(SELECT coalesce(jsonb_agg(id ORDER BY id),'[]') FROM(SELECT DISTINCT unnest(qualification_ids) id) x),'relationships',links);
END $$;

CREATE FUNCTION builderad_private.lock_authority_footprint(p_actor bigint,p_selection jsonb,p_session text,p_extra_users bigint[] DEFAULT '{}',p_extra_workspaces bigint[] DEFAULT '{}',p_extra_qualifications uuid[] DEFAULT '{}') RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE footprint jsonb;user_ids bigint[];workspace_ids bigint[];grant_ids bigint[];qualification_ids uuid[];
BEGIN
 footprint:=builderad_private.authority_footprint(p_actor,p_selection,p_extra_users,p_extra_workspaces);
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'users')) INTO user_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'workspaces')) INTO workspace_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) INTO grant_ids;
 SELECT ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(footprint->'qualifications'))||p_extra_qualifications INTO qualification_ids;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=ANY(user_ids) ORDER BY user_id FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=ANY(user_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.workspaces WHERE id=ANY(workspace_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.workspace_memberships WHERE workspace_id=ANY(workspace_ids) AND user_id=ANY(user_ids) ORDER BY workspace_id,user_id FOR SHARE;
 PERFORM 1 FROM public.workspace_ownerships WHERE workspace_id=ANY(workspace_ids) ORDER BY workspace_id FOR SHARE;
 IF p_session IS NULL THEN RAISE EXCEPTION 'Explicit current session required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session ORDER BY session_key_hash FOR SHARE;
 PERFORM 1 FROM public.security_action_grants WHERE id=ANY(grant_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qualification_ids) ORDER BY qualification_id FOR SHARE;
 IF builderad_private.authority_footprint(p_actor,p_selection,p_extra_users,p_extra_workspaces) IS DISTINCT FROM footprint THEN RAISE EXCEPTION 'PREPARATION_CHANGED: authority footprint changed' USING ERRCODE='40001'; END IF;
END $$;
-- One chain verifier for the three distinct protected target types.
CREATE FUNCTION builderad_private.require_scope(p_kind text,p_target text,p_actor bigint,p_action text,p_selection jsonb,p_session text,p_extra_users bigint[] DEFAULT '{}',p_extra_workspaces bigint[] DEFAULT '{}') RETURNS jsonb
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
 RETURN jsonb_build_object('codec_version',1,'actor',p_actor,'generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=p_actor),'action',p_action,'realm',p_selection-'requirements','grants',chain,'qualifications',quals,'qualification',to_jsonb(root_q),'relationships',relationships,'relationship_qualifications',relationship_quals,'epochs',CASE WHEN p_kind='business' THEN (SELECT jsonb_build_object('business_id',p_target::bigint,'auth_epoch',f.auth_epoch,'cohort_id',c.cohort_id,'cohort_epoch',c.epoch) FROM public.business_authority_fences f JOIN public.migration_cohort_members m ON m.business_id=f.business_id JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id WHERE f.business_id=p_target::bigint) ELSE (SELECT jsonb_agg(to_jsonb(f) ORDER BY f.fence_id) FROM public.resource_right_fences f WHERE f.boundary_id=CASE p_kind WHEN 'client_data' THEN p_target::uuid ELSE (SELECT boundary_id FROM public.data_use_scopes WHERE scope_id=p_target::uuid) END OR f.source_id=(SELECT source_id FROM public.data_use_scopes WHERE p_kind='data_use' AND scope_id=p_target::uuid)) END);
END $$;
