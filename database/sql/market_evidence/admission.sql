-- Deliberately not an application endpoint. Execute with an isolated deployment
-- connection; operational writes use the signed runtime command in evidence.sql.
CREATE FUNCTION builderad_v2_market_admit(p_command uuid,p_action text,p_source uuid,p_expected bigint,p_payload jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE c public.market_source_custody;prior public.market_source_custody;src public.source_registry;f public.resource_right_fences;
 s public.data_use_scopes;q public.authority_qualification_receipts;g public.security_action_grants;
 proof jsonb;payload bytea;result jsonb;old public.business_command_receipts;actor bigint;generation bigint;existing_scope uuid;op text;purpose text;
BEGIN
 IF session_user<>'builderad_owner' THEN RAISE EXCEPTION 'Isolated deployment operator required' USING ERRCODE='42501'; END IF;
 IF p_command IS NULL OR p_source IS NULL OR uuid_extract_version(p_source)<>7 OR p_expected IS NULL OR p_expected<0
 OR p_action IS NULL OR p_action NOT IN('provision_source','restrict_source','revoke_source','enroll_data_use') THEN RAISE EXCEPTION 'Typed market maintenance command required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(p_payload);
 -- Same admission mutex as native custody; no per-source lock can be acquired later out of rank.
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.source.admission.'||p_source,0));
 PERFORM 1 FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source FOR UPDATE;
 payload:=builderad_private.digest('market-maintenance:1',jsonb_build_object('action',p_action,'source',p_source,'expected',p_expected,'payload',p_payload),'{}');
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.actor_user_id IS NOT NULL OR old.action<>'maintenance.market.'||p_action OR old.payload_digest<>payload THEN RAISE EXCEPTION 'Maintenance command conflict' USING ERRCODE='40001'; END IF;
  RETURN old.result_ref;
 END IF;
 SELECT * INTO prior FROM public.market_source_custody WHERE source_id=p_source;
 IF coalesce(prior.revision,0)<>p_expected OR(p_action<>'provision_source' AND prior.source_id IS NULL) THEN RAISE EXCEPTION 'Market custody revision changed' USING ERRCODE='40001'; END IF;
 proof:=jsonb_build_object('operator_role',session_user,'source_id',p_source);
 INSERT INTO builderad_private.invocations VALUES(pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,NULL,0,NULL,'maintenance.market.'||p_action,
  jsonb_build_object('source_id',p_source),proof,builderad_private.authority_digest(proof),builderad_private.digest('dependency-set:1','[]'::jsonb),'authorized');
 IF p_action='provision_source' THEN
  IF p_expected<>0 THEN RAISE EXCEPTION 'Existing source cannot be reprovisioned' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.market_keys(p_payload,ARRAY['family','namespace','external_id','label','policy_id','policy_version','evidence_ref','evidence_digest','allowed_operations','allowed_purposes','allowed_fields','valid_until']);
  c.source_id:=p_source;c.revision:=1;c.status:='active';c.policy_id:=p_payload->>'policy_id';c.policy_version:=(p_payload->>'policy_version')::bigint;
  c.evidence_ref:=p_payload->>'evidence_ref';
  IF coalesce(p_payload->>'evidence_digest','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Explicit rights evidence digest required' USING ERRCODE='22023'; END IF;
  c.evidence_digest:=decode(p_payload->>'evidence_digest','hex');c.valid_from:=clock_timestamp();c.valid_until:=(p_payload->>'valid_until')::timestamptz;
  SELECT array_agg(v) INTO c.allowed_operations FROM jsonb_array_elements_text(p_payload->'allowed_operations') v;
  SELECT array_agg(v) INTO c.allowed_purposes FROM jsonb_array_elements_text(p_payload->'allowed_purposes') v;
  SELECT array_agg(v) INTO c.allowed_fields FROM jsonb_array_elements_text(p_payload->'allowed_fields') v;
  IF coalesce(cardinality(c.allowed_operations),0) NOT BETWEEN 1 AND 12 OR coalesce(cardinality(c.allowed_purposes),0) NOT BETWEEN 1 AND 32
  OR coalesce(cardinality(c.allowed_fields),0) NOT BETWEEN 1 AND 100
  OR EXISTS(SELECT 1 FROM unnest(c.allowed_operations) v WHERE v IS NULL OR v NOT IN('ingest','correct','retained_read','display','process','combine','reuse','export','train','retain','delete','forward'))
  OR EXISTS(SELECT 1 FROM unnest(c.allowed_purposes||c.allowed_fields) v WHERE v IS NULL OR length(v) NOT BETWEEN 1 AND 128 OR v LIKE '%*%')
  THEN RAISE EXCEPTION 'Finite rights policy required' USING ERRCODE='22023'; END IF;
  src.id:=p_source;src.workspace_id:=NULL;src.family:=p_payload->>'family';src.namespace:=p_payload->>'namespace';src.declared_external_id:=p_payload->>'external_id';
  src.label:=p_payload->>'label';src.version:=1;src.status:='UNVERIFIED';src.created_at:=clock_timestamp();src.scope_lock_marker:=false;src.custody_kind:='platform_market';
  PERFORM builderad_private.permit('public.source_registry','insert',NULL,to_jsonb(src));INSERT INTO public.source_registry SELECT(src).*;
  PERFORM builderad_private.permit('public.market_source_custody','insert',NULL,to_jsonb(c));INSERT INTO public.market_source_custody SELECT(c).*;
  f.fence_id:=uuidv7();f.kind:='source';f.source_id:=p_source;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
  PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
  result:=jsonb_build_object('source_ref',p_source,'versions',jsonb_build_object('custody',1));
 ELSIF p_action IN('restrict_source','revoke_source') THEN
  PERFORM builderad_private.market_keys(p_payload,ARRAY['reason']);
  IF jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR length(p_payload->>'reason') NOT BETWEEN 1 AND 600 THEN RAISE EXCEPTION 'Restriction reason required' USING ERRCODE='22023'; END IF;
  c:=prior;c.revision:=c.revision+1;c.status:=CASE WHEN p_action='restrict_source' THEN 'restricted' ELSE 'revoked' END;
  IF prior.status='revoked' THEN RAISE EXCEPTION 'Revoked source cannot be revived' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.permit('public.market_source_custody','update',to_jsonb(prior),to_jsonb(c));
  UPDATE public.market_source_custody SET revision=c.revision,status=c.status WHERE source_id=p_source;
  PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='source' AND source_id=p_source));
  result:=jsonb_build_object('source_ref',p_source,'status',c.status,'reason',p_payload->>'reason','versions',jsonb_build_object('custody',c.revision));
 ELSE
  PERFORM builderad_private.market_keys(p_payload,ARRAY['scope_id','subject_ref','subject_generation','operation','purpose','selection','valid_until','evidence_ref','evidence_digest']);
  s.scope_id:=(p_payload->>'scope_id')::uuid;op:=p_payload->>'operation';purpose:=p_payload->>'purpose';
  SELECT u.id,l.generation INTO actor,generation FROM public.users u JOIN public.principal_lifecycles l ON l.user_id=u.id
   WHERE u.public_id=(p_payload->>'subject_ref')::uuid AND l.principal_class='advertiser' AND l.status='current' AND u.email_verified_at IS NOT NULL FOR SHARE OF u,l;
  IF actor IS NULL OR generation IS DISTINCT FROM (p_payload->>'subject_generation')::bigint OR prior.status<>'active' OR prior.valid_from>clock_timestamp()
  OR(prior.valid_until IS NOT NULL AND prior.valid_until<=clock_timestamp()) OR NOT op=ANY(prior.allowed_operations) OR NOT purpose=ANY(prior.allowed_purposes)
  OR coalesce(p_payload->>'evidence_digest','') !~ '^[0-9a-f]{64}$'
  THEN RAISE EXCEPTION 'Qualified current recipient and policy required' USING ERRCODE='42501'; END IF;
  s.source_id:=p_source;s.resource:='market.evidence';s.selection:=builderad_private.canonical_data_selection(p_payload->'selection');s.selection_schema_version:=1;
  s.operation:=op;s.purpose:=purpose;s.scope_digest:=builderad_private.market_scope_digest(p_source,s.resource,op,purpose,s.selection);s.status:='active';s.revision:=1;s.rights_domain:='market';
  IF s.selection->'native_namespaces' IS DISTINCT FROM '["market.evidence"]'::jsonb
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
 END IF;
 PERFORM builderad_private.record_command(NULL,NULL,p_command,'maintenance.market.'||p_action,payload,result);
 -- A final receipt lock can outlive the source or recipient's qualified window.
 IF p_action IN('provision_source','enroll_data_use') AND
 ((coalesce(c.valid_until,prior.valid_until) IS NOT NULL AND coalesce(c.valid_until,prior.valid_until)<=clock_timestamp())
 OR(q.qualification_id IS NOT NULL AND q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp()))
 THEN RAISE EXCEPTION 'Market enrollment expired before completion' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
