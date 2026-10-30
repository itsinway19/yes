-- Inventory-neutral coordination only: retain every existing native Workspace as legacy, infer no Business.
DO $bootstrap$
DECLARE w record;c public.migration_cohort_control;m public.migration_cohort_members;
BEGIN
 IF EXISTS(SELECT 1 FROM public.workspaces existing WHERE NOT EXISTS(SELECT 1 FROM public.migration_cohort_members member WHERE member.workspace_id=existing.id AND member.kind='legacy_workspace')) THEN
  IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Deployment legacy registration required' USING ERRCODE='42501'; END IF;
  INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),uuidv7(),NULL,0,NULL,'maintenance.legacy.registration',
   '{"schema_version":1}'::jsonb,'{"operator_role":"builderad_owner"}'::jsonb,builderad_private.authority_digest('{"operator_role":"builderad_owner"}'::jsonb),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
  FOR w IN SELECT id FROM public.workspaces existing WHERE NOT EXISTS(SELECT 1 FROM public.migration_cohort_members member WHERE member.workspace_id=existing.id AND member.kind='legacy_workspace') ORDER BY id LOOP
   c.cohort_id:=uuidv7();c.mode:='LEGACY_ACTIVE';c.epoch:=0;c.guard_schema_version:=0;c.manifest_version:=0;c.checkpoint_digest:=NULL;
   PERFORM builderad_private.permit('public.migration_cohort_control','insert',NULL,to_jsonb(c));INSERT INTO public.migration_cohort_control SELECT(c).*;
   m.member_id:=uuidv7();m.cohort_id:=c.cohort_id;m.kind:='legacy_workspace';m.workspace_id:=w.id;m.business_id:=NULL;
   PERFORM builderad_private.permit('public.migration_cohort_members','insert',NULL,to_jsonb(m));INSERT INTO public.migration_cohort_members SELECT(m).*;
  END LOOP;
 END IF;
END $bootstrap$;
-- Legacy admissions are coordination records, never a grant or a Workspace-to-Business resolver.
CREATE FUNCTION builderad_v2_legacy_workspace_visible(p_workspace bigint) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 -- Retirement closes the whole raw legacy profile, which still contains business columns.
 -- A future cooperation projection must select only its own metadata; it cannot reopen this row.
 SELECT EXISTS(SELECT 1 FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
  WHERE m.kind='legacy_workspace' AND m.workspace_id=p_workspace AND c.mode='LEGACY_ACTIVE')
 AND(session_user='builderad_authority' OR EXISTS(SELECT 1 FROM public.builderad_signed_context() actor JOIN public.workspace_memberships member ON member.user_id=actor.actor_user_id
   WHERE member.workspace_id=p_workspace AND member.status='active')
  OR EXISTS(SELECT 1 FROM public.builderad_signed_service_context() service WHERE service.workspace_id=p_workspace));
$$;

CREATE FUNCTION builderad_private.legacy_admitted(p_workspace bigint) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE c public.migration_cohort_control;
BEGIN
 SELECT control.* INTO c FROM public.migration_cohort_members m JOIN public.migration_cohort_control control ON control.cohort_id=m.cohort_id WHERE m.workspace_id=p_workspace AND m.kind='legacy_workspace';
 IF NOT FOUND OR c.mode<>'LEGACY_ACTIVE' OR NOT EXISTS(
  SELECT 1 FROM builderad_private.invocations i JOIN public.builderad_signed_context() actor ON actor.actor_user_id=i.actor_user_id
  WHERE i.backend_pid=pg_backend_pid() AND i.transaction_id=pg_current_xact_id() AND i.phase='authorized' AND i.action='legacy.entry'
   AND i.target_tuple->>'legacy_workspace_id'=p_workspace::text AND i.target_tuple->>'cohort_id'=c.cohort_id::text AND i.target_tuple->>'epoch'=c.epoch::text)
 THEN RAISE EXCEPTION 'UPGRADE_REQUIRED: qualified legacy entry unavailable' USING ERRCODE='42501'; END IF;
END $$;

