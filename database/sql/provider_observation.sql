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
