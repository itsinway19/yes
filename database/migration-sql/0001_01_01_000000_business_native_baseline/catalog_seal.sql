CREATE FUNCTION builderad_private.catalog_digest() RETURNS bytea
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT sha256(convert_to(jsonb_build_object(
  'functions',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',p.proname,'args',pg_get_function_identity_arguments(p.oid),'owner',pg_get_userbyid(p.proowner),'acl',p.proacl::text,'definition',pg_get_functiondef(p.oid)) ORDER BY n.nspname,p.proname,pg_get_function_identity_arguments(p.oid)) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN('public','builderad_private') AND p.prokind='f' AND p.proname<>'installation_digest'),
  'relations',(SELECT jsonb_agg(jsonb_build_object('name',c.relname,'schema',n.nspname,'owner',pg_get_userbyid(c.relowner),'acl',c.relacl::text,'rls',c.relrowsecurity,'force',c.relforcerowsecurity) ORDER BY n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN('public','builderad_private') AND c.relkind IN('r','p','S')),
  'schemas',(SELECT jsonb_agg(jsonb_build_object('name',nspname,'owner',pg_get_userbyid(nspowner),'acl',nspacl::text) ORDER BY nspname) FROM pg_namespace WHERE nspname IN('public','builderad_private')),
  'triggers',(SELECT jsonb_agg(jsonb_build_object('table',c.relname,'schema',n.nspname,'name',t.tgname,'enabled',t.tgenabled,'definition',pg_get_triggerdef(t.oid)) ORDER BY n.nspname,c.relname,t.tgname) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE NOT t.tgisinternal AND n.nspname IN('public','builderad_private')),
  'policies',(SELECT jsonb_agg(to_jsonb(p) ORDER BY p.schemaname,p.tablename,p.policyname) FROM pg_policies p WHERE schemaname IN('public','builderad_private')),
  'views',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',c.relname,'owner',pg_get_userbyid(c.relowner),'acl',c.relacl::text,'options',c.reloptions,'definition',pg_get_viewdef(c.oid)) ORDER BY n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN('public','builderad_private') AND c.relkind IN('v','m')),
  'indexes',(SELECT jsonb_agg(to_jsonb(i) ORDER BY i.schemaname,i.indexname) FROM pg_indexes i WHERE i.schemaname IN('public','builderad_private')),
  'columns',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'table',c.relname,'name',a.attname,'type',format_type(a.atttypid,a.atttypmod),'not_null',a.attnotnull,'identity',a.attidentity,'generated',a.attgenerated,'default',pg_get_expr(d.adbin,d.adrelid)) ORDER BY n.nspname,c.relname,a.attnum) FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE n.nspname IN('public','builderad_private') AND c.relkind IN('r','p') AND a.attnum>0 AND NOT a.attisdropped),
  'constraints',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'table',c.relname,'name',k.conname,'validated',k.convalidated,'definition',pg_get_constraintdef(k.oid)) ORDER BY n.nspname,c.relname,k.conname) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN('public','builderad_private')),
  'roles',(SELECT jsonb_agg(jsonb_build_object('name',rolname,'login',rolcanlogin,'super',rolsuper,'bypass',rolbypassrls,'inherit',rolinherit,'create_role',rolcreaterole,'create_db',rolcreatedb,'config',rolconfig) ORDER BY rolname) FROM pg_roles WHERE rolname IN('builderad_owner','builderad_app','builderad_authority','builderad_scope_executor'))
 )::text,'UTF8'));
$$;


REVOKE ALL ON FUNCTION builderad_private.catalog_digest() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_private.catalog_digest() TO builderad_scope_executor;