CREATE FUNCTION builderad_v2_legacy_enter(p_workspace uuid) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE w bigint;actor bigint;c public.migration_cohort_control;
BEGIN
 SELECT ctx.actor_user_id INTO actor FROM public.builderad_signed_context() ctx WHERE ctx.phase='principal' AND ctx.workspace_id IS NULL;
 SELECT id INTO w FROM public.workspaces WHERE public_id=p_workspace;
 IF session_user<>'builderad_app' OR actor IS NULL OR w IS NULL OR NOT EXISTS(
  SELECT 1 FROM public.principal_lifecycles l JOIN public.workspace_memberships m ON m.user_id=l.user_id AND m.workspace_id=w AND m.status='active'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation AND g.scope_kind='workspace' AND g.workspace_id=w AND g.action='workspace.view'
  WHERE l.user_id=actor AND l.status='current' AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Signed legacy collaboration scope required' USING ERRCODE='42501'; END IF;
 SELECT control.* INTO c FROM public.migration_cohort_members m JOIN public.migration_cohort_control control ON control.cohort_id=m.cohort_id
  WHERE m.workspace_id=w AND m.kind='legacy_workspace' FOR SHARE OF control;
 IF NOT FOUND OR c.mode<>'LEGACY_ACTIVE' THEN RAISE EXCEPTION 'UPGRADE_REQUIRED' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id()) THEN
  PERFORM builderad_private.legacy_admitted(w);RETURN;
 END IF;
 PERFORM builderad_private.start_command(actor,NULL,uuidv7(),'legacy.entry',jsonb_build_object('legacy_workspace_id',w,'cohort_id',c.cohort_id,'epoch',c.epoch),jsonb_build_object('legacy_workspace_id',w,'cohort_id',c.cohort_id,'epoch',c.epoch));
END $$;

CREATE FUNCTION builderad_private.legacy_workspace_birth() RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;c public.migration_cohort_control;m public.migration_cohort_members;
BEGIN
 IF session_user NOT IN('builderad_app','builderad_owner') THEN RAISE EXCEPTION 'Qualified collaboration creator required' USING ERRCODE='42501'; END IF;
 SELECT ctx.actor_user_id INTO actor FROM public.builderad_signed_context() ctx WHERE ctx.phase='principal' AND ctx.workspace_id IS NULL;
 IF session_user='builderad_app' AND(actor IS NULL OR NOT EXISTS(
  SELECT 1 FROM public.principal_lifecycles l JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND g.scope_kind='platform' AND g.action='workspace.create' AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())))
 THEN RAISE EXCEPTION 'Explicit collaboration creation authority required' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id()) THEN RAISE EXCEPTION 'One collaboration genesis per owned transaction required' USING ERRCODE='42501'; END IF;
 c.cohort_id:=uuidv7();c.mode:='LEGACY_ACTIVE';c.epoch:=0;c.guard_schema_version:=1;c.manifest_version:=0;c.checkpoint_digest:=NULL;
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),uuidv7(),actor,coalesce((SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),0),NULL,
  'legacy.entry',jsonb_build_object('legacy_workspace_id',NEW.id,'cohort_id',c.cohort_id,'epoch',0),jsonb_build_object('legacy_workspace_id',NEW.id),
  builderad_private.authority_digest(jsonb_build_object('legacy_workspace_id',NEW.id)),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 PERFORM builderad_private.permit('public.migration_cohort_control','insert',NULL,to_jsonb(c));INSERT INTO public.migration_cohort_control SELECT(c).*;
 m.member_id:=uuidv7();m.cohort_id:=c.cohort_id;m.kind:='legacy_workspace';m.workspace_id:=NEW.id;m.business_id:=NULL;
 PERFORM builderad_private.permit('public.migration_cohort_members','insert',NULL,to_jsonb(m));INSERT INTO public.migration_cohort_members SELECT(m).*;
 RETURN NEW;
END $$;

CREATE FUNCTION builderad_private.legacy_effect_guard() RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE w bigint;
BEGIN
 IF TG_TABLE_NAME='workspaces' THEN
  -- Collaboration-only metadata stays with Workspace. Historical business facts cannot be rewritten after transition.
  IF TG_OP='UPDATE' AND to_jsonb(OLD)-ARRAY['business_type_id','business_context_version','current_intent']=to_jsonb(NEW)-ARRAY['business_type_id','business_context_version','current_intent'] THEN
   IF OLD.business_type_id IS NOT DISTINCT FROM NEW.business_type_id AND OLD.business_context_version=NEW.business_context_version AND OLD.current_intent IS NOT DISTINCT FROM NEW.current_intent THEN RETURN NEW; END IF;
  ELSIF TG_OP='UPDATE' AND OLD.business_type_id IS NOT DISTINCT FROM NEW.business_type_id AND OLD.business_context_version=NEW.business_context_version AND OLD.current_intent IS NOT DISTINCT FROM NEW.current_intent THEN RETURN NEW;
  END IF;
  w:=OLD.id;
 ELSE
  IF TG_TABLE_NAME IN('object_purposes','object_purpose_revisions','object_purpose_command_receipts') AND coalesce(to_jsonb(NEW)->>'business_id',to_jsonb(OLD)->>'business_id') IS NOT NULL THEN
   IF TG_OP='DELETE' THEN RETURN OLD;ELSE RETURN NEW;END IF;
  END IF;
  w:=CASE WHEN TG_OP='INSERT' THEN NEW.workspace_id ELSE OLD.workspace_id END;
  IF TG_OP='UPDATE' AND NEW.workspace_id IS DISTINCT FROM OLD.workspace_id THEN RAISE EXCEPTION 'Legacy scope cannot change identity' USING ERRCODE='42501'; END IF;
 END IF;
 PERFORM builderad_private.legacy_admitted(w);
 IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END $$;

