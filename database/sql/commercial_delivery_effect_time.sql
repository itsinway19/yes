-- Forward repair: custody acceptance is independent of current origin-delivery authority.
CREATE OR REPLACE FUNCTION builderad_record_commercial_audit_delivery(p_obligation uuid,p_state text,p_ack uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE o commercial_audit_obligations; d commercial_audit_delivery;
BEGIN
 SELECT * INTO o FROM commercial_audit_obligations WHERE id = p_obligation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable obligation' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.workspace_id);
 SELECT * INTO d FROM commercial_audit_delivery WHERE obligation_id = p_obligation FOR UPDATE;
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.workspace_id);
 IF d.state = 'acknowledged' THEN RETURN; END IF;
 IF p_state = 'acknowledged' AND NOT EXISTS (
   SELECT 1 FROM security_commercial_audit_custody c WHERE c.obligation_id = o.id AND c.fingerprint = o.fingerprint AND c.acknowledgment_id = p_ack
 ) THEN RAISE EXCEPTION 'Actual custody acknowledgment required' USING ERRCODE = '23514'; END IF;
 UPDATE commercial_audit_delivery SET state = p_state,acknowledgment_id = p_ack,attempts = attempts+1,updated_at = clock_timestamp() WHERE obligation_id = p_obligation;
 -- Any wait during the UPDATE must also be fenced; denial rolls back the local effect.
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.workspace_id);
END $$;
