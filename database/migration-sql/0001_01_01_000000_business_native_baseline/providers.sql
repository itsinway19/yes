-- Business-native provider and Source runtime. Digest null markers preserve accepted Business bytes.

CREATE FUNCTION builderad_private.apply_native_source(p_actor bigint, p_generation bigint, p_business bigint, p_command uuid, p_source uuid, p_effect text, p_payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.source_registry;before_s public.source_registry;cap public.source_capabilities;prior public.source_command_receipts;fp text;result jsonb;env jsonb;obligation uuid;decision timestamptz;
 adopted boolean;r text;op text;purpose_name text;
BEGIN
 IF p_business IS NULL OR p_business<1 THEN RAISE EXCEPTION 'Exact Business Source required' USING ERRCODE='42501'; END IF;
 p_payload:=builderad_private.native_source_payload(p_effect,p_payload);r:=p_payload->>'resource';op:=p_payload->>'operation';purpose_name:=p_payload->>'purpose';
 fp:=encode(sha256(convert_to(jsonb_build_object('effect',p_effect,'source',p_source,'payload',p_payload)::text,'UTF8')),'hex');
 SELECT * INTO prior FROM public.source_command_receipts WHERE command_id=p_command AND
  source_id=p_source;
 IF FOUND THEN
  IF prior.fingerprint<>fp OR prior.actor_user_id<>p_actor OR prior.actor_generation<>p_generation THEN RAISE EXCEPTION 'Source command identity conflict' USING ERRCODE='23505'; END IF;
  RETURN prior.result;
 END IF;
 SELECT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted')
  OR EXISTS(SELECT 1 FROM public.source_registry source WHERE source.id=p_source AND to_jsonb(source)->>'custody_kind'='business_native') INTO adopted;
 SELECT * INTO before_s FROM public.source_registry WHERE id=p_source AND custody_kind='business_native' AND business_id=p_business FOR UPDATE;
 IF p_effect='register' THEN
  IF FOUND THEN RAISE EXCEPTION 'Source already registered' USING ERRCODE='23505'; END IF;
  IF adopted OR (p_payload->>'expected_version')::bigint<>0 THEN RAISE EXCEPTION 'Initial native source version required' USING ERRCODE='22023'; END IF;
   -- Only the approved protected bootstrap can establish exact Business custody.
   IF NOT EXISTS(SELECT 1 FROM builderad_private.invocations i WHERE i.backend_pid=pg_backend_pid() AND i.transaction_id=pg_current_xact_id()
    AND i.phase='authorized' AND i.action='source.business.bootstrap' AND i.actor_user_id=p_actor
    AND i.target_tuple->>'source_id'=p_source::text AND (i.target_tuple->>'business_id')::bigint=p_business)
   THEN RAISE EXCEPTION 'Explicit protected Business Source bootstrap required' USING ERRCODE='42501'; END IF;
   SELECT native.* INTO s FROM builderad_private.invocations i CROSS JOIN LATERAL
    jsonb_populate_record(NULL::public.source_registry,jsonb_build_object('id',p_source,
    'custody_kind','business_native','business_id',(i.target_tuple->>'business_id')::bigint,
    'family',p_payload->>'family','namespace',p_payload->>'namespace','declared_external_id',p_payload->>'external_id',
    'label',p_payload->>'label','version',1,'status','UNVERIFIED','created_at',clock_timestamp(),'scope_lock_marker',false)) native
   WHERE i.backend_pid=pg_backend_pid() AND i.transaction_id=pg_current_xact_id() AND i.phase='authorized';
   adopted:=true;
   PERFORM builderad_private.permit('public.source_registry','insert',NULL,to_jsonb(s));
   INSERT INTO public.source_registry SELECT(s).*;
 ELSE
  IF NOT FOUND THEN RAISE EXCEPTION 'Source unavailable' USING ERRCODE='42501'; END IF;
  IF before_s.version<>(p_payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Source version conflict' USING ERRCODE='23505'; END IF;
  IF before_s.status='REVOKED' THEN RAISE EXCEPTION 'Source revoked' USING ERRCODE='22023'; END IF;
  IF p_effect<>'declare_scope' AND NOT EXISTS(SELECT 1 FROM public.source_capabilities WHERE source_id=p_source AND resource=r AND operation=op AND purpose=purpose_name)
  THEN RAISE EXCEPTION 'Exact declared native scope required' USING ERRCODE='42501'; END IF;
  s:=before_s;s.version:=s.version+1;
  IF p_effect='rename' THEN s.label:=p_payload->>'label'; END IF;
  IF p_effect='revoke' THEN s.status:='REVOKED'; END IF;
  IF adopted THEN PERFORM builderad_private.permit('public.source_registry','update',to_jsonb(before_s),to_jsonb(s)); END IF;
  UPDATE public.source_registry SET version=s.version,label=s.label,status=s.status WHERE id=s.id;
 END IF;
 IF p_effect IN('register','declare_scope') THEN
  cap.source_id:=p_source;cap.resource:=r;cap.operation:=op;cap.purpose:=purpose_name;cap.declared_at:=clock_timestamp();
  IF adopted THEN PERFORM builderad_private.permit('public.source_capabilities','insert',NULL,to_jsonb(cap)); END IF;
  INSERT INTO public.source_capabilities SELECT(cap).*;
 END IF;
 decision:=clock_timestamp();obligation:=uuidv7();
 result:=jsonb_build_object('command_id',p_command,'source_id',p_source,'version',s.version,'registration_state',s.status,'obligation_id',obligation,'local_audit','DURABLE','reason',p_payload->>'reason');
 PERFORM builderad_private.native_source_append('public.source_command_receipts',jsonb_build_object('command_id',p_command,'source_id',p_source,'effect',p_effect,'resource',r,'operation',op,'purpose',purpose_name,
  'fingerprint',fp,'result',result,'actor_user_id',p_actor,'actor_generation',p_generation,'decided_at',decision),adopted);
 PERFORM builderad_private.native_source_append('public.source_versions',jsonb_build_object('source_id',p_source,'version',s.version,'prior_version',nullif(s.version-1,0),'command_id',p_command,
  'snapshot',jsonb_build_object('label',s.label,'status',s.status,'resource',r,'operation',op,'purpose',purpose_name)),adopted);
 env:=jsonb_build_object('policy','BB1-BUSINESS-V1','business_id',p_business,'custody_kind','business_native','source_id',p_source,'command_id',p_command,'effect',p_effect,'resource',r,'operation',op,'purpose',purpose_name,
  'actor_user_id',p_actor,'actor_generation',p_generation,'fingerprint',fp,'prior_version',nullif(s.version-1,0),'result_version',s.version,'reason',p_payload->>'reason','decided_at',decision);
 PERFORM builderad_private.native_source_append('public.source_audit_obligations',jsonb_build_object('id',obligation,'command_id',p_command,'source_id',p_source,'envelope',env,
  'fingerprint',encode(sha256(convert_to(env::text,'UTF8')),'hex'),'recorded_at',decision),adopted);
 PERFORM builderad_private.native_source_append('public.source_audit_delivery',jsonb_build_object('obligation_id',obligation,'state','PENDING','acknowledgment_id',NULL,'attempts',0,'updated_at',decision),adopted);
 RETURN result;
END $$;


ALTER FUNCTION builderad_private.apply_native_source(p_actor bigint, p_generation bigint, p_business bigint, p_command uuid, p_source uuid, p_effect text, p_payload jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.native_source_gate_held(p_source uuid) RETURNS boolean
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted
  AND classid=((hashtextextended('builderad.source.admission.'||p_source,0)>>32)&4294967295)::oid
  AND objid=(hashtextextended('builderad.source.admission.'||p_source,0)&4294967295)::oid AND objsubid=1
  AND mode IN('ShareLock','ExclusiveLock'));
$$;


ALTER FUNCTION builderad_private.native_source_gate_held(p_source uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.native_source_payload(p_effect text, p_payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
BEGIN
 IF p_effect IS NULL OR p_effect NOT IN('register','rename','declare_scope','revoke') OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object'
 OR octet_length(p_payload::text)>65536
 OR p_payload-(ARRAY['resource','operation','purpose','expected_version','reason']||CASE WHEN p_effect='register' THEN ARRAY['family','namespace','external_id','label'] WHEN p_effect='rename' THEN ARRAY['label'] ELSE ARRAY[]::text[] END)<>'{}'::jsonb
 OR jsonb_typeof(p_payload->'resource') IS DISTINCT FROM 'string' OR length(p_payload->>'resource') NOT BETWEEN 1 AND 180 OR p_payload->>'resource'<>trim(p_payload->>'resource')
 OR jsonb_typeof(p_payload->'operation') IS DISTINCT FROM 'string' OR length(p_payload->>'operation') NOT BETWEEN 1 AND 80 OR p_payload->>'operation'<>trim(p_payload->>'operation')
 OR jsonb_typeof(p_payload->'purpose') IS DISTINCT FROM 'string' OR length(p_payload->>'purpose') NOT BETWEEN 1 AND 128 OR p_payload->>'purpose'<>trim(p_payload->>'purpose')
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 OR coalesce(p_payload->>'expected_version','') !~ '^[0-9]{1,18}$'
 THEN RAISE EXCEPTION 'Invalid bounded native source command' USING ERRCODE='22023'; END IF;
 RETURN jsonb_set(p_payload,'{expected_version}',to_jsonb((p_payload->>'expected_version')::bigint));
END $_$;


ALTER FUNCTION builderad_private.native_source_payload(p_effect text, p_payload jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.native_source_append(p_relation regclass, p_row jsonb, p_adopted boolean) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
BEGIN
 IF p_relation NOT IN('public.source_command_receipts'::regclass,'public.source_versions'::regclass,'public.source_audit_obligations'::regclass,'public.source_audit_delivery'::regclass)
 THEN RAISE EXCEPTION 'Unregistered native source append relation' USING ERRCODE='42501'; END IF;
 IF p_adopted THEN PERFORM builderad_private.permit(p_relation,'insert',NULL,p_row); END IF;
 EXECUTE format('INSERT INTO %s SELECT r.* FROM jsonb_populate_record(NULL::%s,$1) r',p_relation,p_relation) USING p_row;
END $_$;


ALTER FUNCTION builderad_private.native_source_append(p_relation regclass, p_row jsonb, p_adopted boolean) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.source_custody_integrity() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE source_ref uuid;source_kind text;
BEGIN
 IF TG_TABLE_NAME='source_registry' THEN
  IF NEW.custody_kind IS DISTINCT FROM OLD.custody_kind OR NEW.business_id IS DISTINCT FROM OLD.business_id
   OR NEW.id IS DISTINCT FROM OLD.id
  THEN RAISE EXCEPTION 'Source custody is immutable' USING ERRCODE='42501'; END IF;
  RETURN NEW;
 END IF;
 IF TG_TABLE_NAME='source_audit_delivery' THEN
  SELECT o.source_id INTO source_ref FROM public.source_audit_obligations o WHERE o.id=NEW.obligation_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Exact source audit custody required' USING ERRCODE='23514'; END IF;
 ELSE source_ref:=NEW.source_id; END IF;
 SELECT sr.custody_kind INTO source_kind FROM public.source_registry sr WHERE sr.id=source_ref;
 IF NOT FOUND OR source_kind NOT IN('business_native','platform_market') THEN
  RAISE EXCEPTION 'Child custody must match its canonical Source' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.source_custody_integrity() OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_google_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous google_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Google attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'google',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'google',r,'report.read',u,'provider.read',av);
 -- Same observation fence: no other command can accept a different generation during this native fetch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'google',r,'report.read',u,'provider.read',av);
 SELECT * INTO previous FROM google_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Google attempt replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN previous.encrypted_result;
 END IF;
 IF coalesce((SELECT generation FROM provider_observation_streams WHERE namespace=ns),0)<>expected THEN
  RAISE EXCEPTION 'Google attempt generation conflict' USING ERRCODE='23505';
 END IF;
 RETURN NULL;
END $_$;


ALTER FUNCTION public.builderad_google_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_google_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous google_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Google attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'google',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'google',r,'report.read',u,'provider.read',av);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'google',r,'report.read',u,'provider.read',av);
 IF result_fp IS NULL OR result_fp !~ '^[a-f0-9]{64}$' OR cipher IS NULL OR length(cipher) NOT BETWEEN 1 AND 65536 THEN
  RAISE EXCEPTION 'Invalid Google scheduling envelope' USING ERRCODE='22023';
 END IF;
 SELECT * INTO previous FROM google_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.result_fingerprint<>result_fp OR previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Google scheduling replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 -- Journal acceptance already incremented the stream, so validate the immutable matching command directly.
 IF NOT EXISTS(SELECT 1 FROM provider_observation_runs WHERE namespace=ns AND command_id=c AND generation=expected+1) THEN
  RAISE EXCEPTION 'Google scheduling requires corresponding accepted run' USING ERRCODE='23514';
 END IF;
 INSERT INTO google_read_attempts(namespace,command_id,generation,request_fingerprint,result_fingerprint,encrypted_result)
 VALUES(ns,c,expected+1,fp,result_fp,cipher);
 PERFORM builderad_provider_request_gate(w,se,s,'google',r,'report.read',u,'provider.read',av);
END $_$;


ALTER FUNCTION public.builderad_google_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_google_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE c provider_access%ROWTYPE;
BEGIN
 IF p_operation IS DISTINCT FROM 'report.read' OR p_purpose IS DISTINCT FROM 'advertising_analysis' THEN
  RAISE EXCEPTION 'Google refresh scope denied' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_request_gate(p_business,p_session,p_source,'google',p_resource,p_operation,p_purpose,'provider.connect',p_version);
 IF NOT EXISTS (SELECT 1 FROM source_registry WHERE business_id=p_business AND custody_kind='business_native' AND id=p_source
  AND family='google' AND namespace='ad_account' AND declared_external_id=p_resource) THEN
  RAISE EXCEPTION 'Google refresh identity denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO c FROM provider_access WHERE source_id=p_source AND provider='google'
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR UPDATE;
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR c.encrypted_credentials IS NULL THEN
  RAISE EXCEPTION 'Google refresh custody unavailable' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_require(p_business,p_session,p_source,'google',p_resource,p_operation,p_purpose,'provider.connect');
 RETURN c.encrypted_credentials;
END $$;


ALTER FUNCTION public.builderad_google_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_meta_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous meta_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Meta attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'meta',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'meta',r,'report.read',u,'provider.read',av);
 -- Same observation fence: no other command can accept a different generation during this native fetch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'meta',r,'report.read',u,'provider.read',av);
 SELECT * INTO previous FROM meta_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Meta attempt replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN previous.encrypted_result;
 END IF;
 IF coalesce((SELECT generation FROM provider_observation_streams WHERE namespace=ns),0)<>expected THEN
  RAISE EXCEPTION 'Meta attempt generation conflict' USING ERRCODE='23505';
 END IF;
 RETURN NULL;
