ALTER TABLE public.service_business_scopes
 ADD COLUMN approval_grant_id bigint NOT NULL REFERENCES public.security_action_grants(id);
ALTER TABLE public.service_action_grants ADD COLUMN consent_version bigint NOT NULL CHECK (consent_version>0);
DROP INDEX public.service_action_grants_active_unique;
CREATE UNIQUE INDEX service_action_grants_active_unique ON public.service_action_grants
 (service_identity_id,business_id,action,consent_version) WHERE status='active';
ALTER TABLE public.service_business_scopes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.service_business_scopes FORCE ROW LEVEL SECURITY;
CREATE POLICY service_consent_owner ON public.service_business_scopes TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY service_consent_executor_read ON public.service_business_scopes FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY service_consent_executor_insert ON public.service_business_scopes FOR INSERT TO builderad_scope_executor
 WITH CHECK(builderad_private.effect_allowed('public.service_business_scopes','insert',to_jsonb(service_business_scopes.*)));
CREATE POLICY service_consent_executor_update ON public.service_business_scopes FOR UPDATE TO builderad_scope_executor USING(true)
 WITH CHECK(builderad_private.effect_allowed('public.service_business_scopes','update',to_jsonb(service_business_scopes.*)));
GRANT SELECT,INSERT,UPDATE ON public.service_business_scopes TO builderad_scope_executor;
GRANT SELECT(id,public_id,name,status,version),UPDATE(runtime_lock_marker) ON public.service_identities TO builderad_scope_executor;
CREATE TRIGGER service_consent_effect BEFORE INSERT OR UPDATE OR DELETE ON public.service_business_scopes
 FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER service_consent_truncate BEFORE TRUNCATE ON public.service_business_scopes
 FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
REVOKE ALL ON public.service_business_scopes FROM builderad_app,builderad_authority;
REVOKE UPDATE,DELETE,TRUNCATE ON public.service_audit_events FROM builderad_app,builderad_authority;
GRANT INSERT ON public.service_audit_events TO builderad_scope_executor;
GRANT USAGE ON SEQUENCE public.service_audit_events_id_seq TO builderad_scope_executor;
CREATE TRIGGER service_audit_immutable BEFORE UPDATE OR DELETE ON public.service_audit_events
 FOR EACH ROW EXECUTE FUNCTION public.builderad_sa01_immutable();

