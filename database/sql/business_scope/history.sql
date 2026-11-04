-- Read the native owners' immutable transitions. This is not an audit log,
-- historical authority, or a reinterpretation of legacy Workspace versions.
CREATE FUNCTION builderad_v2_business_history(p_session text,p_business uuid,p_read bigint,p_kind text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;items jsonb;more boolean;cursor_value bigint;imported boolean;
BEGIN
 IF p_kind IS NULL OR p_kind NOT IN('context','markets','intent','preferences') OR p_after IS NULL OR p_after<0 THEN
  RAISE EXCEPTION 'Typed history owner and cursor required' USING ERRCODE='22023';
 END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN
  RAISE EXCEPTION 'Explicit own Business read path required' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 IF selection->>'realm_kind'='internal_team' THEN RAISE EXCEPTION 'Internal file access does not release private history' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.principal(p_session);
 WITH transitions AS (
  SELECT event_id,before_context_version prior,after_context_version revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_context_transitions WHERE business_id=b AND p_kind='context' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_context_version,after_context_version,before_context_version,after_context_version,to_jsonb(before_codes),to_jsonb(after_codes),recorded_at
   FROM public.business_market_transitions WHERE business_id=b AND p_kind='markets' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_revision,after_revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_intent_transitions WHERE business_id=b AND p_kind='intent' AND after_revision>p_after
  UNION ALL
  SELECT event_id,before_version,after_version,NULL::bigint,NULL::bigint,before_value,after_value,recorded_at
   FROM public.business_preference_transitions WHERE business_id=b AND p_kind='preferences' AND after_version>p_after
 ), bounded AS (SELECT * FROM transitions ORDER BY revision LIMIT 33), page AS (SELECT * FROM bounded ORDER BY revision LIMIT 32)
 SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('event_id',event_id,'before_version',prior::text,'version',revision::text,
  'before_context_version',before_context_version::text,'context_version',after_context_version::text,
  'before',before_value,'after',after_value,'recorded_at',recorded_at) ORDER BY revision) FROM page),'[]'::jsonb),
  (SELECT count(*)>32 FROM bounded),(SELECT max(revision) FROM page) INTO items,more,cursor_value;
 SELECT import_mapping_id IS NOT NULL INTO imported FROM public.business_context_heads WHERE business_id=b;
 -- No I/O that might wait is allowed after the final authority qualification.
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'kind',p_kind,'entries',items,'next_cursor',CASE WHEN more THEN cursor_value::text ELSE NULL END,
  'history_scope','native_business_transitions','has_imported_baseline',imported,
  'legacy_history','not_included','page_limit',32);
END $$;
