-- An isolated deployment command. Runtime and authority connections cannot issue source rights.
CREATE FUNCTION builderad_v2_market_knowledge_enroll(p_command uuid,p_source uuid,p_expected bigint,p_resource text,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE c public.market_source_custody;prior public.market_source_custody;src public.source_registry;f public.resource_right_fences;
 s public.data_use_scopes;q public.authority_qualification_receipts;g public.security_action_grants;
 proof jsonb;payload bytea;result jsonb;old public.business_command_receipts;actor bigint;generation bigint;existing_scope uuid;op text;purpose text;
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Isolated market licensing authority required' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_source IS NULL OR p_expected IS NULL OR p_expected<1 OR p_resource IS NULL OR p_resource NOT IN('market.evidence','market.knowledge','market.business_finding') THEN RAISE EXCEPTION 'Typed market enrollment required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(p_payload);
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 SELECT * INTO prior FROM public.market_source_custody WHERE source_id=p_source;
 IF prior.source_id IS NULL OR prior.revision<>p_expected THEN RAISE EXCEPTION 'Market custody changed' USING ERRCODE='40001'; END IF;
 payload:=builderad_private.digest('market-maintenance:1',jsonb_build_object('resource',p_resource,'source',p_source,'expected',p_expected,'payload',p_payload),'{}');
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.actor_user_id IS NOT NULL OR old.action IS DISTINCT FROM 'maintenance.market.knowledge.enroll' OR old.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Enrollment command conflict' USING ERRCODE='40001'; END IF;
  RETURN old.result_ref;
 END IF;
 proof:=jsonb_build_object('operator_role',session_user,'source_id',p_source);
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.market.knowledge.enroll',
  jsonb_build_object('source_id',p_source),proof,builderad_private.authority_digest(proof),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
  PERFORM builderad_private.market_keys(p_payload,ARRAY['scope_id','subject_ref','subject_generation','operation','purpose','selection','valid_until','evidence_ref','evidence_digest']);
  s.scope_id:=(p_payload->>'scope_id')::uuid;op:=p_payload->>'operation';purpose:=p_payload->>'purpose';
  SELECT u.id,l.generation INTO actor,generation FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
   WHERE u.public_id=(p_payload->>'subject_ref')::uuid AND ((l.principal_class='staff' AND builderad_private.market_staff_eligible(u.id)) OR(l.principal_class='advertiser' AND p_resource<>'market.evidence')) AND l.status='current' AND u.email_verified_at IS NOT NULL FOR SHARE OF u,l;
  IF actor IS NULL OR generation IS DISTINCT FROM (p_payload->>'subject_generation')::bigint OR prior.status<>'active' OR prior.valid_from>clock_timestamp()
  OR(prior.valid_until IS NOT NULL AND prior.valid_until<=clock_timestamp()) OR NOT op=ANY(prior.allowed_operations) OR NOT purpose=ANY(prior.allowed_purposes)
  OR coalesce(p_payload->>'evidence_digest','') !~ '^[0-9a-f]{64}$'
  THEN RAISE EXCEPTION 'Qualified current recipient and policy required' USING ERRCODE='42501'; END IF;
  s.source_id:=p_source;s.resource:=p_resource;s.selection:=builderad_private.canonical_data_selection(p_payload->'selection');s.selection_schema_version:=1;
  s.operation:=op;s.purpose:=purpose;s.scope_digest:=builderad_private.market_scope_digest(p_source,s.resource,op,purpose,s.selection);s.status:='active';s.revision:=1;s.rights_domain:='market';
  IF s.selection->'native_namespaces' IS DISTINCT FROM jsonb_build_array(p_resource)
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') k WHERE NOT k=ANY(prior.allowed_fields))
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'native_keys') k WHERE k !~ '^[0-9a-f-]{36}$')
  THEN RAISE EXCEPTION 'Exact market selection required' USING ERRCODE='42501'; END IF;
  -- A scope is a resource contract, not an individual's grant. Independently licensed
  -- recipients may use the same contract; their grants remain separate.
  SELECT existing.scope_id INTO existing_scope FROM public.data_use_scopes existing WHERE existing.scope_digest=s.scope_digest;
  IF existing_scope IS NULL THEN
   PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
   f.fence_id:=uuidv7();f.kind:='data_use';f.source_id:=NULL;f.boundary_id:=NULL;f.data_use_scope_id:=s.scope_id;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
   PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
  ELSE s.scope_id:=existing_scope;END IF;
  q.qualification_id:=uuidv7();q.policy_id:=prior.policy_id;q.policy_version:=prior.policy_version;q.issuer_kind:='qualified_policy';q.subject_user_id:=actor;
  q.target_kind:='data_use';q.target_ref:=s.scope_id;q.allowed_actions:=ARRAY['data_use.'||op];q.evidence_ref:=p_payload->>'evidence_ref';q.evidence_digest:=decode(p_payload->>'evidence_digest','hex');
  q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=(p_payload->>'valid_until')::timestamptz;q.provenance:='Explicit isolated market licence enrollment; not a legal verifier';
  IF prior.valid_until IS NOT NULL AND(q.valid_until IS NULL OR q.valid_until>prior.valid_until) THEN RAISE EXCEPTION 'Enrollment cannot outlive source rights' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.subject_generation:=generation;g.source:='qualified_policy';g.scope_kind:='data_use';g.data_use_scope_id:=s.scope_id;
  g.qualification_id:=q.qualification_id;g.action:='data_use.'||op;g.reason:='Qualified market source operation';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.expires_at:=q.valid_until;g.delegable:=false;g.delegation_realm_kind:='direct';g.runtime_lock_marker:=false;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  result:=jsonb_build_object('scope_id',s.scope_id,'grant_id',g.id,'qualification_id',q.qualification_id,'versions',jsonb_build_object('grant',1));

 PERFORM builderad_private.record_command(NULL,NULL,p_command,'maintenance.market.knowledge.enroll',payload,result);
 IF(prior.valid_until IS NOT NULL AND prior.valid_until<=clock_timestamp()) OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp()) THEN RAISE EXCEPTION 'Market enrollment expired at final effect' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_knowledge_enroll(uuid,uuid,bigint,text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

GRANT EXECUTE ON FUNCTION builderad_v2_market_knowledge_enroll(uuid,uuid,bigint,text,jsonb) TO builderad_owner;

-- Isolated licence withdrawal for these exact roots. It requires no fresh data-use permission,
-- creates no new right, and remains unavailable to both runtime and the PHP attestation issuer.
CREATE FUNCTION builderad_v2_market_knowledge_withdraw(p_command uuid,p_scope uuid,p_grant bigint,p_expected bigint,p_reason text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE s public.data_use_scopes;g public.security_action_grants;prior public.security_action_grants;f record;gate uuid;proof jsonb;payload bytea;old public.business_command_receipts;result jsonb;
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Isolated market licensing authority required' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_scope IS NULL OR p_grant IS NULL OR p_expected IS NULL OR p_expected<1 OR p_reason IS NULL OR length(p_reason) NOT BETWEEN 1 AND 600 THEN RAISE EXCEPTION 'Exact bounded licence withdrawal required' USING ERRCODE='22023'; END IF;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope AND rights_domain='market' AND resource IN('market.evidence','market.knowledge','market.business_finding');
 IF s.scope_id IS NULL THEN RAISE EXCEPTION 'Declared market resource required' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||s.source_id,0));
 FOR f IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id) ORDER BY fence_id FOR UPDATE LOOP
  IF f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'Qualified market resource fence required' USING ERRCODE='42501'; END IF;
  IF f.kind='data_use' THEN gate:=f.fence_id;END IF;
 END LOOP;
 IF gate IS NULL OR NOT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=s.source_id AND adoption_state='v2_adopted') THEN RAISE EXCEPTION 'Complete adopted resource gates required' USING ERRCODE='42501'; END IF;
 payload:=sha256(convert_to(jsonb_build_object('scope',p_scope,'grant',p_grant,'expected',p_expected,'reason',p_reason)::text,'UTF8'));
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.actor_user_id IS NOT NULL OR old.action IS DISTINCT FROM 'maintenance.market.knowledge.withdraw' OR old.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Licence withdrawal command conflict' USING ERRCODE='40001'; END IF;
  RETURN old.result_ref;
 END IF;
 SELECT * INTO prior FROM public.security_action_grants WHERE id=p_grant AND scope_kind='data_use' AND data_use_scope_id=p_scope FOR UPDATE;
 IF prior.id IS NULL OR prior.parent_grant_id IS NOT NULL OR prior.source IS DISTINCT FROM 'qualified_policy' OR prior.action IS DISTINCT FROM 'data_use.'||s.operation THEN RAISE EXCEPTION 'Exact qualified market root required' USING ERRCODE='42501'; END IF;
 IF prior.version<>p_expected OR prior.status<>'active' THEN RAISE EXCEPTION 'Licence root changed' USING ERRCODE='40001'; END IF;
 proof:=jsonb_build_object('operator_role',session_user,'source_id',s.source_id,'scope_id',s.scope_id,'withdrawn_grant_id',prior.id,'before_image_digest',encode(sha256(convert_to(to_jsonb(prior)::text,'UTF8')),'hex'));
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.market.knowledge.withdraw',jsonb_build_object('scope_id',s.scope_id,'grant_id',p_grant),proof,builderad_private.authority_digest(proof),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 g:=prior;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
 PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior),to_jsonb(g));
 UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
 PERFORM builderad_private.advance_right_epoch(gate);
 result:=jsonb_build_object('grant_id',g.id,'scope_id',s.scope_id,'status','revoked','versions',jsonb_build_object('grant',g.version));
 PERFORM builderad_private.record_command(NULL,NULL,p_command,'maintenance.market.knowledge.withdraw',payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_knowledge_withdraw(uuid,uuid,bigint,bigint,text) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_knowledge_withdraw(uuid,uuid,bigint,bigint,text) TO builderad_owner;
