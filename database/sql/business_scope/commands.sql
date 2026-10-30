CREATE FUNCTION builderad_private.lock_business(p_public uuid,p_write boolean DEFAULT false) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; c uuid; mode text;
BEGIN
 SELECT id INTO b FROM public.businesses WHERE public_id=p_public;
 IF b IS NULL OR session_user<>'builderad_app' OR NOT EXISTS (
  SELECT 1 FROM public.builderad_signed_context() c
  JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE c.phase='principal' AND c.workspace_id IS NULL AND g.scope_kind='business' AND g.business_id=b
   AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
 ) THEN RAISE EXCEPTION 'Business scope denied' USING ERRCODE='42501'; END IF;
 SELECT cohort_id INTO c FROM public.migration_cohort_members WHERE business_id=b AND kind='business';
 IF c IS NULL THEN RAISE EXCEPTION 'Business control required' USING ERRCODE='42501'; END IF;
 SELECT x.mode INTO mode FROM public.migration_cohort_control x WHERE cohort_id=c FOR SHARE;
 IF mode<>'V2_ACTIVE' THEN RAISE EXCEPTION 'Business scope unavailable' USING ERRCODE='42501'; END IF;
 IF p_write THEN PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR UPDATE;
 ELSE PERFORM 1 FROM public.business_authority_fences WHERE business_id=b FOR SHARE; END IF;
 RETURN b;
END $$;

CREATE FUNCTION builderad_private.require_business(p_business bigint,p_actor bigint,p_action text,p_selection jsonb,p_session text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE result jsonb;
BEGIN
 IF jsonb_typeof(p_selection->'requirements')<>'array' OR jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact Business authority selection required' USING ERRCODE='42501'; END IF;
 result:=builderad_private.require_scope('business',p_business::text,p_actor,p_action,p_selection,p_session);
 IF (SELECT lifecycle FROM public.businesses WHERE id=p_business) IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required' USING ERRCODE='42501'; END IF;
 RETURN result;
END $$;
CREATE FUNCTION builderad_private.issue_draft_grant(p_business bigint,p_actor bigint,p_qualification uuid,p_action text) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE g public.security_action_grants;
BEGIN
 g.id:=nextval('public.security_action_grants_id_seq');
 g.subject_user_id:=p_actor; g.granted_by:=NULL; g.source:='business_draft_creation'; g.action:=p_action; g.scope_kind:='business';
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=p_actor;
 g.reason:='Private self-declared Business statements only'; g.status:='active'; g.version:=1;
 g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
 g.business_id:=p_business;g.qualification_id:=p_qualification;g.delegable:=false;g.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));
 INSERT INTO public.security_action_grants SELECT (g).*;
 RETURN g.id;
END $$;

CREATE FUNCTION builderad_private.record_command(p_business bigint,p_actor bigint,p_command uuid,p_action text,p_payload bytea,p_result jsonb,p_state text DEFAULT 'committed') RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.business_command_receipts; i builderad_private.invocations;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 r.command_id:=p_command;r.business_id:=p_business;r.actor_user_id:=p_actor;r.generation:=i.generation;r.action:=p_action;r.payload_digest:=p_payload;
 r.state:=p_state;r.result_ref:=p_result;r.returned_versions:=p_result->'versions';r.recorded_at:=clock_timestamp();
 r.authority_selection_digest:=i.authority_selection_digest;r.dependency_digest:=i.dependency_digest;
 PERFORM builderad_private.permit('public.business_command_receipts','insert',NULL,to_jsonb(r));
 INSERT INTO public.business_command_receipts SELECT (r).*;
 PERFORM builderad_private.assert_invocation_current();
END $$;

