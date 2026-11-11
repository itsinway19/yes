-- Employee-oriented projection and orchestration over the single existing grant graph.
-- No membership rows, role ACL or separate authority evaluator are introduced.
CREATE FUNCTION builderad_private.business_team_context(p_session text,p_business uuid,p_read bigint,p_write boolean DEFAULT false,p_recipient bigint DEFAULT NULL,p_extra_grants bigint[] DEFAULT '{}') RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;roots bigint[];requirements jsonb;footprint jsonb;proof jsonb;root public.security_action_grants;refs jsonb:='{}';children bigint[];qualifications uuid[];
BEGIN
 b:=builderad_private.lock_business(p_business,p_write);actor:=builderad_private.principal(p_session,false);
 IF actor<>(SELECT created_by FROM public.businesses WHERE id=b) THEN RAISE EXCEPTION 'Original Business team administrator required' USING ERRCODE='42501'; END IF;
 SELECT array_agg(g.id ORDER BY g.id) INTO roots FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business' AND g.parent_grant_id IS NULL AND g.delegation_realm_kind='direct'
  AND g.delegation_workspace_id IS NULL AND g.status='active' AND g.revoked_at IS NULL AND g.delegable
  AND q.policy_id='business_declaration_team_admin_v1' AND q.agency_business_id IS NULL;
 IF cardinality(roots) IS DISTINCT FROM 5 THEN RAISE EXCEPTION 'Current explicit Business team enrollment required' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg(jsonb_build_object('requirement_kind','business','leaf_grant_id',id) ORDER BY id) INTO requirements FROM(SELECT DISTINCT unnest(array_append(roots,p_read)) id) ids;
 footprint:=builderad_private.authority_footprint(actor,jsonb_build_object('requirements',requirements),CASE WHEN p_recipient IS NULL THEN '{}'::bigint[] ELSE ARRAY[p_recipient] END);
 -- Target before-images are locks, never live authority prerequisites. Expired or
 -- disabled staff must remain removable using the administrator's current roots.
 SELECT coalesce(array_agg(g.id),'{}'),coalesce(array_agg(g.qualification_id),'{}') INTO children,qualifications
 FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=p_recipient AND g.parent_grant_id=ANY(roots) AND g.status='active' AND g.revoked_at IS NULL
  AND g.scope_kind='business' AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL AND NOT g.delegable
  AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
 footprint:=jsonb_set(footprint,'{grants}',(footprint->'grants')||to_jsonb(children));
 footprint:=jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||to_jsonb(qualifications));
 footprint:=jsonb_set(footprint,'{grants}',(footprint->'grants')||to_jsonb(p_extra_grants));
 footprint:=jsonb_set(footprint,'{qualifications}',(footprint->'qualifications')||coalesce((SELECT jsonb_agg(qualification_id) FROM public.security_action_grants WHERE id=ANY(p_extra_grants)),'[]'::jsonb));
 PERFORM builderad_private.lock_authority_rows(actor,p_session,footprint);
 proof:=builderad_private.require_business(b,actor,'business.context.read',builderad_private.business_selection(p_read),p_session);
 IF proof#>>'{qualification,policy_id}' NOT IN('self_declared_draft','business_declaration_team_admin_v1') THEN RAISE EXCEPTION 'Independent team read required' USING ERRCODE='42501'; END IF;
 FOR root IN SELECT * FROM public.security_action_grants WHERE id=ANY(roots) ORDER BY id LOOP
  PERFORM builderad_private.require_business(b,actor,root.action,builderad_private.business_selection(root.id),p_session);
  refs:=refs||jsonb_build_object(root.action,root.id::text);
 END LOOP;
 RETURN jsonb_build_object('business_id',b,'actor',actor,'roots',refs,'proof',proof);
END $$;

CREATE FUNCTION builderad_v2_business_team_surface(p_session text,p_business uuid,p_read bigint,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE context jsonb;members jsonb:='[]';member record;grants jsonb;root_ids bigint[];next_cursor bigint;seen integer:=0;last_id bigint;
BEGIN
 IF p_after IS NULL OR p_after<0 THEN RAISE EXCEPTION 'Bounded team cursor required' USING ERRCODE='22023'; END IF;
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 FOR member IN SELECT DISTINCT u.id,u.public_id,u.name,u.email FROM public.users u JOIN public.security_action_grants g ON g.subject_user_id=u.id
  JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE u.id>p_after AND g.business_id=(context->>'business_id')::bigint AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business'
   AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL AND NOT g.delegable
   AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL ORDER BY u.id LIMIT 65 LOOP
  seen:=seen+1;IF seen>64 THEN next_cursor:=last_id;EXIT;END IF;
  SELECT jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version,'action',g.action,'expires_at',g.expires_at) ORDER BY g.id) INTO grants
   FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   WHERE g.business_id=(context->>'business_id')::bigint AND g.subject_user_id=member.id AND g.parent_grant_id=ANY(root_ids)
    AND g.scope_kind='business' AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL AND NOT g.delegable
    AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
  members:=members||jsonb_build_array(jsonb_build_object('public_id',member.public_id,'name',member.name,'email',member.email,'grants',grants));last_id:=member.id;
 END LOOP;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'members',members,'next_cursor',next_cursor,
  'actions',jsonb_build_array('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'));
