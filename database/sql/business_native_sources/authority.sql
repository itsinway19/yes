-- Approved Source-custody qualification, not a Business action or data-use root.
CREATE FUNCTION builderad_private.require_business_source_creator(p_business bigint,p_actor bigint,p_session text,p_selection jsonb,p_fresh boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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

CREATE FUNCTION builderad_private.business_source_grant_current(p_grant bigint,p_actor bigint,p_session text,p_fresh boolean) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.user_id=p_actor AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
  JOIN public.security_sessions ss ON ss.user_id=l.user_id AND ss.lifecycle_generation=l.generation AND ss.session_key_hash=p_session
  JOIN public.security_action_grants g ON g.id=p_grant AND g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  JOIN public.source_registry s ON s.id=g.source_target_id AND s.custody_kind='business_native' AND s.workspace_id IS NULL AND s.business_id=g.business_id
  JOIN public.businesses b ON b.id=s.business_id AND b.created_by=p_actor AND b.origin_kind='self_declared_draft' AND b.lifecycle IN('declared_draft','active')
  JOIN public.migration_cohort_members member ON member.business_id=b.id AND member.kind='business'
  JOIN public.migration_cohort_control control ON control.cohort_id=member.cohort_id AND control.mode='V2_ACTIVE'
  JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  JOIN public.security_action_grants basis ON basis.id=q.issuer_grant_id AND basis.subject_user_id=l.user_id AND basis.subject_generation=l.generation
  JOIN public.authority_qualification_receipts basis_q ON basis_q.qualification_id=basis.qualification_id
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='source' AND g.workspace_id IS NULL
   AND g.source='source_owner_request' AND g.granted_by=p_actor AND g.parent_grant_id IS NULL AND NOT g.delegable
   AND g.boundary_id IS NULL AND g.data_use_scope_id IS NULL AND g.delegation_realm_kind IS NULL AND g.delegation_workspace_id IS NULL
   AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
   AND q.policy_id='business_native_source_custody_v1' AND q.policy_version=1 AND q.issuer_kind='qualified_policy'
   AND q.subject_user_id=p_actor AND q.target_kind='source' AND q.target_ref=s.id AND g.action=ANY(q.allowed_actions)
   AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp())
   AND basis.business_id=b.id AND basis.scope_kind='business' AND basis.action='business.context.read'
   AND basis.source='business_draft_creation' AND basis.parent_grant_id IS NULL AND NOT basis.delegable
   AND basis.delegation_realm_kind='direct' AND basis.delegation_workspace_id IS NULL AND basis.status='active' AND basis.revoked_at IS NULL
   AND(basis.expires_at IS NULL OR basis.expires_at>clock_timestamp())
   AND basis_q.policy_id='self_declared_draft' AND basis_q.policy_version=1 AND basis_q.issuer_kind='self_declared_draft'
   AND basis_q.subject_user_id=p_actor AND basis_q.target_kind='business' AND basis_q.target_ref=b.public_id
   AND basis.action=ANY(basis_q.allowed_actions) AND basis_q.status='active' AND basis_q.valid_from<=clock_timestamp()
   AND(basis_q.valid_until IS NULL OR basis_q.valid_until>clock_timestamp())
   AND ss.revoked_at IS NULL AND ss.auth_method<>'internal_passkey' AND ss.idle_expires_at>clock_timestamp() AND ss.absolute_expires_at>clock_timestamp()
   AND(NOT p_fresh OR ss.authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 );
$$;