END $_$;


ALTER FUNCTION public.builderad_meta_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_meta_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous meta_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Meta attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'meta',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'meta',r,'report.read',u,'provider.read',av);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'meta',r,'report.read',u,'provider.read',av);
 IF result_fp IS NULL OR result_fp !~ '^[a-f0-9]{64}$' OR cipher IS NULL OR length(cipher) NOT BETWEEN 1 AND 65536 THEN
  RAISE EXCEPTION 'Invalid Meta scheduling envelope' USING ERRCODE='22023';
 END IF;
 SELECT * INTO previous FROM meta_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.result_fingerprint<>result_fp OR previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Meta scheduling replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 -- Journal acceptance already incremented the stream, so validate the immutable matching command directly.
 IF NOT EXISTS(SELECT 1 FROM provider_observation_runs WHERE namespace=ns AND command_id=c AND generation=expected+1) THEN
  RAISE EXCEPTION 'Meta scheduling requires corresponding accepted run' USING ERRCODE='23514';
 END IF;
 INSERT INTO meta_read_attempts(namespace,command_id,generation,request_fingerprint,result_fingerprint,encrypted_result)
 VALUES(ns,c,expected+1,fp,result_fp,cipher);
 PERFORM builderad_provider_request_gate(w,se,s,'meta',r,'report.read',u,'provider.read',av);
