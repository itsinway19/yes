-- Explicit assignment of the unchanged finite licence. No Source or Business grant is issued.
CREATE FUNCTION builderad_v2_data_use_beneficiary(p_session text,p_business uuid,p_command uuid,p_license uuid,p_effect text,p_recipient uuid,p_until timestamptz,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;recipient bigint;b bigint;s public.data_use_scopes;boundary_row public.client_data_boundaries;
 business_proof jsonb;boundary_proof jsonb;proof jsonb;payload bytea;result jsonb;parent public.security_action_grants;recipient_business public.security_action_grants;
 prior public.security_action_grants;g public.security_action_grants;q public.authority_qualification_receipts;prior_q public.authority_qualification_receipts;
 gate record;gate_count integer:=0;expiry timestamptz;authenticated timestamptz;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_license IS NULL OR p_recipient IS NULL OR p_expected IS NULL OR p_expected<0
 OR p_effect IS NULL OR p_effect NOT IN('assign','revoke') OR p_until IS NULL OR NOT isfinite(p_until)
 THEN RAISE EXCEPTION 'Exact beneficiary command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT id INTO recipient FROM public.users WHERE public_id=p_recipient;
 SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_license;
 SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
 IF recipient IS NULL OR recipient=actor OR b IS NULL OR boundary_row.descriptor->>'policy_id' IS DISTINCT FROM 'declared_data_license_v1'
 OR boundary_row.descriptor->>'business_public_id' IS DISTINCT FROM p_business::text OR boundary_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text
 OR boundary_row.descriptor->>'declarant_generation' IS DISTINCT FROM(SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)
 THEN RAISE EXCEPTION 'Original qualified data authority and exact Business required' USING ERRCODE='42501'; END IF;
 IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>2
 THEN RAISE EXCEPTION 'Independent Business use and data authority required' USING ERRCODE='42501'; END IF;
 FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id LOOP
  IF gate.kind='client_boundary' THEN PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;
  ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE;END IF;
  IF gate.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  gate_count:=gate_count+1;
 END LOOP;
 IF gate_count<>2 THEN RAISE EXCEPTION 'Existing licence resource gates required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_business(p_business,false);
 business_proof:=builderad_private.require_scope('business',b::text,actor,'business.data.use',p_selection,p_session,ARRAY[recipient]);
 boundary_proof:=builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session,ARRAY[recipient]);
 SELECT * INTO STRICT parent FROM public.security_action_grants WHERE id=(business_proof#>>'{grants,0,id}')::bigint;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit beneficiary confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','data_license_beneficiary','business',p_business,'license',p_license,'effect',p_effect,
  'recipient',p_recipient,'until',p_until,'expected',p_expected,'selection',p_selection),'{"business":"U","license":"U","recipient":"U","until":"T","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'data_license.beneficiary.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT grant_row.* INTO prior FROM public.security_action_grants grant_row JOIN public.authority_qualification_receipts qualification ON qualification.qualification_id=grant_row.qualification_id
  WHERE grant_row.scope_kind='data_use' AND grant_row.data_use_scope_id=p_license AND grant_row.subject_user_id=recipient
  AND qualification.policy_id='declared_data_beneficiary_v1' ORDER BY grant_row.id DESC LIMIT 1 FOR UPDATE OF grant_row;
 IF coalesce(prior.version,0)<>p_expected THEN RAISE EXCEPTION 'Reviewed beneficiary assignment changed' USING ERRCODE='B4090'; END IF;
 IF p_effect='assign' THEN
  IF prior.id IS NOT NULL AND prior.status='active' AND prior.revoked_at IS NULL
  THEN RAISE EXCEPTION 'Reconcile or explicitly revoke existing beneficiary' USING ERRCODE='40001'; END IF;
  SELECT child.* INTO recipient_business FROM public.security_action_grants child JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=child.qualification_id
   JOIN public.principal_lifecycles lifecycle ON lifecycle.user_id=child.subject_user_id AND lifecycle.generation=child.subject_generation AND lifecycle.status='current' AND lifecycle.principal_class='advertiser'
   JOIN public.users u ON u.id=child.subject_user_id AND u.email_verified_at IS NOT NULL
   WHERE child.parent_grant_id=parent.id AND child.business_id=b AND child.scope_kind='business' AND child.action='business.data.use'
   AND child.subject_user_id=recipient AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
   AND child.delegation_realm_kind IS NOT DISTINCT FROM p_selection->>'realm_kind'
   AND child.delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid)
   AND qualified.status='active' AND qualified.issuer_kind='existing_grant' AND qualified.issuer_grant_id=parent.id
   AND qualified.valid_from<=clock_timestamp() AND(qualified.valid_until IS NULL OR qualified.valid_until>clock_timestamp()) ORDER BY child.id DESC LIMIT 1 FOR SHARE OF child;
  IF recipient_business.id IS NULL OR NOT parent.delegable OR s.status<>'active' OR boundary_row.status<>'active'
   OR s.operation NOT IN('retained_read','display')
  THEN RAISE EXCEPTION 'Explicit current Business-qualified beneficiary required' USING ERRCODE='42501'; END IF;
  IF recipient_business.delegation_workspace_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.workspace_memberships membership
   WHERE membership.workspace_id=recipient_business.delegation_workspace_id AND membership.user_id=recipient AND membership.status='active'
   AND(coalesce((to_jsonb(membership)->>'joined_revision')::bigint,0)=0 OR recipient_business.created_at>=membership.created_at))
  THEN RAISE EXCEPTION 'Current membership incarnation required' USING ERRCODE='42501'; END IF;
  expiry:=least((boundary_row.descriptor#>>'{declaration,valid_until}')::timestamptz,parent.expires_at,recipient_business.expires_at);
  IF s.resource='business.expenditure_summary' THEN
   SELECT least(expiry,valid_until) INTO expiry FROM public.business_expenditure_summaries WHERE output_scope_id=s.scope_id AND publication_manifest_id IS NOT NULL;
   IF expiry IS NULL THEN RAISE EXCEPTION 'Published finite expenditure summary required' USING ERRCODE='42501'; END IF;
  END IF;
  IF p_until<=clock_timestamp() OR p_until>expiry OR EXISTS(SELECT 1 FROM jsonb_array_elements(business_proof->'qualifications') row_value
   WHERE row_value->>'valid_until' IS NOT NULL AND p_until>(row_value->>'valid_until')::timestamptz)
  THEN RAISE EXCEPTION 'Beneficiary cannot exceed original authority or licence duration' USING ERRCODE='42501'; END IF;
 ELSE
  IF prior.id IS NULL OR prior.status<>'active' THEN RAISE EXCEPTION 'Exact active beneficiary required' USING ERRCODE='40001'; END IF;
 END IF;
 proof:=jsonb_build_object('business_authority',business_proof,'data_authority',boundary_proof,'recipient_business',to_jsonb(recipient_business),
  'confirmation_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
  'dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR f.boundary_id=s.boundary_id));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.beneficiary.'||p_effect,jsonb_build_object('scope_id',p_license,'recipient',recipient),proof);
 IF p_effect='assign' THEN
  q.qualification_id:=uuidv7();q.policy_id:='declared_data_beneficiary_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=recipient_business.id;
  q.subject_user_id:=recipient;q.target_kind:='data_use';q.target_ref:=p_license;q.allowed_actions:=ARRAY['data_use.'||s.operation];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;q.provenance:='Explicit named beneficiary of existing finite consent; no change to original grants, Source or Business authority; independent reader requalification required';
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=recipient;g.source:='qualified_policy';g.action:='data_use.'||s.operation;g.scope_kind:='data_use';g.data_use_scope_id:=p_license;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=recipient;
  g.reason:='Explicit finite named data beneficiary';g.status:='active';g.version:=p_expected+1;g.expires_at:=p_until;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=false;g.delegation_realm_kind:=recipient_business.delegation_realm_kind;g.delegation_workspace_id:=recipient_business.delegation_workspace_id;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 ELSE
  g:=prior;g.status:='revoked';g.version:=prior.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
  PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 END IF;
 PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.data.use',p_selection,p_session,ARRAY[recipient]);
 PERFORM builderad_private.require_scope('client_data',s.boundary_id::text,actor,'client_data.manage',p_selection,p_session,ARRAY[recipient]);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('license_id',p_license,'recipient_public_id',p_recipient,'grant_id',g.id::text,'status',g.status,'versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(b,actor,p_command,'data_license.beneficiary.'||p_effect,payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_data_use_beneficiary(text,uuid,uuid,uuid,text,uuid,timestamptz,bigint,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_data_use_beneficiary(text,uuid,uuid,uuid,text,uuid,timestamptz,bigint,jsonb) TO builderad_app;
