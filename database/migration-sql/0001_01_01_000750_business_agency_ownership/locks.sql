CREATE FUNCTION builderad_private.lock_authority_rows(p_actor bigint,p_session text,footprint jsonb) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE agency_ids bigint[];user_ids bigint[];workspace_ids bigint[];grant_ids bigint[];qualification_ids uuid[];
BEGIN
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'users')) INTO user_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'workspaces')) INTO workspace_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) INTO grant_ids;
 SELECT ARRAY(SELECT value::uuid FROM jsonb_array_elements_text(footprint->'qualifications'))||'{}'::uuid[] INTO qualification_ids;
 SELECT ARRAY(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'agency_businesses')) INTO agency_ids;
 PERFORM 1 FROM public.migration_cohort_control c WHERE c.cohort_id IN(SELECT m.cohort_id FROM public.migration_cohort_members m WHERE m.business_id=ANY(agency_ids)) ORDER BY c.cohort_id FOR SHARE;
 PERFORM 1 FROM public.businesses WHERE id=ANY(agency_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=ANY(user_ids) ORDER BY user_id FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=ANY(user_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.workspaces WHERE id=ANY(workspace_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.workspace_memberships WHERE workspace_id=ANY(workspace_ids) AND user_id=ANY(user_ids) ORDER BY workspace_id,user_id FOR SHARE;
 PERFORM 1 FROM public.workspace_ownerships WHERE workspace_id=ANY(workspace_ids) ORDER BY workspace_id FOR SHARE;
 IF p_session IS NULL THEN RAISE EXCEPTION 'Explicit current session required' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM public.security_sessions WHERE user_id=p_actor AND session_key_hash=p_session ORDER BY session_key_hash FOR SHARE;
 PERFORM 1 FROM public.security_action_grants WHERE id=ANY(grant_ids) ORDER BY id FOR SHARE;
 PERFORM 1 FROM public.authority_qualification_receipts WHERE qualification_id=ANY(qualification_ids) ORDER BY qualification_id FOR SHARE;

END $$;
REVOKE ALL ON FUNCTION builderad_private.lock_authority_rows(bigint,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