END $_$;


ALTER FUNCTION public.builderad_meta_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_observation_accept(w bigint, se text, s uuid, p text, r text, o text, u text, t text, c uuid, expected bigint, av bigint, payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; actor bigint; fp text; previous provider_observation_streams%ROWTYPE; replay provider_observation_runs%ROWTYPE;
 item jsonb; prior provider_observation_entries%ROWTYPE; n bigint; changed integer:=0; fresh integer:=0; same integer:=0; result jsonb; native_hash text;expected_scope jsonb;
BEGIN
 IF o IS DISTINCT FROM 'report.read' OR t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR
    expected IS NULL OR expected<0 OR av IS NULL OR av<1 OR jsonb_typeof(payload) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Invalid observation acceptance request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,p,r,o,u,t);
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 actor:=builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM 1 FROM provider_access WHERE source_id=s AND provider=p AND resource=r AND operation=o AND purpose=u
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
 INSERT INTO provider_observation_streams(namespace,source_id,provider,resource,operation,purpose,stream,generation,access_version)
 VALUES(ns,s,p,r,o,u,t,n,av) ON CONFLICT(namespace) DO UPDATE SET generation=n,access_version=av,
  encrypted_progress=CASE WHEN provider_observation_streams.access_version=av THEN provider_observation_streams.encrypted_progress ELSE NULL END,
  progress_generation=CASE WHEN provider_observation_streams.access_version=av THEN provider_observation_streams.progress_generation ELSE NULL END;
 INSERT INTO provider_observation_runs(namespace,generation,command_id,fingerprint,coverage,provenance,failure,progress_advanced,actor_user_id,actor_generation)
 VALUES(ns,n,c,fp,payload->>'coverage',payload->'provenance',payload->>'failure',payload->>'encrypted_progress' IS NOT NULL,actor,
  (SELECT generation FROM principal_lifecycles WHERE user_id=actor));
 expected_scope:=jsonb_build_object('custody_kind','business_native','business_id',w,'source_id',s,'provider',p,'account_id',r,'operation',o,'purpose',u);
 FOR item IN SELECT value FROM jsonb_array_elements(payload->'observations') LOOP
  IF jsonb_typeof(item) IS DISTINCT FROM 'object' OR item-ARRAY['identity','content_hash','observation']<>'{}'::jsonb
     OR coalesce(item->>'identity','') !~ '^bb2:[0-9a-f]{64}$' OR coalesce(item->>'content_hash','') !~ '^[0-9a-f]{64}$'
     OR item->'observation'->'scope' IS DISTINCT FROM expected_scope
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
END $_$;


