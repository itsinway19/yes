-- Owner-approved direct Founder authority; employees retain exact-grant requirements.
-- Replace in place to preserve existing RLS/function dependencies and effect-time fences.
CREATE OR REPLACE FUNCTION builderad_sa01_exact_grant(p_action text, p_target uuid, p_workspace bigint DEFAULT NULL, p_version uuid DEFAULT NULL)
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
REVOKE ALL ON FUNCTION builderad_sa01_exact_grant(text,uuid,bigint,uuid) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_sa01_founder() RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
 SELECT EXISTS (
   SELECT 1 FROM builderad_signed_context() c
   JOIN principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='staff'
   JOIN users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
   JOIN internal_personnel p ON p.user_id=l.user_id AND p.kind='founder' AND p.status='active'
   WHERE c.phase IN ('principal','workspace')
 )
$$;
REVOKE ALL ON FUNCTION builderad_sa01_founder() FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_sa01_founder() TO builderad_app;

CREATE OR REPLACE FUNCTION builderad_sa01_has(p_action text,p_target uuid,p_workspace bigint DEFAULT NULL,p_version uuid DEFAULT NULL)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=public,pg_temp AS $$
 SELECT builderad_sa01_exact_grant(p_action,p_target,p_workspace,p_version) OR (
   builderad_sa01_founder() AND p_target IS NOT NULL
   AND EXISTS (SELECT 1 FROM builderad_signed_context() c WHERE c.workspace_id IS NULL OR c.workspace_id=p_workspace)
   AND (
     (p_action IN ('ReadCommercialMarketRegistry','ConfigureCommercialMarket','EnableCommercialMarket','DisableCommercialMarket') AND p_workspace IS NULL AND p_version IS NULL)
     OR (p_action='RecordOrVerifyApprovedMarketReference' AND p_workspace IS NULL AND p_version IS NOT NULL)
     OR (p_action IN ('RegisterCommercialScope','RecordBeneficiaryBinding','ReadCommercialContextInternal') AND p_workspace IS NOT NULL AND p_version IS NULL)
     OR (p_action='AssignCommercialMarket' AND p_workspace IS NOT NULL AND p_version IS NOT NULL)
     OR (p_action IN ('ReadCommercialRegistrationReceipt','ReadCommercialAuditDeliveryState','RetryOrRecoverExistingAuditDelivery') AND p_version IS NULL)
   )
 )
$$;

REVOKE ALL ON FUNCTION builderad_sa01_has(text,uuid,bigint,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_sa01_has(text,uuid,bigint,uuid) TO builderad_app;

CREATE FUNCTION builderad_sa01_founder_workspaces() RETURNS TABLE(workspace_public_id uuid,scope_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=public,pg_temp AS $$
 SELECT w.public_id,b.scope_id FROM workspaces w
 LEFT JOIN commercial_beneficiary_bindings b ON b.workspace_id=w.id
 WHERE builderad_sa01_founder() AND w.status='active'
$$;
REVOKE ALL ON FUNCTION builderad_sa01_founder_workspaces() FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_sa01_founder_workspaces() TO builderad_app;

-- Human approval reason is immutable evidence, committed with the existing market
-- command/receipt/obligation rather than stored only as a hash or in browser state.
CREATE TABLE commercial_founder_market_notes (
 command_id uuid PRIMARY KEY REFERENCES commercial_command_results(command_id) ON DELETE RESTRICT,
 reason varchar(120) NOT NULL CHECK(length(trim(reason))>0)
);
ALTER TABLE commercial_founder_market_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_founder_market_notes FORCE ROW LEVEL SECURITY;
CREATE POLICY founder_notes_owner ON commercial_founder_market_notes TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY founder_notes_read ON commercial_founder_market_notes FOR SELECT TO builderad_app USING(builderad_sa01_founder());
GRANT SELECT ON commercial_founder_market_notes TO builderad_app;
CREATE TRIGGER founder_notes_immutable BEFORE UPDATE OR DELETE ON commercial_founder_market_notes
FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();

CREATE FUNCTION builderad_founder_configure_market(p_command uuid,p_market uuid,p_label text,p_reason text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE result jsonb;
BEGIN
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 result:=builderad_configure_commercial_market(p_command,p_market,p_label,
   'founder_market_'||substr(encode(sha256(convert_to(p_reason,'UTF8')),'hex'),1,32));
 INSERT INTO commercial_founder_market_notes(command_id,reason) VALUES(p_command,p_reason) ON CONFLICT DO NOTHING;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_founder_configure_market(uuid,uuid,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_founder_configure_market(uuid,uuid,text,text) TO builderad_app;
