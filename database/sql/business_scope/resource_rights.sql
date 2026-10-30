CREATE FUNCTION builderad_private.lock_boundary(p_boundary uuid,p_write boolean) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE f uuid;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='client_data' AND g.boundary_id=p_boundary AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Boundary authority preflight denied' USING ERRCODE='42501'; END IF;
 IF p_write THEN SELECT fence_id INTO f FROM public.resource_right_fences WHERE boundary_id=p_boundary AND kind='client_boundary' AND adoption_state='v2_adopted' FOR UPDATE;
 ELSE SELECT fence_id INTO f FROM public.resource_right_fences WHERE boundary_id=p_boundary AND kind='client_boundary' AND adoption_state='v2_adopted' FOR SHARE; END IF;
 IF f IS NULL THEN RAISE EXCEPTION 'Qualified boundary gate required' USING ERRCODE='42501'; END IF;
 RETURN f;
END $$;

CREATE FUNCTION builderad_private.advance_right_epoch(p_fence uuid) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.resource_right_fences;f public.resource_right_fences;
BEGIN
 SELECT * INTO STRICT prior FROM public.resource_right_fences WHERE fence_id=p_fence;f:=prior;f.rights_epoch:=prior.rights_epoch+1;
 PERFORM builderad_private.permit('public.resource_right_fences','update',to_jsonb(prior),to_jsonb(f));
 UPDATE public.resource_right_fences SET rights_epoch=f.rights_epoch WHERE fence_id=p_fence;
END $$;

