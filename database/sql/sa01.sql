-- Forward-only SA-01: registry, immutable Catalog/configuration and isolated Context.
ALTER TABLE security_action_grants ADD COLUMN commercial_target_id uuid;
ALTER TABLE security_action_grants ADD COLUMN commercial_version_id uuid;
ALTER TABLE security_action_grants DROP CONSTRAINT security_action_grants_scope_check;
ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_scope_check CHECK (
    (scope_kind = 'platform' AND workspace_id IS NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL)
 OR (scope_kind = 'workspace' AND workspace_id IS NOT NULL AND commercial_target_id IS NULL AND commercial_version_id IS NULL)
 OR (scope_kind = 'commercial' AND commercial_target_id IS NOT NULL AND source = 'human')
);
DROP INDEX security_action_grants_platform_active_unique;
DROP INDEX security_action_grants_workspace_unique;
CREATE UNIQUE INDEX security_action_grants_platform_active_unique ON security_action_grants
    (subject_user_id, action, subject_generation) WHERE scope_kind = 'platform' AND status = 'active';
CREATE UNIQUE INDEX security_action_grants_workspace_unique ON security_action_grants
    (subject_user_id, action, workspace_id, subject_generation) WHERE scope_kind = 'workspace';
CREATE UNIQUE INDEX security_action_grants_commercial_active_unique ON security_action_grants
    (subject_user_id, action, commercial_target_id, workspace_id, commercial_version_id, subject_generation)
    NULLS NOT DISTINCT WHERE scope_kind = 'commercial' AND status = 'active';
ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_commercial_effect_check CHECK (
 scope_kind <> 'commercial' OR (uuid_extract_version(commercial_target_id) = 7 AND (
  (action IN ('ReadCommercialMarketRegistry','ConfigureCommercialMarket','EnableCommercialMarket','DisableCommercialMarket') AND workspace_id IS NULL AND commercial_version_id IS NULL)
  OR (action = 'RecordOrVerifyApprovedMarketReference' AND workspace_id IS NULL AND commercial_version_id IS NOT NULL)
  OR (action IN ('RegisterCommercialScope','RecordBeneficiaryBinding','ReadCommercialContextAdvertiser','ReadCommercialContextInternal') AND workspace_id IS NOT NULL AND commercial_version_id IS NULL)
  OR (action IN ('ReadCommercialRegistrationReceipt','ReadCommercialAuditDeliveryState','RetryOrRecoverExistingAuditDelivery') AND commercial_version_id IS NULL)
  OR (action = 'AssignCommercialMarket' AND workspace_id IS NOT NULL AND commercial_version_id IS NOT NULL)
  OR action = 'SA01CommercialGrantAdministrator'
 ))
);

