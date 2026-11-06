-- Renewal evidence uses connect authority, never an unproved report/privacy grant.
-- A failed verification cannot establish that retained old tokens remain usable.
CREATE TABLE snapchat_renewal_failures (
 workspace_id bigint NOT NULL,
 source_id uuid NOT NULL,
 resource varchar(180) NOT NULL,
 operation varchar(80) NOT NULL CHECK (operation='report.read'),
 purpose varchar(128) NOT NULL CHECK (purpose='advertising_analysis'),
 access_version bigint NOT NULL CHECK (access_version>0),
 command_id uuid NOT NULL,
 failure text NOT NULL CHECK (failure ~ '^snapchat_[a-z_]{1,65}$'),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (workspace_id,source_id,resource,operation,purpose,command_id),
 FOREIGN KEY (workspace_id,source_id,resource,operation,purpose)
  REFERENCES source_capabilities(workspace_id,source_id,resource,operation,purpose) ON DELETE RESTRICT
);
ALTER TABLE snapchat_renewal_failures ENABLE ROW LEVEL SECURITY;
ALTER TABLE snapchat_renewal_failures FORCE ROW LEVEL SECURITY;
REVOKE ALL ON snapchat_renewal_failures FROM PUBLIC,builderad_app,builderad_authority;
CREATE POLICY snapchat_renewal_owner ON snapchat_renewal_failures TO builderad_owner USING (true) WITH CHECK (true);
GRANT SELECT ON snapchat_renewal_failures TO builderad_app;
CREATE POLICY snapchat_renewal_read ON snapchat_renewal_failures FOR SELECT TO builderad_app USING (
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE TRIGGER snapchat_renewal_immutable BEFORE UPDATE OR DELETE ON snapchat_renewal_failures
 FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();
