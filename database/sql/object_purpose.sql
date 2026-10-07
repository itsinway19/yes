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
  IF NOT r->'countries' @> r->'subset' THEN RAISE EXCEPTION 'Explicit subset outside reference' USING ERRCODE='22023'; END IF;
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
