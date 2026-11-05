-- Canonical final Purpose admission and exact effect guard.
-- Native scope admits only existing-history correct/withdraw; all effects consume permits.
CREATE OR REPLACE FUNCTION builderad_private.apply_object_purpose(w bigint,b bigint,a bigint,c jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE pid uuid; rid uuid; cid uuid; predecessor uuid; expected bigint; head bigint;
 op text; snap jsonb; old object_purpose_revisions%ROWTYPE; receipt object_purpose_command_receipts%ROWTYPE;
 fp text; result jsonb; cf jsonb; field text; required boolean; start_at timestamptz; until_at timestamptz; accepted timestamptz;
 context_version bigint; reference_version bigint; countries jsonb; intent_value jsonb; affected jsonb; reason text;
 new_head public.object_purposes; previous_head public.object_purposes; new_revision public.object_purpose_revisions; new_receipt public.object_purpose_command_receipts; ref public.object_purpose_business_context_refs;
BEGIN
 IF w IS NOT NULL AND coalesce(c->>'operation','') NOT IN('correct','withdraw') THEN RAISE EXCEPTION 'WORKSPACE_PURPOSE_DECLARATION_RETIRED' USING ERRCODE='42501'; END IF;
 IF num_nonnulls(w,b)<>1 THEN RAISE EXCEPTION 'Exactly one native Purpose owner required' USING ERRCODE='22023'; END IF;
 IF c IS NULL OR octet_length(c::text)>131072 OR NOT builderad_object_purpose_keys(c,
  ARRAY['command_id','purpose_id','expected_version','operation','declaration','effective_from','effective_until','supersedes_revision_id','affected_revision_ids','correction_reason','withdrawal_reason','confirmations']) THEN
  RAISE EXCEPTION 'Exact bounded Purpose command required' USING ERRCODE='22023'; END IF;
 IF jsonb_typeof(c->'command_id') IS DISTINCT FROM 'string' OR (c->>'command_id') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR
  jsonb_typeof(c->'expected_version') IS DISTINCT FROM 'number' OR (c->>'expected_version') !~ '^\d{1,18}$' OR
  jsonb_typeof(c->'operation') IS DISTINCT FROM 'string' OR c->>'operation' NOT IN ('declare','replace','correct','withdraw') THEN
  RAISE EXCEPTION 'Command identity, version and operation required' USING ERRCODE='22023'; END IF;
 cid:=(c->>'command_id')::uuid; expected:=(c->>'expected_version')::bigint; op:=c->>'operation';
 fp:=encode(digest(c::text,'sha256'),'hex');
 SELECT * INTO receipt FROM object_purpose_command_receipts WHERE workspace_id IS NOT DISTINCT FROM w AND business_id IS NOT DISTINCT FROM b AND command_id=cid;
 IF FOUND THEN
  IF receipt.fingerprint<>fp OR receipt.actor_user_id<>a THEN RAISE EXCEPTION 'Command reuse conflict' USING ERRCODE='40001'; END IF;
  RETURN receipt.result;
 END IF;
 cf:=c->'confirmations';
 IF NOT builderad_object_purpose_keys(cf,ARRAY['meaning','scope','authority','identity','standing','dtm','intent','historical','transition']) OR
  EXISTS (SELECT 1 FROM jsonb_each(cf) e WHERE jsonb_typeof(e.value)<>'boolean') THEN
  RAISE EXCEPTION 'Explicit boolean confirmations required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['meaning','scope','authority','identity','standing'] LOOP
  IF cf->field IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'All decisive confirmations required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF jsonb_typeof(c->'affected_revision_ids') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Affected revisions list required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(c->'affected_revision_ids')>1000 OR EXISTS (SELECT 1 FROM jsonb_array_elements(c->'affected_revision_ids') e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') THEN
  RAISE EXCEPTION 'Bounded exact affected revisions required' USING ERRCODE='22023'; END IF;
 IF NOT builderad_object_purpose_text(c->'correction_reason',4000) OR NOT builderad_object_purpose_text(c->'withdrawal_reason',4000) THEN
  RAISE EXCEPTION 'Bounded transition reason required' USING ERRCODE='22023'; END IF;
 IF op='declare' THEN
  IF c->'purpose_id'<>'null'::jsonb OR c->'supersedes_revision_id'<>'null'::jsonb OR expected<>0 OR c->'affected_revision_ids'<>'[]'::jsonb OR
   c->'correction_reason'<>'null'::jsonb OR c->'withdrawal_reason'<>'null'::jsonb THEN RAISE EXCEPTION 'New identity command required' USING ERRCODE='22023'; END IF;
  pid:=uuidv7(); head:=0;
 ELSE
  IF jsonb_typeof(c->'purpose_id') IS DISTINCT FROM 'string' OR (c->>'purpose_id') !~* '^[0-9a-f-]{36}$' OR
   jsonb_typeof(c->'supersedes_revision_id') IS DISTINCT FROM 'string' OR (c->>'supersedes_revision_id') !~* '^[0-9a-f-]{36}$' OR cf->'transition'<>'true'::jsonb THEN
   RAISE EXCEPTION 'Explicit reviewed predecessor and transition confirmation required' USING ERRCODE='22023'; END IF;
  BEGIN pid:=(c->>'purpose_id')::uuid; predecessor:=(c->>'supersedes_revision_id')::uuid;
  EXCEPTION WHEN invalid_text_representation THEN RAISE EXCEPTION 'Valid identity required' USING ERRCODE='22023'; END;
  SELECT head_version INTO head FROM object_purposes WHERE workspace_id IS NOT DISTINCT FROM w AND business_id IS NOT DISTINCT FROM b AND id=pid FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Purpose not available in Workspace' USING ERRCODE='42501'; END IF;
  IF head<>expected THEN RAISE EXCEPTION 'Purpose revision changed' USING ERRCODE='40001'; END IF;
  SELECT * INTO old FROM object_purpose_revisions WHERE workspace_id IS NOT DISTINCT FROM w AND business_id IS NOT DISTINCT FROM b AND purpose_id=pid AND version=head;
  IF old.revision_id<>predecessor THEN RAISE EXCEPTION 'Reviewed predecessor changed' USING ERRCODE='40001'; END IF;
 END IF;
 accepted:=clock_timestamp();
 start_at:=coalesce(builderad_object_purpose_instant(c->'effective_from'),accepted);
 until_at:=builderad_object_purpose_instant(c->'effective_until');
 IF until_at IS NOT NULL AND until_at<=start_at THEN RAISE EXCEPTION 'Exclusive end must follow start' USING ERRCODE='22023'; END IF;
 IF op='withdraw' THEN
  IF c->'declaration'<>'null'::jsonb OR NOT builderad_object_purpose_text(c->'withdrawal_reason',4000,true) OR until_at IS NOT NULL THEN
   RAISE EXCEPTION 'Withdrawal reason and no new declaration required' USING ERRCODE='22023'; END IF;
  snap:=old.snapshot; reason:=c->>'withdrawal_reason';
 ELSE
  snap:=builderad_object_purpose_normalize(c->'declaration');
  IF snap IS NULL THEN RAISE EXCEPTION 'Full declaration required' USING ERRCODE='22023'; END IF;
  IF op='correct' AND NOT builderad_object_purpose_text(c->'correction_reason',4000,true) THEN RAISE EXCEPTION 'Correction reason required' USING ERRCODE='22023'; END IF;
  reason:=c->>'correction_reason';
 END IF;
 IF start_at<accepted OR (op<>'withdraw' AND snap#>>'{dtm,mode}'='historical_attestation') THEN
  IF cf->'historical' IS DISTINCT FROM 'true'::jsonb OR (op<>'withdraw' AND NOT builderad_object_purpose_text(snap#>'{provenance,historical_support}',4000,true)) OR (op='withdraw' AND NOT builderad_object_purpose_text(to_jsonb(reason),4000,true)) THEN
   RAISE EXCEPTION 'Explicit supported historical attestation required' USING ERRCODE='22023'; END IF;
 END IF;
 IF snap#>'{provenance,statement_at}'<>'null'::jsonb THEN
  IF builderad_object_purpose_instant(snap#>'{provenance,statement_at}')>accepted THEN RAISE EXCEPTION 'Original statement cannot be future knowledge' USING ERRCODE='22023'; END IF;
 END IF;
 IF op<>'declare' THEN
  SELECT coalesce(jsonb_agg(revision_id::text ORDER BY revision_id::text),'[]') INTO affected
   FROM object_purpose_revisions WHERE workspace_id IS NOT DISTINCT FROM w AND business_id IS NOT DISTINCT FROM b AND purpose_id=pid AND effective_from>=start_at;
  IF NOT ((c->'affected_revision_ids') @> affected) OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(c->'affected_revision_ids') e
   WHERE NOT EXISTS (SELECT 1 FROM object_purpose_revisions WHERE workspace_id IS NOT DISTINCT FROM w AND business_id IS NOT DISTINCT FROM b AND purpose_id=pid AND revision_id::text=e)) THEN
   RAISE EXCEPTION 'Explicit review of affected scheduled/history revisions required' USING ERRCODE='40001'; END IF;
 END IF;
 IF b IS NULL THEN SELECT business_context_version INTO context_version FROM workspaces WHERE id=w;
 ELSE SELECT h.context_version INTO context_version FROM business_context_heads h WHERE business_id=b; END IF;
 IF op<>'withdraw' AND snap->'dtm'<>'null'::jsonb THEN
  IF cf->'dtm' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit DTM confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{dtm,version}')::bigint;
  IF reference_version>context_version OR (NOT builderad_private.purpose_context_exists(w,b,reference_version)) THEN
   RAISE EXCEPTION 'Exact canonical Context revision unavailable' USING ERRCODE='22023'; END IF;
  -- The immutable DTM journal contains material transitions only; Intent/name/type drift is unrelated.
  IF snap#>>'{dtm,mode}' IN ('current_alignment','current_dependency') AND builderad_private.purpose_latest_markets(w,b)>reference_version THEN
   RAISE EXCEPTION 'Current DTM alignment changed; review required' USING ERRCODE='40001'; END IF;
  countries:=builderad_private.purpose_markets_at(w,b,reference_version);
  IF countries<>snap#>'{dtm,countries}' THEN RAISE EXCEPTION 'Canonical DTM snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 IF op<>'withdraw' AND snap->'intent'<>'null'::jsonb THEN
  IF cf->'intent' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit Intent confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{intent,version}')::bigint;
  IF reference_version>context_version OR (NOT builderad_private.purpose_context_exists(w,b,reference_version)) THEN
   RAISE EXCEPTION 'Exact declared Intent revision unavailable' USING ERRCODE='22023'; END IF;
  intent_value:=builderad_private.purpose_intent_at(w,b,reference_version);
  IF intent_value IS NULL OR intent_value<>snap#>'{intent,value}' THEN RAISE EXCEPTION 'Canonical Intent snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 IF b IS NULL THEN PERFORM public.builderad_object_purpose_actor(w,current_setting('app.object_purpose_session'),true); END IF;
 IF op='declare' THEN
  new_head.id:=pid;new_head.workspace_id:=w;new_head.business_id:=b;new_head.head_version:=0;new_head.created_at:=accepted;
  PERFORM builderad_private.permit('public.object_purposes','insert',NULL,to_jsonb(new_head));
  INSERT INTO public.object_purposes SELECT(new_head).*;
 END IF;
 SELECT * INTO STRICT previous_head FROM public.object_purposes WHERE id=pid;
 IF b IS NOT NULL AND head>=1000 THEN RAISE EXCEPTION 'Purpose revision history bound reached' USING ERRCODE='22023'; END IF;
 new_revision.workspace_id:=w;new_revision.business_id:=b;new_revision.purpose_id:=pid;new_revision.version:=head+1;
 new_revision.revision_id:=uuidv7();new_revision.prior_version:=nullif(head,0);new_revision.supersedes_revision_id:=predecessor;
 new_revision.operation:=op;new_revision.effective_from:=start_at;new_revision.effective_until:=until_at;new_revision.snapshot:=snap;
 new_revision.actor_user_id:=a;SELECT generation INTO new_revision.actor_generation FROM public.principal_lifecycles WHERE user_id=a;
 IF b IS NULL THEN SELECT version INTO new_revision.ownership_version FROM public.workspace_ownerships WHERE workspace_id=w;
 ELSE new_revision.ownership_version:=0;SELECT auth_epoch INTO new_revision.business_authority_epoch FROM public.business_authority_fences WHERE business_id=b; END IF;
 new_revision.confirmations:=cf;new_revision.command_id:=cid;new_revision.reason:=reason;new_revision.affected_revision_ids:=c->'affected_revision_ids';new_revision.accepted_at:=accepted;
 PERFORM builderad_private.permit('public.object_purpose_revisions','insert',NULL,to_jsonb(new_revision));
 INSERT INTO public.object_purpose_revisions SELECT(new_revision).*;rid:=new_revision.revision_id;
 new_head:=previous_head;new_head.head_version:=head+1;
 PERFORM builderad_private.permit('public.object_purposes','update',to_jsonb(previous_head),to_jsonb(new_head));
 UPDATE public.object_purposes SET head_version=new_head.head_version WHERE id=pid;
 result:=jsonb_build_object('purpose_id',pid,'revision_id',rid,'version',head+1);
 new_receipt.workspace_id:=w;new_receipt.business_id:=b;new_receipt.command_id:=cid;new_receipt.actor_user_id:=a;new_receipt.fingerprint:=fp;
 new_receipt.result:=result;new_receipt.purpose_id:=pid;new_receipt.revision_id:=rid;new_receipt.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.object_purpose_command_receipts','insert',NULL,to_jsonb(new_receipt));
 INSERT INTO public.object_purpose_command_receipts SELECT(new_receipt).*;
 IF b IS NULL THEN PERFORM public.builderad_object_purpose_actor(w,current_setting('app.object_purpose_session'),true);
 ELSE
  ref.ref_id:=uuidv7();ref.native_revision_id:=rid;ref.business_id:=b;ref.context_version:=context_version;ref.reference_origin:='v2';ref.mapping_id:=NULL;
  PERFORM builderad_private.permit('public.object_purpose_business_context_refs','insert',NULL,to_jsonb(ref));INSERT INTO public.object_purpose_business_context_refs SELECT(ref).*;
 END IF;
 RETURN result;
END $$;


-- Legacy admission and Business admission share the same canonical engine.

CREATE OR REPLACE FUNCTION builderad_private.guard_effect() RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE before_row jsonb; after_row jsonb; op text:=lower(TG_OP); affected integer; adopted_source boolean;
BEGIN
 IF TG_OP='TRUNCATE' THEN
  IF session_user='builderad_owner' AND current_database()='builderad_test_disposable'
   AND (SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()) LIKE 'builderad-disposable-test:v1:%'
  THEN RETURN NULL; END IF;
  RAISE EXCEPTION 'Protected Business truth cannot be truncated' USING ERRCODE='42501';
 END IF;
 IF TG_OP<>'INSERT' THEN before_row:=to_jsonb(OLD); END IF;
 IF TG_OP<>'DELETE' THEN after_row:=to_jsonb(NEW); END IF;
 IF TG_TABLE_NAME IN('source_command_receipts','source_versions','source_audit_obligations','source_audit_delivery') THEN
  IF TG_TABLE_NAME='source_audit_delivery' THEN
   SELECT EXISTS(SELECT 1 FROM public.source_audit_obligations o JOIN public.resource_right_fences f ON f.source_id=o.source_id
    WHERE o.id=(after_row->>'obligation_id')::uuid AND f.kind='source' AND f.adoption_state='v2_adopted') INTO adopted_source;
  ELSE
   SELECT EXISTS(SELECT 1 FROM public.resource_right_fences WHERE source_id=(after_row->>'source_id')::uuid AND kind='source' AND adoption_state='v2_adopted') INTO adopted_source;
  END IF;
  IF NOT adopted_source THEN
   SELECT EXISTS(SELECT 1 FROM public.source_registry s WHERE to_jsonb(s)->>'custody_kind'='business_native'
    AND s.id=CASE WHEN TG_TABLE_NAME='source_audit_delivery' THEN
     (SELECT source_id FROM public.source_audit_obligations WHERE id=(after_row->>'obligation_id')::uuid)
     ELSE(after_row->>'source_id')::uuid END) INTO adopted_source;
  END IF;
  IF NOT adopted_source THEN RETURN NEW; END IF;
 END IF;
 IF TG_TABLE_NAME IN('source_registry','source_capabilities') THEN
  IF TG_TABLE_NAME='source_registry' AND TG_OP='UPDATE' AND before_row-'scope_lock_marker'=after_row-'scope_lock_marker' THEN RETURN NEW; END IF;
  SELECT EXISTS(SELECT 1 FROM public.resource_right_fences f WHERE f.kind='source' AND f.adoption_state='v2_adopted'
   AND f.source_id IN((before_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid,
    (after_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid)) INTO adopted_source;
  IF NOT adopted_source THEN
   adopted_source:=coalesce(after_row->>'custody_kind',before_row->>'custody_kind','')='business_native'
    OR EXISTS(SELECT 1 FROM public.source_registry s WHERE to_jsonb(s)->>'custody_kind'='business_native'
     AND s.id=coalesce((after_row->>'source_id')::uuid,(before_row->>'source_id')::uuid));
  END IF;
  IF NOT adopted_source AND NOT(TG_TABLE_NAME='source_registry' AND coalesce(after_row->>'custody_kind',before_row->>'custody_kind','')='platform_market') THEN
   IF session_user='builderad_app' AND NOT builderad_private.native_source_gate_held(coalesce((after_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid,(before_row->>CASE WHEN TG_TABLE_NAME='source_registry' THEN 'id' ELSE 'source_id' END)::uuid))
   THEN RAISE EXCEPTION 'Native source admission required' USING ERRCODE='42501'; END IF;
   IF TG_OP='DELETE' THEN RETURN OLD;ELSE RETURN NEW;END IF;
  END IF;
 END IF;
 IF TG_TABLE_NAME='security_action_grants' THEN
  SELECT EXISTS(SELECT 1 FROM public.resource_right_fences f WHERE f.kind='source' AND f.adoption_state='v2_adopted' AND f.source_id IN((before_row->>'source_target_id')::uuid,(after_row->>'source_target_id')::uuid)) INTO adopted_source;
  IF NOT adopted_source THEN
   SELECT EXISTS(SELECT 1 FROM public.source_registry s WHERE to_jsonb(s)->>'custody_kind'='business_native'
    AND s.id IN((before_row->>'source_target_id')::uuid,(after_row->>'source_target_id')::uuid)) INTO adopted_source;
  END IF;
  IF coalesce(before_row->>'scope_kind','') NOT IN ('business','client_data','data_use')
   AND coalesce(after_row->>'scope_kind','') NOT IN ('business','client_data','data_use')
   AND NOT(coalesce(after_row->>'scope_kind',before_row->>'scope_kind')='workspace'
    AND coalesce(after_row->>'action',before_row->>'action')='workspace.view'
    AND EXISTS(SELECT 1 FROM builderad_private.invocations i WHERE i.backend_pid=pg_backend_pid() AND i.transaction_id=pg_current_xact_id()
     AND i.phase='authorized' AND i.action='organization.team.add'
     AND i.target_tuple->>'workspace_id'=coalesce(after_row->>'workspace_id',before_row->>'workspace_id'))) THEN
   IF NOT adopted_source AND session_user='builderad_authority' AND (before_row->>'scope_kind'='source' OR after_row->>'scope_kind'='source')
    AND (((before_row->>'source_target_id') IS NOT NULL AND NOT builderad_private.native_source_gate_held((before_row->>'source_target_id')::uuid))
      OR((after_row->>'source_target_id') IS NOT NULL AND NOT builderad_private.native_source_gate_held((after_row->>'source_target_id')::uuid)))
   THEN RAISE EXCEPTION 'Native authority source admission required' USING ERRCODE='42501'; END IF;
   IF adopted_source THEN
    -- No late acquisition of K1 here: only an earlier protected writer can issue this exact row permit.
    NULL;
   ELSIF (coalesce(before_row->>'scope_kind','')='workspace' AND before_row->>'action'='business.context.manage')
    OR(coalesce(after_row->>'scope_kind','')='workspace' AND after_row->>'action'='business.context.manage') THEN
    -- Withdrawal survives commercial retirement; it cannot create, renew or
    -- widen a grant, and the terminal commercial-grant guard checks its image.
    IF session_user IN('builderad_authority','builderad_owner') AND TG_OP='UPDATE'
     AND before_row->>'status'='active' AND after_row->>'status'='revoked' AND after_row->>'revoked_at' IS NOT NULL
     AND (after_row->>'version')::bigint=(before_row->>'version')::bigint+1
     AND before_row-ARRAY['status','revoked_at','version','updated_at','runtime_lock_marker']=after_row-ARRAY['status','revoked_at','version','updated_at','runtime_lock_marker']
    THEN RETURN NEW; END IF;
    IF session_user='builderad_owner' THEN
     IF EXISTS(SELECT 1 FROM public.migration_cohort_members m JOIN public.migration_cohort_control c ON c.cohort_id=m.cohort_id
      WHERE m.workspace_id=coalesce((after_row->>'workspace_id')::bigint,(before_row->>'workspace_id')::bigint) AND c.mode='LEGACY_ACTIVE') THEN
      IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
     END IF;
    END IF;
    PERFORM builderad_private.legacy_admitted(coalesce((after_row->>'workspace_id')::bigint,(before_row->>'workspace_id')::bigint));
   ELSE
    IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
   END IF;
  END IF;
  IF TG_OP='UPDATE' AND before_row-'runtime_lock_marker'=after_row-'runtime_lock_marker' THEN RETURN NEW; END IF;
  IF TG_OP='UPDATE' AND (
   before_row->>'id' IS DISTINCT FROM after_row->>'id' OR
   (before_row-ARRAY['status','version','revoked_at','updated_at','runtime_lock_marker']) IS DISTINCT FROM
   (after_row-ARRAY['status','version','revoked_at','updated_at','runtime_lock_marker']) OR
   before_row->>'status'='revoked' OR (after_row->>'version')::bigint<>(before_row->>'version')::bigint+1
  ) THEN RAISE EXCEPTION 'Immutable grant identity and monotone revocation required' USING ERRCODE='42501'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized')
 THEN RAISE EXCEPTION 'Authorized Business invocation required' USING ERRCODE='42501'; END IF;
 UPDATE builderad_private.command_permits p SET uses_left=0,consumed_at=clock_timestamp()
 WHERE p.backend_pid=pg_backend_pid() AND p.transaction_id=pg_current_xact_id() AND p.uses_left=1
 AND p.object_schema=TG_TABLE_SCHEMA AND p.object_name=TG_TABLE_NAME AND p.dml_kind=op
 AND p.old_digest IS NOT DISTINCT FROM builderad_private.image_digest(TG_RELID,before_row,op)
 AND p.new_digest IS NOT DISTINCT FROM builderad_private.image_digest(TG_RELID,after_row,op)
 AND EXISTS(SELECT 1 FROM builderad_private.invocations i WHERE i.nonce=p.invocation_nonce AND i.phase='authorized'
  AND i.authority_selection_digest=p.authority_selection_digest);
 GET DIAGNOSTICS affected=ROW_COUNT;
 IF affected<>1 THEN RAISE EXCEPTION 'Exact single-use Business effect permit required' USING ERRCODE='42501'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END $$;