CREATE FUNCTION builderad_private.service_mandate(p_business bigint,p_actor bigint,p_mandate bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE basis public.security_action_grants; qualification public.authority_qualification_receipts;
BEGIN
 SELECT * INTO basis FROM public.security_action_grants WHERE id=p_mandate;
 SELECT * INTO qualification FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 IF basis.business_id IS DISTINCT FROM p_business OR basis.subject_user_id IS DISTINCT FROM p_actor
 OR basis.action IS DISTINCT FROM 'business.data.use' OR qualification.policy_id IS DISTINCT FROM 'business_operating_mandate_v1'
 OR qualification.provenance IS DISTINCT FROM 'Explicit dated responsible_business operating mandate; declared assurance only'
 THEN RAISE EXCEPTION 'Exact current responsible Business mandate required for service consent' USING ERRCODE='42501'; END IF;
 RETURN builderad_private.verify_scope_path('business',p_business::text,p_actor,basis.action,builderad_private.business_selection(p_mandate),NULL,'{}',false);
END $$;
ALTER FUNCTION builderad_private.service_mandate(bigint,bigint,bigint) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.service_mandate(bigint,bigint,bigint) FROM PUBLIC;

CREATE FUNCTION builderad_private.service_approval_proof(p_business bigint,p_actor bigint,p_grant bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE approval public.security_action_grants; qualification public.authority_qualification_receipts; proof jsonb;
BEGIN
 SELECT * INTO approval FROM public.security_action_grants WHERE id=p_grant;
 SELECT * INTO qualification FROM public.authority_qualification_receipts WHERE qualification_id=approval.qualification_id;
 IF approval.action IS DISTINCT FROM 'business.service.approve' OR approval.subject_user_id IS DISTINCT FROM p_actor
 OR approval.business_id IS DISTINCT FROM p_business OR approval.delegable OR approval.parent_grant_id IS NOT NULL
 OR qualification.policy_id IS DISTINCT FROM 'business_service_approval_v1' OR qualification.policy_version IS DISTINCT FROM 1
 OR qualification.issuer_grant_id IS NULL OR qualification.allowed_actions IS DISTINCT FROM ARRAY['business.service.approve']::text[]
 THEN RAISE EXCEPTION 'Independent current service approval qualification required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.verify_scope_path('business',p_business::text,p_actor,'business.service.approve',builderad_private.business_selection(p_grant),NULL,'{}',false);
 RETURN proof||jsonb_build_object('service_mandate',builderad_private.service_mandate(p_business,p_actor,qualification.issuer_grant_id));
END $$;
ALTER FUNCTION builderad_private.service_approval_proof(bigint,bigint,bigint) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.service_approval_proof(bigint,bigint,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_private.service_approval_proof(bigint,bigint,bigint) TO builderad_owner;

CREATE FUNCTION public.builderad_business_service_enroll(p_session text,p_business uuid,p_command uuid,p_confirmed boolean,p_reference text,p_expiry timestamptz,p_mandate bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; fresh timestamptz; mandate public.security_action_grants; q public.authority_qualification_receipts;
 grant_row public.security_action_grants; proof jsonb; payload bytea; result jsonb;
BEGIN
 IF p_confirmed IS DISTINCT FROM true OR p_reference IS NULL OR length(btrim(p_reference)) NOT BETWEEN 8 AND 512
 OR p_expiry IS NULL OR NOT isfinite(p_expiry) OR p_command IS NULL THEN RAISE EXCEPTION 'Explicit service responsibility required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true); actor:=builderad_private.principal(p_session,false);
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('realm_kind','direct','requirements',
  (p_selection->'requirements')||(builderad_private.business_selection(p_mandate)->'requirements')),p_session);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'self_declared_draft' THEN
  RAISE EXCEPTION 'Original Business declaration basis required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('service_mandate',builderad_private.service_mandate(b,actor,p_mandate));
 SELECT * INTO STRICT mandate FROM public.security_action_grants WHERE id=p_mandate;
 SELECT authenticated_at INTO fresh FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF fresh IS NULL OR fresh NOT BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp()
 OR p_expiry<=clock_timestamp() OR mandate.expires_at IS NULL OR p_expiry>mandate.expires_at THEN
  RAISE EXCEPTION 'Fresh bounded service approval responsibility required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'reference',p_reference,'expiry',p_expiry,'mandate',p_mandate));
 result:=builderad_private.command_replay(b,actor,p_command,'business.service.enroll',payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND action='business.service.approve'
   AND status='active' AND revoked_at IS NULL AND expires_at>clock_timestamp()) THEN RAISE EXCEPTION 'Current service approval qualification already exists' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('service_freshness',jsonb_build_object('valid_until',fresh+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.service.enroll',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7(); q.policy_id:='business_service_approval_v1'; q.policy_version:=1; q.issuer_kind:='qualified_policy';
 q.issuer_grant_id:=p_mandate; q.subject_user_id:=actor; q.target_kind:='business'; q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.service.approve']; q.evidence_ref:='command:'||p_command; q.evidence_digest:=payload;
 q.status:='active'; q.revision:=1; q.valid_from:=clock_timestamp(); q.valid_until:=p_expiry;
 q.provenance:='Explicit bounded responsibility to approve Business metadata access';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));
 INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 grant_row.id:=nextval('public.security_action_grants_id_seq'); grant_row.subject_user_id:=actor;
 SELECT generation INTO grant_row.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
 grant_row.source:='qualified_policy'; grant_row.action:='business.service.approve'; grant_row.scope_kind:='business'; grant_row.business_id:=b;
 grant_row.reason:='Explicit Business service approval qualification'; grant_row.status:='active'; grant_row.version:=1;
 grant_row.expires_at:=p_expiry; grant_row.created_at:=clock_timestamp(); grant_row.updated_at:=clock_timestamp();
 grant_row.runtime_lock_marker:=false; grant_row.qualification_id:=q.qualification_id; grant_row.delegable:=false; grant_row.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(grant_row));
 INSERT INTO public.security_action_grants SELECT(grant_row).*;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('business_public_id',p_business,'grant_id',grant_row.id::text,'revision',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b));
 result:=result||jsonb_build_object('versions',jsonb_build_object('business',(result->>'revision')::bigint,'grant',grant_row.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.service.enroll',payload,result);
 PERFORM builderad_private.finish_command(); RETURN result;
END $$;
ALTER FUNCTION public.builderad_business_service_enroll(text,uuid,uuid,boolean,text,timestamptz,bigint,jsonb) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_business_service_enroll(text,uuid,uuid,boolean,text,timestamptz,bigint,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_enroll(text,uuid,uuid,boolean,text,timestamptz,bigint,jsonb) TO builderad_app;

CREATE FUNCTION public.builderad_business_service_approval(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; proof jsonb; approval bigint; mandate bigint; candidate bigint; scopes jsonb:='[]';
BEGIN
 b:=builderad_private.lock_business(p_business,false); actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',builderad_private.business_selection(p_read),p_session);
 IF proof#>>'{qualification,policy_id}'='self_declared_draft' THEN
  FOR candidate IN SELECT id FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor
   AND action IN('business.data.use','business.service.approve') AND status='active' AND revoked_at IS NULL ORDER BY id DESC LOOP
   BEGIN
    IF (SELECT action FROM public.security_action_grants WHERE id=candidate)='business.service.approve' THEN
     PERFORM builderad_private.service_approval_proof(b,actor,candidate); approval:=candidate;
    ELSE PERFORM builderad_private.service_mandate(b,actor,candidate); mandate:=candidate; END IF;
   EXCEPTION WHEN insufficient_privilege THEN CONTINUE;
   END;
  END LOOP;
  IF approval IS NOT NULL THEN
   SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',i.public_id,'name',i.name,'purpose',s.purpose,'revision',s.version) ORDER BY i.name,i.id),'[]') INTO scopes
   FROM public.service_business_scopes s JOIN public.service_identities i ON i.id=s.service_identity_id
   WHERE s.business_id=b AND s.status='active' AND s.authorized_by=actor;
  END IF;
 END IF;
 RETURN jsonb_build_object('enrollment',jsonb_build_object('available',mandate IS NOT NULL AND approval IS NULL,'mandate_grant_id',mandate::text),
  'available',approval IS NOT NULL,'selection',CASE WHEN approval IS NOT NULL THEN builderad_private.business_selection(approval) END,
  'business_revision',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b),'scopes',scopes);
END $$;
ALTER FUNCTION public.builderad_business_service_approval(text,uuid,bigint) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_business_service_approval(text,uuid,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_approval(text,uuid,bigint) TO builderad_app;

CREATE FUNCTION public.builderad_business_service_consent(p_session text,p_business uuid,p_command uuid,p_service uuid,p_purpose text,p_expected bigint,p_epoch bigint,p_selection jsonb,p_revoke boolean) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; approval bigint; fresh timestamptz; service public.service_identities;
 prior public.service_business_scopes; next_scope public.service_business_scopes; proof jsonb; payload bytea; result jsonb;
BEGIN
 IF p_purpose IS NULL OR length(btrim(p_purpose)) NOT BETWEEN 10 AND 128 OR p_expected IS NULL OR p_epoch IS NULL OR p_revoke IS NULL THEN
  RAISE EXCEPTION 'Exact service consent command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true); actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.service.approve',p_selection,p_session);
 approval:=(proof#>>'{grants,0,id}')::bigint;
 proof:=builderad_private.service_approval_proof(b,actor,approval);
 SELECT authenticated_at INTO fresh FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF fresh IS NULL OR fresh NOT BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp() THEN RAISE EXCEPTION 'Fresh Business consent required' USING ERRCODE='42501'; END IF;
 SELECT id,status INTO service.id,service.status FROM public.service_identities WHERE public_id=p_service FOR SHARE;
 IF NOT FOUND OR (NOT p_revoke AND service.status<>'enabled') THEN RAISE EXCEPTION 'Available service identity required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'service',p_service,'purpose',p_purpose,'expected',p_expected,'epoch',p_epoch,'revoke',p_revoke));
 result:=builderad_private.command_replay(b,actor,p_command,'business.service.consent',payload); IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO prior FROM public.service_business_scopes WHERE business_id=b AND service_identity_id=service.id FOR UPDATE;
 IF coalesce(prior.version,0)<>p_expected OR (SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)<>p_epoch
 OR (p_revoke AND (prior.status IS DISTINCT FROM 'active' OR prior.authorized_by<>actor OR prior.purpose<>p_purpose)) THEN
  RAISE EXCEPTION 'Reviewed service consent changed' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('service_freshness',jsonb_build_object('valid_until',fresh+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.service.consent',jsonb_build_object('business_id',b),proof);
 next_scope:=prior; next_scope.business_id:=b; next_scope.service_identity_id:=service.id; next_scope.authorized_by:=actor;
 next_scope.approval_grant_id:=approval; next_scope.purpose:=p_purpose;
 next_scope.version:=coalesce(prior.version,0)+1; next_scope.status:=CASE WHEN p_revoke THEN 'revoked' ELSE 'active' END;
 next_scope.revoked_at:=CASE WHEN p_revoke THEN clock_timestamp() END;
 next_scope.created_at:=coalesce(prior.created_at,clock_timestamp()); next_scope.updated_at:=clock_timestamp();
 IF prior.version IS NULL THEN
  PERFORM builderad_private.permit('public.service_business_scopes','insert',NULL,to_jsonb(next_scope));
  INSERT INTO public.service_business_scopes SELECT(next_scope).*;
 ELSE
  PERFORM builderad_private.permit('public.service_business_scopes','update',to_jsonb(prior),to_jsonb(next_scope));
  UPDATE public.service_business_scopes SET authorized_by=next_scope.authorized_by,approval_grant_id=next_scope.approval_grant_id,
   purpose=next_scope.purpose,version=next_scope.version,status=next_scope.status,
   revoked_at=next_scope.revoked_at,updated_at=next_scope.updated_at WHERE business_id=b AND service_identity_id=service.id;
 END IF;
 INSERT INTO public.service_audit_events(actor_user_id,service_identity_id,business_id,event,reason,prior_status,new_status,prior_version,new_version,occurred_at)
 VALUES(actor,service.id,b,CASE WHEN p_revoke THEN 'service_business_consent_revoked' ELSE 'service_business_consent_approved' END,
  p_purpose,prior.status,next_scope.status,prior.version,next_scope.version,clock_timestamp());
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.service_approval_proof(b,actor,approval);
 result:=jsonb_build_object('redirect','/businesses/'||p_business::text||'?access='||(proof#>>'{service_mandate,qualification,issuer_grant_id}')||'&section=sources',
  'versions',jsonb_build_object('consent',next_scope.version,'business',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.service.consent',payload,result);
 PERFORM builderad_private.finish_command(); RETURN result;
END $$;
ALTER FUNCTION public.builderad_business_service_consent(text,uuid,uuid,uuid,text,bigint,bigint,jsonb,boolean) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_business_service_consent(text,uuid,uuid,uuid,text,bigint,bigint,jsonb,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_consent(text,uuid,uuid,uuid,text,bigint,bigint,jsonb,boolean) TO builderad_app;

CREATE FUNCTION public.builderad_service_business_metadata(p_service uuid,p_secret text,p_business uuid,p_purpose text) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE b public.businesses; service public.service_identities; consent public.service_business_scopes; admin_grant public.service_action_grants;
BEGIN
 IF session_user<>'builderad_app' OR p_secret IS NULL OR length(p_secret) NOT BETWEEN 40 AND 128 THEN
  RAISE EXCEPTION 'Exact service authority required' USING ERRCODE='42501'; END IF;
 SELECT * INTO b FROM public.businesses WHERE public_id=p_business;
 PERFORM 1 FROM public.migration_cohort_control c JOIN public.migration_cohort_members m ON m.cohort_id=c.cohort_id
  WHERE m.business_id=b.id AND c.mode='V2_ACTIVE' FOR SHARE OF c;
 IF NOT FOUND THEN RAISE EXCEPTION 'Current Business service scope required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=b.id FOR SHARE;
 SELECT * INTO b FROM public.businesses WHERE id=b.id AND lifecycle IN('declared_draft','active') FOR SHARE;
 SELECT * INTO service FROM public.service_identities WHERE public_id=p_service AND status='enabled'
  AND credential_hash=encode(sha256(convert_to(p_secret,'UTF8')),'hex') FOR SHARE;
 SELECT * INTO consent FROM public.service_business_scopes WHERE business_id=b.id AND service_identity_id=service.id
  AND status='active' AND revoked_at IS NULL AND purpose=p_purpose FOR SHARE;
 SELECT * INTO admin_grant FROM public.service_action_grants WHERE business_id=b.id AND service_identity_id=service.id
  AND consent_version=consent.version AND action='business.metadata.read' AND status='active' AND revoked_at IS NULL FOR SHARE;
 IF NOT FOUND THEN
  RAISE EXCEPTION 'Service identity, Business consent and administrative grant required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=consent.authorized_by FOR SHARE;
 PERFORM builderad_private.service_approval_proof(b.id,consent.authorized_by,consent.approval_grant_id);
 IF admin_grant.expires_at IS NOT NULL AND admin_grant.expires_at<=clock_timestamp() THEN
  RAISE EXCEPTION 'Current administrative service grant required' USING ERRCODE='42501'; END IF;
 INSERT INTO public.service_audit_events(service_identity_id,business_id,event,reason,new_status,new_version,occurred_at)
 VALUES(service.id,b.id,'service_business_metadata_read',consent.purpose,consent.status,consent.version,clock_timestamp());
 RETURN (SELECT jsonb_build_object('public_id',b.public_id,'display_name',h.display_name,'lifecycle',b.lifecycle)
  FROM public.business_context_heads h WHERE h.business_id=b.id);
END $$;
REVOKE ALL ON FUNCTION public.builderad_service_business_metadata(uuid,text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_service_business_metadata(uuid,text,uuid,text) TO builderad_app;

CREATE FUNCTION public.builderad_service_business_public_by_id(p_business bigint) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
 SELECT b.public_id FROM public.businesses b WHERE b.id=p_business AND EXISTS (
  SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
  JOIN public.internal_personnel i ON i.user_id=l.user_id AND i.status='active'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND l.status='current' AND l.principal_class='staff'
   AND g.scope_kind='platform' AND g.action='service.manage' AND g.status='active' AND g.revoked_at IS NULL
   AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
$$;
REVOKE ALL ON FUNCTION public.builderad_service_business_public_by_id(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_service_business_public_by_id(bigint) TO builderad_app;

CREATE VIEW public.service_business_admin_scopes WITH (security_barrier=true) AS
 SELECT service_identity_id,business_id,purpose,status FROM public.service_business_scopes
 WHERE public.builderad_service_business_public_by_id(business_id) IS NOT NULL;
REVOKE ALL ON public.service_business_admin_scopes FROM PUBLIC;
GRANT SELECT ON public.service_business_admin_scopes TO builderad_app;

CREATE FUNCTION public.builderad_service_grant_target(p_business uuid,p_service uuid) RETURNS TABLE(business_id bigint,consent_version bigint)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE b bigint; s public.service_identities; consent public.service_business_scopes;
BEGIN
 IF session_user<>'builderad_authority' THEN RAISE EXCEPTION 'Service grant authority connection required' USING ERRCODE='42501'; END IF;
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 PERFORM 1 FROM public.business_authority_fences f WHERE f.business_id=b FOR SHARE;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND lifecycle IN('active','declared_draft')) THEN
  RAISE EXCEPTION 'Current Business required for service grant' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.service_identities WHERE public_id=p_service AND status='enabled' FOR SHARE;
 SELECT c.* INTO consent FROM public.service_business_scopes c WHERE c.business_id=b AND c.service_identity_id=s.id
  AND c.status='active' AND c.revoked_at IS NULL FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Current Business service consent required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=consent.authorized_by FOR SHARE;
 PERFORM builderad_private.service_approval_proof(b,consent.authorized_by,consent.approval_grant_id);
 RETURN QUERY SELECT b,consent.version;
END $$;
REVOKE ALL ON FUNCTION public.builderad_service_grant_target(uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_service_grant_target(uuid,uuid) TO builderad_authority;
