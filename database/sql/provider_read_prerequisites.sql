-- Correct the fetch/readiness circularity without changing effect authority or product READY derivation.
-- Fetch must prove native support, permission, usable credentials and permitted privacy/purpose.
-- Freshness, coverage/completeness and product exposure govern use of the resulting observations;
-- they cannot be prerequisites for collecting the evidence that establishes their own state.
CREATE OR REPLACE FUNCTION builderad_provider_credential(p_workspace bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE c provider_access%ROWTYPE; axis text; f jsonb;
BEGIN
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 SELECT * INTO c FROM provider_access WHERE workspace_id IS NOT DISTINCT FROM p_workspace AND source_id=p_source AND provider=p_provider
  AND resource=p_resource AND operation=p_operation AND purpose=p_purpose FOR SHARE;
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,'provider.read');
 IF c.local_state IS DISTINCT FROM 'CREDENTIAL_STORED' OR
    (c.credential_expires_at IS NOT NULL AND c.credential_expires_at<=clock_timestamp()) THEN
  RAISE EXCEPTION 'Provider credential unavailable' USING ERRCODE='42501';
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
