-- Native history stays at its original owner. Import copies only an explicitly qualified baseline
-- into an independently created, empty Business; it never creates a Business or an authority grant.
CREATE FUNCTION builderad_private.legacy_environment_require() RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Deployment operator required' USING ERRCODE='42501'; END IF;
 -- G3-F qualification only. No production/environment G3-M qualification has been installed.
 IF current_database()<>'builderad_test_disposable' OR coalesce((SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()),'') NOT LIKE 'builderad-disposable-test:v1:%'
 THEN RAISE EXCEPTION 'ENVIRONMENT_MIGRATION_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
END $$;

CREATE FUNCTION builderad_private.legacy_catalog_digest() RETURNS bytea
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT sha256(convert_to(jsonb_build_object(
  'functions',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',p.proname,'args',pg_get_function_identity_arguments(p.oid),'owner',pg_get_userbyid(p.proowner),'acl',p.proacl::text,'definition',pg_get_functiondef(p.oid)) ORDER BY n.nspname,p.proname,pg_get_function_identity_arguments(p.oid)) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN('public','builderad_private') AND p.prokind='f' AND p.proname<>'legacy_installation_digest'),
  'relations',(SELECT jsonb_agg(jsonb_build_object('name',c.relname,'schema',n.nspname,'owner',pg_get_userbyid(c.relowner),'acl',c.relacl::text,'rls',c.relrowsecurity,'force',c.relforcerowsecurity) ORDER BY n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN('public','builderad_private') AND c.relkind IN('r','p','S')),
  'schemas',(SELECT jsonb_agg(jsonb_build_object('name',nspname,'owner',pg_get_userbyid(nspowner),'acl',nspacl::text) ORDER BY nspname) FROM pg_namespace WHERE nspname IN('public','builderad_private')),
  'triggers',(SELECT jsonb_agg(jsonb_build_object('table',c.relname,'schema',n.nspname,'name',t.tgname,'enabled',t.tgenabled,'definition',pg_get_triggerdef(t.oid)) ORDER BY n.nspname,c.relname,t.tgname) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE NOT t.tgisinternal AND n.nspname IN('public','builderad_private')),
  'policies',(SELECT jsonb_agg(to_jsonb(p) ORDER BY p.schemaname,p.tablename,p.policyname) FROM pg_policies p WHERE schemaname IN('public','builderad_private')),
  'columns',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'table',c.relname,'name',a.attname,'type',format_type(a.atttypid,a.atttypmod),'not_null',a.attnotnull,'identity',a.attidentity,'generated',a.attgenerated,'default',pg_get_expr(d.adbin,d.adrelid)) ORDER BY n.nspname,c.relname,a.attnum) FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum WHERE n.nspname IN('public','builderad_private') AND c.relkind IN('r','p') AND a.attnum>0 AND NOT a.attisdropped),
  'constraints',(SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'table',c.relname,'name',k.conname,'validated',k.convalidated,'definition',pg_get_constraintdef(k.oid)) ORDER BY n.nspname,c.relname,k.conname) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN('public','builderad_private')),
  'roles',(SELECT jsonb_agg(jsonb_build_object('name',rolname,'login',rolcanlogin,'super',rolsuper,'bypass',rolbypassrls,'inherit',rolinherit,'create_role',rolcreaterole,'create_db',rolcreatedb,'config',rolconfig) ORDER BY rolname) FROM pg_roles WHERE rolname IN('builderad_owner','builderad_app','builderad_authority','builderad_scope_executor'))
 )::text,'UTF8'));
$$;

