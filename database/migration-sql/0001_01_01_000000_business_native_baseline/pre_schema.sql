-- Business-native installation, composed from final accepted d5d93ed catalog.
SET LOCAL check_function_bodies=false;
GRANT CREATE,USAGE ON SCHEMA public TO builderad_scope_executor;
-- Name: builderad_private; Type: SCHEMA; Schema: -; Owner: builderad_owner
--

CREATE SCHEMA builderad_private;
GRANT USAGE,CREATE ON SCHEMA builderad_private TO builderad_scope_executor;


ALTER SCHEMA builderad_private OWNER TO builderad_owner;


-- Name: public; Type: SCHEMA; Schema: -; Owner: builderad_owner
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO builderad_owner;


-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;



-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner:
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';



-- Name: market_scope_identity(text, uuid, uuid, text, text, text, jsonb, bytea); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_scope_identity(p_domain text, p_boundary uuid, p_source uuid, p_resource text, p_operation text, p_purpose text, p_selection jsonb, p_digest bytea) RETURNS boolean
    LANGUAGE sql IMMUTABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT p_selection=builderad_private.canonical_data_selection(p_selection) AND CASE p_domain
 WHEN 'client' THEN p_digest=builderad_private.data_scope_digest(p_boundary,p_source,p_resource,p_operation,p_purpose,p_selection)
 WHEN 'market' THEN p_digest=builderad_private.market_scope_digest(p_source,p_resource,p_operation,p_purpose,p_selection)
 ELSE false END
$$;


ALTER FUNCTION builderad_private.market_scope_identity(p_domain text, p_boundary uuid, p_source uuid, p_resource text, p_operation text, p_purpose text, p_selection jsonb, p_digest bytea) OWNER TO builderad_scope_executor;