CREATE FUNCTION builderad_v2_resource_right_command(p_session text,p_command uuid,p_kind text,p_target uuid,p_effect text,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE boundary_ref uuid;fence uuid;b bigint;cohort uuid;mode text;actor bigint;snapshot jsonb;business_proof jsonb;payload bytea;result jsonb;
 old_m public.boundary_subjects;m public.boundary_subjects;prior_g public.security_action_grants;g public.security_action_grants;issuer bigint;action text;
 boundary_row public.client_data_boundaries;branch bigint;
BEGIN
 IF p_command IS NULL OR p_target IS NULL OR p_expected IS NULL OR p_expected<0 OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object' OR octet_length(p_payload::text)>65536
 OR p_kind IS NULL OR p_kind NOT IN('client_boundary','boundary_subject','data_use','source') THEN RAISE EXCEPTION 'Typed resource right command required' USING ERRCODE='22023'; END IF;
 IF p_kind='source' THEN RETURN builderad_private.source_right_command(p_session,p_command,p_target,p_effect,p_payload,p_expected,p_selection); END IF;
 IF p_kind='data_use' THEN RETURN builderad_private.data_use_right_command(p_session,p_command,p_target,p_effect,p_payload,p_expected,p_selection); END IF;
 -- Root creation/qualification has no enabled issuer policy. No caller may bootstrap a right.
 IF p_effect IS NULL OR p_effect NOT IN('attach_subject','detach_subject','revoke') THEN RAISE EXCEPTION 'Qualified resource issuer required; unsupported root operation' USING ERRCODE='42501'; END IF;
 IF p_kind='boundary_subject' THEN
  IF p_effect='attach_subject' THEN
   IF (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['branch_id','business_public_id']::text[]
    OR coalesce(p_payload->>'business_public_id','') !~ '^[0-9a-fA-F-]{36}$' THEN RAISE EXCEPTION 'Exact boundary membership command required' USING ERRCODE='22023'; END IF;
   boundary_ref:=p_target;SELECT id INTO b FROM public.businesses WHERE public_id=(p_payload->>'business_public_id')::uuid;
   IF b IS NULL THEN RAISE EXCEPTION 'Boundary membership denied' USING ERRCODE='42501'; END IF;
   IF p_payload->'branch_id'<>'null'::jsonb THEN
    IF coalesce(p_payload->>'branch_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Typed branch identity required' USING ERRCODE='22023'; END IF;
    branch:=(p_payload->>'branch_id')::bigint;
   END IF;
  ELSIF p_effect='detach_subject' THEN
   IF p_payload<>'{}'::jsonb THEN RAISE EXCEPTION 'Detach selects an exact existing membership, not replacement targets' USING ERRCODE='22023'; END IF;
   SELECT * INTO old_m FROM public.boundary_subjects WHERE membership_id=p_target;
   IF NOT FOUND THEN RAISE EXCEPTION 'Boundary membership denied' USING ERRCODE='42501'; END IF;
   boundary_ref:=old_m.boundary_id;b:=old_m.business_id;branch:=old_m.branch_id;
  ELSE RAISE EXCEPTION 'Typed boundary membership effect required' USING ERRCODE='22023'; END IF;
  action:='client_data.manage';
 ELSE
  IF p_effect<>'revoke' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
   OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Exact boundary grant revoke required' USING ERRCODE='22023'; END IF;
  boundary_ref:=p_target;SELECT * INTO prior_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='client_data' AND boundary_id=boundary_ref;
  IF NOT FOUND THEN RAISE EXCEPTION 'Boundary grant denied' USING ERRCODE='42501'; END IF;action:=prior_g.action;
 END IF;
 IF p_effect='attach_subject' AND NOT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants candidate ON candidate.subject_user_id=l.user_id AND candidate.subject_generation=l.generation
  JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(p_selection->'requirements')='array' THEN p_selection->'requirements' ELSE '[]'::jsonb END) selected(v)
   ON selected.v->>'leaf_grant_id'=candidate.id::text AND selected.v->>'requirement_kind'='business'
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND candidate.scope_kind='business' AND candidate.business_id=b AND candidate.action='business.link.manage'
   AND candidate.status='active' AND candidate.revoked_at IS NULL AND(candidate.expires_at IS NULL OR candidate.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Business relationship preflight denied' USING ERRCODE='42501'; END IF;
 fence:=builderad_private.lock_boundary(boundary_ref,true);
 -- A boundary owner can withdraw coverage without a Business grant, but must serialize with that Business.
 IF b IS NOT NULL THEN
  SELECT cohort_id INTO cohort FROM public.migration_cohort_members WHERE business_id=b AND kind='business';
  SELECT c.mode INTO mode FROM public.migration_cohort_control c WHERE c.cohort_id=cohort FOR SHARE;
  IF mode IS DISTINCT FROM 'V2_ACTIVE' THEN RAISE EXCEPTION 'Business transition unavailable' USING ERRCODE='42501'; END IF;
  PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR UPDATE;
 END IF;
 actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_scope('client_data',boundary_ref::text,actor,action,p_selection,p_session);
 IF p_effect='attach_subject' THEN
  IF jsonb_array_length(p_selection->'requirements')<>2 THEN RAISE EXCEPTION 'Both boundary and Business authority required' USING ERRCODE='42501'; END IF;
  business_proof:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
  IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required' USING ERRCODE='42501'; END IF;
  snapshot:=snapshot||jsonb_build_object('business_authority',business_proof);
 ELSE
  IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact boundary authority required' USING ERRCODE='42501'; END IF;
 END IF;
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=boundary_ref FOR SHARE;
 IF boundary_row.status<>'active' AND p_effect='attach_subject' THEN RAISE EXCEPTION 'Boundary coverage unavailable' USING ERRCODE='42501'; END IF;
 IF branch IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.business_branches WHERE id=branch AND business_id=b AND status='active') THEN RAISE EXCEPTION 'Qualified branch required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind',p_kind,'target',p_target,'effect',p_effect,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 SELECT r.result_ref INTO result FROM public.business_command_receipts r WHERE r.command_id=p_command AND r.actor_user_id=actor AND r.action='resource.'||p_effect AND r.payload_digest=payload;
 IF FOUND THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.business_command_receipts WHERE command_id=p_command) THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'resource.'||p_effect,jsonb_build_object('boundary_id',boundary_ref,'business_id',b),snapshot);
 IF p_kind='boundary_subject' THEN
  SELECT * INTO old_m FROM public.boundary_subjects WHERE boundary_id=boundary_ref AND business_id=b AND branch_id IS NOT DISTINCT FROM branch ORDER BY revision DESC,membership_id DESC LIMIT 1 FOR UPDATE;
  IF p_effect='detach_subject' AND old_m.membership_id IS DISTINCT FROM p_target THEN RAISE EXCEPTION 'Exact current boundary membership changed before effect' USING ERRCODE='B4090'; END IF;
  IF coalesce(old_m.revision,0)<>p_expected THEN RAISE EXCEPTION 'Boundary membership changed before effect' USING ERRCODE='B4090'; END IF;
  m:=old_m;
  IF p_effect='attach_subject' THEN
   IF old_m.status='active' THEN RAISE EXCEPTION 'Coverage already attached' USING ERRCODE='40001'; END IF;
   m.membership_id:=uuidv7();m.boundary_id:=boundary_ref;m.business_id:=b;m.branch_id:=branch;m.status:='active';m.revision:=coalesce(old_m.revision,0)+1;
   PERFORM builderad_private.permit('public.boundary_subjects','insert',NULL,to_jsonb(m));INSERT INTO public.boundary_subjects SELECT(m).*;
  ELSE
   IF old_m.membership_id IS NULL OR old_m.status<>'active' THEN RAISE EXCEPTION 'Current coverage required' USING ERRCODE='40001'; END IF;
   m.status:='ended';m.revision:=old_m.revision+1;
   PERFORM builderad_private.permit('public.boundary_subjects','update',to_jsonb(old_m),to_jsonb(m));UPDATE public.boundary_subjects SET status=m.status,revision=m.revision WHERE membership_id=m.membership_id;
  END IF;
  PERFORM builderad_private.advance_business_epoch(b);
  result:=jsonb_build_object('membership_id',m.membership_id,'status',m.status,'versions',jsonb_build_object('membership',m.revision));
 ELSE
  issuer:=(snapshot#>>'{grants,0,id}')::bigint;
  SELECT * INTO prior_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint FOR UPDATE;
  IF prior_g.version<>p_expected OR prior_g.status<>'active' THEN RAISE EXCEPTION 'Boundary grant changed' USING ERRCODE='40001'; END IF;
  IF prior_g.parent_grant_id IS DISTINCT FROM issuer AND NOT(prior_g.id=issuer AND prior_g.subject_user_id=actor) THEN RAISE EXCEPTION 'Exact issuer or surrender required' USING ERRCODE='42501'; END IF;
  g:=prior_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior_g),to_jsonb(g));
  UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
  result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 END IF;
 PERFORM builderad_private.advance_right_epoch(fence);PERFORM builderad_private.principal(p_session);
 IF NOT(p_effect='revoke' AND prior_g.id=issuer) THEN PERFORM builderad_private.require_scope('client_data',boundary_ref::text,actor,action,p_selection,p_session); END IF;
 IF p_effect='attach_subject' THEN PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session); END IF;
 PERFORM builderad_private.record_command(b,actor,p_command,'resource.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