-- Fixed signatures only. No identifier or SQL supplied by an application caller.
DO $install$
DECLARE signature text;definition text;w_parameter text;t text;
BEGIN
 FOREACH signature IN ARRAY ARRAY[
 'public.builderad_select_workspace_context(bigint)',
 'public.builderad_business_context_begin(bigint,text)',
 'public.builderad_business_context_write(bigint,text,bigint,text,bigint)',
 'public.builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint)',
 'public.builderad_business_target_markets_write(bigint,text,text[],bigint)',
 'public.builderad_advertiser_goals_write(bigint,text,jsonb,bigint)',
 'public.builderad_object_purpose_write(bigint,text,jsonb)'
 ] LOOP
  SELECT pg_get_functiondef(signature::regprocedure) INTO definition;
  w_parameter:=CASE WHEN signature LIKE '%select_workspace_context%' THEN 'p_workspace_id' WHEN signature LIKE '%object_purpose_write%' THEN 'w' ELSE 'p_workspace' END;
  definition:=regexp_replace(definition,'\mBEGIN\M','BEGIN PERFORM builderad_private.legacy_admitted('||w_parameter||');','i');
  EXECUTE definition;
 END LOOP;
 CREATE TRIGGER zz_legacy_workspace_birth AFTER INSERT ON public.workspaces FOR EACH ROW EXECUTE FUNCTION builderad_private.legacy_workspace_birth();
 CREATE TRIGGER zz_legacy_workspace_facts BEFORE UPDATE OR DELETE ON public.workspaces FOR EACH ROW EXECUTE FUNCTION builderad_private.legacy_effect_guard();
 FOREACH t IN ARRAY ARRAY['business_context_events','business_current_intent_events','business_target_markets','business_target_market_events','advertiser_goal_preferences','advertiser_goal_preference_events','object_purposes','object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('CREATE TRIGGER zz_legacy_effect BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.legacy_effect_guard()',t);
  EXECUTE format('CREATE TRIGGER zz_legacy_truncate BEFORE TRUNCATE ON public.%I FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()',t);
 END LOOP;
END $install$;

CREATE FUNCTION builderad_v2_legacy_context_grant(p_workspace bigint,p_session text) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE proof record;g public.security_action_grants;
BEGIN
 PERFORM builderad_private.legacy_admitted(p_workspace);
 SELECT * INTO proof FROM public.builderad_source_owner_evidence(p_workspace,p_session);
 IF proof.actor_id IS NULL THEN RAISE EXCEPTION 'Fresh responsible collaboration owner required' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('business.context.grant.'||proof.actor_id::text||'.'||proof.generation::text||'.'||p_workspace::text,0));
 SELECT * INTO g FROM public.security_action_grants WHERE workspace_id=p_workspace AND subject_user_id=proof.actor_id AND subject_generation=proof.generation AND action='business.context.manage' AND scope_kind='workspace' FOR UPDATE;
 IF FOUND THEN
  IF g.status<>'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp()) THEN RAISE EXCEPTION 'Legacy declaration authority revoked or expired' USING ERRCODE='42501'; END IF;
  RETURN g.id;
 END IF;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=proof.actor_id;g.subject_generation:=proof.generation;g.workspace_id:=p_workspace;
 g.granted_by:=proof.actor_id;g.source:='business_owner_request';g.scope_kind:='workspace';g.action:='business.context.manage';g.reason:='explicit_business_context_authority_request';
 g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.delegable:=false;
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 INSERT INTO public.security_audit_events(actor_user_id,subject_user_id,workspace_id,event,reason,new_status,new_generation,occurred_at)
 VALUES(proof.actor_id,proof.actor_id,p_workspace,'business_context_granted','explicit_business_context_authority_request','active',proof.generation,clock_timestamp());
 PERFORM public.builderad_source_owner_evidence(p_workspace,p_session);PERFORM builderad_private.legacy_admitted(p_workspace);
 RETURN g.id;
