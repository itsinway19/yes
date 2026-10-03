-- Owner-approved narrow Founder preparation. This is authority evidence, not a Commercial market.
CREATE TABLE security_commercial_market_approvals (
    id uuid PRIMARY KEY CHECK (uuid_extract_version(id) = 7),
    target_id uuid NOT NULL UNIQUE CHECK (uuid_extract_version(target_id) = 7),
    founder_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    founder_generation bigint NOT NULL,
    administrator_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    administrator_generation bigint NOT NULL,
    label varchar(120) NOT NULL,
    reason varchar(120) NOT NULL,
    expires_at timestamptz NOT NULL,
    fingerprint char(64) NOT NULL,
    grant_id bigint NOT NULL REFERENCES security_action_grants(id) ON DELETE RESTRICT,
    approved_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK (founder_id <> administrator_id)
);
ALTER TABLE security_commercial_market_approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE security_commercial_market_approvals FORCE ROW LEVEL SECURITY;
CREATE POLICY market_approval_owner ON security_commercial_market_approvals TO builderad_owner USING (true) WITH CHECK (true);
CREATE POLICY market_approval_authority ON security_commercial_market_approvals TO builderad_authority USING (true) WITH CHECK (true);
GRANT SELECT, INSERT ON security_commercial_market_approvals TO builderad_authority;
CREATE TRIGGER market_approval_immutable BEFORE UPDATE OR DELETE ON security_commercial_market_approvals
FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();

-- Only the authority service may ask whether its already-authorized exact target was consumed.
CREATE FUNCTION builderad_commercial_preparation_consumed(target uuid) RETURNS boolean
LANGUAGE sql SECURITY DEFINER STABLE SET search_path = public, pg_temp AS $$
    SELECT EXISTS (SELECT 1 FROM commercial_markets WHERE id = target)
$$;
REVOKE ALL ON FUNCTION builderad_commercial_preparation_consumed(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_commercial_preparation_consumed(uuid) TO builderad_authority;
