-- TikTok-specific encrypted retry/poll receipts. These are not provider acceptance progress or grants.
CREATE TABLE tiktok_read_attempts (
 namespace text NOT NULL, command_id uuid NOT NULL, generation bigint NOT NULL,
 request_fingerprint text NOT NULL CHECK(request_fingerprint ~ '^[a-f0-9]{64}$'),
 result_fingerprint text NOT NULL CHECK(result_fingerprint ~ '^[a-f0-9]{64}$'),
 encrypted_result text NOT NULL CHECK(length(encrypted_result) BETWEEN 1 AND 65536),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(namespace,command_id),
 FOREIGN KEY(namespace,generation) REFERENCES provider_observation_runs(namespace,generation)
);
ALTER TABLE tiktok_read_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE tiktok_read_attempts FORCE ROW LEVEL SECURITY;
REVOKE ALL ON tiktok_read_attempts FROM PUBLIC,builderad_app,builderad_authority;
CREATE POLICY tiktok_attempt_owner ON tiktok_read_attempts TO builderad_owner USING(true) WITH CHECK(true);
CREATE TRIGGER tiktok_attempt_immutable BEFORE UPDATE OR DELETE ON tiktok_read_attempts
 FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();

CREATE OR REPLACE FUNCTION builderad_tiktok_attempt_begin(w bigint,se text,s uuid,r text,u text,t text,c uuid,av bigint,expected bigint,fp text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
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
END $$;
REVOKE ALL ON FUNCTION builderad_tiktok_attempt_begin(bigint,text,uuid,text,text,text,uuid,bigint,bigint,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_tiktok_attempt_begin(bigint,text,uuid,text,text,text,uuid,bigint,bigint,text) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_tiktok_attempt_finish(w bigint,se text,s uuid,r text,u text,t text,c uuid,av bigint,expected bigint,fp text,result_fp text,cipher text)
RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
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
END $$;
REVOKE ALL ON FUNCTION builderad_tiktok_attempt_finish(bigint,text,uuid,text,text,text,uuid,bigint,bigint,text,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_tiktok_attempt_finish(bigint,text,uuid,text,text,text,uuid,bigint,bigint,text,text,text) TO builderad_app;
