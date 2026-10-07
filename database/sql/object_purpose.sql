-- Purpose-specific static validation. No provider rules, catalogs, inference or files.
CREATE FUNCTION builderad_object_purpose_keys(v jsonb, expected text[]) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
 SELECT coalesce(jsonb_typeof(v)='object' AND
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k)=
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM unnest(expected) k),false)
$$;
CREATE FUNCTION builderad_object_purpose_text(v jsonb, maximum integer, required boolean DEFAULT false) RETURNS boolean
LANGUAGE sql IMMUTABLE AS $$
 SELECT coalesce((NOT required AND v='null'::jsonb) OR
  (jsonb_typeof(v)='string' AND char_length(v#>>'{}')<=maximum AND
   char_length(btrim(v#>>'{}',E' \t\r\n'))>0),false)
$$;
CREATE FUNCTION builderad_object_purpose_instant(v jsonb) RETURNS timestamptz
LANGUAGE plpgsql STABLE SET search_path=public,pg_temp AS $$
BEGIN
 IF v='null'::jsonb THEN RETURN NULL; END IF;
 IF jsonb_typeof(v) IS DISTINCT FROM 'string' OR (v#>>'{}') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$' THEN
  RAISE EXCEPTION 'Timezone-qualified instant required' USING ERRCODE='22023';
 END IF;
 BEGIN RETURN (v#>>'{}')::timestamptz;
 EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN
  RAISE EXCEPTION 'Valid instant required' USING ERRCODE='22023'; END;
END $$;
CREATE FUNCTION builderad_object_purpose_normalize(v jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=public,pg_temp AS $$
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
END $$;
CREATE TABLE object_purposes (
 id uuid PRIMARY KEY DEFAULT uuidv7() CHECK (uuid_extract_version(id)=7),
 workspace_id bigint NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
 head_version bigint NOT NULL DEFAULT 0 CHECK (head_version>=0), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(workspace_id,id)
);
CREATE TABLE object_purpose_revisions (
 workspace_id bigint NOT NULL, purpose_id uuid NOT NULL, version bigint NOT NULL,
 revision_id uuid NOT NULL DEFAULT uuidv7() UNIQUE CHECK (uuid_extract_version(revision_id)=7),
 prior_version bigint, supersedes_revision_id uuid,
 operation text NOT NULL CHECK(operation IN ('declare','replace','correct','withdraw')),
 effective_from timestamptz NOT NULL, effective_until timestamptz,
 snapshot jsonb NOT NULL CHECK (snapshot=builderad_object_purpose_normalize(snapshot)),
 actor_user_id bigint NOT NULL REFERENCES users(id), actor_generation bigint NOT NULL,
 ownership_version bigint NOT NULL, confirmations jsonb NOT NULL, command_id uuid NOT NULL,
 reason text, affected_revision_ids jsonb NOT NULL, accepted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(workspace_id,purpose_id,version), UNIQUE(workspace_id,purpose_id,revision_id),
 CHECK(version>0 AND prior_version IS NOT DISTINCT FROM nullif(version-1,0)),
 CHECK((version=1 AND operation='declare' AND supersedes_revision_id IS NULL) OR (version>1 AND operation<>'declare' AND supersedes_revision_id IS NOT NULL)),
 CHECK(effective_until IS NULL OR effective_until>effective_from),
 FOREIGN KEY(workspace_id,purpose_id) REFERENCES object_purposes(workspace_id,id),
 FOREIGN KEY(workspace_id,purpose_id,supersedes_revision_id) REFERENCES object_purpose_revisions(workspace_id,purpose_id,revision_id)
);
CREATE TABLE object_purpose_command_receipts (
 workspace_id bigint NOT NULL, command_id uuid NOT NULL, actor_user_id bigint NOT NULL REFERENCES users(id),
 fingerprint text NOT NULL CHECK(fingerprint ~ '^[0-9a-f]{64}$'), result jsonb NOT NULL,
 purpose_id uuid NOT NULL, revision_id uuid NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(workspace_id,command_id),
 FOREIGN KEY(workspace_id,purpose_id,revision_id) REFERENCES object_purpose_revisions(workspace_id,purpose_id,revision_id)
);
ALTER TABLE object_purpose_revisions ADD FOREIGN KEY(workspace_id,command_id)
 REFERENCES object_purpose_command_receipts(workspace_id,command_id) DEFERRABLE INITIALLY DEFERRED;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['object_purposes','object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('REVOKE ALL ON %I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('CREATE POLICY object_purpose_owner ON %I TO builderad_owner USING (true) WITH CHECK (true)',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('CREATE TRIGGER object_purpose_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable()',t);
 END LOOP;
END $$;
REVOKE ALL ON FUNCTION builderad_object_purpose_keys(jsonb,text[]),builderad_object_purpose_text(jsonb,integer,boolean),builderad_object_purpose_instant(jsonb),builderad_object_purpose_normalize(jsonb) FROM PUBLIC,builderad_app,builderad_authority;

-- Independent Purpose session context; no source actor/context dependency.
CREATE FUNCTION builderad_object_purpose_actor(w bigint,s text,fresh boolean) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 IF s IS NULL OR fresh IS NULL THEN RAISE EXCEPTION 'Exact session required' USING ERRCODE='42501'; END IF;
 a:=builderad_current_workspace_actor(w,s,true,fresh);
 PERFORM set_config('app.object_purpose_session',s,true);
 PERFORM set_config('app.object_purpose_signature',(SELECT encode(hmac(convert_to('object-purpose|'||current_setting('app.signed_context')||'|'||s,'UTF8'),secret,'sha256'),'hex') FROM builderad_context_secret WHERE singleton),true);
 RETURN a;
END $$;
CREATE FUNCTION builderad_object_purpose_read_begin(w bigint,s text) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 a:=builderad_object_purpose_actor(w,s,false);
 -- A coherent head/history/currentness projection within this signed transaction.
 PERFORM pg_advisory_xact_lock_shared(hashtextextended('builderad.object-purpose.'||w,0));
 a:=builderad_object_purpose_actor(w,s,false);
 RETURN a;
END $$;
CREATE FUNCTION builderad_object_purpose_can_read(w bigint) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE s text:=current_setting('app.object_purpose_session',true);
BEGIN
 IF s IS NULL OR current_setting('app.object_purpose_signature',true) IS DISTINCT FROM
  (SELECT encode(hmac(convert_to('object-purpose|'||current_setting('app.signed_context',true)||'|'||s,'UTF8'),secret,'sha256'),'hex') FROM builderad_context_secret WHERE singleton) THEN RETURN false; END IF;
 PERFORM builderad_current_workspace_actor(w,s,true,false);
 RETURN true;
EXCEPTION WHEN insufficient_privilege THEN RETURN false;
END $$;
GRANT SELECT ON object_purposes,object_purpose_revisions,object_purpose_command_receipts TO builderad_app;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['object_purposes','object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('CREATE POLICY object_purpose_read ON %I FOR SELECT TO builderad_app USING (builderad_object_purpose_can_read(workspace_id))',t);
 END LOOP;
END $$;
CREATE FUNCTION builderad_object_purpose_write(w bigint,s text,c jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint; pid uuid; rid uuid; cid uuid; predecessor uuid; expected bigint; head bigint;
 op text; snap jsonb; old object_purpose_revisions%ROWTYPE; receipt object_purpose_command_receipts%ROWTYPE;
 fp text; result jsonb; cf jsonb; field text; required boolean; start_at timestamptz; until_at timestamptz; accepted timestamptz;
 context_version bigint; reference_version bigint; countries jsonb; intent_value jsonb; affected jsonb; reason text;
BEGIN
 a:=builderad_object_purpose_actor(w,s,true);
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.object-purpose.'||w,0));
 a:=builderad_object_purpose_actor(w,s,true);
 IF c IS NULL OR octet_length(c::text)>131072 OR NOT builderad_object_purpose_keys(c,
  ARRAY['command_id','purpose_id','expected_version','operation','declaration','effective_from','effective_until','supersedes_revision_id','affected_revision_ids','correction_reason','withdrawal_reason','confirmations']) THEN
  RAISE EXCEPTION 'Exact bounded Purpose command required' USING ERRCODE='22023'; END IF;
 IF jsonb_typeof(c->'command_id') IS DISTINCT FROM 'string' OR (c->>'command_id') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR
  jsonb_typeof(c->'expected_version') IS DISTINCT FROM 'number' OR (c->>'expected_version') !~ '^\d{1,18}$' OR
  jsonb_typeof(c->'operation') IS DISTINCT FROM 'string' OR c->>'operation' NOT IN ('declare','replace','correct','withdraw') THEN
  RAISE EXCEPTION 'Command identity, version and operation required' USING ERRCODE='22023'; END IF;
 cid:=(c->>'command_id')::uuid; expected:=(c->>'expected_version')::bigint; op:=c->>'operation';
 fp:=encode(digest(c::text,'sha256'),'hex');
 SELECT * INTO receipt FROM object_purpose_command_receipts WHERE workspace_id=w AND command_id=cid;
 IF FOUND THEN
  IF receipt.fingerprint<>fp OR receipt.actor_user_id<>a THEN RAISE EXCEPTION 'Command reuse conflict' USING ERRCODE='40001'; END IF;
  RETURN receipt.result;
 END IF;
 cf:=c->'confirmations';
 IF NOT builderad_object_purpose_keys(cf,ARRAY['meaning','scope','authority','identity','standing','dtm','intent','historical','transition']) OR
  EXISTS (SELECT 1 FROM jsonb_each(cf) e WHERE jsonb_typeof(e.value)<>'boolean') THEN
  RAISE EXCEPTION 'Explicit boolean confirmations required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['meaning','scope','authority','identity','standing'] LOOP
  IF cf->field IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'All decisive confirmations required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF jsonb_typeof(c->'affected_revision_ids') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Affected revisions list required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(c->'affected_revision_ids')>1000 OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'affected_revision_ids') e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') THEN
  RAISE EXCEPTION 'Bounded exact affected revisions required' USING ERRCODE='22023'; END IF;
 IF NOT builderad_object_purpose_text(c->'correction_reason',4000) OR NOT builderad_object_purpose_text(c->'withdrawal_reason',4000) THEN
  RAISE EXCEPTION 'Bounded transition reason required' USING ERRCODE='22023'; END IF;
 IF op='declare' THEN
  IF c->'purpose_id'<>'null'::jsonb OR c->'supersedes_revision_id'<>'null'::jsonb OR expected<>0 OR c->'affected_revision_ids'<>'[]'::jsonb OR
   c->'correction_reason'<>'null'::jsonb OR c->'withdrawal_reason'<>'null'::jsonb THEN RAISE EXCEPTION 'New identity command required' USING ERRCODE='22023'; END IF;
  pid:=uuidv7(); head:=0;
 ELSE
  IF jsonb_typeof(c->'purpose_id') IS DISTINCT FROM 'string' OR (c->>'purpose_id') !~* '^[0-9a-f-]{36}$' OR
   jsonb_typeof(c->'supersedes_revision_id') IS DISTINCT FROM 'string' OR (c->>'supersedes_revision_id') !~* '^[0-9a-f-]{36}$' OR cf->'transition'<>'true'::jsonb THEN
   RAISE EXCEPTION 'Explicit reviewed predecessor and transition confirmation required' USING ERRCODE='22023'; END IF;
  BEGIN pid:=(c->>'purpose_id')::uuid; predecessor:=(c->>'supersedes_revision_id')::uuid;
  EXCEPTION WHEN invalid_text_representation THEN RAISE EXCEPTION 'Valid identity required' USING ERRCODE='22023'; END;
  SELECT head_version INTO head FROM object_purposes WHERE workspace_id=w AND id=pid FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Purpose not available in Workspace' USING ERRCODE='42501'; END IF;
  IF head<>expected THEN RAISE EXCEPTION 'Purpose revision changed' USING ERRCODE='40001'; END IF;
  SELECT * INTO old FROM object_purpose_revisions WHERE workspace_id=w AND purpose_id=pid AND version=head;
  IF old.revision_id<>predecessor THEN RAISE EXCEPTION 'Reviewed predecessor changed' USING ERRCODE='40001'; END IF;
 END IF;
 accepted:=clock_timestamp();
 start_at:=coalesce(builderad_object_purpose_instant(c->'effective_from'),accepted);
 until_at:=builderad_object_purpose_instant(c->'effective_until');
 IF until_at IS NOT NULL AND until_at<=start_at THEN RAISE EXCEPTION 'Exclusive end must follow start' USING ERRCODE='22023'; END IF;
 IF op='withdraw' THEN
  IF c->'declaration'<>'null'::jsonb OR NOT builderad_object_purpose_text(c->'withdrawal_reason',4000,true) OR until_at IS NOT NULL THEN
   RAISE EXCEPTION 'Withdrawal reason and no new declaration required' USING ERRCODE='22023'; END IF;
  snap:=old.snapshot; reason:=c->>'withdrawal_reason';
 ELSE
  snap:=builderad_object_purpose_normalize(c->'declaration');
  IF snap IS NULL THEN RAISE EXCEPTION 'Full declaration required' USING ERRCODE='22023'; END IF;
  IF op='correct' AND NOT builderad_object_purpose_text(c->'correction_reason',4000,true) THEN RAISE EXCEPTION 'Correction reason required' USING ERRCODE='22023'; END IF;
  reason:=c->>'correction_reason';
 END IF;
 IF start_at<accepted OR (op<>'withdraw' AND snap#>>'{dtm,mode}'='historical_attestation') THEN
  IF cf->'historical' IS DISTINCT FROM 'true'::jsonb OR (op<>'withdraw' AND NOT builderad_object_purpose_text(snap#>'{provenance,historical_support}',4000,true)) OR (op='withdraw' AND NOT builderad_object_purpose_text(to_jsonb(reason),4000,true)) THEN
   RAISE EXCEPTION 'Explicit supported historical attestation required' USING ERRCODE='22023'; END IF;
 END IF;
 IF snap#>'{provenance,statement_at}'<>'null'::jsonb THEN
  IF builderad_object_purpose_instant(snap#>'{provenance,statement_at}')>accepted THEN RAISE EXCEPTION 'Original statement cannot be future knowledge' USING ERRCODE='22023'; END IF;
 END IF;
 IF op<>'declare' THEN
  SELECT coalesce(jsonb_agg(revision_id::text ORDER BY revision_id::text),'[]') INTO affected
   FROM object_purpose_revisions WHERE workspace_id=w AND purpose_id=pid AND effective_from>=start_at;
  IF NOT ((c->'affected_revision_ids') @> affected) OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(c->'affected_revision_ids') e
   WHERE NOT EXISTS (SELECT 1 FROM object_purpose_revisions WHERE workspace_id=w AND purpose_id=pid AND revision_id::text=e)) THEN
   RAISE EXCEPTION 'Explicit review of affected scheduled/history revisions required' USING ERRCODE='40001'; END IF;
 END IF;
 SELECT business_context_version INTO context_version FROM workspaces WHERE id=w;
 IF op<>'withdraw' AND snap->'dtm'<>'null'::jsonb THEN
  IF cf->'dtm' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit DTM confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{dtm,version}')::bigint;
  IF reference_version>context_version OR (reference_version<>0 AND NOT EXISTS (
   SELECT 1 FROM business_context_events WHERE workspace_id=w AND version=reference_version UNION ALL
   SELECT 1 FROM business_target_market_events WHERE workspace_id=w AND version=reference_version UNION ALL
   SELECT 1 FROM business_current_intent_events WHERE workspace_id=w AND after_version=reference_version)) THEN
   RAISE EXCEPTION 'Exact canonical Context revision unavailable' USING ERRCODE='22023'; END IF;
  -- The immutable DTM journal contains material transitions only; Intent/name/type drift is unrelated.
  IF snap#>>'{dtm,mode}' IN ('current_alignment','current_dependency') AND EXISTS (
   SELECT 1 FROM business_target_market_events WHERE workspace_id=w AND version>reference_version AND version<=context_version) THEN
   RAISE EXCEPTION 'Current DTM alignment changed; review required' USING ERRCODE='40001'; END IF;
  SELECT to_jsonb(after_codes) INTO countries FROM business_target_market_events WHERE workspace_id=w AND version<=reference_version ORDER BY version DESC LIMIT 1;
  countries:=coalesce(countries,'[]');
  IF countries<>snap#>'{dtm,countries}' THEN RAISE EXCEPTION 'Canonical DTM snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 IF op<>'withdraw' AND snap->'intent'<>'null'::jsonb THEN
  IF cf->'intent' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit Intent confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{intent,version}')::bigint;
  IF reference_version>context_version OR (reference_version<>0 AND NOT EXISTS (
   SELECT 1 FROM business_context_events WHERE workspace_id=w AND version=reference_version UNION ALL
   SELECT 1 FROM business_target_market_events WHERE workspace_id=w AND version=reference_version UNION ALL
   SELECT 1 FROM business_current_intent_events WHERE workspace_id=w AND after_version=reference_version)) THEN
   RAISE EXCEPTION 'Exact declared Intent revision unavailable' USING ERRCODE='22023'; END IF;
  SELECT after_value INTO intent_value FROM business_current_intent_events WHERE workspace_id=w AND after_version<=reference_version ORDER BY after_version DESC LIMIT 1;
  IF intent_value IS NULL OR intent_value<>snap#>'{intent,value}' THEN RAISE EXCEPTION 'Canonical Intent snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 a:=builderad_object_purpose_actor(w,s,true);
 IF op='declare' THEN INSERT INTO object_purposes(id,workspace_id) VALUES(pid,w); END IF;
 INSERT INTO object_purpose_revisions(workspace_id,purpose_id,version,prior_version,supersedes_revision_id,operation,effective_from,effective_until,snapshot,
 actor_user_id,actor_generation,ownership_version,confirmations,command_id,reason,affected_revision_ids,accepted_at)
 SELECT w,pid,head+1,nullif(head,0),predecessor,op,start_at,until_at,snap,a,l.generation,o.version,cf,cid,reason,c->'affected_revision_ids',accepted
 FROM principal_lifecycles l JOIN workspace_ownerships o ON o.workspace_id=w WHERE l.user_id=a;
 SELECT revision_id INTO rid FROM object_purpose_revisions WHERE workspace_id=w AND purpose_id=pid AND version=head+1;
 UPDATE object_purposes SET head_version=head+1 WHERE workspace_id=w AND id=pid;
 result:=jsonb_build_object('purpose_id',pid,'revision_id',rid,'version',head+1);
 INSERT INTO object_purpose_command_receipts(workspace_id,command_id,actor_user_id,fingerprint,result,purpose_id,revision_id) VALUES(w,cid,a,fp,result,pid,rid);
 PERFORM builderad_object_purpose_actor(w,s,true);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_object_purpose_actor(bigint,text,boolean),builderad_object_purpose_read_begin(bigint,text),builderad_object_purpose_can_read(bigint),builderad_object_purpose_write(bigint,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_object_purpose_read_begin(bigint,text),builderad_object_purpose_can_read(bigint),builderad_object_purpose_write(bigint,text,jsonb) TO builderad_app;
