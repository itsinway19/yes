-- Google-specific encrypted retry/poll receipts. These are not provider acceptance progress or grants.
CREATE TABLE google_read_attempts (
 namespace text NOT NULL, command_id uuid NOT NULL, generation bigint NOT NULL,
 request_fingerprint text NOT NULL CHECK(request_fingerprint ~ '^[a-f0-9]{64}$'),
 result_fingerprint text NOT NULL CHECK(result_fingerprint ~ '^[a-f0-9]{64}$'),
 encrypted_result text NOT NULL CHECK(length(encrypted_result) BETWEEN 1 AND 65536),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(namespace,command_id),
 FOREIGN KEY(namespace,generation) REFERENCES provider_observation_runs(namespace,generation)
);
ALTER TABLE google_read_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE google_read_attempts FORCE ROW LEVEL SECURITY;
REVOKE ALL ON google_read_attempts FROM PUBLIC,builderad_app,builderad_authority;
CREATE POLICY google_attempt_owner ON google_read_attempts TO builderad_owner USING(true) WITH CHECK(true);
CREATE TRIGGER google_attempt_immutable BEFORE UPDATE OR DELETE ON google_read_attempts
 FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();
