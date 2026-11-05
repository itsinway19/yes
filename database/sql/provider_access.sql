-- BB2 custody primitives. No external account/app approval or permission is inferred from a stored credential.
ALTER TABLE security_action_grants DROP CONSTRAINT source_grant_shape;
ALTER TABLE security_action_grants ADD CONSTRAINT source_grant_shape CHECK (
 (scope_kind <> 'source' AND source_target_id IS NULL AND source_resource IS NULL AND source_operation IS NULL AND source_purpose IS NULL)
 OR (scope_kind = 'source' AND uuid_extract_version(source_target_id) = 7
     AND action IN ('source.register','source.manage','source.read','source.audit.recover','provider.connect','provider.read','provider.revoke')
     AND source_resource IS NOT NULL AND source_operation IS NOT NULL AND source_purpose IS NOT NULL
     AND length(trim(source_resource)) > 0 AND length(trim(source_operation)) > 0 AND length(trim(source_purpose)) > 0)
);

CREATE TABLE provider_access (
 workspace_id bigint NOT NULL,
 source_id uuid NOT NULL,
 provider text NOT NULL CHECK (provider IN ('meta','google','snapchat','tiktok','x')),
 resource varchar(180) NOT NULL,
 operation varchar(80) NOT NULL,
 purpose varchar(128) NOT NULL,
 version bigint NOT NULL CHECK (version>0),
 local_state text NOT NULL CHECK (local_state IN ('CREDENTIAL_STORED','REVOKED')),
 native_state varchar(80) NOT NULL CHECK (native_state ~ '^[A-Z][A-Z0-9_]{0,79}$'),
 encrypted_credentials text,
 credential_expires_at timestamptz,
 verification jsonb NOT NULL CHECK (jsonb_typeof(verification)='object'),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (workspace_id,source_id,provider,resource,operation,purpose),
 FOREIGN KEY (workspace_id,source_id,resource,operation,purpose)
  REFERENCES source_capabilities(workspace_id,source_id,resource,operation,purpose) ON DELETE RESTRICT,
 CHECK ((local_state='REVOKED' AND encrypted_credentials IS NULL) OR
        (local_state='CREDENTIAL_STORED' AND length(encrypted_credentials)>0))
);
CREATE TABLE provider_access_receipts (
 namespace text NOT NULL,
 command_id uuid NOT NULL,
 workspace_id bigint NOT NULL,
 source_id uuid NOT NULL,
 provider text NOT NULL,
 resource varchar(180) NOT NULL,
 operation varchar(80) NOT NULL,
 purpose varchar(128) NOT NULL,
 fingerprint text NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
 result jsonb NOT NULL,
 PRIMARY KEY (namespace,command_id),
 FOREIGN KEY (workspace_id,source_id,provider,resource,operation,purpose)
  REFERENCES provider_access(workspace_id,source_id,provider,resource,operation,purpose) ON DELETE RESTRICT
);
CREATE TABLE provider_access_events (
 namespace text NOT NULL,
 command_id uuid NOT NULL,
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_generation bigint NOT NULL CHECK (actor_generation>0),
 effect text NOT NULL CHECK (effect IN ('connect','reconnect','revoke')),
 prior_version bigint,
 version bigint NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (namespace,command_id),
 FOREIGN KEY (namespace,command_id) REFERENCES provider_access_receipts(namespace,command_id) ON DELETE RESTRICT
);
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['provider_access','provider_access_receipts','provider_access_events'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('REVOKE ALL ON %I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('CREATE POLICY provider_owner ON %I TO builderad_owner USING (true) WITH CHECK (true)',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['provider_access_receipts','provider_access_events'] LOOP
  EXECUTE format('CREATE TRIGGER provider_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable()',t);
 END LOOP;
END $$;
GRANT SELECT (workspace_id,source_id,provider,resource,operation,purpose,version,local_state,native_state,credential_expires_at,verification,recorded_at)
 ON provider_access TO builderad_app;
GRANT SELECT ON provider_access_receipts,provider_access_events TO builderad_app;
CREATE POLICY provider_access_read ON provider_access FOR SELECT TO builderad_app USING (
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE POLICY provider_receipt_read ON provider_access_receipts FOR SELECT TO builderad_app USING (
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE POLICY provider_event_read ON provider_access_events FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM provider_access_receipts r WHERE r.namespace=provider_access_events.namespace AND r.command_id=provider_access_events.command_id));
