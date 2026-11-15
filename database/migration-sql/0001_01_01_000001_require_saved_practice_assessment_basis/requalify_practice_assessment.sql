-- Replace the function as its existing owner inside the migration transaction.
-- A forward migration must reject catalog drift before recording a new seal.
SET LOCAL ROLE builderad_scope_executor;
DO $verify$
BEGIN
 IF builderad_private.catalog_digest() IS DISTINCT FROM builderad_private.installation_digest() THEN
  RAISE EXCEPTION 'Assessment replay migration requires a sealed predecessor catalog';
 END IF;
END $verify$;
RESET ROLE;

GRANT CREATE ON SCHEMA public TO builderad_scope_executor;
SET LOCAL ROLE builderad_scope_executor;

CREATE OR REPLACE FUNCTION public.builderad_v2_customer_discovery_practice(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_expected bigint, p_ref jsonb, p_purpose text, p_relation jsonb, p_question jsonb, p_read jsonb, p_material jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE actor bigint;b bigint;material jsonb;recipient uuid;proof jsonb;payload bytea;r public.business_practice_revisions;a public.business_practice_assessments;prior public.business_practice_assessments;result jsonb;plan jsonb;known jsonb;
 subject_basis jsonb;subject_binding text;prior_binding jsonb;
 absent_binding CONSTANT text:=encode(sha256(convert_to('practice_assessment_subject_basis_absent:1','UTF8')),'hex');
BEGIN
 IF p_command IS NULL OR p_expected IS NULL OR p_expected<1 OR p_ref->>'namespace' NOT IN('market.knowledge','market.business_finding') THEN RAISE EXCEPTION 'Exact practice evaluation required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_relation,ARRAY['basis','applicability','rationale']);
 IF p_relation->>'basis' IS NULL OR p_relation->>'basis' NOT IN('business_declared','unknown','inferred_unverified','conflicting')
 OR p_relation->>'applicability' IS NULL OR p_relation->>'applicability' NOT IN('related','unrelated','unknown') OR jsonb_typeof(p_relation->'rationale')<>'string' OR length(p_relation->>'rationale')>1000 THEN RAISE EXCEPTION 'Explicit relation provenance required; no qualified issuer implied' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_question,ARRAY['geography','period','offering']);
 IF p_question->'geography'<>'null'::jsonb THEN
  PERFORM builderad_private.market_keys(p_question->'geography',ARRAY['authority','level','ref']);
  IF EXISTS(SELECT 1 FROM jsonb_each(p_question->'geography') v WHERE jsonb_typeof(v.value)<>'string' OR length(v.value#>>'{}') NOT BETWEEN 1 AND 160) THEN RAISE EXCEPTION 'Bounded question geography required' USING ERRCODE='22023'; END IF;
  IF p_question#>>'{geography,authority}'='iso3166-1' AND(p_question#>>'{geography,level}'<>'country' OR NOT EXISTS(SELECT 1 FROM public.country_references WHERE code::text=p_question#>>'{geography,ref}')) THEN RAISE EXCEPTION 'Known canonical question country required' USING ERRCODE='22023'; END IF;
 END IF;
 IF p_question->'period'<>'null'::jsonb THEN
  PERFORM builderad_private.market_keys(p_question->'period',ARRAY['from','until']);
  IF EXISTS(SELECT 1 FROM jsonb_each(p_question->'period') v WHERE jsonb_typeof(v.value)<>'string' OR(v.value#>>'{}') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$') THEN RAISE EXCEPTION 'Timezone-qualified period required' USING ERRCODE='22023'; END IF;
  IF NOT isfinite((p_question#>>'{period,from}')::timestamptz) OR NOT isfinite((p_question#>>'{period,until}')::timestamptz) OR(p_question#>>'{period,from}')::timestamptz>=(p_question#>>'{period,until}')::timestamptz THEN RAISE EXCEPTION 'Finite question period required' USING ERRCODE='22023'; END IF;
 END IF;
 IF jsonb_typeof(p_question->'offering') NOT IN('null','string') OR length(p_question->>'offering')>500 THEN RAISE EXCEPTION 'Bounded question offering required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);SELECT public_id INTO recipient FROM public.users WHERE id=actor;
 -- Existing material owner acquires resources before Business/authority, and requalifies after waits.
 material:=public.builderad_v2_scoped_material_read(p_session,p_business,uuidv7(),jsonb_build_array(p_ref),'display',p_purpose,recipient,p_material);
 subject_basis:=NULLIF(material#>'{materials,0,fields,practice_basis}','null'::jsonb);
 subject_binding:=encode(sha256(convert_to(subject_basis::text,'UTF8')),'hex');
 b:=builderad_private.lock_business(p_business);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
 SELECT * INTO r FROM public.business_practice_revisions WHERE business_id=b AND practice_id=p_practice ORDER BY revision DESC LIMIT 1;
 IF r.practice_id IS NULL THEN RAISE EXCEPTION 'Same Business practice required' USING ERRCODE='42501'; END IF;
 payload:=sha256(convert_to(jsonb_build_object('practice',p_practice,'revision',p_expected,'material',p_ref,'purpose',p_purpose,'relation',p_relation,'question',p_question)::text,'UTF8'));
 known:=builderad_private.command_replay(b,actor,p_command,'business.practice.assess',payload);
 SELECT * INTO prior FROM public.business_practice_assessments WHERE assessment_id=p_command;
 IF (known IS NULL)<>(prior.assessment_id IS NULL) THEN RAISE EXCEPTION 'Exact assessment command receipt required' USING ERRCODE='42501'; END IF;
 IF prior.assessment_id IS NOT NULL THEN
  IF prior.business_id<>b OR prior.actor_id<>actor OR prior.payload_digest<>payload THEN RAISE EXCEPTION 'Assessment command conflict' USING ERRCODE='40001'; END IF;
  prior_binding:=prior.result#>'{relation,subject_binding}';
  -- Only new assessments can attest that no subject basis was used. Keep
  -- that limited judgment even if this retry can now read more fields.
  IF prior_binding=to_jsonb(absent_binding) THEN
   subject_basis:=NULL;
   subject_binding:=NULL;
  ELSIF subject_binding IS NULL OR prior_binding IS DISTINCT FROM to_jsonb(subject_binding) THEN
   RAISE EXCEPTION 'Exact assessment subject basis required for replay' USING ERRCODE='42501';
  END IF;
  result:=prior.result||jsonb_build_object('historical_receipt',true,'currentness',CASE WHEN prior.practice_digest IS DISTINCT FROM builderad_private.practice_dependency(r) OR material#>>'{materials,0,fields,finding,validity,status}' IS DISTINCT FROM 'current' THEN 'review_required' ELSE 'current' END,'current_practice_revision',r.revision);
  result:=jsonb_set(result,'{relation}',(result->'relation')||jsonb_build_object('subject_basis',subject_basis,'subject_binding',subject_binding));
 ELSE
  IF r.revision<>p_expected OR r.state<>'active' THEN RAISE EXCEPTION 'Reviewed active practice revision required' USING ERRCODE='B4090'; END IF;
  result:=builderad_private.assess_practice(material#>'{materials,0,fields,finding}',subject_basis,builderad_private.practice_projection(r),p_relation,p_question);
  result:=result||jsonb_build_object('assessment_id',p_command,'business_public_id',p_business,'practice_id',r.practice_id,'practice_revision',r.revision,'material_ref',p_ref,'method_ref','practice_interest_eligibility:1',
   'relation',p_relation||jsonb_build_object('subject_basis',subject_basis,'subject_binding',subject_binding),
   'question',p_question,'historical_receipt',false,'currentness','current','current_practice_revision',r.revision);
  a.assessment_id:=p_command;a.business_id:=b;a.practice_id:=r.practice_id;a.practice_revision:=r.revision;a.actor_id:=actor;a.payload_digest:=payload;
  -- Retain server-owned references, declaration and bounded conclusion only.
  -- Display permission does not grant retention of the licensed subject payload.
  a.result:=jsonb_set(result#-'{relation,subject_basis}','{relation,subject_binding}',to_jsonb(coalesce(subject_binding,absent_binding)));
  a.material_ref:=p_ref;a.practice_digest:=builderad_private.practice_dependency(r);a.recorded_at:=clock_timestamp();
  -- The nested material owner has finished its invocation. Its immutable read
  -- receipt remains; release only the completed transaction-local permit slot.
  DELETE FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='completed';
  PERFORM builderad_private.start_command(actor,p_session,p_command,'business.practice.assess',jsonb_build_object('business_id',b),proof);
  PERFORM builderad_private.permit('public.business_practice_assessments','insert',NULL,to_jsonb(a));INSERT INTO public.business_practice_assessments SELECT(a).*;
  PERFORM builderad_private.record_command(b,actor,p_command,'business.practice.assess',payload,jsonb_build_object('assessment_id',p_command,'practice_id',r.practice_id,'versions',jsonb_build_object('practice',r.revision)));
  PERFORM builderad_private.finish_command();
 END IF;
 plan:=builderad_private.material_use_plan(actor,p_business,jsonb_build_array(p_ref),'display',p_purpose,p_material);
 PERFORM builderad_private.require_material_use(actor,b,p_session,'display',plan,p_material);
 IF (CASE p_ref->>'namespace' WHEN 'market.knowledge' THEN builderad_private.knowledge_validity(p_ref) ELSE builderad_private.business_finding_validity(p_ref) END)->>'status' IS DISTINCT FROM 'current'
 THEN result:=result||jsonb_build_object('currentness','review_required'); END IF;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $_$;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM builderad_scope_executor;

DO $seal$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.catalog_digest();
 RESET ROLE;
 -- The existing installer-owned, restricted function stores immutable bytes.
 EXECUTE format('CREATE OR REPLACE FUNCTION builderad_private.installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L', 'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
END $seal$;
