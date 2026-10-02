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

CREATE OR REPLACE FUNCTION builderad_snapchat_renewal_failure(
 p_workspace bigint,p_session text,p_source uuid,p_resource text,p_operation text,p_purpose text,
 p_version bigint,p_command uuid,p_failure text)
RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE prior snapchat_renewal_failures%ROWTYPE;
BEGIN
 IF p_operation IS DISTINCT FROM 'report.read' OR p_purpose IS DISTINCT FROM 'advertising_analysis'
  OR p_version IS NULL OR p_version<1 OR p_command IS NULL OR p_failure IS NULL
  OR p_failure !~ '^snapchat_[a-z_]{1,65}$' THEN
  RAISE EXCEPTION 'Snapchat renewal evidence denied' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_request_gate(p_workspace,p_session,p_source,'snapchat',p_resource,p_operation,p_purpose,'provider.connect',p_version);
 IF NOT EXISTS (SELECT 1 FROM source_registry WHERE workspace_id=p_workspace AND id=p_source
  AND family='snapchat' AND namespace='ad_account' AND declared_external_id=p_resource) THEN
  RAISE EXCEPTION 'Snapchat renewal identity denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO prior FROM snapchat_renewal_failures WHERE workspace_id=p_workspace AND source_id=p_source
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose AND command_id=p_command;
 IF FOUND THEN
  IF prior.access_version<>p_version OR prior.failure<>p_failure THEN
   RAISE EXCEPTION 'Snapchat renewal command conflict' USING ERRCODE='23505';
  END IF;
  RETURN;
 END IF;
 INSERT INTO snapchat_renewal_failures(workspace_id,source_id,resource,operation,purpose,access_version,command_id,failure)
  VALUES(p_workspace,p_source,p_resource,p_operation,p_purpose,p_version,p_command,p_failure);
END $$;
REVOKE ALL ON FUNCTION builderad_snapchat_renewal_failure(bigint,text,uuid,text,text,text,bigint,uuid,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_snapchat_renewal_failure(bigint,text,uuid,text,text,text,bigint,uuid,text) TO builderad_app;

-- Preserve the common four fetch prerequisites; add only Snapchat's current renewal uncertainty.
CREATE OR REPLACE FUNCTION builderad_provider_credential(p_workspace bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE c provider_access%ROWTYPE; axis text; f jsonb;
BEGIN
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 SELECT * INTO c FROM provider_access WHERE workspace_id=p_workspace AND source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR SHARE;
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR
    (c.credential_expires_at IS NOT NULL AND c.credential_expires_at<=clock_timestamp()) THEN
  RAISE EXCEPTION 'Provider credential unavailable' USING ERRCODE='42501';
 END IF;
 IF p_provider='snapchat' AND EXISTS (SELECT 1 FROM snapchat_renewal_failures WHERE workspace_id=p_workspace
  AND source_id=p_source AND resource=p_resource AND operation=p_operation AND purpose=p_purpose AND access_version=c.version) THEN
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
REVOKE ALL ON FUNCTION builderad_provider_credential(bigint,text,uuid,text,text,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_provider_credential(bigint,text,uuid,text,text,text,text) TO builderad_app;
