-- Preserve pre-hardening history without promoting unattested results to trusted output.
ALTER TABLE market_processing_runs ADD COLUMN completion_attestation_digest bytea
 CHECK(completion_attestation_digest IS NULL OR octet_length(completion_attestation_digest)=32);
-- Narrow execution evidence, issued only across the existing authority connection.
-- The runtime can neither mint nor read these attestations. No user right is issued.
CREATE TABLE market_processing_completion_tickets (
 token_hash text PRIMARY KEY CHECK(token_hash ~ '^[0-9a-f]{64}$'),
 run_id uuid NOT NULL REFERENCES market_processing_runs(run_id) ON DELETE RESTRICT,
 command_id uuid NOT NULL,actor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 generation bigint NOT NULL,business_public_id uuid NOT NULL REFERENCES businesses(public_id) ON DELETE RESTRICT,
 method_ref text NOT NULL,session_digest bytea NOT NULL CHECK(octet_length(session_digest)=32),
 binding_digest bytea NOT NULL CHECK(octet_length(binding_digest)=32),
 backend_pid integer NOT NULL,transaction_id xid8 NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),expires_at timestamptz NOT NULL,consumed_at timestamptz,
 CHECK(isfinite(created_at) AND isfinite(expires_at) AND expires_at>created_at AND expires_at<=created_at+interval '30 seconds'),
 CHECK(consumed_at IS NULL OR(isfinite(consumed_at) AND consumed_at>=created_at))
);
CREATE INDEX processing_completion_ticket_expiry ON market_processing_completion_tickets(expires_at);
REVOKE ALL ON market_processing_completion_tickets FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE market_processing_completion_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE market_processing_completion_tickets FORCE ROW LEVEL SECURITY;
CREATE POLICY completion_ticket_owner ON market_processing_completion_tickets TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY completion_ticket_issuer ON market_processing_completion_tickets FOR INSERT TO builderad_authority WITH CHECK(consumed_at IS NULL);
CREATE POLICY completion_ticket_expiry_read ON market_processing_completion_tickets FOR SELECT TO builderad_authority USING(true);
CREATE POLICY completion_ticket_expiry_cleanup ON market_processing_completion_tickets FOR DELETE TO builderad_authority USING(expires_at<=clock_timestamp());
GRANT INSERT,DELETE ON market_processing_completion_tickets TO builderad_authority;
GRANT SELECT(expires_at) ON market_processing_completion_tickets TO builderad_authority;
CREATE POLICY completion_ticket_executor_read ON market_processing_completion_tickets FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY completion_ticket_executor_update ON market_processing_completion_tickets FOR UPDATE TO builderad_scope_executor USING(true)
 WITH CHECK(builderad_private.effect_allowed('public.market_processing_completion_tickets','update',to_jsonb(market_processing_completion_tickets.*)));
GRANT SELECT,UPDATE ON market_processing_completion_tickets TO builderad_scope_executor;
CREATE TRIGGER zz_completion_ticket_consume BEFORE UPDATE ON market_processing_completion_tickets FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
