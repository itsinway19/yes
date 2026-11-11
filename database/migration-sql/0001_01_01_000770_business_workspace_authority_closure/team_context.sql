-- Forward from760: preserve creator administration and admit one exact v2 direct custodian qualification.
CREATE OR REPLACE FUNCTION builderad_private.business_team_context(p_session text,p_business uuid,p_read bigint,p_write boolean DEFAULT false,p_recipient bigint DEFAULT NULL,p_extra_grants bigint[] DEFAULT '{}') RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;roots bigint[];requirements jsonb;footprint jsonb;proof jsonb;root public.security_action_grants;refs jsonb:='{}';children bigint[];qualifications uuid[];custodian_qualification uuid;
BEGIN
 b:=builderad_private.lock_business(p_business,p_write);actor:=builderad_private.principal(p_session,false);
 -- Select the administration family from the exact requested read root before
 -- collecting one combined lock footprint. A staff child is never an admin root.
 SELECT q.qualification_id INTO custodian_qualification FROM public.security_action_grants g
 JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.id=p_read AND g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business' AND g.action='business.context.read'
  AND g.parent_grant_id IS NULL AND g.delegable AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL
  AND g.status='active' AND g.revoked_at IS NULL AND g.source='qualified_policy'
  AND q.policy_id='internal_client_file_admin_v1' AND q.policy_version=2 AND q.issuer_kind='qualified_policy'
  AND q.evidence_ref='business-custody:'||p_business::text AND q.agency_business_id IS NULL;
 IF custodian_qualification IS NOT NULL THEN
  SELECT array_agg(g.id ORDER BY g.id) INTO roots FROM public.security_action_grants g
  WHERE g.qualification_id=custodian_qualification AND g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business'
   AND g.parent_grant_id IS NULL AND g.delegable AND g.delegation_realm_kind='direct' AND g.delegation_workspace_id IS NULL
   AND g.status='active' AND g.revoked_at IS NULL AND g.source='qualified_policy'
   AND g.action IN('business.context.read','business.context.manage','business.intent.declare','business.markets.declare','business.preferences.manage');
 ELSE
  IF actor<>(SELECT created_by FROM public.businesses WHERE id=b) THEN RAISE EXCEPTION 'Original Business team administrator or explicit direct custodian required' USING ERRCODE='42501'; END IF;
 SELECT array_agg(g.id ORDER BY g.id) INTO roots FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
 WHERE g.business_id=b AND g.subject_user_id=actor AND g.scope_kind='business' AND g.parent_grant_id IS NULL AND g.delegation_realm_kind='direct'
  AND g.delegation_workspace_id IS NULL AND g.status='active' AND g.revoked_at IS NULL AND g.delegable
  AND q.policy_id='business_declaration_team_admin_v1' AND q.agency_business_id IS NULL;
 END IF;
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
 IF custodian_qualification IS NULL THEN
  IF proof#>>'{qualification,policy_id}' NOT IN('self_declared_draft','business_declaration_team_admin_v1') THEN RAISE EXCEPTION 'Independent team read required' USING ERRCODE='42501'; END IF;
 ELSE
  IF proof#>>'{qualification,qualification_id}' IS DISTINCT FROM custodian_qualification::text
   OR proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'internal_client_file_admin_v1'
   OR proof#>>'{qualification,policy_version}' IS DISTINCT FROM '2'
   OR proof#>>'{qualification,issuer_kind}' IS DISTINCT FROM 'qualified_policy'
   OR proof#>>'{qualification,evidence_ref}' IS DISTINCT FROM 'business-custody:'||p_business::text
  THEN RAISE EXCEPTION 'Exact current direct Business custodian required' USING ERRCODE='42501'; END IF;
 END IF;
 FOR root IN SELECT * FROM public.security_action_grants WHERE id=ANY(roots) ORDER BY id LOOP
  PERFORM builderad_private.require_business(b,actor,root.action,builderad_private.business_selection(root.id),p_session);
  refs:=refs||jsonb_build_object(root.action,root.id::text);
 END LOOP;
 RETURN jsonb_build_object('business_id',b,'actor',actor,'roots',refs,'proof',proof);
END $$;
