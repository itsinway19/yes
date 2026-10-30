-- D1: existing source identity and grants; market custody never implies public access.
ALTER TABLE source_registry ADD COLUMN custody_kind text NOT NULL DEFAULT 'workspace_native'
 CHECK(custody_kind IN('workspace_native','platform_market'));
ALTER TABLE source_registry ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE source_registry ADD CONSTRAINT source_custody_shape CHECK(
 (custody_kind='workspace_native' AND workspace_id IS NOT NULL) OR
 (custody_kind='platform_market' AND workspace_id IS NULL));
CREATE UNIQUE INDEX market_source_identity ON source_registry(family,namespace,declared_external_id) WHERE custody_kind='platform_market';

ALTER TABLE data_use_scopes ADD COLUMN rights_domain text NOT NULL DEFAULT 'client' CHECK(rights_domain IN('client','market'));
ALTER TABLE data_use_scopes ALTER COLUMN boundary_id DROP NOT NULL;
ALTER TABLE data_use_scopes ADD CONSTRAINT data_use_domain_shape CHECK(
 (rights_domain='client' AND boundary_id IS NOT NULL AND operation NOT IN('ingest','correct')) OR
 (rights_domain='market' AND boundary_id IS NULL));
ALTER TABLE data_use_scopes DROP CONSTRAINT data_use_scopes_operation_check;
ALTER TABLE data_use_scopes ADD CONSTRAINT data_use_scopes_operation_check CHECK(
 operation IN('retained_read','process','combine','display','reuse','export','train','retain','delete','forward','ingest','correct'));
ALTER TABLE data_use_scopes DROP CONSTRAINT scope_canonical_identity;

ALTER TABLE resource_right_fences ADD COLUMN data_use_scope_id uuid REFERENCES data_use_scopes(scope_id) ON DELETE RESTRICT;
ALTER TABLE resource_right_fences DROP CONSTRAINT resource_right_fences_kind_check;
ALTER TABLE resource_right_fences DROP CONSTRAINT resource_right_fences_check;
ALTER TABLE resource_right_fences ADD CONSTRAINT resource_right_fences_kind_check CHECK(kind IN('source','client_boundary','data_use'));
ALTER TABLE resource_right_fences ADD CONSTRAINT resource_right_fences_check CHECK(
 (kind='source' AND source_id IS NOT NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL) OR
 (kind='client_boundary' AND source_id IS NULL AND boundary_id IS NOT NULL AND data_use_scope_id IS NULL) OR
 (kind='data_use' AND source_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NOT NULL));
CREATE UNIQUE INDEX market_scope_gate ON resource_right_fences(data_use_scope_id) WHERE kind='data_use';

CREATE TABLE market_source_custody (
 source_id uuid PRIMARY KEY REFERENCES source_registry(id) ON DELETE RESTRICT,
 revision bigint NOT NULL CHECK(revision>0),status text NOT NULL CHECK(status IN('active','restricted','revoked')),
 policy_id text NOT NULL CHECK(length(policy_id) BETWEEN 1 AND 180),policy_version bigint NOT NULL CHECK(policy_version>0),
 evidence_ref text NOT NULL CHECK(length(evidence_ref) BETWEEN 1 AND 4096),evidence_digest bytea NOT NULL CHECK(octet_length(evidence_digest)=32),
 allowed_operations text[] NOT NULL,allowed_purposes text[] NOT NULL,allowed_fields text[] NOT NULL,
 valid_from timestamptz NOT NULL,valid_until timestamptz,
 CHECK(isfinite(valid_from) AND (valid_until IS NULL OR(isfinite(valid_until) AND valid_until>valid_from)))
);
CREATE TABLE market_evidence_heads (
 evidence_id uuid PRIMARY KEY CHECK(uuid_extract_version(evidence_id)=7),
 source_id uuid NOT NULL REFERENCES source_registry(id) ON DELETE RESTRICT,
 record_key text NOT NULL CHECK(length(record_key) BETWEEN 1 AND 180),current_revision bigint NOT NULL CHECK(current_revision>0),
 UNIQUE(source_id,record_key)
);
CREATE TABLE market_evidence_revisions (
 evidence_id uuid NOT NULL REFERENCES market_evidence_heads(evidence_id) ON DELETE RESTRICT,
 revision bigint NOT NULL CHECK(revision>0),prior_revision bigint,content_digest bytea NOT NULL CHECK(octet_length(content_digest)=32),
 evidence_type text NOT NULL CHECK(evidence_type IN('market.offer:1','market.interest_signal:1')),
 fields jsonb NOT NULL CHECK(jsonb_typeof(fields)='object'),
 coverage text NOT NULL CHECK(coverage IN('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')),
 provenance jsonb NOT NULL CHECK(jsonb_typeof(provenance)='object'),
 effective_at timestamptz NOT NULL,observed_at timestamptz NOT NULL,valid_until timestamptz,
 command_id uuid NOT NULL,recorded_at timestamptz NOT NULL,
 PRIMARY KEY(evidence_id,revision),UNIQUE(evidence_id,content_digest),
 CHECK(prior_revision IS NOT DISTINCT FROM nullif(revision-1,0)),
 CHECK(isfinite(effective_at) AND isfinite(observed_at) AND isfinite(recorded_at) AND
 (valid_until IS NULL OR(isfinite(valid_until) AND valid_until>effective_at)))
);
CREATE TABLE market_evidence_transitions (
 command_id uuid PRIMARY KEY,evidence_id uuid NOT NULL REFERENCES market_evidence_heads(evidence_id) ON DELETE RESTRICT,
 from_revision bigint,to_revision bigint NOT NULL,changed_fields text[] NOT NULL,reason text NOT NULL CHECK(length(reason) BETWEEN 1 AND 600),
 recorded_at timestamptz NOT NULL,
 FOREIGN KEY(evidence_id,to_revision) REFERENCES market_evidence_revisions(evidence_id,revision) ON DELETE RESTRICT
);
-- No runtime row access. All effects require the existing exact single-use permit.
DO $permissions$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['market_source_custody','market_evidence_heads','market_evidence_revisions','market_evidence_transitions'] LOOP
  EXECUTE format('REVOKE ALL ON public.%I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY market_owner ON public.%I TO builderad_owner USING(true) WITH CHECK(true)',t);
  EXECUTE format('CREATE POLICY market_executor_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY market_executor_insert ON public.%I FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed(%L::regclass,''insert'',to_jsonb(%I.*)))',t,'public.'||t,t);
  EXECUTE format('GRANT SELECT,INSERT ON public.%I TO builderad_scope_executor',t);
  IF t IN('market_source_custody','market_evidence_heads') THEN
   EXECUTE format('CREATE POLICY market_executor_update ON public.%I FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed(%L::regclass,''update'',to_jsonb(%I.*)))',t,'public.'||t,t);
   EXECUTE format('GRANT UPDATE ON public.%I TO builderad_scope_executor',t);
  END IF;
  EXECUTE format('CREATE TRIGGER zz_market_effect BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect()',t);
  EXECUTE format('CREATE TRIGGER zz_market_truncate BEFORE TRUNCATE ON public.%I FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()',t);
 END LOOP;
END $permissions$;
