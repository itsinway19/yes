-- Clean Business product: no Workspace discovery or organizational permission projection. Existing Business/data-boundary authority keeps its canonical writer.
CREATE OR REPLACE FUNCTION builderad_v2_business_choices(p_session text,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; g record; b record; c uuid; selection jsonb; footprint jsonb; proof jsonb; row_value jsonb;
 candidates bigint[]:='{}'; business_ids bigint[]:='{}'; requirements jsonb:='[]'; items jsonb:='[]'; proofs jsonb:='[]'; scanned bigint:=p_after; next_cursor bigint; mode text;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded explicit cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 FOR g IN SELECT x.* FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation
 WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.delegation_realm_kind='direct' AND x.delegation_workspace_id IS NULL AND x.action='business.context.read' AND x.status='active' AND x.revoked_at IS NULL
 AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp()) AND x.id>p_after ORDER BY x.id LIMIT 32 LOOP
  scanned:=g.id; selection:=builderad_private.business_selection(g.id);
  BEGIN
   footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM g.business_id AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN
    RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501';
   END IF;
   candidates:=array_append(candidates,g.id);business_ids:=array_append(business_ids,g.business_id);
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 FOR c IN SELECT DISTINCT cohort_id FROM public.migration_cohort_members WHERE business_id=ANY(business_ids) AND kind='business' ORDER BY cohort_id LOOP
  PERFORM 1 FROM public.migration_cohort_control WHERE cohort_id=c FOR SHARE;
 END LOOP;
 PERFORM 1 FROM public.business_authority_fences WHERE business_id=ANY(business_ids) ORDER BY business_id FOR SHARE;
 IF cardinality(candidates)>0 THEN
  PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);
 END IF;
 FOR g IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidates) ORDER BY id LOOP
  BEGIN
   SELECT x.* INTO STRICT b FROM public.businesses x WHERE id=g.business_id;
   SELECT x.mode INTO mode FROM public.migration_cohort_control x JOIN public.migration_cohort_members m ON m.cohort_id=x.cohort_id WHERE m.business_id=b.id AND m.kind='business';
   IF mode IS DISTINCT FROM 'V2_ACTIVE' THEN RAISE EXCEPTION 'Business not active in this foundation' USING ERRCODE='42501'; END IF;
   selection:=builderad_private.business_selection(g.id);
   proof:=builderad_private.require_business(b.id,actor,'business.context.read',selection,p_session);
   SELECT jsonb_build_object('business_public_id',b.public_id,'name',h.display_name,'state',b.lifecycle,'read_grant_id',g.id::text,
    'realm_kind',selection->'realm_kind','realm_workspace_public_id',selection->'realm_workspace_public_id','agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=g.qualification_id)) INTO row_value FROM public.business_context_heads h WHERE h.business_id=b.id;
   items:=items||jsonb_build_array(row_value);proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 -- Expiry is checked again after the last potentially waiting authority read, without substituting another path.
 FOR row_value IN SELECT value FROM jsonb_array_elements(items) LOOP
  PERFORM builderad_private.require_business((SELECT id FROM public.businesses WHERE public_id=(row_value->>'business_public_id')::uuid),actor,'business.context.read',builderad_private.business_selection((row_value->>'read_grant_id')::bigint),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x JOIN public.principal_lifecycles l ON l.user_id=x.subject_user_id AND l.generation=x.subject_generation WHERE x.subject_user_id=actor AND x.scope_kind='business' AND x.delegation_realm_kind='direct' AND x.delegation_workspace_id IS NULL AND x.action='business.context.read' AND x.id>scanned AND x.status='active' AND x.revoked_at IS NULL AND(x.expires_at IS NULL OR x.expires_at>clock_timestamp())) THEN next_cursor:=scanned; END IF;
 RETURN jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'items',items,'next_cursor',next_cursor::text,'scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('items',items,'proofs',proofs)::text,'UTF8')),'hex')));
END $$;

CREATE OR REPLACE FUNCTION builderad_v2_business_surface(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; b bigint; read_selection jsonb; selection jsonb; proof jsonb; read_proof jsonb; options jsonb:='[]'; proofs jsonb:='[]'; requirements jsonb;
 g record; footprint jsonb; result jsonb;fact_options jsonb:='{"markets":[],"intent":[],"preferences":[],"purpose":[]}';fact_key text;write_action text;
 binding_options jsonb:='[]';
 delegation_options jsonb:='[]';issued_grants jsonb:='[]';expiry timestamptz;parent_row public.security_action_grants;more_grants boolean:=false;
 bindings jsonb:='[]';bindings_more boolean:=false;archive_options jsonb:='[]';
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 read_selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Explicit own read path required' USING ERRCODE='42501'; END IF;
 requirements:=read_selection->'requirements';
 FOR g IN SELECT * FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND id<>p_read AND scope_kind='business' AND status='active' AND revoked_at IS NULL
 AND delegation_realm_kind=read_selection->>'realm_kind' AND delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid) ORDER BY id LIMIT 31 LOOP
  BEGIN
   selection:=builderad_private.business_selection(g.id);footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 footprint:=builderad_private.authority_footprint(actor,read_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b AND NOT x.business_id=ANY(ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')))) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  BEGIN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   proof:=builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
   IF write_action='business.draft.archive' AND EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND origin_kind='self_declared_draft' AND lifecycle='declared_draft') THEN
    -- Discover the existing exact action, never infer archive from context editing.
    archive_options:=archive_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.context.manage' THEN options:=options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.link.manage' THEN
    binding_options:=binding_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action IN('business.markets.declare','business.intent.declare','business.preferences.manage','business.purpose.declare') THEN
    fact_key:=CASE write_action WHEN 'business.markets.declare' THEN 'markets' WHEN 'business.intent.declare' THEN 'intent' WHEN 'business.preferences.manage' THEN 'preferences' WHEN 'business.purpose.declare' THEN 'purpose' END;
    fact_options:=jsonb_set(fact_options,ARRAY[fact_key],(fact_options->fact_key)||jsonb_build_array(jsonb_build_object('grant_id',g.id::text)));
   END IF;
   SELECT * INTO STRICT parent_row FROM public.security_action_grants WHERE id=g.id;
   -- Only the holder's currently qualified paths and their immediate issued children.
   -- This is not a user directory or a grant to read another customer's delegation graph.
   IF parent_row.delegable OR EXISTS(SELECT 1 FROM public.security_action_grants child WHERE child.parent_grant_id=g.id AND child.business_id=b) THEN
    SELECT min(value::timestamptz) INTO expiry FROM(
     SELECT x->>'expires_at' value FROM jsonb_array_elements(proof->'grants') x
     UNION ALL SELECT x->>'valid_until' FROM jsonb_array_elements(proof->'qualifications') x
    ) limits WHERE value IS NOT NULL;
    delegation_options:=delegation_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text,'action',write_action,
     'agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=parent_row.qualification_id),'can_issue',parent_row.delegable AND jsonb_array_length(proof->'grants')<16 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qa(value) WHERE qa.value->>'agency_business_id' IS NOT NULL),
     'can_delegate_onward',NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qa(value) WHERE qa.value->>'agency_business_id' IS NOT NULL) AND proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1','business_operating_mandate_v1'),
     'can_choose_agency',false,
     'max_expires_at',expiry,'chain_depth',jsonb_array_length(proof->'grants')));
   END IF;
   proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 SELECT count(*)>64 INTO more_grants FROM(SELECT 1 FROM public.security_action_grants child
 WHERE child.scope_kind='business' AND child.business_id=b AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',child.id::text,'parent_grant_id',child.parent_grant_id::text,'recipient_public_id',u.public_id,
  'action',child.action,'recorded_status',child.status,'revision',child.version,'expires_at',child.expires_at,'delegable',child.delegable,
  'realm_kind',child.delegation_realm_kind,'realm_workspace_public_id',w.public_id,'agency_business_public_id',(SELECT ab.public_id FROM public.authority_qualification_receipts aq JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE aq.qualification_id=child.qualification_id)) ORDER BY child.id DESC),'[]'::jsonb) INTO issued_grants
 FROM(SELECT * FROM public.security_action_grants child WHERE child.scope_kind='business' AND child.business_id=b
  AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) ORDER BY child.id DESC LIMIT 64) child
 JOIN public.users u ON u.id=child.subject_user_id LEFT JOIN public.workspaces w ON w.id=child.delegation_workspace_id;
 IF jsonb_array_length(binding_options)>0 THEN
  WITH latest AS MATERIALIZED(SELECT DISTINCT ON(data_use_scope_id) * FROM public.business_data_use_bindings WHERE business_id=b ORDER BY data_use_scope_id,revision DESC,binding_id DESC),
  bounded AS MATERIALIZED(SELECT *,row_number() OVER(ORDER BY binding_id DESC) position FROM latest ORDER BY binding_id DESC LIMIT 65)
  SELECT coalesce(jsonb_agg(jsonb_build_object('binding_id',binding_id,'scope_id',data_use_scope_id,'state',status,'revision',revision) ORDER BY binding_id DESC) FILTER(WHERE position<=64),'[]'),count(*)>64
   INTO bindings,bindings_more FROM bounded;

 END IF;
 result:=jsonb_build_object('business',public.builderad_v2_business_read(p_session,p_business,read_selection),'authority_selection',read_selection,'agency_business_public_id',(SELECT ab.public_id FROM public.security_action_grants rg JOIN public.authority_qualification_receipts aq ON aq.qualification_id=rg.qualification_id JOIN public.businesses ab ON ab.id=aq.agency_business_id WHERE rg.id=p_read),
 'private_history_available',read_selection->>'realm_kind'<>'internal_team' AND read_proof#>>'{qualification,policy_id}'<>'business_declaration_team_admin_v1',
 'agency_task',jsonb_build_object(
  'can_mandate',actor=(SELECT created_by FROM public.businesses WHERE id=b)
   AND EXISTS(SELECT 1 FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    WHERE rg.id=p_read AND rq.policy_id IN('self_declared_draft','business_declaration_team_admin_v1'))
   AND EXISTS(SELECT 1 FROM jsonb_array_elements(delegation_options) opt(value)
    JOIN public.security_action_grants og ON og.id=(opt.value->>'grant_id')::bigint
    JOIN public.authority_qualification_receipts oq ON oq.qualification_id=og.qualification_id
    WHERE opt.value->>'can_issue'='true' AND oq.policy_id='business_declaration_team_admin_v1'),
  'can_assign',EXISTS(SELECT 1 FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    WHERE rg.id=p_read AND rg.delegable AND rq.policy_id='business_agency_mandate_v1' AND rq.agency_business_id IS NOT NULL),
  'agency_business_public_id',(SELECT ab.public_id FROM public.security_action_grants rg JOIN public.authority_qualification_receipts rq ON rq.qualification_id=rg.qualification_id
    JOIN public.businesses ab ON ab.id=rq.agency_business_id WHERE rg.id=p_read)),
 'direct_team_enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1' AND target_ref=p_business AND target_kind='business' AND subject_user_id=actor AND status='active'),
 'purpose_statement',jsonb_build_object('authority_scope','own_business_statement_only','establishes_external_authority',false,
  'enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b),
  'previous_grant',coalesce((SELECT max(x.id)::text FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'),'0'),
  'current_grant',(SELECT jsonb_build_object('id',x.id::text,'version',x.version) FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
   WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(fact_options->'purpose') admitted WHERE admitted->>'grant_id'=x.id::text) ORDER BY x.id DESC LIMIT 1)),
 'context_write_options',options,'fact_write_options',fact_options,
 'lifecycle',jsonb_build_object('revision',(SELECT lifecycle_revision FROM public.businesses WHERE id=b),'archive_options',archive_options),
 'data_use',jsonb_build_object('binding_options',binding_options,'bindings',bindings,'bindings_limit',64,'bindings_more',bindings_more),
 'delegation',jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'options',delegation_options,'issued',issued_grants,'issued_limit',64,'issued_more',more_grants,
  'option_limit',32,'options_may_be_partial',jsonb_array_length(requirements)=32),'catalog',public.builderad_v2_business_catalog(p_session));
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(options||(fact_options->'markets')||(fact_options->'intent')||(fact_options->'preferences')||(fact_options->'purpose')||binding_options||delegation_options||archive_options) x WHERE(x->>'grant_id')::bigint=g.id) THEN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   PERFORM builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
  END IF;
 END LOOP;

 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result||jsonb_build_object('scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('result',result,'read',read_proof,'write',proofs)::text,'UTF8')),'hex')));
END $$;
-- Native source administration projects only the exact current owner's management
-- tuple. It grants no Business, client boundary, data-use or provider permission.
