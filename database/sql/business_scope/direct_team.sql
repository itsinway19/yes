-- Explicit administration of an independent Business's current declarations.
-- This policy neither represents an external client nor releases private history/data.
CREATE FUNCTION builderad_v2_business_team_enroll(p_session text,p_business uuid,p_command uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;q public.authority_qualification_receipts;
 g public.security_action_grants;action_name text;refs jsonb:='{}';authenticated timestamptz;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Exact enrollment command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF actor<>(SELECT created_by FROM public.businesses WHERE id=b) OR p_selection->>'realm_kind'<>'direct'
 OR proof#>>'{qualification,policy_id}'<>'self_declared_draft' OR proof#>>'{qualification,policy_version}'<>'1'
 OR proof#>>'{qualification,issuer_kind}'<>'self_declared_draft'
 THEN RAISE EXCEPTION 'Current original creator declaration basis required' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh independent team confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business),'{"business":"U"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.direct_team.enroll',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1'
  AND target_kind='business' AND target_ref=p_business AND subject_user_id=actor AND status='active')
 THEN RAISE EXCEPTION 'Existing team enrollment must be reconciled, not replaced' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.direct_team.enroll',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_declaration_team_admin_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();
 q.provenance:='Original creator explicit independent declaration-team administration; no external mandate, private history, source or customer-data rights';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 FOREACH action_name IN ARRAY q.allowed_actions LOOP
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
  g.reason:='Explicit independent Business team';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:='direct';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  refs:=refs||jsonb_build_object(action_name,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',refs,'qualification_ref',q.qualification_id,
  'versions',jsonb_build_object('qualification',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.direct_team.enroll',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
