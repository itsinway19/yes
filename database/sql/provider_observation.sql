-- Immutable read history with a provider-native encrypted progress fence.
CREATE TABLE provider_observation_streams (
 namespace text PRIMARY KEY, workspace_id bigint NOT NULL, source_id uuid NOT NULL,
 provider text NOT NULL CHECK(provider IN ('meta','google','snapchat','tiktok','x')),
 resource varchar(180) NOT NULL, operation varchar(80) NOT NULL CHECK(operation='report.read'), purpose varchar(128) NOT NULL,
 stream varchar(128) NOT NULL CHECK(stream ~ '^[a-zA-Z0-9_.:-]{1,128}$'),
 generation bigint NOT NULL CHECK(generation>0), access_version bigint NOT NULL CHECK(access_version>0),
 progress_generation bigint, encrypted_progress text,
 FOREIGN KEY(workspace_id,source_id,resource,operation,purpose) REFERENCES source_capabilities(workspace_id,source_id,resource,operation,purpose),
 UNIQUE(workspace_id,source_id,provider,resource,operation,purpose,stream),
 CHECK((progress_generation IS NULL AND encrypted_progress IS NULL) OR
       (progress_generation>0 AND progress_generation<=generation AND length(encrypted_progress)>0))
);
CREATE TABLE provider_observation_runs (
 namespace text NOT NULL REFERENCES provider_observation_streams(namespace), generation bigint NOT NULL,
 command_id uuid NOT NULL, fingerprint text NOT NULL, coverage text NOT NULL CHECK(coverage IN ('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')),
 provenance jsonb NOT NULL CHECK(jsonb_typeof(provenance)='object'), failure text, progress_advanced boolean NOT NULL,
 actor_user_id bigint NOT NULL REFERENCES users(id), actor_generation bigint NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(namespace,generation), UNIQUE(namespace,command_id)
);
CREATE TABLE provider_observation_entries (
 namespace text NOT NULL, generation bigint NOT NULL, identity text NOT NULL,
 content_hash text NOT NULL CHECK(content_hash ~ '^[0-9a-f]{64}$'), observation jsonb NOT NULL,
 prior_generation bigint, relation text NOT NULL CHECK(relation IN ('FIRST_OBSERVATION','IDENTICAL_REREAD','RESTATEMENT')),
 PRIMARY KEY(namespace,generation,identity), FOREIGN KEY(namespace,generation) REFERENCES provider_observation_runs(namespace,generation),
 FOREIGN KEY(namespace,prior_generation,identity) REFERENCES provider_observation_entries(namespace,generation,identity)
);
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['provider_observation_streams','provider_observation_runs','provider_observation_entries'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('REVOKE ALL ON %I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('CREATE POLICY observation_owner ON %I TO builderad_owner USING(true) WITH CHECK(true)',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['provider_observation_runs','provider_observation_entries'] LOOP
  EXECUTE format('CREATE TRIGGER observation_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable()',t);
 END LOOP;
END $$;
GRANT SELECT(namespace,workspace_id,source_id,provider,resource,operation,purpose,stream,generation,access_version,progress_generation) ON provider_observation_streams TO builderad_app;
GRANT SELECT ON provider_observation_runs,provider_observation_entries TO builderad_app;
CREATE POLICY observation_stream_read ON provider_observation_streams FOR SELECT TO builderad_app USING(
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE POLICY observation_run_read ON provider_observation_runs FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM provider_observation_streams s WHERE s.namespace=provider_observation_runs.namespace));
CREATE POLICY observation_entry_read ON provider_observation_entries FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM provider_observation_streams s WHERE s.namespace=provider_observation_entries.namespace));

CREATE OR REPLACE FUNCTION builderad_observation_namespace(w bigint,s uuid,p text,r text,o text,u text,t text)
RETURNS text LANGUAGE sql IMMUTABLE SET search_path=public,pg_temp AS $$
 SELECT encode(sha256(convert_to(jsonb_build_array(w,s,p,r,o,u,t)::text,'UTF8')),'hex')
$$;
REVOKE ALL ON FUNCTION builderad_observation_namespace(bigint,uuid,text,text,text,text,text) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_observation_accept(w bigint,se text,s uuid,p text,r text,o text,u text,t text,
 c uuid,expected bigint,av bigint,payload jsonb)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE ns text; actor bigint; fp text; previous provider_observation_streams%ROWTYPE; replay provider_observation_runs%ROWTYPE;
 item jsonb; prior provider_observation_entries%ROWTYPE; n bigint; changed integer:=0; fresh integer:=0; same integer:=0; result jsonb; native_hash text;
