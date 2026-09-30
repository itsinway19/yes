-- BB1: no provider credentials, provider calls or asserted external evidence.
ALTER TABLE security_action_grants ADD COLUMN source_target_id uuid;
ALTER TABLE security_action_grants ADD COLUMN source_resource varchar(180);
ALTER TABLE security_action_grants ADD COLUMN source_operation varchar(80);
ALTER TABLE security_action_grants ADD COLUMN source_purpose varchar(128);
ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_scope_check;
ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_scope_check CHECK (
 (scope_kind = 'platform' AND workspace_id IS NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL)
 OR (scope_kind = 'workspace' AND workspace_id IS NOT NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL)
 OR (scope_kind = 'commercial' AND commercial_target_id IS NOT NULL AND source = 'human')
 OR (scope_kind = 'source' AND workspace_id IS NOT NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL
     AND source_target_id IS NOT NULL AND source = 'source_owner_request' AND granted_by = subject_user_id)
);
ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_source_check;
ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_source_check CHECK (
 (source = 'system_registration' AND granted_by IS NULL AND action = 'workspace.create' AND scope_kind = 'platform')
 OR (source = 'system_workspace_creation' AND granted_by IS NULL AND action IN ('workspace.view','service.scope') AND scope_kind = 'workspace')
 OR (source = 'founder_bootstrap' AND granted_by IS NULL AND scope_kind = 'platform')
 OR (source = 'human' AND granted_by IS NOT NULL AND granted_by <> subject_user_id)
 OR (source = 'system_owner_reassessment' AND granted_by IS NULL AND action IN ('workspace.view','service.scope') AND scope_kind = 'workspace')
 OR (source = 'source_owner_request' AND scope_kind = 'source' AND granted_by = subject_user_id)
);
ALTER TABLE security_action_grants ADD CONSTRAINT source_grant_shape CHECK (
 (scope_kind <> 'source' AND source_target_id IS NULL AND source_resource IS NULL AND source_operation IS NULL AND source_purpose IS NULL)
 OR (scope_kind = 'source' AND uuid_extract_version(source_target_id) = 7
     AND action IN ('source.register','source.manage','source.read','source.audit.recover')
     AND source_resource IS NOT NULL AND source_operation IS NOT NULL AND source_purpose IS NOT NULL
     AND length(trim(source_resource)) > 0 AND length(trim(source_operation)) > 0 AND length(trim(source_purpose)) > 0)
);
CREATE UNIQUE INDEX source_grants_active_unique ON security_action_grants
 (subject_user_id,subject_generation,workspace_id,source_target_id,source_resource,source_operation,source_purpose,action)
 WHERE scope_kind = 'source' AND status = 'active';

