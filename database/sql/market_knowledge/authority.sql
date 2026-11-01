-- Qualified platform-market operational metadata only. No Business, evidence payload or run table is queried.
-- Reuse the established narrow staff-validator SECURITY DEFINER pattern, without granting internal personnel table access to another role.
RESET ROLE;
CREATE OR REPLACE FUNCTION builderad_private.market_operator(p_session text,p_action text,p_lock boolean DEFAULT true) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;g public.security_action_grants;
BEGIN
 IF session_user<>'builderad_app' OR (p_action IS NULL OR p_action NOT IN('market.knowledge.observe','market.knowledge.capture','market.knowledge.publish')) THEN RAISE EXCEPTION 'Qualified operational reader required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal' AND c.workspace_id IS NULL;
 IF actor IS NULL THEN RAISE EXCEPTION 'Signed operational principal required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN
  PERFORM 1 FROM public.principal_lifecycles WHERE user_id=actor FOR SHARE;
  PERFORM 1 FROM public.users WHERE id=actor FOR SHARE;
  PERFORM 1 FROM public.internal_personnel WHERE user_id=actor FOR SHARE;
  PERFORM 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
 END IF;
 SELECT a.* INTO g FROM public.security_action_grants a JOIN public.principal_lifecycles l ON l.user_id=a.subject_user_id AND l.generation=a.subject_generation
 WHERE a.subject_user_id=actor AND a.action=p_action AND a.scope_kind='platform' AND a.workspace_id IS NULL
  AND a.status='active' AND a.revoked_at IS NULL AND(a.expires_at IS NULL OR a.expires_at>clock_timestamp()) ORDER BY a.id LIMIT 1;
 IF g.id IS NULL THEN RAISE EXCEPTION 'Explicit operational entitlement required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN PERFORM 1 FROM public.security_action_grants WHERE id=g.id FOR SHARE; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id
  JOIN public.internal_personnel p ON p.user_id=l.user_id JOIN public.security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  JOIN public.security_action_grants a ON a.subject_user_id=l.user_id AND a.subject_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='staff' AND u.email_verified_at IS NOT NULL AND p.status='active'
   AND s.session_key_hash=p_session AND s.auth_method='internal_passkey' AND s.revoked_at IS NULL AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
   AND a.id=g.id AND a.action=p_action AND a.scope_kind='platform' AND a.workspace_id IS NULL AND a.status='active' AND a.revoked_at IS NULL AND(a.expires_at IS NULL OR a.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Current qualified staff entitlement and session required' USING ERRCODE='42501'; END IF;
 RETURN actor;
END $$;

REVOKE ALL ON FUNCTION builderad_private.market_operator(text,text,boolean) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_private.market_operator(text,text,boolean) TO builderad_scope_executor;
CREATE FUNCTION builderad_private.market_staff_eligible(p_actor bigint) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 PERFORM 1 FROM public.internal_personnel WHERE user_id=p_actor FOR SHARE;
 RETURN EXISTS(SELECT 1 FROM public.internal_personnel WHERE user_id=p_actor AND status='active');
END $$;
REVOKE ALL ON FUNCTION builderad_private.market_staff_eligible(bigint) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_private.market_staff_eligible(bigint) TO builderad_scope_executor;
SET LOCAL ROLE builderad_scope_executor;


-- Only the new human-operated market path accepts these non-delegable, deployment-qualified roots.
-- Existing advertiser/agency require_scope and all its callers are deliberately unchanged.
CREATE FUNCTION builderad_private.market_operator_authorize(p_session text,p_action text,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;req jsonb;g public.security_action_grants;q public.authority_qualification_receipts;
 s public.data_use_scopes;c public.market_source_custody;proofs jsonb:='[]';
BEGIN
 IF p_action IS NULL OR p_action NOT IN('market.knowledge.capture','market.knowledge.publish') THEN RAISE EXCEPTION 'Explicit market effect required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.market_keys(p_selection,ARRAY['realm_kind','realm_workspace_public_id','requirements']);
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb
 OR jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements') NOT BETWEEN 1 AND 16
 OR (SELECT count(*)<>count(DISTINCT value->>'leaf_grant_id') FROM jsonb_array_elements(p_selection->'requirements'))
 THEN RAISE EXCEPTION 'Exact bounded direct market authority required' USING ERRCODE='42501'; END IF;
 SELECT x.actor_user_id INTO actor FROM public.builderad_signed_context() x WHERE x.phase='principal' AND x.workspace_id IS NULL;
 IF session_user<>'builderad_app' OR actor IS NULL THEN RAISE EXCEPTION 'Signed market operator required' USING ERRCODE='42501'; END IF;
 -- Caller has frozen the complete source/data-use resource union at K1, before this K4 footprint.
 PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
 IF builderad_private.market_operator(p_session,p_action) IS DISTINCT FROM actor THEN RAISE EXCEPTION 'Market actor mismatch' USING ERRCODE='42501'; END IF;
 FOR req IN SELECT value FROM jsonb_array_elements(p_selection->'requirements') LOOP
  PERFORM builderad_private.market_keys(req,ARRAY['leaf_grant_id','requirement_kind']);
  IF req->>'requirement_kind' IS DISTINCT FROM 'data_use' OR coalesce(req->>'leaf_grant_id','') !~ '^[1-9][0-9]{0,17}$' THEN RAISE EXCEPTION 'Exact data-use root required' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE id=(req->>'leaf_grant_id')::bigint;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=g.data_use_scope_id;
  SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
  IF g.id IS NULL OR g.subject_user_id IS DISTINCT FROM actor OR g.subject_generation IS DISTINCT FROM (SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
  OR g.scope_kind IS DISTINCT FROM 'data_use' OR g.parent_grant_id IS NOT NULL OR g.delegable IS DISTINCT FROM false OR g.source IS DISTINCT FROM 'qualified_policy'
  OR g.delegation_realm_kind IS DISTINCT FROM 'direct' OR g.delegation_workspace_id IS NOT NULL OR g.status IS DISTINCT FROM 'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
  OR q.qualification_id IS NULL OR q.issuer_kind IS DISTINCT FROM 'qualified_policy' OR q.status IS DISTINCT FROM 'active' OR q.subject_user_id IS DISTINCT FROM actor
  OR q.target_kind IS DISTINCT FROM 'data_use' OR q.target_ref IS DISTINCT FROM s.scope_id OR NOT g.action=ANY(q.allowed_actions)
  OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
  OR s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL OR s.status IS DISTINCT FROM 'active'
  OR s.resource NOT IN('market.evidence','market.knowledge','market.business_finding') OR g.action IS DISTINCT FROM 'data_use.'||s.operation
  OR s.selection->'native_namespaces' IS DISTINCT FROM jsonb_build_array(s.resource)
  OR c.source_id IS NULL OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
  OR q.policy_id IS DISTINCT FROM c.policy_id OR q.policy_version IS DISTINCT FROM c.policy_version
  OR NOT s.operation=ANY(c.allowed_operations) OR NOT s.purpose=ANY(c.allowed_purposes)
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
  THEN RAISE EXCEPTION 'Current qualified market operation root required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.market_policy(s.scope_id);
  proofs:=proofs||jsonb_build_array(jsonb_build_object('grant_row',to_jsonb(g),'qualification_row',to_jsonb(q),'scope',to_jsonb(s),'policy',to_jsonb(c)));
 END LOOP;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(proofs) v WHERE
  (v#>>'{grant_row,expires_at}' IS NOT NULL AND(v#>>'{grant_row,expires_at}')::timestamptz<=clock_timestamp())
  OR(v#>>'{qualification_row,valid_until}' IS NOT NULL AND(v#>>'{qualification_row,valid_until}')::timestamptz<=clock_timestamp())
  OR(v#>>'{policy,valid_until}' IS NOT NULL AND(v#>>'{policy,valid_until}')::timestamptz<=clock_timestamp()))
 THEN RAISE EXCEPTION 'Selected market licence expired before qualification release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.market_operator(p_session,p_action,false);
 RETURN jsonb_build_object('actor',actor,'effect',p_action,'rights',proofs,'dependencies',coalesce((SELECT jsonb_agg(to_jsonb(f) ORDER BY f.fence_id)
 FROM public.resource_right_fences f WHERE (f.kind='source' AND f.source_id IN(SELECT (v#>>'{scope,source_id}')::uuid FROM jsonb_array_elements(proofs) v))
 OR(f.kind='data_use' AND f.data_use_scope_id IN(SELECT (v#>>'{scope,scope_id}')::uuid FROM jsonb_array_elements(proofs) v))),'[]'::jsonb));
END $$;
REVOKE ALL ON FUNCTION builderad_private.market_operator_authorize(text,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE OR REPLACE FUNCTION builderad_private.assert_invocation_current() RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE i builderad_private.invocations;session_ref text;expired boolean;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 IF i.actor_user_id IS NOT NULL THEN
  SELECT session_key_hash INTO session_ref FROM public.security_sessions WHERE user_id=i.actor_user_id AND lifecycle_generation=i.generation
   AND sha256(convert_to(session_key_hash,'UTF8'))=i.session_ref_digest;
  IF session_ref IS NULL THEN RAISE EXCEPTION 'Final bound session unavailable' USING ERRCODE='42501'; END IF;
  IF i.action IN('market.knowledge.capture','market.knowledge.publish') THEN
   PERFORM builderad_private.market_operator(session_ref,i.action,false);
  ELSE PERFORM builderad_private.principal(session_ref,false); END IF;
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
