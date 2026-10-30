CREATE FUNCTION builderad_private.frame(tag text,payload text) RETURNS text
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp
AS $$ SELECT tag||octet_length(convert_to(payload,'UTF8'))::text||':'||payload $$;

CREATE FUNCTION builderad_private.cb1(value jsonb,depth integer DEFAULT 0) RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE kind text; payload text:=''; item record; digits text; exponent integer; negative boolean; fraction integer;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'CB1 nesting limit' USING ERRCODE='22023'; END IF;
 IF value IS NULL OR value='null'::jsonb THEN RETURN 'N0:'; END IF;
 kind:=jsonb_typeof(value);
 IF kind='string' THEN
  payload:=value#>>'{}';
  IF octet_length(payload)>65536 THEN RAISE EXCEPTION 'CB1 string limit' USING ERRCODE='22023'; END IF;
  RETURN builderad_private.frame('S',payload);
 ELSIF kind='boolean' THEN RETURN builderad_private.frame('B',CASE WHEN value='true'::jsonb THEN '1' ELSE '0' END);
 ELSIF kind='number' THEN
  payload:=(value#>>'{}')::numeric::text;
  negative:=left(payload,1)='-'; payload:=ltrim(payload,'-');
  fraction:=CASE WHEN position('.' IN payload)>0 THEN length(split_part(payload,'.',2)) ELSE 0 END;
  digits:=ltrim(replace(payload,'.',''),'0'); exponent:=-fraction;
  IF digits='' THEN RETURN 'D3:0e0'; END IF;
  WHILE right(digits,1)='0' LOOP digits:=left(digits,length(digits)-1); exponent:=exponent+1; END LOOP;
  RETURN builderad_private.frame('D',CASE WHEN negative THEN '-' ELSE '' END||digits||'e'||exponent::text);
 ELSIF kind='array' THEN
  payload:=builderad_private.frame('I',jsonb_array_length(value)::text);
  FOR item IN SELECT v FROM jsonb_array_elements(value) AS x(v) LOOP
   payload:=payload||builderad_private.cb1(item.v,depth+1);
  END LOOP;
  RETURN builderad_private.frame('A',payload);
 ELSIF kind='object' THEN
  payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(value)));
  FOR item IN SELECT key,v FROM jsonb_each(value) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
   IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'CB1 schema key required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.frame('S',item.key)||builderad_private.cb1(item.v,depth+1);
  END LOOP;
  RETURN builderad_private.frame('O',payload);
 END IF;
 RAISE EXCEPTION 'Unsupported CB1 value' USING ERRCODE='22023';
END $$;

CREATE FUNCTION builderad_private.atom(tag text,value jsonb) RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE v text;
BEGIN
 IF value IS NULL OR value='null'::jsonb THEN RETURN 'N0:'; END IF;
 v:=value#>>'{}';
 IF tag='D' THEN RETURN builderad_private.cb1(to_jsonb(v::numeric));
 ELSIF tag='I' THEN v:=(v::bigint)::text;
 ELSIF tag='U' THEN v:=(v::uuid)::text;
 ELSIF tag='T' THEN
  IF NOT isfinite(v::timestamptz) THEN RAISE EXCEPTION 'Finite CB1 instant required' USING ERRCODE='22023'; END IF;
  v:=to_char(v::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"');
 ELSIF tag='X' THEN v:=lower(CASE WHEN left(v,2)='\x' THEN substring(v FROM 3) ELSE v END);
  IF v !~ '^([0-9a-f]{2})*$' THEN RAISE EXCEPTION 'CB1 bytea required' USING ERRCODE='22023'; END IF;
 ELSIF tag='S' THEN
  IF octet_length(v)>65536 THEN RAISE EXCEPTION 'CB1 string limit' USING ERRCODE='22023'; END IF;
 ELSE RETURN builderad_private.cb1(value); END IF;
 RETURN builderad_private.frame(tag,v);
END $$;

-- The schema is supplied only by protected writers, never by a caller's JSON.
CREATE FUNCTION builderad_private.fields(value jsonb,tags jsonb DEFAULT '{}') RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE payload text; item record;
BEGIN
 IF jsonb_typeof(tags)='string' THEN RETURN builderad_private.atom(tags#>>'{}',value); END IF;
 IF jsonb_typeof(value)='array' AND jsonb_typeof(tags)='array' THEN
  IF jsonb_array_length(value)<>jsonb_array_length(tags) THEN RAISE EXCEPTION 'Exact CB1 array schema required' USING ERRCODE='22023'; END IF;
  payload:=builderad_private.frame('I',jsonb_array_length(value)::text);
  FOR item IN SELECT v,ord FROM jsonb_array_elements(value) WITH ORDINALITY AS x(v,ord) LOOP
   payload:=payload||builderad_private.fields(item.v,tags->(item.ord::integer-1));
  END LOOP;
  RETURN builderad_private.frame('A',payload);
 END IF;
 IF jsonb_typeof(value)<>'object' OR value='null'::jsonb THEN RETURN builderad_private.cb1(value); END IF;
 payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(value)));
 FOR item IN SELECT key,v FROM jsonb_each(value) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
  IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'CB1 schema key required' USING ERRCODE='22023'; END IF;
  payload:=payload||builderad_private.frame('S',item.key)||
   CASE WHEN tags ? item.key THEN builderad_private.fields(item.v,tags->item.key) ELSE builderad_private.cb1(item.v) END;
 END LOOP;
 RETURN builderad_private.frame('O',payload);
