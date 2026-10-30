-- Native source rights retain their native Workspace/capability contract. They never become Business grants.
CREATE FUNCTION builderad_private.require_native_source_grant(p_grant bigint,p_actor bigint,p_session text,p_selection jsonb,p_owner boolean,p_fresh boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE g public.security_action_grants;selected_count integer;workspace_public uuid;
BEGIN
 IF session_user<>'builderad_app' OR p_actor IS NULL OR p_grant IS NULL OR p_session IS NULL OR p_owner IS NULL OR p_fresh IS NULL
 OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() WHERE actor_user_id=p_actor AND phase='principal' AND workspace_id IS NULL)
 THEN RAISE EXCEPTION 'Clean signed native source actor required' USING ERRCODE='42501'; END IF;
 IF p_selection IS NULL OR jsonb_typeof(p_selection) IS DISTINCT FROM 'object' OR octet_length(p_selection::text)>65536
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_selection) k) IS DISTINCT FROM ARRAY['realm_kind','realm_workspace_public_id','requirements']::text[]
 OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array'
 OR jsonb_typeof(p_selection->'realm_kind') IS DISTINCT FROM 'string' OR p_selection->>'realm_kind' NOT IN('direct','agency')
 OR(p_selection->>'realm_kind'='direct' AND p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb)
 OR(p_selection->>'realm_kind'='agency' AND(jsonb_typeof(p_selection->'realm_workspace_public_id') IS DISTINCT FROM 'string' OR coalesce(p_selection->>'realm_workspace_public_id','') !~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'))
 THEN RAISE EXCEPTION 'Explicit processing and native source selection required' USING ERRCODE='42501'; END IF;
 SELECT count(*) INTO selected_count FROM jsonb_array_elements(p_selection->'requirements') x(v) WHERE v->>'requirement_kind'='native_source' AND v->>'leaf_grant_id'=p_grant::text;
 IF selected_count<>1 THEN RAISE EXCEPTION 'Exact native source grant selection required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(p_actor,p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_grant;
 IF NOT FOUND OR g.scope_kind<>'source' OR g.source<>'source_owner_request' OR g.subject_user_id<>p_actor OR g.granted_by<>p_actor
 OR g.parent_grant_id IS NOT NULL OR g.qualification_id IS NOT NULL OR g.business_id IS NOT NULL OR g.boundary_id IS NOT NULL OR g.data_use_scope_id IS NOT NULL
 OR g.delegation_realm_kind IS NOT NULL OR g.delegation_workspace_id IS NOT NULL OR g.status<>'active' OR g.revoked_at IS NOT NULL
 OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.principal_lifecycles WHERE user_id=p_actor AND generation=g.subject_generation AND status='current' AND principal_class='advertiser')
 OR NOT builderad_private.workspace_allowed(g.workspace_id,p_actor,p_owner)
 OR(p_fresh AND NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp()))
 THEN RAISE EXCEPTION 'Current native source ownership, session and exact grant required' USING ERRCODE='42501'; END IF;
 SELECT public_id INTO STRICT workspace_public FROM public.workspaces WHERE id=g.workspace_id;
 RETURN jsonb_build_object('codec_version',1,'actor',p_actor,'generation',g.subject_generation,'action',g.action,
  'realm',p_selection-'requirements','native_realm',jsonb_build_object('workspace_public_id',workspace_public,'workspace_id',g.workspace_id,
   'source_id',g.source_target_id,'resource',g.source_resource,'operation',g.source_operation,'purpose',g.source_purpose),
  'grants',jsonb_build_array(to_jsonb(g)),'qualifications','[]'::jsonb);
END $$;

CREATE FUNCTION builderad_private.source_right_command(p_session text,p_command uuid,p_source uuid,p_effect text,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE old_g public.security_action_grants;g public.security_action_grants;admission public.security_action_grants;selected_id bigint;selected_ref text;lock_selection jsonb;known_command boolean;actor bigint;fence public.resource_right_fences;proof jsonb;payload bytea;result jsonb;
BEGIN
 IF p_effect='adopt' THEN RETURN builderad_private.source_adopt(p_session,p_command,p_source,p_payload,p_expected,p_selection); END IF;
 IF p_effect='qualify_issue' THEN RETURN builderad_private.source_grant_issue(p_session,p_command,p_source,p_payload,p_expected,p_selection); END IF;
 IF p_effect IN('retire','rename','declare_scope') THEN RETURN builderad_private.source_fact_command(p_session,p_command,p_source,p_effect,p_payload,p_expected,p_selection); END IF;
 IF p_effect IS DISTINCT FROM 'revoke' OR p_expected<1
 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_payload) k) IS DISTINCT FROM ARRAY['grant_id']::text[]
 OR coalesce(p_payload->>'grant_id','') !~ '^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact adopted-source grant revoke required; root/adoption issuer not enabled here' USING ERRCODE='22023'; END IF;
 -- Minimal own-grant preflight precedes the K1 wait; it is not the authorization decision.
 SELECT * INTO old_g FROM public.security_action_grants WHERE id=(p_payload->>'grant_id')::bigint AND scope_kind='source' AND source_target_id=p_source;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND l.status='current' AND old_g.subject_user_id=l.user_id AND old_g.subject_generation=l.generation)
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
 IF admission.source_target_id IS DISTINCT FROM old_g.source_target_id OR admission.workspace_id IS DISTINCT FROM old_g.workspace_id
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
 IF NOT builderad_private.workspace_allowed(old_g.workspace_id,actor,true) OR NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 THEN RAISE EXCEPTION 'Native source owner evidence expired before effect' USING ERRCODE='42501'; END IF;
 result:=jsonb_build_object('grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 IF admission.id<>old_g.id THEN PERFORM builderad_private.require_native_source_grant(admission.id,actor,p_session,p_selection,true,true); END IF;
 PERFORM builderad_private.record_command(NULL,actor,p_command,'resource.revoke',payload,result);
 -- The receipt insert can wait after the mutation. Eligibility must still hold at this final local release boundary.
 -- Own surrender deliberately uses the locked, previously qualified before-image, never a resurrected revoked grant.
 PERFORM builderad_private.principal(p_session);
 IF (admission.expires_at IS NOT NULL AND admission.expires_at<=clock_timestamp())
 OR NOT builderad_private.workspace_allowed(old_g.workspace_id,actor,true)
 OR NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 THEN RAISE EXCEPTION 'Native source eligibility expired after receipt wait' USING ERRCODE='42501'; END IF;
 IF admission.id<>old_g.id THEN PERFORM builderad_private.require_native_source_grant(admission.id,actor,p_session,p_selection,true,true); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