-- The deployment owner has SET ROLE, but does not inherit the executor's
-- privileges. Apply final ACLs as the actual function owner after creating triggers
-- and policies that need the deployment owner's installation-time EXECUTE access.
SET LOCAL ROLE builderad_scope_executor;
GRANT ALL ON FUNCTION builderad_private.principal(p_session text, p_lock boolean) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_boundary_surface(p_session text, p_boundary uuid, p_read bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_data_use_binding(p_session text, p_business uuid, p_command uuid, p_scope uuid, p_effect text, p_expected bigint, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_finding_capture(p_session text, p_business uuid, p_command uuid, p_run uuid, p_read uuid, p_output uuid, p_process jsonb, p_context jsonb, p_continuation jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_customer_discovery_practice(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_expected bigint, p_ref jsonb, p_purpose text, p_relation jsonb, p_question jsonb, p_read jsonb, p_material jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_eligibility(p_session text, p_business uuid, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_enroll(p_session text, p_business uuid, p_command uuid, p_expected_basis bigint, p_confirm boolean, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_read(p_session text, p_business uuid, p_selection jsonb, p_declaration uuid, p_after bigint, p_cursor uuid) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_write(p_session text, p_business uuid, p_command uuid, p_declaration uuid, p_expected bigint, p_effect text, p_value jsonb, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_evidence_write(p_session text, p_command uuid, p_evidence uuid, p_scope uuid, p_expected bigint, p_payload jsonb, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_knowledge_capture(p_session text, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_knowledge_enroll(p_command uuid, p_source uuid, p_expected bigint, p_resource text, p_payload jsonb) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_market_knowledge_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_knowledge_publish(p_session text, p_command uuid, p_run uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb, p_ticket text) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_knowledge_withdraw(p_command uuid, p_scope uuid, p_grant bigint, p_expected bigint, p_reason text) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb, p_ticket text) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_processing_input(p_session text, p_business uuid, p_run uuid, p_selection jsonb, p_context_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_processing_prepare(p_session text, p_business uuid, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb, p_context_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION builderad_private.apply_object_purpose(b bigint, a bigint, c jsonb) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.purpose_context_exists(b bigint, v bigint) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.purpose_intent_at(b bigint, v bigint) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.purpose_latest_markets(b bigint) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.purpose_markets_at(b bigint, v bigint) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_business_purpose_enroll(p_session text, p_business uuid, p_command uuid, p_confirmed boolean, p_expected_previous bigint, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_purpose_snapshot(p_session text, p_business uuid, p_read bigint, p_purpose uuid) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_purpose_write(p_session text, p_business uuid, p_command jsonb, p_expected_context bigint, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION builderad_private.apply_native_source(p_actor bigint, p_generation bigint, p_business bigint, p_command uuid, p_source uuid, p_effect text, p_payload jsonb) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.native_source_append(p_relation regclass, p_row jsonb, p_adopted boolean) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.native_source_payload(p_effect text, p_payload jsonb) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.business_source_audit_delivery(p_session text, p_obligation uuid, p_ack uuid) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.business_source_grant_current(p_grant bigint, p_actor bigint, p_session text, p_fresh boolean) TO builderad_owner;
GRANT ALL ON FUNCTION builderad_private.business_source_require(p_action text, p_source uuid, p_resource text, p_operation text, p_purpose text, p_session text) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_business_source_bootstrap(p_session text, p_business uuid, p_command uuid, p_source uuid, p_payload jsonb, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_source_choices(p_session text, p_business uuid, p_read bigint, p_after bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_business_source_owner(p_session text, p_business uuid) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_data_license_journey(p_session text, p_business uuid, p_access bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_expenditure_summary(p_session text, p_business uuid, p_command uuid, p_summary uuid, p_input jsonb, p_license uuid, p_read uuid, p_confirmed boolean, p_until timestamp with time zone, p_consent jsonb, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_scoped_material_publish(p_session text, p_business uuid, p_command uuid, p_ref jsonb, p_dependencies jsonb, p_purpose text, p_recipient uuid, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_scoped_material_read(p_session text, p_business uuid, p_command uuid, p_refs jsonb, p_operation text, p_purpose text, p_recipient uuid, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_source_choices(p_session text, p_after bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_source_surface(p_session text, p_source uuid, p_access bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_boundary_choices(p_session text, p_after bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_beneficiary(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_recipient uuid, p_until timestamp with time zone, p_expected bigint, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_choices(p_session text, p_after bigint) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_data_use_license(p_session text, p_business uuid, p_command uuid, p_license uuid, p_effect text, p_expected bigint, p_declaration jsonb, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_inquiry_revoke(p_session text, p_business uuid, p_command uuid, p_action text, p_selection jsonb) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_market_admit(p_command uuid, p_action text, p_source uuid, p_expected bigint, p_payload jsonb) TO builderad_owner;
GRANT ALL ON FUNCTION public.builderad_v2_market_operations_read(p_session text, p_cursor uuid) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_practice_knowledge_options(p_session text, p_business uuid, p_access bigint, p_after integer) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_v2_resource_right_command(p_session text, p_command uuid, p_kind text, p_target uuid, p_effect text, p_payload jsonb, p_expected bigint, p_selection jsonb) TO builderad_app;
GRANT EXECUTE ON FUNCTION builderad_private.commercial_business_reader(bigint,bigint) TO builderad_owner;
GRANT EXECUTE ON FUNCTION builderad_private.admin_business_status(bigint,text,uuid,bigint,text) TO builderad_owner;
GRANT EXECUTE ON FUNCTION builderad_private.service_approval_proof(bigint,bigint,bigint) TO builderad_owner;
REVOKE ALL ON FUNCTION public.builderad_business_service_enroll(text,uuid,uuid,boolean,text,timestamptz,bigint,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_enroll(text,uuid,uuid,boolean,text,timestamptz,bigint,jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_business_service_approval(text,uuid,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_approval(text,uuid,bigint) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_business_service_consent(text,uuid,uuid,uuid,text,bigint,bigint,jsonb,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_service_consent(text,uuid,uuid,uuid,text,bigint,bigint,jsonb,boolean) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_business_source_effect(bigint,text,uuid,uuid,text,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_effect(bigint,text,uuid,uuid,text,jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_business_source_authority(bigint,text,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_business_source_authority(bigint,text,jsonb) TO builderad_app;

DO $executor_acl$
DECLARE routine record;
BEGIN
 FOR routine IN
  SELECT n.nspname,p.proname,pg_get_function_identity_arguments(p.oid) AS arguments
  FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
  WHERE p.proowner=current_user::regrole AND p.prokind='f'
    AND (n.nspname='builderad_private' OR (n.nspname='public' AND p.proname LIKE 'builderad_v2_%'))
 LOOP
  EXECUTE format('REVOKE EXECUTE ON FUNCTION %I.%I(%s) FROM PUBLIC',routine.nspname,routine.proname,routine.arguments);
  IF routine.nspname='public' AND routine.proname=ANY(ARRAY[
   'builderad_v2_business_agency_grant','builderad_v2_business_agency_mandates',
   'builderad_v2_business_agency_options','builderad_v2_business_agency_withdraw',
   'builderad_v2_business_catalog','builderad_v2_business_choices','builderad_v2_business_context_write',
   'builderad_v2_business_create_draft','builderad_v2_business_grant_command','builderad_v2_business_history',
   'builderad_v2_business_intent_write','builderad_v2_business_lifecycle_command','builderad_v2_business_markets_write',
   'builderad_v2_business_operating_mandate','builderad_v2_business_practice_write','builderad_v2_business_practices',
   'builderad_v2_business_preferences_write','builderad_v2_business_read','builderad_v2_business_surface',
   'builderad_v2_business_team_change','builderad_v2_business_team_enroll','builderad_v2_business_team_recipient',
   'builderad_v2_business_team_surface','builderad_v2_internal_team_command','builderad_v2_internal_team_surface'
  ]) THEN
   EXECUTE format('GRANT EXECUTE ON FUNCTION %I.%I(%s) TO builderad_app',routine.nspname,routine.proname,routine.arguments);
  END IF;
 END LOOP;
END $executor_acl$;
GRANT EXECUTE ON FUNCTION public.builderad_v2_cohort_transition(uuid,uuid,text,bigint,text,bytea) TO builderad_owner;
RESET ROLE;

-- Seal the actual installed catalog only after ownership, RLS, triggers and ACL installation.
-- Unqualified plugin/definer/ACL drift cannot be silently promoted into migration readiness.
DO $install$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.catalog_digest();
 RESET ROLE;
 -- Installer-owned immutable bytes only; SECURITY INVOKER conveys no deployment privilege.
 -- Do not regrant CREATE to the non-login executor after the permission boundary was closed.
 EXECUTE format('CREATE FUNCTION builderad_private.installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L', 'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
 REVOKE ALL ON FUNCTION builderad_private.installation_digest() FROM PUBLIC,builderad_app,builderad_authority;
 GRANT EXECUTE ON FUNCTION builderad_private.installation_digest() TO builderad_scope_executor;
END $install$;