ALTER FUNCTION public.builderad_observation_accept(w bigint, se text, s uuid, p text, r text, o text, u text, t text, c uuid, expected bigint, av bigint, payload jsonb) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_observation_namespace(w bigint, s uuid, p text, r text, o text, u text, t text) RETURNS text
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $$
 -- Frozen Business stream bytes; canonical Source UUID provides globally unique custody identity.
 SELECT encode(sha256(convert_to(jsonb_build_array(NULL,s,p,r,o,u,t)::text,'UTF8')),'hex')
$$;


ALTER FUNCTION public.builderad_observation_namespace(w bigint, s uuid, p text, r text, o text, u text, t text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_observation_progress(w bigint, se text, s uuid, p text, r text, o text, u text, t text, av bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE result text;
BEGIN
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 PERFORM 1 FROM provider_access WHERE source_id=s AND provider=p AND resource=r AND operation=o AND purpose=u AND version=av FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Progress access generation changed' USING ERRCODE='42501'; END IF;
 SELECT encrypted_progress INTO result FROM provider_observation_streams WHERE namespace=builderad_observation_namespace(w,s,p,r,o,u,t) AND access_version=av FOR SHARE;
 PERFORM builderad_provider_require(w,se,s,p,r,o,u,'provider.read');
 PERFORM builderad_provider_credential(w,se,s,p,r,o,u);
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_observation_progress(w bigint, se text, s uuid, p text, r text, o text, u text, t text, av bigint) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_provider_access_command(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_command uuid, p_expected_version bigint, p_effect text, p_payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; fp text; receipt provider_access_receipts%ROWTYPE; previous provider_access%ROWTYPE;
 a bigint; act text; next_version bigint; result jsonb;
BEGIN
 IF p_effect IS NULL OR p_effect NOT IN ('connect','reconnect','revoke') OR p_expected_version IS NULL OR p_expected_version<0 OR p_command IS NULL
    OR jsonb_typeof(p_payload) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Invalid provider command' USING ERRCODE='22023';
 END IF;
 act:=CASE WHEN p_effect='revoke' THEN 'provider.revoke' ELSE 'provider.connect' END;
 -- Frozen Business access-lock bytes: the leading null is a format marker, not an authority selector.
 ns:='bb2.access.'||encode(sha256(convert_to(jsonb_build_array(NULL,p_source,p_provider,p_resource,p_operation,p_purpose)::text,'UTF8')),'hex');
 -- Business custody takes K1 before the access namespace. Bootstrap and
  -- revocation take the same order; no access holder may wait backwards on K1.
  PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 PERFORM pg_advisory_xact_lock(hashtextextended(ns,0));
 a:=builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 -- Cipher randomness is excluded; the server HMAC binds credential content to this exact scope.
 fp:=encode(sha256(convert_to(jsonb_build_array(p_expected_version,p_effect,p_payload-'encrypted_credentials')::text,'UTF8')),'hex');
 SELECT * INTO receipt FROM provider_access_receipts WHERE namespace=ns AND command_id=p_command;
 IF FOUND THEN
  IF receipt.fingerprint<>fp THEN RAISE EXCEPTION 'Provider command conflict' USING ERRCODE='23505'; END IF;
  RETURN receipt.result;
 END IF;
 SELECT * INTO previous FROM provider_access WHERE source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR UPDATE;
 IF coalesce(previous.version,0)<>p_expected_version THEN RAISE EXCEPTION 'Provider version conflict' USING ERRCODE='23505'; END IF;
 IF (p_effect='connect' AND previous.version IS NOT NULL) OR (p_effect IN ('reconnect','revoke') AND previous.version IS NULL) THEN
  RAISE EXCEPTION 'Provider transition denied' USING ERRCODE='22023';
 END IF;
 IF p_effect='revoke' THEN
  IF p_payload<>'{}'::jsonb THEN RAISE EXCEPTION 'Revocation payload denied' USING ERRCODE='22023'; END IF;
 ELSE
  IF p_payload-ARRAY['encrypted_credentials','credential_fingerprint','expires_at','native_state','verification']<>'{}'::jsonb
     OR coalesce(length(p_payload->>'encrypted_credentials'),0) NOT BETWEEN 1 AND 65536
     OR coalesce(p_payload->>'credential_fingerprint','') !~ '^[0-9a-f]{64}$'
     OR coalesce(p_payload->>'native_state','') !~ '^[A-Z][A-Z0-9_]{0,79}$'
     OR jsonb_typeof(p_payload->'verification') IS DISTINCT FROM 'object' THEN
   RAISE EXCEPTION 'Invalid server credential envelope' USING ERRCODE='22023';
  END IF;
 END IF;
 -- Final authority fence follows row and namespace waits; every durable effect stays provider-local.
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 next_version:=coalesce(previous.version,0)+1;
 INSERT INTO provider_access (source_id,provider,resource,operation,purpose,version,local_state,native_state,encrypted_credentials,credential_expires_at,verification)
 VALUES (p_source,p_provider,p_resource,p_operation,p_purpose,next_version,
  CASE WHEN p_effect='revoke' THEN 'REVOKED' ELSE 'CREDENTIAL_STORED' END,
  CASE WHEN p_effect='revoke' THEN previous.native_state ELSE p_payload->>'native_state' END,
  CASE WHEN p_effect='revoke' THEN NULL ELSE p_payload->>'encrypted_credentials' END,
  CASE WHEN p_effect='revoke' THEN NULL ELSE (p_payload->>'expires_at')::timestamptz END,
  CASE WHEN p_effect='revoke' THEN '{}'::jsonb ELSE p_payload->'verification' END)
 ON CONFLICT ON CONSTRAINT provider_access_pkey DO UPDATE SET
  version=EXCLUDED.version,local_state=EXCLUDED.local_state,native_state=EXCLUDED.native_state,
  encrypted_credentials=EXCLUDED.encrypted_credentials,credential_expires_at=EXCLUDED.credential_expires_at,
  verification=EXCLUDED.verification,recorded_at=clock_timestamp();
 result:=jsonb_build_object('version',next_version,'local_state',CASE WHEN p_effect='revoke' THEN 'REVOKED' ELSE 'CREDENTIAL_STORED' END);
 INSERT INTO provider_access_receipts VALUES (ns,p_command,p_source,p_provider,p_resource,p_operation,p_purpose,fp,result);
 INSERT INTO provider_access_events(namespace,command_id,actor_user_id,actor_generation,effect,prior_version,version)
  VALUES (ns,p_command,a,(SELECT generation FROM principal_lifecycles WHERE user_id=a),p_effect,previous.version,next_version);
 RETURN result;
END $_$;


ALTER FUNCTION public.builderad_provider_access_command(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_command uuid, p_expected_version bigint, p_effect text, p_payload jsonb) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_provider_credential(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE c provider_access%ROWTYPE; axis text; f jsonb;
BEGIN
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 SELECT * INTO c FROM provider_access WHERE source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR SHARE;
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR
    (c.credential_expires_at IS NOT NULL AND c.credential_expires_at<=clock_timestamp()) THEN
  RAISE EXCEPTION 'Provider credential unavailable' USING ERRCODE='42501';
 END IF;
 IF p_provider='snapchat' AND EXISTS (SELECT 1 FROM snapchat_renewal_failures WHERE source_id=p_source AND resource=p_resource AND operation=p_operation AND purpose=p_purpose AND access_version=c.version) THEN
  RAISE EXCEPTION 'Snapchat renewal requires verification' USING ERRCODE='42501';
 END IF;
 FOREACH axis IN ARRAY ARRAY['provider_support','provider_permission','credential','privacy_purpose'] LOOP
  f:=c.verification->axis;
  IF coalesce(f->>'state','')<>'VERIFIED' OR coalesce(f->>'provenance','')='' OR
     f->>'verified_at' IS NULL OR f->>'valid_until' IS NULL OR
     (f->>'verified_at')::timestamptz>clock_timestamp() OR
     (f->>'valid_until')::timestamptz<=(f->>'verified_at')::timestamptz OR
     (f->>'valid_until')::timestamptz<=clock_timestamp() THEN
   RAISE EXCEPTION 'Provider fetch prerequisite incomplete or stale' USING ERRCODE='42501';
  END IF;
 END LOOP;
 RETURN c.encrypted_credentials;
END $$;


ALTER FUNCTION public.builderad_provider_credential(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_provider_custody_scope(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 IF p_business IS NULL OR p_business<1 THEN RAISE EXCEPTION 'Exact Business custody context required' USING ERRCODE='42501'; END IF;
 IF p_action='source.read' THEN
  PERFORM public.builderad_source_require(p_action,p_source,p_business,p_resource,p_operation,p_purpose,p_session);
 ELSE PERFORM public.builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action); END IF;
 IF NOT EXISTS(SELECT 1 FROM public.source_registry s WHERE s.id=p_source AND s.family=p_provider AND s.declared_external_id=p_resource
  AND s.custody_kind='business_native' AND s.business_id=p_business)
 THEN RAISE EXCEPTION 'Declared provider custody does not match its exact Source' USING ERRCODE='42501'; END IF;
END
$$;


ALTER FUNCTION public.builderad_provider_custody_scope(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_provider_request_gate(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text, p_expected_version bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE v bigint;
BEGIN
 IF p_action NOT IN ('provider.connect','provider.read') OR p_expected_version IS NULL OR p_expected_version<0 THEN
  RAISE EXCEPTION 'Provider request gate denied' USING ERRCODE='42501';
 END IF;
 -- Same exact namespace as custody commands: also fence the initial absent-row epoch.
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.access.'||encode(sha256(convert_to(
  jsonb_build_array(NULL,p_source,p_provider,p_resource,p_operation,p_purpose)::text,'UTF8')),'hex'),0));
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action);
 IF p_action='provider.read' THEN
  -- Invoke, but never return, encrypted custody after current fetch-prerequisite checks.
  PERFORM builderad_provider_credential(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose);
 END IF;
 SELECT version INTO v FROM provider_access WHERE source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR SHARE;
 -- The row lock may wait. Recheck time-sensitive authority after that wait.
 PERFORM builderad_provider_require(p_business,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action);
 IF coalesce(v,0)<>p_expected_version THEN
  RAISE EXCEPTION 'Provider access epoch changed before request' USING ERRCODE='42501';
 END IF;
END $$;


ALTER FUNCTION public.builderad_provider_request_gate(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text, p_expected_version bigint) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_provider_require(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE a bigint;
BEGIN
 IF p_action NOT IN ('provider.connect','provider.read','provider.revoke') OR
    p_provider NOT IN ('meta','google','snapchat','tiktok','x') OR p_operation NOT IN ('report.read','discovery.read') THEN
  RAISE EXCEPTION 'Provider effect denied' USING ERRCODE='42501';
 END IF;
 a:=builderad_source_actor(p_business,p_session,p_action<>'provider.read',p_action<>'provider.read');
 IF NOT EXISTS(SELECT 1 FROM source_registry WHERE id=p_source AND custody_kind='business_native' AND business_id=p_business)
 THEN RAISE EXCEPTION 'Exact Business Source required' USING ERRCODE='42501'; END IF;
 a:=builderad_private.business_source_require(p_action,p_source,p_resource,p_operation,p_purpose,p_session);
 PERFORM 1 FROM source_registry WHERE id=p_source AND custody_kind='business_native' AND business_id=p_business FOR SHARE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=a AND scope_kind='source'
  AND source_target_id=p_source AND source_resource=p_resource AND source_operation=p_operation AND source_purpose=p_purpose AND action=p_action FOR SHARE;
 -- Recheck every current/time-sensitive condition after the last possible wait.
 PERFORM builderad_source_actor(p_business,p_session,p_action<>'provider.read',p_action<>'provider.read');
 IF NOT builderad_source_has(p_action,p_source,p_business,p_resource,p_operation,p_purpose)
    OR(p_action='provider.read' AND NOT builderad_source_has('source.read',p_source,p_business,p_resource,p_operation,p_purpose))
    OR NOT EXISTS (SELECT 1 FROM source_registry WHERE id=p_source AND custody_kind='business_native' AND business_id=p_business
      AND family=p_provider AND declared_external_id=p_resource AND status<>'REVOKED') THEN
  RAISE EXCEPTION 'Exact current provider authority required' USING ERRCODE='42501';
 END IF;
 RETURN a;
END
$$;


ALTER FUNCTION public.builderad_provider_require(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_snapchat_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous snapchat_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Snapchat attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'snapchat',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'snapchat',r,'report.read',u,'provider.read',av);
 -- Same observation fence: no other command can accept a different generation during this native fetch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'snapchat',r,'report.read',u,'provider.read',av);
 SELECT * INTO previous FROM snapchat_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Snapchat attempt replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN previous.encrypted_result;
 END IF;
 IF coalesce((SELECT generation FROM provider_observation_streams WHERE namespace=ns),0)<>expected THEN
  RAISE EXCEPTION 'Snapchat attempt generation conflict' USING ERRCODE='23505';
 END IF;
 RETURN NULL;
END $_$;


ALTER FUNCTION public.builderad_snapchat_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_snapchat_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous snapchat_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid Snapchat attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'snapchat',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'snapchat',r,'report.read',u,'provider.read',av);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'snapchat',r,'report.read',u,'provider.read',av);
 IF result_fp IS NULL OR result_fp !~ '^[a-f0-9]{64}$' OR cipher IS NULL OR length(cipher) NOT BETWEEN 1 AND 65536 THEN
  RAISE EXCEPTION 'Invalid Snapchat scheduling envelope' USING ERRCODE='22023';
 END IF;
 SELECT * INTO previous FROM snapchat_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.result_fingerprint<>result_fp OR previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'Snapchat scheduling replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 -- Journal acceptance already incremented the stream, so validate the immutable matching command directly.
 IF NOT EXISTS(SELECT 1 FROM provider_observation_runs WHERE namespace=ns AND command_id=c AND generation=expected+1) THEN
  RAISE EXCEPTION 'Snapchat scheduling requires corresponding accepted run' USING ERRCODE='23514';
 END IF;
 INSERT INTO snapchat_read_attempts(namespace,command_id,generation,request_fingerprint,result_fingerprint,encrypted_result)
 VALUES(ns,c,expected+1,fp,result_fp,cipher);
 PERFORM builderad_provider_request_gate(w,se,s,'snapchat',r,'report.read',u,'provider.read',av);
