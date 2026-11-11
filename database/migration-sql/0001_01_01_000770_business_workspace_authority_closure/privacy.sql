-- Forward private-history exclusions: latest570 history/legacy readers and latest630 Purpose snapshot.
CREATE OR REPLACE FUNCTION builderad_v2_business_history(p_session text,p_business uuid,p_read bigint,p_kind text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;items jsonb;more boolean;cursor_value bigint;imported boolean;proof jsonb;
BEGIN
 IF p_kind IS NULL OR p_kind NOT IN('context','markets','intent','preferences') OR p_after IS NULL OR p_after<0 THEN
  RAISE EXCEPTION 'Typed history owner and cursor required' USING ERRCODE='22023';
 END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN
  RAISE EXCEPTION 'Explicit own Business read path required' USING ERRCODE='42501';
 END IF;
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 IF selection->>'realm_kind'='internal_team' OR proof#>>'{qualification,policy_id}' IN('business_declaration_team_admin_v1','internal_client_file_admin_v1') THEN RAISE EXCEPTION 'Current declaration team access does not release private history' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.principal(p_session);
 WITH transitions AS (
  SELECT event_id,before_context_version prior,after_context_version revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_context_transitions WHERE business_id=b AND p_kind='context' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_context_version,after_context_version,before_context_version,after_context_version,to_jsonb(before_codes),to_jsonb(after_codes),recorded_at
   FROM public.business_market_transitions WHERE business_id=b AND p_kind='markets' AND after_context_version>p_after
  UNION ALL
  SELECT event_id,before_revision,after_revision,before_context_version,after_context_version,before_value,after_value,recorded_at
   FROM public.business_intent_transitions WHERE business_id=b AND p_kind='intent' AND after_revision>p_after
  UNION ALL
  SELECT event_id,before_version,after_version,NULL::bigint,NULL::bigint,before_value,after_value,recorded_at
   FROM public.business_preference_transitions WHERE business_id=b AND p_kind='preferences' AND after_version>p_after
 ), bounded AS (SELECT * FROM transitions ORDER BY revision LIMIT 33), page AS (SELECT * FROM bounded ORDER BY revision LIMIT 32)
 SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('event_id',event_id,'before_version',prior::text,'version',revision::text,
  'before_context_version',before_context_version::text,'context_version',after_context_version::text,
  'before',before_value,'after',after_value,'recorded_at',recorded_at) ORDER BY revision) FROM page),'[]'::jsonb),
  (SELECT count(*)>32 FROM bounded),(SELECT max(revision) FROM page) INTO items,more,cursor_value;
 SELECT import_mapping_id IS NOT NULL INTO imported FROM public.business_context_heads WHERE business_id=b;
 -- No I/O that might wait is allowed after the final authority qualification.
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'kind',p_kind,'entries',items,'next_cursor',CASE WHEN more THEN cursor_value::text ELSE NULL END,
  'history_scope','native_business_transitions','has_imported_baseline',imported,
  'legacy_history','not_included','page_limit',32);
END $$;

CREATE OR REPLACE FUNCTION builderad_v2_business_legacy_history(p_session text,p_business uuid,p_read bigint,p_kind text,p_after uuid DEFAULT NULL) RETURNS jsonb
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
 IF read_proof#>>'{qualification,policy_id}' IN('business_declaration_team_admin_v1','internal_client_file_admin_v1') THEN RAISE EXCEPTION 'Current declaration team access does not release private legacy history' USING ERRCODE='42501'; END IF;
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

CREATE OR REPLACE FUNCTION builderad_v2_business_purpose_snapshot(p_session text,p_business uuid,p_read bigint,p_purpose uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;context_version bigint;heads jsonb;result jsonb;now_value timestamptz;proof jsonb;history_available boolean;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Exact own Business read path required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);PERFORM builderad_private.principal(p_session);
 history_available:=selection->>'realm_kind'<>'internal_team' AND proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'business_declaration_team_admin_v1' AND proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'internal_client_file_admin_v1';
 SELECT h.context_version INTO context_version FROM public.business_context_heads h WHERE business_id=b FOR SHARE;
 PERFORM 1 FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose) ORDER BY id FOR SHARE;
 IF (SELECT count(*) FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose))>64 THEN RAISE EXCEPTION 'Select exact Purpose; bounded projection unavailable' USING ERRCODE='22023'; END IF;
 IF p_purpose IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.object_purposes WHERE business_id=b AND id=p_purpose) THEN RAISE EXCEPTION 'Purpose unavailable in this Business' USING ERRCODE='42501'; END IF;
 now_value:=clock_timestamp();
 -- Current record authority never releases the temporal journal to the app.
 -- The last accepted transition starting by now cuts all earlier segments,
 -- including when it withdraws or has already reached its exclusive end.
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',h.id,'head_version',h.head_version,'history',
  CASE WHEN history_available THEN (SELECT coalesce(jsonb_agg((to_jsonb(r)-ARRAY['workspace_id','business_id','actor_user_id','actor_generation','ownership_version','business_authority_epoch','command_id']) ORDER BY r.version),'[]') FROM public.object_purpose_revisions r WHERE r.business_id=b AND r.purpose_id=h.id) ELSE '[]'::jsonb END,
  'current',CASE WHEN NOT history_available THEN (SELECT jsonb_build_object('purpose_id',r.purpose_id,'revision_id',r.revision_id,'version',r.version,
   'operation',r.operation,'effective_from',r.effective_from,'effective_until',r.effective_until,'accepted_at',r.accepted_at,'snapshot',r.snapshot)
   FROM (SELECT * FROM public.object_purpose_revisions WHERE business_id=b AND purpose_id=h.id AND accepted_at<=now_value AND effective_from<=now_value ORDER BY version DESC LIMIT 1) r
   WHERE r.operation<>'withdraw' AND(r.effective_until IS NULL OR now_value<r.effective_until)) ELSE NULL END) ORDER BY h.id),'[]') INTO heads
 FROM public.object_purposes h WHERE h.business_id=b AND(p_purpose IS NULL OR h.id=p_purpose);
 result:=jsonb_build_object('business_public_id',p_business,'heads',heads,'history_available',history_available,'context_version',context_version,'dtm_version',builderad_private.purpose_latest_markets(NULL,b),'scope','native_business_purposes_only');
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Complete Purpose projection exceeds bound' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN result||jsonb_build_object('read_at',now_value);
END $$;