-- Name: builderad_advertiser_goals_normalize(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_advertiser_goals_normalize(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE catalog jsonb := '{"sales":["actual_sales","purchasing_customers","sales_value","existing_customer_purchases"],"customers":["serious_inquiries","closer_to_purchase","fewer_unsuitable_inquiries","inquiry_sales_conversion"],"growth":["new_customer_groups","new_markets","new_needs","new_offerings"],"efficiency":["better_results","less_unproductive_spend","profitable_ad_sales","worthwhile_allocation"]}';
 keys text[]; goal jsonb; result jsonb; chosen jsonb; normalized_results jsonb := '{}'; allowed jsonb;
BEGIN
 IF v IS NULL OR octet_length(v::text)>8192 OR jsonb_typeof(v) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Exact bounded preferences required' USING ERRCODE='22023';
 END IF;
 SELECT array_agg(k ORDER BY k COLLATE "C") INTO keys FROM jsonb_object_keys(v) k;
 IF keys IS DISTINCT FROM ARRAY['goals','priority','results']::text[]
  OR jsonb_typeof(v->'goals') IS DISTINCT FROM 'array'
  OR jsonb_typeof(v->'results') IS DISTINCT FROM 'object'
  OR jsonb_typeof(v->'priority') IS DISTINCT FROM 'string' THEN
  RAISE EXCEPTION 'Complete goals, priority and results required' USING ERRCODE='22023';
 END IF;
 IF jsonb_array_length(v->'goals') NOT BETWEEN 1 AND 4 THEN
  RAISE EXCEPTION 'Select at least one available goal' USING ERRCODE='22023';
 END IF;
 chosen := '[]';
 FOR goal IN SELECT value FROM jsonb_array_elements(v->'goals') LOOP
  IF jsonb_typeof(goal) IS DISTINCT FROM 'string' OR NOT catalog ? (goal#>>'{}') OR chosen @> jsonb_build_array(goal) THEN
   RAISE EXCEPTION 'Unique available goal IDs required' USING ERRCODE='22023';
  END IF;
  chosen := chosen || jsonb_build_array(goal);
 END LOOP;
 IF NOT chosen @> jsonb_build_array(v->'priority') THEN
  RAISE EXCEPTION 'Priority must be one selected goal' USING ERRCODE='22023';
 END IF;
 IF EXISTS (SELECT 1 FROM jsonb_object_keys(v->'results') k WHERE NOT chosen @> jsonb_build_array(k)) THEN
  RAISE EXCEPTION 'Removed goals cannot have active results' USING ERRCODE='22023';
 END IF;
 FOR goal IN SELECT value FROM jsonb_array_elements(chosen) LOOP
  allowed := catalog->(goal#>>'{}');
  IF v->'results' ? (goal#>>'{}') AND jsonb_typeof(v->'results'->(goal#>>'{}')) IS DISTINCT FROM 'array' THEN
   RAISE EXCEPTION 'Result ID lists required' USING ERRCODE='22023';
  END IF;
  allowed := '[]';
  FOR result IN SELECT value FROM jsonb_array_elements(coalesce(v->'results'->(goal#>>'{}'),'[]'::jsonb)) LOOP
   IF jsonb_typeof(result) IS DISTINCT FROM 'string' OR NOT (catalog->(goal#>>'{}')) @> jsonb_build_array(result)
    OR allowed @> jsonb_build_array(result) THEN
    RAISE EXCEPTION 'Unique results belonging to the selected goal required' USING ERRCODE='22023';
   END IF;
   allowed := allowed || jsonb_build_array(result);
  END LOOP;
  SELECT coalesce(jsonb_agg(value ORDER BY (value#>>'{}') COLLATE "C"),'[]'::jsonb) INTO allowed FROM jsonb_array_elements(allowed);
  normalized_results := normalized_results || jsonb_build_object(goal#>>'{}',allowed);
 END LOOP;
 SELECT jsonb_agg(value ORDER BY (value#>>'{}') COLLATE "C") INTO chosen FROM jsonb_array_elements(chosen);
 RETURN jsonb_build_object('goals',chosen,'priority',v->'priority','results',normalized_results);
END $$;


ALTER FUNCTION public.builderad_advertiser_goals_normalize(v jsonb) OWNER TO builderad_owner;


-- Name: builderad_current_intent_normalize(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_normalize(p_value jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
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
END $_$;


ALTER FUNCTION public.builderad_current_intent_normalize(p_value jsonb) OWNER TO builderad_owner;


-- Name: builderad_object_purpose_instant(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_object_purpose_instant(v jsonb) RETURNS timestamp with time zone
    LANGUAGE plpgsql STABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
BEGIN
 IF v='null'::jsonb THEN RETURN NULL; END IF;
 IF jsonb_typeof(v) IS DISTINCT FROM 'string' OR (v#>>'{}') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$' THEN
  RAISE EXCEPTION 'Timezone-qualified instant required' USING ERRCODE='22023';
 END IF;
 BEGIN RETURN (v#>>'{}')::timestamptz;
 EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN
  RAISE EXCEPTION 'Valid instant required' USING ERRCODE='22023'; END;
END $_$;


ALTER FUNCTION public.builderad_object_purpose_instant(v jsonb) OWNER TO builderad_owner;


-- Name: builderad_object_purpose_keys(jsonb, text[]); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
 SELECT coalesce(jsonb_typeof(v)='object' AND
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k)=
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM unnest(expected) k),false)
$$;


ALTER FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) OWNER TO builderad_owner;


-- Name: builderad_object_purpose_normalize(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_object_purpose_normalize(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE field text; item jsonb; seen jsonb:='[]'; tuples jsonb; p jsonb; r jsonb;
BEGIN
 IF v IS NULL OR octet_length(v::text)>131072 OR NOT builderad_object_purpose_keys(v,
 ARRAY['meaning','qualifications','phase','included_objects','excluded_objects','provenance','dtm','intent','end_condition','limitations','conflicts']) THEN
  RAISE EXCEPTION 'Complete bounded Purpose snapshot required' USING ERRCODE='22023'; END IF;
 IF NOT builderad_object_purpose_text(v->'meaning',4000,true) THEN RAISE EXCEPTION 'Confirmed meaning required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['qualifications','end_condition','limitations','phase'] LOOP
  IF NOT builderad_object_purpose_text(v->field,CASE WHEN field='phase' THEN 80 ELSE 4000 END) THEN
   RAISE EXCEPTION 'Bounded text or null required' USING ERRCODE='22023'; END IF;
 END LOOP;
 FOREACH field IN ARRAY ARRAY['included_objects','excluded_objects'] LOOP
  tuples:=v->field;
  IF jsonb_typeof(tuples) IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Finite object list required' USING ERRCODE='22023'; END IF;
  IF jsonb_array_length(tuples)>50 OR (field='included_objects' AND jsonb_array_length(tuples)=0) THEN
   RAISE EXCEPTION 'Bounded finite membership required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(tuples) LOOP
   IF NOT builderad_object_purpose_keys(item,ARRAY['provider','account_namespace','account_id','object_kind','object_id','incarnation']) THEN
    RAISE EXCEPTION 'Exact opaque object tuple required' USING ERRCODE='22023'; END IF;
   IF EXISTS (SELECT 1 FROM jsonb_each(item) e WHERE NOT builderad_object_purpose_text(e.value,180,e.key<>'incarnation')) THEN
    RAISE EXCEPTION 'Bounded case-preserved opaque strings required' USING ERRCODE='22023'; END IF;
   IF seen @> jsonb_build_array(item) THEN RAISE EXCEPTION 'Duplicate or included/excluded tuple' USING ERRCODE='22023'; END IF;
   seen:=seen||jsonb_build_array(item);
  END LOOP;
  SELECT coalesce(jsonb_agg(value ORDER BY value::text COLLATE "C"),'[]') INTO tuples FROM jsonb_array_elements(tuples);
  v:=jsonb_set(v,ARRAY[field],tuples);
 END LOOP;
 p:=v->'provenance';
 IF NOT builderad_object_purpose_keys(p,ARRAY['speaker','statement_at','source','identity_basis','authority_basis','historical_support','effective_basis']) THEN
  RAISE EXCEPTION 'Structured attribution required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['speaker','statement_at','source','identity_basis','authority_basis','historical_support','effective_basis'] LOOP
  IF NOT builderad_object_purpose_text(p->field,4000,field IN ('source','identity_basis','authority_basis','effective_basis')) THEN
   RAISE EXCEPTION 'Bounded provenance required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF jsonb_typeof(v->'conflicts') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Declared conflict list required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(v->'conflicts')>50 OR EXISTS (SELECT 1 FROM jsonb_array_elements(v->'conflicts') e WHERE NOT builderad_object_purpose_text(e,4000,true)) THEN
  RAISE EXCEPTION 'Bounded explicitly declared conflicts required' USING ERRCODE='22023'; END IF;
 r:=v->'dtm';
 IF r<>'null'::jsonb THEN
  IF NOT builderad_object_purpose_keys(r,ARRAY['mode','version','countries','subset','statement']) OR
    jsonb_typeof(r->'mode') IS DISTINCT FROM 'string' OR r->>'mode' NOT IN ('historical_attestation','current_alignment','current_dependency') OR
    jsonb_typeof(r->'version') IS DISTINCT FROM 'number' OR (r->>'version') !~ '^\d{1,18}$' OR
    NOT builderad_object_purpose_text(r->'statement',4000,true) OR
    jsonb_typeof(r->'countries') IS DISTINCT FROM 'array' OR jsonb_typeof(r->'subset') IS DISTINCT FROM 'array' THEN
   RAISE EXCEPTION 'Exact declared DTM relation required' USING ERRCODE='22023'; END IF;
  FOREACH field IN ARRAY ARRAY['countries','subset'] LOOP
   tuples:=r->field;
   IF jsonb_array_length(tuples)>249 OR EXISTS (SELECT 1 FROM jsonb_array_elements(tuples) e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~ '^[A-Z]{2}$') OR
    (SELECT count(*) FROM jsonb_array_elements(tuples))<>(SELECT count(DISTINCT value) FROM jsonb_array_elements(tuples)) THEN
    RAISE EXCEPTION 'Distinct country code list required' USING ERRCODE='22023'; END IF;
   SELECT coalesce(jsonb_agg(value ORDER BY value::text COLLATE "C"),'[]') INTO tuples FROM jsonb_array_elements(tuples);
   r:=jsonb_set(r,ARRAY[field],tuples);
  END LOOP;
  IF NOT ((r->'countries') @> (r->'subset')) THEN RAISE EXCEPTION 'Explicit subset outside reference' USING ERRCODE='22023'; END IF;
  v:=jsonb_set(v,'{dtm}',r);
 END IF;
 r:=v->'intent';
 IF r<>'null'::jsonb AND (NOT builderad_object_purpose_keys(r,ARRAY['version','value','statement']) OR
  jsonb_typeof(r->'version') IS DISTINCT FROM 'number' OR (r->>'version') !~ '^\d{1,18}$' OR
  jsonb_typeof(r->'value') IS DISTINCT FROM 'object' OR NOT builderad_object_purpose_text(r->'statement',4000,true)) THEN
  RAISE EXCEPTION 'Exact explicit Intent relationship required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $_$;


ALTER FUNCTION public.builderad_object_purpose_normalize(v jsonb) OWNER TO builderad_owner;


-- Name: builderad_object_purpose_text(jsonb, integer, boolean); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean DEFAULT false) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
 SELECT coalesce((NOT required AND v='null'::jsonb) OR
  (jsonb_typeof(v)='string' AND char_length(v#>>'{}')<=maximum AND
   char_length(btrim(v#>>'{}',E' \t\r\n'))>0),false)
$$;


ALTER FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean) OWNER TO builderad_owner;