END $$;
CREATE FUNCTION builderad_private.bounded(value jsonb) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE nodes integer; deepest integer;
BEGIN
 IF octet_length(value::text)>2097152 THEN RAISE EXCEPTION 'CB1 payload limit' USING ERRCODE='22023'; END IF;
 WITH RECURSIVE walk(v,d) AS (
  SELECT value,0 UNION ALL
  SELECT children.v,walk.d+1 FROM walk CROSS JOIN LATERAL (
   SELECT x.v FROM jsonb_each(CASE WHEN jsonb_typeof(walk.v)='object' THEN walk.v ELSE '{}'::jsonb END) AS x(k,v)
   UNION ALL SELECT x.v FROM jsonb_array_elements(CASE WHEN jsonb_typeof(walk.v)='array' THEN walk.v ELSE '[]'::jsonb END) AS x(v)
  ) children WHERE walk.d<33
 ) SELECT count(*),max(d) INTO nodes,deepest FROM (SELECT * FROM walk LIMIT 8194) bounded;
 IF nodes>8192 OR deepest>32 THEN RAISE EXCEPTION 'CB1 structure limit' USING ERRCODE='22023'; END IF;
END $$;

CREATE FUNCTION builderad_private.digest(domain text,value jsonb,tags jsonb DEFAULT '{}') RETURNS bytea
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE encoded text;
BEGIN
 IF domain NOT IN ('data-use-scope:1','row-permit:1','authority-selection:1','dependency-set:1','command-payload:1','lineage-manifest:1')
 THEN RAISE EXCEPTION 'CB1 domain required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(value); encoded:=builderad_private.fields(value,tags);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'CB1 encoded limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/'||domain,'UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;

CREATE FUNCTION builderad_private.row_image(relation regclass,value jsonb) RETURNS text
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE tags jsonb; expected text[]; actual text[];
BEGIN
 IF value IS NULL THEN RETURN 'N0:'; END IF;
 value:=value-'runtime_lock_marker'-'scope_lock_marker';
 SELECT array_agg(a.attname::text ORDER BY convert_to(a.attname::text,'UTF8')),
  coalesce(jsonb_object_agg(a.attname,
   CASE t.typname WHEN 'int2' THEN 'I' WHEN 'int4' THEN 'I' WHEN 'int8' THEN 'I'
    WHEN 'uuid' THEN 'U' WHEN 'timestamptz' THEN 'T' WHEN 'bytea' THEN 'X' WHEN 'xid8' THEN 'S' ELSE 'J' END),'{}')
 INTO expected,tags
 FROM pg_attribute a JOIN pg_type t ON t.oid=a.atttypid WHERE a.attrelid=relation AND a.attnum>0 AND NOT a.attisdropped
 AND a.attname NOT IN ('runtime_lock_marker','scope_lock_marker');
 SELECT array_agg(k ORDER BY convert_to(k,'UTF8')) INTO actual FROM jsonb_object_keys(value) k;
 IF actual IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Complete controlled row image required' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.fields(value,tags);
END $$;
