CREATE FUNCTION builderad_private.advance_business_epoch(p_business bigint) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE old_f public.business_authority_fences;f public.business_authority_fences;
BEGIN
 SELECT * INTO STRICT old_f FROM public.business_authority_fences WHERE business_id=p_business;f:=old_f;f.auth_epoch:=f.auth_epoch+1;
 PERFORM builderad_private.permit('public.business_authority_fences','update',to_jsonb(old_f),to_jsonb(f));
 UPDATE public.business_authority_fences SET auth_epoch=f.auth_epoch WHERE business_id=p_business;
END $$;

CREATE FUNCTION builderad_v2_business_grant_command(p_session text,p_business uuid,p_command uuid,p_effect text,p_grantee uuid,p_action text,p_parent bigint,p_grant bigint,p_expected bigint,p_expires timestamptz,p_delegable boolean,p_realm_workspace uuid,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;recipient bigint;realm_id bigint;proof jsonb;payload bytea;result jsonb;selected bigint;
 parent public.security_action_grants;g public.security_action_grants;old_g public.security_action_grants;q public.authority_qualification_receipts;root_q public.authority_qualification_receipts;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('issue','revoke') OR p_grantee IS NULL OR p_expected IS NULL OR p_expected<0 OR p_delegable IS NULL
 OR p_action IS NULL OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage','business.link.manage','business.draft.archive','business.lifecycle.manage','business.branch.manage','business.data.use','business.purpose.declare')
 THEN RAISE EXCEPTION 'Exact bounded Business grant command required' USING ERRCODE='22023'; END IF;
 IF (p_effect='issue' AND(p_parent IS NULL OR p_grant IS NOT NULL OR p_expected<>0)) OR(p_effect='revoke' AND(p_grant IS NULL OR p_expected<1))
 THEN RAISE EXCEPTION 'Issue needs selected parent; revoke needs exact grant and version' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_grantee;
 IF recipient IS NULL THEN RAISE EXCEPTION 'Qualified recipient required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,p_action,p_selection,p_session,ARRAY[recipient]);
 PERFORM builderad_private.principal(p_session);
 IF jsonb_array_length(p_selection->'requirements')<>1 OR(SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') THEN RAISE EXCEPTION 'Exact current Business authority required' USING ERRCODE='42501'; END IF;
 selected:=(proof#>>'{grants,0,id}')::bigint;SELECT * INTO STRICT parent FROM public.security_action_grants WHERE id=selected;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('effect',p_effect,'grantee',p_grantee,'action',p_action,'parent',p_parent,'grant',p_grant,'expected',p_expected,'expires',p_expires,'delegable',p_delegable,'realm',p_realm_workspace),
 '{"grantee":"U","parent":"I","grant":"I","expected":"I","expires":"T","realm":"U"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.grant.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF p_effect='issue' THEN
  IF jsonb_array_length(proof->'grants')>=16 OR p_parent<>selected OR NOT parent.delegable OR recipient=actor OR(p_expires IS NOT NULL AND p_expires<=clock_timestamp())
   OR(parent.expires_at IS NOT NULL AND(p_expires IS NULL OR p_expires>parent.expires_at)) THEN RAISE EXCEPTION 'Delegation exceeds current parent' USING ERRCODE='42501'; END IF;
  SELECT * INTO STRICT root_q FROM public.authority_qualification_receipts WHERE qualification_id=parent.qualification_id;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'qualifications') qualification_item(value) WHERE qualification_item.value->>'valid_until' IS NOT NULL AND(p_expires IS NULL OR p_expires>(qualification_item.value->>'valid_until')::timestamptz))
   OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') grant_item(value) WHERE grant_item.value->>'expires_at' IS NOT NULL AND(p_expires IS NULL OR p_expires>(grant_item.value->>'expires_at')::timestamptz))
  THEN RAISE EXCEPTION 'Delegation exceeds complete parent validity window' USING ERRCODE='42501'; END IF;
  IF NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=recipient AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL) THEN RAISE EXCEPTION 'Current recipient required' USING ERRCODE='42501'; END IF;
  IF root_q.policy_id='internal_client_file_admin_v1' AND(p_delegable OR p_realm_workspace IS NULL
   OR p_realm_workspace IS DISTINCT FROM(SELECT public_id FROM public.workspaces WHERE id=parent.delegation_workspace_id)
   OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  THEN RAISE EXCEPTION 'Internal staff authority is exact, team-bound and nondelegable' USING ERRCODE='42501'; END IF;
  IF root_q.policy_id='business_declaration_team_admin_v1' AND(p_delegable OR p_realm_workspace IS NOT NULL
   OR p_action NOT IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage'))
  THEN RAISE EXCEPTION 'Independent staff rights are exact, direct and nondelegable' USING ERRCODE='42501'; END IF;
  IF p_realm_workspace IS NOT NULL THEN
   SELECT id INTO realm_id FROM public.workspaces WHERE public_id=p_realm_workspace;
   IF root_q.policy_id='internal_client_file_admin_v1' THEN
    IF realm_id IS DISTINCT FROM parent.delegation_workspace_id OR NOT EXISTS(SELECT 1 FROM public.business_workspace_links relationship
     WHERE relationship.business_id=b AND relationship.workspace_id=realm_id AND relationship.kind='internal_team' AND relationship.status='active'
     AND root_q.evidence_ref='internal-team:'||relationship.link_id::text)
     OR NOT EXISTS(SELECT 1 FROM public.workspace_memberships WHERE workspace_id=realm_id AND user_id=recipient AND status='active')
    THEN RAISE EXCEPTION 'Current internal file team recipient required' USING ERRCODE='42501'; END IF;
   ELSIF realm_id IS NULL OR NOT EXISTS(SELECT 1 FROM public.business_workspace_links relationship JOIN public.authority_qualification_receipts qualified_link ON qualified_link.qualification_id=relationship.authority_ref WHERE relationship.business_id=b AND relationship.workspace_id=realm_id AND relationship.kind='agency' AND relationship.status='active' AND relationship.valid_from<=clock_timestamp() AND(relationship.valid_until IS NULL OR relationship.valid_until>clock_timestamp()) AND qualified_link.status='active' AND qualified_link.target_kind='business' AND qualified_link.target_ref=p_business AND qualified_link.issuer_kind<>'self_declared_draft' AND 'business.link.manage'=ANY(qualified_link.allowed_actions) AND qualified_link.valid_from<=clock_timestamp() AND(qualified_link.valid_until IS NULL OR qualified_link.valid_until>clock_timestamp()))
    OR NOT EXISTS(SELECT 1 FROM public.workspace_memberships WHERE workspace_id=realm_id AND user_id=recipient AND status='active') THEN RAISE EXCEPTION 'Qualified agency and recipient team required' USING ERRCODE='42501'; END IF;
  END IF;
  IF parent.delegation_realm_kind IN('agency','internal_team') AND parent.delegation_workspace_id IS DISTINCT FROM realm_id THEN RAISE EXCEPTION 'Organizational authority cannot escape its realm' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.start_command(actor,p_session,p_command,'business.grant.issue',jsonb_build_object('business_id',b),proof);
  q.qualification_id:=uuidv7();q.policy_id:='bounded_parent_delegation';q.policy_version:=1;q.issuer_kind:='existing_grant';q.issuer_grant_id:=parent.id;
  q.subject_user_id:=recipient;q.target_kind:='business';q.target_ref:=p_business;q.allowed_actions:=ARRAY[p_action];q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_expires;q.provenance:='Exact existing parent; qualified realm; no new root authority';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.granted_by:=actor;g.source:='qualified_delegation';g.action:=p_action;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
  g.reason:='Bounded explicit parent delegation';g.status:='active';g.version:=1;g.expires_at:=p_expires;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
  g.qualification_id:=q.qualification_id;g.parent_grant_id:=parent.id;g.delegable:=p_delegable;g.delegation_realm_kind:=CASE WHEN realm_id IS NULL THEN 'direct' WHEN root_q.policy_id='internal_client_file_admin_v1' THEN 'internal_team' ELSE 'agency' END;g.delegation_workspace_id:=realm_id;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 ELSE
  SELECT * INTO old_g FROM public.security_action_grants WHERE id=p_grant AND business_id=b AND scope_kind='business' FOR UPDATE;
  IF NOT FOUND OR old_g.subject_user_id<>recipient OR old_g.action<>p_action OR (old_g.parent_grant_id IS DISTINCT FROM selected AND NOT(old_g.id=selected AND old_g.subject_user_id=actor)) THEN RAISE EXCEPTION 'Exact issuer or own surrender authority required' USING ERRCODE='42501'; END IF;
  IF old_g.version<>p_expected OR old_g.status<>'active' THEN RAISE EXCEPTION 'Grant changed' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.start_command(actor,p_session,p_command,'business.grant.revoke',jsonb_build_object('business_id',b,'grant_id',p_grant),proof);
  g:=old_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(old_g),to_jsonb(g));
  UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 END IF;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.principal(p_session);
 -- Surrender is authorized by the locked before-image; every other effect requalifies the unchanged issuer path.
 IF NOT(p_effect='revoke' AND p_grant=selected) THEN PERFORM builderad_private.require_business(b,actor,p_action,p_selection,p_session); END IF;
 result:=jsonb_build_object('business_public_id',p_business,'grant_id',g.id,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.grant.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