END $_$;


ALTER FUNCTION public.builderad_snapchat_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_snapchat_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE c provider_access%ROWTYPE;
BEGIN
 IF p_operation IS DISTINCT FROM 'report.read' OR p_purpose IS DISTINCT FROM 'advertising_analysis' THEN
  RAISE EXCEPTION 'Snapchat refresh scope denied' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_request_gate(p_business,p_session,p_source,'snapchat',p_resource,p_operation,p_purpose,'provider.connect',p_version);
 IF NOT EXISTS (SELECT 1 FROM source_registry WHERE business_id=p_business AND custody_kind='business_native' AND id=p_source
  AND family='snapchat' AND namespace='ad_account' AND declared_external_id=p_resource) THEN
  RAISE EXCEPTION 'Snapchat refresh identity denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO c FROM provider_access WHERE source_id=p_source AND provider='snapchat'
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR UPDATE;
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR c.encrypted_credentials IS NULL THEN
  RAISE EXCEPTION 'Snapchat refresh custody unavailable' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_require(p_business,p_session,p_source,'snapchat',p_resource,p_operation,p_purpose,'provider.connect');
 RETURN c.encrypted_credentials;
END $$;


ALTER FUNCTION public.builderad_snapchat_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_snapchat_renewal_failure(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint, p_command uuid, p_failure text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE prior snapchat_renewal_failures%ROWTYPE;
BEGIN
 IF p_operation IS DISTINCT FROM 'report.read' OR p_purpose IS DISTINCT FROM 'advertising_analysis'
  OR p_version IS NULL OR p_version<1 OR p_command IS NULL OR p_failure IS NULL
  OR p_failure !~ '^snapchat_[a-z_]{1,65}$' THEN
  RAISE EXCEPTION 'Snapchat renewal evidence denied' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_request_gate(p_business,p_session,p_source,'snapchat',p_resource,p_operation,p_purpose,'provider.connect',p_version);
 IF NOT EXISTS (SELECT 1 FROM source_registry WHERE business_id=p_business AND custody_kind='business_native' AND id=p_source
  AND family='snapchat' AND namespace='ad_account' AND declared_external_id=p_resource) THEN
  RAISE EXCEPTION 'Snapchat renewal identity denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO prior FROM snapchat_renewal_failures WHERE source_id=p_source
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose AND command_id=p_command;
 IF FOUND THEN
  IF prior.access_version<>p_version OR prior.failure<>p_failure THEN
   RAISE EXCEPTION 'Snapchat renewal command conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 INSERT INTO snapchat_renewal_failures(source_id,resource,operation,purpose,access_version,command_id,failure)
  VALUES(p_source,p_resource,p_operation,p_purpose,p_version,p_command,p_failure);
END $_$;


ALTER FUNCTION public.builderad_snapchat_renewal_failure(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint, p_command uuid, p_failure text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_tiktok_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous tiktok_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid TikTok attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'tiktok',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'tiktok',r,'report.read',u,'provider.read',av);
 -- Same observation fence: no other command can accept a different generation during this native fetch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'tiktok',r,'report.read',u,'provider.read',av);
 SELECT * INTO previous FROM tiktok_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'TikTok attempt replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN previous.encrypted_result;
 END IF;
 IF coalesce((SELECT generation FROM provider_observation_streams WHERE namespace=ns),0)<>expected THEN
  RAISE EXCEPTION 'TikTok attempt generation conflict' USING ERRCODE='23505';
 END IF;
 RETURN NULL;