CREATE FUNCTION builderad_private.legacy_native_rows(p_cohort uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp SET timezone='UTC' AS $$
DECLARE ws bigint[];rows_value jsonb;result jsonb:='[]';r record;k jsonb;v text;t text;row_value jsonb;
BEGIN
 SELECT array_agg(workspace_id ORDER BY workspace_id) INTO ws FROM public.migration_cohort_members WHERE cohort_id=p_cohort AND kind='legacy_workspace';
 IF ws IS NULL THEN RAISE EXCEPTION 'Exact legacy cohort required' USING ERRCODE='22023'; END IF;
 FOR r IN SELECT * FROM (
  SELECT 'workspaces'::text table_name,jsonb_build_object('id',w.id) key_value,w.business_context_version::text native_version,
   jsonb_build_object('id',w.id,'public_id',w.public_id,'name',coalesce((SELECT coalesce((SELECT e.before_value->>'name' FROM public.business_context_transitions e WHERE e.business_id=h.business_id ORDER BY after_context_version LIMIT 1),h.display_name)
    FROM public.legacy_scope_mappings m JOIN public.business_context_heads h ON h.import_mapping_id=m.mapping_id WHERE m.origin_table='workspaces' AND m.origin_key=jsonb_build_object('id',w.id)::text AND m.status='qualified'),w.name),
    'business_type_id',w.business_type_id,'business_context_version',w.business_context_version,'current_intent',w.current_intent) row_value FROM public.workspaces w WHERE id=ANY(ws)
  UNION ALL SELECT 'business_context_events',jsonb_build_object('id',x.id),x.version::text,to_jsonb(x) FROM public.business_context_events x WHERE workspace_id=ANY(ws)
  UNION ALL SELECT 'business_current_intent_events',jsonb_build_object('id',x.id),x.after_version::text,to_jsonb(x) FROM public.business_current_intent_events x WHERE workspace_id=ANY(ws)
  UNION ALL SELECT 'business_target_market_events',jsonb_build_object('id',x.id),x.version::text,to_jsonb(x) FROM public.business_target_market_events x WHERE workspace_id=ANY(ws)
  UNION ALL SELECT 'business_target_markets',jsonb_build_object('workspace_id',x.workspace_id,'country_code',x.country_code),w.business_context_version::text,to_jsonb(x) FROM public.business_target_markets x JOIN public.workspaces w ON w.id=x.workspace_id WHERE x.workspace_id=ANY(ws)
  UNION ALL SELECT 'advertiser_goal_preferences',jsonb_build_object('workspace_id',x.workspace_id),x.version::text,to_jsonb(x) FROM public.advertiser_goal_preferences x WHERE workspace_id=ANY(ws)
  UNION ALL SELECT 'advertiser_goal_preference_events',jsonb_build_object('id',x.id),x.version::text,to_jsonb(x) FROM public.advertiser_goal_preference_events x WHERE workspace_id=ANY(ws)
  UNION ALL SELECT 'object_purpose_revisions',jsonb_build_object('revision_id',x.revision_id),x.version::text,to_jsonb(x)-ARRAY['business_id','business_authority_epoch'] FROM public.object_purpose_revisions x WHERE workspace_id=ANY(ws)
  ) native_set ORDER BY table_name,key_value::text,native_version
 LOOP
  IF jsonb_array_length(result)>=4096 THEN RAISE EXCEPTION 'Legacy inventory row limit; no truncated qualification' USING ERRCODE='22023'; END IF;
  result:=result||jsonb_build_array(jsonb_build_object('origin_table',r.table_name,'origin_key',r.key_value::text,'native_version',r.native_version,
   'origin_digest',encode(builderad_private.digest('dependency-set:1',jsonb_build_object('kind','legacy-origin','table',r.table_name,'key',r.key_value,'version',r.native_version,'row',r.row_value)),'hex'),
   'origin_ref','builderad.legacy/'||r.table_name||'/'||r.key_value::text||'@'||r.native_version,'row',r.row_value));
 END LOOP;
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Legacy inventory byte limit; no partial result' USING ERRCODE='22023'; END IF;
 RETURN result;
END $$;

CREATE FUNCTION builderad_v2_legacy_inventory(p_cohort uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE c public.migration_cohort_control;rows_value jsonb;
BEGIN
 PERFORM builderad_private.legacy_environment_require();
 SELECT * INTO c FROM public.migration_cohort_control WHERE cohort_id=p_cohort FOR SHARE;
 IF NOT FOUND OR c.mode NOT IN('DRAINING','V2_ACTIVE','RECOVERY_HOLD') THEN RAISE EXCEPTION 'Frozen native cohort required' USING ERRCODE='42501'; END IF;
 IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'LEGACY_CATALOG_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
 rows_value:=builderad_private.legacy_native_rows(p_cohort);
 RETURN jsonb_build_object('cohort_id',p_cohort,'mode',c.mode,'epoch',c.epoch,'entries',rows_value,'native_digest',encode(builderad_private.digest('dependency-set:1',jsonb_build_object('kind','legacy-native-set','entries',rows_value)),'hex'));
END $$;

CREATE FUNCTION builderad_private.legacy_mapping_require(m public.legacy_scope_mappings) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE q public.authority_qualification_receipts;target uuid;required_action text;
BEGIN
 IF m.status<>'qualified' THEN RETURN; END IF;
 SELECT public_id INTO target FROM public.businesses WHERE id=m.target_business_id;
 SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=m.qualification_ref;
 required_action:=CASE m.origin_table WHEN 'workspaces' THEN 'legacy.context.import' WHEN 'business_target_markets' THEN 'legacy.markets.import' WHEN 'advertiser_goal_preferences' THEN 'legacy.preferences.import' ELSE 'legacy.history.map' END;
 IF q.qualification_id IS NULL OR q.status<>'active' OR q.target_kind<>'legacy_scope_mapping' OR q.target_ref IS DISTINCT FROM target
 OR NOT(required_action=ANY(q.allowed_actions)) OR q.evidence_digest IS DISTINCT FROM m.origin_digest
 OR q.evidence_ref IS DISTINCT FROM 'builderad.legacy/'||m.origin_table||'/'||m.origin_key||'@'||m.native_version
 OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=q.subject_user_id AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
 THEN RAISE EXCEPTION 'Exact current historic mapping qualification required' USING ERRCODE='42501'; END IF;
END $$;

CREATE FUNCTION builderad_private.legacy_manifest_check(p_cohort uuid,p_manifest uuid) RETURNS bytea
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE inv jsonb;x jsonb;m public.legacy_scope_mappings;count_rows integer;sealed jsonb:='[]';head_count integer:=0;
BEGIN
 PERFORM builderad_private.legacy_environment_require();
 IF p_manifest IS NULL OR builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'LEGACY_CATALOG_OR_MANIFEST_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.jobs) OR EXISTS(SELECT 1 FROM public.failed_jobs) THEN RAISE EXCEPTION 'LEGACY_WORK_RECONCILIATION_REQUIRED' USING ERRCODE='42501'; END IF;
 inv:=builderad_private.legacy_native_rows(p_cohort);
 SELECT count(*) INTO count_rows FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest;
 IF count_rows<>jsonb_array_length(inv) THEN RAISE EXCEPTION 'Incomplete native manifest' USING ERRCODE='42501'; END IF;
 FOR x IN SELECT value FROM jsonb_array_elements(inv) LOOP
  SELECT * INTO m FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest AND origin_table=x->>'origin_table' AND origin_key=x->>'origin_key' AND native_version=x->>'native_version';
  IF NOT FOUND OR m.origin_digest IS DISTINCT FROM decode(x->>'origin_digest','hex') THEN RAISE EXCEPTION 'Native manifest changed or ambiguous' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.legacy_mapping_require(m);
  IF m.origin_table='workspaces' AND m.status='qualified' THEN head_count:=head_count+1; END IF;
  sealed:=sealed||jsonb_build_array(to_jsonb(m));
 END LOOP;
 IF head_count=0 THEN RAISE EXCEPTION 'Qualified native baseline required' USING ERRCODE='42501'; END IF;
 RETURN builderad_private.digest('dependency-set:1',jsonb_build_object('kind','legacy-qualified-manifest','cohort',p_cohort,'manifest',p_manifest,'rows',sealed),'{"cohort":"U","manifest":"U"}');
END $$;

CREATE FUNCTION builderad_v2_legacy_import(p_command uuid,p_cohort uuid,p_epoch bigint,p_manifest uuid,p_entries jsonb,p_native_digest bytea) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp SET timezone='UTC' AS $$
DECLARE c public.migration_cohort_control;before_c public.migration_cohort_control;native_rows jsonb;entry jsonb;native jsonb;canonical jsonb;
 payload bytea;receipt public.business_command_receipts;m public.legacy_scope_mappings;q public.authority_qualification_receipts;
 b bigint;control_id uuid;targets bigint[];controls uuid[];qid uuid;uid bigint;seen text[]:='{}';ref text;result jsonb;seal bytea;
 old_h public.business_context_heads;h public.business_context_heads;old_i public.business_current_intents;i public.business_current_intents;
 pref public.business_goal_preferences;market public.business_declared_markets;head_m public.legacy_scope_mappings;head jsonb;v jsonb;current_m public.legacy_scope_mappings;
 purpose_ref public.object_purpose_business_context_refs;original_purpose public.object_purpose_revisions;
BEGIN
 PERFORM builderad_private.legacy_environment_require();
 IF p_command IS NULL OR p_manifest IS NULL OR p_epoch IS NULL OR p_epoch<0 OR p_native_digest IS NULL OR octet_length(p_native_digest)<>32
 OR p_entries IS NULL OR jsonb_typeof(p_entries)<>'array' OR jsonb_array_length(p_entries) NOT BETWEEN 1 AND 4096 OR octet_length(p_entries::text)>2097152
 THEN RAISE EXCEPTION 'Typed bounded import manifest required' USING ERRCODE='22023'; END IF;
 SELECT jsonb_agg(value ORDER BY value->>'origin_table',value->>'origin_key',value->>'native_version') INTO canonical FROM jsonb_array_elements(p_entries);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('action','maintenance.legacy.import','cohort',p_cohort,'epoch',p_epoch,'manifest',p_manifest,'entries',canonical,'native_digest',p_native_digest),'{"cohort":"U","epoch":"I","manifest":"U","native_digest":"X"}');
 SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF receipt.actor_user_id IS NOT NULL OR receipt.action<>'maintenance.legacy.import' OR receipt.payload_digest<>payload THEN RAISE EXCEPTION 'Import command identity conflict' USING ERRCODE='40001'; END IF;
  RETURN receipt.result_ref;
 END IF;
 SELECT array_agg(DISTINCT b0.id ORDER BY b0.id) INTO targets FROM jsonb_array_elements(canonical) e JOIN public.businesses b0 ON b0.public_id=CASE WHEN jsonb_typeof(e->'target_business_public_id')='string' THEN (e->>'target_business_public_id')::uuid ELSE NULL END WHERE e->>'status'='qualified';
 SELECT array_agg(DISTINCT cohort_id ORDER BY cohort_id) INTO controls FROM public.migration_cohort_members WHERE business_id=ANY(targets);
 controls:=array_append(coalesce(controls,'{}'),p_cohort);
 PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=ANY(controls) ORDER BY cohort_id FOR UPDATE;
 SELECT * INTO before_c FROM public.migration_cohort_control WHERE cohort_id=p_cohort;
 IF NOT FOUND OR before_c.mode<>'DRAINING' OR before_c.epoch<>p_epoch OR before_c.manifest_version<>0 THEN RAISE EXCEPTION 'Fresh drained cohort required; reconcile actual state' USING ERRCODE='40001'; END IF;
 IF EXISTS(SELECT 1 FROM public.migration_cohort_members member JOIN public.migration_cohort_control ctl ON ctl.cohort_id=member.cohort_id WHERE member.business_id=ANY(targets) AND ctl.mode<>'RECOVERY_HOLD') THEN RAISE EXCEPTION 'Explicit target Business hold required' USING ERRCODE='42501'; END IF;
 IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'LEGACY_CATALOG_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
 native_rows:=builderad_private.legacy_native_rows(p_cohort);
 IF builderad_private.digest('dependency-set:1',jsonb_build_object('kind','legacy-native-set','entries',native_rows))<>p_native_digest OR jsonb_array_length(native_rows)<>jsonb_array_length(canonical) THEN RAISE EXCEPTION 'Native inventory changed or incomplete' USING ERRCODE='40001'; END IF;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=ANY(targets) ORDER BY business_id FOR UPDATE;
 -- Freeze all reviewed qualification principals before qualification rows, in the existing K4 order.
 FOR uid IN SELECT DISTINCT q0.subject_user_id FROM jsonb_array_elements(canonical) e JOIN public.authority_qualification_receipts q0 ON q0.qualification_id=CASE WHEN jsonb_typeof(e->'qualification_ref')='string' THEN(e->>'qualification_ref')::uuid ELSE NULL END ORDER BY q0.subject_user_id LOOP
  PERFORM 1 FROM public.principal_lifecycles WHERE user_id=uid FOR SHARE;PERFORM 1 FROM public.users WHERE id=uid FOR SHARE;
 END LOOP;
 FOR qid IN SELECT DISTINCT(e->>'qualification_ref')::uuid FROM jsonb_array_elements(canonical) e WHERE e->'qualification_ref'<>'null'::jsonb ORDER BY 1 LOOP
  PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=qid FOR SHARE;
 END LOOP;
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.legacy.import',jsonb_build_object('cohort_id',p_cohort,'manifest_ref',p_manifest),jsonb_build_object('operator_role',session_user),builderad_private.authority_digest(jsonb_build_object('operator_role',session_user)),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 FOR entry IN SELECT value FROM jsonb_array_elements(canonical) LOOP
  IF jsonb_typeof(entry)<>'object' OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(entry) k) IS DISTINCT FROM ARRAY['native_version','origin_digest','origin_key','origin_table','qualification_ref','status','target_business_public_id']::text[]
   OR entry->>'status' NOT IN('qualified','unresolved','rejected') OR jsonb_typeof(entry->'status') IS DISTINCT FROM 'string'
  THEN RAISE EXCEPTION 'Exact reviewed native disposition required' USING ERRCODE='22023'; END IF;
  SELECT value INTO native FROM jsonb_array_elements(native_rows) WHERE value->>'origin_table'=entry->>'origin_table' AND value->>'origin_key'=entry->>'origin_key' AND value->>'native_version'=entry->>'native_version' AND value->>'origin_digest'=entry->>'origin_digest';
  ref:=(entry->>'origin_table')||'|'||(entry->>'origin_key')||'|'||(entry->>'native_version');
  IF native IS NULL OR ref=ANY(seen) THEN RAISE EXCEPTION 'Unknown, duplicate or changed native reference' USING ERRCODE='22023'; END IF;seen:=array_append(seen,ref);
  m.mapping_id:=uuidv7();m.origin_namespace:='builderad.legacy';m.origin_table:=entry->>'origin_table';m.origin_key:=entry->>'origin_key';m.native_version:=entry->>'native_version';m.origin_digest:=decode(entry->>'origin_digest','hex');m.import_manifest_id:=p_manifest;m.revision:=1;m.status:=entry->>'status';m.target_business_id:=NULL;m.qualification_ref:=NULL;
  IF m.status='qualified' THEN
   SELECT id INTO m.target_business_id FROM public.businesses WHERE public_id=(entry->>'target_business_public_id')::uuid;
   m.qualification_ref:=(entry->>'qualification_ref')::uuid;
   IF m.target_business_id IS NULL OR m.qualification_ref IS NULL THEN RAISE EXCEPTION 'Independent target and qualification required' USING ERRCODE='42501'; END IF;
   PERFORM builderad_private.legacy_mapping_require(m);
  ELSIF entry->'target_business_public_id' IS DISTINCT FROM 'null'::jsonb OR entry->'qualification_ref' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Unresolved history cannot confer a target or right' USING ERRCODE='22023'; END IF;
  PERFORM builderad_private.permit('public.legacy_scope_mappings','insert',NULL,to_jsonb(m));INSERT INTO public.legacy_scope_mappings SELECT(m).*;
 END LOOP;
 FOR head_m IN SELECT * FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest AND origin_table='workspaces' AND status='qualified' ORDER BY target_business_id LOOP
  b:=head_m.target_business_id;
  SELECT value->'row' INTO head FROM jsonb_array_elements(native_rows) WHERE value->>'origin_table'='workspaces' AND value->>'origin_key'=head_m.origin_key;
  SELECT * INTO old_h FROM public.business_context_heads WHERE business_id=b FOR UPDATE;
  SELECT * INTO old_i FROM public.business_current_intents WHERE business_id=b FOR UPDATE;
  IF old_h.business_id IS NULL OR old_i.business_id IS NULL OR old_h.context_version<>0 OR old_h.import_mapping_id IS NOT NULL OR old_i.intent_revision<>0 OR old_i.value IS NOT NULL
   OR EXISTS(SELECT 1 FROM public.business_goal_preferences WHERE business_id=b) OR EXISTS(SELECT 1 FROM public.business_declared_markets WHERE business_id=b)
   OR EXISTS(SELECT 1 FROM public.business_context_transitions WHERE business_id=b) OR EXISTS(SELECT 1 FROM public.business_intent_transitions WHERE business_id=b)
  THEN RAISE EXCEPTION 'Import cannot overwrite existing canonical facts' USING ERRCODE='42501'; END IF;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=head_m.qualification_ref;
  IF head->'current_intent'<>'null'::jsonb AND NOT('legacy.intent.import'=ANY(q.allowed_actions)) THEN RAISE EXCEPTION 'Legacy business_wide intent requires its own explicit qualification' USING ERRCODE='42501'; END IF;
  h:=old_h;h.display_name:=head->>'name';h.business_type_id:=(head->>'business_type_id')::bigint;h.import_mapping_id:=head_m.mapping_id;
  PERFORM builderad_private.permit('public.business_context_heads','update',to_jsonb(old_h),to_jsonb(h));
  UPDATE public.business_context_heads SET display_name=h.display_name,business_type_id=h.business_type_id,import_mapping_id=h.import_mapping_id WHERE business_id=b;
  i:=old_i;i.value:=nullif(head->'current_intent','null'::jsonb);
  PERFORM builderad_private.permit('public.business_current_intents','update',to_jsonb(old_i),to_jsonb(i));UPDATE public.business_current_intents SET value=i.value WHERE business_id=b;
  FOR native IN SELECT value FROM jsonb_array_elements(native_rows) WHERE value->>'origin_table' IN('business_target_markets','advertiser_goal_preferences') AND(value#>>'{row,workspace_id}')::bigint=(head->>'id')::bigint LOOP
   SELECT * INTO current_m FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest AND origin_table=native->>'origin_table' AND origin_key=native->>'origin_key' AND native_version=native->>'native_version';
   IF current_m.status<>'qualified' OR current_m.target_business_id<>b THEN RAISE EXCEPTION 'Incomplete or mixed current baseline cannot be imported' USING ERRCODE='42501'; END IF;
   IF current_m.origin_table='business_target_markets' THEN
    market.business_id:=b;market.country_code:=native#>>'{row,country_code}';PERFORM builderad_private.permit('public.business_declared_markets','insert',NULL,to_jsonb(market));INSERT INTO public.business_declared_markets SELECT(market).*;
   ELSE
    pref.business_id:=b;pref.preference_version:=1;pref.value:=native#>'{row,value}';pref.actor_id:=(native#>>'{row,actor_user_id}')::bigint;pref.updated_at:=(native#>>'{row,updated_at}')::timestamptz;
    PERFORM builderad_private.permit('public.business_goal_preferences','insert',NULL,to_jsonb(pref));INSERT INTO public.business_goal_preferences SELECT(pref).*;
   END IF;
  END LOOP;
 END LOOP;
 -- A separately qualified original Purpose revision may refer to the imported
 -- baseline, without becoming a new or currently applicable declaration. Both
 -- its own disposition and the exact original Workspace baseline must agree.
 FOR m IN SELECT * FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest AND origin_table='object_purpose_revisions' AND status='qualified' ORDER BY mapping_id LOOP
  SELECT * INTO STRICT original_purpose FROM public.object_purpose_revisions WHERE revision_id=((m.origin_key::jsonb)->>'revision_id')::uuid;
  IF EXISTS(SELECT 1 FROM public.business_context_heads h0 JOIN public.legacy_scope_mappings base ON base.mapping_id=h0.import_mapping_id
   WHERE h0.business_id=m.target_business_id AND base.import_manifest_id=p_manifest AND base.status='qualified'
    AND base.origin_table='workspaces' AND(base.origin_key::jsonb->>'id')::bigint=original_purpose.workspace_id) THEN
   purpose_ref.ref_id:=uuidv7();purpose_ref.native_revision_id:=original_purpose.revision_id;purpose_ref.business_id:=m.target_business_id;
   purpose_ref.context_version:=0;purpose_ref.reference_origin:='qualified_import';purpose_ref.mapping_id:=m.mapping_id;
   PERFORM builderad_private.permit('public.object_purpose_business_context_refs','insert',NULL,to_jsonb(purpose_ref));INSERT INTO public.object_purpose_business_context_refs SELECT(purpose_ref).*;
  END IF;
 END LOOP;
 seal:=builderad_private.legacy_manifest_check(p_cohort,p_manifest);
 c:=before_c;c.manifest_version:=1;c.checkpoint_digest:=seal;
 PERFORM builderad_private.permit('public.migration_cohort_control','update',to_jsonb(before_c),to_jsonb(c));UPDATE public.migration_cohort_control SET manifest_version=1,checkpoint_digest=seal WHERE cohort_id=p_cohort;
 -- A final check follows possible FK/receipt waits; expired qualifications cannot publish a successful import.
 result:=jsonb_build_object('cohort_id',p_cohort,'manifest_ref',p_manifest,'mapping_count',jsonb_array_length(canonical),'checkpoint',encode(seal,'hex'),'versions',jsonb_build_object('manifest',1));
 receipt.command_id:=p_command;receipt.business_id:=NULL;receipt.actor_user_id:=NULL;receipt.generation:=0;receipt.action:='maintenance.legacy.import';receipt.payload_digest:=payload;receipt.state:='committed';receipt.result_ref:=result;receipt.returned_versions:=result->'versions';receipt.recorded_at:=clock_timestamp();receipt.authority_selection_digest:=builderad_private.authority_digest(jsonb_build_object('operator_role',session_user));receipt.dependency_digest:=builderad_private.digest('dependency-set:1','[]'::jsonb);
 PERFORM builderad_private.permit('public.business_command_receipts','insert',NULL,to_jsonb(receipt));INSERT INTO public.business_command_receipts SELECT(receipt).*;
 IF builderad_private.legacy_manifest_check(p_cohort,p_manifest)<>seal THEN RAISE EXCEPTION 'Imported manifest changed before commit' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

CREATE FUNCTION builderad_private.legacy_related_cohorts(p_cohort uuid) RETURNS uuid[]
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 WITH imports AS(
  SELECT result_ref FROM public.business_command_receipts r WHERE action='maintenance.legacy.import' AND
   (result_ref->>'cohort_id'=p_cohort::text OR EXISTS(SELECT 1 FROM public.legacy_scope_mappings m JOIN public.migration_cohort_members member ON member.business_id=m.target_business_id
    WHERE m.import_manifest_id=(r.result_ref->>'manifest_ref')::uuid AND member.cohort_id=p_cohort))
 ),related AS(
  SELECT p_cohort id UNION SELECT(result_ref->>'cohort_id')::uuid FROM imports
  UNION SELECT member.cohort_id FROM imports JOIN public.legacy_scope_mappings m ON m.import_manifest_id=(imports.result_ref->>'manifest_ref')::uuid JOIN public.migration_cohort_members member ON member.business_id=m.target_business_id
 ) SELECT array_agg(id ORDER BY id) FROM related;
$$;

CREATE FUNCTION builderad_private.legacy_activation_require(p_cohort uuid,p_manifest uuid,p_checkpoint bytea) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.business_command_receipts;manifest_ids uuid[];uid bigint;qid uuid;source_cohort uuid;seal bytea;
BEGIN
 SELECT array_agg(DISTINCT(r0.result_ref->>'manifest_ref')::uuid) INTO manifest_ids FROM public.business_command_receipts r0 WHERE action='maintenance.legacy.import' AND
  (result_ref->>'cohort_id'=p_cohort::text OR EXISTS(SELECT 1 FROM public.legacy_scope_mappings m JOIN public.migration_cohort_members member ON member.business_id=m.target_business_id
   WHERE m.import_manifest_id=(r0.result_ref->>'manifest_ref')::uuid AND member.cohort_id=p_cohort));
 IF manifest_ids IS NULL THEN
  IF EXISTS(SELECT 1 FROM public.migration_cohort_members WHERE cohort_id=p_cohort AND kind='legacy_workspace') THEN RAISE EXCEPTION 'LEGACY_GUARD_AND_IMPORT_QUALIFICATION_REQUIRED' USING ERRCODE='42501'; END IF;
  RETURN;
 END IF;
 IF cardinality(manifest_ids)<>1 OR p_manifest IS DISTINCT FROM manifest_ids[1] THEN RAISE EXCEPTION 'Exact single imported manifest required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.business_command_receipts WHERE action='maintenance.legacy.import' AND result_ref->>'manifest_ref'=p_manifest::text;
 source_cohort:=(r.result_ref->>'cohort_id')::uuid;
 IF source_cohort<>p_cohort AND (SELECT mode FROM public.migration_cohort_control WHERE cohort_id=source_cohort)<>'V2_ACTIVE' THEN RAISE EXCEPTION 'Original writer must retire before target activation' USING ERRCODE='42501'; END IF;
 FOR uid IN SELECT DISTINCT q.subject_user_id FROM public.legacy_scope_mappings m JOIN public.authority_qualification_receipts q ON q.qualification_id=m.qualification_ref WHERE import_manifest_id=p_manifest ORDER BY q.subject_user_id LOOP
  PERFORM 1 FROM public.principal_lifecycles WHERE user_id=uid FOR SHARE;PERFORM 1 FROM public.users WHERE id=uid FOR SHARE;
 END LOOP;
 FOR qid IN SELECT DISTINCT qualification_ref FROM public.legacy_scope_mappings WHERE import_manifest_id=p_manifest AND qualification_ref IS NOT NULL ORDER BY qualification_ref LOOP
  PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=qid FOR SHARE;
 END LOOP;
 seal:=builderad_private.legacy_manifest_check(source_cohort,p_manifest);
 IF seal IS DISTINCT FROM p_checkpoint OR seal IS DISTINCT FROM decode(r.result_ref->>'checkpoint','hex') THEN RAISE EXCEPTION 'Qualified import checkpoint mismatch' USING ERRCODE='40001'; END IF;
END $$;

CREATE FUNCTION builderad_v2_legacy_resolve(p_business uuid,p_mapping uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE m public.legacy_scope_mappings;r public.business_command_receipts;source_cohort uuid;rows_value jsonb;x jsonb;
BEGIN
 PERFORM builderad_private.legacy_environment_require();
 SELECT m0.* INTO m FROM public.legacy_scope_mappings m0 JOIN public.businesses b ON b.id=m0.target_business_id WHERE m0.mapping_id=p_mapping AND b.public_id=p_business AND m0.status='qualified';
 IF NOT FOUND THEN RAISE EXCEPTION 'Qualified exact historic target required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.legacy_mapping_require(m);
 SELECT * INTO r FROM public.business_command_receipts WHERE action='maintenance.legacy.import' AND result_ref->>'manifest_ref'=m.import_manifest_id::text;
 IF NOT FOUND THEN RAISE EXCEPTION 'Committed qualified import required' USING ERRCODE='42501'; END IF;
 source_cohort:=(r.result_ref->>'cohort_id')::uuid;
 PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=ANY(builderad_private.legacy_related_cohorts(source_cohort)) ORDER BY cohort_id FOR SHARE;
 SELECT * INTO m FROM public.legacy_scope_mappings WHERE mapping_id=p_mapping AND target_business_id=(SELECT id FROM public.businesses WHERE public_id=p_business) AND status='qualified';
 IF NOT FOUND THEN RAISE EXCEPTION 'Historic mapping changed after wait' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=(SELECT subject_user_id FROM public.authority_qualification_receipts WHERE qualification_id=m.qualification_ref) FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=(SELECT subject_user_id FROM public.authority_qualification_receipts WHERE qualification_id=m.qualification_ref) FOR SHARE;
 PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=m.qualification_ref FOR SHARE;
 PERFORM builderad_private.legacy_mapping_require(m);
 IF (SELECT mode FROM public.migration_cohort_control WHERE cohort_id=source_cohort)<>'V2_ACTIVE' THEN RAISE EXCEPTION 'Historic source cohort unavailable' USING ERRCODE='42501'; END IF;
 rows_value:=builderad_private.legacy_native_rows(source_cohort);
 SELECT value INTO x FROM jsonb_array_elements(rows_value) WHERE value->>'origin_table'=m.origin_table AND value->>'origin_key'=m.origin_key AND value->>'native_version'=m.native_version AND decode(value->>'origin_digest','hex')=m.origin_digest;
 IF x IS NULL THEN RAISE EXCEPTION 'Native historic reference changed' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('business_public_id',p_business,'mapping_id',m.mapping_id,'origin',x,'interpretation','qualified_historical_reference_not_current_authority');
END $$;
