CREATE FUNCTION builderad_private.identity_generation_continuity(p_user bigint,p_from bigint,p_to bigint)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE step bigint:=p_from; transition record; pending boolean:=false;
BEGIN
 IF p_from<1 OR p_to<=p_from OR p_to-p_from>64 THEN RETURN false; END IF;
 WHILE step<p_to LOOP
  SELECT e.event,e.prior_status,e.new_status INTO transition FROM public.security_audit_events e
  WHERE e.subject_user_id=p_user AND e.prior_generation=step AND e.new_generation=step+1
   AND e.event IN ('advertiser_activated','customer_suspend','customer_reactivate') ORDER BY e.id DESC LIMIT 1;
  IF NOT FOUND THEN RETURN false; END IF;
  IF transition.event='advertiser_activated' AND transition.prior_status='pending' AND transition.new_status='current' THEN
   IF NOT pending AND NOT EXISTS(SELECT 1 FROM public.security_audit_events WHERE subject_user_id=p_user
      AND security_audit_events.event='primary_email_changed' AND prior_generation=step AND new_generation=step) THEN RETURN false; END IF;
   pending:=false; step:=step+1;
  ELSIF transition.event='customer_suspend' AND transition.prior_status IN('current','pending') AND transition.new_status='disabled' THEN
   SELECT e.event,e.prior_status,e.new_status INTO transition FROM public.security_audit_events e
   WHERE e.subject_user_id=p_user AND e.prior_generation=step+1 AND e.new_generation=step+2 AND e.event='customer_reactivate'
   ORDER BY e.id DESC LIMIT 1;
   IF NOT FOUND OR transition.prior_status<>'disabled' OR transition.new_status NOT IN('current','pending') OR step+2>p_to THEN RETURN false; END IF;
   pending:=transition.new_status='pending'; step:=step+2;
  ELSE RETURN false;
  END IF;
 END LOOP;
 RETURN NOT pending;
END $$;
ALTER FUNCTION builderad_private.identity_generation_continuity(bigint,bigint,bigint) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.identity_generation_continuity(bigint,bigint,bigint) FROM PUBLIC;

