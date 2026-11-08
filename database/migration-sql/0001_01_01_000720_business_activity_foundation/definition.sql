-- Additive Business declaration owner. No changes to historical SQL or grants.
CREATE TABLE public.business_practice_revisions (
 business_id bigint NOT NULL REFERENCES public.businesses(id) ON DELETE RESTRICT,
 practice_id uuid NOT NULL CHECK(uuid_extract_version(practice_id) IS NOT DISTINCT FROM 7),
 revision bigint NOT NULL CHECK(revision>0),
 operation text NOT NULL CHECK(operation IN('create','update','correct','reclassify','deactivate')),
 corrects_revision bigint, correction_reason text,
 state text NOT NULL CHECK(state IN('active','inactive')),
 value jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 command_id uuid NOT NULL UNIQUE, recorded_at timestamptz NOT NULL,
 PRIMARY KEY(business_id,practice_id,revision),
 CHECK ((operation='correct' AND corrects_revision IS NOT NULL AND correction_reason IS NOT NULL) OR (operation<>'correct' AND corrects_revision IS NULL AND correction_reason IS NULL)),
 CHECK (corrects_revision IS NULL OR corrects_revision=revision-1),
 CHECK (correction_reason IS NULL OR length(btrim(correction_reason)) BETWEEN 3 AND 1000),
 FOREIGN KEY(business_id,practice_id,corrects_revision) REFERENCES public.business_practice_revisions(business_id,practice_id,revision)
);
CREATE UNIQUE INDEX business_practice_identity ON public.business_practice_revisions(practice_id) WHERE revision=1;
GRANT ALL ON public.business_practice_revisions TO builderad_owner;

DO $$
BEGIN
 REVOKE ALL ON public.business_practice_revisions FROM PUBLIC,builderad_app,builderad_authority;
 ALTER TABLE public.business_practice_revisions ENABLE ROW LEVEL SECURITY;
 ALTER TABLE public.business_practice_revisions FORCE ROW LEVEL SECURITY;
 CREATE POLICY practice_owner ON public.business_practice_revisions TO builderad_owner USING(true) WITH CHECK(true);
 CREATE POLICY practice_read ON public.business_practice_revisions FOR SELECT TO builderad_scope_executor USING(true);
 CREATE POLICY practice_insert ON public.business_practice_revisions FOR INSERT TO builderad_scope_executor
 WITH CHECK(builderad_private.effect_allowed('public.business_practice_revisions'::regclass,'insert',to_jsonb(business_practice_revisions.*)));
 CREATE TRIGGER zz_practice_effect BEFORE INSERT OR UPDATE OR DELETE ON public.business_practice_revisions FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
 CREATE TRIGGER zz_practice_truncate BEFORE TRUNCATE ON public.business_practice_revisions FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
END $$;