END $_$;


ALTER FUNCTION public.builderad_tiktok_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_tiktok_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous tiktok_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid TikTok attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'tiktok',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'tiktok',r,'report.read',u,'provider.read',av);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'tiktok',r,'report.read',u,'provider.read',av);
 IF result_fp IS NULL OR result_fp !~ '^[a-f0-9]{64}$' OR cipher IS NULL OR length(cipher) NOT BETWEEN 1 AND 65536 THEN
  RAISE EXCEPTION 'Invalid TikTok scheduling envelope' USING ERRCODE='22023';
 END IF;
 SELECT * INTO previous FROM tiktok_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.result_fingerprint<>result_fp OR previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'TikTok scheduling replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 -- Journal acceptance already incremented the stream, so validate the immutable matching command directly.
 IF NOT EXISTS(SELECT 1 FROM provider_observation_runs WHERE namespace=ns AND command_id=c AND generation=expected+1) THEN
  RAISE EXCEPTION 'TikTok scheduling requires corresponding accepted run' USING ERRCODE='23514';
 END IF;
 INSERT INTO tiktok_read_attempts(namespace,command_id,generation,request_fingerprint,result_fingerprint,encrypted_result)
 VALUES(ns,c,expected+1,fp,result_fp,cipher);
 PERFORM builderad_provider_request_gate(w,se,s,'tiktok',r,'report.read',u,'provider.read',av);
