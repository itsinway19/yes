CREATE TABLE market_processing_runs (
 run_id uuid PRIMARY KEY,
 business_id bigint NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
 actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 generation bigint NOT NULL, session_digest bytea NOT NULL CHECK(octet_length(session_digest)=32),
 question text NOT NULL CHECK(question IN('compare_offers','compare_interest','assess_business_feasibility')),
 method_ref text NOT NULL CHECK(method_ref IN('offer_equivalence:1','qualified_interest_change:1','business_feasibility:1')),
 purpose text NOT NULL CHECK(length(purpose) BETWEEN 1 AND 128),
 material_refs jsonb NOT NULL CHECK(jsonb_typeof(material_refs)='array' AND jsonb_array_length(material_refs) BETWEEN 1 AND 2),
 request_digest bytea NOT NULL CHECK(octet_length(request_digest)=32),
 selection_digest bytea NOT NULL CHECK(octet_length(selection_digest)=32),
 input_digest bytea NOT NULL CHECK(octet_length(input_digest)=32),
 dependency_digest bytea NOT NULL CHECK(octet_length(dependency_digest)=32),
 state text NOT NULL CHECK(state IN('prepared','validated','abstained','failed','cancelled','unavailable')),
 finding jsonb,reason_code text,
 elapsed_microseconds bigint NOT NULL CHECK(elapsed_microseconds BETWEEN 0 AND 30000000),
 execution_count smallint NOT NULL CHECK(execution_count IN(0,1)),
 prepared_at timestamptz NOT NULL,completed_at timestamptz,
 CHECK((state='prepared')=(completed_at IS NULL)),
 CHECK((state IN('validated','abstained'))=(finding IS NOT NULL))
);
REVOKE ALL ON market_processing_runs FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE market_processing_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE market_processing_runs FORCE ROW LEVEL SECURITY;
CREATE POLICY processing_owner ON market_processing_runs TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY processing_executor_read ON market_processing_runs FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY processing_executor_insert ON market_processing_runs FOR INSERT TO builderad_scope_executor
 WITH CHECK(builderad_private.effect_allowed('public.market_processing_runs','insert',to_jsonb(market_processing_runs.*)));
CREATE POLICY processing_executor_update ON market_processing_runs FOR UPDATE TO builderad_scope_executor
 USING(true) WITH CHECK(builderad_private.effect_allowed('public.market_processing_runs','update',to_jsonb(market_processing_runs.*)));
GRANT SELECT,INSERT,UPDATE ON market_processing_runs TO builderad_scope_executor;
CREATE TRIGGER zz_processing_effect BEFORE INSERT OR UPDATE OR DELETE ON market_processing_runs FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_processing_truncate BEFORE TRUNCATE ON market_processing_runs FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
ALTER TABLE market_evidence_revisions DROP CONSTRAINT market_evidence_revisions_evidence_type_check;
ALTER TABLE market_evidence_revisions ADD CONSTRAINT market_evidence_revisions_evidence_type_check
 CHECK(evidence_type IN('market.offer:1','market.interest_signal:1','market.offer:2','market.interest_signal:2'));