CREATE FUNCTION builderad_private.practice_value(p_value jsonb) RETURNS jsonb
LANGUAGE plpgsql STABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE k text;item jsonb;v jsonb:=p_value;
BEGIN
 PERFORM builderad_private.market_keys(v,ARRAY['name','description','kind','sectors','specializations','offering','commercial_model','channels','target_markets','fulfillment']);
 IF jsonb_typeof(v->'name') IS DISTINCT FROM 'string' OR length(btrim(v->>'name')) NOT BETWEEN 2 AND 120
 OR jsonb_typeof(v->'description') IS DISTINCT FROM 'string' OR length(v->>'description')>2000
 OR v->>'kind' IS NULL OR v->>'kind' NOT IN('sale','installation','maintenance','service','production','brokerage','rental','other')
 THEN RAISE EXCEPTION 'Bounded practice name, description and kind required' USING ERRCODE='22023'; END IF;
 FOREACH k IN ARRAY ARRAY['offering','commercial_model'] LOOP
  IF jsonb_typeof(v->k) NOT IN('null','string') OR length(v->>k)>500 THEN RAISE EXCEPTION 'Bounded optional declaration required' USING ERRCODE='22023'; END IF;
 END LOOP;
 FOREACH k IN ARRAY ARRAY['sectors','specializations','channels'] LOOP
  IF jsonb_typeof(v->k) IS DISTINCT FROM 'array' OR jsonb_array_length(v->k)>20 THEN RAISE EXCEPTION 'Bounded declaration list required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(v->k) LOOP
   IF jsonb_typeof(item) IS DISTINCT FROM 'string' OR length(btrim(item#>>'{}')) NOT BETWEEN 1 AND 120 THEN RAISE EXCEPTION 'Bounded declared label required' USING ERRCODE='22023'; END IF;
  END LOOP;
 END LOOP;
 -- Geography keys are issuer/level scoped, not free text used for automatic matching.
 FOREACH k IN ARRAY ARRAY['target_markets','fulfillment'] LOOP
  IF jsonb_typeof(v->k) IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Explicit geographic declaration required' USING ERRCODE='22023'; END IF;
  PERFORM builderad_private.market_keys(v->k,ARRAY['status','areas']);
  IF v#>>ARRAY[k,'status'] IS NULL OR v#>>ARRAY[k,'status'] NOT IN('unspecified','declared')
   OR jsonb_typeof(v#>ARRAY[k,'areas']) IS DISTINCT FROM 'array' OR jsonb_array_length(v#>ARRAY[k,'areas'])>50
   OR(v#>>ARRAY[k,'status']='unspecified' AND v#>ARRAY[k,'areas']<>'[]'::jsonb)
  THEN RAISE EXCEPTION 'Declared or unspecified geography required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(v#>ARRAY[k,'areas']) LOOP
   PERFORM builderad_private.market_keys(item,ARRAY['authority','level','ref']);
   IF EXISTS(SELECT 1 FROM jsonb_each(item) x WHERE jsonb_typeof(x.value)<>'string' OR length(x.value#>>'{}') NOT BETWEEN 1 AND 160)
   THEN RAISE EXCEPTION 'Scoped geography reference required' USING ERRCODE='22023'; END IF;
   IF item->>'authority'='iso3166-1' AND(item->>'level'<>'country' OR NOT EXISTS(SELECT 1 FROM public.country_references WHERE code::text=item->>'ref')) THEN RAISE EXCEPTION 'Known canonical country reference required' USING ERRCODE='22023'; END IF;
  END LOOP;
 END LOOP;
 IF octet_length(v::text)>24000 THEN RAISE EXCEPTION 'Practice declaration too large' USING ERRCODE='22023'; END IF;
 RETURN v;
END $$;

CREATE FUNCTION builderad_private.practice_projection(r public.business_practice_revisions) RETURNS jsonb
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT jsonb_build_object('practice_id',r.practice_id,'revision',r.revision,'operation',r.operation,'corrects_revision',r.corrects_revision,'correction_reason',r.correction_reason,'state',r.state,'value',r.value,'recorded_at',r.recorded_at);
$$;

CREATE FUNCTION builderad_v2_business_practices(p_session text,p_business uuid,p_access bigint,p_practice uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;result jsonb;p_selection jsonb;history_allowed boolean;
BEGIN
 p_selection:=builderad_private.business_selection(p_access);
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 history_allowed:=p_selection->>'realm_kind'<>'internal_team' AND proof#>>'{qualification,policy_id}'<>'business_declaration_team_admin_v1';
 SELECT jsonb_build_object('business_public_id',p_business,'history_available',history_allowed,'practices',coalesce(jsonb_agg(builderad_private.practice_projection(r) ORDER BY r.practice_id),'[]'::jsonb)) INTO result
 FROM(SELECT DISTINCT ON(practice_id) * FROM public.business_practice_revisions WHERE business_id=b AND(p_practice IS NULL OR practice_id=p_practice) ORDER BY practice_id,revision DESC) r;
 IF p_practice IS NOT NULL AND history_allowed THEN
  result:=result||jsonb_build_object('history',coalesce((SELECT jsonb_agg(builderad_private.practice_projection(r) ORDER BY revision DESC) FROM public.business_practice_revisions r WHERE business_id=b AND practice_id=p_practice),'[]'::jsonb));
 END IF;
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;

CREATE FUNCTION builderad_v2_business_practice_write(p_session text,p_business uuid,p_command uuid,p_practice uuid,p_operation text,p_expected bigint,p_value jsonb,p_selection jsonb,p_read jsonb,p_correction_reason text DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;prior public.business_practice_revisions;r public.business_practice_revisions;
BEGIN
 IF p_command IS NULL OR p_practice IS NULL OR uuid_extract_version(p_practice) IS DISTINCT FROM 7 OR p_expected IS NULL OR p_expected<0
 OR p_operation IS NULL OR p_operation NOT IN('create','update','correct','reclassify','deactivate') THEN RAISE EXCEPTION 'Exact practice command required' USING ERRCODE='22023'; END IF;
 IF (p_operation='correct' AND (p_correction_reason IS NULL OR length(btrim(p_correction_reason)) NOT BETWEEN 3 AND 1000))
 OR (p_operation<>'correct' AND p_correction_reason IS NOT NULL) THEN RAISE EXCEPTION 'Correction requires an explicit bounded explanation' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 -- Idempotency never grants read access to an earlier declaration/receipt.
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
 payload:=sha256(convert_to(jsonb_build_object('practice',p_practice,'operation',p_operation,'expected',p_expected,'value',p_value,'correction_reason',p_correction_reason)::text,'UTF8'));
 result:=builderad_private.command_replay(b,actor,p_command,'business.practice.write',payload);
 IF result IS NOT NULL THEN
  PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
  PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
  RETURN result||jsonb_build_object('historical_receipt',true,'current_revision',(SELECT max(revision) FROM public.business_practice_revisions WHERE business_id=b AND practice_id=p_practice));
 END IF;
 SELECT * INTO prior FROM public.business_practice_revisions WHERE business_id=b AND practice_id=p_practice ORDER BY revision DESC LIMIT 1;
 IF coalesce(prior.revision,0)<>p_expected OR(p_operation='create')<>(prior.revision IS NULL) OR prior.state='inactive'
 THEN RAISE EXCEPTION 'Practice revision or lifecycle changed' USING ERRCODE='B4090'; END IF;
 IF p_operation='create' AND EXISTS(SELECT 1 FROM public.business_practice_revisions WHERE practice_id=p_practice) THEN RAISE EXCEPTION 'Practice identity already used' USING ERRCODE='40001'; END IF;
 r.business_id:=b;r.practice_id:=p_practice;r.revision:=p_expected+1;r.operation:=p_operation;r.state:=CASE p_operation WHEN 'deactivate' THEN 'inactive' ELSE 'active' END;
 IF p_operation='deactivate' THEN
  IF p_value IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Deactivation preserves declaration' USING ERRCODE='22023'; END IF;
  r.value:=prior.value;
 ELSE r.value:=builderad_private.practice_value(p_value); END IF;
 IF p_operation NOT IN('create','deactivate') THEN
  IF r.value=prior.value THEN RAISE EXCEPTION 'A revision requires an actual change' USING ERRCODE='22023'; END IF;
  IF p_operation='update' AND (r.value->'kind' IS DISTINCT FROM prior.value->'kind'
   OR (r.value-'sectors'-'specializations') IS NOT DISTINCT FROM (prior.value-'sectors'-'specializations'))
  THEN RAISE EXCEPTION 'Use classification or explained correction; a different practice needs its own identity' USING ERRCODE='22023'; END IF;
  IF p_operation='reclassify' AND (r.value-'sectors'-'specializations') IS DISTINCT FROM (prior.value-'sectors'-'specializations')
  THEN RAISE EXCEPTION 'Reclassification preserves the economic practice and its context' USING ERRCODE='22023'; END IF;
  IF p_operation='correct' THEN
   IF r.value->'kind' IS DISTINCT FROM prior.value->'kind' AND (nullif(btrim(prior.value->>'offering'),'') IS NULL OR r.value->'offering' IS DISTINCT FROM prior.value->'offering')
   THEN RAISE EXCEPTION 'Correction must retain an economic identity anchor; create a different practice separately' USING ERRCODE='22023'; END IF;
   r.corrects_revision:=prior.revision;r.correction_reason:=btrim(p_correction_reason);
  END IF;
 END IF;
 r.actor_id:=actor;r.command_id:=p_command;r.recorded_at:=clock_timestamp();
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.practice.write',jsonb_build_object('business_id',b),proof);
 PERFORM builderad_private.permit('public.business_practice_revisions','insert',NULL,to_jsonb(r));INSERT INTO public.business_practice_revisions SELECT(r).*;
 result:=jsonb_build_object('business_public_id',p_business,'practice',builderad_private.practice_projection(r),'historical_receipt',false,'current_revision',r.revision,'versions',jsonb_build_object('practice',r.revision));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.practice.write',payload,result);
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.manage',p_selection,p_session);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_read,p_session);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;

REVOKE ALL ON FUNCTION builderad_private.practice_value(jsonb),builderad_private.practice_projection(public.business_practice_revisions) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_v2_business_practices(text,uuid,bigint,uuid),builderad_v2_business_practice_write(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb,jsonb,text) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_business_practices(text,uuid,bigint,uuid),builderad_v2_business_practice_write(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb,jsonb,text) TO builderad_app;

-- Explicit new field entitlement, never implicitly added to finding-only scopes.
ALTER FUNCTION builderad_private.registered_producer_fields(jsonb,jsonb) RENAME TO registered_producer_fields_before_activity;
CREATE FUNCTION builderad_private.registered_producer_fields(p_ref jsonb,p_fields jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE reads jsonb;edge jsonb;row_value public.market_evidence_revisions;source uuid;items jsonb:='[]';result jsonb:='{}';fields text[]:=ARRAY['subject','geography','metric','comparison_basis'];
BEGIN
 IF NOT p_fields ? 'practice_basis' THEN RETURN builderad_private.registered_producer_fields_before_activity(p_ref,p_fields); END IF;
 IF p_ref->>'namespace' NOT IN('market.knowledge','market.business_finding') OR p_fields IS DISTINCT FROM '["finding","practice_basis"]'::jsonb
 THEN RAISE EXCEPTION 'Exact separately licensed practice projection required' USING ERRCODE='42501'; END IF;
 result:=builderad_private.registered_producer_fields_before_activity(p_ref,'["finding"]');
 IF p_ref->>'namespace'='market.knowledge' THEN
  SELECT input_reads INTO reads FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 ELSE SELECT input_reads INTO reads FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid; END IF;
 FOR edge IN SELECT value FROM jsonb_array_elements(reads) LOOP
  SELECT r.* INTO row_value FROM public.market_evidence_revisions r WHERE r.evidence_id=(edge#>>'{input,key}')::uuid AND r.content_digest=decode(edge#>>'{input,version}','hex');
  SELECT source_id INTO source FROM public.market_evidence_heads WHERE evidence_id=row_value.evidence_id;
  -- The projection has meaning only when the captured method actually consumed these fields.
  IF NOT coalesce(edge->'field_keys','[]'::jsonb) @> to_jsonb(fields) OR row_value.evidence_type<>'market.interest_signal:2' THEN CONTINUE; END IF;
  items:=items||jsonb_build_array(jsonb_build_object('source_ref',source,'subject',row_value.fields->'subject','geography',
    (row_value.fields->'geography')||CASE WHEN row_value.fields#>>'{geography,level}'='country' AND EXISTS(SELECT 1 FROM public.country_references WHERE code::text=row_value.fields#>>'{geography,ref}') THEN '{"authority":"iso3166-1"}'::jsonb ELSE '{}'::jsonb END,
    'metric',row_value.fields->'metric','period',jsonb_build_object('from',row_value.fields#>'{comparison_basis,period_start}','until',row_value.fields#>'{comparison_basis,period_end}'),
    'measurement',(row_value.fields->'comparison_basis')-'period_start'-'period_end','coverage',row_value.coverage));
 END LOOP;
 RETURN result||jsonb_build_object('practice_basis',jsonb_build_object('schema','market.practice_basis:1','signals',items));
END $$;
REVOKE ALL ON FUNCTION builderad_private.registered_producer_fields(jsonb,jsonb),builderad_private.registered_producer_fields_before_activity(jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE TABLE public.business_practice_assessments (
 assessment_id uuid PRIMARY KEY, business_id bigint NOT NULL REFERENCES public.businesses(id) ON DELETE RESTRICT,
 practice_id uuid NOT NULL, practice_revision bigint NOT NULL,
 actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 payload_digest bytea NOT NULL, result jsonb NOT NULL, material_ref jsonb NOT NULL,
 practice_digest bytea NOT NULL, recorded_at timestamptz NOT NULL,
 FOREIGN KEY(business_id,practice_id,practice_revision) REFERENCES public.business_practice_revisions(business_id,practice_id,revision) ON DELETE RESTRICT
);
GRANT ALL ON public.business_practice_assessments TO builderad_owner;
REVOKE ALL ON public.business_practice_assessments FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE public.business_practice_assessments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.business_practice_assessments FORCE ROW LEVEL SECURITY;
CREATE POLICY assessment_owner ON public.business_practice_assessments TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY assessment_read ON public.business_practice_assessments FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY assessment_insert ON public.business_practice_assessments FOR INSERT TO builderad_scope_executor
WITH CHECK(builderad_private.effect_allowed('public.business_practice_assessments'::regclass,'insert',to_jsonb(business_practice_assessments.*)));
CREATE TRIGGER zz_assessment_effect BEFORE INSERT OR UPDATE OR DELETE ON public.business_practice_assessments FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_assessment_truncate BEFORE TRUNCATE ON public.business_practice_assessments FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();

CREATE FUNCTION builderad_private.practice_dependency(r public.business_practice_revisions) RETURNS bytea
LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT sha256(convert_to(jsonb_build_object('state',r.state,'value',CASE WHEN nullif(btrim(r.value->>'offering'),'') IS NOT NULL THEN r.value-'name'-'channels' ELSE r.value-'channels' END)::text,'UTF8'));
$$;

CREATE FUNCTION builderad_private.assess_practice(p_finding jsonb,p_basis jsonb,p_practice jsonb,p_relation jsonb,p_question jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE status text:='inconclusive';reason text:='RELATION_UNQUALIFIED';signal jsonb;other jsonb;geo jsonb;fulfillment text:='unknown';target text:='unknown';relation text:=p_relation->>'basis';scoped boolean:=false;
BEGIN
 signal:=p_basis#>'{signals,0}';other:=p_basis#>'{signals,1}';geo:=signal->'geography';
 IF p_finding#>>'{validity,status}' IS DISTINCT FROM 'current' THEN status:='review_required';reason:='KNOWLEDGE_REQUIRES_REVIEW';
 ELSIF p_practice->>'state'<>'active' THEN status:='review_required';reason:='PRACTICE_INACTIVE';
 ELSIF relation='conflicting' THEN status:='review_required';reason:='RELATION_CONFLICT';
 ELSIF signal IS NULL OR jsonb_array_length(p_basis->'signals')<>2 OR signal->'subject' IS DISTINCT FROM other->'subject'
  OR signal->>'source_ref' IS DISTINCT FROM other->>'source_ref' OR signal#>>'{subject,identity_state}' IS DISTINCT FROM 'source_asserted'
  OR signal->'geography' IS DISTINCT FROM other->'geography' OR signal->'metric' IS DISTINCT FROM other->'metric'
  OR signal->'measurement' IS DISTINCT FROM other->'measurement' OR signal->>'coverage' NOT IN('COMPLETE','PARTIAL') OR other->>'coverage' NOT IN('COMPLETE','PARTIAL')
 THEN reason:='SUBJECT_OR_COVERAGE_UNKNOWN';
 ELSE
  -- Only a known shared reference system establishes disjoint geography; labels never do.
  IF p_question->'geography'<>'null'::jsonb AND p_question#>>'{geography,authority}'='iso3166-1'
   AND p_question#>>'{geography,level}'='country' AND geo->>'level'='country' AND geo->>'authority'='iso3166-1' AND p_question#>>'{geography,ref}' ~ '^[A-Z]{2}$' THEN
   scoped:=true;
   IF p_question#>>'{geography,ref}' IS DISTINCT FROM geo->>'ref' THEN status:='not_applicable';reason:='EVIDENCE_OUTSIDE_QUESTION_GEOGRAPHY'; END IF;
  END IF;
  IF status<>'not_applicable' AND p_question->'period'<>'null'::jsonb THEN
   IF (p_question#>>'{period,until}')::timestamptz<=(signal#>>'{period,from}')::timestamptz OR (p_question#>>'{period,from}')::timestamptz>=(other#>>'{period,until}')::timestamptz THEN
    status:='not_applicable';reason:='EVIDENCE_OUTSIDE_QUESTION_PERIOD';
   ELSIF (p_question#>>'{period,from}')::timestamptz IS DISTINCT FROM (signal#>>'{period,from}')::timestamptz OR (p_question#>>'{period,until}')::timestamptz IS DISTINCT FROM (other#>>'{period,until}')::timestamptz THEN
    reason:='QUESTION_PERIOD_NOT_MATCHED';RETURN jsonb_build_object('assessment','inconclusive','reason_code',reason,'relation_basis',relation,'relation_independently_verified',false,'fulfillment','unknown','target_market','unknown','supports_investigating_reach',false);
   END IF;
  END IF;
  IF status<>'not_applicable' AND(p_question->'geography'='null'::jsonb OR scoped)
   AND(p_question->'offering'='null'::jsonb OR p_question->'offering'=p_practice#>'{value,offering}')
   AND relation='business_declared' AND p_relation->>'applicability'='related'
   AND p_finding->>'method_ref'='qualified_interest_change:1' AND p_finding->>'status'='comparable'
   AND jsonb_typeof(p_finding#>'{values,delta}')='number' AND(p_finding#>>'{values,delta}')::numeric>0
  THEN status:='applicable';reason:='DECLARED_RELATION_QUALIFIED_INTEREST_RISE'; END IF;
  IF geo->>'level'='country' AND geo->>'authority'='iso3166-1' THEN
   IF p_practice#>>'{value,target_markets,status}'='declared' THEN
    target:=CASE WHEN p_practice#>'{value,target_markets,areas}' @> jsonb_build_array(jsonb_build_object('authority','iso3166-1','level','country','ref',geo->'ref')) THEN 'within_declared' ELSE 'not_listed_in_declaration' END;
   END IF;
   IF p_practice#>>'{value,fulfillment,status}'='declared' THEN
    fulfillment:=CASE WHEN p_practice#>'{value,fulfillment,areas}' @> jsonb_build_array(jsonb_build_object('authority','iso3166-1','level','country','ref',geo->'ref')) THEN 'within_declared' ELSE 'not_listed_in_declaration' END;
   END IF;
  END IF;
 END IF;
 RETURN jsonb_build_object('assessment',status,'reason_code',reason,'relation_basis',relation,'relation_independently_verified',false,
 'target_market',target,'fulfillment',fulfillment,'supports_investigating_reach',status='applicable',
 'limitations',jsonb_build_array('INTEREST_IS_NOT_BUYERS','REACH_AND_PROFITABILITY_UNPROVEN','BUSINESS_DECLARATION_IS_NOT_INDEPENDENT_VERIFICATION','FULFILLMENT_DECLARATION_IS_NOT_CAPACITY_PROOF'));
END $$;

CREATE FUNCTION builderad_v2_customer_discovery_practice(p_session text,p_business uuid,p_command uuid,p_practice uuid,p_expected bigint,p_ref jsonb,p_purpose text,p_relation jsonb,p_question jsonb,p_read jsonb,p_material jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;material jsonb;recipient uuid;proof jsonb;payload bytea;r public.business_practice_revisions;a public.business_practice_assessments;prior public.business_practice_assessments;result jsonb;plan jsonb;known jsonb;
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
  result:=prior.result||jsonb_build_object('historical_receipt',true,'currentness',CASE WHEN prior.practice_digest IS DISTINCT FROM builderad_private.practice_dependency(r) OR material#>>'{materials,0,fields,finding,validity,status}' IS DISTINCT FROM 'current' THEN 'review_required' ELSE 'current' END,'current_practice_revision',r.revision);
  result:=jsonb_set(result,'{relation,subject_basis}',CASE WHEN prior.result#>>'{relation,subject_binding}'=encode(sha256(convert_to((material#>'{materials,0,fields,practice_basis}')::text,'UTF8')),'hex') THEN material#>'{materials,0,fields,practice_basis}' ELSE 'null'::jsonb END);
 ELSE
  IF r.revision<>p_expected OR r.state<>'active' THEN RAISE EXCEPTION 'Reviewed active practice revision required' USING ERRCODE='B4090'; END IF;
  result:=builderad_private.assess_practice(material#>'{materials,0,fields,finding}',material#>'{materials,0,fields,practice_basis}',builderad_private.practice_projection(r),p_relation,p_question);
  result:=result||jsonb_build_object('assessment_id',p_command,'business_public_id',p_business,'practice_id',r.practice_id,'practice_revision',r.revision,'material_ref',p_ref,'method_ref','practice_interest_eligibility:1',
   'relation',p_relation||jsonb_build_object('subject_basis',material#>'{materials,0,fields,practice_basis}',
    'subject_binding',encode(sha256(convert_to((material#>'{materials,0,fields,practice_basis}')::text,'UTF8')),'hex')),'question',p_question,'historical_receipt',false,'currentness','current','current_practice_revision',r.revision);
  a.assessment_id:=p_command;a.business_id:=b;a.practice_id:=r.practice_id;a.practice_revision:=r.revision;a.actor_id:=actor;a.payload_digest:=payload;
  -- Retain server-owned references, declaration and bounded conclusion only.
  -- Display permission does not grant retention of the licensed subject payload.
  a.result:=result#-'{relation,subject_basis}';a.material_ref:=p_ref;a.practice_digest:=builderad_private.practice_dependency(r);a.recorded_at:=clock_timestamp();
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
END $$;
REVOKE ALL ON FUNCTION builderad_private.practice_dependency(public.business_practice_revisions),builderad_private.assess_practice(jsonb,jsonb,jsonb,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION builderad_v2_customer_discovery_practice(text,uuid,uuid,uuid,bigint,jsonb,text,jsonb,jsonb,jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_customer_discovery_practice(text,uuid,uuid,uuid,bigint,jsonb,text,jsonb,jsonb,jsonb,jsonb) TO builderad_app;

-- One qualified candidate per transaction preserves the existing sorted resource
-- lock boundary. A cursor advances even when that candidate cannot be disclosed.
CREATE FUNCTION builderad_v2_practice_knowledge_options(p_session text,p_business uuid,p_access bigint,p_after integer DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;candidate record;ref jsonb;selection jsonb;read_selection jsonb;material jsonb;recipient uuid;result jsonb;saved jsonb;
BEGIN
 IF p_after IS NULL OR p_after<0 OR p_after>10000 THEN RAISE EXCEPTION 'Bounded cursor required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 read_selection:=builderad_private.business_selection(p_access);
 -- No locks before the material owner's K1 resource preparation.
 SELECT bg.id business_grant,dg.id data_grant,s.purpose,s.resource,k.key INTO candidate
 FROM public.security_action_grants bg JOIN public.security_action_grants dg ON dg.subject_user_id=bg.subject_user_id
 JOIN public.data_use_scopes s ON s.scope_id=dg.data_use_scope_id
 CROSS JOIN LATERAL jsonb_array_elements_text(s.selection->'native_keys') k(key)
 WHERE bg.subject_user_id=actor AND bg.business_id=b AND bg.action='business.data.use' AND bg.scope_kind='business' AND bg.status='active' AND bg.revoked_at IS NULL
 AND dg.action='data_use.display' AND dg.scope_kind='data_use' AND dg.status='active' AND dg.revoked_at IS NULL
 AND bg.delegation_realm_kind=read_selection->>'realm_kind' AND dg.delegation_realm_kind=bg.delegation_realm_kind
 AND dg.delegation_workspace_id IS NOT DISTINCT FROM bg.delegation_workspace_id
 AND s.resource IN('market.knowledge','market.business_finding') AND s.status='active'
 AND s.selection->'field_keys'='["finding","practice_basis"]'::jsonb
 ORDER BY bg.id,dg.id,k.key LIMIT 1 OFFSET p_after;
 result:=jsonb_build_object('business_public_id',p_business,'options','[]'::jsonb,'next_cursor',NULL);
 IF candidate.data_grant IS NOT NULL THEN
  result:=result||jsonb_build_object('next_cursor',p_after+1);
  IF candidate.resource='market.knowledge' THEN SELECT jsonb_build_object('namespace',candidate.resource,'key',run_id,'version',encode(material_digest,'hex')) INTO ref FROM public.market_knowledge_captures WHERE run_id::text=candidate.key AND state='published';
  ELSE SELECT jsonb_build_object('namespace',candidate.resource,'key',artifact_id,'version',encode(material_digest,'hex')) INTO ref FROM public.market_business_finding_captures WHERE artifact_id::text=candidate.key AND state='published' AND business_id=b; END IF;
  IF ref IS NOT NULL THEN
   selection:=builderad_private.business_selection(candidate.business_grant);
   selection:=jsonb_set(selection,'{requirements}',(selection->'requirements')||jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',candidate.data_grant)));
   SELECT public_id INTO recipient FROM public.users WHERE id=actor;
   BEGIN
    material:=public.builderad_v2_scoped_material_read(p_session,p_business,uuidv7(),jsonb_build_array(ref),'display',candidate.purpose,recipient,selection);
    SELECT coalesce(jsonb_agg(x.item),'[]'::jsonb) INTO saved FROM(
     SELECT DISTINCT ON(a.practice_id) jsonb_build_object('practice_id',a.practice_id,'practice_revision',current_practice.revision,'assessment_id',a.assessment_id,
      'relation',(a.result->'relation')-'subject_basis'-'subject_binding','question',a.result->'question') item
     FROM public.business_practice_assessments a
     CROSS JOIN LATERAL(SELECT p.* FROM public.business_practice_revisions p WHERE p.business_id=b AND p.practice_id=a.practice_id ORDER BY p.revision DESC LIMIT 1) current_practice
     WHERE a.business_id=b AND a.actor_id=actor AND a.material_ref=ref AND a.practice_digest=builderad_private.practice_dependency(current_practice)
      AND a.result#>>'{relation,subject_binding}'=encode(sha256(convert_to((material#>'{materials,0,fields,practice_basis}')::text,'UTF8')),'hex')
     ORDER BY a.practice_id,a.recorded_at DESC LIMIT 32
    ) x;
    result:=result||jsonb_build_object('options',jsonb_build_array(jsonb_build_object('material_ref',ref,'purpose',candidate.purpose,'authority_selection',selection,
     'finding',material#>'{materials,0,fields,finding}','practice_basis',material#>'{materials,0,fields,practice_basis}','saved_relations',saved)));
   EXCEPTION WHEN insufficient_privilege THEN NULL; END;
  END IF;
 END IF;
 PERFORM builderad_private.lock_business(p_business);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_practice_knowledge_options(text,uuid,bigint,integer) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_practice_knowledge_options(text,uuid,bigint,integer) TO builderad_app;