CREATE TABLE source_registry (
 id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
 workspace_id bigint NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
 family varchar(40) NOT NULL CHECK (family ~ '^[a-z][a-z0-9_]{0,39}$'),
 namespace varchar(80) NOT NULL CHECK (length(trim(namespace)) > 0 AND namespace=trim(namespace)),
 declared_external_id varchar(180) NOT NULL CHECK (length(trim(declared_external_id)) > 0 AND declared_external_id=trim(declared_external_id)),
 label varchar(120) NOT NULL CHECK (length(trim(label)) > 0),
 version bigint NOT NULL CHECK (version > 0),
 status text NOT NULL CHECK (status IN ('UNVERIFIED','REVOKED')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE (id,workspace_id), UNIQUE (workspace_id,family,namespace,declared_external_id)
);
CREATE TABLE source_capabilities (
 workspace_id bigint NOT NULL,
 source_id uuid NOT NULL,
 resource varchar(180) NOT NULL CHECK (length(trim(resource)) > 0),
 operation varchar(80) NOT NULL CHECK (length(trim(operation)) > 0),
 purpose varchar(128) NOT NULL CHECK (length(trim(purpose)) > 0),
 declared_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (workspace_id,source_id,resource,operation,purpose),
 FOREIGN KEY (source_id,workspace_id) REFERENCES source_registry(id,workspace_id) ON DELETE RESTRICT
);
CREATE TABLE source_command_receipts (
 workspace_id bigint NOT NULL, command_id uuid NOT NULL, source_id uuid NOT NULL,
 effect text NOT NULL, resource varchar(180) NOT NULL, operation varchar(80) NOT NULL, purpose varchar(128) NOT NULL,
 fingerprint text NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'), result jsonb NOT NULL,
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_generation bigint NOT NULL,
 decided_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY (workspace_id,command_id),
 UNIQUE (workspace_id,command_id,source_id),
 FOREIGN KEY (source_id,workspace_id) REFERENCES source_registry(id,workspace_id) ON DELETE RESTRICT
);
CREATE TABLE source_versions (
 workspace_id bigint NOT NULL, source_id uuid NOT NULL, version bigint NOT NULL, prior_version bigint,
 command_id uuid NOT NULL, snapshot jsonb NOT NULL,
 PRIMARY KEY (workspace_id,source_id,version),
 CHECK (version>0 AND prior_version IS NOT DISTINCT FROM nullif(version-1,0)),
 FOREIGN KEY (workspace_id,command_id,source_id) REFERENCES source_command_receipts(workspace_id,command_id,source_id) ON DELETE RESTRICT
);
CREATE TABLE source_audit_obligations (
 id uuid PRIMARY KEY DEFAULT uuidv7(), workspace_id bigint NOT NULL, command_id uuid NOT NULL,
 source_id uuid NOT NULL, envelope jsonb NOT NULL, fingerprint text NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE (workspace_id,command_id), UNIQUE (id,workspace_id),
 FOREIGN KEY (workspace_id,command_id,source_id) REFERENCES source_command_receipts(workspace_id,command_id,source_id) ON DELETE RESTRICT
);
CREATE TABLE source_audit_delivery (
 obligation_id uuid PRIMARY KEY, workspace_id bigint NOT NULL,
 state text NOT NULL DEFAULT 'PENDING' CHECK (state IN ('PENDING','FAILED','ACKNOWLEDGED')),
 acknowledgment_id uuid, attempts bigint NOT NULL DEFAULT 0, updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY (obligation_id,workspace_id) REFERENCES source_audit_obligations(id,workspace_id) ON DELETE RESTRICT,
 CHECK ((state = 'ACKNOWLEDGED') = (acknowledgment_id IS NOT NULL))
);
-- Independent security custody. The source writer cannot manufacture acknowledgments.
CREATE TABLE security_source_audit_custody (
 obligation_id uuid PRIMARY KEY, acknowledgment_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
 fingerprint text NOT NULL CHECK (fingerprint ~ '^[0-9a-f]{64}$'), envelope jsonb NOT NULL,
 accepted_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- Current session is checked on the database clock even after waiting locks.
CREATE OR REPLACE FUNCTION builderad_source_actor(p_workspace bigint,p_session text,p_owner boolean,p_fresh boolean)
RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint; g bigint;
BEGIN
 IF session_user <> 'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id,l.generation INTO a,g FROM builderad_signed_context() c
 JOIN principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='advertiser'
 WHERE c.phase='workspace' AND c.workspace_id=p_workspace;
 IF a IS NULL THEN RAISE EXCEPTION 'Signed current Workspace required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM principal_lifecycles WHERE user_id=a FOR SHARE;
 PERFORM 1 FROM users WHERE id=a FOR SHARE;
 PERFORM 1 FROM workspaces WHERE id=p_workspace FOR SHARE;
 PERFORM 1 FROM workspace_memberships WHERE workspace_id=p_workspace FOR SHARE;
 PERFORM 1 FROM workspace_ownerships WHERE workspace_id=p_workspace FOR SHARE;
 PERFORM 1 FROM security_sessions WHERE user_id=a AND session_key_hash=p_session FOR SHARE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=a AND workspace_id=p_workspace AND action='workspace.view' FOR SHARE;
 IF NOT EXISTS (
  SELECT 1 FROM principal_lifecycles l JOIN users u ON u.id=l.user_id
  JOIN security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  JOIN workspace_memberships m ON m.user_id=l.user_id AND m.workspace_id=p_workspace AND m.status='active'
  JOIN workspaces w ON w.id=m.workspace_id AND w.status='active'
  JOIN workspace_ownerships o ON o.workspace_id=w.id
  JOIN workspace_memberships om ON om.workspace_id=w.id AND om.user_id=o.responsible_owner_user_id AND om.role='owner' AND om.status='active'
  JOIN principal_lifecycles ol ON ol.user_id=om.user_id AND ol.status='current' AND ol.principal_class='advertiser'
  JOIN users ou ON ou.id=ol.user_id AND ou.email_verified_at IS NOT NULL
  JOIN security_action_grants v ON v.subject_user_id=l.user_id AND v.subject_generation=l.generation
   AND v.workspace_id=w.id AND v.scope_kind='workspace' AND v.action='workspace.view'
  WHERE l.user_id=a AND l.generation=g AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL
   AND s.session_key_hash=p_session AND s.revoked_at IS NULL AND s.auth_method <> 'internal_passkey'
   AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
   AND (NOT p_fresh OR s.authenticated_at BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp())
   AND (NOT p_owner OR o.responsible_owner_user_id=a) AND o.version>0
   AND v.status='active' AND v.revoked_at IS NULL AND (v.expires_at IS NULL OR v.expires_at>clock_timestamp())
 ) THEN RAISE EXCEPTION 'Current source actor denied' USING ERRCODE='42501'; END IF;
 -- Bind source RLS to this verified server session and the existing signed transaction.
 PERFORM set_config('app.source_session',p_session,true);
 PERFORM set_config('app.source_session_signature',
  (SELECT encode(hmac(convert_to(current_setting('app.signed_context')||'|'||p_session,'UTF8'),secret,'sha256'),'hex')
   FROM builderad_context_secret WHERE singleton),true);
 RETURN a;
END $$;
REVOKE ALL ON FUNCTION builderad_source_actor(bigint,text,boolean,boolean) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_source_has(p_action text,p_source uuid,p_workspace bigint,p_resource text,p_operation text,p_purpose text)
RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
 SELECT EXISTS (
  SELECT 1 FROM builderad_signed_context() c
  JOIN principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='advertiser'
  JOIN users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
  JOIN security_sessions ss ON ss.user_id=l.user_id AND ss.lifecycle_generation=l.generation
   AND ss.session_key_hash=current_setting('app.source_session',true) AND ss.revoked_at IS NULL
   AND ss.auth_method<>'internal_passkey' AND ss.idle_expires_at>clock_timestamp() AND ss.absolute_expires_at>clock_timestamp()
  JOIN workspaces w ON w.id=c.workspace_id AND w.status='active'
  JOIN workspace_memberships m ON m.workspace_id=w.id AND m.user_id=l.user_id AND m.status='active'
  JOIN workspace_ownerships o ON o.workspace_id=w.id AND o.version>0
  JOIN principal_lifecycles ol ON ol.user_id=o.responsible_owner_user_id AND ol.status='current' AND ol.principal_class='advertiser'
  JOIN workspace_memberships om ON om.workspace_id=w.id AND om.user_id=ol.user_id AND om.status='active' AND om.role='owner'
  JOIN users ou ON ou.id=ol.user_id AND ou.email_verified_at IS NOT NULL
  JOIN security_action_grants v ON v.subject_user_id=l.user_id AND v.subject_generation=l.generation AND v.workspace_id=w.id
   AND v.scope_kind='workspace' AND v.action='workspace.view' AND v.status='active' AND v.revoked_at IS NULL
   AND (v.expires_at IS NULL OR v.expires_at>clock_timestamp())
  JOIN security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='workspace' AND c.workspace_id=p_workspace
   AND current_setting('app.source_session_signature',true) =
    (SELECT encode(hmac(convert_to(current_setting('app.signed_context',true)||'|'||current_setting('app.source_session',true),'UTF8'),secret,'sha256'),'hex')
     FROM builderad_context_secret WHERE singleton)
   AND g.scope_kind='source' AND g.workspace_id=p_workspace AND g.source_target_id=p_source
   AND g.action=p_action AND g.source_resource=p_resource AND g.source_operation=p_operation AND g.source_purpose=p_purpose
   AND g.status='active' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())
 )
$$;
REVOKE ALL ON FUNCTION builderad_source_has(text,uuid,bigint,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_has(text,uuid,bigint,text,text,text) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_require(p_action text,p_source uuid,p_workspace bigint,p_resource text,p_operation text,p_purpose text,p_session text)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint;
BEGIN
 a:=builderad_source_actor(p_workspace,p_session,p_action <> 'source.read',p_action <> 'source.read');
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=a AND workspace_id=p_workspace AND scope_kind='source'
  AND source_target_id=p_source AND source_resource=p_resource AND source_operation=p_operation AND source_purpose=p_purpose AND action=p_action FOR SHARE;
 -- All time-dependent predicates follow the last possible wait.
 a:=builderad_source_actor(p_workspace,p_session,p_action <> 'source.read',p_action <> 'source.read');
 IF NOT builderad_source_has(p_action,p_source,p_workspace,p_resource,p_operation,p_purpose) THEN
  RAISE EXCEPTION 'Exact source grant required' USING ERRCODE='42501';
 END IF;
 RETURN a;
END $$;
REVOKE ALL ON FUNCTION builderad_source_require(text,uuid,bigint,text,text,text,text) FROM PUBLIC,builderad_app,builderad_authority;

-- Evidence only: A-003 remains the sole grant writer on the authority connection.
CREATE OR REPLACE FUNCTION builderad_source_owner_evidence(p_workspace bigint,p_session text)
RETURNS TABLE(actor_id bigint,generation bigint) LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint;
BEGIN
 a:=builderad_source_actor(p_workspace,p_session,true,true);
 RETURN QUERY SELECT a,l.generation FROM principal_lifecycles l WHERE l.user_id=a;
END $$;
REVOKE ALL ON FUNCTION builderad_source_owner_evidence(bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_owner_evidence(bigint,text) TO builderad_app;

DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['source_registry','source_capabilities','source_command_receipts','source_versions','source_audit_obligations','source_audit_delivery','security_source_audit_custody'] LOOP
  EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('REVOKE ALL ON %I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('CREATE POLICY source_owner ON %I TO builderad_owner USING (true) WITH CHECK (true)',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['source_command_receipts','source_versions','source_audit_obligations','security_source_audit_custody'] LOOP
  EXECUTE format('CREATE TRIGGER source_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable()',t);
 END LOOP;
END $$;
GRANT SELECT ON source_registry,source_capabilities,source_command_receipts,source_versions,source_audit_obligations,source_audit_delivery TO builderad_app;
GRANT SELECT,INSERT ON security_source_audit_custody TO builderad_authority;
CREATE POLICY source_custody_authority ON security_source_audit_custody TO builderad_authority USING (true) WITH CHECK (true);
CREATE POLICY source_capability_read ON source_capabilities FOR SELECT TO builderad_app USING (
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE POLICY source_identity_read ON source_registry FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM source_capabilities c WHERE c.workspace_id=source_registry.workspace_id AND c.source_id=source_registry.id));
CREATE POLICY source_receipt_read ON source_command_receipts FOR SELECT TO builderad_app USING (
 builderad_source_has('source.read',source_id,workspace_id,resource,operation,purpose));
CREATE POLICY source_version_read ON source_versions FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM source_command_receipts r WHERE r.workspace_id=source_versions.workspace_id AND r.command_id=source_versions.command_id));
CREATE POLICY source_obligation_read ON source_audit_obligations FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM source_command_receipts r WHERE r.workspace_id=source_audit_obligations.workspace_id AND r.command_id=source_audit_obligations.command_id));
CREATE POLICY source_delivery_read ON source_audit_delivery FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM source_audit_obligations o WHERE o.workspace_id=source_audit_delivery.workspace_id AND o.id=source_audit_delivery.obligation_id));