CREATE FUNCTION builderad_private.business_source_proof(p_grant bigint,p_actor bigint,p_session text,p_fresh boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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

CREATE FUNCTION builderad_private.business_source_require(p_action text,p_source uuid,p_resource text,p_operation text,p_purpose text,p_session text) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;selected bigint;selected_read bigint;b_public uuid;selection jsonb;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 -- Own metadata is a preflight only. K1 and Business fences precede K4 locks;
 -- every current/time-dependent qualification is checked after those waits.
 SELECT g.id,b.public_id INTO selected,b_public FROM public.security_action_grants g
 JOIN public.source_registry s ON s.id=g.source_target_id AND s.custody_kind='business_native' AND s.business_id=g.business_id AND s.workspace_id IS NULL
 JOIN public.businesses b ON b.id=s.business_id
 WHERE g.subject_user_id=actor AND g.scope_kind='source' AND g.workspace_id IS NULL AND g.source_target_id=p_source
  AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose AND g.action=p_action
  AND g.status='active' AND g.revoked_at IS NULL;
 IF selected IS NULL THEN RAISE EXCEPTION 'Exact Business-native Source preflight denied' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted' FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Protected Source custody fence required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_business(b_public,false);
 selection:=jsonb_build_object('realm_kind','direct','realm_workspace_public_id',NULL,'requirements',
  jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',selected)));
 IF p_action='provider.read' THEN
  SELECT id INTO selected_read FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND workspace_id IS NULL
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

-- The existing native selector still owns its HMAC/session binding. A clean
-- principal branch carries no Workspace membership and grants no Source right.
CREATE OR REPLACE FUNCTION public.builderad_source_actor(p_workspace bigint,p_session text,p_owner boolean,p_fresh boolean)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 IF p_workspace IS NULL THEN
  a:=builderad_private.principal(p_session,false);
  IF p_fresh AND NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=a AND session_key_hash=p_session
   AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
  THEN RAISE EXCEPTION 'Fresh Source administrator authentication required' USING ERRCODE='42501'; END IF;
 ELSE a:=public.builderad_current_workspace_actor(p_workspace,p_session,p_owner,p_fresh); END IF;
 PERFORM set_config('app.source_session',p_session,true);
 PERFORM set_config('app.source_session_signature',
  (SELECT encode(hmac(convert_to(current_setting('app.signed_context')||'|'||p_session,'UTF8'),secret,'sha256'),'hex') FROM public.builderad_context_secret WHERE singleton),true);
 RETURN a;
END $$;

CREATE OR REPLACE FUNCTION public.builderad_source_owner_evidence(p_workspace bigint,p_session text)
RETURNS TABLE(actor_id bigint,generation bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 IF p_workspace IS NULL THEN RAISE EXCEPTION 'Workspace evidence cannot issue Business Source authority' USING ERRCODE='42501'; END IF;
 a:=public.builderad_source_actor(p_workspace,p_session,true,true);
 RETURN QUERY SELECT a,l.generation FROM public.principal_lifecycles l WHERE l.user_id=a;
END $$;

CREATE OR REPLACE FUNCTION public.builderad_source_has(p_action text,p_source uuid,p_workspace bigint,p_resource text,p_operation text,p_purpose text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
BEGIN
 IF p_workspace IS NULL THEN
  RETURN current_setting('app.source_session_signature',true)=
   (SELECT encode(hmac(convert_to(current_setting('app.signed_context',true)||'|'||current_setting('app.source_session',true),'UTF8'),secret,'sha256'),'hex') FROM public.builderad_context_secret WHERE singleton)
   AND EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.security_action_grants g ON g.subject_user_id=c.actor_user_id
    WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='source' AND g.workspace_id IS NULL AND g.source_target_id=p_source
    AND g.action=p_action AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose
    AND builderad_private.business_source_grant_current(g.id,c.actor_user_id,current_setting('app.source_session',true),false));
 END IF;
 RETURN EXISTS(
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
  JOIN public.security_sessions ss ON ss.user_id=l.user_id AND ss.lifecycle_generation=l.generation
   AND ss.session_key_hash=current_setting('app.source_session',true) AND ss.revoked_at IS NULL
   AND ss.auth_method<>'internal_passkey' AND ss.idle_expires_at>clock_timestamp() AND ss.absolute_expires_at>clock_timestamp()
  JOIN public.workspaces w ON w.id=c.workspace_id AND w.status='active'
  JOIN public.workspace_memberships m ON m.workspace_id=w.id AND m.user_id=l.user_id AND m.status='active'
  JOIN public.workspace_ownerships o ON o.workspace_id=w.id AND o.version>0
  JOIN public.principal_lifecycles ol ON ol.user_id=o.responsible_owner_user_id AND ol.status='current' AND ol.principal_class='advertiser'
  JOIN public.workspace_memberships om ON om.workspace_id=w.id AND om.user_id=ol.user_id AND om.status='active' AND om.role='owner'
  JOIN public.users ou ON ou.id=ol.user_id AND ou.email_verified_at IS NOT NULL
  JOIN public.security_action_grants v ON v.subject_user_id=l.user_id AND v.subject_generation=l.generation AND v.workspace_id=w.id
   AND v.scope_kind='workspace' AND v.action='workspace.view' AND v.status='active' AND v.revoked_at IS NULL AND(v.expires_at IS NULL OR v.expires_at>clock_timestamp())
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='workspace' AND c.workspace_id=p_workspace AND current_setting('app.source_session_signature',true)=
   (SELECT encode(hmac(convert_to(current_setting('app.signed_context',true)||'|'||current_setting('app.source_session',true),'UTF8'),secret,'sha256'),'hex') FROM public.builderad_context_secret WHERE singleton)
  AND g.scope_kind='source' AND g.workspace_id=p_workspace AND g.source_target_id=p_source
  AND g.action=p_action AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose
  AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()));
END $$;

CREATE OR REPLACE FUNCTION public.builderad_source_require(p_action text,p_source uuid,p_workspace bigint,p_resource text,p_operation text,p_purpose text,p_session text)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 a:=public.builderad_source_actor(p_workspace,p_session,p_action<>'source.read',p_action<>'source.read');
 IF p_workspace IS NULL THEN
  a:=builderad_private.business_source_require(p_action,p_source,p_resource,p_operation,p_purpose,p_session);
 ELSE
  PERFORM 1 FROM public.security_action_grants WHERE subject_user_id=a AND workspace_id=p_workspace AND scope_kind='source'
   AND source_target_id=p_source AND source_resource=p_resource AND source_operation=p_operation AND source_purpose=p_purpose AND action=p_action FOR SHARE;
 END IF;
 a:=public.builderad_source_actor(p_workspace,p_session,p_action<>'source.read',p_action<>'source.read');
 IF NOT public.builderad_source_has(p_action,p_source,p_workspace,p_resource,p_operation,p_purpose)
 THEN RAISE EXCEPTION 'Exact current Source grant required' USING ERRCODE='42501'; END IF;
 RETURN a;
END $$;
