CREATE OR REPLACE FUNCTION builderad_v2_internal_team_surface(p_session text,p_business uuid,p_read bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;selection jsonb;creator_basis boolean;can_admin boolean;can_appoint boolean;teams jsonb;custodians jsonb;g public.security_action_grants;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 SELECT * INTO g FROM public.security_action_grants WHERE id=p_read;
 creator_basis:=proof#>>'{qualification,policy_id}'='self_declared_draft' AND proof#>>'{qualification,policy_version}'='1'
  AND proof#>>'{qualification,issuer_kind}'='self_declared_draft' AND actor=(SELECT created_by FROM public.businesses WHERE id=b);
 can_admin:=creator_basis OR(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND g.parent_grant_id IS NULL AND g.delegable);
 can_appoint:=creator_basis OR(proof#>>'{qualification,policy_id}'='internal_client_file_admin_v1' AND proof#>>'{qualification,policy_version}'='2' AND selection->>'realm_kind'='direct' AND g.parent_grant_id IS NULL AND g.delegable);
 SELECT coalesce(jsonb_agg(jsonb_build_object('link_ref',l.link_id,'workspace_public_id',w.public_id,'workspace_slug',w.slug,'state',l.status,'revision',l.revision) ORDER BY w.id),'[]')
 INTO teams FROM(SELECT DISTINCT ON(workspace_id) * FROM public.business_workspace_links WHERE business_id=b AND kind='internal_team'
  ORDER BY workspace_id,revision DESC,link_id DESC) l JOIN public.workspaces w ON w.id=l.workspace_id
 WHERE creator_basis OR(selection->>'realm_kind'='internal_team' AND g.delegation_workspace_id=l.workspace_id);
 SELECT coalesce(jsonb_agg(jsonb_build_object('public_id',u.public_id,'name',u.name,'email',u.email,'qualification_ref',q.qualification_id,'status','recorded',
  'grant_refs',(SELECT jsonb_object_agg(sg.action,sg.id::text) FROM public.security_action_grants sg WHERE sg.qualification_id=q.qualification_id AND sg.status='active' AND sg.revoked_at IS NULL)) ORDER BY u.id),'[]') INTO custodians
 FROM public.authority_qualification_receipts q JOIN public.users u ON u.id=q.subject_user_id
 WHERE can_appoint AND q.target_kind='business' AND q.target_ref=p_business AND q.policy_id='internal_client_file_admin_v1' AND q.policy_version=2
  AND q.evidence_ref='business-custody:'||p_business::text AND q.status='active';
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'creator_basis',creator_basis,'can_admin',can_admin,'can_appoint',can_appoint,'teams',teams,'custodians',custodians,'versions',jsonb_build_object('authority_epoch',(SELECT auth_epoch FROM public.business_authority_fences WHERE business_id=b)));
END $$;