END $_$;


ALTER FUNCTION public.builderad_tiktok_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_x_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous x_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid X attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'x',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'x',r,'report.read',u,'provider.read',av);
 -- Same observation fence: no other command can accept a different generation during this native fetch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'x',r,'report.read',u,'provider.read',av);
 SELECT * INTO previous FROM x_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'X attempt replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN previous.encrypted_result;
 END IF;
 IF coalesce((SELECT generation FROM provider_observation_streams WHERE namespace=ns),0)<>expected THEN
  RAISE EXCEPTION 'X attempt generation conflict' USING ERRCODE='23505';
 END IF;
 RETURN NULL;
END $_$;


ALTER FUNCTION public.builderad_x_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_x_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE ns text; previous x_read_attempts%ROWTYPE;
BEGIN
 IF t IS NULL OR t !~ '^[a-zA-Z0-9_.:-]{1,128}$' OR c IS NULL OR av IS NULL OR av<1
    OR expected IS NULL OR expected<0 OR fp IS NULL OR fp !~ '^[a-f0-9]{64}$' THEN
  RAISE EXCEPTION 'Invalid X attempt request' USING ERRCODE='22023';
 END IF;
 ns:=builderad_observation_namespace(w,s,'x',r,'report.read',u,t);
 PERFORM builderad_provider_request_gate(w,se,s,'x',r,'report.read',u,'provider.read',av);
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.observation.'||ns,0));
 PERFORM builderad_provider_request_gate(w,se,s,'x',r,'report.read',u,'provider.read',av);
 IF result_fp IS NULL OR result_fp !~ '^[a-f0-9]{64}$' OR cipher IS NULL OR length(cipher) NOT BETWEEN 1 AND 65536 THEN
  RAISE EXCEPTION 'Invalid X scheduling envelope' USING ERRCODE='22023';
 END IF;
 SELECT * INTO previous FROM x_read_attempts WHERE namespace=ns AND command_id=c;
 IF FOUND THEN
  IF previous.result_fingerprint<>result_fp OR previous.request_fingerprint<>fp OR previous.generation<>expected+1 THEN
   RAISE EXCEPTION 'X scheduling replay conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 -- Journal acceptance already incremented the stream, so validate the immutable matching command directly.
 IF NOT EXISTS(SELECT 1 FROM provider_observation_runs WHERE namespace=ns AND command_id=c AND generation=expected+1) THEN
  RAISE EXCEPTION 'X scheduling requires corresponding accepted run' USING ERRCODE='23514';
 END IF;
 INSERT INTO x_read_attempts(namespace,command_id,generation,request_fingerprint,result_fingerprint,encrypted_result)
 VALUES(ns,c,expected+1,fp,result_fp,cipher);
 PERFORM builderad_provider_request_gate(w,se,s,'x',r,'report.read',u,'provider.read',av);
END $_$;


