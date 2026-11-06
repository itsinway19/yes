-- Reuse the native obligation and independent custody acknowledgment. Only the
-- new protected custody branch needs a permit for its local delivery update.
CREATE FUNCTION builderad_private.business_source_audit_delivery(p_session text,p_obligation uuid,p_ack uuid) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE o public.source_audit_obligations;r public.source_command_receipts;prior public.source_audit_delivery;next_row public.source_audit_delivery;
 actor bigint;selected bigint;proof jsonb;
BEGIN
 SELECT obligation.* INTO o FROM public.source_audit_obligations obligation JOIN public.source_registry s ON s.id=obligation.source_id
  WHERE obligation.id=p_obligation AND obligation.workspace_id IS NULL AND s.custody_kind='business_native' AND s.workspace_id IS NULL;
 IF NOT FOUND THEN RAISE EXCEPTION 'Business Source obligation unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.source_command_receipts WHERE source_id=o.source_id AND command_id=o.command_id AND workspace_id IS NULL;
 actor:=builderad_private.business_source_require('source.audit.recover',o.source_id,r.resource,r.operation,r.purpose,p_session);
 SELECT id INTO STRICT selected FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='source' AND workspace_id IS NULL
  AND source_target_id=o.source_id AND source_resource=r.resource AND source_operation=r.operation AND source_purpose=r.purpose
  AND action='source.audit.recover' AND status='active' AND revoked_at IS NULL;
 proof:=builderad_private.business_source_proof(selected,actor,p_session,true);
 SELECT * INTO STRICT prior FROM public.source_audit_delivery WHERE obligation_id=o.id AND workspace_id IS NULL FOR UPDATE;
 IF p_ack IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.security_source_audit_custody c WHERE c.obligation_id=o.id AND c.acknowledgment_id=p_ack AND c.fingerprint=o.fingerprint AND c.envelope=o.envelope)
 THEN RAISE EXCEPTION 'Independent matching custody required' USING ERRCODE='42501'; END IF;
 IF prior.state<>'ACKNOWLEDGED' THEN
  PERFORM builderad_private.start_command(actor,p_session,uuidv7(),'source.audit.recover',jsonb_build_object('source_id',o.source_id,'obligation_id',o.id),proof);
  next_row:=prior;next_row.state:=CASE WHEN p_ack IS NULL THEN 'FAILED' ELSE 'ACKNOWLEDGED' END;
  next_row.acknowledgment_id:=p_ack;next_row.attempts:=prior.attempts+1;next_row.updated_at:=clock_timestamp();
  PERFORM builderad_private.permit('public.source_audit_delivery','update',to_jsonb(prior),to_jsonb(next_row));
  UPDATE public.source_audit_delivery SET state=next_row.state,acknowledgment_id=next_row.acknowledgment_id,attempts=next_row.attempts,updated_at=next_row.updated_at WHERE obligation_id=o.id;
  PERFORM builderad_private.business_source_proof(selected,actor,p_session,true);
  PERFORM builderad_private.finish_command();
 END IF;
 PERFORM builderad_private.business_source_require('source.audit.recover',o.source_id,r.resource,r.operation,r.purpose,p_session);
 RETURN (SELECT state FROM public.source_audit_delivery WHERE obligation_id=o.id);
END $$;

RESET ROLE;
CREATE OR REPLACE FUNCTION public.builderad_source_record_delivery(p_workspace bigint,p_session text,p_obligation uuid,p_ack uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE o source_audit_obligations;r source_command_receipts;v_state text;
BEGIN
 IF p_workspace IS NULL THEN RETURN builderad_private.business_source_audit_delivery(p_session,p_obligation,p_ack); END IF;
 SELECT * INTO o FROM source_audit_obligations WHERE id=p_obligation AND workspace_id=p_workspace;
 IF NOT FOUND THEN RAISE EXCEPTION 'Obligation unavailable' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM source_command_receipts WHERE workspace_id=p_workspace AND command_id=o.command_id;
 PERFORM builderad_source_require('source.audit.recover',o.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
 PERFORM 1 FROM source_audit_delivery WHERE obligation_id=o.id FOR UPDATE;
 IF p_ack IS NOT NULL AND NOT EXISTS(SELECT 1 FROM security_source_audit_custody c WHERE c.obligation_id=o.id AND c.acknowledgment_id=p_ack AND c.fingerprint=o.fingerprint AND c.envelope=o.envelope)
 THEN RAISE EXCEPTION 'Independent matching custody required' USING ERRCODE='42501'; END IF;
 UPDATE source_audit_delivery SET state=CASE WHEN p_ack IS NULL THEN 'FAILED' ELSE 'ACKNOWLEDGED' END,acknowledgment_id=p_ack,attempts=attempts+1,updated_at=clock_timestamp()
  WHERE obligation_id=o.id AND state<>'ACKNOWLEDGED';
 PERFORM builderad_source_require('source.audit.recover',o.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
 SELECT state INTO v_state FROM source_audit_delivery WHERE obligation_id=o.id;RETURN v_state;
END $$;

CREATE OR REPLACE FUNCTION public.builderad_source_begin_recovery(p_workspace bigint,p_session text,p_obligation uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE r source_command_receipts;
BEGIN
 SELECT c.* INTO r FROM source_command_receipts c JOIN source_audit_obligations o ON o.source_id=c.source_id AND o.workspace_id IS NOT DISTINCT FROM c.workspace_id AND o.command_id=c.command_id
  WHERE o.workspace_id IS NOT DISTINCT FROM p_workspace AND o.id=p_obligation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Obligation unavailable' USING ERRCODE='42501'; END IF;
 PERFORM builderad_source_require('source.audit.recover',r.source_id,p_workspace,r.resource,r.operation,r.purpose,p_session);
END $$;
SET LOCAL ROLE builderad_scope_executor;
