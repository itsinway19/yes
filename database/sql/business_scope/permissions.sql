-- Install all role boundaries atomically with the foundation migration.
DO $body$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY[
 'businesses','business_context_heads','business_current_intents','business_authority_fences',
 'migration_cohort_control','migration_cohort_members','authority_qualification_receipts','business_workspace_links',
 'business_command_receipts','business_goal_preferences','business_context_transitions','business_intent_transitions',
 'business_declared_markets','business_market_transitions','business_preference_transitions',
 'business_branches','client_data_boundaries','boundary_subjects','data_use_scopes','business_data_use_bindings','resource_right_fences',
 'legacy_scope_mappings','object_purpose_business_context_refs','material_use_dependencies','material_lineage_manifests'
 ] LOOP
  EXECUTE format('REVOKE ALL ON public.%I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY business_owner ON public.%I TO builderad_owner USING(true) WITH CHECK(true)',t);
  EXECUTE format('CREATE POLICY business_executor_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY business_executor_insert ON public.%I FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed(%L::regclass,''insert'',to_jsonb(%I.*)))',t,'public.'||t,t);
  EXECUTE format('GRANT SELECT,INSERT ON public.%I TO builderad_scope_executor',t);
  IF t NOT IN ('business_command_receipts','business_context_transitions','business_intent_transitions','business_market_transitions','business_preference_transitions','object_purpose_business_context_refs','material_use_dependencies','material_lineage_manifests') THEN
   EXECUTE format('CREATE POLICY business_executor_update ON public.%I FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed(%L::regclass,''update'',to_jsonb(%I.*)))',t,'public.'||t,t);
   EXECUTE format('GRANT UPDATE ON public.%I TO builderad_scope_executor',t);
  END IF;
  EXECUTE format('CREATE TRIGGER zz_business_effect BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect()',t);
  EXECUTE format('CREATE TRIGGER zz_business_truncate BEFORE TRUNCATE ON public.%I FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()',t);
 END LOOP;
 FOREACH t IN ARRAY ARRAY['invocations','command_permits'] LOOP
  EXECUTE format('REVOKE ALL ON builderad_private.%I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('ALTER TABLE builderad_private.%I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE builderad_private.%I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY private_owner ON builderad_private.%I TO builderad_owner USING(true) WITH CHECK(true)',t);
  EXECUTE format('CREATE POLICY private_executor ON builderad_private.%I TO builderad_scope_executor USING(true) WITH CHECK(true)',t);
  EXECUTE format('GRANT SELECT,INSERT,UPDATE,DELETE ON builderad_private.%I TO builderad_scope_executor',t);
 END LOOP;
END $body$;
GRANT USAGE ON SEQUENCE business_branches_id_seq,businesses_id_seq,security_action_grants_id_seq TO builderad_scope_executor;
GRANT SELECT,INSERT,UPDATE ON security_action_grants TO builderad_scope_executor;
GRANT SELECT ON principal_lifecycles,security_sessions,workspace_memberships,workspace_ownerships,business_types TO builderad_scope_executor;
GRANT SELECT(id,public_id,email_verified_at,scope_lock_marker) ON users TO builderad_scope_executor;
GRANT SELECT(id,public_id,status,name,slug,timezone,administration_revision,scope_lock_marker) ON workspaces TO builderad_scope_executor;
GRANT UPDATE(runtime_lock_marker) ON principal_lifecycles,security_sessions,security_action_grants TO builderad_scope_executor;
GRANT UPDATE(scope_lock_marker) ON users,workspaces,workspace_memberships,workspace_ownerships,business_types TO builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_signed_context(),builderad_current_intent_normalize(jsonb),builderad_advertiser_goals_normalize(jsonb) TO builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_signed_service_context() TO builderad_scope_executor;

DO $policy$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['users','principal_lifecycles','security_sessions','workspaces','workspace_memberships','workspace_ownerships','business_types'] LOOP
  EXECUTE format('CREATE POLICY scope_metadata_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY scope_metadata_lock ON public.%I FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(false)',t);
 END LOOP;
END $policy$;
ALTER TABLE security_action_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE security_action_grants FORCE ROW LEVEL SECURITY;
CREATE POLICY grant_owner ON security_action_grants TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY grant_executor_read ON security_action_grants FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY grant_executor_insert ON security_action_grants FOR INSERT TO builderad_scope_executor WITH CHECK((scope_kind IN('business','client_data','data_use') OR(scope_kind='source' AND EXISTS(SELECT 1 FROM resource_right_fences WHERE source_id=source_target_id AND kind='source' AND adoption_state='v2_adopted')) OR(scope_kind='workspace' AND action='business.context.manage')) AND builderad_private.effect_allowed('public.security_action_grants','insert',to_jsonb(security_action_grants.*)));
CREATE POLICY grant_executor_update ON security_action_grants FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(scope_kind IN('business','client_data','data_use','source') AND builderad_private.effect_allowed('public.security_action_grants','update',to_jsonb(security_action_grants.*)));
CREATE POLICY grant_authority_read ON security_action_grants FOR SELECT TO builderad_authority USING(scope_kind NOT IN('business','client_data','data_use'));
CREATE POLICY grant_authority_insert ON security_action_grants FOR INSERT TO builderad_authority WITH CHECK(scope_kind NOT IN('business','client_data','data_use'));
CREATE POLICY grant_authority_update ON security_action_grants FOR UPDATE TO builderad_authority USING(scope_kind NOT IN('business','client_data','data_use')) WITH CHECK(scope_kind NOT IN('business','client_data','data_use'));
CREATE POLICY grant_runtime_read ON security_action_grants FOR SELECT TO builderad_app USING(
 scope_kind NOT IN('business','client_data','data_use') AND EXISTS(
 SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
 WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation));
CREATE POLICY grant_runtime_lock ON security_action_grants FOR UPDATE TO builderad_app USING(
 scope_kind NOT IN('business','client_data','data_use') AND EXISTS(
 SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
 WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation))
 WITH CHECK(scope_kind NOT IN('business','client_data','data_use') AND EXISTS(
 SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
 WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation));
CREATE POLICY grant_authority_closed ON security_action_grants AS RESTRICTIVE TO builderad_authority USING(scope_kind NOT IN('business','client_data','data_use')) WITH CHECK(scope_kind NOT IN('business','client_data','data_use'));
CREATE POLICY grant_app_closed ON security_action_grants AS RESTRICTIVE TO builderad_app USING(scope_kind NOT IN('business','client_data','data_use')) WITH CHECK(scope_kind NOT IN('business','client_data','data_use'));
CREATE POLICY legacy_workspace_profile_retired ON workspaces AS RESTRICTIVE FOR SELECT TO builderad_app,builderad_authority USING(public.builderad_v2_legacy_workspace_visible(id));
DO $legacy_visibility$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['business_context_events','business_current_intent_events','business_target_markets','business_target_market_events','advertiser_goal_preferences','advertiser_goal_preference_events','object_purposes','object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('CREATE POLICY legacy_profile_retired ON public.%I AS RESTRICTIVE FOR SELECT TO builderad_app,builderad_authority USING(public.builderad_v2_legacy_workspace_visible(workspace_id))',t);
 END LOOP;
END $legacy_visibility$;
CREATE POLICY business_market_delete ON business_declared_markets FOR DELETE TO builderad_scope_executor USING(true);
GRANT DELETE ON business_declared_markets TO builderad_scope_executor;
GRANT SELECT ON country_references TO builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_current_intent_timezone_name_allowed(text) TO builderad_scope_executor;
GRANT SELECT(business_type_id,business_context_version,current_intent) ON workspaces TO builderad_scope_executor;
DO $legacy_read$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['business_context_events','business_current_intent_events','business_target_markets','business_target_market_events','advertiser_goal_preferences','advertiser_goal_preference_events','object_purpose_revisions'] LOOP
  EXECUTE format('GRANT SELECT ON public.%I TO builderad_scope_executor',t);
  EXECUTE format('CREATE POLICY scope_qualified_history ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
 END LOOP;
END $legacy_read$;
GRANT SELECT(id) ON jobs,failed_jobs TO builderad_scope_executor;
GRANT INSERT ON security_audit_events TO builderad_scope_executor;
GRANT USAGE ON SEQUENCE security_audit_events_id_seq TO builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_source_owner_evidence(bigint,text) TO builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_source_has(text,uuid,bigint,text,text,text) TO builderad_scope_executor;
GRANT SELECT ON source_registry,source_capabilities TO builderad_scope_executor;
CREATE POLICY source_executor_metadata_read ON source_registry FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY source_capability_executor_metadata_read ON source_capabilities FOR SELECT TO builderad_scope_executor USING(true);
GRANT UPDATE ON source_registry TO builderad_scope_executor;
CREATE POLICY source_executor_write ON source_registry FOR UPDATE TO builderad_scope_executor USING(true)
 WITH CHECK(EXISTS(SELECT 1 FROM resource_right_fences WHERE source_id=source_registry.id AND kind='source' AND adoption_state='v2_adopted') AND builderad_private.effect_allowed('public.source_registry','update',to_jsonb(source_registry.*)));
GRANT INSERT ON source_capabilities TO builderad_scope_executor;
CREATE POLICY source_capability_executor_insert ON source_capabilities FOR INSERT TO builderad_scope_executor
 WITH CHECK(EXISTS(SELECT 1 FROM resource_right_fences WHERE source_id=source_capabilities.source_id AND kind='source' AND adoption_state='v2_adopted') AND builderad_private.effect_allowed('public.source_capabilities','insert',to_jsonb(source_capabilities.*)));
DO $native_append$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['source_command_receipts','source_versions','source_audit_obligations','source_audit_delivery'] LOOP
  EXECUTE format('GRANT SELECT,INSERT ON public.%I TO builderad_scope_executor',t);
  EXECUTE format('CREATE POLICY scope_native_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY scope_native_append ON public.%I FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed(%L::regclass,''insert'',to_jsonb(%I.*)))',t,'public.'||t,t);
  EXECUTE format('CREATE TRIGGER zz_native_scope_append BEFORE INSERT ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect()',t);
  EXECUTE format('CREATE TRIGGER zz_native_scope_truncate BEFORE TRUNCATE ON public.%I FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()',t);
 END LOOP;
END $native_append$;
GRANT EXECUTE ON FUNCTION builderad_current_intent_validate_horizon(jsonb) TO builderad_scope_executor;
CREATE TRIGGER zz_business_grant_effect BEFORE INSERT OR UPDATE OR DELETE ON security_action_grants FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_business_grant_truncate BEFORE TRUNCATE ON security_action_grants FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();

-- Native-only sources retain their current writers. Adopted sources reject every stale direct/definer DML path.
CREATE TRIGGER zz_adopted_source_effect BEFORE INSERT OR UPDATE OR DELETE ON source_registry FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_adopted_source_capability_effect BEFORE INSERT OR UPDATE OR DELETE ON source_capabilities FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_adopted_source_truncate BEFORE TRUNCATE ON source_registry FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_adopted_source_capability_truncate BEFORE TRUNCATE ON source_capabilities FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();

GRANT EXECUTE ON FUNCTION public.builderad_object_purpose_keys(jsonb,text[]),public.builderad_object_purpose_text(jsonb,integer,boolean),public.builderad_object_purpose_instant(jsonb),public.builderad_object_purpose_normalize(jsonb) TO builderad_scope_executor;
DO $purpose_permissions$
DECLARE t text;
BEGIN
 FOREACH t IN ARRAY ARRAY['object_purposes','object_purpose_revisions','object_purpose_command_receipts'] LOOP
  EXECUTE format('GRANT SELECT,INSERT ON public.%I TO builderad_scope_executor',t);
  EXECUTE format('CREATE POLICY purpose_scope_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY purpose_scope_insert ON public.%I FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed(%L::regclass,''insert'',to_jsonb(%I.*)))',t,'public.'||t,t);
  EXECUTE format('CREATE TRIGGER zz_business_purpose_effect BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect()',t);
 END LOOP;
END $purpose_permissions$;
GRANT UPDATE(head_version) ON public.object_purposes TO builderad_scope_executor;
CREATE POLICY purpose_scope_lock ON public.object_purposes FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.object_purposes','update',to_jsonb(object_purposes.*)));

DO $body$
DECLARE f record;
BEGIN
 FOR f IN SELECT p.oid::regprocedure AS signature,n.nspname,p.proname
 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname='builderad_private' OR(n.nspname='public' AND p.proname LIKE 'builderad_v2_%')
 LOOP
  EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC,builderad_app,builderad_authority',f.signature);
  IF f.nspname='public' THEN
   IF f.proname='builderad_v2_native_source_authority_enter' THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO builderad_authority',f.signature);
   ELSIF f.proname IN('builderad_v2_cohort_transition','builderad_v2_legacy_inventory','builderad_v2_legacy_import','builderad_v2_legacy_resolve') THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO builderad_owner',f.signature);
   ELSE EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO builderad_app',f.signature); END IF;
  END IF;
  EXECUTE format('ALTER FUNCTION %s OWNER TO builderad_scope_executor',f.signature);
 END LOOP;
END $body$;
REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor;
-- ALTER OWNER rewrites the previous owner's ACL entry; grant the maintenance entry after ownership transfer.
SET LOCAL ROLE builderad_scope_executor;
GRANT EXECUTE ON FUNCTION public.builderad_v2_cohort_transition(uuid,uuid,text,bigint,text,uuid,bytea) TO builderad_owner;
GRANT EXECUTE ON FUNCTION public.builderad_v2_legacy_inventory(uuid),public.builderad_v2_legacy_import(uuid,uuid,bigint,uuid,jsonb,bytea),public.builderad_v2_legacy_resolve(uuid,uuid) TO builderad_owner;
GRANT EXECUTE ON FUNCTION builderad_private.legacy_admitted(bigint),builderad_private.legacy_creation_grant(bigint,bigint,bigint),builderad_private.assert_native_source_write(uuid) TO builderad_owner;
GRANT EXECUTE ON FUNCTION builderad_private.native_source_payload(text,jsonb),builderad_private.native_source_append(regclass,jsonb,boolean),builderad_private.apply_native_source(bigint,bigint,bigint,uuid,uuid,text,jsonb) TO builderad_owner;
GRANT EXECUTE ON FUNCTION builderad_private.apply_object_purpose(bigint,bigint,bigint,jsonb),builderad_private.purpose_context_exists(bigint,bigint,bigint),builderad_private.purpose_latest_markets(bigint,bigint),builderad_private.purpose_markets_at(bigint,bigint,bigint),builderad_private.purpose_intent_at(bigint,bigint,bigint) TO builderad_owner;
RESET ROLE;

-- Metadata-only native resolver access. No observation payload or encrypted progress privilege.
GRANT SELECT(namespace,workspace_id,source_id,provider,resource,operation,purpose,stream) ON provider_observation_streams TO builderad_scope_executor;
GRANT SELECT(namespace,generation,identity,content_hash) ON provider_observation_entries TO builderad_scope_executor;
GRANT SELECT(namespace,generation,command_id,coverage,provenance,recorded_at) ON provider_observation_runs TO builderad_scope_executor;
CREATE POLICY scope_observation_stream_metadata ON provider_observation_streams FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY scope_observation_entry_metadata ON provider_observation_entries FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY scope_observation_run_metadata ON provider_observation_runs FOR SELECT TO builderad_scope_executor USING(true);


GRANT SELECT(observation) ON provider_observation_entries TO builderad_scope_executor;
