-- Expired reporting access must not prevent an explicitly authorized OAuth refresh.
-- This connection-only entry point does not weaken report fetch/readiness prerequisites.
CREATE OR REPLACE FUNCTION builderad_google_connection_credential(
 p_workspace bigint,p_session text,p_source uuid,p_resource text,p_operation text,p_purpose text,p_version bigint)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE c provider_access%ROWTYPE;
BEGIN
 IF p_operation IS DISTINCT FROM 'report.read' OR p_purpose IS DISTINCT FROM 'advertising_analysis' THEN
  RAISE EXCEPTION 'Google refresh scope denied' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_request_gate(p_workspace,p_session,p_source,'google',p_resource,p_operation,p_purpose,'provider.connect',p_version);
 IF NOT EXISTS (SELECT 1 FROM source_registry WHERE workspace_id=p_workspace AND id=p_source
  AND family='google' AND namespace='ad_account' AND declared_external_id=p_resource) THEN
  RAISE EXCEPTION 'Google refresh identity denied' USING ERRCODE='42501';
 END IF;
 SELECT * INTO c FROM provider_access WHERE workspace_id=p_workspace AND source_id=p_source AND provider='google'
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR UPDATE;
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR c.encrypted_credentials IS NULL THEN
  RAISE EXCEPTION 'Google refresh custody unavailable' USING ERRCODE='42501';
 END IF;
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,'google',p_resource,p_operation,p_purpose,'provider.connect');
 RETURN c.encrypted_credentials;
END $$;
REVOKE ALL ON FUNCTION builderad_google_connection_credential(bigint,text,uuid,text,text,text,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_google_connection_credential(bigint,text,uuid,text,text,text,bigint) TO builderad_app;
