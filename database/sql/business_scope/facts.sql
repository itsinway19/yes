CREATE FUNCTION builderad_private.command_replay(p_business bigint,p_actor bigint,p_command uuid,p_action text,p_payload bytea) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.business_command_receipts;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Explicit command required' USING ERRCODE='22023'; END IF;
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF NOT FOUND THEN RETURN NULL; END IF;
 IF r.business_id<>p_business OR r.actor_user_id<>p_actor OR r.action<>p_action OR r.payload_digest<>p_payload
 THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 RETURN r.result_ref;
END $$;

CREATE FUNCTION builderad_private.finish_fact(p_session text,p_business bigint,p_actor bigint,p_selection jsonb,p_payload bytea,p_result jsonb,p_changed boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE i builderad_private.invocations;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.require_business(p_business,p_actor,i.action,p_selection,p_session);
 PERFORM builderad_private.record_command(p_business,p_actor,i.command_id,i.action,p_payload,p_result,CASE WHEN p_changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();
 RETURN p_result;
END $$;

CREATE FUNCTION builderad_v2_business_context_write(p_session text,p_business uuid,p_command uuid,p_name text,p_type bigint,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; snapshot jsonb; payload bytea; result jsonb; h public.business_context_heads; prior public.business_context_heads;
 e public.business_context_transitions; changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 OR p_name IS NULL OR length(btrim(p_name)) NOT BETWEEN 2 AND 120 OR octet_length(p_name)>480 THEN RAISE EXCEPTION 'Bounded name and expected version required' USING ERRCODE='22023'; END IF;
 p_name:=regexp_replace(btrim(p_name),'\s+',' ','g');
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 IF p_type IS NOT NULL THEN PERFORM 1 FROM public.business_types WHERE id=p_type FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Known Business type required' USING ERRCODE='22023'; END IF;
 END IF;
 SELECT * INTO prior FROM public.business_context_heads WHERE business_id=b FOR UPDATE;
 IF p_type IS NOT NULL AND p_type IS DISTINCT FROM prior.business_type_id AND NOT (SELECT active FROM public.business_types WHERE id=p_type) THEN RAISE EXCEPTION 'Selectable Business type required' USING ERRCODE='22023'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('name',p_name,'type',p_type,'expected',p_expected),'{"type":"I","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.context.manage',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.context.manage',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.display_name IS DISTINCT FROM p_name OR prior.business_type_id IS DISTINCT FROM p_type;h:=prior;
 IF changed THEN
  IF prior.context_version<>p_expected THEN RAISE EXCEPTION 'Business context changed' USING ERRCODE='40001'; END IF;
  h.display_name:=p_name;h.business_type_id:=p_type;h.context_version:=prior.context_version+1;
  PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior),to_jsonb(h));
  UPDATE public.business_context_heads SET display_name=h.display_name,business_type_id=h.business_type_id,context_version=h.context_version WHERE business_id=b;
  e.event_id:=uuidv7();e.business_id:=b;e.before_context_version:=prior.context_version;e.after_context_version:=h.context_version;
  e.before_value:=jsonb_build_object('name',prior.display_name,'business_type_id',prior.business_type_id);e.after_value:=jsonb_build_object('name',h.display_name,'business_type_id',h.business_type_id);
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_context_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_context_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'name',h.display_name,'business_type_id',h.business_type_id,'versions',jsonb_build_object('context',h.context_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;

CREATE FUNCTION builderad_v2_business_markets_write(p_session text,p_business uuid,p_command uuid,p_codes text[],p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint;snapshot jsonb;payload bytea;result jsonb;h public.business_context_heads;prior public.business_context_heads;
 before_codes text[];after_codes text[];e public.business_market_transitions;r public.business_declared_markets;changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 OR p_codes IS NULL OR coalesce(array_ndims(p_codes),1)<>1 OR cardinality(p_codes)>300 OR array_position(p_codes,NULL) IS NOT NULL
 OR EXISTS(SELECT 1 FROM unnest(p_codes) submitted(code) WHERE submitted.code !~ '^[A-Z]{2}$' OR NOT EXISTS(SELECT 1 FROM public.country_references c WHERE c.code=submitted.code))
 THEN RAISE EXCEPTION 'Canonical country list and version required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(array_agg(DISTINCT code COLLATE "C" ORDER BY code COLLATE "C"),'{}'::text[]) INTO after_codes FROM unnest(p_codes) code;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.markets.declare',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO prior FROM public.business_context_heads WHERE business_id=b FOR UPDATE;h:=prior;
 SELECT coalesce(array_agg(country_code::text ORDER BY country_code COLLATE "C"),'{}'::text[]) INTO before_codes FROM public.business_declared_markets WHERE business_id=b;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('codes',after_codes,'expected',p_expected),'{"expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.markets.declare',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.markets.declare',jsonb_build_object('business_id',b),snapshot);changed:=before_codes<>after_codes;
 IF changed THEN
  IF prior.context_version<>p_expected THEN RAISE EXCEPTION 'Business context changed' USING ERRCODE='40001'; END IF;
  FOR r IN SELECT * FROM public.business_declared_markets WHERE business_id=b LOOP
   PERFORM builderad_private.permit('public.business_declared_markets','delete',to_jsonb(r),NULL);
   DELETE FROM public.business_declared_markets WHERE business_id=b AND country_code=r.country_code;
  END LOOP;
  FOR r IN SELECT b,code::char(2) FROM unnest(after_codes) code LOOP
   PERFORM builderad_private.permit('public.business_declared_markets','insert',NULL,to_jsonb(r));INSERT INTO public.business_declared_markets SELECT(r).*;
  END LOOP;
  h.context_version:=prior.context_version+1;PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior),to_jsonb(h));
  UPDATE public.business_context_heads SET context_version=h.context_version WHERE business_id=b;
  e.event_id:=uuidv7();e.business_id:=b;e.before_context_version:=prior.context_version;e.after_context_version:=h.context_version;
  e.before_codes:=before_codes;e.after_codes:=after_codes;e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_market_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_market_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'codes',after_codes,'versions',jsonb_build_object('context',h.context_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;

CREATE FUNCTION builderad_v2_business_preferences_write(p_session text,p_business uuid,p_command uuid,p_value jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;snapshot jsonb;payload bytea;result jsonb;prior public.business_goal_preferences;r public.business_goal_preferences;
 e public.business_preference_transitions;next_value jsonb;current_version bigint;changed boolean;
BEGIN
 IF p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Expected preference revision required' USING ERRCODE='22023'; END IF;
 next_value:=public.builderad_advertiser_goals_normalize(p_value);
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.preferences.manage',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.preferences.'||b::text,0));
 SELECT * INTO prior FROM public.business_goal_preferences WHERE business_id=b FOR UPDATE;current_version:=coalesce(prior.preference_version,0);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('value',next_value,'expected',p_expected),'{"expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.preferences.manage',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF current_version<>p_expected THEN RAISE EXCEPTION 'Saved preferences changed' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.preferences.manage',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.value IS DISTINCT FROM next_value;r:=prior;
 IF changed THEN
  r.business_id:=b;r.preference_version:=current_version+1;r.value:=next_value;r.actor_id:=actor;r.updated_at:=clock_timestamp();
  IF prior.business_id IS NULL THEN
   PERFORM builderad_private.permit('public.business_goal_preferences','insert',NULL,to_jsonb(r));INSERT INTO public.business_goal_preferences SELECT(r).*;
  ELSE
   PERFORM builderad_private.permit('public.business_goal_preferences','update',to_jsonb(prior),to_jsonb(r));
   UPDATE public.business_goal_preferences SET preference_version=r.preference_version,value=r.value,actor_id=r.actor_id,updated_at=r.updated_at WHERE business_id=b;
  END IF;
  e.event_id:=uuidv7();e.business_id:=b;e.before_version:=current_version;e.after_version:=r.preference_version;e.before_value:=prior.value;e.after_value:=r.value;
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=r.updated_at;e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_preference_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_preference_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'value',r.value,'versions',jsonb_build_object('preferences',r.preference_version));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;
-- Confirmation rules belong to Current Intent, not to the Business context writer.
CREATE FUNCTION builderad_private.intent_confirmations(prior_value jsonb,next_value jsonb,p_confirmations jsonb) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN IF p_confirmations IS NULL OR jsonb_typeof(p_confirmations)<>'object' THEN
  RAISE EXCEPTION 'Explicit boolean confirmation object required' USING ERRCODE='22023';
 END IF;
 IF EXISTS (SELECT 1 FROM jsonb_each(p_confirmations) c WHERE c.key NOT IN
  ('declaration','business_wide','outcome','acceptance_clear','clarification_required','quantity','horizon')
  OR jsonb_typeof(c.value)<>'boolean') OR NOT coalesce(p_confirmations @> '{"declaration":true}',false) THEN
  RAISE EXCEPTION 'Recognized boolean declaration confirmations required' USING ERRCODE='22023';
 END IF;

 IF next_value IS NULL THEN
  IF EXISTS (SELECT 1 FROM jsonb_each(p_confirmations) c WHERE c.key<>'declaration' AND c.value='true'::jsonb) THEN
   RAISE EXCEPTION 'Clear cannot confirm replacement fields' USING ERRCODE='22023';
  END IF;
 ELSE
  IF NOT p_confirmations @> '{"business_wide":true}' THEN
   RAISE EXCEPTION 'Whole business confirmation required' USING ERRCODE='22023';
  END IF;
  IF next_value->'outcome' IS DISTINCT FROM prior_value->'outcome' THEN
   IF NOT p_confirmations @> '{"outcome":true,"acceptance_clear":true}'
    OR coalesce(p_confirmations @> '{"clarification_required":true}',false)<>
       (next_value->'outcome'->'clarification'<>'null'::jsonb) THEN
    RAISE EXCEPTION 'Independent clear outcome and consistent clarification confirmation required' USING ERRCODE='22023';
   END IF;
  END IF;
  IF next_value->'quantity'<>'null'::jsonb AND
   (next_value->'quantity' IS DISTINCT FROM prior_value->'quantity' OR next_value->'outcome' IS DISTINCT FROM prior_value->'outcome')
   AND NOT p_confirmations @> '{"quantity":true}' THEN
   RAISE EXCEPTION 'Quantity with its outcome and count unit must be confirmed' USING ERRCODE='22023';
  END IF;
 END IF;

