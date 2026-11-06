-- Frozen pre650 surface from9807a173; synthetic upgrade verification only.
CREATE FUNCTION builderad_v2_business_surface(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; b bigint; read_selection jsonb; selection jsonb; proof jsonb; read_proof jsonb; options jsonb:='[]'; proofs jsonb:='[]'; requirements jsonb;
 g record; footprint jsonb; result jsonb;fact_options jsonb:='{"markets":[],"intent":[],"preferences":[],"purpose":[]}';fact_key text;write_action text;
 workspace_ids bigint[]:='{}';workspace_candidates bigint[]:='{}';link_options jsonb:='[]';workspace_choices jsonb:='[]';relationships jsonb:='[]';
 delegation_options jsonb:='[]';issued_grants jsonb:='[]';expiry timestamptz;parent_row public.security_action_grants;more_grants boolean:=false;
 bindings jsonb:='[]';bindings_more boolean:=false;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 read_selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Explicit own read path required' USING ERRCODE='42501'; END IF;
 requirements:=read_selection->'requirements';
 FOR g IN SELECT * FROM public.security_action_grants WHERE business_id=b AND subject_user_id=actor AND id<>p_read AND scope_kind='business' AND status='active' AND revoked_at IS NULL
 AND delegation_realm_kind=read_selection->>'realm_kind' AND delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid) ORDER BY id LIMIT 31 LOOP
  BEGIN
   selection:=builderad_private.business_selection(g.id);footprint:=builderad_private.authority_footprint(actor,selection);
   IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
   requirements:=requirements||(selection->'requirements');
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 footprint:=builderad_private.authority_footprint(actor,read_selection);
 IF EXISTS(SELECT 1 FROM public.security_action_grants x WHERE x.id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND x.scope_kind='business' AND x.business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Target expansion denied' USING ERRCODE='42501'; END IF;
 -- Discover only own current native workspace-view candidates. No raw Workspace profile is released.
 -- Freeze their entire existing authority footprint together with the Business paths, before K4.
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(requirements) r JOIN public.security_action_grants candidate ON candidate.id=(r->>'leaf_grant_id')::bigint WHERE candidate.action='business.link.manage') THEN
  SELECT coalesce(array_agg(id ORDER BY id),'{}'::bigint[]) INTO workspace_candidates FROM(
   SELECT DISTINCT g0.workspace_id id FROM public.security_action_grants g0 JOIN public.principal_lifecycles l ON l.user_id=g0.subject_user_id AND l.generation=g0.subject_generation
   JOIN public.workspace_ownerships o ON o.workspace_id=g0.workspace_id AND o.responsible_owner_user_id=actor
   WHERE g0.subject_user_id=actor AND g0.scope_kind='workspace' AND g0.action='workspace.view' AND g0.status='active' AND g0.revoked_at IS NULL
    AND(g0.expires_at IS NULL OR g0.expires_at>clock_timestamp()) AND builderad_private.workspace_allowed(g0.workspace_id,actor,true) ORDER BY g0.workspace_id LIMIT 33
  ) qualified_candidates;
  workspace_ids:=workspace_candidates[1:32];
 END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',requirements),p_session,'{}',workspace_ids);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  BEGIN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   proof:=builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
   IF write_action='business.context.manage' THEN options:=options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text));
   ELSIF write_action='business.link.manage' THEN
    link_options:=link_options||jsonb_build_array(jsonb_build_object('grant_id',g.id::text,'allows_agency',proof#>>'{qualification,issuer_kind}'<>'self_declared_draft'));
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
     'can_issue',parent_row.delegable AND jsonb_array_length(proof->'grants')<16,
     'can_delegate_onward',proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1'),
     'can_choose_agency',read_selection->>'realm_kind'='direct' AND proof#>>'{qualification,policy_id}' NOT IN('internal_client_file_admin_v1','business_declaration_team_admin_v1'),
     'max_expires_at',expiry,'chain_depth',jsonb_array_length(proof->'grants')));
   END IF;
   proofs:=proofs||jsonb_build_array(proof);
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 SELECT count(*)>64 INTO more_grants FROM(SELECT 1 FROM public.security_action_grants child
 WHERE child.scope_kind='business' AND child.business_id=b AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) LIMIT 65) bounded;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',child.id::text,'parent_grant_id',child.parent_grant_id::text,'recipient_public_id',u.public_id,
  'action',child.action,'recorded_status',child.status,'revision',child.version,'expires_at',child.expires_at,'delegable',child.delegable,
  'realm_kind',child.delegation_realm_kind,'realm_workspace_public_id',w.public_id) ORDER BY child.id DESC),'[]'::jsonb) INTO issued_grants
 FROM(SELECT * FROM public.security_action_grants child WHERE child.scope_kind='business' AND child.business_id=b
  AND child.parent_grant_id IN(SELECT(x->>'grant_id')::bigint FROM jsonb_array_elements(delegation_options) x) ORDER BY child.id DESC LIMIT 64) child
 JOIN public.users u ON u.id=child.subject_user_id LEFT JOIN public.workspaces w ON w.id=child.delegation_workspace_id;
 IF jsonb_array_length(link_options)>0 THEN
  WITH latest AS MATERIALIZED(SELECT DISTINCT ON(data_use_scope_id) * FROM public.business_data_use_bindings WHERE business_id=b ORDER BY data_use_scope_id,revision DESC,binding_id DESC),
  bounded AS MATERIALIZED(SELECT *,row_number() OVER(ORDER BY binding_id DESC) position FROM latest ORDER BY binding_id DESC LIMIT 65)
  SELECT coalesce(jsonb_agg(jsonb_build_object('binding_id',binding_id,'scope_id',data_use_scope_id,'state',status,'revision',revision) ORDER BY binding_id DESC) FILTER(WHERE position<=64),'[]'),count(*)>64
   INTO bindings,bindings_more FROM bounded;
  -- The legacy name can itself be an unqualified old Business fact. Only the independently
  -- owned collaboration identifier is released; never relabel that old name as safe metadata.
  SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',w.public_id,'slug',w.slug) ORDER BY w.id),'[]'::jsonb)
   INTO workspace_choices FROM public.workspaces w WHERE w.id=ANY(workspace_ids) AND builderad_private.workspace_allowed(w.id,actor,true);
  SELECT coalesce(jsonb_agg(jsonb_build_object('link_ref',x.link_id,'workspace_public_id',w.public_id,'workspace_slug',w.slug,'kind',x.kind,'state',x.status,'revision',x.revision) ORDER BY x.link_id),'[]'::jsonb)
   INTO relationships FROM(SELECT DISTINCT ON(workspace_id,kind) * FROM public.business_workspace_links WHERE business_id=b AND workspace_id=ANY(workspace_ids) ORDER BY workspace_id,kind,revision DESC,link_id DESC) x
   JOIN public.workspaces w ON w.id=x.workspace_id WHERE w.id=ANY(workspace_ids) AND builderad_private.workspace_allowed(w.id,actor,true);
 END IF;
 result:=jsonb_build_object('business',public.builderad_v2_business_read(p_session,p_business,read_selection),'authority_selection',read_selection,
 'private_history_available',read_selection->>'realm_kind'<>'internal_team' AND read_proof#>>'{qualification,policy_id}'<>'business_declaration_team_admin_v1',
 'direct_team_enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND NOT EXISTS(SELECT 1 FROM public.authority_qualification_receipts WHERE policy_id='business_declaration_team_admin_v1' AND target_ref=p_business AND target_kind='business' AND subject_user_id=actor AND status='active'),
 'purpose_statement',jsonb_build_object('authority_scope','own_business_statement_only','establishes_external_authority',false,
  'enrollment_available',read_proof#>>'{qualification,policy_id}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b),
  'previous_grant',coalesce((SELECT max(x.id)::text FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'),'0'),
  'current_grant',(SELECT jsonb_build_object('id',x.id::text,'version',x.version) FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
   WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1'
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(fact_options->'purpose') admitted WHERE admitted->>'grant_id'=x.id::text) ORDER BY x.id DESC LIMIT 1)),
 'context_write_options',options,'fact_write_options',fact_options,'data_use',jsonb_build_object('bindings',bindings,'bindings_limit',64,'bindings_more',bindings_more),
 'collaboration',jsonb_build_object('options',link_options,'workspaces',workspace_choices,'relationships',relationships,
  'workspace_limit',32,'workspace_more',cardinality(workspace_candidates)>32,'relationship_limit',64),
 'delegation',jsonb_build_object('actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),'options',delegation_options,'issued',issued_grants,'issued_limit',64,'issued_more',more_grants,
  'option_limit',32,'options_may_be_partial',jsonb_array_length(requirements)=32),'catalog',public.builderad_v2_business_catalog(p_session));
 FOR g IN SELECT (value->>'leaf_grant_id')::bigint id FROM jsonb_array_elements(requirements) LOOP
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(options||(fact_options->'markets')||(fact_options->'intent')||(fact_options->'preferences')||(fact_options->'purpose')||link_options||delegation_options) x WHERE(x->>'grant_id')::bigint=g.id) THEN
   SELECT action INTO write_action FROM public.security_action_grants WHERE id=g.id;
   PERFORM builderad_private.require_business(b,actor,write_action,builderad_private.business_selection(g.id),p_session);
  END IF;
 END LOOP;
 FOR g IN SELECT value FROM jsonb_array_elements(workspace_choices) LOOP
  IF NOT builderad_private.workspace_allowed((SELECT id FROM public.workspaces WHERE public_id=(g.value->>'public_id')::uuid),actor,true)
   THEN RAISE EXCEPTION 'Collaboration metadata authority changed after wait' USING ERRCODE='42501'; END IF;
 END LOOP;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result||jsonb_build_object('scope',jsonb_build_object('principal_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
 'authority_fingerprint',encode(sha256(convert_to(jsonb_build_object('result',result,'read',read_proof,'write',proofs)::text,'UTF8')),'hex')));
END $$;