END $$;

CREATE FUNCTION builderad_v2_business_team_recipient(p_session text,p_business uuid,p_read bigint,p_email text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE context jsonb;recipient record;
BEGIN
 IF p_email IS NULL OR length(p_email)>254 OR btrim(p_email)='' THEN RAISE EXCEPTION 'Exact registered email required' USING ERRCODE='22023'; END IF;
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 IF (SELECT count(*) FROM public.users u WHERE lower(u.email)=lower(btrim(p_email)))<>1 THEN RAISE EXCEPTION 'Unambiguous registered recipient required' USING ERRCODE='42501'; END IF;
 SELECT u.id,u.public_id,u.name,u.email INTO recipient FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
  WHERE lower(u.email)=lower(btrim(p_email)) AND u.id<>(context->>'actor')::bigint AND u.email_verified_at IS NOT NULL
   AND l.status='current' AND l.principal_class='advertiser';
 IF NOT FOUND THEN RAISE EXCEPTION 'Current registered recipient required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'recipient',jsonb_build_object('public_id',recipient.public_id,'name',recipient.name,'email',recipient.email));
END $$;

-- Preserve every completed child proof in the outer invocation before reusing
-- its transaction slot. The canonical child receipts remain independently durable.
CREATE FUNCTION builderad_private.business_team_complete_child(p_actor bigint,p_command uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE child builderad_private.invocations;
BEGIN
 SELECT * INTO child FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id()
  AND actor_user_id=p_actor AND command_id=p_command AND phase='completed' AND action IN('business.grant.issue','business.grant.revoke');
 IF NOT FOUND OR EXISTS(SELECT 1 FROM builderad_private.command_permits WHERE invocation_nonce=child.nonce)
 THEN RAISE EXCEPTION 'Exact finalized child invocation required' USING ERRCODE='42501'; END IF;
 DELETE FROM builderad_private.invocations WHERE nonce=child.nonce AND phase='completed';
 RETURN jsonb_build_object('command_id',child.command_id,'action',child.action,'authority_selection',child.authority_selection,
  'authority_selection_digest',encode(child.authority_selection_digest,'hex'),'dependency_digest',encode(child.dependency_digest,'hex'));
END $$;

CREATE FUNCTION builderad_v2_business_team_change(p_session text,p_business uuid,p_command uuid,p_selection jsonb,p_recipient uuid,p_expected jsonb,p_permissions jsonb,p_expires timestamptz,p_confirmed boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE context jsonb;b bigint;actor bigint;recipient bigint;read_id bigint;root_ids bigint[];desired text[];canonical_expected jsonb;current_grants jsonb;
 payload bytea;result jsonb;refs jsonb:='{}';grant_row public.security_action_grants;action_name text;child_command uuid;issued jsonb;child_proofs jsonb:='[]';retained_proofs jsonb:='[]';retained_proof jsonb;
BEGIN
 IF p_command IS NULL OR p_recipient IS NULL OR p_confirmed IS DISTINCT FROM true OR jsonb_typeof(p_expected) IS DISTINCT FROM 'array'
  OR jsonb_array_length(p_expected)>256 OR jsonb_typeof(p_permissions) IS DISTINCT FROM 'array' OR jsonb_array_length(p_permissions)>5
  OR (p_expires IS NOT NULL AND NOT isfinite(p_expires))
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1 THEN RAISE EXCEPTION 'Exact confirmed employee change required' USING ERRCODE='22023'; END IF;
 SELECT array_agg(value ORDER BY value COLLATE "C") INTO desired FROM jsonb_array_elements_text(p_permissions);
 IF coalesce(cardinality(desired),0)<> (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(p_permissions))
  OR EXISTS(SELECT 1 FROM unnest(desired) a WHERE a NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  OR(cardinality(desired)>0 AND NOT 'business.context.read'=ANY(desired)) THEN RAISE EXCEPTION 'Exact supported employee permissions required' USING ERRCODE='22023'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_expected) x WHERE jsonb_typeof(x) IS DISTINCT FROM 'object' OR x->>'grant_id' !~ '^[1-9][0-9]{0,17}$' OR x->>'version' !~ '^[1-9][0-9]{0,17}$'
  OR (SELECT count(*) FROM jsonb_object_keys(x))<>2) THEN RAISE EXCEPTION 'Exact employee grant versions required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(jsonb_agg(value ORDER BY (value->>'grant_id')::bigint),'[]') INTO canonical_expected FROM jsonb_array_elements(p_expected);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 IF recipient IS NULL THEN RAISE EXCEPTION 'Current registered recipient required' USING ERRCODE='42501'; END IF;
 IF coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,18}$' THEN RAISE EXCEPTION 'Exact Business read reference required' USING ERRCODE='22023'; END IF;
 IF (p_selection#>>'{requirements,0,leaf_grant_id}')::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Bounded Business read reference required' USING ERRCODE='22023'; END IF;
 read_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 b:=(context->>'business_id')::bigint;actor:=(context->>'actor')::bigint;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF recipient=actor THEN RAISE EXCEPTION 'Employee must be another account' USING ERRCODE='42501'; END IF;
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'recipient',p_recipient,'expected',canonical_expected,'permissions',coalesce(to_jsonb(desired),'[]'),'expires',p_expires,'confirmed',p_confirmed),'{"business":"U","recipient":"U","expires":"T"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.team.change',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 -- Expiry constrains a new effect, never recovery of its exact recorded result.
 IF p_expires IS NOT NULL AND p_expires<=clock_timestamp() THEN RAISE EXCEPTION 'Future employee permission expiry required' USING ERRCODE='22023'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id),'[]') INTO current_grants
 FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=recipient AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business'
  AND g.status='active' AND g.revoked_at IS NULL AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL AND NOT g.delegable
  AND q.policy_id='bounded_parent_delegation' AND q.agency_business_id IS NULL;
 IF current_grants IS DISTINCT FROM canonical_expected THEN RAISE EXCEPTION 'Employee permissions changed; review current roster' USING ERRCODE='40001'; END IF;
 IF cardinality(desired)>0 AND NOT EXISTS(SELECT 1 FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id WHERE u.id=recipient AND u.email_verified_at IS NOT NULL AND l.status='current' AND l.principal_class='advertiser') THEN RAISE EXCEPTION 'Current registered employee required' USING ERRCODE='42501'; END IF;
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE id IN(SELECT (x->>'grant_id')::bigint FROM jsonb_array_elements(current_grants) x) ORDER BY id LOOP
  IF grant_row.action=ANY(desired) AND grant_row.expires_at IS NOT DISTINCT FROM p_expires AND NOT refs ? grant_row.action THEN
   retained_proof:=NULL;
   BEGIN
    retained_proof:=builderad_private.verify_scope_path('business',b::text,recipient,grant_row.action,builderad_private.business_selection(grant_row.id),NULL,'{}','{}',false);
   EXCEPTION WHEN insufficient_privilege THEN retained_proof:=NULL;END;
   IF retained_proof IS NOT NULL THEN
    refs:=refs||jsonb_build_object(grant_row.action,grant_row.id::text);retained_proofs:=retained_proofs||jsonb_build_array(retained_proof);CONTINUE;
   END IF;
  END IF;
  child_command:=md5(p_command::text||':staff:revoke:'||grant_row.id::text)::uuid;
  PERFORM public.builderad_v2_business_grant_command(p_session,p_business,child_command,'revoke',p_recipient,grant_row.action,NULL,grant_row.id,grant_row.version,NULL,false,NULL,builderad_private.business_selection(grant_row.parent_grant_id));
  -- A child command has already consumed/cleared every permit and finalized its
  -- receipt. Release only this actor's completed ephemeral transaction slot so
  -- the next canonical child can run. Never replace an authorized invocation.
  child_proofs:=child_proofs||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
 END LOOP;
 FOREACH action_name IN ARRAY coalesce(desired,'{}') LOOP
  IF refs ? action_name THEN CONTINUE;END IF;
  child_command:=md5(p_command::text||':staff:issue:'||action_name)::uuid;
  issued:=public.builderad_v2_business_grant_command(p_session,p_business,child_command,'issue',p_recipient,action_name,(context->'roots'->>action_name)::bigint,NULL,0,p_expires,false,NULL,builderad_private.business_selection((context->'roots'->>action_name)::bigint));
  child_proofs:=child_proofs||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
  refs:=refs||jsonb_build_object(action_name,issued->>'grant_id');
 END LOOP;
 -- Fresh owner proof only: target history/expired grants are not prerequisites.
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.team.change',jsonb_build_object('business_id',b),
  (context->'proof')||jsonb_build_object('completed_children',child_proofs,'retained_staff',retained_proofs,
   'requested_staff_window',CASE WHEN cardinality(desired)>0 THEN jsonb_build_object('expires_at',p_expires) ELSE '{}'::jsonb END));
 result:=jsonb_build_object('business_public_id',p_business,'recipient_public_id',p_recipient,'status','recorded','grant_refs',refs,
  'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.team.change',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_team_context(text,uuid,bigint,boolean,bigint,bigint[]) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_private.business_team_complete_child(bigint,uuid) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_v2_business_team_surface(text,uuid,bigint,bigint),builderad_v2_business_team_recipient(text,uuid,bigint,text),builderad_v2_business_team_change(text,uuid,uuid,jsonb,uuid,jsonb,jsonb,timestamptz,boolean) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_team_surface(text,uuid,bigint,bigint),builderad_v2_business_team_recipient(text,uuid,bigint,text),builderad_v2_business_team_change(text,uuid,uuid,jsonb,uuid,jsonb,jsonb,timestamptz,boolean) TO builderad_app;
