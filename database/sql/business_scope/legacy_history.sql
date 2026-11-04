-- An exact mapping qualifies a whole original declaration, not an inferred
-- current Business fact. Native UUIDs, versions, timestamps and bytes stay put.
CREATE FUNCTION builderad_private.legacy_history_origin(m public.legacy_scope_mappings) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp SET timezone='UTC' AS $$
DECLARE row_value jsonb;k jsonb;v text;source_cohort uuid;
BEGIN
 k:=m.origin_key::jsonb;
 CASE m.origin_table
 WHEN 'business_context_events' THEN SELECT to_jsonb(x),x.version::text INTO row_value,v FROM public.business_context_events x WHERE x.id=(k->>'id')::bigint;
 WHEN 'business_current_intent_events' THEN SELECT to_jsonb(x),x.after_version::text INTO row_value,v FROM public.business_current_intent_events x WHERE x.id=(k->>'id')::bigint;
 WHEN 'business_target_market_events' THEN SELECT to_jsonb(x),x.version::text INTO row_value,v FROM public.business_target_market_events x WHERE x.id=(k->>'id')::bigint;
 WHEN 'advertiser_goal_preference_events' THEN SELECT to_jsonb(x),x.version::text INTO row_value,v FROM public.advertiser_goal_preference_events x WHERE x.id=(k->>'id')::bigint;
 WHEN 'object_purpose_revisions' THEN SELECT to_jsonb(x)-ARRAY['business_id','business_authority_epoch'],x.version::text INTO row_value,v FROM public.object_purpose_revisions x WHERE x.revision_id=(k->>'revision_id')::uuid;
 ELSE RAISE EXCEPTION 'Exact native history owner required' USING ERRCODE='22023';
 END CASE;
 SELECT(r.result_ref->>'cohort_id')::uuid INTO source_cohort FROM public.business_command_receipts r
  WHERE r.action='maintenance.legacy.import' AND r.result_ref->>'manifest_ref'=m.import_manifest_id::text;
 IF row_value IS NULL OR source_cohort IS NULL OR v IS DISTINCT FROM m.native_version OR NOT EXISTS(
  SELECT 1 FROM public.migration_cohort_members WHERE cohort_id=source_cohort AND kind='legacy_workspace' AND workspace_id=(row_value->>'workspace_id')::bigint)
  OR builderad_private.digest('dependency-set:1',jsonb_build_object('kind','legacy-origin','table',m.origin_table,'key',k,'version',v,'row',row_value)) IS DISTINCT FROM m.origin_digest
 THEN RAISE EXCEPTION 'Committed exact native history reference required' USING ERRCODE='42501'; END IF;
 RETURN row_value;
END $$;

