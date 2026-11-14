CREATE FUNCTION public.builderad_business_source_recovery(p_business bigint,p_session text,p_obligation uuid)
RETURNS TABLE(fingerprint text,envelope text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE bound_source uuid; receipt public.source_command_receipts;
BEGIN
 SELECT o.source_id INTO bound_source FROM public.source_audit_obligations o JOIN public.source_registry s ON s.id=o.source_id
  WHERE o.id=p_obligation AND s.business_id=p_business AND s.custody_kind='business_native';
 SELECT r.* INTO receipt FROM public.source_command_receipts r JOIN public.source_audit_obligations o
  ON o.source_id=r.source_id AND o.command_id=r.command_id WHERE o.id=p_obligation AND r.source_id=bound_source;
 IF NOT FOUND THEN RAISE EXCEPTION 'Exact Business source recovery required' USING ERRCODE='42501'; END IF;
 PERFORM public.builderad_source_require('source.audit.recover',bound_source,p_business,receipt.resource,receipt.operation,receipt.purpose,p_session);
 RETURN QUERY SELECT o.fingerprint,o.envelope::text FROM public.source_audit_obligations o WHERE o.id=p_obligation;
END $$;
REVOKE ALL ON FUNCTION public.builderad_business_source_recovery(bigint,text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_recovery(bigint,text,uuid) TO builderad_app;

CREATE FUNCTION public.builderad_business_source_record_delivery(p_business bigint,p_session text,p_obligation uuid,p_ack uuid) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
BEGIN
 PERFORM public.builderad_business_source_recovery(p_business,p_session,p_obligation);
 RETURN builderad_private.business_source_audit_delivery(p_session,p_obligation,p_ack);
END $$;
REVOKE ALL ON FUNCTION public.builderad_business_source_record_delivery(bigint,text,uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_record_delivery(bigint,text,uuid,uuid) TO builderad_app;

CREATE FUNCTION public.builderad_business_source_effect(p_business bigint,p_session text,p_command uuid,p_source uuid,p_effect text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; grant_id bigint; selection jsonb;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 SELECT g.id INTO grant_id FROM public.security_action_grants g JOIN public.principal_lifecycles l
 ON l.user_id=g.subject_user_id AND l.generation=g.subject_generation
 WHERE g.business_id=p_business AND g.scope_kind='source' AND g.subject_user_id=actor AND g.source_target_id=p_source
  AND g.action='source.manage' AND g.status='active' AND g.revoked_at IS NULL
  AND g.source_resource=p_payload->>'resource' AND g.source_operation=p_payload->>'operation' AND g.source_purpose=p_payload->>'purpose';
 IF grant_id IS NULL THEN RAISE EXCEPTION 'Current source management authority required' USING ERRCODE='42501'; END IF;
 selection:=builderad_private.native_source_selection(grant_id);
 RETURN builderad_private.source_fact_command(p_session,p_command,p_source,CASE WHEN p_effect='revoke' THEN 'retire' ELSE p_effect END,
  p_payload-ARRAY['resource','operation','purpose','expected_version'],(p_payload->>'expected_version')::bigint,selection);
END $$;
ALTER FUNCTION public.builderad_business_source_effect(bigint,text,uuid,uuid,text,jsonb) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_business_source_effect(bigint,text,uuid,uuid,text,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_effect(bigint,text,uuid,uuid,text,jsonb) TO builderad_app;

CREATE FUNCTION public.builderad_business_source_authority(p_business bigint,p_session text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; bound_source uuid; manager bigint; target public.security_action_grants;
 prior_command public.business_command_receipts; selection jsonb; result jsonb; decision_command uuid;
BEGIN
 actor:=builderad_private.principal(p_session,false); bound_source:=(p_payload->>'source_id')::uuid;
 decision_command:=(p_payload->>'command_id')::uuid;
 IF decision_command IS NULL THEN RAISE EXCEPTION 'Stable source decision identity required' USING ERRCODE='22023'; END IF;
 SELECT id INTO manager FROM public.security_action_grants WHERE business_id=p_business AND subject_user_id=actor AND scope_kind='source'
  AND source_target_id=bound_source AND action='source.manage' AND source_resource=p_payload->>'resource'
  AND source_operation=p_payload->>'operation' AND source_purpose=p_payload->>'purpose' AND status='active' AND revoked_at IS NULL;
 IF manager IS NULL THEN RAISE EXCEPTION 'Explicit source management authority required' USING ERRCODE='42501'; END IF;
 selection:=builderad_private.native_source_selection(manager);
 SELECT * INTO prior_command FROM public.business_command_receipts WHERE command_id=decision_command;
 IF FOUND AND (prior_command.actor_user_id IS DISTINCT FROM actor OR prior_command.business_id IS NOT NULL
  OR prior_command.action IS DISTINCT FROM CASE WHEN p_payload->>'decision'='authorize' THEN 'resource.qualify_issue' ELSE 'resource.revoke' END)
 THEN RAISE EXCEPTION 'Source decision identity conflict' USING ERRCODE='40001'; END IF;
 IF p_payload->>'decision'='authorize' THEN
  result:=builderad_private.source_grant_issue(p_session,decision_command,bound_source,
   jsonb_build_object('action',p_payload->>'action','reason',p_payload->>'reason','expires_at',p_payload->>'expires_at'),0,selection);
 ELSIF p_payload->>'decision'='revoke' THEN
  IF p_payload->>'expires_at' IS NOT NULL THEN RAISE EXCEPTION 'Revocation has no expiry' USING ERRCODE='22023'; END IF;
  IF prior_command.command_id IS NOT NULL THEN
   SELECT * INTO target FROM public.security_action_grants WHERE id=(prior_command.result_ref->>'grant_id')::bigint;
  ELSE
   SELECT * INTO target FROM public.security_action_grants WHERE business_id=p_business AND subject_user_id=actor AND scope_kind='source'
    AND source_target_id=bound_source AND action=p_payload->>'action' AND source_resource=p_payload->>'resource'
    AND source_operation=p_payload->>'operation' AND source_purpose=p_payload->>'purpose' AND status='active' AND revoked_at IS NULL;
  END IF;
  IF NOT FOUND THEN RAISE EXCEPTION 'Exact source authority required' USING ERRCODE='42501'; END IF;
  IF target.business_id IS DISTINCT FROM p_business OR target.subject_user_id IS DISTINCT FROM actor
   OR target.scope_kind IS DISTINCT FROM 'source' OR target.source_target_id IS DISTINCT FROM bound_source
   OR target.action IS DISTINCT FROM p_payload->>'action' OR target.source_resource IS DISTINCT FROM p_payload->>'resource'
   OR target.source_operation IS DISTINCT FROM p_payload->>'operation' OR target.source_purpose IS DISTINCT FROM p_payload->>'purpose'
  THEN RAISE EXCEPTION 'Source decision identity conflict' USING ERRCODE='40001'; END IF;
  result:=builderad_private.source_right_command(p_session,decision_command,bound_source,'revoke',jsonb_build_object('grant_id',target.id::text,'reason',p_payload->>'reason'),
   CASE WHEN prior_command.command_id IS NOT NULL THEN target.version-1 ELSE target.version END,selection);
 ELSE RAISE EXCEPTION 'Exact source authority decision required' USING ERRCODE='22023'; END IF;
 RETURN result;
END $$;
ALTER FUNCTION public.builderad_business_source_authority(bigint,text,jsonb) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_business_source_authority(bigint,text,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_authority(bigint,text,jsonb) TO builderad_app;