CREATE TABLE commercial_markets (
    id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
    label varchar(120) NOT NULL CHECK (length(trim(label)) > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE commercial_catalog_references (
    id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
    market_id uuid NOT NULL REFERENCES commercial_markets(id) ON DELETE RESTRICT,
    catalog_identity uuid NOT NULL,
    catalog_version varchar(80) NOT NULL CHECK (length(trim(catalog_version)) > 0),
    approval_reference varchar(120) NOT NULL CHECK (approval_reference ~ '^[A-Za-z0-9_.:/-]{3,120}$'),
    source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[a-f0-9]{64}$'),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (catalog_identity, catalog_version),
    UNIQUE (id, market_id)
);
CREATE TABLE commercial_market_configuration_versions (
    id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
    market_id uuid NOT NULL REFERENCES commercial_markets(id) ON DELETE RESTRICT,
    version integer NOT NULL CHECK (version > 0),
    catalog_reference_id uuid,
    denomination_code char(3) CHECK (denomination_code ~ '^[A-Z]{3}$'),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (catalog_reference_id, market_id) REFERENCES commercial_catalog_references(id, market_id) ON DELETE RESTRICT,
    UNIQUE (market_id, version),
    UNIQUE (id, market_id),
    UNIQUE (id, market_id, catalog_reference_id, denomination_code)
);
CREATE TABLE commercial_market_assignability (
    market_id uuid PRIMARY KEY REFERENCES commercial_markets(id) ON DELETE RESTRICT,
    configuration_id uuid,
    revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
    changed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (configuration_id, market_id) REFERENCES commercial_market_configuration_versions(id, market_id) ON DELETE RESTRICT
);
CREATE TABLE commercial_scopes (
    id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE commercial_beneficiary_bindings (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    scope_id uuid NOT NULL UNIQUE REFERENCES commercial_scopes(id) ON DELETE RESTRICT,
    workspace_id bigint NOT NULL UNIQUE REFERENCES workspaces(id) ON DELETE RESTRICT,
    decided_at timestamptz NOT NULL,
    effective_at timestamptz NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (scope_id, workspace_id)
);
COMMENT ON CONSTRAINT commercial_beneficiary_bindings_workspace_id_key ON commercial_beneficiary_bindings
 IS 'SA-01 initial registration only: reject competing initial registrations; no universal future binding-cardinality policy';
CREATE TABLE commercial_market_assignments (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    scope_id uuid NOT NULL UNIQUE,
    workspace_id bigint NOT NULL,
    market_id uuid NOT NULL,
    configuration_id uuid NOT NULL,
    catalog_reference_id uuid NOT NULL,
    denomination_code char(3) NOT NULL,
    decided_at timestamptz NOT NULL,
    effective_at timestamptz NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (scope_id, workspace_id) REFERENCES commercial_beneficiary_bindings(scope_id, workspace_id) ON DELETE RESTRICT,
    FOREIGN KEY (configuration_id, market_id, catalog_reference_id, denomination_code)
      REFERENCES commercial_market_configuration_versions(id, market_id, catalog_reference_id, denomination_code) ON DELETE RESTRICT
);
CREATE TABLE commercial_command_results (
    command_id uuid PRIMARY KEY,
    effect varchar(64) NOT NULL,
    target_id uuid NOT NULL,
    workspace_id bigint REFERENCES workspaces(id) ON DELETE RESTRICT,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    result jsonb NOT NULL CHECK (jsonb_typeof(result) = 'object'),
    actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    actor_generation bigint NOT NULL,
    reason_code varchar(64) NOT NULL CHECK (reason_code ~ '^[a-z][a-z0-9_.-]{2,63}$'),
    policy_version varchar(32) NOT NULL DEFAULT 'SA01-V3',
    decided_at timestamptz NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE commercial_audit_obligations (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    command_id uuid NOT NULL UNIQUE REFERENCES commercial_command_results(command_id) ON DELETE RESTRICT,
    target_id uuid NOT NULL,
    workspace_id bigint REFERENCES workspaces(id) ON DELETE RESTRICT,
    envelope jsonb NOT NULL CHECK (jsonb_typeof(envelope) = 'object'),
    fingerprint char(64) NOT NULL CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE commercial_audit_delivery (
    obligation_id uuid PRIMARY KEY REFERENCES commercial_audit_obligations(id) ON DELETE RESTRICT,
    state varchar(16) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'failed', 'unknown', 'acknowledged')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    acknowledgment_id uuid,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK ((state = 'acknowledged') = (acknowledgment_id IS NOT NULL))
);
-- P4-A custody owns acceptance/acknowledgment; origin commercial owners cannot write it.
CREATE TABLE security_commercial_audit_custody (
    obligation_id uuid PRIMARY KEY,
    fingerprint char(64) NOT NULL CHECK (fingerprint ~ '^[a-f0-9]{64}$'),
    envelope jsonb NOT NULL,
    acknowledgment_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
    accepted_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE security_commercial_mandate_executions (
    mandate_id uuid PRIMARY KEY,
    fingerprint char(64) NOT NULL,
    grant_id bigint NOT NULL REFERENCES security_action_grants(id) ON DELETE RESTRICT,
    executed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE OR REPLACE FUNCTION builderad_sa01_immutable() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_temp AS $$
BEGIN RAISE EXCEPTION 'SA-01 immutable historical fact' USING ERRCODE = '23514'; END $$;

CREATE OR REPLACE FUNCTION builderad_sa01_assignability_complete() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
 IF NEW.configuration_id IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM commercial_market_configuration_versions c
   JOIN commercial_catalog_references r ON r.id = c.catalog_reference_id AND r.market_id = c.market_id
   WHERE c.id = NEW.configuration_id AND c.market_id = NEW.market_id AND c.denomination_code IS NOT NULL
 ) THEN RAISE EXCEPTION 'Incomplete configuration cannot be assignable' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION builderad_sa01_assignability_complete() FROM PUBLIC, builderad_app, builderad_authority;
CREATE TRIGGER sa01_complete_assignment BEFORE INSERT OR UPDATE ON commercial_market_assignability
 FOR EACH ROW EXECUTE FUNCTION builderad_sa01_assignability_complete();

CREATE OR REPLACE FUNCTION builderad_sa01_has(p_action text, p_target uuid, p_workspace bigint DEFAULT NULL, p_version uuid DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
    SELECT EXISTS (
      SELECT 1 FROM builderad_signed_context() c
      JOIN principal_lifecycles l ON l.user_id = c.actor_user_id AND l.status = 'current'
      JOIN users u ON u.id = l.user_id AND u.email_verified_at IS NOT NULL
      JOIN security_action_grants g ON g.subject_user_id = l.user_id AND g.subject_generation = l.generation
      WHERE c.phase IN ('principal', 'workspace')
        AND (c.workspace_id IS NULL OR c.workspace_id = p_workspace)
        AND g.action = p_action AND g.scope_kind = 'commercial' AND g.commercial_target_id = p_target
        AND g.workspace_id IS NOT DISTINCT FROM p_workspace
        AND g.commercial_version_id IS NOT DISTINCT FROM p_version
        AND g.status = 'active' AND g.revoked_at IS NULL
        AND (g.expires_at IS NULL OR g.expires_at > clock_timestamp())
        AND ((l.principal_class = 'staff' AND p_action <> 'ReadCommercialContextAdvertiser'
          AND EXISTS (SELECT 1 FROM internal_personnel p WHERE p.user_id = l.user_id AND p.status = 'active'))
          OR (l.principal_class = 'advertiser' AND p_action = 'ReadCommercialContextAdvertiser'
          AND EXISTS (SELECT 1 FROM workspace_memberships m WHERE m.user_id = l.user_id AND m.workspace_id = p_workspace AND m.status = 'active')))
    )
$$;
REVOKE ALL ON FUNCTION builderad_sa01_has(text, uuid, bigint, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_sa01_has(text, uuid, bigint, uuid) TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_sa01_require(p_action text, p_target uuid, p_workspace bigint DEFAULT NULL, p_version uuid DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_actor bigint;
BEGIN
    IF session_user <> 'builderad_app' OR NOT builderad_sa01_has(p_action, p_target, p_workspace, p_version) THEN
      RAISE EXCEPTION 'Exact current commercial effect required' USING ERRCODE = '42501';
    END IF;
    SELECT actor_user_id INTO v_actor FROM builderad_signed_context();
    -- Locks retained to caller commit: invalidation-first denies; commit-first preserves historical fact.
    PERFORM 1 FROM principal_lifecycles WHERE user_id = v_actor FOR SHARE;
    PERFORM 1 FROM internal_personnel WHERE user_id = v_actor FOR SHARE;
    PERFORM 1 FROM security_action_grants
      WHERE subject_user_id = v_actor AND action = p_action AND scope_kind = 'commercial'
        AND commercial_target_id = p_target AND workspace_id IS NOT DISTINCT FROM p_workspace
        AND commercial_version_id IS NOT DISTINCT FROM p_version FOR SHARE;
    IF NOT builderad_sa01_has(p_action, p_target, p_workspace, p_version) THEN
      RAISE EXCEPTION 'Invalidated commercial authority' USING ERRCODE = '42501';
    END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_sa01_require(text, uuid, bigint, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_sa01_require(text, uuid, bigint, uuid) TO builderad_app;

-- Transient producer fence only: the writer holds a transaction advisory lock.
-- No transaction identity is persisted as commercial eligibility. Other producers
-- are excluded by MVCC; this backend's marker cannot be explicitly unlocked and
-- survives savepoint release until top-level completion (rollback also removes its row).
CREATE OR REPLACE FUNCTION builderad_sa01_require_committed_catalog(p_reference uuid,p_market uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_key bigint := hashtextextended('sa01.catalog.producer.'||p_reference::text,0);
BEGIN
 IF NOT EXISTS (SELECT 1 FROM commercial_catalog_references r WHERE r.id = p_reference AND r.market_id = p_market)
 OR EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted
   AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
   AND classid::bigint = ((v_key >> 32) & 4294967295::bigint)
   AND objid::bigint = (v_key & 4294967295::bigint) AND objsubid = 1) THEN
   RAISE EXCEPTION 'Separately committed exact Catalog required' USING ERRCODE = '23514';
 END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_sa01_require_committed_catalog(uuid,uuid) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_sa01_context_visible(p_scope uuid, p_workspace bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
 SELECT builderad_sa01_has('ReadCommercialContextAdvertiser', p_scope, p_workspace)
     OR builderad_sa01_has('ReadCommercialContextInternal', p_scope, p_workspace)
$$;
REVOKE ALL ON FUNCTION builderad_sa01_context_visible(uuid, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_sa01_context_visible(uuid, bigint) TO builderad_app;

DO $$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['commercial_markets','commercial_catalog_references','commercial_market_configuration_versions',
 'commercial_market_assignability','commercial_scopes','commercial_beneficiary_bindings','commercial_market_assignments',
 'commercial_command_results','commercial_audit_obligations','commercial_audit_delivery',
 'security_commercial_audit_custody','security_commercial_mandate_executions'] LOOP
   EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
   EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
   EXECUTE format('CREATE POLICY sa01_owner ON %I FOR ALL TO builderad_owner USING (true) WITH CHECK (true)', t);
   EXECUTE format('REVOKE ALL ON %I FROM PUBLIC, builderad_app, builderad_authority', t);
   IF t NOT IN ('commercial_market_assignability','commercial_audit_delivery') THEN
     EXECUTE format('CREATE TRIGGER sa01_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable()', t);
   END IF;
 END LOOP;
END $$;
GRANT SELECT ON commercial_markets, commercial_catalog_references, commercial_market_configuration_versions,
 commercial_market_assignability, commercial_scopes, commercial_beneficiary_bindings, commercial_market_assignments,
 commercial_command_results, commercial_audit_obligations, commercial_audit_delivery TO builderad_app;
GRANT SELECT, INSERT ON security_commercial_audit_custody, security_commercial_mandate_executions TO builderad_authority;
CREATE POLICY custody_authority ON security_commercial_audit_custody TO builderad_authority USING (true) WITH CHECK (true);
CREATE POLICY mandate_authority ON security_commercial_mandate_executions TO builderad_authority USING (true) WITH CHECK (true);

CREATE POLICY market_read ON commercial_markets FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialMarketRegistry',id)
 OR EXISTS (SELECT 1 FROM commercial_market_assignments a WHERE a.market_id = commercial_markets.id)
);
CREATE POLICY catalog_read ON commercial_catalog_references FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialMarketRegistry',market_id)
 OR EXISTS (SELECT 1 FROM commercial_market_assignments a WHERE a.catalog_reference_id = commercial_catalog_references.id)
);
CREATE POLICY config_read ON commercial_market_configuration_versions FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialMarketRegistry',market_id)
 OR EXISTS (SELECT 1 FROM commercial_market_assignments a WHERE a.configuration_id = commercial_market_configuration_versions.id)
);
CREATE POLICY assignability_read ON commercial_market_assignability FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialMarketRegistry',market_id)
);
CREATE POLICY scope_read ON commercial_scopes FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM commercial_beneficiary_bindings b WHERE b.scope_id = commercial_scopes.id)
);
CREATE POLICY binding_read ON commercial_beneficiary_bindings FOR SELECT TO builderad_app USING (builderad_sa01_context_visible(scope_id,workspace_id));
CREATE POLICY assignment_read ON commercial_market_assignments FOR SELECT TO builderad_app USING (builderad_sa01_context_visible(scope_id,workspace_id));
CREATE POLICY receipt_read ON commercial_command_results FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialRegistrationReceipt',target_id,workspace_id)
);
CREATE POLICY obligation_read ON commercial_audit_obligations FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialAuditDeliveryState',target_id,workspace_id)
 OR builderad_sa01_has('RetryOrRecoverExistingAuditDelivery',target_id,workspace_id)
);
CREATE POLICY delivery_read ON commercial_audit_delivery FOR SELECT TO builderad_app USING (
 EXISTS (SELECT 1 FROM commercial_audit_obligations o WHERE o.id = commercial_audit_delivery.obligation_id)
);

-- Commands are the only runtime material writers; private finish owns local atomic obligation.
CREATE OR REPLACE FUNCTION builderad_sa01_finish(p_command uuid, p_effect text, p_target uuid, p_workspace bigint,
 p_fingerprint text, p_reason text, p_result jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_actor bigint; v_generation bigint; v_envelope jsonb; v_obligation uuid; v_now timestamptz := clock_timestamp();
BEGIN
 SELECT c.actor_user_id,l.generation INTO v_actor,v_generation FROM builderad_signed_context() c JOIN principal_lifecycles l ON l.user_id = c.actor_user_id;
 INSERT INTO commercial_command_results(command_id,effect,target_id,workspace_id,request_fingerprint,result,actor_user_id,actor_generation,reason_code,decided_at)
 VALUES(p_command,p_effect,p_target,p_workspace,p_fingerprint,p_result,v_actor,v_generation,p_reason,v_now);
 v_envelope := jsonb_build_object('command_id',p_command,'effect',p_effect,'target_id',p_target,'workspace_id',p_workspace,
 'request_fingerprint',p_fingerprint,'result',p_result,'actor_user_id',v_actor,'actor_generation',v_generation,
 'reason_code',p_reason,'policy_version','SA01-V3','decided_at',v_now);
 INSERT INTO commercial_audit_obligations(command_id,target_id,workspace_id,envelope,fingerprint)
 VALUES(p_command,p_target,p_workspace,v_envelope,encode(sha256(convert_to(v_envelope::text,'UTF8')),'hex')) RETURNING id INTO v_obligation;
 INSERT INTO commercial_audit_delivery(obligation_id) VALUES(v_obligation);
 -- Effect-time fence AFTER all material/unique/FK/obligation waits. A failed fence
 -- rolls back the entire local command; no accepted fact survives expired authority.
 IF p_effect = 'RegisterCommercialScope' THEN
   PERFORM builderad_sa01_require('RegisterCommercialScope',p_target,p_workspace);
   PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_target,p_workspace);
   PERFORM builderad_sa01_require('AssignCommercialMarket',p_target,p_workspace,(p_result->>'configuration_id')::uuid);
 ELSE
   PERFORM builderad_sa01_require(CASE WHEN p_effect = 'ConfigureCommercialMarketVersion' THEN 'ConfigureCommercialMarket' ELSE p_effect END,
     p_target,p_workspace,CASE WHEN p_effect = 'RecordOrVerifyApprovedMarketReference' THEN (p_result->>'catalog_reference_id')::uuid ELSE NULL END);
 END IF;
 RETURN p_result;
END $$;
REVOKE ALL ON FUNCTION builderad_sa01_finish(uuid,text,uuid,bigint,text,text,jsonb) FROM PUBLIC, builderad_app, builderad_authority;

CREATE OR REPLACE FUNCTION builderad_sa01_prior(p_command uuid, p_effect text, p_target uuid, p_workspace bigint, p_fingerprint text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r commercial_command_results;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('sa01.command.'||p_command::text,0));
 SELECT * INTO r FROM commercial_command_results WHERE command_id = p_command;
 IF FOUND THEN
   IF r.effect <> p_effect OR r.target_id <> p_target OR r.workspace_id IS DISTINCT FROM p_workspace OR r.request_fingerprint <> p_fingerprint THEN
     RAISE EXCEPTION 'Command payload conflict' USING ERRCODE = '23505';
   END IF;
   RETURN r.result;
 END IF;
 RETURN NULL;
END $$;
REVOKE ALL ON FUNCTION builderad_sa01_prior(uuid,text,uuid,bigint,text) FROM PUBLIC, builderad_app, builderad_authority;

CREATE OR REPLACE FUNCTION builderad_configure_commercial_market(p_command uuid,p_market uuid,p_label text,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r jsonb; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('ConfigureCommercialMarket',p_market,p_label,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 r := builderad_sa01_prior(p_command,'ConfigureCommercialMarket',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 INSERT INTO commercial_markets(id,label) VALUES(p_market,p_label);
 INSERT INTO commercial_market_assignability(market_id) VALUES(p_market);
 RETURN builderad_sa01_finish(p_command,'ConfigureCommercialMarket',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'label',p_label));
END $$;

CREATE OR REPLACE FUNCTION builderad_record_commercial_catalog(p_command uuid,p_market uuid,p_reference uuid,p_catalog uuid,p_version text,p_approval text,p_source text,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r jsonb; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('RecordOrVerifyApprovedMarketReference',p_market,p_reference,p_catalog,p_version,p_approval,p_source,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('RecordOrVerifyApprovedMarketReference',p_market,NULL,p_reference);
 r := builderad_sa01_prior(p_command,'RecordOrVerifyApprovedMarketReference',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 -- Shared marker does not serialize independent producers. Hash collision can
 -- only cause a conservative denial in this open transaction, never eligibility.
 PERFORM pg_advisory_xact_lock_shared(hashtextextended('sa01.catalog.producer.'||p_reference::text,0));
 PERFORM builderad_sa01_require('RecordOrVerifyApprovedMarketReference',p_market,NULL,p_reference);
 INSERT INTO commercial_catalog_references(id,market_id,catalog_identity,catalog_version,approval_reference,source_sha256)
 VALUES(p_reference,p_market,p_catalog,p_version,p_approval,p_source);
 RETURN builderad_sa01_finish(p_command,'RecordOrVerifyApprovedMarketReference',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('catalog_reference_id',p_reference,'market_id',p_market,'catalog_identity',p_catalog,'catalog_version',p_version));
END $$;

CREATE OR REPLACE FUNCTION builderad_create_market_configuration(p_command uuid,p_market uuid,p_configuration uuid,p_catalog_reference uuid,p_denomination text,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r jsonb; v_version integer; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('ConfigureCommercialMarketVersion',p_market,p_configuration,p_catalog_reference,p_denomination,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 r := builderad_sa01_prior(p_command,'ConfigureCommercialMarketVersion',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM 1 FROM commercial_market_assignability WHERE market_id = p_market FOR UPDATE;
 IF p_catalog_reference IS NOT NULL THEN PERFORM builderad_sa01_require_committed_catalog(p_catalog_reference,p_market); END IF;
 SELECT coalesce(max(version),0)+1 INTO v_version FROM commercial_market_configuration_versions WHERE market_id = p_market;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 INSERT INTO commercial_market_configuration_versions(id,market_id,version,catalog_reference_id,denomination_code)
 VALUES(p_configuration,p_market,v_version,p_catalog_reference,p_denomination);
 RETURN builderad_sa01_finish(p_command,'ConfigureCommercialMarketVersion',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'configuration_id',p_configuration,'version',v_version,'catalog_reference_id',p_catalog_reference,'denomination_code',p_denomination));
END $$;

CREATE OR REPLACE FUNCTION builderad_set_market_assignability(p_command uuid,p_market uuid,p_configuration uuid,p_expected_revision bigint,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r jsonb; v_effect text; v_revision bigint; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('MarketAssignability',p_market,p_configuration,p_expected_revision,p_reason)::text,'UTF8')),'hex');
 v_effect := CASE WHEN p_configuration IS NULL THEN 'DisableCommercialMarket' ELSE 'EnableCommercialMarket' END;
 PERFORM builderad_sa01_require(v_effect,p_market);
 r := builderad_sa01_prior(p_command,v_effect,p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 SELECT revision INTO v_revision FROM commercial_market_assignability WHERE market_id = p_market FOR UPDATE;
 IF v_revision IS NULL OR v_revision <> p_expected_revision THEN RAISE EXCEPTION 'Assignability revision conflict' USING ERRCODE = '23505'; END IF;
 IF p_configuration IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM commercial_market_configuration_versions c JOIN commercial_catalog_references r ON r.id = c.catalog_reference_id AND r.market_id = c.market_id
   WHERE c.id = p_configuration AND c.market_id = p_market AND c.denomination_code IS NOT NULL
 ) THEN RAISE EXCEPTION 'Complete exact approved configuration required' USING ERRCODE = '23514'; END IF;
 IF p_configuration IS NOT NULL THEN
   PERFORM builderad_sa01_require_committed_catalog((SELECT catalog_reference_id FROM commercial_market_configuration_versions WHERE id = p_configuration),p_market);
 END IF;
 PERFORM builderad_sa01_require(v_effect,p_market);
 UPDATE commercial_market_assignability SET configuration_id = p_configuration,revision = revision+1,changed_at = clock_timestamp() WHERE market_id = p_market;
 RETURN builderad_sa01_finish(p_command,v_effect,p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'configuration_id',p_configuration,'revision',v_revision+1));
END $$;

CREATE OR REPLACE FUNCTION builderad_commercial_workspace_reference(p_scope uuid,p_workspace uuid) RETURNS TABLE(public_id uuid,name varchar,slug varchar)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE v_workspace bigint;
BEGIN
 SELECT id INTO v_workspace FROM workspaces WHERE workspaces.public_id = p_workspace AND status = 'active' FOR SHARE;
 IF v_workspace IS NULL THEN RAISE EXCEPTION 'Unavailable commercial target' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_workspace);
 RETURN QUERY SELECT w.public_id,w.name,w.slug FROM workspaces w WHERE w.id = v_workspace;
END $$;

CREATE OR REPLACE FUNCTION builderad_register_commercial_scope(p_command uuid,p_scope uuid,p_workspace uuid,p_market uuid,p_configuration uuid,p_catalog_reference uuid,p_denomination text,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r jsonb; v_workspace bigint; v_owner bigint; c commercial_market_configuration_versions; v_now timestamptz; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('RegisterCommercialScope',p_scope,p_workspace,p_market,p_configuration,p_catalog_reference,p_denomination,p_reason)::text,'UTF8')),'hex');
 SELECT id INTO v_workspace FROM workspaces WHERE public_id = p_workspace AND status = 'active' FOR SHARE;
 IF v_workspace IS NULL THEN RAISE EXCEPTION 'Active valid beneficiary required' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_workspace);
 PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_scope,v_workspace);
 PERFORM builderad_sa01_require('AssignCommercialMarket',p_scope,v_workspace,p_configuration);
 -- Deterministic consumed-tuple order: Workspace, operator/effects, ownership,
 -- beneficiary lifecycle, beneficiary identity, exact owner membership.
 SELECT responsible_owner_user_id INTO v_owner FROM workspace_ownerships WHERE workspace_id = v_workspace FOR SHARE;
 PERFORM 1 FROM principal_lifecycles WHERE user_id = v_owner FOR SHARE;
 PERFORM 1 FROM users WHERE id = v_owner FOR SHARE;
 PERFORM 1 FROM workspace_memberships WHERE workspace_id = v_workspace AND user_id = v_owner FOR SHARE;
 IF v_owner IS NULL OR NOT builderad_has_valid_workspace_owner(v_workspace) THEN
   RAISE EXCEPTION 'Current locked beneficiary qualification required' USING ERRCODE = '42501';
 END IF;
 r := builderad_sa01_prior(p_command,'RegisterCommercialScope',p_scope,v_workspace,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM 1 FROM commercial_market_assignability WHERE market_id = p_market AND configuration_id = p_configuration FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Exact market configuration is not assignable' USING ERRCODE = '23514'; END IF;
 SELECT * INTO c FROM commercial_market_configuration_versions WHERE id = p_configuration AND market_id = p_market;
 IF NOT FOUND OR c.catalog_reference_id IS DISTINCT FROM p_catalog_reference OR c.denomination_code IS DISTINCT FROM p_denomination OR c.catalog_reference_id IS NULL OR c.denomination_code IS NULL THEN
   RAISE EXCEPTION 'Exact Catalog and denomination configuration required' USING ERRCODE = '23514';
 END IF;
 PERFORM builderad_sa01_require_committed_catalog(p_catalog_reference,p_market);
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_workspace);
 PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_scope,v_workspace);
 PERFORM builderad_sa01_require('AssignCommercialMarket',p_scope,v_workspace,p_configuration);
 v_now := clock_timestamp();
 INSERT INTO commercial_scopes(id) VALUES(p_scope);
 INSERT INTO commercial_beneficiary_bindings(scope_id,workspace_id,decided_at,effective_at) VALUES(p_scope,v_workspace,v_now,v_now);
 INSERT INTO commercial_market_assignments(scope_id,workspace_id,market_id,configuration_id,catalog_reference_id,denomination_code,decided_at,effective_at)
 VALUES(p_scope,v_workspace,p_market,p_configuration,p_catalog_reference,p_denomination,v_now,v_now);
 RETURN builderad_sa01_finish(p_command,'RegisterCommercialScope',p_scope,v_workspace,p_fingerprint,p_reason,
   jsonb_build_object('scope_id',p_scope,'workspace_public_id',p_workspace,'market_id',p_market,'configuration_id',p_configuration,
   'catalog_reference_id',p_catalog_reference,'catalog_identity',(SELECT catalog_identity FROM commercial_catalog_references WHERE id = p_catalog_reference),
   'catalog_version',(SELECT catalog_version FROM commercial_catalog_references WHERE id = p_catalog_reference),
   'configuration_version',c.version,'denomination_code',p_denomination,'formation_state','CONTEXT_RECORDED','agreement_state','UNKNOWN','amount_state','UNKNOWN'));
END $$;

CREATE OR REPLACE FUNCTION builderad_record_commercial_audit_delivery(p_obligation uuid,p_state text,p_ack uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o commercial_audit_obligations; d commercial_audit_delivery;
BEGIN
 SELECT * INTO o FROM commercial_audit_obligations WHERE id = p_obligation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable obligation' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.workspace_id);
 SELECT * INTO d FROM commercial_audit_delivery WHERE obligation_id = p_obligation FOR UPDATE;
 IF d.state = 'acknowledged' THEN RETURN; END IF;
 IF p_state = 'acknowledged' AND NOT EXISTS (
   SELECT 1 FROM security_commercial_audit_custody c WHERE c.obligation_id = o.id AND c.fingerprint = o.fingerprint AND c.acknowledgment_id = p_ack
 ) THEN RAISE EXCEPTION 'Actual custody acknowledgment required' USING ERRCODE = '23514'; END IF;
 UPDATE commercial_audit_delivery SET state = p_state,acknowledgment_id = p_ack,attempts = attempts+1,updated_at = clock_timestamp() WHERE obligation_id = p_obligation;
END $$;

DO $$
DECLARE f record;
BEGIN
 FOR f IN SELECT p.oid::regprocedure AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
 WHERE n.nspname = 'public' AND p.proname IN ('builderad_configure_commercial_market','builderad_record_commercial_catalog',
 'builderad_create_market_configuration','builderad_set_market_assignability','builderad_commercial_workspace_reference',
 'builderad_register_commercial_scope','builderad_record_commercial_audit_delivery') LOOP
   EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, builderad_authority',f.signature);
   EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO builderad_app',f.signature);
 END LOOP;
END $$;
