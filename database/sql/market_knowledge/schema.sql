-- Canonical shared capture custodian. No Business surrogate and no copied evidence payload.
CREATE TABLE market_knowledge_captures (
 run_id uuid PRIMARY KEY, actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 generation bigint NOT NULL,session_digest bytea NOT NULL CHECK(octet_length(session_digest)=32),
 question text NOT NULL CHECK(question IN('compare_offers','compare_interest','record_price','record_geography')),
 method_ref text NOT NULL CHECK(method_ref IN('offer_equivalence:1','qualified_interest_change:1','qualified_price_observation:1','qualified_geography_observation:1')),
 purpose text NOT NULL CHECK(length(purpose) BETWEEN 1 AND 128),
 material_refs jsonb NOT NULL CHECK(jsonb_typeof(material_refs)='array' AND jsonb_array_length(material_refs) BETWEEN 1 AND 2),
 input_digest bytea NOT NULL CHECK(octet_length(input_digest)=32),input_reads jsonb NOT NULL,
 state text NOT NULL DEFAULT 'prepared' CHECK(state IN('prepared','validated','abstained','failed','published','retention_removed')),
 finding jsonb,continuation_rights jsonb,completion_attestation_digest bytea CHECK(completion_attestation_digest IS NULL OR octet_length(completion_attestation_digest)=32),
 output_scope_id uuid REFERENCES data_use_scopes(scope_id) ON DELETE RESTRICT,
 publication_manifest_id uuid REFERENCES material_lineage_manifests(manifest_id) ON DELETE RESTRICT,
 material_digest bytea CHECK(material_digest IS NULL OR octet_length(material_digest)=32),
 created_at timestamptz NOT NULL,completed_at timestamptz,
 CHECK((state='prepared')=(completed_at IS NULL)),
 CHECK((state IN('published','retention_removed'))=(publication_manifest_id IS NOT NULL)),
 CHECK(state<>'published' OR(finding IS NOT NULL AND completion_attestation_digest IS NOT NULL AND output_scope_id IS NOT NULL AND material_digest IS NOT NULL))
);
REVOKE ALL ON market_knowledge_captures FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE market_knowledge_captures ENABLE ROW LEVEL SECURITY;
ALTER TABLE market_knowledge_captures FORCE ROW LEVEL SECURITY;
CREATE POLICY knowledge_capture_owner ON market_knowledge_captures TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY knowledge_capture_read ON market_knowledge_captures FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY knowledge_capture_insert ON market_knowledge_captures FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed('public.market_knowledge_captures','insert',to_jsonb(market_knowledge_captures.*)));
CREATE POLICY knowledge_capture_update ON market_knowledge_captures FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.market_knowledge_captures','update',to_jsonb(market_knowledge_captures.*)));
GRANT SELECT,INSERT,UPDATE ON market_knowledge_captures TO builderad_scope_executor;
CREATE TRIGGER zz_knowledge_capture_effect BEFORE INSERT OR UPDATE OR DELETE ON market_knowledge_captures FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_knowledge_capture_truncate BEFORE TRUNCATE ON market_knowledge_captures FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
