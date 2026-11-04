-- Preserve commercial origin while Workspace's organizational label evolves independently.
CREATE OR REPLACE FUNCTION builderad_private.legacy_native_rows(p_cohort uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp SET timezone='UTC' AS $$
DECLARE ws bigint[];rows_value jsonb;result jsonb:='[]';r record;k jsonb;v text;t text;row_value jsonb;
BEGIN
 SELECT array_agg(workspace_id ORDER BY workspace_id) INTO ws FROM public.migration_cohort_members WHERE cohort_id=p_cohort AND kind='legacy_workspace';
 IF ws IS NULL THEN RAISE EXCEPTION 'Exact legacy cohort required' USING ERRCODE='22023'; END IF;
 FOR r IN SELECT * FROM (
  SELECT 'workspaces'::text table_name,jsonb_build_object('id',w.id) key_value,w.business_context_version::text native_version,
   jsonb_build_object('id',w.id,'public_id',w.public_id,'name',coalesce((SELECT coalesce((SELECT e.before_value->>'name' FROM public.business_context_transitions e WHERE e.business_id=h.business_id ORDER BY after_context_version LIMIT 1),h.display_name)
    FROM public.legacy_scope_mappings m JOIN public.business_context_heads h ON h.import_mapping_id=m.mapping_id WHERE m.origin_table='workspaces' AND m.origin_key=jsonb_build_object('id',w.id)::text AND m.status='qualified'),(SELECT preserved.name FROM public.legacy_workspace_commercial_names preserved WHERE preserved.workspace_id=w.id),w.name),
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