BEGIN
 IF o IS DISTINCT FROM 'report.read' OR t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR
    expected IS NULL OR expected<0 OR av IS NULL OR av<1 OR jsonb_typeof(payload) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Invalid observation acceptance request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,p,r,o,u,t);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 actor:=builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM 1 FROM provider_access WHERE workspace_id=w AND source_id=s AND provider=p AND resource=r AND operation=o AND purpose=u
  AND version=av AND local_state='CREDENTIAL_STORED' FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Observation access generation changed' USING ERRCODE='42501'; END IF;
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 fp:=encode(sha256(convert_to(jsonb_build_array(expected,av,payload-'encrypted_progress')::text,'UTF8')),'hex');
 SELECT * INTO replay FROM provider_observation_runs WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF replay.fingerprint<>fp THEN RAISE EXCEPTION 'Observation replay conflict' USING ERRCODE='23505'; END IF;
  RETURN jsonb_build_object('generation',replay.generation,'coverage',replay.coverage,
   'first_observations',(SELECT count(*) FROM provider_observation_entries WHERE namespace=ns AND generation=replay.generation AND relation='FIRST_OBSERVATION'),
   'identical_rereads',(SELECT count(*) FROM provider_observation_entries WHERE namespace=ns AND generation=replay.generation AND relation='IDENTICAL_REREAD'),
   'restatements',(SELECT count(*) FROM provider_observation_entries WHERE namespace=ns AND generation=replay.generation AND relation='RESTATEMENT'),
   'progress_advanced',replay.progress_advanced);
 END IF;
 SELECT * INTO previous FROM provider_observation_streams WHERE namespace=ns FOR UPDATE;
 IF coalesce(previous.generation,0)<>expected THEN RAISE EXCEPTION 'Observation generation conflict' USING ERRCODE='23505'; END IF;
 IF length(payload::text)>2097152 OR payload-ARRAY['coverage','failure','provenance','observations','encrypted_progress','progress_fingerprint']<>'{}'::jsonb
    OR coalesce(payload->>'coverage','') NOT IN ('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')
    OR jsonb_typeof(payload->'observations') IS DISTINCT FROM 'array' OR jsonb_typeof(payload->'provenance') IS DISTINCT FROM 'object'
    OR (payload->'provenance')-ARRAY['api_version','report','documentation_basis']<>'{}'::jsonb
    OR coalesce(payload->'provenance'->>'api_version','')='' OR coalesce(payload->'provenance'->>'report','')=''
    OR coalesce(payload->'provenance'->>'documentation_basis','')='' THEN
  RAISE EXCEPTION 'Invalid observation provenance or payload' USING ERRCODE='22023';
 END IF;
 IF payload->>'coverage'='FAILED' AND (jsonb_array_length(payload->'observations')<>0 OR payload->>'failure' IS NULL) THEN
  RAISE EXCEPTION 'Failed read cannot accept observations' USING ERRCODE='22023';
 END IF;
 IF (payload->>'coverage'='COMPLETE' AND payload->>'failure' IS NOT NULL)
    OR (payload->>'failure' IS NOT NULL AND payload->>'failure' !~ '^[a-z][a-z0-9_]{0,79}$') THEN
  RAISE EXCEPTION 'Invalid read failure classification' USING ERRCODE='22023';
 END IF;
 IF payload->>'encrypted_progress' IS NOT NULL AND
   (payload->>'coverage'<>'COMPLETE' OR payload->>'failure' IS NOT NULL OR length(payload->>'encrypted_progress') NOT BETWEEN 1 AND 131072
    OR coalesce(payload->>'progress_fingerprint','') !~ '^[0-9a-f]{64}$') THEN
  RAISE EXCEPTION 'Unresolved read cannot advance progress' USING ERRCODE='22023';
 END IF;
 n:=expected+1;
 -- Establish the stream row without advancing native progress. All changes remain invisible until commit.
 INSERT INTO provider_observation_streams(namespace,workspace_id,source_id,provider,resource,operation,purpose,stream,generation,access_version)
 VALUES(ns,w,s,p,r,o,u,t,n,av) ON CONFLICT(namespace) DO UPDATE SET generation=n,access_version=av,
  encrypted_progress=CASE WHEN provider_observation_streams.access_version=av THEN provider_observation_streams.encrypted_progress ELSE NULL END,
  progress_generation=CASE WHEN provider_observation_streams.access_version=av THEN provider_observation_streams.progress_generation ELSE NULL END;
 INSERT INTO provider_observation_runs(namespace,generation,command_id,fingerprint,coverage,provenance,failure,progress_advanced,actor_user_id,actor_generation)
 VALUES(ns,n,c,fp,payload->>'coverage',payload->'provenance',payload->>'failure',payload->>'encrypted_progress' IS NOT NULL,actor,
  (SELECT generation FROM principal_lifecycles WHERE user_id=actor));
 FOR item IN SELECT value FROM jsonb_array_elements(payload->'observations') LOOP
  IF jsonb_typeof(item) IS DISTINCT FROM 'object' OR item-ARRAY['identity','content_hash','observation']<>'{}'::jsonb
     OR coalesce(item->>'identity','') !~ '^bb2:[0-9a-f]{64}$' OR coalesce(item->>'content_hash','') !~ '^[0-9a-f]{64}$'
     OR item->'observation'->'scope' IS DISTINCT FROM jsonb_build_object('workspace_id',w,'source_id',s,'provider',p,'account_id',r,'operation',o,'purpose',u)
     OR coalesce(item->'observation'->>'campaign_id','')='' OR jsonb_typeof(item->'observation'->'provenance') IS DISTINCT FROM 'object'
     OR jsonb_typeof(item->'observation'->'spend') IS DISTINCT FROM 'object' OR jsonb_typeof(item->'observation'->'window') IS DISTINCT FROM 'object'
     OR jsonb_typeof(item->'observation'->'native_facts') IS DISTINCT FROM 'object'
     OR coalesce(item->'observation'->>'read_at','') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?(Z|[+-][0-9]{2}:[0-9]{2})$'
     OR ((item->'observation')->'provenance')-ARRAY['api_version','report','documentation_basis']<>'{}'::jsonb
     OR coalesce(item->'observation'->'provenance'->>'api_version','')=''
     OR coalesce(item->'observation'->'provenance'->>'report','')=''
     OR coalesce(item->'observation'->'provenance'->>'documentation_basis','')=''
     OR (item->'observation'->'spend'->>'native_value' IS NOT NULL AND
         item->'observation'->'spend'->>'native_value' !~ '^-?[0-9]{1,60}(\.[0-9]{1,30})?$')
     OR (item->'observation'->'spend'->>'currency' IS NOT NULL AND
         item->'observation'->'spend'->>'currency' !~ '^[A-Z]{3}$')
     OR coalesce(item->'observation'->'spend'->>'native_unit','')=''
     OR (item->'observation')-ARRAY['scope','campaign_id','window','spend','native_facts','provenance','read_at','fresh_until']<>'{}'::jsonb THEN
   RAISE EXCEPTION 'Rejected observation identity or provenance' USING ERRCODE='22023';
  END IF;
  SELECT * INTO prior FROM provider_observation_entries WHERE namespace=ns AND identity=item->>'identity' ORDER BY generation DESC LIMIT 1;
  IF (item->'observation'->>'read_at')::timestamptz>clock_timestamp()
     OR (prior.generation IS NOT NULL AND
       ((item->'observation'->'window') IS DISTINCT FROM (prior.observation->'window')
        OR item->'observation'->>'campaign_id' IS DISTINCT FROM prior.observation->>'campaign_id'
        OR (item->'observation'->>'read_at')::timestamptz<(prior.observation->>'read_at')::timestamptz)) THEN
   RAISE EXCEPTION 'Stale read or measurement identity changed' USING ERRCODE='22023';
  END IF;
  -- The database derives content identity independently; a caller's claimed hash cannot hide a correction.
  native_hash:=encode(sha256(convert_to(jsonb_build_array(item->'observation'->'spend',item->'observation'->'native_facts',item->'observation'->'provenance')::text,'UTF8')),'hex');
  INSERT INTO provider_observation_entries VALUES(ns,n,item->>'identity',native_hash,item->'observation',prior.generation,
   CASE WHEN prior.generation IS NULL THEN 'FIRST_OBSERVATION' WHEN prior.content_hash=native_hash THEN 'IDENTICAL_REREAD' ELSE 'RESTATEMENT' END);
  IF prior.generation IS NULL THEN fresh:=fresh+1; ELSIF prior.content_hash=native_hash THEN same:=same+1; ELSE changed:=changed+1; END IF;
 END LOOP;
 -- Recheck time-sensitive authority after every possible row wait, before progress acceptance.
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 IF payload->>'encrypted_progress' IS NOT NULL THEN
  UPDATE provider_observation_streams SET encrypted_progress=payload->>'encrypted_progress',progress_generation=n WHERE namespace=ns;
 END IF;
 result:=jsonb_build_object('generation',n,'coverage',payload->>'coverage','first_observations',fresh,'identical_rereads',same,'restatements',changed,
  'progress_advanced',payload->>'encrypted_progress' IS NOT NULL);
 -- Replay derives the same receipt from the immutable run and its immutable accepted entries.
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_observation_accept(bigint,text,uuid,text,text,text,text,text,uuid,bigint,bigint,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_observation_accept(bigint,text,uuid,text,text,text,text,text,uuid,bigint,bigint,jsonb) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_observation_progress(w bigint,se text,s uuid,p text,r text,o text,u text,t text,av bigint)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE result text;
BEGIN
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 PERFORM 1 FROM provider_access WHERE workspace_id=w AND source_id=s AND provider=p AND resource=r AND operation=o AND purpose=u AND version=av FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Progress access generation changed' USING ERRCODE='42501'; END IF;
 SELECT encrypted_progress INTO result FROM provider_observation_streams WHERE namespace=builderad_observation_namespace(w,s,p,r,o,u,t) AND access_version=av FOR SHARE;
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_observation_progress(bigint,text,uuid,text,text,text,text,text,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_observation_progress(bigint,text,uuid,text,text,text,text,text,bigint) TO builderad_app;
