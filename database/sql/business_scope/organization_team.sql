-- Workspace remains the organizational membership/resource owner. No Business
-- or source/data right is created by these commands.
CREATE FUNCTION builderad_private.organization_actor(s text,w uuid,recipient uuid,write_mode boolean,fresh boolean) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;wid bigint;owner_id bigint;rid bigint;view_grant public.security_action_grants;member public.workspace_memberships;
 authenticated timestamptz;ids bigint[];
BEGIN
 actor:=builderad_private.principal(s,false);
 SELECT x.id,o.responsible_owner_user_id INTO wid,owner_id FROM public.workspaces x JOIN public.workspace_ownerships o ON o.workspace_id=x.id WHERE x.public_id=w;
 SELECT id INTO rid FROM public.users WHERE public_id=recipient;
 IF wid IS NULL OR(recipient IS NOT NULL AND rid IS NULL) THEN RAISE EXCEPTION 'Organization scope denied' USING ERRCODE='42501'; END IF;
 ids:=ARRAY[actor,owner_id,rid];
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=ANY(ids) ORDER BY user_id FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=ANY(ids) ORDER BY id FOR SHARE;
 IF write_mode THEN PERFORM 1 FROM public.workspaces WHERE id=wid FOR UPDATE;
 ELSE PERFORM 1 FROM public.workspaces WHERE id=wid FOR SHARE; END IF;
 IF write_mode THEN PERFORM 1 FROM public.workspace_memberships WHERE workspace_id=wid AND user_id=ANY(ids) ORDER BY user_id FOR UPDATE;
 ELSE PERFORM 1 FROM public.workspace_memberships WHERE workspace_id=wid AND user_id=ANY(ids) ORDER BY user_id FOR SHARE; END IF;
 PERFORM 1 FROM public.workspace_ownerships WHERE workspace_id=wid FOR SHARE;
 PERFORM 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=s FOR SHARE;
 SELECT * INTO view_grant FROM public.security_action_grants WHERE subject_user_id=actor AND workspace_id=wid AND scope_kind='workspace'
  AND action='workspace.view' AND subject_generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
  AND status='active' AND revoked_at IS NULL AND(expires_at IS NULL OR expires_at>clock_timestamp()) ORDER BY id LIMIT 1 FOR SHARE;
 PERFORM builderad_private.principal(s,false);
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=s;
 SELECT * INTO member FROM public.workspace_memberships WHERE workspace_id=wid AND user_id=actor;
 -- Members can read/surrender their own membership even when Workspace view
 -- was never granted or was withdrawn. This releases no other member metadata.
 IF(view_grant.id IS NULL AND actor=owner_id) OR member.status IS DISTINCT FROM 'active' OR NOT EXISTS(SELECT 1 FROM public.workspaces WHERE id=wid AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.workspace_ownerships o JOIN public.workspace_memberships m ON m.workspace_id=o.workspace_id AND m.user_id=o.responsible_owner_user_id
  JOIN public.principal_lifecycles l ON l.user_id=m.user_id JOIN public.users u ON u.id=l.user_id
  WHERE o.workspace_id=wid AND o.responsible_owner_user_id=owner_id AND m.role='owner' AND m.status='active' AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
 OR(fresh AND(authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()))
 THEN RAISE EXCEPTION 'Current organizational membership and authority required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('actor',actor,'workspace_id',wid,'recipient',rid,'owner',owner_id,'is_owner',actor=owner_id AND member.role='owner',
  'grants',CASE WHEN view_grant.id IS NULL THEN '[]'::jsonb ELSE jsonb_build_array(to_jsonb(view_grant)) END,
  'freshness',CASE WHEN fresh THEN jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes') ELSE '{}'::jsonb END);
END $$;

CREATE FUNCTION builderad_v2_organization_team(s text,w uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE proof jsonb;rows_value jsonb;revision_value bigint;
BEGIN
 proof:=builderad_private.organization_actor(s,w,NULL,false,false);
 SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',u.public_id,'role',m.role,'state',m.status) ORDER BY m.user_id),'[]') INTO rows_value
 FROM public.workspace_memberships m JOIN public.users u ON u.id=m.user_id
 WHERE m.workspace_id=(proof->>'workspace_id')::bigint AND((proof->>'is_owner')::boolean OR m.user_id=(proof->>'actor')::bigint);
 SELECT revision INTO revision_value FROM public.organization_team_revisions WHERE workspace_id=(proof->>'workspace_id')::bigint;
 PERFORM builderad_private.organization_actor(s,w,NULL,false,false);
 RETURN jsonb_build_object('workspace_public_id',w,'revision',coalesce(revision_value,0),'can_manage',(proof->>'is_owner')::boolean,
  'actor_public_id',(SELECT public_id FROM public.users WHERE id=(proof->>'actor')::bigint),'can_leave',NOT(proof->>'is_owner')::boolean,'members',rows_value);
END $$;

CREATE FUNCTION builderad_v2_organization_team_command(s text,w uuid,c uuid,effect text,recipient uuid,expected bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE proof jsonb;actor bigint;wid bigint;rid bigint;payload bytea;result jsonb;old_member public.workspace_memberships;new_member public.workspace_memberships;
 old_revision public.organization_team_revisions;new_revision public.organization_team_revisions;g public.security_action_grants;joined_at timestamptz;
BEGIN
 IF c IS NULL OR recipient IS NULL OR effect IS NULL OR effect NOT IN('add','remove','leave') OR expected IS NULL OR expected<0
 THEN RAISE EXCEPTION 'Exact organization membership command required' USING ERRCODE='22023'; END IF;
 proof:=builderad_private.organization_actor(s,w,recipient,true,true);actor:=(proof->>'actor')::bigint;wid:=(proof->>'workspace_id')::bigint;rid:=(proof->>'recipient')::bigint;
 IF(effect='leave' AND(rid<>actor OR(proof->>'is_owner')::boolean)) OR(effect<>'leave' AND NOT(proof->>'is_owner')::boolean)
 OR rid=(proof->>'owner')::bigint THEN RAISE EXCEPTION 'Owner manages membership; members may surrender only themselves' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('workspace',w,'effect',effect,'recipient',recipient,'expected',expected),'{"workspace":"U","recipient":"U","expected":"I"}');
 result:=builderad_private.command_replay(NULL,actor,c,'organization.team.'||effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO old_revision FROM public.organization_team_revisions WHERE workspace_id=wid FOR UPDATE;
 IF coalesce(old_revision.revision,0)<>expected THEN RAISE EXCEPTION 'Organization membership changed; review current revision' USING ERRCODE='B4090'; END IF;
 SELECT * INTO old_member FROM public.workspace_memberships WHERE workspace_id=wid AND user_id=rid;
 IF(effect='add' AND old_member.status='active') OR(effect<>'add' AND old_member.status IS DISTINCT FROM 'active')
 THEN RAISE EXCEPTION 'Membership state changed' USING ERRCODE='B4090'; END IF;
 IF effect='add' AND NOT EXISTS(SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id WHERE l.user_id=rid AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL)
 THEN RAISE EXCEPTION 'Current verified registered employee required' USING ERRCODE='42501'; END IF;
 IF effect='add' THEN
  joined_at:=clock_timestamp();
  -- Fail closed if clock ordering cannot distinguish this membership from any
  -- earlier organizational Business grant. Never let a backwards clock revive it.
  IF EXISTS(SELECT 1 FROM public.security_action_grants prior WHERE prior.scope_kind='business' AND prior.subject_user_id=rid
   AND prior.delegation_workspace_id=wid AND prior.created_at>=joined_at)
  THEN RAISE EXCEPTION 'Membership incarnation clock ordering unavailable' USING ERRCODE='42501'; END IF;
  SELECT * INTO g FROM public.security_action_grants WHERE subject_user_id=rid AND workspace_id=wid AND scope_kind='workspace' AND action='workspace.view'
   AND subject_generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=rid) FOR SHARE;
  IF g.id IS NOT NULL AND(g.status<>'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp()))
  THEN RAISE EXCEPTION 'Revoked or expired organizational view rights cannot be revived' USING ERRCODE='42501'; END IF;
 END IF;
 proof:=proof||jsonb_build_object('membership_before',to_jsonb(old_member),'recipient_view',to_jsonb(g));
 PERFORM builderad_private.start_command(actor,s,c,'organization.team.'||effect,jsonb_build_object('workspace_id',wid),proof);
 new_member:=old_member;new_member.workspace_id:=wid;new_member.user_id:=rid;
 IF effect='add' THEN new_member.role:='member';new_member.status:='active';new_member.created_at:=joined_at;new_member.revoked_at:=NULL;new_member.scope_lock_marker:=false;new_member.joined_revision:=expected+1;
 ELSE new_member.status:='revoked';new_member.revoked_at:=clock_timestamp(); END IF;
 IF old_member.user_id IS NULL THEN
  PERFORM builderad_private.permit('public.workspace_memberships','insert',NULL,to_jsonb(new_member));INSERT INTO public.workspace_memberships SELECT(new_member).*;
 ELSE
  PERFORM builderad_private.permit('public.workspace_memberships','update',to_jsonb(old_member),to_jsonb(new_member));
  UPDATE public.workspace_memberships SET role=new_member.role,status=new_member.status,created_at=new_member.created_at,revoked_at=new_member.revoked_at,joined_revision=new_member.joined_revision WHERE workspace_id=wid AND user_id=rid;
 END IF;
 IF effect='add' AND g.id IS NULL THEN
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=rid;g.granted_by:=actor;g.source:='human';g.action:='workspace.view';g.scope_kind:='workspace';g.workspace_id:=wid;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=rid;
  g.reason:='Explicit organizational membership only';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.delegable:=false;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 END IF;
 -- Departure withdraws the membership prerequisite. It does not rewrite a
 -- separately revoked/expired grant, delete history or acquire Business rights.
 new_revision.workspace_id:=wid;new_revision.revision:=expected+1;
 IF old_revision.workspace_id IS NULL THEN
  PERFORM builderad_private.permit('public.organization_team_revisions','insert',NULL,to_jsonb(new_revision));INSERT INTO public.organization_team_revisions SELECT(new_revision).*;
 ELSE
  PERFORM builderad_private.permit('public.organization_team_revisions','update',to_jsonb(old_revision),to_jsonb(new_revision));UPDATE public.organization_team_revisions SET revision=new_revision.revision WHERE workspace_id=wid;
 END IF;
 result:=jsonb_build_object('workspace_public_id',w,'recipient_public_id',recipient,'state',new_member.status,'versions',jsonb_build_object('team',new_revision.revision));
 PERFORM builderad_private.record_command(NULL,actor,c,'organization.team.'||effect,payload,result);
 PERFORM builderad_private.assert_invocation_current();PERFORM builderad_private.finish_command();RETURN result;
END $$;