CREATE FUNCTION builderad_v2_business_create_draft(p_session text,p_command uuid,p_name text,p_type bigint DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint; payload bytea; b public.businesses; h public.business_context_heads; n public.business_current_intents;
 f public.business_authority_fences; c public.migration_cohort_control; m public.migration_cohort_members;
 q public.authority_qualification_receipts; result jsonb; grants jsonb:='{}'; a text; existing public.business_command_receipts; snapshot jsonb;
BEGIN
 SELECT x.actor_user_id INTO actor FROM public.builderad_signed_context() x WHERE x.phase='principal' AND x.workspace_id IS NULL;
 IF session_user<>'builderad_app' OR actor IS NULL OR p_command IS NULL OR p_name IS NULL OR length(btrim(p_name)) NOT BETWEEN 2 AND 120 OR octet_length(p_name)>480
 THEN RAISE EXCEPTION 'Bounded explicit draft command required' USING ERRCODE='22023'; END IF;
 p_name:=regexp_replace(btrim(p_name),'\s+',' ','g');
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('name',p_name,'business_type_id',p_type),jsonb_build_object('business_type_id','I'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.business.create.'||actor::text||':'||p_command::text,0));
 SELECT * INTO existing FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF existing.actor_user_id<>actor OR existing.action<>'business.draft.create' OR existing.payload_digest<>payload
  THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.lock_business((SELECT public_id FROM public.businesses WHERE id=existing.business_id));
  actor:=builderad_private.principal(p_session,false);
  PERFORM builderad_private.require_business(existing.business_id,actor,'business.context.read',
   jsonb_build_object('realm_kind','direct','realm_workspace_public_id',NULL,'requirements',
    jsonb_build_array(jsonb_build_object('requirement_kind','business','leaf_grant_id',existing.result_ref#>'{grant_refs,business.context.read}'))),p_session);
  RETURN existing.result_ref;
 END IF;
 actor:=builderad_private.principal(p_session);
 IF p_type IS NOT NULL THEN
  PERFORM 1 FROM public.business_types WHERE id=p_type AND active FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Selectable Business type required' USING ERRCODE='22023'; END IF;
 END IF;
 b.id:=nextval('public.businesses_id_seq');b.public_id:=uuidv7();b.lifecycle:='declared_draft';b.lifecycle_revision:=0;b.origin_kind:='self_declared_draft';b.created_by:=actor;b.created_at:=clock_timestamp();
 snapshot:=jsonb_build_object('policy','self_declared_draft','actor',actor,'business',b.public_id,'command',p_command);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.draft.create',jsonb_build_object('business_id',b.id),snapshot);
 PERFORM builderad_private.permit('public.businesses','insert',NULL,to_jsonb(b));INSERT INTO public.businesses SELECT (b).*;
 h.business_id:=b.id;h.display_name:=p_name;h.business_type_id:=p_type;h.context_version:=0;
 PERFORM builderad_private.permit('public.business_context_heads','insert',NULL,to_jsonb(h));INSERT INTO public.business_context_heads SELECT (h).*;
 n.business_id:=b.id;n.intent_revision:=0;n.value:=NULL;
 PERFORM builderad_private.permit('public.business_current_intents','insert',NULL,to_jsonb(n));INSERT INTO public.business_current_intents SELECT (n).*;
 f.business_id:=b.id;f.auth_epoch:=0;
 PERFORM builderad_private.permit('public.business_authority_fences','insert',NULL,to_jsonb(f));INSERT INTO public.business_authority_fences SELECT (f).*;
 c.cohort_id:=uuidv7();c.mode:='V2_ACTIVE';c.epoch:=0;c.guard_schema_version:=1;c.manifest_version:=0;c.checkpoint_digest:=NULL;
 PERFORM builderad_private.permit('public.migration_cohort_control','insert',NULL,to_jsonb(c));INSERT INTO public.migration_cohort_control SELECT (c).*;
 m.member_id:=uuidv7();m.cohort_id:=c.cohort_id;m.kind:='business';m.workspace_id:=NULL;m.business_id:=b.id;
 PERFORM builderad_private.permit('public.migration_cohort_members','insert',NULL,to_jsonb(m));INSERT INTO public.migration_cohort_members SELECT (m).*;
 q.qualification_id:=uuidv7();q.policy_id:='self_declared_draft';q.policy_version:=1;q.issuer_kind:='self_declared_draft';q.issuer_grant_id:=NULL;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=b.public_id;
 q.allowed_actions:=ARRAY['business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage','business.link.manage','business.draft.archive'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=b.created_at;q.valid_until:=NULL;q.provenance:='Own statements only; no legal ownership or external-source authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT (q).*;
 FOREACH a IN ARRAY q.allowed_actions LOOP
  grants:=grants||jsonb_build_object(a,builderad_private.issue_draft_grant(b.id,actor,q.qualification_id,a));
 END LOOP;
 result:=jsonb_build_object('business_public_id',b.public_id,'name',h.display_name,'state',b.lifecycle,'grant_refs',grants,'versions',jsonb_build_object('context',0,'intent',0,'preferences',0));
 PERFORM builderad_private.record_command(b.id,actor,p_command,'business.draft.create',payload,result);
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.finish_command();
 RETURN result;
END $$;

CREATE FUNCTION builderad_v2_business_read(p_session text,p_business uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; snapshot jsonb; result jsonb;
BEGIN
 b:=builderad_private.lock_business(p_business);
 actor:=builderad_private.principal(p_session,false);
 snapshot:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.start_command(actor,p_session,uuidv7(),'business.context.read',jsonb_build_object('business_id',b),snapshot);
 SELECT jsonb_build_object('business_public_id',x.public_id,'name',h.display_name,'business_type_id',h.business_type_id,'state',x.lifecycle,
  'current_intent',n.value,'preferences',(SELECT value FROM public.business_goal_preferences WHERE business_id=b),
  'declared_markets',coalesce((SELECT jsonb_agg(country_code::text ORDER BY country_code COLLATE "C") FROM public.business_declared_markets WHERE business_id=b),'[]'::jsonb),'versions',jsonb_build_object('context',h.context_version,'intent',n.intent_revision,
   'preferences',coalesce((SELECT preference_version FROM public.business_goal_preferences WHERE business_id=b),0)),
  'links',coalesce((SELECT jsonb_agg(jsonb_build_object('workspace_public_id',w.public_id,'kind',l.kind,'revision',l.revision))
   FROM public.business_workspace_links l JOIN public.workspaces w ON w.id=l.workspace_id
   WHERE l.business_id=b AND l.status='active' AND l.valid_from<=clock_timestamp() AND (l.valid_until IS NULL OR l.valid_until>clock_timestamp())
   AND builderad_private.workspace_allowed(w.id,actor,false)),'[]'::jsonb))
 INTO result FROM public.businesses x JOIN public.business_context_heads h ON h.business_id=x.id
 JOIN public.business_current_intents n ON n.business_id=x.id WHERE x.id=b;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 PERFORM builderad_private.finish_command();
 RETURN result;
END $$;
CREATE FUNCTION builderad_private.workspace_allowed(p_workspace bigint,p_actor bigint,p_manage boolean) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 RETURN EXISTS (
  SELECT 1 FROM public.workspaces w JOIN public.workspace_memberships m ON m.workspace_id=w.id AND m.user_id=p_actor AND m.status='active'
  JOIN public.workspace_ownerships o ON o.workspace_id=w.id
  JOIN public.workspace_memberships om ON om.workspace_id=w.id AND om.user_id=o.responsible_owner_user_id AND om.role='owner' AND om.status='active'
  JOIN public.principal_lifecycles owner_l ON owner_l.user_id=o.responsible_owner_user_id AND owner_l.status='current' AND owner_l.principal_class='advertiser'
  JOIN public.users owner_u ON owner_u.id=owner_l.user_id AND owner_u.email_verified_at IS NOT NULL
  JOIN public.principal_lifecycles actor_l ON actor_l.user_id=p_actor AND actor_l.status='current'
  JOIN public.security_action_grants g ON g.workspace_id=w.id AND g.subject_user_id=p_actor AND g.subject_generation=actor_l.generation
  WHERE w.id=p_workspace AND w.status='active' AND o.version>0 AND (NOT p_manage OR o.responsible_owner_user_id=p_actor)
  AND g.action='workspace.view' AND g.scope_kind='workspace' AND g.status='active' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())
 );
END $$;

CREATE FUNCTION builderad_v2_business_link_command(p_session text,p_business uuid,p_workspace uuid,p_command uuid,p_effect text,p_expected bigint,p_selection jsonb,p_kind text DEFAULT 'collaboration') RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; w bigint; snapshot jsonb; payload bytea; l public.business_workspace_links; prior public.business_workspace_links;
 f public.business_authority_fences; old_f public.business_authority_fences; result jsonb; r public.business_command_receipts; changed boolean:=false;
BEGIN
 IF p_kind IS NULL OR p_kind NOT IN('collaboration','agency') OR p_command IS NULL OR p_effect NOT IN ('add','end') OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed relationship command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true); actor:=builderad_private.principal(p_session,false);
 SELECT id INTO w FROM public.workspaces WHERE public_id=p_workspace;
 IF w IS NULL OR jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact collaboration target and Business authority required' USING ERRCODE='42501'; END IF;
 snapshot:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session,'{}',ARRAY[w]);
 PERFORM builderad_private.principal(p_session);
 IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') OR(p_kind='agency' AND snapshot#>>'{qualification,issuer_kind}'='self_declared_draft') THEN RAISE EXCEPTION 'Qualified agency relationship authority required' USING ERRCODE='42501'; END IF;
 IF w IS NULL OR NOT builderad_private.workspace_allowed(w,actor,true) THEN RAISE EXCEPTION 'Qualified collaboration authority required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'workspace',p_workspace,'effect',p_effect,'expected',p_expected,'kind',p_kind),
 '{"business":"U","workspace":"U","expected":"I"}');
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF r.actor_user_id<>actor OR r.business_id<>b OR r.action<>'business.link.manage' OR r.payload_digest<>payload THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
  RETURN r.result_ref;
 END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.link.manage',jsonb_build_object('business_id',b,'workspace_id',w),snapshot);
 -- The logical tuple keeps its CAS watermark across independent relationship identities.
 -- Resetting to zero/one on recreation lets an old prepared end target a different relationship.
 SELECT * INTO prior FROM public.business_workspace_links WHERE business_id=b AND workspace_id=w AND kind=p_kind ORDER BY revision DESC,link_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Relationship changed before effect' USING ERRCODE='B4090'; END IF;
 l:=prior;
 IF p_effect='add' AND(prior.link_id IS NULL OR prior.status='ended') THEN
  l.link_id:=uuidv7();l.business_id:=b;l.workspace_id:=w;l.kind:=p_kind;l.status:='active';l.revision:=coalesce(prior.revision,0)+1;l.valid_from:=clock_timestamp();l.valid_until:=NULL;
  l.authority_ref:=(snapshot#>>'{qualification,qualification_id}')::uuid;
  PERFORM builderad_private.permit('public.business_workspace_links','insert',NULL,to_jsonb(l));INSERT INTO public.business_workspace_links SELECT (l).*;changed:=true;
 ELSIF p_effect='end' AND prior.status='active' THEN
  l.status:='ended';l.revision:=prior.revision+1;l.valid_until:=clock_timestamp();
  PERFORM builderad_private.permit('public.business_workspace_links','update',to_jsonb(prior),to_jsonb(l));
  UPDATE public.business_workspace_links SET status=l.status,revision=l.revision,valid_until=l.valid_until WHERE link_id=l.link_id;changed:=true;
 END IF;
 IF changed THEN
  SELECT * INTO old_f FROM public.business_authority_fences WHERE business_id=b;f:=old_f;f.auth_epoch:=f.auth_epoch+1;
  PERFORM builderad_private.permit('public.business_authority_fences','update',to_jsonb(old_f),to_jsonb(f));
  UPDATE public.business_authority_fences SET auth_epoch=f.auth_epoch WHERE business_id=b;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'workspace_public_id',p_workspace,'kind',p_kind,'state',CASE WHEN p_effect='end' THEN 'ended' ELSE 'active' END,
 'versions',jsonb_build_object('link',coalesce(l.revision,0)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.link.manage',payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.link.manage',p_selection,p_session);
 IF NOT builderad_private.workspace_allowed(w,actor,true) THEN RAISE EXCEPTION 'Collaboration authority changed' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
