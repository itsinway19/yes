-- Extend the canonical Purpose owner; no parallel declaration/event store.
-- Original UUIDs, native commands and legacy workspace namespaces remain valid.
ALTER TABLE public.object_purposes ADD COLUMN business_id bigint REFERENCES public.businesses(id) ON DELETE RESTRICT;
ALTER TABLE public.object_purpose_revisions ADD COLUMN business_id bigint REFERENCES public.businesses(id) ON DELETE RESTRICT,
 ADD COLUMN business_authority_epoch bigint;
ALTER TABLE public.object_purpose_command_receipts ADD COLUMN business_id bigint REFERENCES public.businesses(id) ON DELETE RESTRICT;
DO $foreign_key$
DECLARE constraint_name text;
BEGIN
 FOR constraint_name IN SELECT conname FROM pg_constraint WHERE conrelid='public.object_purpose_revisions'::regclass AND confrelid='public.object_purpose_command_receipts'::regclass LOOP
  EXECUTE format('ALTER TABLE public.object_purpose_revisions DROP CONSTRAINT %I',constraint_name);
 END LOOP;
END $foreign_key$;
ALTER TABLE public.object_purpose_command_receipts DROP CONSTRAINT object_purpose_command_receipts_pkey;
ALTER TABLE public.object_purpose_command_receipts ADD PRIMARY KEY(purpose_id,command_id),ADD UNIQUE(workspace_id,command_id),ADD UNIQUE(business_id,command_id);
ALTER TABLE public.object_purpose_revisions DROP CONSTRAINT object_purpose_revisions_pkey;
ALTER TABLE public.object_purpose_revisions ADD PRIMARY KEY(purpose_id,version);
ALTER TABLE public.object_purposes ALTER COLUMN workspace_id DROP NOT NULL,ADD UNIQUE(id,business_id),
 ADD CONSTRAINT purpose_exact_owner CHECK(num_nonnulls(workspace_id,business_id)=1);
