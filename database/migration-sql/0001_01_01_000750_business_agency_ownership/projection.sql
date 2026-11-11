-- A narrowly qualified preparation projection, not a public agency directory.
CREATE FUNCTION builderad_v2_business_agency_options(p_session text,p_business uuid,p_read bigint,p_agency uuid,p_recipient uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;agency_id bigint;actor bigint;recipient_id bigint;membership bigint;mode text;selection jsonb;requirements jsonb;
 read_proof jsonb;basis_proof jsonb;eligibility jsonb;options jsonb:='[]';grant_row public.security_action_grants;
 read_qualification public.authority_qualification_receipts;candidate_ids bigint[];deadline timestamptz;
BEGIN
 IF p_read IS NULL OR p_read<1 OR p_agency IS NULL THEN RAISE EXCEPTION 'Exact Business and agency required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,false);actor:=builderad_private.principal(p_session,false);
 selection:=builderad_private.business_selection(p_read);
 SELECT q.* INTO read_qualification FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.id=p_read AND g.subject_user_id=actor AND g.business_id=b AND g.scope_kind='business' AND g.action='business.context.read';
 SELECT id INTO agency_id FROM public.businesses WHERE public_id=p_agency;
 IF NOT FOUND OR agency_id=b OR read_qualification.qualification_id IS NULL THEN RAISE EXCEPTION 'Qualified agency context required' USING ERRCODE='42501'; END IF;
 IF actor=(SELECT created_by FROM public.businesses WHERE id=b)
  AND read_qualification.policy_id IN('self_declared_draft','business_declaration_team_admin_v1') THEN
  mode:='mandate';
  IF p_recipient IS NOT NULL THEN RAISE EXCEPTION 'Client selects an agency Business, not an arbitrary employee' USING ERRCODE='22023'; END IF;
  SELECT created_by INTO recipient_id FROM public.businesses WHERE id=agency_id;
  SELECT g.id INTO membership FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
   WHERE g.business_id=agency_id AND g.subject_user_id=recipient_id AND g.scope_kind='business' AND g.action='business.context.read'
    AND g.source='business_draft_creation' AND g.parent_grant_id IS NULL AND q.policy_id='self_declared_draft' ORDER BY g.id LIMIT 1;
 ELSE
  IF p_recipient IS NULL OR read_qualification.policy_id<>'business_agency_mandate_v1' OR read_qualification.agency_business_id IS DISTINCT FROM agency_id
  THEN RAISE EXCEPTION 'Exact current agency mandate and named employee required' USING ERRCODE='42501'; END IF;
  mode:='assign';SELECT id INTO recipient_id FROM public.users WHERE public_id=p_recipient;
  SELECT child.id INTO membership FROM public.security_action_grants child JOIN public.security_action_grants parent ON parent.id=child.parent_grant_id
   JOIN public.authority_qualification_receipts q ON q.qualification_id=parent.qualification_id
   WHERE child.business_id=agency_id AND child.subject_user_id=recipient_id AND child.action='business.context.read' AND child.scope_kind='business'
    AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
    AND q.policy_id='business_declaration_team_admin_v1' AND parent.parent_grant_id IS NULL ORDER BY child.id DESC LIMIT 1;
 END IF;
 IF membership IS NULL OR recipient_id IS NULL OR recipient_id=actor THEN RAISE EXCEPTION 'Current independent named recipient required' USING ERRCODE='42501'; END IF;
 -- No recipient metadata is returned until both client read and employee bases qualify.
 eligibility:=builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 SELECT coalesce(array_agg(g.id ORDER BY g.id),'{}') INTO candidate_ids FROM(
  SELECT x.id FROM public.security_action_grants x JOIN public.authority_qualification_receipts q ON q.qualification_id=x.qualification_id
  WHERE x.business_id=b AND x.scope_kind='business' AND x.subject_user_id=actor AND x.delegable AND x.status='active' AND x.revoked_at IS NULL
   AND x.action IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage')
   AND((mode='mandate' AND q.policy_id='business_declaration_team_admin_v1' AND x.parent_grant_id IS NULL)
    OR(mode='assign' AND q.policy_id='business_agency_mandate_v1' AND q.agency_business_id=agency_id)) ORDER BY x.id LIMIT 30
 ) g;
 SELECT jsonb_agg(jsonb_build_object('requirement_kind','business','leaf_grant_id',id) ORDER BY id) INTO requirements
  FROM(SELECT DISTINCT unnest(array_append(candidate_ids,p_read)) id) ids;
 PERFORM builderad_private.lock_agency_command(actor,jsonb_build_object('requirements',requirements),p_session,recipient_id,membership,agency_id);
 read_proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 eligibility:=builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE id=ANY(candidate_ids) ORDER BY id LOOP
  BEGIN
   basis_proof:=builderad_private.require_business(b,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
   SELECT min(value::timestamptz) INTO deadline FROM(
    SELECT item->>'expires_at' value FROM jsonb_array_elements((basis_proof->'grants')||(eligibility->'grants')) item
    UNION ALL SELECT item->>'valid_until' FROM jsonb_array_elements((basis_proof->'qualifications')||(eligibility->'qualifications')) item
   ) deadlines WHERE value IS NOT NULL;
   options:=options||jsonb_build_array(jsonb_build_object('action',grant_row.action,'parent_grant_id',grant_row.id,
    'authority_selection',builderad_private.business_selection(grant_row.id),'max_expires_at',deadline));
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE; END;
 END LOOP;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 PERFORM builderad_private.business_agency_eligibility(agency_id,recipient_id,membership);
 RETURN jsonb_build_object('business_public_id',p_business,'agency_business_public_id',p_agency,'effect',mode,
  'recipient',jsonb_build_object('public_id',(SELECT public_id FROM public.users WHERE id=recipient_id),'membership_grant_id',membership),
  'options',options,'scope','current_declaration_agency_only');
END $$;
REVOKE ALL ON FUNCTION builderad_v2_business_agency_options(text,uuid,bigint,uuid,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_agency_options(text,uuid,bigint,uuid,uuid) TO builderad_app;
