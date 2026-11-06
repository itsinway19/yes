CREATE OR REPLACE FUNCTION builderad_provider_require(p_workspace bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text,p_action text)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE a bigint;
BEGIN
 IF p_action NOT IN ('provider.connect','provider.read','provider.revoke') OR
    p_provider NOT IN ('meta','google','snapchat','tiktok','x') OR p_operation NOT IN ('report.read','discovery.read') THEN
  RAISE EXCEPTION 'Provider effect denied' USING ERRCODE='42501';
 END IF;
 IF p_workspace IS NULL THEN
  a:=builderad_source_actor(NULL,p_session,p_action<>'provider.read',p_action<>'provider.read');
  a:=builderad_private.business_source_require(p_action,p_source,p_resource,p_operation,p_purpose,p_session);
 ELSE
  a:=builderad_source_require(CASE WHEN p_action='provider.read' THEN 'source.read' ELSE p_action END,
   p_source,p_workspace,p_resource,p_operation,p_purpose,p_session);
 END IF;
 PERFORM 1 FROM source_registry WHERE id=p_source AND workspace_id IS NOT DISTINCT FROM p_workspace FOR SHARE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=a AND workspace_id IS NOT DISTINCT FROM p_workspace AND scope_kind='source'
  AND source_target_id=p_source AND source_resource=p_resource AND source_operation=p_operation AND source_purpose=p_purpose AND action=p_action FOR SHARE;
 -- Recheck every current/time-sensitive condition after the last possible wait.
 PERFORM builderad_source_actor(p_workspace,p_session,p_action<>'provider.read',p_action<>'provider.read');
 IF NOT builderad_source_has(p_action,p_source,p_workspace,p_resource,p_operation,p_purpose)
    OR(p_workspace IS NULL AND p_action='provider.read' AND NOT builderad_source_has('source.read',p_source,NULL,p_resource,p_operation,p_purpose))
    OR NOT EXISTS (SELECT 1 FROM source_registry WHERE id=p_source AND workspace_id IS NOT DISTINCT FROM p_workspace
      AND family=p_provider AND declared_external_id=p_resource AND status<>'REVOKED') THEN
  RAISE EXCEPTION 'Exact current provider authority required' USING ERRCODE='42501';
 END IF;
 RETURN a;
END $$;
REVOKE ALL ON FUNCTION builderad_provider_require(bigint,text,uuid,text,text,text,text,text) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_provider_access_command(p_workspace bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text,
 p_command uuid,p_expected_version bigint,p_effect text,p_payload jsonb)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE ns text; fp text; receipt provider_access_receipts%ROWTYPE; previous provider_access%ROWTYPE;
 a bigint; act text; next_version bigint; result jsonb;
BEGIN
 IF p_effect IS NULL OR p_effect NOT IN ('connect','reconnect','revoke') OR p_expected_version IS NULL OR p_expected_version<0 OR p_command IS NULL
    OR jsonb_typeof(p_payload) IS DISTINCT FROM 'object' THEN
  RAISE EXCEPTION 'Invalid provider command' USING ERRCODE='22023';
 END IF;
 act:=CASE WHEN p_effect='revoke' THEN 'provider.revoke' ELSE 'provider.connect' END;
 ns:='bb2.access.'||encode(sha256(convert_to(jsonb_build_array(p_workspace,p_source,p_provider,p_resource,p_operation,p_purpose)::text,'UTF8')),'hex');
 IF p_workspace IS NULL THEN
  -- Business custody takes K1 before the access namespace. Bootstrap and
  -- revocation take the same order; no access holder may wait backwards on K1.
  PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended(ns,0));
 a:=builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 -- Cipher randomness is excluded; the server HMAC binds credential content to this exact scope.
 fp:=encode(sha256(convert_to(jsonb_build_array(p_expected_version,p_effect,p_payload-'encrypted_credentials')::text,'UTF8')),'hex');
 SELECT * INTO receipt FROM provider_access_receipts WHERE namespace=ns AND command_id=p_command;
 IF FOUND THEN
  IF receipt.fingerprint<>fp THEN RAISE EXCEPTION 'Provider command conflict' USING ERRCODE='23505'; END IF;
  RETURN receipt.result;
 END IF;
 SELECT * INTO previous FROM provider_access WHERE workspace_id IS NOT DISTINCT FROM p_workspace AND source_id=p_source AND provider=p_provider
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
 PERFORM builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,act);
 next_version:=coalesce(previous.version,0)+1;
 INSERT INTO provider_access (workspace_id,source_id,provider,resource,operation,purpose,version,local_state,native_state,encrypted_credentials,credential_expires_at,verification)
 VALUES (p_workspace,p_source,p_provider,p_resource,p_operation,p_purpose,next_version,
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
 INSERT INTO provider_access_receipts VALUES (ns,p_command,p_workspace,p_source,p_provider,p_resource,p_operation,p_purpose,fp,result);
 INSERT INTO provider_access_events(namespace,command_id,actor_user_id,actor_generation,effect,prior_version,version)
  VALUES (ns,p_command,a,(SELECT generation FROM principal_lifecycles WHERE user_id=a),p_effect,previous.version,next_version);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_provider_access_command(bigint,text,uuid,text,text,text,text,uuid,bigint,text,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_provider_access_command(bigint,text,uuid,text,text,text,text,uuid,bigint,text,jsonb) TO builderad_app;

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
 FOREACH axis IN ARRAY ARRAY['provider_support','provider_permission','credential','privacy_purpose','freshness','completeness','product_exposure'] LOOP
  f:=c.verification->axis;
  IF coalesce(f->>'state','')<>'VERIFIED' OR coalesce(f->>'provenance','')='' OR
     f->>'verified_at' IS NULL OR f->>'valid_until' IS NULL OR
     (f->>'verified_at')::timestamptz>clock_timestamp() OR
     (f->>'valid_until')::timestamptz<=(f->>'verified_at')::timestamptz OR
     (f->>'valid_until')::timestamptz<=clock_timestamp() THEN
   RAISE EXCEPTION 'Provider access evidence incomplete or stale' USING ERRCODE='42501';
  END IF;
 END LOOP;
 RETURN c.encrypted_credentials;
END $$;
REVOKE ALL ON FUNCTION builderad_provider_credential(bigint,text,uuid,text,text,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_provider_credential(bigint,text,uuid,text,text,text,text) TO builderad_app;
