-- Qualified platform-market operational metadata only. No Business, evidence payload or run table is queried.
-- Reuse the established narrow staff-validator SECURITY DEFINER pattern, without granting internal personnel table access to another role.
RESET ROLE;
CREATE FUNCTION builderad_private.market_operator(p_session text,p_action text,p_lock boolean DEFAULT true) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;g public.security_action_grants;
BEGIN
 IF session_user<>'builderad_app' OR p_action<>'market.knowledge.observe' THEN RAISE EXCEPTION 'Qualified operational reader required' USING ERRCODE='42501'; END IF;
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
SET LOCAL ROLE builderad_scope_executor;

CREATE FUNCTION builderad_v2_market_operations_read(p_session text,p_cursor uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE result jsonb;after_ref uuid;actor bigint;
BEGIN
 actor:=builderad_private.market_operator(p_session,'market.knowledge.observe');
 -- The entitlement covers only these operational source-custody fields. It is not data-use, processing or publication authority.
 SELECT coalesce(jsonb_agg(jsonb_build_object('source_ref',source_id,'custody_revision',revision,'state',status,
  'rights_window',CASE WHEN valid_from>clock_timestamp() THEN 'not_started' WHEN valid_until<=clock_timestamp() THEN 'expired' ELSE 'current' END,
  'valid_until',valid_until) ORDER BY source_id),'[]') INTO result FROM(
  SELECT source_id,revision,status,valid_from,valid_until FROM public.market_source_custody
  WHERE p_cursor IS NULL OR source_id>p_cursor ORDER BY source_id LIMIT 50
 ) allowed_metadata;
 IF jsonb_array_length(result)=50 THEN
  after_ref:=(result->49->>'source_ref')::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.market_source_custody WHERE source_id>after_ref) THEN after_ref:=NULL;END IF;
 END IF;
 PERFORM builderad_private.market_operator(p_session,'market.knowledge.observe',false);
 RETURN jsonb_build_object('sources',result,'next_cursor',after_ref);
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_operations_read(text,uuid) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_operations_read(text,uuid) TO builderad_app;
