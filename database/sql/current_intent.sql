-- Durable invariants are derived exclusively from stored values, never tzdata/catalogs.
CREATE FUNCTION builderad_current_intent_normalize(p_value jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=public,pg_temp AS $$
DECLARE v jsonb:=p_value; keys text[]; item text; raw text; normalized text;
 path text[]; limit_chars integer; y integer; m integer; d integer; days integer;
BEGIN
 IF p_value IS NULL THEN RETURN NULL; END IF;
 IF octet_length(p_value::text)>40960 OR jsonb_typeof(v)<>'object' THEN
  RAISE EXCEPTION 'Bounded declaration object required' USING ERRCODE='22023';
 END IF;
 SELECT array_agg(k ORDER BY k COLLATE "C") INTO keys FROM jsonb_object_keys(v) k;
 IF keys IS DISTINCT FROM ARRAY['description','direction','horizon','outcome','quantity','scope']::text[] THEN
  RAISE EXCEPTION 'Complete exact declaration shape required' USING ERRCODE='22023';
 END IF;
 IF jsonb_typeof(v->'direction')<>'string' OR v->>'direction' NOT IN
  ('increase_value','preserve_value','improve_efficiency_preserving_value','validate_business_direction')
  OR jsonb_typeof(v->'scope')<>'string' OR v->>'scope'<>'business_wide' THEN
  RAISE EXCEPTION 'Explicit bounded direction and business-wide scope required' USING ERRCODE='22023';
 END IF;
 IF jsonb_typeof(v->'outcome')<>'object' THEN
  RAISE EXCEPTION 'Confirmed outcome value required' USING ERRCODE='22023';
 END IF;
 SELECT array_agg(k ORDER BY k COLLATE "C") INTO keys FROM jsonb_object_keys(v->'outcome') k;
 IF keys IS DISTINCT FROM ARRAY['clarification','count_unit','meaning']::text[] THEN
  RAISE EXCEPTION 'Complete exact outcome shape required' USING ERRCODE='22023';
 END IF;
 FOREACH item IN ARRAY ARRAY['description','meaning','clarification','count_unit'] LOOP
  path:=CASE WHEN item='description' THEN ARRAY[item] ELSE ARRAY['outcome',item] END;
  limit_chars:=CASE WHEN item='count_unit' THEN 120 ELSE 2000 END;
  IF jsonb_typeof(v#>path) NOT IN ('null','string') OR
   (item='meaning' AND jsonb_typeof(v#>path)<>'string') THEN
   RAISE EXCEPTION 'Text or permitted null required' USING ERRCODE='22023';
  END IF;
  raw:=v#>>path;
  IF char_length(raw)>limit_chars THEN
   RAISE EXCEPTION 'Declaration text exceeds technical input limit' USING ERRCODE='22023';
  END IF;
  normalized:=nullif(btrim(raw,E' \t\r\n'),'');
  IF item='meaning' AND normalized IS NULL THEN
   RAISE EXCEPTION 'Nonempty outcome meaning required' USING ERRCODE='22023';
  END IF;
  v:=jsonb_set(v,path,coalesce(to_jsonb(normalized),'null'::jsonb));
 END LOOP;
 IF jsonb_typeof(v->'quantity')<>'null' THEN
  raw:=v->>'quantity';
  IF jsonb_typeof(v->'quantity')<>'string' OR char_length(raw)>120 OR raw !~ '^[0-9]+$' THEN
   RAISE EXCEPTION 'Bounded positive integer digit string required' USING ERRCODE='22023';
  END IF;
  normalized:=ltrim(raw,'0');
  IF normalized='' OR v#>>'{outcome,count_unit}' IS NULL THEN
   RAISE EXCEPTION 'Positive quantity and semantic count unit required' USING ERRCODE='22023';
  END IF;
  v:=jsonb_set(v,'{quantity}',to_jsonb(normalized));
 END IF;
 IF jsonb_typeof(v->'horizon')<>'null' THEN
  IF jsonb_typeof(v->'horizon')<>'object' THEN
   RAISE EXCEPTION 'Complete date and timezone pair required' USING ERRCODE='22023';
  END IF;
  SELECT array_agg(k ORDER BY k COLLATE "C") INTO keys FROM jsonb_object_keys(v->'horizon') k;
  IF keys IS DISTINCT FROM ARRAY['deadline_date','timezone']::text[] OR
   jsonb_typeof(v#>'{horizon,deadline_date}')<>'string' OR jsonb_typeof(v#>'{horizon,timezone}')<>'string' THEN
   RAISE EXCEPTION 'Complete date and timezone pair required' USING ERRCODE='22023';
  END IF;
  raw:=v#>>'{horizon,timezone}';
  IF char_length(raw)>120 OR raw !~ '^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)*$' THEN
   RAISE EXCEPTION 'Bounded timezone lexical form required' USING ERRCODE='22023';
  END IF;
  raw:=v#>>'{horizon,deadline_date}';
  IF raw !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
   RAISE EXCEPTION 'Concrete Gregorian date required' USING ERRCODE='22023';
  END IF;
  y:=substring(raw,1,4)::integer; m:=substring(raw,6,2)::integer; d:=substring(raw,9,2)::integer;
  IF y<1 OR m NOT BETWEEN 1 AND 12 OR d<1 THEN
   RAISE EXCEPTION 'Valid Gregorian calendar structure required' USING ERRCODE='22023';
  END IF;
  days:=CASE WHEN m=2 THEN CASE WHEN y%400=0 OR (y%4=0 AND y%100<>0) THEN 29 ELSE 28 END
    WHEN m IN (4,6,9,11) THEN 30 ELSE 31 END;
  IF d>days THEN RAISE EXCEPTION 'Valid Gregorian calendar structure required' USING ERRCODE='22023'; END IF;
 END IF;
 IF octet_length(v::text)>40960 THEN
  RAISE EXCEPTION 'Declaration exceeds technical storage budget' USING ERRCODE='22023';
 END IF;
 RETURN v;
END $$;
REVOKE ALL ON FUNCTION builderad_current_intent_normalize(jsonb) FROM PUBLIC,builderad_app,builderad_authority;

ALTER TABLE workspaces ADD COLUMN current_intent jsonb NULL;
ALTER TABLE workspaces ADD CONSTRAINT current_intent_canonical CHECK
 (current_intent IS NULL OR current_intent=builderad_current_intent_normalize(current_intent));
CREATE TABLE business_current_intent_events (
 id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
 workspace_id bigint NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 before_version bigint NOT NULL CHECK (before_version>=0),
 after_version bigint NOT NULL CHECK (after_version=before_version+1),
 before_value jsonb NULL CHECK (before_value IS NULL OR before_value=builderad_current_intent_normalize(before_value)),
 after_value jsonb NULL CHECK (after_value IS NULL OR after_value=builderad_current_intent_normalize(after_value)),
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE (workspace_id,after_version),
 CHECK (before_value IS DISTINCT FROM after_value)
);
ALTER TABLE business_current_intent_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE business_current_intent_events FORCE ROW LEVEL SECURITY;
CREATE POLICY current_intent_events_owner ON business_current_intent_events TO builderad_owner USING (true) WITH CHECK (true);
CREATE POLICY current_intent_events_read ON business_current_intent_events FOR SELECT TO builderad_app USING
 (workspace_id IN (SELECT c.workspace_id FROM builderad_signed_context() c WHERE c.phase='workspace'));
REVOKE ALL ON business_current_intent_events FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON SEQUENCE business_current_intent_events_id_seq FROM PUBLIC,builderad_app,builderad_authority;
GRANT SELECT ON business_current_intent_events TO builderad_app;
CREATE FUNCTION builderad_current_intent_event_immutable() RETURNS trigger
LANGUAGE plpgsql SET search_path=public,pg_temp AS $$
BEGIN RAISE EXCEPTION 'Current Intent journal is immutable' USING ERRCODE='23514'; END $$;
REVOKE ALL ON FUNCTION builderad_current_intent_event_immutable() FROM PUBLIC,builderad_app,builderad_authority;
CREATE TRIGGER current_intent_event_immutable BEFORE UPDATE OR DELETE ON business_current_intent_events
 FOR EACH ROW EXECUTE FUNCTION builderad_current_intent_event_immutable();

-- Environmental acceptance belongs only to controlled writes and option discovery.
-- Never invoke these functions from durable row constraints or projections.
CREATE FUNCTION builderad_current_intent_timezone_name_allowed(p_zone text) RETURNS boolean
LANGUAGE sql IMMUTABLE SET search_path=public,pg_temp AS $$
 SELECT coalesce(length(p_zone)<=120
  AND (p_zone='UTC' OR p_zone ~ '^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)+$')
  AND p_zone !~ '^(posix|right)/',false)
$$;
REVOKE ALL ON FUNCTION builderad_current_intent_timezone_name_allowed(text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_current_intent_timezone_name_allowed(text) TO builderad_app;

-- One policy, separate single-name and set-wise catalog execution shapes.
CREATE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean
LANGUAGE plpgsql VOLATILE SET search_path=public,pg_temp SET TimeZone='UTC' AS $$
BEGIN
 IF NOT builderad_current_intent_timezone_name_allowed(p_zone) THEN RETURN false; END IF;
 -- A leading colon requires a timezone file, without POSIX-spec fallback.
 -- PostgreSQL returns its canonical filename, preserving exact-name acceptance.
 -- The function-local GUC scope restores the caller's timezone on every exit.
 RETURN set_config('TimeZone',':'||p_zone,true)=p_zone;
EXCEPTION WHEN invalid_parameter_value THEN RETURN false;
END;
$$;
REVOKE ALL ON FUNCTION builderad_current_intent_timezone_supported(text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_current_intent_timezone_supported(text) TO builderad_app;

CREATE FUNCTION builderad_current_intent_validate_horizon(p_horizon jsonb) RETURNS void
LANGUAGE plpgsql STABLE SET search_path=public,pg_temp AS $$
DECLARE zone_name text; civil_date date;
BEGIN
 zone_name:=p_horizon->>'timezone';
 IF NOT builderad_current_intent_timezone_supported(zone_name) THEN
  RAISE EXCEPTION 'Recognized named timezone required' USING ERRCODE='22023';
 END IF;
 -- Lexical/Gregorian shape has already been statically validated. A midnight
 -- shift within the same date is valid; a skipped civil date is not.
 civil_date:=(p_horizon->>'deadline_date')::date;
 IF ((civil_date::timestamp AT TIME ZONE zone_name) AT TIME ZONE zone_name)::date<>civil_date THEN
  RAISE EXCEPTION 'Selected civil date does not exist in this timezone' USING ERRCODE='22023';
 END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_current_intent_validate_horizon(jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_current_intent_write(p_workspace bigint,p_session text,p_value jsonb,p_confirmations jsonb,p_version bigint)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint; current_version bigint; prior_value jsonb; next_value jsonb; event_time timestamptz;
BEGIN
 PERFORM builderad_business_context_require_prepared(p_workspace);
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 SELECT business_context_version,current_intent INTO current_version,prior_value
  FROM workspaces WHERE id=p_workspace FOR UPDATE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=actor AND workspace_id=p_workspace
  AND action='business.context.manage' AND scope_kind='workspace' FOR SHARE;
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 IF NOT EXISTS (SELECT 1 FROM security_action_grants g JOIN principal_lifecycles l ON l.user_id=g.subject_user_id
  WHERE g.subject_user_id=actor AND g.subject_generation=l.generation AND g.workspace_id=p_workspace
  AND g.action='business.context.manage' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Explicit business context mutation grant required' USING ERRCODE='42501';
 END IF;
 IF p_version IS NULL OR p_version<0 OR
  coalesce(octet_length(p_value::text),0)+coalesce(octet_length(p_confirmations::text),0)>81920 THEN
  RAISE EXCEPTION 'Nonnegative revision and bounded command required' USING ERRCODE='22023';
 END IF;
 IF p_confirmations IS NULL OR jsonb_typeof(p_confirmations)<>'object' THEN
  RAISE EXCEPTION 'Explicit boolean confirmation object required' USING ERRCODE='22023';
 END IF;
 IF EXISTS (SELECT 1 FROM jsonb_each(p_confirmations) c WHERE c.key NOT IN
  ('declaration','business_wide','outcome','acceptance_clear','clarification_required','quantity','horizon')
  OR jsonb_typeof(c.value)<>'boolean') OR NOT coalesce(p_confirmations @> '{"declaration":true}',false) THEN
  RAISE EXCEPTION 'Recognized boolean declaration confirmations required' USING ERRCODE='22023';
 END IF;
 next_value:=builderad_current_intent_normalize(p_value);
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
 -- Equality is authorized, bounded and canonical, before the material stale check.
 IF next_value IS NOT DISTINCT FROM prior_value THEN
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 IF NOT EXISTS (SELECT 1 FROM security_action_grants g JOIN principal_lifecycles l ON l.user_id=g.subject_user_id
  WHERE g.subject_user_id=actor AND g.subject_generation=l.generation AND g.workspace_id=p_workspace
  AND g.action='business.context.manage' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Business context authority expired before no-op return' USING ERRCODE='42501';
 END IF;
  RETURN current_version;
 END IF;
 IF current_version<>p_version THEN
  RAISE EXCEPTION 'Business context changed; review before retry' USING ERRCODE='40001';
 END IF;
 IF next_value->'horizon'<>'null'::jsonb AND next_value->'horizon' IS DISTINCT FROM prior_value->'horizon' THEN
  IF NOT p_confirmations @> '{"horizon":true}' THEN
   RAISE EXCEPTION 'New deadline and timezone must be confirmed together' USING ERRCODE='22023';
  END IF;
  PERFORM builderad_current_intent_validate_horizon(next_value->'horizon');
 END IF;
 UPDATE workspaces SET current_intent=next_value,business_context_version=current_version+1,updated_at=clock_timestamp()
  WHERE id=p_workspace;
 -- Audit recording time, never commit time or ordering authority.
 event_time:=clock_timestamp();
 INSERT INTO business_current_intent_events(workspace_id,actor_user_id,before_version,after_version,before_value,after_value,occurred_at)
  VALUES(p_workspace,actor,current_version,current_version+1,prior_value,next_value,event_time);
 actor:=builderad_source_actor(p_workspace,p_session,false,false);
 IF NOT EXISTS (SELECT 1 FROM security_action_grants g JOIN principal_lifecycles l ON l.user_id=g.subject_user_id
  WHERE g.subject_user_id=actor AND g.subject_generation=l.generation AND g.workspace_id=p_workspace
  AND g.action='business.context.manage' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Business context authority expired before effect' USING ERRCODE='42501';
 END IF;
 RETURN current_version+1;
END $$;
REVOKE ALL ON FUNCTION builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint) TO builderad_app;