END $$;
CREATE FUNCTION builderad_v2_business_intent_write(p_session text,p_business uuid,p_command uuid,p_value jsonb,p_confirmations jsonb,p_context bigint,p_intent bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;snapshot jsonb;payload bytea;result jsonb;h public.business_context_heads;prior_h public.business_context_heads;
 r public.business_current_intents;prior public.business_current_intents;e public.business_intent_transitions;next_value jsonb;changed boolean;
BEGIN
 IF p_context IS NULL OR p_context<0 OR p_intent IS NULL OR p_intent<0 OR coalesce(octet_length(p_value::text),0)+coalesce(octet_length(p_confirmations::text),0)>81920
 THEN RAISE EXCEPTION 'Bounded declaration and expected versions required' USING ERRCODE='22023'; END IF;
 next_value:=public.builderad_current_intent_normalize(nullif(p_value,'null'::jsonb));
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);snapshot:=builderad_private.require_business(b,actor,'business.intent.declare',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT * INTO prior_h FROM public.business_context_heads WHERE business_id=b FOR UPDATE;
 SELECT * INTO prior FROM public.business_current_intents WHERE business_id=b FOR UPDATE;
 PERFORM builderad_private.intent_confirmations(prior.value,next_value,p_confirmations);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('value',next_value,'confirmations',p_confirmations,'context',p_context,'intent',p_intent),'{"context":"I","intent":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.intent.declare',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.intent.declare',jsonb_build_object('business_id',b),snapshot);
 changed:=prior.value IS DISTINCT FROM next_value;r:=prior;h:=prior_h;
 IF changed THEN
  IF prior_h.context_version<>p_context OR prior.intent_revision<>p_intent THEN RAISE EXCEPTION 'Current Intent or context changed' USING ERRCODE='40001'; END IF;
  IF next_value->'horizon'<>'null'::jsonb AND next_value->'horizon' IS DISTINCT FROM prior.value->'horizon' THEN
   IF NOT p_confirmations @> '{"horizon":true}' THEN RAISE EXCEPTION 'Deadline and timezone confirmation required' USING ERRCODE='22023'; END IF;
   PERFORM public.builderad_current_intent_validate_horizon(next_value->'horizon');
  END IF;
  r.value:=next_value;r.intent_revision:=prior.intent_revision+1;r.last_event_id:=uuidv7();h.context_version:=prior_h.context_version+1;
  PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(prior_h),to_jsonb(h));UPDATE public.business_context_heads SET context_version=h.context_version WHERE business_id=b;
  PERFORM builderad_private.permit('public.business_current_intents','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.business_current_intents SET value=r.value,intent_revision=r.intent_revision,last_event_id=r.last_event_id WHERE business_id=b;
  e.event_id:=r.last_event_id;e.business_id:=b;e.before_revision:=prior.intent_revision;e.after_revision:=r.intent_revision;
  e.before_context_version:=prior_h.context_version;e.after_context_version:=h.context_version;e.before_value:=prior.value;e.after_value:=r.value;
  e.actor_id:=actor;e.command_id:=p_command;e.recorded_at:=clock_timestamp();e.origin_ref:=NULL;
  PERFORM builderad_private.permit('public.business_intent_transitions','insert',NULL,to_jsonb(e));INSERT INTO public.business_intent_transitions SELECT(e).*;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'value',r.value,'versions',jsonb_build_object('context',h.context_version,'intent',r.intent_revision));
 RETURN builderad_private.finish_fact(p_session,b,actor,p_selection,payload,result,changed);
END $$;