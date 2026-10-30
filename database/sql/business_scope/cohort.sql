-- Trusted deployment maintenance never creates customer grants or claims a live migration was inventoried.
CREATE FUNCTION builderad_v2_cohort_transition(p_command uuid,p_cohort uuid,p_mode text,p_epoch bigint,p_requested text,p_manifest uuid,p_checkpoint bytea) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.migration_cohort_control;c public.migration_cohort_control;payload bytea;result jsonb;r public.business_command_receipts;related uuid[];
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Deployment operator only' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_cohort IS NULL OR p_epoch IS NULL OR p_epoch<0 OR p_mode IS NULL OR p_requested IS NULL OR p_checkpoint IS NULL OR octet_length(p_checkpoint)<>32
 OR p_requested NOT IN('LEGACY_ACTIVE','DRAINING','V2_ACTIVE','RECOVERY_HOLD') THEN RAISE EXCEPTION 'Exact maintenance transition and checkpoint required' USING ERRCODE='22023'; END IF;
 related:=builderad_private.legacy_related_cohorts(p_cohort);
 PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=ANY(related) ORDER BY cohort_id FOR UPDATE;
 IF builderad_private.legacy_related_cohorts(p_cohort) IS DISTINCT FROM related THEN RAISE EXCEPTION 'Related cohort set changed; no late lock expansion' USING ERRCODE='40001'; END IF;
 SELECT * INTO prior FROM public.migration_cohort_control WHERE cohort_id=p_cohort;
 IF NOT FOUND THEN RAISE EXCEPTION 'Unknown deployment cohort' USING ERRCODE='22023'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('cohort',p_cohort,'mode',p_mode,'epoch',p_epoch,'requested',p_requested,'manifest',p_manifest,'checkpoint',p_checkpoint),'{"cohort":"U","epoch":"I","manifest":"U","checkpoint":"X"}');
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF r.actor_user_id IS NOT NULL OR r.action<>'maintenance.cohort.transition' OR r.payload_digest<>payload THEN RAISE EXCEPTION 'Maintenance command identity conflict' USING ERRCODE='40001'; END IF;
  RETURN r.result_ref;
 END IF;
 IF prior.mode<>p_mode OR prior.epoch<>p_epoch THEN RAISE EXCEPTION 'Cohort changed; reconcile authoritative state' USING ERRCODE='40001'; END IF;
 IF NOT((prior.mode='LEGACY_ACTIVE' AND p_requested='DRAINING') OR(prior.mode='DRAINING' AND p_requested IN('LEGACY_ACTIVE','V2_ACTIVE'))
  OR(prior.mode='V2_ACTIVE' AND p_requested='RECOVERY_HOLD') OR(prior.mode='RECOVERY_HOLD' AND p_requested='V2_ACTIVE')) THEN RAISE EXCEPTION 'Forbidden cohort transition; no post-v2 legacy downgrade' USING ERRCODE='42501'; END IF;
 IF p_requested='V2_ACTIVE' THEN PERFORM builderad_private.legacy_activation_require(p_cohort,p_manifest,p_checkpoint); END IF;
 IF p_requested='LEGACY_ACTIVE' AND EXISTS(SELECT 1 FROM public.business_command_receipts WHERE action='maintenance.legacy.import' AND result_ref->>'cohort_id'=p_cohort::text) THEN RAISE EXCEPTION 'Imported canonical facts forbid legacy restoration' USING ERRCODE='42501'; END IF;
 IF p_requested='LEGACY_ACTIVE' AND EXISTS(SELECT 1 FROM public.business_command_receipts WHERE action='maintenance.cohort.transition' AND result_ref->>'cohort_id'=p_cohort::text AND result_ref->>'mode' IN('V2_ACTIVE','RECOVERY_HOLD')) THEN RAISE EXCEPTION 'Legacy downgrade forbidden' USING ERRCODE='42501'; END IF;
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.cohort.transition',
  jsonb_build_object('cohort_id',p_cohort),jsonb_build_object('operator_role',session_user),builderad_private.authority_digest(jsonb_build_object('operator_role',session_user)),
  builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 c:=prior;c.mode:=p_requested;c.epoch:=prior.epoch+1;c.checkpoint_digest:=p_checkpoint;
 PERFORM builderad_private.permit('public.migration_cohort_control','update',to_jsonb(prior),to_jsonb(c));
 UPDATE public.migration_cohort_control SET mode=c.mode,epoch=c.epoch,checkpoint_digest=c.checkpoint_digest WHERE cohort_id=p_cohort;
 result:=jsonb_build_object('cohort_id',p_cohort,'mode',c.mode,'epoch',c.epoch,'checkpoint',encode(c.checkpoint_digest,'hex'),'manifest_ref',p_manifest);
 r.command_id:=p_command;r.business_id:=NULL;r.actor_user_id:=NULL;r.generation:=0;r.action:='maintenance.cohort.transition';r.payload_digest:=payload;r.state:='committed';r.result_ref:=result;
 r.returned_versions:=jsonb_build_object('epoch',c.epoch);r.recorded_at:=clock_timestamp();r.authority_selection_digest:=builderad_private.authority_digest(jsonb_build_object('operator_role',session_user));r.dependency_digest:=builderad_private.digest('dependency-set:1','[]'::jsonb);
 PERFORM builderad_private.permit('public.business_command_receipts','insert',NULL,to_jsonb(r));INSERT INTO public.business_command_receipts SELECT(r).*;
 IF p_requested='V2_ACTIVE' THEN PERFORM builderad_private.legacy_activation_require(p_cohort,p_manifest,p_checkpoint); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