ALTER TABLE public.object_purpose_revisions ALTER COLUMN workspace_id DROP NOT NULL,
 ADD CONSTRAINT purpose_revision_exact_owner CHECK(num_nonnulls(workspace_id,business_id)=1),
 ADD CONSTRAINT purpose_revision_epoch CHECK((business_id IS NULL AND business_authority_epoch IS NULL) OR(business_id IS NOT NULL AND business_authority_epoch>=0 AND ownership_version=0)),
 ADD UNIQUE(purpose_id,revision_id,business_id),
 ADD FOREIGN KEY(purpose_id,business_id) REFERENCES public.object_purposes(id,business_id) ON DELETE RESTRICT,
 ADD FOREIGN KEY(purpose_id,command_id) REFERENCES public.object_purpose_command_receipts(purpose_id,command_id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE public.object_purpose_command_receipts ALTER COLUMN workspace_id DROP NOT NULL,
 ADD CONSTRAINT purpose_receipt_exact_owner CHECK(num_nonnulls(workspace_id,business_id)=1),
 ADD FOREIGN KEY(purpose_id,revision_id,business_id) REFERENCES public.object_purpose_revisions(purpose_id,revision_id,business_id) ON DELETE RESTRICT;

CREATE FUNCTION builderad_private.purpose_context_exists(w bigint,b bigint,v bigint) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF v=0 THEN RETURN true; END IF;
 IF b IS NULL THEN RETURN EXISTS(SELECT 1 FROM public.business_context_events WHERE workspace_id=w AND version=v UNION ALL SELECT 1 FROM public.business_target_market_events WHERE workspace_id=w AND version=v UNION ALL SELECT 1 FROM public.business_current_intent_events WHERE workspace_id=w AND after_version=v); END IF;
 RETURN EXISTS(SELECT 1 FROM public.business_context_transitions WHERE business_id=b AND after_context_version=v UNION ALL SELECT 1 FROM public.business_market_transitions WHERE business_id=b AND after_context_version=v UNION ALL SELECT 1 FROM public.business_intent_transitions WHERE business_id=b AND after_context_version=v);
END $$;
CREATE FUNCTION builderad_private.purpose_latest_markets(w bigint,b bigint) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF b IS NULL THEN RETURN coalesce((SELECT max(version) FROM public.business_target_market_events WHERE workspace_id=w),0); END IF;
 RETURN coalesce((SELECT max(after_context_version) FROM public.business_market_transitions WHERE business_id=b),0);
END $$;
CREATE FUNCTION builderad_private.purpose_markets_at(w bigint,b bigint,v bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE result jsonb;
BEGIN
 IF b IS NULL THEN SELECT to_jsonb(after_codes) INTO result FROM public.business_target_market_events WHERE workspace_id=w AND version<=v ORDER BY version DESC LIMIT 1;
 ELSE
  SELECT to_jsonb(after_codes) INTO result FROM public.business_market_transitions WHERE business_id=b AND after_context_version<=v ORDER BY after_context_version DESC LIMIT 1;
  IF NOT FOUND THEN SELECT to_jsonb(before_codes) INTO result FROM public.business_market_transitions WHERE business_id=b ORDER BY after_context_version LIMIT 1;
   IF NOT FOUND THEN SELECT jsonb_agg(country_code::text ORDER BY country_code COLLATE "C") INTO result FROM public.business_declared_markets WHERE business_id=b; END IF;
  END IF;
 END IF;
 RETURN coalesce(result,'[]'::jsonb);
END $$;
CREATE FUNCTION builderad_private.purpose_intent_at(w bigint,b bigint,v bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE result jsonb;
BEGIN
 IF b IS NULL THEN SELECT after_value INTO result FROM public.business_current_intent_events WHERE workspace_id=w AND after_version<=v ORDER BY after_version DESC LIMIT 1;
 ELSE
  SELECT after_value INTO result FROM public.business_intent_transitions WHERE business_id=b AND after_context_version<=v ORDER BY after_context_version DESC LIMIT 1;
  IF NOT FOUND THEN SELECT before_value INTO result FROM public.business_intent_transitions WHERE business_id=b ORDER BY after_context_version LIMIT 1;
   IF NOT FOUND THEN SELECT value INTO result FROM public.business_current_intents WHERE business_id=b AND intent_revision=0; END IF;
  END IF;
 END IF;
 RETURN result;
END $$;


CREATE FUNCTION builderad_private.apply_object_purpose(w bigint,b bigint,a bigint,c jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE pid uuid; rid uuid; cid uuid; predecessor uuid; expected bigint; head bigint;
 op text; snap jsonb; old object_purpose_revisions%ROWTYPE; receipt object_purpose_command_receipts%ROWTYPE;
 fp text; result jsonb; cf jsonb; field text; required boolean; start_at timestamptz; until_at timestamptz; accepted timestamptz;
 context_version bigint; reference_version bigint; countries jsonb; intent_value jsonb; affected jsonb; reason text;
 new_head public.object_purposes; previous_head public.object_purposes; new_revision public.object_purpose_revisions; new_receipt public.object_purpose_command_receipts; ref public.object_purpose_business_context_refs;
BEGIN
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
  IF b IS NOT NULL THEN PERFORM builderad_private.permit('public.object_purposes','insert',NULL,to_jsonb(new_head)); END IF;
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
 IF b IS NOT NULL THEN PERFORM builderad_private.permit('public.object_purpose_revisions','insert',NULL,to_jsonb(new_revision)); END IF;
 INSERT INTO public.object_purpose_revisions SELECT(new_revision).*;rid:=new_revision.revision_id;
 new_head:=previous_head;new_head.head_version:=head+1;
 IF b IS NOT NULL THEN PERFORM builderad_private.permit('public.object_purposes','update',to_jsonb(previous_head),to_jsonb(new_head)); END IF;
 UPDATE public.object_purposes SET head_version=new_head.head_version WHERE id=pid;
 result:=jsonb_build_object('purpose_id',pid,'revision_id',rid,'version',head+1);
 new_receipt.workspace_id:=w;new_receipt.business_id:=b;new_receipt.command_id:=cid;new_receipt.actor_user_id:=a;new_receipt.fingerprint:=fp;
 new_receipt.result:=result;new_receipt.purpose_id:=pid;new_receipt.revision_id:=rid;new_receipt.recorded_at:=clock_timestamp();
 IF b IS NOT NULL THEN PERFORM builderad_private.permit('public.object_purpose_command_receipts','insert',NULL,to_jsonb(new_receipt)); END IF;
 INSERT INTO public.object_purpose_command_receipts SELECT(new_receipt).*;
 IF b IS NULL THEN PERFORM public.builderad_object_purpose_actor(w,current_setting('app.object_purpose_session'),true);
 ELSE
  ref.ref_id:=uuidv7();ref.native_revision_id:=rid;ref.business_id:=b;ref.context_version:=context_version;ref.reference_origin:='v2';ref.mapping_id:=NULL;
  PERFORM builderad_private.permit('public.object_purpose_business_context_refs','insert',NULL,to_jsonb(ref));INSERT INTO public.object_purpose_business_context_refs SELECT(ref).*;
 END IF;
 RETURN result;
END $$;


-- Legacy admission and Business admission share the same canonical engine.
CREATE OR REPLACE FUNCTION public.builderad_object_purpose_write(w bigint,s text,c jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
DECLARE actor bigint;result jsonb;
BEGIN
 PERFORM builderad_private.legacy_admitted(w);
 actor:=public.builderad_object_purpose_actor(w,s,true);
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.object-purpose.'||w,0));
 actor:=public.builderad_object_purpose_actor(w,s,true);
 result:=builderad_private.apply_object_purpose(w,NULL,actor,c);
 PERFORM public.builderad_object_purpose_actor(w,s,true);PERFORM builderad_private.legacy_admitted(w);
 RETURN result;
END $$;

CREATE FUNCTION builderad_v2_business_purpose_write(p_session text,p_business uuid,p_command jsonb,p_expected_context bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;context_version bigint;cid uuid;authenticated timestamptz;
BEGIN
 IF p_expected_context IS NULL OR p_expected_context<0 OR p_command IS NULL OR jsonb_typeof(p_command)<>'object' THEN RAISE EXCEPTION 'Typed Business Purpose command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.purpose.declare',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated NOT BETWEEN clock_timestamp()-interval '15 minutes' AND clock_timestamp() THEN RAISE EXCEPTION 'Fresh Purpose authentication required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('purpose_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 IF proof#>>'{qualification,issuer_kind}'='self_declared_draft' THEN RAISE EXCEPTION 'Qualified Purpose authority required' USING ERRCODE='42501'; END IF;
 -- Serialize Purpose commands with the canonical Business context head. New
 -- identities have no Purpose row to lock yet; concurrent delivery of one command
 -- must observe the committed native receipt before attempting a second identity.
 SELECT h.context_version INTO context_version FROM public.business_context_heads h WHERE business_id=b FOR UPDATE;
 IF coalesce(p_command->>'command_id','') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN RAISE EXCEPTION 'Exact Purpose command identity required' USING ERRCODE='22023'; END IF;
 cid:=(p_command->>'command_id')::uuid;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('command',p_command,'context',p_expected_context),'{"context":"I"}');
 result:=builderad_private.command_replay(b,actor,cid,'business.purpose.declare',payload);IF result IS NOT NULL THEN RETURN result; END IF;
 IF context_version<>p_expected_context THEN RAISE EXCEPTION 'Reviewed Business context changed' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,cid,'business.purpose.declare',jsonb_build_object('business_id',b),proof);
 result:=builderad_private.apply_object_purpose(NULL,b,actor,p_command)||jsonb_build_object('business_public_id',p_business,'versions',jsonb_build_object('context',context_version));
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.purpose.declare',p_selection,p_session);
 PERFORM builderad_private.record_command(b,actor,cid,'business.purpose.declare',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;

CREATE FUNCTION builderad_v2_business_purpose_snapshot(p_session text,p_business uuid,p_read bigint,p_purpose uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;context_version bigint;heads jsonb;result jsonb;now_value timestamptz;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Exact own Business read path required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);PERFORM builderad_private.principal(p_session);
 SELECT h.context_version INTO context_version FROM public.business_context_heads h WHERE business_id=b FOR SHARE;
 PERFORM 1 FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose) ORDER BY id FOR SHARE;
 IF (SELECT count(*) FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose))>64 THEN RAISE EXCEPTION 'Select exact Purpose; bounded projection unavailable' USING ERRCODE='22023'; END IF;
 IF p_purpose IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.object_purposes WHERE business_id=b AND id=p_purpose) THEN RAISE EXCEPTION 'Purpose unavailable in this Business' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',h.id,'head_version',h.head_version,'history',
  (SELECT coalesce(jsonb_agg((to_jsonb(r)-ARRAY['workspace_id','business_id','actor_user_id','actor_generation','ownership_version','business_authority_epoch','command_id']) ORDER BY r.version),'[]') FROM public.object_purpose_revisions r WHERE r.business_id=b AND r.purpose_id=h.id)) ORDER BY h.id),'[]') INTO heads
 FROM public.object_purposes h WHERE h.business_id=b AND(p_purpose IS NULL OR h.id=p_purpose);
 result:=jsonb_build_object('business_public_id',p_business,'heads',heads,'context_version',context_version,'dtm_version',builderad_private.purpose_latest_markets(NULL,b),'scope','native_business_purposes_only');
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Complete Purpose projection exceeds bound' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN result||jsonb_build_object('read_at',clock_timestamp());
END $$;