END $$;

CREATE FUNCTION builderad_private.legacy_creation_grant(w bigint,actor bigint,generation_value bigint) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE g public.security_action_grants;
BEGIN
 PERFORM builderad_private.legacy_admitted(w);
 IF NOT EXISTS(SELECT 1 FROM public.builderad_signed_context() c WHERE c.actor_user_id=actor AND c.phase='principal' AND c.workspace_id IS NULL)
 OR NOT EXISTS(SELECT 1 FROM public.workspace_ownerships o JOIN public.workspace_memberships m ON m.workspace_id=o.workspace_id AND m.user_id=o.responsible_owner_user_id
  JOIN public.principal_lifecycles l ON l.user_id=m.user_id AND l.status='current' AND l.generation=generation_value
  WHERE o.workspace_id=w AND o.responsible_owner_user_id=actor AND m.role='owner' AND m.status='active')
 THEN RAISE EXCEPTION 'Qualified legacy collaboration genesis required' USING ERRCODE='42501'; END IF;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=generation_value;g.workspace_id:=w;g.granted_by:=NULL;
 g.source:='system_workspace_creation';g.scope_kind:='workspace';g.action:='business.context.manage';g.reason:='initial_responsible_owner';
 g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.delegable:=false;
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 INSERT INTO public.security_audit_events(actor_user_id,subject_user_id,workspace_id,event,reason,new_status,new_generation,occurred_at)
 VALUES(actor,actor,w,'business_context_granted','initial_responsible_owner','active',generation_value,clock_timestamp());
END $$;
DO $adapt$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('public.builderad_create_workspace(bigint,text,text,text)'::regprocedure) INTO definition;
 definition:=replace(definition,'(VALUES (''workspace.view''), (''service.scope''), (''business.context.manage''))','(VALUES (''workspace.view''), (''service.scope''))');
 definition:=replace(definition,'RETURN v_public_id;', 'IF v_workspace_id IS NOT NULL THEN PERFORM builderad_private.legacy_creation_grant(v_workspace_id,p_actor_user_id,v_generation); END IF; RETURN v_public_id;');
 EXECUTE definition;
END $adapt$;

DO $seal$
DECLARE prior public.migration_cohort_control;c public.migration_cohort_control;
BEGIN
 IF EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND action='maintenance.legacy.registration' AND phase='authorized') THEN
  FOR prior IN SELECT * FROM public.migration_cohort_control WHERE mode='LEGACY_ACTIVE' AND guard_schema_version=0 ORDER BY cohort_id LOOP
   c:=prior;c.guard_schema_version:=1;
   PERFORM builderad_private.permit('public.migration_cohort_control','update',to_jsonb(prior),to_jsonb(c));
   UPDATE public.migration_cohort_control SET guard_schema_version=1 WHERE cohort_id=c.cohort_id;
  END LOOP;
  PERFORM builderad_private.finish_command();
 END IF;
END $seal$;
-- Retirement rejection precedes native fact/grant row locks. This does not enable a v2 source writer.
CREATE FUNCTION builderad_private.assert_native_source_write(p_source uuid) RETURNS void
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_source IS NULL THEN RAISE EXCEPTION 'Exact native source required' USING ERRCODE='22023'; END IF;
 -- A legacy provider may discover the exact source only after its native Workspace selector.
 -- It may join admission only without waiting. If adoption owns/waits for the mutex, abort the
 -- old transaction and release its K4 locks; never wait backwards from K4 for the adoption gate.
 IF NOT builderad_private.native_source_gate_held(p_source)
  AND NOT pg_try_advisory_xact_lock_shared(hashtextextended('builderad.source.admission.'||p_source,0))
 THEN RAISE EXCEPTION 'UPGRADE_REQUIRED: native source adoption is draining' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source AND adoption_state='v2_adopted')
 THEN RAISE EXCEPTION 'UPGRADE_REQUIRED: adopted source needs protected resource entry' USING ERRCODE='42501'; END IF;
END $$;
CREATE FUNCTION builderad_v2_legacy_source_grant_guard(p_source uuid) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE w bigint;
BEGIN
 IF session_user<>'builderad_app' THEN RAISE EXCEPTION 'Native runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT workspace_id INTO w FROM public.builderad_signed_context() WHERE phase='workspace';
 IF w IS NULL THEN RAISE EXCEPTION 'Current native Workspace context required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.legacy_admitted(w);PERFORM builderad_private.assert_native_source_write(p_source);
END $$;
