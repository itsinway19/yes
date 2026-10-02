-- Narrow runtime gate over the existing signed exact-source actor and custody guards.
-- No grant, credential, readiness evidence or source truth is written by this function.
CREATE OR REPLACE FUNCTION builderad_provider_request_gate(
 p_workspace bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text,
 p_action text,p_expected_version bigint)
RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE v bigint;
BEGIN
 IF p_action NOT IN ('provider.connect','provider.read') OR p_expected_version IS NULL OR p_expected_version<0 THEN
  RAISE EXCEPTION 'Provider request gate denied' USING ERRCODE='42501';
 END IF;
 -- Same exact namespace as custody commands: also fence the initial absent-row epoch.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb2.access.'||encode(sha256(convert_to(
  jsonb_build_array(p_workspace,p_source,p_provider,p_resource,p_operation,p_purpose)::text,'UTF8')),'hex'),0));
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action);
 IF p_action='provider.read' THEN
  -- Invoke, but never return, encrypted custody after current fetch-prerequisite checks.
  PERFORM builderad_provider_credential(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose);
 END IF;
 SELECT version INTO v FROM provider_access WHERE workspace_id=p_workspace AND source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR SHARE;
 -- The row lock may wait. Recheck time-sensitive authority after that wait.
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action);
 IF coalesce(v,0)<>p_expected_version THEN
  RAISE EXCEPTION 'Provider access epoch changed before request' USING ERRCODE='42501';
 END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_provider_request_gate(bigint,text,uuid,text,text,text,text,text,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_provider_request_gate(bigint,text,uuid,text,text,text,text,text,bigint) TO builderad_app;