ALTER FUNCTION public.builderad_x_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_source_actor(p_business bigint, p_session text, p_owner boolean, p_fresh boolean) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE a bigint;
BEGIN
 IF p_business IS NULL OR p_business<1 THEN RAISE EXCEPTION 'Exact Business Source context required' USING ERRCODE='42501'; END IF;
 a:=builderad_private.principal(p_session,false);
 IF p_fresh AND NOT EXISTS(SELECT 1 FROM public.security_sessions WHERE user_id=a AND session_key_hash=p_session
  AND authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
 THEN RAISE EXCEPTION 'Fresh Source administrator authentication required' USING ERRCODE='42501'; END IF;
 PERFORM set_config('app.source_session',p_session,true);
 PERFORM set_config('app.source_session_signature',
  (SELECT encode(hmac(convert_to(current_setting('app.signed_context')||'|'||p_session,'UTF8'),secret,'sha256'),'hex') FROM public.builderad_context_secret WHERE singleton),true);
 RETURN a;
END
$$;


ALTER FUNCTION public.builderad_source_actor(p_business bigint, p_session text, p_owner boolean, p_fresh boolean) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_source_begin_read(p_business bigint, p_session text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 PERFORM builderad_source_actor(p_business,p_session,false,false);
END $$;


ALTER FUNCTION public.builderad_source_begin_read(p_business bigint, p_session text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_source_read_scope(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 PERFORM builderad_source_require('source.read',p_source,p_business,p_resource,p_operation,p_purpose,p_session);
END $$;


ALTER FUNCTION public.builderad_source_read_scope(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_source_has(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 RETURN p_business IS NOT NULL AND p_business>0 AND current_setting('app.source_session_signature',true)=
  (SELECT encode(hmac(convert_to(current_setting('app.signed_context',true)||'|'||current_setting('app.source_session',true),'UTF8'),secret,'sha256'),'hex') FROM public.builderad_context_secret WHERE singleton)
  AND EXISTS(SELECT 1 FROM public.builderad_signed_context() c JOIN public.security_action_grants g ON g.subject_user_id=c.actor_user_id
   JOIN public.source_registry s ON s.id=g.source_target_id AND s.custody_kind='business_native' AND s.business_id=p_business
   WHERE c.phase='principal' AND g.scope_kind='source' AND g.source_target_id=p_source
   AND g.action=p_action AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose
   AND builderad_private.business_source_grant_current(g.id,c.actor_user_id,current_setting('app.source_session',true),false));
END
$$;


ALTER FUNCTION public.builderad_source_has(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text) OWNER TO builderad_owner;

CREATE FUNCTION public.builderad_source_require(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text, p_session text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE a bigint;
BEGIN
 a:=public.builderad_source_actor(p_business,p_session,p_action<>'source.read',p_action<>'source.read');
 IF NOT EXISTS(SELECT 1 FROM public.source_registry WHERE id=p_source AND custody_kind='business_native' AND business_id=p_business)
 THEN RAISE EXCEPTION 'Exact Business Source required' USING ERRCODE='42501'; END IF;
 a:=builderad_private.business_source_require(p_action,p_source,p_resource,p_operation,p_purpose,p_session);
 a:=public.builderad_source_actor(p_business,p_session,p_action<>'source.read',p_action<>'source.read');
 IF NOT public.builderad_source_has(p_action,p_source,p_business,p_resource,p_operation,p_purpose)
 THEN RAISE EXCEPTION 'Exact current Source grant required' USING ERRCODE='42501'; END IF;
 RETURN a;
END
$$;


ALTER FUNCTION public.builderad_source_require(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text, p_session text) OWNER TO builderad_owner;

-- Preserve accepted function privileges.
REVOKE ALL ON FUNCTION public.builderad_google_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_google_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_google_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_google_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_google_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_google_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_meta_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_meta_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_meta_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_meta_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_observation_accept(w bigint, se text, s uuid, p text, r text, o text, u text, t text, c uuid, expected bigint, av bigint, payload jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_observation_accept(w bigint, se text, s uuid, p text, r text, o text, u text, t text, c uuid, expected bigint, av bigint, payload jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_observation_namespace(w bigint, s uuid, p text, r text, o text, u text, t text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.builderad_observation_progress(w bigint, se text, s uuid, p text, r text, o text, u text, t text, av bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_observation_progress(w bigint, se text, s uuid, p text, r text, o text, u text, t text, av bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_provider_access_command(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_command uuid, p_expected_version bigint, p_effect text, p_payload jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_provider_access_command(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_command uuid, p_expected_version bigint, p_effect text, p_payload jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_provider_credential(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_provider_credential(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_provider_custody_scope(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_provider_custody_scope(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_provider_request_gate(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text, p_expected_version bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_provider_request_gate(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text, p_expected_version bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_provider_require(p_business bigint, p_session text, p_source uuid, p_provider text, p_resource text, p_operation text, p_purpose text, p_action text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.builderad_snapchat_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_snapchat_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_snapchat_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_snapchat_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_snapchat_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_snapchat_connection_credential(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_snapchat_renewal_failure(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint, p_command uuid, p_failure text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_snapchat_renewal_failure(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_version bigint, p_command uuid, p_failure text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_source_actor(p_business bigint, p_session text, p_owner boolean, p_fresh boolean) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.builderad_source_begin_read(p_business bigint, p_session text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_source_begin_read(p_business bigint, p_session text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_source_has(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_source_has(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_source_has(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text) TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_source_read_scope(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_source_read_scope(p_business bigint, p_session text, p_source uuid, p_resource text, p_operation text, p_purpose text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_source_require(p_action text, p_source uuid, p_business bigint, p_resource text, p_operation text, p_purpose text, p_session text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.builderad_tiktok_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_tiktok_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_tiktok_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_tiktok_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_x_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_x_attempt_begin(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_x_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_x_attempt_finish(w bigint, se text, s uuid, r text, u text, t text, c uuid, av bigint, expected bigint, fp text, result_fp text, cipher text) TO builderad_app;
