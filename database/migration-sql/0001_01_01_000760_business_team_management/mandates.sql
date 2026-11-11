-- A complete withdrawal is a finite set of existing mandate grants, not another
-- relationship/ACL store. Independent user, Source and data rights are excluded.
CREATE FUNCTION builderad_v2_business_agency_mandates(p_session text,p_business uuid,p_read bigint,p_after uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE context jsonb;items jsonb:='[]';group_row record;root_ids bigint[];seen integer:=0;more boolean:=false;last_id uuid;next_cursor uuid;
BEGIN
 context:=builderad_private.business_team_context(p_session,p_business,p_read);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 FOR group_row IN SELECT ab.public_id,jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id) grants,count(*) n
  FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   JOIN public.businesses ab ON ab.id=q.agency_business_id
  WHERE g.business_id=(context->>'business_id')::bigint AND g.parent_grant_id=ANY(root_ids) AND g.status='active' AND g.revoked_at IS NULL
   AND q.policy_id='business_agency_mandate_v1' AND(p_after IS NULL OR ab.public_id>p_after) GROUP BY ab.public_id ORDER BY ab.public_id LIMIT 65 LOOP
  seen:=seen+1;IF seen>64 THEN more:=true;next_cursor:=last_id;EXIT;END IF;
  items:=items||jsonb_build_array(jsonb_build_object('agency_business_public_id',group_row.public_id,
   'grants',CASE WHEN group_row.n<=256 THEN group_row.grants ELSE '[]'::jsonb END,'complete',group_row.n<=256));last_id:=group_row.public_id;
 END LOOP;
 PERFORM builderad_private.require_business((context->>'business_id')::bigint,(context->>'actor')::bigint,'business.context.read',builderad_private.business_selection(p_read),p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'items',items,'more',more,'next_cursor',next_cursor);
END $$;

CREATE FUNCTION builderad_v2_business_agency_withdraw(p_session text,p_business uuid,p_command uuid,p_selection jsonb,p_agency uuid,p_expected jsonb,p_confirmed boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;recipient bigint;agency bigint;read_id bigint;context jsonb;root_ids bigint[];targets bigint[];expected jsonb;current_set jsonb;payload bytea;result jsonb;mandate_row public.security_action_grants;child_command uuid;children jsonb:='[]';
BEGIN
 IF p_command IS NULL OR p_agency IS NULL OR p_confirmed IS DISTINCT FROM true OR jsonb_typeof(p_expected) IS DISTINCT FROM 'array' OR jsonb_array_length(p_expected)>256
  OR p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR p_selection->'realm_workspace_public_id' IS DISTINCT FROM 'null'::jsonb
  OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1 OR coalesce(p_selection#>>'{requirements,0,leaf_grant_id}','') !~ '^[1-9][0-9]{0,18}$'
 THEN RAISE EXCEPTION 'Exact confirmed mandate withdrawal required' USING ERRCODE='22023';END IF;
 IF (p_selection#>>'{requirements,0,leaf_grant_id}')::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Bounded read reference required' USING ERRCODE='22023';END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p_expected) x WHERE jsonb_typeof(x) IS DISTINCT FROM 'object' OR coalesce(x->>'grant_id','') !~ '^[1-9][0-9]{0,17}$'
  OR coalesce(x->>'version','') !~ '^[1-9][0-9]{0,17}$' OR (SELECT count(*) FROM jsonb_object_keys(x))<>2) THEN RAISE EXCEPTION 'Exact mandate grant versions required' USING ERRCODE='22023';END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);read_id:=(p_selection#>>'{requirements,0,leaf_grant_id}')::bigint;
 SELECT id,created_by INTO agency,recipient FROM public.businesses WHERE public_id=p_agency;
 IF agency IS NULL OR agency=b THEN RAISE EXCEPTION 'Exact counterparty Business required' USING ERRCODE='42501';END IF;
 SELECT coalesce(array_agg(g.id),'{}') INTO targets FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE g.business_id=b AND q.policy_id='business_agency_mandate_v1' AND q.agency_business_id=agency AND g.status='active' AND g.revoked_at IS NULL;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient,targets);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 SELECT array_agg(value::bigint) INTO root_ids FROM jsonb_each_text(context->'roots');
 SELECT coalesce(jsonb_agg(value ORDER BY (value->>'grant_id')::bigint),'[]') INTO expected FROM jsonb_array_elements(p_expected);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'agency',p_agency,'expected',expected,'confirmed',p_confirmed),'{"business":"U","agency":"U"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.agency.withdraw',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('grant_id',g.id::text,'version',g.version) ORDER BY g.id),'[]') INTO current_set FROM public.security_action_grants g
  WHERE g.id=ANY(targets) AND g.parent_grant_id=ANY(root_ids) AND g.scope_kind='business' AND g.subject_user_id=recipient;
 IF current_set IS DISTINCT FROM expected OR jsonb_array_length(expected)=0 OR cardinality(targets)<>jsonb_array_length(expected)
 THEN RAISE EXCEPTION 'Complete current mandate set required' USING ERRCODE='40001';END IF;
 FOR mandate_row IN SELECT * FROM public.security_action_grants WHERE id=ANY(targets) ORDER BY id LOOP
  child_command:=md5(p_command::text||':mandate:withdraw:'||mandate_row.id::text)::uuid;
  PERFORM public.builderad_v2_business_grant_command(p_session,p_business,child_command,'revoke',(SELECT public_id FROM public.users WHERE id=recipient),mandate_row.action,NULL,mandate_row.id,mandate_row.version,NULL,false,NULL,builderad_private.business_selection(mandate_row.parent_grant_id));
  children:=children||jsonb_build_array(builderad_private.business_team_complete_child(actor,child_command));
 END LOOP;
 context:=builderad_private.business_team_context(p_session,p_business,read_id,true,recipient);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.agency.withdraw',jsonb_build_object('business_id',b),
  (context->'proof')||jsonb_build_object('completed_children',children));
 result:=jsonb_build_object('business_public_id',p_business,'agency_business_public_id',p_agency,'status','withdrawn',
  'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.agency.withdraw',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_business_agency_mandates(text,uuid,bigint,uuid),builderad_v2_business_agency_withdraw(text,uuid,uuid,jsonb,uuid,jsonb,boolean) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_agency_mandates(text,uuid,bigint,uuid),builderad_v2_business_agency_withdraw(text,uuid,uuid,jsonb,uuid,jsonb,boolean) TO builderad_app;