-- Sole fact writer. Canonical jsonb payload determines fingerprints, not caller hashes.
CREATE OR REPLACE FUNCTION builderad_source_command(p_workspace bigint,p_session text,p_command uuid,p_source uuid,p_effect text,p_payload jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE a bigint; g bigint; act text; fp text; prior source_command_receipts; s source_registry;
 r text:=p_payload->>'resource'; op text:=p_payload->>'operation'; v_purpose text:=p_payload->>'purpose';
 result jsonb; env jsonb; obligation uuid; decision timestamptz;
BEGIN
 IF p_effect NOT IN ('register','rename','declare_scope','revoke') OR p_command IS NULL OR p_source IS NULL
  OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object'
  OR p_payload - (ARRAY['resource','operation','purpose','expected_version','reason'] ||
   CASE WHEN p_effect='register' THEN ARRAY['family','namespace','external_id','label']
        WHEN p_effect='rename' THEN ARRAY['label'] ELSE ARRAY[]::text[] END) <> '{}'::jsonb
  OR r IS NULL OR length(r) NOT BETWEEN 1 AND 180 OR r<>trim(r)
  OR op IS NULL OR length(op) NOT BETWEEN 1 AND 80 OR op<>trim(op)
  OR v_purpose IS NULL OR length(v_purpose) NOT BETWEEN 1 AND 128 OR v_purpose<>trim(v_purpose)
  OR coalesce(p_payload->>'reason','') !~ '^[a-z][a-z0-9_]{2,79}$'
 THEN RAISE EXCEPTION 'Invalid bounded source command' USING ERRCODE='22023'; END IF;
 p_payload:=jsonb_set(p_payload,'{expected_version}',to_jsonb((p_payload->>'expected_version')::bigint));
 act:=CASE WHEN p_effect='register' THEN 'source.register' ELSE 'source.manage' END;
 -- Lock command before checking/replaying; lock source before mutating/rechecking expiry.
 PERFORM pg_advisory_xact_lock(hashtextextended('bb1.command.'||p_workspace||'.'||p_command,0));
 PERFORM pg_advisory_xact_lock(hashtextextended('bb1.source.'||p_workspace||'.'||p_source,0));
 a:=builderad_source_require(act,p_source,p_workspace,r,op,v_purpose,p_session);
 SELECT generation INTO g FROM principal_lifecycles WHERE user_id=a;
 fp:=encode(sha256(convert_to(jsonb_build_object('effect',p_effect,'source',p_source,'payload',p_payload)::text,'UTF8')),'hex');
 SELECT * INTO prior FROM source_command_receipts WHERE workspace_id=p_workspace AND command_id=p_command;
 IF FOUND THEN
  IF prior.fingerprint<>fp THEN RAISE EXCEPTION 'Source command payload conflict' USING ERRCODE='23505'; END IF;
  RETURN prior.result;
 END IF;
 SELECT * INTO s FROM source_registry WHERE id=p_source AND workspace_id=p_workspace FOR UPDATE;
 IF p_effect='register' THEN
  IF FOUND THEN RAISE EXCEPTION 'Source already registered' USING ERRCODE='23505'; END IF;
  IF (p_payload->>'expected_version')::bigint IS DISTINCT FROM 0 THEN RAISE EXCEPTION 'Initial source version required' USING ERRCODE='22023'; END IF;
  INSERT INTO source_registry(id,workspace_id,family,namespace,declared_external_id,label,version,status)
  VALUES(p_source,p_workspace,p_payload->>'family',p_payload->>'namespace',p_payload->>'external_id',p_payload->>'label',1,'UNVERIFIED') RETURNING * INTO s;
 ELSE
  IF NOT FOUND THEN RAISE EXCEPTION 'Source unavailable' USING ERRCODE='42501'; END IF;
  IF s.version IS DISTINCT FROM (p_payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Source version conflict' USING ERRCODE='23505'; END IF;
  IF s.status='REVOKED' THEN RAISE EXCEPTION 'Source revoked' USING ERRCODE='22023'; END IF;
  IF p_effect<>'declare_scope' AND NOT EXISTS (SELECT 1 FROM source_capabilities WHERE workspace_id=p_workspace AND source_id=p_source AND resource=r AND operation=op AND purpose=v_purpose) THEN
   RAISE EXCEPTION 'Exact declared scope required' USING ERRCODE='42501';
  END IF;
  UPDATE source_registry SET version=version+1,label=CASE WHEN p_effect='rename' THEN p_payload->>'label' ELSE label END,
   status=CASE WHEN p_effect='revoke' THEN 'REVOKED' ELSE status END WHERE id=p_source AND workspace_id=p_workspace RETURNING * INTO s;
 END IF;
 IF p_effect IN ('register','declare_scope') THEN
  INSERT INTO source_capabilities(workspace_id,source_id,resource,operation,purpose) VALUES(p_workspace,p_source,r,op,v_purpose);
 END IF;
 decision:=clock_timestamp(); obligation:=uuidv7();
 result:=jsonb_build_object('command_id',p_command,'source_id',p_source,'version',s.version,'registration_state',s.status,
  'obligation_id',obligation,'local_audit','DURABLE','reason',p_payload->>'reason');
 INSERT INTO source_command_receipts(workspace_id,command_id,source_id,effect,resource,operation,purpose,fingerprint,result,actor_user_id,actor_generation,decided_at)
 VALUES(p_workspace,p_command,p_source,p_effect,r,op,v_purpose,fp,result,a,g,decision);
 INSERT INTO source_versions(workspace_id,source_id,version,prior_version,command_id,snapshot)
 VALUES(p_workspace,p_source,s.version,nullif(s.version-1,0),p_command,jsonb_build_object('label',s.label,'status',s.status,'resource',r,'operation',op,'purpose',v_purpose));
 env:=jsonb_build_object('policy','BB1-V1','workspace_id',p_workspace,'source_id',p_source,'command_id',p_command,'effect',p_effect,
  'resource',r,'operation',op,'purpose',v_purpose,'actor_user_id',a,'actor_generation',g,'fingerprint',fp,
  'prior_version',nullif(s.version-1,0),'result_version',s.version,'reason',p_payload->>'reason','decided_at',decision);
 INSERT INTO source_audit_obligations(id,workspace_id,command_id,source_id,envelope,fingerprint)
 VALUES(obligation,p_workspace,p_command,p_source,env,encode(sha256(convert_to(env::text,'UTF8')),'hex'));
 INSERT INTO source_audit_delivery(obligation_id,workspace_id) VALUES(obligation,p_workspace);
 -- Final fence covers natural-identity/FK/audit waits too. Failure rolls back all facts.
 PERFORM builderad_source_require(act,p_source,p_workspace,r,op,v_purpose,p_session);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_source_command(bigint,text,uuid,uuid,text,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_command(bigint,text,uuid,uuid,text,jsonb) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_record_delivery(p_workspace bigint,p_session text,p_obligation uuid,p_ack uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o source_audit_obligations; r source_command_receipts; v_state text;
BEGIN
 SELECT * INTO o FROM source_audit_obligations WHERE id=p_obligation AND workspace_id=p_workspace;
 IF NOT FOUND THEN RAISE EXCEPTION 'Obligation unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM source_command_receipts WHERE workspace_id=p_workspace AND command_id=o.command_id;
 PERFORM builderad_source_require('source.audit.recover',o.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
 PERFORM 1 FROM source_audit_delivery WHERE obligation_id=o.id FOR UPDATE;
 IF p_ack IS NOT NULL AND NOT EXISTS (SELECT 1 FROM security_source_audit_custody c WHERE c.obligation_id=o.id AND c.acknowledgment_id=p_ack AND c.fingerprint=o.fingerprint AND c.envelope=o.envelope) THEN
  RAISE EXCEPTION 'Independent matching custody required' USING ERRCODE='42501';
 END IF;
 UPDATE source_audit_delivery SET state=CASE WHEN p_ack IS NULL THEN 'FAILED' ELSE 'ACKNOWLEDGED' END,
  acknowledgment_id=p_ack,attempts=attempts+1,updated_at=clock_timestamp()
 WHERE obligation_id=o.id AND state<>'ACKNOWLEDGED';
 PERFORM builderad_source_require('source.audit.recover',o.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
 SELECT d.state INTO v_state FROM source_audit_delivery d WHERE d.obligation_id=o.id;
 RETURN v_state;
END $$;
REVOKE ALL ON FUNCTION builderad_source_record_delivery(bigint,text,uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_record_delivery(bigint,text,uuid,uuid) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_read_scope(p_workspace bigint,p_session text,p_source uuid,p_resource text,p_operation text,p_purpose text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
 PERFORM builderad_source_require('source.read',p_source,p_workspace,p_resource,p_operation,p_purpose,p_session);
END $$;
REVOKE ALL ON FUNCTION builderad_source_read_scope(bigint,text,uuid,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_read_scope(bigint,text,uuid,text,text,text) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_begin_read(p_workspace bigint,p_session text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
 PERFORM builderad_source_actor(p_workspace,p_session,false,false);
END $$;
REVOKE ALL ON FUNCTION builderad_source_begin_read(bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_begin_read(bigint,text) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_begin_recovery(p_workspace bigint,p_session text,p_obligation uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r source_command_receipts;
BEGIN
 SELECT c.* INTO r FROM source_command_receipts c JOIN source_audit_obligations o ON o.workspace_id=c.workspace_id AND o.command_id=c.command_id
  WHERE o.workspace_id=p_workspace AND o.id=p_obligation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Obligation unavailable' USING ERRCODE='42501'; END IF;
 PERFORM builderad_source_require('source.audit.recover',r.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
END $$;
REVOKE ALL ON FUNCTION builderad_source_begin_recovery(bigint,text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_begin_recovery(bigint,text,uuid) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_source_registration_available(p_workspace bigint,p_source uuid,p_resource text,p_operation text,p_purpose text)
RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
 SELECT builderad_source_has('source.register',p_source,p_workspace,p_resource,p_operation,p_purpose)
  AND NOT EXISTS (SELECT 1 FROM source_registry WHERE workspace_id=p_workspace AND id=p_source)
$$;
REVOKE ALL ON FUNCTION builderad_source_registration_available(bigint,uuid,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_source_registration_available(bigint,uuid,text,text,text) TO builderad_app;
