-- Native Business Purpose statements, temporal history, and context lineage.
-- First four pure validators must precede object_purpose_revisions snapshot CHECK creation.

CREATE OR REPLACE FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
 SELECT coalesce(jsonb_typeof(v)='object' AND
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k)=
  (SELECT array_agg(k ORDER BY k COLLATE "C") FROM unnest(expected) k),false)
$$;


ALTER FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) OWNER TO builderad_owner;

CREATE OR REPLACE FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean DEFAULT false) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    AS $$
 SELECT coalesce((NOT required AND v='null'::jsonb) OR
  (jsonb_typeof(v)='string' AND char_length(v#>>'{}')<=maximum AND
   char_length(btrim(v#>>'{}',E' \t\r\n'))>0),false)
$$;


ALTER FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean) OWNER TO builderad_owner;

CREATE OR REPLACE FUNCTION public.builderad_object_purpose_instant(v jsonb) RETURNS timestamp with time zone
    LANGUAGE plpgsql STABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
BEGIN
 IF v='null'::jsonb THEN RETURN NULL; END IF;
 IF jsonb_typeof(v) IS DISTINCT FROM 'string' OR (v#>>'{}') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$' THEN
  RAISE EXCEPTION 'Timezone-qualified instant required' USING ERRCODE='22023';
 END IF;
 BEGIN RETURN (v#>>'{}')::timestamptz;
 EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN
  RAISE EXCEPTION 'Valid instant required' USING ERRCODE='22023'; END;
END $_$;


ALTER FUNCTION public.builderad_object_purpose_instant(v jsonb) OWNER TO builderad_owner;

CREATE OR REPLACE FUNCTION public.builderad_object_purpose_normalize(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
DECLARE field text; item jsonb; seen jsonb:='[]'; tuples jsonb; p jsonb; r jsonb;
BEGIN
 IF v IS NULL OR octet_length(v::text)>131072 OR NOT builderad_object_purpose_keys(v,
 ARRAY['meaning','qualifications','phase','included_objects','excluded_objects','provenance','dtm','intent','end_condition','limitations','conflicts']) THEN
  RAISE EXCEPTION 'Complete bounded Purpose snapshot required' USING ERRCODE='22023'; END IF;
 IF NOT builderad_object_purpose_text(v->'meaning',4000,true) THEN RAISE EXCEPTION 'Confirmed meaning required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['qualifications','end_condition','limitations','phase'] LOOP
  IF NOT builderad_object_purpose_text(v->field,CASE WHEN field='phase' THEN 80 ELSE 4000 END) THEN
   RAISE EXCEPTION 'Bounded text or null required' USING ERRCODE='22023'; END IF;
 END LOOP;
 FOREACH field IN ARRAY ARRAY['included_objects','excluded_objects'] LOOP
  tuples:=v->field;
  IF jsonb_typeof(tuples) IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Finite object list required' USING ERRCODE='22023'; END IF;
  IF jsonb_array_length(tuples)>50 OR (field='included_objects' AND jsonb_array_length(tuples)=0) THEN
   RAISE EXCEPTION 'Bounded finite membership required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(tuples) LOOP
   IF NOT builderad_object_purpose_keys(item,ARRAY['provider','account_namespace','account_id','object_kind','object_id','incarnation']) THEN
    RAISE EXCEPTION 'Exact opaque object tuple required' USING ERRCODE='22023'; END IF;
   IF EXISTS (SELECT 1 FROM jsonb_each(item) e WHERE NOT builderad_object_purpose_text(e.value,180,e.key<>'incarnation')) THEN
    RAISE EXCEPTION 'Bounded case-preserved opaque strings required' USING ERRCODE='22023'; END IF;
   IF seen @> jsonb_build_array(item) THEN RAISE EXCEPTION 'Duplicate or included/excluded tuple' USING ERRCODE='22023'; END IF;
   seen:=seen||jsonb_build_array(item);
  END LOOP;
  SELECT coalesce(jsonb_agg(value ORDER BY value::text COLLATE "C"),'[]') INTO tuples FROM jsonb_array_elements(tuples);
  v:=jsonb_set(v,ARRAY[field],tuples);
 END LOOP;
 p:=v->'provenance';
 IF NOT builderad_object_purpose_keys(p,ARRAY['speaker','statement_at','source','identity_basis','authority_basis','historical_support','effective_basis']) THEN
  RAISE EXCEPTION 'Structured attribution required' USING ERRCODE='22023'; END IF;
 FOREACH field IN ARRAY ARRAY['speaker','statement_at','source','identity_basis','authority_basis','historical_support','effective_basis'] LOOP
  IF NOT builderad_object_purpose_text(p->field,4000,field IN ('source','identity_basis','authority_basis','effective_basis')) THEN
   RAISE EXCEPTION 'Bounded provenance required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF jsonb_typeof(v->'conflicts') IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'Declared conflict list required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(v->'conflicts')>50 OR EXISTS (SELECT 1 FROM jsonb_array_elements(v->'conflicts') e WHERE NOT builderad_object_purpose_text(e,4000,true)) THEN
  RAISE EXCEPTION 'Bounded explicitly declared conflicts required' USING ERRCODE='22023'; END IF;
 r:=v->'dtm';
 IF r<>'null'::jsonb THEN
  IF NOT builderad_object_purpose_keys(r,ARRAY['mode','version','countries','subset','statement']) OR
    jsonb_typeof(r->'mode') IS DISTINCT FROM 'string' OR r->>'mode' NOT IN ('historical_attestation','current_alignment','current_dependency') OR
    jsonb_typeof(r->'version') IS DISTINCT FROM 'number' OR (r->>'version') !~ '^\d{1,18}$' OR
    NOT builderad_object_purpose_text(r->'statement',4000,true) OR
    jsonb_typeof(r->'countries') IS DISTINCT FROM 'array' OR jsonb_typeof(r->'subset') IS DISTINCT FROM 'array' THEN
   RAISE EXCEPTION 'Exact declared DTM relation required' USING ERRCODE='22023'; END IF;
  FOREACH field IN ARRAY ARRAY['countries','subset'] LOOP
   tuples:=r->field;
   IF jsonb_array_length(tuples)>249 OR EXISTS (SELECT 1 FROM jsonb_array_elements(tuples) e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~ '^[A-Z]{2}$') OR
    (SELECT count(*) FROM jsonb_array_elements(tuples))<>(SELECT count(DISTINCT value) FROM jsonb_array_elements(tuples)) THEN
    RAISE EXCEPTION 'Distinct country code list required' USING ERRCODE='22023'; END IF;
   SELECT coalesce(jsonb_agg(value ORDER BY value::text COLLATE "C"),'[]') INTO tuples FROM jsonb_array_elements(tuples);
   r:=jsonb_set(r,ARRAY[field],tuples);
  END LOOP;
  IF NOT ((r->'countries') @> (r->'subset')) THEN RAISE EXCEPTION 'Explicit subset outside reference' USING ERRCODE='22023'; END IF;
  v:=jsonb_set(v,'{dtm}',r);
 END IF;
 r:=v->'intent';
 IF r<>'null'::jsonb AND (NOT builderad_object_purpose_keys(r,ARRAY['version','value','statement']) OR
  jsonb_typeof(r->'version') IS DISTINCT FROM 'number' OR (r->>'version') !~ '^\d{1,18}$' OR
  jsonb_typeof(r->'value') IS DISTINCT FROM 'object' OR NOT builderad_object_purpose_text(r->'statement',4000,true)) THEN
  RAISE EXCEPTION 'Exact explicit Intent relationship required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $_$;


ALTER FUNCTION public.builderad_object_purpose_normalize(v jsonb) OWNER TO builderad_owner;

CREATE FUNCTION builderad_private.apply_object_purpose(b bigint, a bigint, c jsonb) RETURNS jsonb
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE pid uuid; rid uuid; cid uuid; predecessor uuid; expected bigint; head bigint;
 op text; snap jsonb; old object_purpose_revisions%ROWTYPE; receipt object_purpose_command_receipts%ROWTYPE;
 fp text; result jsonb; cf jsonb; field text; required boolean; start_at timestamptz; until_at timestamptz; accepted timestamptz;
 context_version bigint; reference_version bigint; countries jsonb; intent_value jsonb; affected jsonb; reason text;
 new_head public.object_purposes; previous_head public.object_purposes; new_revision public.object_purpose_revisions; new_receipt public.object_purpose_command_receipts; ref public.object_purpose_business_context_refs;
BEGIN
 IF b IS NULL OR b<1 THEN RAISE EXCEPTION 'Exact Business Purpose owner required' USING ERRCODE='22023'; END IF;
 IF c IS NULL OR octet_length(c::text)>131072 OR NOT builderad_object_purpose_keys(c,
  ARRAY['command_id','purpose_id','expected_version','operation','declaration','effective_from','effective_until','supersedes_revision_id','affected_revision_ids','correction_reason','withdrawal_reason','confirmations']) THEN
  RAISE EXCEPTION 'Exact bounded Purpose command required' USING ERRCODE='22023'; END IF;
 IF jsonb_typeof(c->'command_id') IS DISTINCT FROM 'string' OR (c->>'command_id') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR
  jsonb_typeof(c->'expected_version') IS DISTINCT FROM 'number' OR (c->>'expected_version') !~ '^\d{1,18}$' OR
  jsonb_typeof(c->'operation') IS DISTINCT FROM 'string' OR c->>'operation' NOT IN ('declare','replace','correct','withdraw') THEN
  RAISE EXCEPTION 'Command identity, version and operation required' USING ERRCODE='22023'; END IF;
 cid:=(c->>'command_id')::uuid; expected:=(c->>'expected_version')::bigint; op:=c->>'operation';
 fp:=encode(digest(c::text,'sha256'),'hex');
 SELECT * INTO receipt FROM object_purpose_command_receipts WHERE business_id=b AND command_id=cid;
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
  SELECT head_version INTO head FROM object_purposes WHERE business_id=b AND id=pid FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Purpose not available in Business' USING ERRCODE='42501'; END IF;
  IF head<>expected THEN RAISE EXCEPTION 'Purpose revision changed' USING ERRCODE='40001'; END IF;
  SELECT * INTO old FROM object_purpose_revisions WHERE business_id=b AND purpose_id=pid AND version=head;
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
   FROM object_purpose_revisions WHERE business_id=b AND purpose_id=pid AND effective_from>=start_at;
  IF NOT ((c->'affected_revision_ids') @> affected) OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(c->'affected_revision_ids') e
   WHERE NOT EXISTS (SELECT 1 FROM object_purpose_revisions WHERE business_id=b AND purpose_id=pid AND revision_id::text=e)) THEN
   RAISE EXCEPTION 'Explicit review of affected scheduled/history revisions required' USING ERRCODE='40001'; END IF;
 END IF;
 SELECT h.context_version INTO context_version FROM business_context_heads h WHERE business_id=b;
 IF op<>'withdraw' AND snap->'dtm'<>'null'::jsonb THEN
  IF cf->'dtm' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit DTM confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{dtm,version}')::bigint;
  IF reference_version>context_version OR (NOT builderad_private.purpose_context_exists(b,reference_version)) THEN
   RAISE EXCEPTION 'Exact canonical Context revision unavailable' USING ERRCODE='22023'; END IF;
  -- The immutable DTM journal contains material transitions only; Intent/name/type drift is unrelated.
  IF snap#>>'{dtm,mode}' IN ('current_alignment','current_dependency') AND builderad_private.purpose_latest_markets(b)>reference_version THEN
   RAISE EXCEPTION 'Current DTM alignment changed; review required' USING ERRCODE='40001'; END IF;
  countries:=builderad_private.purpose_markets_at(b,reference_version);
  IF countries<>snap#>'{dtm,countries}' THEN RAISE EXCEPTION 'Canonical DTM snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 IF op<>'withdraw' AND snap->'intent'<>'null'::jsonb THEN
  IF cf->'intent' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Explicit Intent confirmation required' USING ERRCODE='22023'; END IF;
  reference_version:=(snap#>>'{intent,version}')::bigint;
  IF reference_version>context_version OR (NOT builderad_private.purpose_context_exists(b,reference_version)) THEN
   RAISE EXCEPTION 'Exact declared Intent revision unavailable' USING ERRCODE='22023'; END IF;
  intent_value:=builderad_private.purpose_intent_at(b,reference_version);
  IF intent_value IS NULL OR intent_value<>snap#>'{intent,value}' THEN RAISE EXCEPTION 'Canonical Intent snapshot mismatch' USING ERRCODE='22023'; END IF;
 END IF;
 IF op='declare' THEN
  new_head.id:=pid;new_head.business_id:=b;new_head.head_version:=0;new_head.created_at:=accepted;
  PERFORM builderad_private.permit('public.object_purposes','insert',NULL,to_jsonb(new_head));
  INSERT INTO public.object_purposes SELECT(new_head).*;
 END IF;
 SELECT * INTO STRICT previous_head FROM public.object_purposes WHERE id=pid;
 IF head>=1000 THEN RAISE EXCEPTION 'Purpose revision history bound reached' USING ERRCODE='22023'; END IF;
 new_revision.business_id:=b;new_revision.purpose_id:=pid;new_revision.version:=head+1;
 new_revision.revision_id:=uuidv7();new_revision.prior_version:=nullif(head,0);new_revision.supersedes_revision_id:=predecessor;
 new_revision.operation:=op;new_revision.effective_from:=start_at;new_revision.effective_until:=until_at;new_revision.snapshot:=snap;
 new_revision.actor_user_id:=a;SELECT generation INTO new_revision.actor_generation FROM public.principal_lifecycles WHERE user_id=a;
 SELECT auth_epoch INTO new_revision.business_authority_epoch FROM public.business_authority_fences WHERE business_id=b;
 new_revision.confirmations:=cf;new_revision.command_id:=cid;new_revision.reason:=reason;new_revision.affected_revision_ids:=c->'affected_revision_ids';new_revision.accepted_at:=accepted;
 PERFORM builderad_private.permit('public.object_purpose_revisions','insert',NULL,to_jsonb(new_revision));
 INSERT INTO public.object_purpose_revisions SELECT(new_revision).*;rid:=new_revision.revision_id;
 new_head:=previous_head;new_head.head_version:=head+1;
 PERFORM builderad_private.permit('public.object_purposes','update',to_jsonb(previous_head),to_jsonb(new_head));
 UPDATE public.object_purposes SET head_version=new_head.head_version WHERE id=pid;
 result:=jsonb_build_object('purpose_id',pid,'revision_id',rid,'version',head+1);
 new_receipt.business_id:=b;new_receipt.command_id:=cid;new_receipt.actor_user_id:=a;new_receipt.fingerprint:=fp;
 new_receipt.result:=result;new_receipt.purpose_id:=pid;new_receipt.revision_id:=rid;new_receipt.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.object_purpose_command_receipts','insert',NULL,to_jsonb(new_receipt));
 INSERT INTO public.object_purpose_command_receipts SELECT(new_receipt).*;
 ref.ref_id:=uuidv7();ref.native_revision_id:=rid;ref.business_id:=b;ref.context_version:=context_version;ref.reference_origin:='v2';
 PERFORM builderad_private.permit('public.object_purpose_business_context_refs','insert',NULL,to_jsonb(ref));
 INSERT INTO public.object_purpose_business_context_refs SELECT(ref).*;
 RETURN result;
END $_$;


ALTER FUNCTION builderad_private.apply_object_purpose(b bigint, a bigint, c jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.purpose_context_exists(b bigint, v bigint) RETURNS boolean
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
BEGIN
 IF v=0 THEN RETURN true; END IF;
 RETURN EXISTS(SELECT 1 FROM public.business_context_transitions WHERE business_id=b AND after_context_version=v UNION ALL SELECT 1 FROM public.business_market_transitions WHERE business_id=b AND after_context_version=v UNION ALL SELECT 1 FROM public.business_intent_transitions WHERE business_id=b AND after_context_version=v);
END $$;


ALTER FUNCTION builderad_private.purpose_context_exists(b bigint, v bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.purpose_intent_at(b bigint, v bigint) RETURNS jsonb
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
DECLARE result jsonb;
BEGIN
  SELECT after_value INTO result FROM public.business_intent_transitions WHERE business_id=b AND after_context_version<=v ORDER BY after_context_version DESC LIMIT 1;
  IF NOT FOUND THEN SELECT before_value INTO result FROM public.business_intent_transitions WHERE business_id=b ORDER BY after_context_version LIMIT 1;
   IF NOT FOUND THEN SELECT value INTO result FROM public.business_current_intents WHERE business_id=b AND intent_revision=0; END IF;
  END IF;
 RETURN result;
END $$;


ALTER FUNCTION builderad_private.purpose_intent_at(b bigint, v bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.purpose_latest_markets(b bigint) RETURNS bigint
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
BEGIN
 RETURN coalesce((SELECT max(after_context_version) FROM public.business_market_transitions WHERE business_id=b),0);
END $$;


ALTER FUNCTION builderad_private.purpose_latest_markets(b bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION builderad_private.purpose_markets_at(b bigint, v bigint) RETURNS jsonb
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
DECLARE result jsonb;
BEGIN
  SELECT to_jsonb(after_codes) INTO result FROM public.business_market_transitions WHERE business_id=b AND after_context_version<=v ORDER BY after_context_version DESC LIMIT 1;
  IF NOT FOUND THEN SELECT to_jsonb(before_codes) INTO result FROM public.business_market_transitions WHERE business_id=b ORDER BY after_context_version LIMIT 1;
   IF NOT FOUND THEN SELECT jsonb_agg(country_code::text ORDER BY country_code COLLATE "C") INTO result FROM public.business_declared_markets WHERE business_id=b; END IF;
  END IF;
 RETURN coalesce(result,'[]'::jsonb);
END $$;


ALTER FUNCTION builderad_private.purpose_markets_at(b bigint, v bigint) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_purpose_enroll(p_session text, p_business uuid, p_command uuid, p_confirmed boolean, p_expected_previous bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;q public.authority_qualification_receipts;
 g public.security_action_grants;prior_g public.security_action_grants;prior_q public.authority_qualification_receipts;
 basis public.security_action_grants;basis_q public.authority_qualification_receipts;authenticated timestamptz;
BEGIN
 IF p_command IS NULL OR p_confirmed IS DISTINCT FROM true OR p_expected_previous IS NULL OR p_expected_previous<0
 THEN RAISE EXCEPTION 'Explicit confirmed Purpose statement enrollment required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 SELECT * INTO STRICT basis FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint;
 SELECT * INTO STRICT basis_q FROM public.authority_qualification_receipts WHERE qualification_id=basis.qualification_id;
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR basis_q.policy_id<>'self_declared_draft' OR basis_q.policy_version<>1
 OR basis_q.issuer_kind<>'self_declared_draft' OR basis.parent_grant_id IS NOT NULL OR basis.source<>'business_draft_creation'
 OR basis.delegable OR actor<>(SELECT created_by FROM public.businesses WHERE id=b AND origin_kind='self_declared_draft')
 THEN RAISE EXCEPTION 'Current original Business creator basis required' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh Purpose statement confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'confirmed',p_confirmed,'previous',p_expected_previous),' {"business":"U","previous":"I"}'::jsonb);
 result:=builderad_private.command_replay(b,actor,p_command,'business.purpose.enroll',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 SELECT x.* INTO prior_g FROM public.security_action_grants x JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=x.qualification_id
 WHERE x.business_id=b AND x.subject_user_id=actor AND qualified.policy_id='business_purpose_statement_v1' ORDER BY x.id DESC LIMIT 1;
 IF coalesce(prior_g.id,0)<>p_expected_previous THEN RAISE EXCEPTION 'Reviewed Purpose qualification changed' USING ERRCODE='40001'; END IF;
 IF prior_g.id IS NOT NULL THEN
  SELECT * INTO STRICT prior_q FROM public.authority_qualification_receipts WHERE qualification_id=prior_g.qualification_id;
  IF prior_g.status='active' AND prior_g.revoked_at IS NULL AND(prior_g.expires_at IS NULL OR prior_g.expires_at>clock_timestamp())
   AND prior_q.status='active' AND prior_q.valid_from<=clock_timestamp() AND(prior_q.valid_until IS NULL OR prior_q.valid_until>clock_timestamp())
  THEN RAISE EXCEPTION 'Reconcile existing Purpose enrollment' USING ERRCODE='40001'; END IF;
 END IF;
 proof:=proof||jsonb_build_object('custody_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.purpose.enroll',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_purpose_statement_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=basis.id;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;q.allowed_actions:=ARRAY['business.purpose.declare'];
 q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis_q.valid_until;
 q.provenance:='Original creator explicitly opts into own Business Purpose statements; no external object ownership, provider/source access, data use, representation or onward delegation';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:='business.purpose.declare';g.scope_kind:='business';g.business_id:=b;
 SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
 g.reason:='Explicit own Business Purpose statement qualification';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
 g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.expires_at:=basis.expires_at;g.delegable:=false;g.delegation_realm_kind:='direct';
 PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',jsonb_build_object('business.purpose.declare',g.id::text),'qualification_ref',q.qualification_id,
  'authority_scope','own_business_statement_only','establishes_external_authority',false,'versions',jsonb_build_object('qualification',1,'grant',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.purpose.enroll',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_purpose_enroll(p_session text, p_business uuid, p_command uuid, p_confirmed boolean, p_expected_previous bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_purpose_snapshot(p_session text, p_business uuid, p_read bigint, p_purpose uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;selection jsonb;context_version bigint;heads jsonb;result jsonb;now_value timestamptz;proof jsonb;history_available boolean;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Exact own Business read path required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);PERFORM builderad_private.principal(p_session);
 history_available:=selection->>'realm_kind'<>'internal_team' AND proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'business_declaration_team_admin_v1' AND proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'internal_client_file_admin_v1';
 SELECT h.context_version INTO context_version FROM public.business_context_heads h WHERE business_id=b FOR SHARE;
 PERFORM 1 FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose) ORDER BY id FOR SHARE;
 IF (SELECT count(*) FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose))>64 THEN RAISE EXCEPTION 'Select exact Purpose; bounded projection unavailable' USING ERRCODE='22023'; END IF;
 IF p_purpose IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.object_purposes WHERE business_id=b AND id=p_purpose) THEN RAISE EXCEPTION 'Purpose unavailable in this Business' USING ERRCODE='42501'; END IF;
 now_value:=clock_timestamp();
 -- Current record authority never releases the temporal journal to the app.
 -- The last accepted transition starting by now cuts all earlier segments,
 -- including when it withdraws or has already reached its exclusive end.
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',h.id,'head_version',h.head_version,'history',
  CASE WHEN history_available THEN (SELECT coalesce(jsonb_agg((to_jsonb(r)-ARRAY['business_id','actor_user_id','actor_generation','business_authority_epoch','command_id']) ORDER BY r.version),'[]') FROM public.object_purpose_revisions r WHERE r.business_id=b AND r.purpose_id=h.id) ELSE '[]'::jsonb END,
  'current',CASE WHEN NOT history_available THEN (SELECT jsonb_build_object('purpose_id',r.purpose_id,'revision_id',r.revision_id,'version',r.version,
   'operation',r.operation,'effective_from',r.effective_from,'effective_until',r.effective_until,'accepted_at',r.accepted_at,'snapshot',r.snapshot)
   FROM (SELECT * FROM public.object_purpose_revisions WHERE business_id=b AND purpose_id=h.id AND accepted_at<=now_value AND effective_from<=now_value ORDER BY version DESC LIMIT 1) r
   WHERE r.operation<>'withdraw' AND(r.effective_until IS NULL OR now_value<r.effective_until)) ELSE NULL END) ORDER BY h.id),'[]') INTO heads
 FROM public.object_purposes h WHERE h.business_id=b AND(p_purpose IS NULL OR h.id=p_purpose);
 result:=jsonb_build_object('business_public_id',p_business,'heads',heads,'history_available',history_available,'context_version',context_version,'dtm_version',builderad_private.purpose_latest_markets(b),'scope','native_business_purposes_only');
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Complete Purpose projection exceeds bound' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN result||jsonb_build_object('read_at',now_value);
END $$;


ALTER FUNCTION public.builderad_v2_business_purpose_snapshot(p_session text, p_business uuid, p_read bigint, p_purpose uuid) OWNER TO builderad_scope_executor;

CREATE FUNCTION public.builderad_v2_business_purpose_write(p_session text, p_business uuid, p_command jsonb, p_expected_context bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
 result:=builderad_private.apply_object_purpose(b,actor,p_command)||jsonb_build_object('business_public_id',p_business,'versions',jsonb_build_object('context',context_version));
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.purpose.declare',p_selection,p_session);
 PERFORM builderad_private.record_command(b,actor,cid,'business.purpose.declare',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_business_purpose_write(p_session text, p_business uuid, p_command jsonb, p_expected_context bigint, p_selection jsonb) OWNER TO builderad_scope_executor;

-- Preserve accepted function privileges.
REVOKE ALL ON FUNCTION public.builderad_object_purpose_instant(v jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_object_purpose_instant(v jsonb) TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_object_purpose_keys(v jsonb, expected text[]) TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_object_purpose_normalize(v jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_object_purpose_normalize(v jsonb) TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_object_purpose_text(v jsonb, maximum integer, required boolean) TO builderad_scope_executor;