CREATE FUNCTION builderad_v2_business_legacy_history(p_session text,p_business uuid,p_read bigint,p_kind text,p_after uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;table_name text;candidate_ids uuid[];qual_ids uuid[];users bigint[];cohorts uuid[];cohort uuid;
 initial_rows jsonb;current_rows jsonb;items jsonb:='[]';accepted uuid[]:='{}';m public.legacy_scope_mappings;row_value jsonb;entry jsonb;
 scanned uuid;more boolean;source_cohort uuid;read_proof jsonb;
BEGIN
 table_name:=CASE p_kind WHEN 'context' THEN 'business_context_events' WHEN 'markets' THEN 'business_target_market_events'
 WHEN 'intent' THEN 'business_current_intent_events' WHEN 'preferences' THEN 'advertiser_goal_preference_events' WHEN 'object_purpose' THEN 'object_purpose_revisions' END;
 IF table_name IS NULL THEN RAISE EXCEPTION 'Typed native history owner required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 IF b IS NULL OR NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND scope_kind='business' AND action='business.context.read' AND status='active' AND revoked_at IS NULL)
 THEN RAISE EXCEPTION 'Explicit own Business read path required' USING ERRCODE='42501'; END IF;
 selection:=builderad_private.business_selection(p_read);
 IF selection->>'realm_kind'='internal_team' THEN RAISE EXCEPTION 'Internal file access does not release legacy private history' USING ERRCODE='42501'; END IF;
 SELECT coalesce(array_agg(mapping_id ORDER BY mapping_id),'{}'::uuid[]),coalesce(jsonb_agg(to_jsonb(x) ORDER BY mapping_id),'[]') INTO candidate_ids,initial_rows FROM(
  SELECT * FROM public.legacy_scope_mappings WHERE target_business_id=b AND status='qualified' AND origin_table=table_name AND(p_after IS NULL OR mapping_id>p_after) ORDER BY mapping_id LIMIT 32) x;
 SELECT array_agg(DISTINCT qualification_ref) INTO qual_ids FROM public.legacy_scope_mappings WHERE mapping_id=ANY(candidate_ids);
 SELECT array_agg(DISTINCT subject_user_id) INTO users FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qual_ids);
 SELECT array_agg(DISTINCT id ORDER BY id) INTO cohorts FROM(
  SELECT cohort_id id FROM public.migration_cohort_members WHERE business_id=b AND kind='business'
  UNION ALL SELECT(r.result_ref->>'cohort_id')::uuid FROM public.business_command_receipts r JOIN public.legacy_scope_mappings x ON x.import_manifest_id::text=r.result_ref->>'manifest_ref'
   WHERE x.mapping_id=ANY(candidate_ids) AND r.action='maintenance.legacy.import') x;
 -- Complete source/target K2 union precedes K3, then all mapping and Business
 -- principals/qualifications share the same ordered K4 lock mechanism.
 FOREACH cohort IN ARRAY coalesce(cohorts,'{}') LOOP
  PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=cohort AND mode='V2_ACTIVE' FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Historical cohort unavailable' USING ERRCODE='42501'; END IF;
 END LOOP;
 PERFORM builderad_private.lock_business(p_business);
 PERFORM builderad_private.lock_authority_footprint(actor,selection,p_session,coalesce(users,'{}'),'{}',coalesce(qual_ids,'{}'));
 IF EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qual_ids) AND NOT(subject_user_id=ANY(coalesce(users,'{}')))) THEN
  RAISE EXCEPTION 'Historical qualification principal changed' USING ERRCODE='40001';
 END IF;
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 PERFORM builderad_private.principal(p_session);
 SELECT coalesce(jsonb_agg(to_jsonb(x) ORDER BY mapping_id),'[]') INTO current_rows FROM public.legacy_scope_mappings x WHERE mapping_id=ANY(candidate_ids) AND target_business_id=b AND status='qualified';
 IF current_rows IS DISTINCT FROM initial_rows THEN RAISE EXCEPTION 'Prepared historical mappings changed' USING ERRCODE='40001'; END IF;
 FOR m IN SELECT * FROM public.legacy_scope_mappings WHERE mapping_id=ANY(candidate_ids) ORDER BY mapping_id LOOP
  scanned:=m.mapping_id;
  BEGIN
   PERFORM builderad_private.legacy_mapping_require(m);
   row_value:=builderad_private.legacy_history_origin(m);
   entry:=CASE p_kind
    WHEN 'context' THEN jsonb_build_object('before',jsonb_build_object('name',row_value->'prior_name','business_type_id',row_value->'prior_type_id'),'after',jsonb_build_object('name',row_value->'new_name','business_type_id',row_value->'new_type_id'))
    WHEN 'markets' THEN jsonb_build_object('before',row_value->'before_codes','after',row_value->'after_codes')
    WHEN 'object_purpose' THEN jsonb_build_object('purpose_id',row_value->'purpose_id','revision_id',row_value->'revision_id','operation',row_value->'operation',
     'prior_version',row_value->>'prior_version','supersedes_revision_id',row_value->'supersedes_revision_id','effective_from',row_value->'effective_from','effective_until',row_value->'effective_until',
     'snapshot',row_value->'snapshot','reason',row_value->'reason','affected_revision_ids',row_value->'affected_revision_ids','applicability','NOT_EVALUATED_HISTORICAL_DECLARATION')
    ELSE jsonb_build_object('before',row_value->'before_value','after',row_value->'after_value') END;
   entry:=entry||jsonb_build_object('mapping_ref',m.mapping_id,'native_key',m.origin_key,'native_version',m.native_version,
    'recorded_at',coalesce(row_value->'occurred_at',row_value->'accepted_at'),'original_digest',encode(m.origin_digest,'hex'));
   items:=items||jsonb_build_array(entry);accepted:=array_append(accepted,m.mapping_id);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 SELECT EXISTS(SELECT 1 FROM public.legacy_scope_mappings WHERE target_business_id=b AND status='qualified' AND origin_table=table_name AND mapping_id>scanned) INTO more;
 -- Qualification expiry after any possible wait fails closed for the whole
 -- prepared page. It never substitutes a different historical mapping.
 FOR m IN SELECT * FROM public.legacy_scope_mappings WHERE mapping_id=ANY(accepted) ORDER BY mapping_id LOOP PERFORM builderad_private.legacy_mapping_require(m); END LOOP;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'kind',p_kind,'entries',items,'next_cursor',CASE WHEN more THEN scanned ELSE NULL END,
  'history_scope','qualified_original_references','completeness','QUALIFIED_SUBSET_ONLY','order','mapping_reference_not_effective_timeline','page_limit',32);
END $$;
