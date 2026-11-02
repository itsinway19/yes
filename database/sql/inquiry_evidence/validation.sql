CREATE FUNCTION builderad_private.inquiry_keys(v jsonb,keys text[]) RETURNS boolean
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_typeof(v)='object' AND ARRAY(SELECT jsonb_object_keys(v) ORDER BY 1)=ARRAY(SELECT unnest(keys) ORDER BY 1)
$$;
CREATE FUNCTION builderad_private.inquiry_count(v jsonb) RETURNS boolean
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT v='null'::jsonb OR(jsonb_typeof(v)='number' AND(v#>>'{}')~'^[0-9]{1,13}$' AND(v#>>'{}')::numeric<=1000000000000)
$$;
CREATE FUNCTION builderad_private.inquiry_value(v jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE c jsonb;o jsonb;a jsonb;i jsonb;t text;moment timestamptz;
BEGIN
 IF NOT coalesce(builderad_private.inquiry_keys(v,ARRAY['cohort','observation','cost','inquiries','acceptance']),false) OR octet_length(v::text)>8192 THEN
  RAISE EXCEPTION 'Bounded declaration fields required' USING ERRCODE='22023'; END IF;
 c:=v->'cohort';o:=v->'observation';a:=v->'acceptance';i:=v->'inquiries';
 IF NOT coalesce(builderad_private.inquiry_keys(c,ARRAY['channel_kind','definition_version','country_code','population','counting_policy']),false)
 OR jsonb_typeof(c->'channel_kind')<>'string' OR c->>'channel_kind' NOT IN('form','call','message','crm','other')
 OR c->>'population' IS DISTINCT FROM 'recorded_inquiries' OR c->>'counting_policy' IS DISTINCT FROM 'distinct_inquiries'
 OR jsonb_typeof(c->'definition_version') IS DISTINCT FROM 'number' OR coalesce(c->>'definition_version','')!~'^[1-9][0-9]{0,8}$'
 OR NOT(c->'country_code'='null'::jsonb OR(jsonb_typeof(c->'country_code')='string' AND c->>'country_code'~'^[A-Z]{2}$'))
 OR NOT coalesce(builderad_private.inquiry_keys(o,ARRAY['period_start','period_end','as_of','timezone']),false)
 OR NOT EXISTS(SELECT 1 FROM pg_catalog.pg_timezone_names WHERE name=o->>'timezone')
 THEN RAISE EXCEPTION 'Typed cohort and observation required' USING ERRCODE='22023'; END IF;
 FOREACH t IN ARRAY ARRAY['period_start','period_end','as_of'] LOOP
  IF jsonb_typeof(o->t) IS DISTINCT FROM 'string' OR(o->>t)!~'(Z|[+-][0-9]{2}:[0-9]{2})$' THEN RAISE EXCEPTION 'Explicit observation instant required' USING ERRCODE='22023'; END IF;
  moment:=(o->>t)::timestamptz;
 END LOOP;
 IF (o->>'period_end')::timestamptz<=(o->>'period_start')::timestamptz OR(o->>'as_of')::timestamptz<(o->>'period_start')::timestamptz OR(o->>'as_of')::timestamptz>clock_timestamp()
 THEN RAISE EXCEPTION 'Invalid observation window' USING ERRCODE='22023'; END IF;
 IF v->'cost'<>'null'::jsonb THEN
  c:=v->'cost';
  IF NOT coalesce(builderad_private.inquiry_keys(c,ARRAY['amount','currency','precision','cost_basis','allocation_basis']),false)
  OR jsonb_typeof(c->'amount') IS DISTINCT FROM 'string' OR coalesce(c->>'amount','')!~'^(0|[1-9][0-9]{0,11})([.][0-9]{1,8})?$'
  OR jsonb_typeof(c->'currency') IS DISTINCT FROM 'string' OR coalesce(c->>'currency','')!~'^[A-Z]{3}$'
  OR jsonb_typeof(c->'precision') IS DISTINCT FROM 'number' OR coalesce(c->>'precision','')!~'^[0-8]$' OR length(split_part(c->>'amount','.',2))>(c->>'precision')::integer
  OR coalesce(c->>'cost_basis','') NOT IN('advertising_spend','declared_cohort_cost') OR coalesce(c->>'allocation_basis','') NOT IN('whole_cohort','declared_attribution')
  THEN RAISE EXCEPTION 'Typed declared cost required' USING ERRCODE='22023'; END IF;
 END IF;
 IF i<>'null'::jsonb THEN
  IF NOT coalesce(builderad_private.inquiry_keys(i,ARRAY['count','coverage']),false) OR NOT coalesce(builderad_private.inquiry_count(i->'count'),false)
  OR coalesce(i->>'coverage','') NOT IN('complete','partial','unknown') OR(i->>'coverage'='complete' AND i->'count'='null'::jsonb)
  THEN RAISE EXCEPTION 'Typed inquiry count required' USING ERRCODE='22023'; END IF;
 END IF;
 IF a<>'null'::jsonb THEN
  IF NOT coalesce(builderad_private.inquiry_keys(a,ARRAY['count','pending_count','criterion_code','criterion_version','criterion_definition','cutoff','maturity','coverage']),false)
  OR NOT coalesce(builderad_private.inquiry_count(a->'count'),false) OR NOT coalesce(builderad_private.inquiry_count(a->'pending_count'),false)
  OR coalesce(a->>'criterion_code','') NOT IN('operator_defined','within_service_area','response_ready')
  OR jsonb_typeof(a->'criterion_version') IS DISTINCT FROM 'number' OR coalesce(a->>'criterion_version','')!~'^[1-9][0-9]{0,8}$'
  OR coalesce(a->>'maturity','') NOT IN('mature','pending','unknown') OR coalesce(a->>'coverage','') NOT IN('complete','partial','unknown')
  OR jsonb_typeof(a->'cutoff') IS DISTINCT FROM 'string' OR(a->>'cutoff')!~'(Z|[+-][0-9]{2}:[0-9]{2})$'
  THEN RAISE EXCEPTION 'Typed declared acceptance required' USING ERRCODE='22023'; END IF;
  -- Abstract rule only: no contact identifiers, markup, executable expressions or external references.
  -- This is source-asserted text, not an automatic guarantee of semantic truth or PII detection.
  IF a->>'criterion_code'='operator_defined' THEN
   IF jsonb_typeof(a->'criterion_definition') IS DISTINCT FROM 'string'
    OR char_length(a->>'criterion_definition') NOT BETWEEN 8 AND 500
    OR btrim(a->>'criterion_definition')<>a->>'criterion_definition'
    OR (a->>'criterion_definition') ~ '[[:cntrl:]<>@{}\[\]\\]'
    OR (a->>'criterion_definition') ~* '(https?[:/]|www[.]|javascript:|data:|=>|[[:digit:]][[:digit:] .()+-]{5,}[[:digit:]])'
   THEN RAISE EXCEPTION 'Bounded abstract criterion definition required; no personal data, code or external references' USING ERRCODE='22023'; END IF;
  ELSIF a->'criterion_definition' IS DISTINCT FROM 'null'::jsonb THEN
   RAISE EXCEPTION 'Fixed criterion cannot be redefined' USING ERRCODE='22023';
  END IF;
  IF(a->>'cutoff')::timestamptz>(o->>'as_of')::timestamptz OR(a->>'cutoff')::timestamptz<(o->>'period_start')::timestamptz
  OR(a->>'maturity'='mature' AND coalesce((a->>'pending_count')::numeric,0)>0)
  OR(a->>'coverage'='complete' AND a->'count'='null'::jsonb)
  OR(i<>'null'::jsonb AND i->'count'<>'null'::jsonb AND a->'count'<>'null'::jsonb AND(a->>'count')::numeric>(i->>'count')::numeric)
  THEN RAISE EXCEPTION 'Inconsistent declared acceptance' USING ERRCODE='22023'; END IF;
 END IF;
 IF v->'cost'='null'::jsonb AND i='null'::jsonb AND a='null'::jsonb THEN RAISE EXCEPTION 'At least one declared axis required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $$;