CREATE FUNCTION builderad_private.requalify_business_identity(p_session text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; generation_now bigint; ids bigint[]; locked_businesses bigint[]; row_before public.security_action_grants;
 row_after public.security_action_grants; footprint jsonb; combined jsonb; selection jsonb; key_name text;
 proof jsonb; command uuid; target text; progress boolean; candidates bigint[]:='{}';
BEGIN
 actor:=builderad_private.principal(p_session,false);
 SELECT generation INTO generation_now FROM public.principal_lifecycles WHERE user_id=actor;
 SELECT array_agg(id ORDER BY id) INTO ids FROM public.security_action_grants
  WHERE subject_user_id=actor AND subject_generation<generation_now AND scope_kind IN('business','source','client_data','data_use')
   AND status='active' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())
   AND builderad_private.identity_generation_continuity(actor,subject_generation,generation_now);
 IF ids IS NULL THEN RETURN; END IF;
 combined:=jsonb_build_object('grants','[]'::jsonb,'qualifications','[]'::jsonb,'users',jsonb_build_array(actor),'agency_businesses','[]'::jsonb);
 FOR row_before IN SELECT * FROM public.security_action_grants WHERE id=ANY(ids) ORDER BY id LOOP
  BEGIN
   selection:=jsonb_build_object('realm_kind','direct','requirements',jsonb_build_array(jsonb_build_object(
    'requirement_kind',CASE row_before.scope_kind WHEN 'source' THEN 'native_source' ELSE row_before.scope_kind END,'leaf_grant_id',row_before.id)));
   footprint:=builderad_private.authority_footprint(actor,selection);
   FOREACH key_name IN ARRAY ARRAY['grants','qualifications','users','agency_businesses'] LOOP
    combined:=jsonb_set(combined,ARRAY[key_name],(combined->key_name)||(footprint->key_name));
   END LOOP;
   candidates:=array_append(candidates,row_before.id);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;
  END;
 END LOOP;
 IF cardinality(candidates)=0 THEN RETURN; END IF;
 SELECT array_agg(DISTINCT business_id) INTO locked_businesses FROM public.security_action_grants
  WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(combined->'grants')) AND business_id IS NOT NULL;
 locked_businesses:=coalesce(locked_businesses,'{}'::bigint[])||ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(combined->'agency_businesses'));
 PERFORM 1 FROM public.resource_right_fences f WHERE EXISTS(SELECT 1 FROM public.security_action_grants g
   WHERE g.id IN(SELECT value::bigint FROM jsonb_array_elements_text(combined->'grants')) AND
   (f.source_id=g.source_target_id OR f.boundary_id=g.boundary_id OR f.data_use_scope_id=g.data_use_scope_id)) ORDER BY f.fence_id FOR SHARE;
 PERFORM 1 FROM public.migration_cohort_control c WHERE c.cohort_id IN(SELECT m.cohort_id FROM public.migration_cohort_members m
   WHERE m.business_id=ANY(locked_businesses)) ORDER BY c.cohort_id FOR SHARE;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=ANY(locked_businesses) ORDER BY business_id FOR UPDATE;
 PERFORM builderad_private.lock_authority_rows(actor,p_session,combined);
 PERFORM builderad_private.principal(p_session);
 IF generation_now IS DISTINCT FROM (SELECT generation FROM public.principal_lifecycles WHERE user_id=actor) THEN
  RAISE EXCEPTION 'Identity changed during Business recovery' USING ERRCODE='40001';
 END IF;
 FOR attempt IN 1..16 LOOP
  progress:=false;
  FOR row_before IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidates) AND subject_user_id=actor
   AND subject_generation<generation_now AND status='active' AND revoked_at IS NULL
   AND (expires_at IS NULL OR expires_at>clock_timestamp()) ORDER BY id LOOP
   BEGIN
    IF NOT builderad_private.identity_generation_continuity(actor,row_before.subject_generation,generation_now) THEN CONTINUE; END IF;
    selection:=jsonb_build_object('realm_kind','direct','requirements',jsonb_build_array(jsonb_build_object(
     'requirement_kind',CASE row_before.scope_kind WHEN 'source' THEN 'native_source' ELSE row_before.scope_kind END,'leaf_grant_id',row_before.id)));
    footprint:=builderad_private.authority_footprint(actor,selection);
    IF NOT ((combined->'grants') @> (footprint->'grants') AND (combined->'qualifications') @> (footprint->'qualifications')
       AND (combined->'users') @> (footprint->'users') AND (combined->'agency_businesses') @> (footprint->'agency_businesses')) THEN
      RAISE EXCEPTION 'Identity recovery authority footprint changed' USING ERRCODE='40001';
    END IF;
    IF row_before.business_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.businesses b
       JOIN public.migration_cohort_members m ON m.business_id=b.id JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
       WHERE b.id=row_before.business_id AND b.lifecycle IN('active','declared_draft') AND c.mode='V2_ACTIVE') THEN CONTINUE; END IF;
    row_after:=row_before; row_after.subject_generation:=generation_now; command:=uuidv7();
    proof:=jsonb_build_object('identity_continuity',jsonb_build_object('actor',actor,'grant_id',row_before.id,
      'from_generation',row_before.subject_generation,'to_generation',generation_now,'before',to_jsonb(row_before),'after',to_jsonb(row_after)));
    PERFORM builderad_private.start_command(actor,p_session,command,'identity.business.requalify',jsonb_build_object('grant_id',row_before.id),proof);
    PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(row_before),to_jsonb(row_after));
    UPDATE public.security_action_grants SET subject_generation=generation_now WHERE id=row_before.id;
    IF row_before.scope_kind='source' THEN
     PERFORM builderad_private.business_source_proof(row_before.id,actor,p_session,false);
    ELSE
     target:=coalesce(row_before.business_id::text,row_before.boundary_id::text,row_before.data_use_scope_id::text);
     PERFORM builderad_private.verify_scope_path(row_before.scope_kind,target,actor,row_before.action,selection,p_session,'{}',false);
    END IF;
    PERFORM builderad_private.record_command(row_before.business_id,actor,command,'identity.business.requalify',
      builderad_private.authority_digest(proof),jsonb_build_object('grant_id',row_before.id,'versions',
       jsonb_build_object('grant',row_before.version,'principal_generation',generation_now)));
    PERFORM builderad_private.finish_command();
    DELETE FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='completed' AND command_id=command;
    progress:=true;
   EXCEPTION WHEN insufficient_privilege OR unique_violation THEN CONTINUE;
   END;
  END LOOP;
  EXIT WHEN NOT progress;
 END LOOP;
END $$;
ALTER FUNCTION builderad_private.requalify_business_identity(text) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.requalify_business_identity(text) FROM PUBLIC;

CREATE FUNCTION public.builderad_founder_bootstrap_guard(p_user bigint)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
BEGIN
 IF session_user<>'builderad_authority' THEN RAISE EXCEPTION 'Founder bootstrap requires the authority connection' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=p_user AND status='current' AND principal_class='advertiser' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Current verified advertiser required for Founder bootstrap' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.businesses WHERE created_by=p_user AND lifecycle IN('declared_draft','active','suspended'))
 OR EXISTS(SELECT 1 FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   WHERE (g.subject_user_id=p_user OR g.granted_by=p_user) AND g.scope_kind IN('business','source','client_data','data_use')
    AND g.status='active' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())
    AND q.status='active' AND q.valid_from<=clock_timestamp() AND (q.valid_until IS NULL OR q.valid_until>clock_timestamp())) THEN
  RAISE EXCEPTION 'Founder bootstrap cannot convert an account with current Business responsibilities or qualified rights' USING ERRCODE='42501';
 END IF;
END $$;
REVOKE ALL ON FUNCTION public.builderad_founder_bootstrap_guard(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_founder_bootstrap_guard(bigint) TO builderad_authority;
GRANT SELECT ON public.security_audit_events TO builderad_scope_executor;
CREATE POLICY identity_audit_executor_read ON public.security_audit_events FOR SELECT TO builderad_scope_executor USING (true);
