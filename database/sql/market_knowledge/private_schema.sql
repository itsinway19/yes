-- A product pointer over the canonical trusted D2 run; no duplicate finding or evidence payload.
CREATE TABLE market_business_finding_captures (
 artifact_id uuid PRIMARY KEY,processing_run_id uuid NOT NULL REFERENCES market_processing_runs(run_id) ON DELETE RESTRICT,
 business_id bigint NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,processor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 output_scope_id uuid NOT NULL REFERENCES data_use_scopes(scope_id) ON DELETE RESTRICT,purpose text NOT NULL CHECK(length(purpose) BETWEEN 1 AND 128),
 material_digest bytea NOT NULL CHECK(octet_length(material_digest)=32),input_reads jsonb NOT NULL,continuation_rights jsonb NOT NULL,
 context_snapshot jsonb,state text NOT NULL CHECK(state IN('prepared','published','retention_removed')),
 publication_manifest_id uuid REFERENCES material_lineage_manifests(manifest_id) ON DELETE RESTRICT,created_at timestamptz NOT NULL,
 CHECK((state IN('published','retention_removed'))=(publication_manifest_id IS NOT NULL))
);
REVOKE ALL ON market_business_finding_captures FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE market_business_finding_captures ENABLE ROW LEVEL SECURITY;
ALTER TABLE market_business_finding_captures FORCE ROW LEVEL SECURITY;
CREATE POLICY business_finding_owner ON market_business_finding_captures TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY business_finding_read ON market_business_finding_captures FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY business_finding_insert ON market_business_finding_captures FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed('public.market_business_finding_captures','insert',to_jsonb(market_business_finding_captures.*)));
CREATE POLICY business_finding_update ON market_business_finding_captures FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.market_business_finding_captures','update',to_jsonb(market_business_finding_captures.*)));
GRANT SELECT,INSERT,UPDATE ON market_business_finding_captures TO builderad_scope_executor;
CREATE TRIGGER zz_business_finding_effect BEFORE INSERT OR UPDATE OR DELETE ON market_business_finding_captures FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_business_finding_truncate BEFORE TRUNCATE ON market_business_finding_captures FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
