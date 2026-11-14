-- Name: advance_business_epoch(bigint); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.advance_business_epoch(p_business bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE old_f public.business_authority_fences;f public.business_authority_fences;
BEGIN
 SELECT * INTO STRICT old_f FROM public.business_authority_fences WHERE business_id=p_business;f:=old_f;f.auth_epoch:=f.auth_epoch+1;
 PERFORM builderad_private.permit('public.business_authority_fences','update',to_jsonb(old_f),to_jsonb(f));
 UPDATE public.business_authority_fences SET auth_epoch=f.auth_epoch WHERE business_id=p_business;
END $$;


ALTER FUNCTION builderad_private.advance_business_epoch(p_business bigint) OWNER TO builderad_scope_executor;


-- Name: advance_right_epoch(uuid); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.advance_right_epoch(p_fence uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.resource_right_fences;f public.resource_right_fences;
BEGIN
 SELECT * INTO STRICT prior FROM public.resource_right_fences WHERE fence_id=p_fence;f:=prior;f.rights_epoch:=prior.rights_epoch+1;
 PERFORM builderad_private.permit('public.resource_right_fences','update',to_jsonb(prior),to_jsonb(f));
 UPDATE public.resource_right_fences SET rights_epoch=f.rights_epoch WHERE fence_id=p_fence;
END $$;


ALTER FUNCTION builderad_private.advance_right_epoch(p_fence uuid) OWNER TO builderad_scope_executor;


-- Name: assert_invocation_current(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.assert_invocation_current() RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE i builderad_private.invocations;session_ref text;expired boolean;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 IF i.actor_user_id IS NOT NULL THEN
  SELECT session_key_hash INTO session_ref FROM public.security_sessions WHERE user_id=i.actor_user_id AND lifecycle_generation=i.generation
   AND sha256(convert_to(session_key_hash,'UTF8'))=i.session_ref_digest;
  IF session_ref IS NULL THEN RAISE EXCEPTION 'Final bound session unavailable' USING ERRCODE='42501'; END IF;
  IF i.action='customer.business.status' THEN
   IF public.builderad_customer_administrator(session_ref) IS DISTINCT FROM i.actor_user_id THEN
    RAISE EXCEPTION 'Current customer administrator required' USING ERRCODE='42501';
   END IF;
  ELSIF i.action IN('market.knowledge.capture','market.knowledge.publish') THEN
   PERFORM builderad_private.market_operator(session_ref,i.action,false);
  ELSE PERFORM builderad_private.principal(session_ref,false); END IF;
 END IF;
 WITH RECURSIVE walk(k,v,d) AS(
  SELECT ''::text,i.authority_selection,0 UNION ALL
  SELECT child.k,child.v,walk.d+1 FROM walk CROSS JOIN LATERAL(
   SELECT key k,value v FROM jsonb_each(CASE WHEN jsonb_typeof(walk.v)='object' THEN walk.v ELSE '{}'::jsonb END)
   UNION ALL SELECT ''::text,value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(walk.v)='array' THEN walk.v ELSE '[]'::jsonb END)
  ) child WHERE walk.d<33
 ) SELECT EXISTS(SELECT 1 FROM walk WHERE CASE
  WHEN k IN('expires_at','valid_until','valid_from') AND v<>'null'::jsonb THEN CASE
   WHEN jsonb_typeof(v)<>'string' THEN true
   WHEN k='valid_from' THEN(v#>>'{}')::timestamptz>clock_timestamp()
   ELSE(v#>>'{}')::timestamptz<=clock_timestamp() END
  ELSE false END) INTO expired;
 IF expired THEN RAISE EXCEPTION 'Selected authority expired after final receipt wait' USING ERRCODE='42501'; END IF;
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN
 RAISE EXCEPTION 'Typed final authority deadline unavailable' USING ERRCODE='42501';
END $$;


ALTER FUNCTION builderad_private.assert_invocation_current() OWNER TO builderad_scope_executor;


-- Name: assess_practice(jsonb, jsonb, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.assess_practice(p_finding jsonb, p_basis jsonb, p_practice jsonb, p_relation jsonb, p_question jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
END $_$;


ALTER FUNCTION builderad_private.assess_practice(p_finding jsonb, p_basis jsonb, p_practice jsonb, p_relation jsonb, p_question jsonb) OWNER TO builderad_scope_executor;


-- Name: atom(text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.atom(tag text, value jsonb) RETURNS text
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE v text;
BEGIN
 IF value IS NULL OR value='null'::jsonb THEN RETURN 'N0:'; END IF;
 v:=value#>>'{}';
 IF tag='D' THEN RETURN builderad_private.cb1(to_jsonb(v::numeric));
 ELSIF tag='I' THEN v:=(v::bigint)::text;
 ELSIF tag='U' THEN v:=(v::uuid)::text;
 ELSIF tag='T' THEN
  IF NOT isfinite(v::timestamptz) THEN RAISE EXCEPTION 'Finite CB1 instant required' USING ERRCODE='22023'; END IF;
  v:=to_char(v::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"');
 ELSIF tag='X' THEN v:=lower(CASE WHEN left(v,2)='\x' THEN substring(v FROM 3) ELSE v END);
  IF v !~ '^([0-9a-f]{2})*$' THEN RAISE EXCEPTION 'CB1 bytea required' USING ERRCODE='22023'; END IF;
 ELSIF tag='S' THEN
  IF octet_length(v)>65536 THEN RAISE EXCEPTION 'CB1 string limit' USING ERRCODE='22023'; END IF;
 ELSE RETURN builderad_private.cb1(value); END IF;
 RETURN builderad_private.frame(tag,v);
END $_$;


ALTER FUNCTION builderad_private.atom(tag text, value jsonb) OWNER TO builderad_scope_executor;


-- Name: authority_cb1(jsonb, text, integer); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.authority_cb1(v jsonb, field_name text DEFAULT ''::text, depth integer DEFAULT 0) RETURNS text
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE payload text;item record;child_field text;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'Authority snapshot limit' USING ERRCODE='22023'; END IF;
 IF v IS NULL OR v='null'::jsonb THEN RETURN 'N0:'; END IF;
 IF field_name='grant_row' THEN RETURN builderad_private.row_image('public.security_action_grants',v); END IF;
 IF field_name IN('qualification','qualification_row') THEN RETURN builderad_private.row_image('public.authority_qualification_receipts',v); END IF;
 IF jsonb_typeof(v)='number' THEN RETURN builderad_private.atom('I',v); END IF;
 IF jsonb_typeof(v)='string' AND field_name IN('business','command','boundary_id','source_id','scope_id','fence_id','cohort_id') THEN RETURN builderad_private.atom('U',v); END IF;
 IF jsonb_typeof(v)='array' THEN
  payload:=builderad_private.frame('I',jsonb_array_length(v)::text);
  child_field:=CASE field_name WHEN 'grants' THEN 'grant_row' WHEN 'qualifications' THEN 'qualification_row' WHEN 'relationship_qualifications' THEN 'qualification_row' WHEN 'relationships' THEN 'relationship_row' ELSE field_name END;
  FOR item IN SELECT value FROM jsonb_array_elements(v) LOOP payload:=payload||builderad_private.authority_cb1(item.value,child_field,depth+1); END LOOP;
  RETURN builderad_private.frame('A',payload);
 END IF;
 IF jsonb_typeof(v)='object' THEN
  payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(v)));
  FOR item IN SELECT key,value FROM jsonb_each(v) ORDER BY convert_to(key,'UTF8') LOOP
   IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'Typed authority schema required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.frame('S',item.key)||builderad_private.authority_cb1(item.value,item.key,depth+1);
  END LOOP;
  RETURN builderad_private.frame('O',payload);
 END IF;
 RETURN builderad_private.cb1(v);
END $_$;


ALTER FUNCTION builderad_private.authority_cb1(v jsonb, field_name text, depth integer) OWNER TO builderad_scope_executor;


-- Name: authority_digest(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.authority_digest(v jsonb) RETURNS bytea
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE encoded text;
BEGIN
 PERFORM builderad_private.bounded(v);encoded:=builderad_private.authority_cb1(v);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'Authority envelope limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/authority-selection:1','UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;


ALTER FUNCTION builderad_private.authority_digest(v jsonb) OWNER TO builderad_scope_executor;


-- Name: bounded(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.bounded(value jsonb) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE nodes integer; deepest integer;
BEGIN
 IF octet_length(value::text)>2097152 THEN RAISE EXCEPTION 'CB1 payload limit' USING ERRCODE='22023'; END IF;
 WITH RECURSIVE walk(v,d) AS (
  SELECT value,0 UNION ALL
  SELECT children.v,walk.d+1 FROM walk CROSS JOIN LATERAL (
   SELECT x.v FROM jsonb_each(CASE WHEN jsonb_typeof(walk.v)='object' THEN walk.v ELSE '{}'::jsonb END) AS x(k,v)
   UNION ALL SELECT x.v FROM jsonb_array_elements(CASE WHEN jsonb_typeof(walk.v)='array' THEN walk.v ELSE '[]'::jsonb END) AS x(v)
  ) children WHERE walk.d<33
 ) SELECT count(*),max(d) INTO nodes,deepest FROM (SELECT * FROM walk LIMIT 8194) bounded;
 IF nodes>8192 OR deepest>32 THEN RAISE EXCEPTION 'CB1 structure limit' USING ERRCODE='22023'; END IF;
END $$;


ALTER FUNCTION builderad_private.bounded(value jsonb) OWNER TO builderad_scope_executor;


-- Name: business_finding_descriptor(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.business_finding_descriptor(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace' IS DISTINCT FROM 'market.business_finding' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact private producer reference required' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid AND state IN('prepared','published') AND material_digest=decode(p_ref->>'version','hex');
 SELECT * INTO run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 IF r.artifact_id IS NULL OR run.completion_attestation_digest IS NULL OR run.state NOT IN('validated','abstained') OR run.finding IS NULL
 OR run.business_id IS DISTINCT FROM r.business_id OR run.actor_user_id IS DISTINCT FROM r.processor_user_id OR run.purpose IS DISTINCT FROM r.purpose THEN RAISE EXCEPTION 'Trusted same-Business producer required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'resolver_contract_version',1,'business_public_id',(SELECT public_id FROM public.businesses WHERE id=r.business_id),
  'processor_user_id',r.processor_user_id,'output_scope_id',r.output_scope_id,'purpose',r.purpose,'processing_operation','process','material_digest',p_ref->'version',
  'producer_receipt_ref','market_business_finding:'||r.artifact_id,'coverage',CASE run.state WHEN 'validated' THEN 'COMPLETE' ELSE 'PARTIAL' END,
  'provenance',jsonb_build_object('kind','private_market_finding'),'input_reads',r.input_reads,'publication_manifest_id',r.publication_manifest_id);
END $_$;


ALTER FUNCTION builderad_private.business_finding_descriptor(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: business_finding_fields(jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.business_finding_fields(p_ref jsonb, p_fields jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 IF p_fields IS DISTINCT FROM '["finding"]'::jsonb THEN RAISE EXCEPTION 'Exact private output field licence required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid;
 SELECT * INTO STRICT run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 RETURN jsonb_build_object('finding',(run.finding-'evidence_refs')||jsonb_build_object('validity',builderad_private.business_finding_validity(p_ref)));
END $$;


ALTER FUNCTION builderad_private.business_finding_fields(p_ref jsonb, p_fields jsonb) OWNER TO builderad_scope_executor;


-- Name: business_finding_publish(jsonb, uuid, bytea); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.business_finding_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.market_business_finding_captures;r public.market_business_finding_captures;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 SELECT * INTO STRICT prior FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid FOR UPDATE;r:=prior;
 IF builderad_private.business_finding_validity(p_ref)->>'status' IS DISTINCT FROM 'current' THEN RAISE EXCEPTION 'Private finding dependencies changed before publication' USING ERRCODE='42501'; END IF;
 IF prior.state<>'prepared' OR NOT EXISTS(SELECT 1 FROM public.material_lineage_manifests m WHERE m.manifest_id=p_manifest AND m.material_namespace='market.business_finding'
  AND m.material_key=prior.artifact_id::text AND m.material_digest=prior.material_digest AND m.closure_digest=p_closure AND m.resolver_contract_version=1 AND m.state='sealed')
 THEN RAISE EXCEPTION 'Exact atomic private publication manifest required' USING ERRCODE='42501'; END IF;
 r.state:='published';r.publication_manifest_id:=p_manifest;
 PERFORM builderad_private.permit('public.market_business_finding_captures','update',to_jsonb(prior),to_jsonb(r));
 UPDATE public.market_business_finding_captures SET state=r.state,publication_manifest_id=r.publication_manifest_id WHERE artifact_id=r.artifact_id;
END $$;


ALTER FUNCTION builderad_private.business_finding_publish(p_ref jsonb, p_manifest uuid, p_closure bytea) OWNER TO builderad_scope_executor;


-- Name: business_finding_validity(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.business_finding_validity(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;v jsonb;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid;
 SELECT * INTO STRICT run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 v:=builderad_private.captured_knowledge_validity(r.input_reads,run.method_ref);
 IF run.method_ref='business_feasibility:1' AND r.context_snapshot->>'context_version' IS DISTINCT FROM (SELECT context_version::text FROM public.business_context_heads WHERE business_id=r.business_id)
 THEN v:=jsonb_build_object('status','review_required'); END IF;
 RETURN v;
END $$;


ALTER FUNCTION builderad_private.business_finding_validity(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: canonical_data_selection(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.canonical_data_selection(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE k text;item jsonb;out_value jsonb:='{}';limit_count integer;
BEGIN
 IF v IS NULL OR jsonb_typeof(v)<>'object' OR octet_length(v::text)>262144 OR
 (SELECT array_agg(key ORDER BY key COLLATE "C") FROM jsonb_object_keys(v) key) IS DISTINCT FROM ARRAY['field_keys','native_keys','native_namespaces','partition_ref']::text[]
 THEN RAISE EXCEPTION 'Exact bounded data selection required' USING ERRCODE='22023'; END IF;
 -- No registered source resolver currently attests a partition selector.
 IF v->'partition_ref' IS DISTINCT FROM 'null'::jsonb THEN RAISE EXCEPTION 'Partition selector requires a registered resolver' USING ERRCODE='22023'; END IF;
 FOREACH k IN ARRAY ARRAY['native_namespaces','native_keys','field_keys'] LOOP
  limit_count:=CASE k WHEN 'native_namespaces' THEN 50 WHEN 'native_keys' THEN 1000 ELSE 100 END;
  IF jsonb_typeof(v->k) IS DISTINCT FROM 'array' OR jsonb_array_length(v->k) NOT BETWEEN 1 AND limit_count THEN RAISE EXCEPTION 'Explicit bounded selector set required' USING ERRCODE='22023'; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(v->k) LOOP
   IF jsonb_typeof(item)<>'string' OR length(item#>>'{}') NOT BETWEEN 1 AND 180 OR octet_length(item#>>'{}')>720
    OR(k='native_namespaces' AND((item#>>'{}') !~ '^[\x21-\x7e]{1,80}$')) THEN RAISE EXCEPTION 'Bounded opaque selector identifier required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF (SELECT count(*)<>count(DISTINCT value) FROM jsonb_array_elements(v->k)) THEN RAISE EXCEPTION 'Duplicate selector identifiers' USING ERRCODE='22023'; END IF;
  SELECT out_value||jsonb_build_object(k,jsonb_agg(value ORDER BY convert_to(value#>>'{}','UTF8'))) INTO out_value FROM jsonb_array_elements(v->k);
 END LOOP;
 RETURN out_value||jsonb_build_object('partition_ref',NULL);
END $_$;


ALTER FUNCTION builderad_private.canonical_data_selection(v jsonb) OWNER TO builderad_scope_executor;


-- Name: captured_knowledge_validity(jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.captured_knowledge_validity(p_reads jsonb, p_method text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE edge jsonb;before_row public.market_evidence_revisions;current_row public.market_evidence_revisions;keys text[];key text;status text:='current';
BEGIN
 keys:=CASE p_method WHEN 'offer_equivalence:1' THEN ARRAY['product','service','geography'] WHEN 'qualified_interest_change:1' THEN ARRAY['subject','geography','metric','comparison_basis','value'] WHEN 'qualified_price_observation:1' THEN ARRAY['price','currency','product'] WHEN 'qualified_geography_observation:1' THEN ARRAY['geography'] WHEN 'business_feasibility:1' THEN ARRAY['geography'] ELSE NULL END;
 IF keys IS NULL THEN RETURN jsonb_build_object('status','unknown'); END IF;
 FOR edge IN SELECT value FROM jsonb_array_elements(p_reads) LOOP
  SELECT * INTO before_row FROM public.market_evidence_revisions WHERE evidence_id=(edge#>>'{input,key}')::uuid AND content_digest=decode(edge#>>'{input,version}','hex');
  SELECT e.* INTO current_row FROM public.market_evidence_heads h JOIN public.market_evidence_revisions e ON e.evidence_id=h.evidence_id AND e.revision=h.current_revision WHERE h.evidence_id=before_row.evidence_id;
  IF current_row.evidence_id IS NULL OR current_row.evidence_type IS DISTINCT FROM before_row.evidence_type OR current_row.coverage NOT IN('COMPLETE','PARTIAL')
   OR current_row.valid_until IS NULL OR current_row.valid_until<=clock_timestamp() OR current_row.effective_at>clock_timestamp() THEN status:='review_required'; END IF;
  FOREACH key IN ARRAY keys LOOP
   IF before_row.fields->key IS DISTINCT FROM current_row.fields->key THEN status:='review_required'; END IF;
  END LOOP;
 END LOOP;
 RETURN jsonb_build_object('status',status);
END $$;


ALTER FUNCTION builderad_private.captured_knowledge_validity(p_reads jsonb, p_method text) OWNER TO builderad_scope_executor;


-- Name: cb1(jsonb, integer); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.cb1(value jsonb, depth integer DEFAULT 0) RETURNS text
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE kind text; payload text:=''; item record; digits text; exponent integer; negative boolean; fraction integer;
BEGIN
 IF depth>32 THEN RAISE EXCEPTION 'CB1 nesting limit' USING ERRCODE='22023'; END IF;
 IF value IS NULL OR value='null'::jsonb THEN RETURN 'N0:'; END IF;
 kind:=jsonb_typeof(value);
 IF kind='string' THEN
  payload:=value#>>'{}';
  IF octet_length(payload)>65536 THEN RAISE EXCEPTION 'CB1 string limit' USING ERRCODE='22023'; END IF;
  RETURN builderad_private.frame('S',payload);
 ELSIF kind='boolean' THEN RETURN builderad_private.frame('B',CASE WHEN value='true'::jsonb THEN '1' ELSE '0' END);
 ELSIF kind='number' THEN
  payload:=(value#>>'{}')::numeric::text;
  negative:=left(payload,1)='-'; payload:=ltrim(payload,'-');
  fraction:=CASE WHEN position('.' IN payload)>0 THEN length(split_part(payload,'.',2)) ELSE 0 END;
  digits:=ltrim(replace(payload,'.',''),'0'); exponent:=-fraction;
  IF digits='' THEN RETURN 'D3:0e0'; END IF;
  WHILE right(digits,1)='0' LOOP digits:=left(digits,length(digits)-1); exponent:=exponent+1; END LOOP;
  RETURN builderad_private.frame('D',CASE WHEN negative THEN '-' ELSE '' END||digits||'e'||exponent::text);
 ELSIF kind='array' THEN
  payload:=builderad_private.frame('I',jsonb_array_length(value)::text);
  FOR item IN SELECT v FROM jsonb_array_elements(value) AS x(v) LOOP
   payload:=payload||builderad_private.cb1(item.v,depth+1);
  END LOOP;
  RETURN builderad_private.frame('A',payload);
 ELSIF kind='object' THEN
  payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(value)));
  FOR item IN SELECT key,v FROM jsonb_each(value) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
   IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'CB1 schema key required' USING ERRCODE='22023'; END IF;
   payload:=payload||builderad_private.frame('S',item.key)||builderad_private.cb1(item.v,depth+1);
  END LOOP;
  RETURN builderad_private.frame('O',payload);
 END IF;
 RAISE EXCEPTION 'Unsupported CB1 value' USING ERRCODE='22023';
END $_$;


ALTER FUNCTION builderad_private.cb1(value jsonb, depth integer) OWNER TO builderad_scope_executor;


-- Name: command_replay(bigint, bigint, uuid, text, bytea); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.command_replay(p_business bigint, p_actor bigint, p_command uuid, p_action text, p_payload bytea) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.business_command_receipts;
BEGIN
 IF p_command IS NULL THEN RAISE EXCEPTION 'Explicit command required' USING ERRCODE='22023'; END IF;
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF NOT FOUND THEN RETURN NULL; END IF;
 IF r.business_id IS DISTINCT FROM p_business OR r.actor_user_id IS DISTINCT FROM p_actor OR r.action IS DISTINCT FROM p_action OR r.payload_digest IS DISTINCT FROM p_payload
 THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
 RETURN r.result_ref;
END $$;


ALTER FUNCTION builderad_private.command_replay(p_business bigint, p_actor bigint, p_command uuid, p_action text, p_payload bytea) OWNER TO builderad_scope_executor;


-- Name: data_scope_digest(uuid, uuid, text, text, text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.data_scope_digest(boundary_ref uuid, source_ref uuid, resource_name text, operation_name text, purpose_name text, selection_value jsonb) RETURNS bytea
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE value jsonb;encoded text;
BEGIN
 IF boundary_ref IS NULL OR source_ref IS NULL OR resource_name IS NULL OR length(resource_name) NOT BETWEEN 1 AND 180 OR octet_length(resource_name)>720
 OR operation_name NOT IN('retained_read','process','combine','display','reuse','export','train','retain','delete','forward')
 OR purpose_name IS NULL OR length(purpose_name) NOT BETWEEN 1 AND 128 OR octet_length(purpose_name)>512 THEN RAISE EXCEPTION 'Bounded typed data scope required' USING ERRCODE='22023'; END IF;
 value:=jsonb_build_object('codec_version',1,'selection_schema_version',1,'boundary_id',boundary_ref,'source_id',source_ref,
  'resource',resource_name,'operation',operation_name,'purpose',purpose_name,'selection',builderad_private.canonical_data_selection(selection_value));
 encoded:=builderad_private.fields(value,'{"codec_version":"I","selection_schema_version":"I","boundary_id":"U","source_id":"U"}');
 IF octet_length(value::text)>262144 OR octet_length(encoded)>524288 THEN RAISE EXCEPTION 'Aggregate data scope limit' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.digest('data-use-scope:1',value,'{"codec_version":"I","selection_schema_version":"I","boundary_id":"U","source_id":"U"}');
END $$;


ALTER FUNCTION builderad_private.data_scope_digest(boundary_ref uuid, source_ref uuid, resource_name text, operation_name text, purpose_name text, selection_value jsonb) OWNER TO builderad_scope_executor;


-- Name: digest(text, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.digest(domain text, value jsonb, tags jsonb DEFAULT '{}'::jsonb) RETURNS bytea
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE encoded text;
BEGIN
 IF domain NOT IN ('data-use-scope:1','row-permit:1','authority-selection:1','dependency-set:1','command-payload:1','lineage-manifest:1','data-use-market-scope:1','market-command:1','market-evidence:1','market-maintenance:1')
 THEN RAISE EXCEPTION 'CB1 domain required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.bounded(value); encoded:=builderad_private.fields(value,tags);
 IF octet_length(encoded)>2097152 THEN RAISE EXCEPTION 'CB1 encoded limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/'||domain,'UTF8')||decode('00','hex')||convert_to(encoded,'UTF8'));
END $$;


ALTER FUNCTION builderad_private.digest(domain text, value jsonb, tags jsonb) OWNER TO builderad_scope_executor;


-- Name: effect_allowed(regclass, text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.effect_allowed(relation regclass, operation text, image jsonb) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized') THEN RETURN false; END IF;
 RETURN EXISTS(SELECT 1 FROM builderad_private.command_permits p
  JOIN pg_class c ON c.oid=relation JOIN pg_namespace n ON n.oid=c.relnamespace
  JOIN builderad_private.invocations i ON i.nonce=p.invocation_nonce
  WHERE p.backend_pid=pg_backend_pid() AND p.transaction_id=pg_current_xact_id()
   AND p.object_schema=n.nspname AND p.object_name=c.relname AND p.dml_kind=operation
   AND p.uses_left=0 AND p.consumed_at IS NOT NULL AND i.phase='authorized'
   AND i.authority_selection_digest=p.authority_selection_digest
   AND p.new_digest IS NOT DISTINCT FROM builderad_private.image_digest(relation,image,operation));
END $$;


ALTER FUNCTION builderad_private.effect_allowed(relation regclass, operation text, image jsonb) OWNER TO builderad_scope_executor;


-- Name: fields(jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.fields(value jsonb, tags jsonb DEFAULT '{}'::jsonb) RETURNS text
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE payload text; item record;
BEGIN
 IF jsonb_typeof(tags)='string' THEN RETURN builderad_private.atom(tags#>>'{}',value); END IF;
 IF jsonb_typeof(value)='array' AND jsonb_typeof(tags)='array' THEN
  IF jsonb_array_length(value)<>jsonb_array_length(tags) THEN RAISE EXCEPTION 'Exact CB1 array schema required' USING ERRCODE='22023'; END IF;
  payload:=builderad_private.frame('I',jsonb_array_length(value)::text);
  FOR item IN SELECT v,ord FROM jsonb_array_elements(value) WITH ORDINALITY AS x(v,ord) LOOP
   payload:=payload||builderad_private.fields(item.v,tags->(item.ord::integer-1));
  END LOOP;
  RETURN builderad_private.frame('A',payload);
 END IF;
 IF jsonb_typeof(value)<>'object' OR value='null'::jsonb THEN RETURN builderad_private.cb1(value); END IF;
 payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(value)));
 FOR item IN SELECT key,v FROM jsonb_each(value) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
  IF item.key !~ '^[\x20-\x7e]+$' THEN RAISE EXCEPTION 'CB1 schema key required' USING ERRCODE='22023'; END IF;
  payload:=payload||builderad_private.frame('S',item.key)||
   CASE WHEN tags ? item.key THEN builderad_private.fields(item.v,tags->item.key) ELSE builderad_private.cb1(item.v) END;
 END LOOP;
 RETURN builderad_private.frame('O',payload);
END $_$;


ALTER FUNCTION builderad_private.fields(value jsonb, tags jsonb) OWNER TO builderad_scope_executor;


-- Name: finish_command(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.finish_command() RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 DELETE FROM builderad_private.command_permits WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id();
 UPDATE builderad_private.invocations SET phase='completed' WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 IF NOT FOUND THEN RAISE EXCEPTION 'Owned Business command required' USING ERRCODE='42501'; END IF;
END $$;


ALTER FUNCTION builderad_private.finish_command() OWNER TO builderad_scope_executor;


-- Name: frame(text, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.frame(tag text, payload text) RETURNS text
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$ SELECT tag||octet_length(convert_to(payload,'UTF8'))::text||':'||payload $$;


ALTER FUNCTION builderad_private.frame(tag text, payload text) OWNER TO builderad_scope_executor;


-- Name: guard_declared_data_license(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.guard_declared_data_license() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE descriptor jsonb;boundary_ref uuid;business_ref uuid;
BEGIN
 IF TG_TABLE_NAME='client_data_boundaries' THEN
  IF OLD.descriptor->>'policy_id'='declared_data_license_v1' AND
   ((to_jsonb(NEW)-'status'-'revision') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'revision')
    OR OLD.status<>'active' OR NEW.status<>'archived' OR NEW.revision<>OLD.revision+1)
  THEN RAISE EXCEPTION 'Declared licence identity is immutable; withdrawal is final' USING ERRCODE='42501'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.status<>'active' THEN RETURN NEW; END IF;
 IF TG_TABLE_NAME='boundary_subjects' THEN boundary_ref:=NEW.boundary_id;
 ELSE SELECT boundary_id INTO boundary_ref FROM public.data_use_scopes WHERE scope_id=NEW.data_use_scope_id; END IF;
 SELECT b.descriptor INTO descriptor FROM public.client_data_boundaries b WHERE b.boundary_id=boundary_ref;
 IF descriptor->>'policy_id'='declared_data_license_v1' THEN
  SELECT public_id INTO business_ref FROM public.businesses WHERE id=NEW.business_id;
  IF business_ref::text IS DISTINCT FROM descriptor->>'business_public_id'
  THEN RAISE EXCEPTION 'Declared licence is restricted to its exact Business' USING ERRCODE='42501'; END IF;
 END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.guard_declared_data_license() OWNER TO builderad_scope_executor;


-- Name: guard_effect(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.guard_effect() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE before_row jsonb; after_row jsonb; op text:=lower(TG_OP); affected integer; adopted_source boolean; continuity boolean:=false;
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
  IF coalesce(after_row->>'scope_kind',before_row->>'scope_kind') IN ('platform','commercial') THEN
   IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
  END IF;
  IF TG_OP='UPDATE' AND before_row-'runtime_lock_marker'=after_row-'runtime_lock_marker' THEN RETURN NEW; END IF;
  IF TG_OP='UPDATE' AND before_row-'subject_generation'=after_row-'subject_generation' AND before_row->>'status'='active' THEN
   SELECT EXISTS(SELECT 1 FROM builderad_private.invocations i
    WHERE i.backend_pid=pg_backend_pid() AND i.transaction_id=pg_current_xact_id() AND i.phase='authorized'
     AND i.action='identity.business.requalify' AND i.actor_user_id=(before_row->>'subject_user_id')::bigint
     AND i.generation=(after_row->>'subject_generation')::bigint
     AND i.authority_selection#>'{identity_continuity,before}'=before_row
     AND i.authority_selection#>'{identity_continuity,after}'=after_row
     AND builderad_private.identity_generation_continuity(i.actor_user_id,(before_row->>'subject_generation')::bigint,i.generation)) INTO continuity;
  END IF;
  IF TG_OP='UPDATE' AND NOT continuity AND (
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


ALTER FUNCTION builderad_private.guard_effect() OWNER TO builderad_scope_executor;


-- Name: guard_operating_mandate_effect(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.guard_operating_mandate_effect() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE restricted boolean;
BEGIN
 WITH RECURSIVE walk(v) AS(SELECT NEW.authority_selection UNION ALL
  SELECT child FROM walk CROSS JOIN LATERAL (
   SELECT value child FROM jsonb_each(CASE WHEN jsonb_typeof(walk.v)='object' THEN walk.v ELSE '{}'::jsonb END)
   UNION ALL SELECT value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(walk.v)='array' THEN walk.v ELSE '[]'::jsonb END)
  ) x)
 SELECT EXISTS(SELECT 1 FROM walk WHERE v->>'policy_id'='business_operating_mandate_v1') INTO restricted;
 IF restricted AND NEW.action IN('business.link.add','business.link.end','business.link.collaboration','business.link.agency')
 THEN RAISE EXCEPTION 'Operating mandate cannot create organizational authority' USING ERRCODE='42501'; END IF;
 IF restricted AND NEW.action LIKE 'business.link.%'
 THEN RAISE EXCEPTION 'Operating mandate is restricted to licensed data binding' USING ERRCODE='42501'; END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.guard_operating_mandate_effect() OWNER TO builderad_scope_executor;


-- Name: image_digest(regclass, jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.image_digest(relation regclass, image jsonb, operation text) RETURNS bytea
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE inv builderad_private.invocations; encoded jsonb; payload text; item record; schema_name text; relation_name text;
BEGIN
 IF image IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO STRICT inv FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 SELECT n.nspname,c.relname INTO schema_name,relation_name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=relation;
 encoded:=jsonb_build_object(
  'codec_version',builderad_private.frame('I','1'),
  'object_schema',builderad_private.frame('S',schema_name),'object_name',builderad_private.frame('S',relation_name),
  'dml_kind',builderad_private.frame('S',operation),'command_id',builderad_private.atom('U',to_jsonb(inv.command_id)),
  'invocation_nonce',builderad_private.atom('U',to_jsonb(inv.nonce)),
  'backend_pid',builderad_private.atom('I',to_jsonb(inv.backend_pid)),'transaction_id',builderad_private.frame('S',inv.transaction_id::text),
  'actor_user_id',builderad_private.atom('I',to_jsonb(inv.actor_user_id)),'generation',builderad_private.atom('I',to_jsonb(inv.generation)),
  'action',builderad_private.frame('S',inv.action),'target_tuple',builderad_private.authority_cb1(inv.target_tuple),
  'authority_selection_digest',builderad_private.atom('X',to_jsonb(inv.authority_selection_digest)),
  'dependency_digest',builderad_private.atom('X',to_jsonb(inv.dependency_digest)),
  'image',builderad_private.row_image(relation,image)
 );
 payload:=builderad_private.frame('I',(SELECT count(*)::text FROM jsonb_object_keys(encoded)));
 FOR item IN SELECT key,v FROM jsonb_each_text(encoded) AS x(key,v) ORDER BY convert_to(key,'UTF8') LOOP
  payload:=payload||builderad_private.frame('S',item.key)||item.v;
 END LOOP;
 payload:=builderad_private.frame('O',payload);
 IF octet_length(payload)>2097152 THEN RAISE EXCEPTION 'Permit image limit' USING ERRCODE='22023'; END IF;
 RETURN sha256(convert_to('BuilderAd/CB1/row-permit:1','UTF8')||decode('00','hex')||convert_to(payload,'UTF8'));
END $$;


ALTER FUNCTION builderad_private.image_digest(relation regclass, image jsonb, operation text) OWNER TO builderad_scope_executor;


-- Name: inquiry_basis(bigint, bigint, jsonb, text, bigint); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_basis(b bigint, actor bigint, selection jsonb, session_ref text, expected bigint DEFAULT NULL::bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE proof jsonb;q public.authority_qualification_receipts;
BEGIN
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,session_ref);
 SELECT * INTO STRICT q FROM public.authority_qualification_receipts WHERE qualification_id=(proof#>>'{qualification,qualification_id}')::uuid;
 IF selection->>'realm_kind' IS DISTINCT FROM 'direct' OR q.policy_id<>'self_declared_draft' OR q.policy_version<>1 OR q.issuer_kind<>'self_declared_draft'
 OR q.subject_user_id<>actor OR q.revision IS DISTINCT FROM coalesce(expected,q.revision)
 OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND created_by=actor AND origin_kind='self_declared_draft')
 OR NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=(proof#>>'{grants,0,id}')::bigint AND parent_grant_id IS NULL AND source='business_draft_creation' AND NOT delegable)
 THEN RAISE EXCEPTION 'Original own-statement basis required' USING ERRCODE='42501'; END IF;
 RETURN proof;
END $$;


ALTER FUNCTION builderad_private.inquiry_basis(b bigint, actor bigint, selection jsonb, session_ref text, expected bigint) OWNER TO builderad_scope_executor;


-- Name: inquiry_count(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_count(v jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
 SELECT v='null'::jsonb OR(jsonb_typeof(v)='number' AND(v#>>'{}')~'^[0-9]{1,13}$' AND(v#>>'{}')::numeric<=1000000000000)
$_$;


ALTER FUNCTION builderad_private.inquiry_count(v jsonb) OWNER TO builderad_scope_executor;


-- Name: inquiry_fresh(jsonb, bigint, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_fresh(proof jsonb, actor bigint, session_ref text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE authenticated timestamptz;
BEGIN
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=session_ref;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh explicit inquiry confirmation required' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('inquiry_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
END $$;


ALTER FUNCTION builderad_private.inquiry_fresh(proof jsonb, actor bigint, session_ref text) OWNER TO builderad_scope_executor;


-- Name: inquiry_keys(jsonb, text[]); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_keys(v jsonb, keys text[]) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_typeof(v)='object' AND ARRAY(SELECT jsonb_object_keys(v) ORDER BY 1)=ARRAY(SELECT unnest(keys) ORDER BY 1)
$$;


ALTER FUNCTION builderad_private.inquiry_keys(v jsonb, keys text[]) OWNER TO builderad_scope_executor;


-- Name: inquiry_require(bigint, bigint, text, jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_require(b bigint, actor bigint, action_name text, selection jsonb, session_ref text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE enrolled public.inquiry_evidence_enrollments;basis public.authority_qualification_receipts;proof jsonb;g public.security_action_grants;
BEGIN
 IF action_name NOT IN('business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read')
 OR selection->>'realm_kind' IS DISTINCT FROM 'direct' OR jsonb_typeof(selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(selection->'requirements')<>1
 OR coalesce(selection#>>'{requirements,0,leaf_grant_id}','')!~'^[1-9][0-9]{0,17}$'
 THEN RAISE EXCEPTION 'Exact inquiry action required' USING ERRCODE='42501'; END IF;
 SELECT * INTO g FROM public.security_action_grants WHERE id=(selection#>>'{requirements,0,leaf_grant_id}')::bigint AND subject_user_id=actor AND business_id=b AND scope_kind='business' AND action=action_name;
 SELECT * INTO enrolled FROM public.inquiry_evidence_enrollments WHERE qualification_id=g.qualification_id AND business_id=b AND actor_user_id=actor AND status='active';
 IF enrolled.qualification_id IS NULL THEN RAISE EXCEPTION 'Inquiry access unavailable' USING ERRCODE='42501'; END IF;
 -- Include the original qualification in the initial sorted K4 footprint. Never acquire it after effects.
 PERFORM builderad_private.lock_authority_footprint(actor,selection,session_ref,'{}',ARRAY[enrolled.basis_qualification_id]);
 proof:=builderad_private.require_business(b,actor,action_name,selection,session_ref);
 SELECT * INTO STRICT basis FROM public.authority_qualification_receipts WHERE qualification_id=enrolled.basis_qualification_id;
 IF basis.status<>'active' OR basis.policy_id<>'self_declared_draft' OR basis.policy_version<>1 OR basis.issuer_kind<>'self_declared_draft'
 OR basis.revision<>enrolled.basis_revision OR basis.subject_user_id<>actor OR basis.target_kind<>'business'
 OR basis.target_ref IS DISTINCT FROM(SELECT public_id FROM public.businesses WHERE id=b)
 OR basis.valid_from>clock_timestamp() OR(basis.valid_until IS NOT NULL AND basis.valid_until<=clock_timestamp())
 OR NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=b AND created_by=actor AND origin_kind='self_declared_draft')
 OR enrolled.generation<>(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 OR proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'inquiry_evidence_custody_v1'
 OR proof#>>'{qualification,policy_version}' IS DISTINCT FROM '1' OR proof#>>'{qualification,issuer_kind}' IS DISTINCT FROM 'qualified_policy'
 OR g.source<>'qualified_policy' OR g.parent_grant_id IS NOT NULL OR g.delegable OR g.delegation_realm_kind<>'direct'
 THEN RAISE EXCEPTION 'Current original inquiry basis required' USING ERRCODE='42501'; END IF;
 RETURN proof||jsonb_build_object('inquiry_basis',jsonb_build_object('qualification_row',to_jsonb(basis),'policy_version',1));
END $_$;


ALTER FUNCTION builderad_private.inquiry_require(b bigint, actor bigint, action_name text, selection jsonb, session_ref text) OWNER TO builderad_scope_executor;


-- Name: inquiry_value(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.inquiry_value(v jsonb) RETURNS jsonb
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE c jsonb;o jsonb;a jsonb;i jsonb;t text;moment timestamptz;
BEGIN
 IF NOT coalesce(builderad_private.inquiry_keys(v,ARRAY['cohort','observation','cost','inquiries','acceptance']),false) OR octet_length(v::text)>8192 THEN
  RAISE EXCEPTION 'Bounded declaration fields required' USING ERRCODE='22023'; END IF;
 c:=v->'cohort';o:=v->'observation';a:=v->'acceptance';i:=v->'inquiries';
 IF NOT coalesce(builderad_private.inquiry_keys(c,ARRAY['channel_kind','definition_version','country_code','population','counting_policy']),false)
 OR jsonb_typeof(c->'channel_kind')<>'string' OR c->>'channel_kind' NOT IN('form','call','message','crm','other')
 OR c->>'population' IS DISTINCT FROM 'recorded_inquiries' OR c->>'counting_policy' IS DISTINCT FROM 'distinct_inquiries'
 OR jsonb_typeof(c->'definition_version') IS DISTINCT FROM 'number' OR coalesce(c->>'definition_version','')!~'^[1-9][0-9]{0,8}$'
 OR NOT(c->'country_code'='null'::jsonb OR(jsonb_typeof(c->'country_code')='string' AND c->>'country_code'~'^[A-Z]{2}$'))
 OR NOT coalesce(builderad_private.inquiry_keys(o,ARRAY['period_start','period_end','as_of','timezone']),false)
 OR NOT EXISTS(SELECT 1 FROM pg_catalog.pg_timezone_names WHERE name=o->>'timezone')
 THEN RAISE EXCEPTION 'Typed cohort and observation required' USING ERRCODE='22023'; END IF;
 FOREACH t IN ARRAY ARRAY['period_start','period_end','as_of'] LOOP
  IF jsonb_typeof(o->t) IS DISTINCT FROM 'string' OR(o->>t)!~'(Z|[+-][0-9]{2}:[0-9]{2})$' THEN RAISE EXCEPTION 'Explicit observation instant required' USING ERRCODE='22023'; END IF;
  moment:=(o->>t)::timestamptz;
 END LOOP;
 IF (o->>'period_end')::timestamptz<=(o->>'period_start')::timestamptz OR(o->>'as_of')::timestamptz<(o->>'period_start')::timestamptz OR(o->>'as_of')::timestamptz>clock_timestamp()
 THEN RAISE EXCEPTION 'Invalid observation window' USING ERRCODE='22023'; END IF;
 IF v->'cost'<>'null'::jsonb THEN
  c:=v->'cost';
  IF NOT coalesce(builderad_private.inquiry_keys(c,ARRAY['amount','currency','precision','cost_basis','allocation_basis']),false)
  OR jsonb_typeof(c->'amount') IS DISTINCT FROM 'string' OR coalesce(c->>'amount','')!~'^(0|[1-9][0-9]{0,11})([.][0-9]{1,8})?$'
  OR jsonb_typeof(c->'currency') IS DISTINCT FROM 'string' OR coalesce(c->>'currency','')!~'^[A-Z]{3}$'
  OR jsonb_typeof(c->'precision') IS DISTINCT FROM 'number' OR coalesce(c->>'precision','')!~'^[0-8]$' OR length(split_part(c->>'amount','.',2))>(c->>'precision')::integer
  OR coalesce(c->>'cost_basis','') NOT IN('advertising_spend','declared_cohort_cost') OR coalesce(c->>'allocation_basis','') NOT IN('whole_cohort','declared_attribution')
  THEN RAISE EXCEPTION 'Typed declared cost required' USING ERRCODE='22023'; END IF;
 END IF;
 IF i<>'null'::jsonb THEN
  IF NOT coalesce(builderad_private.inquiry_keys(i,ARRAY['count','coverage']),false) OR NOT coalesce(builderad_private.inquiry_count(i->'count'),false)
  OR coalesce(i->>'coverage','') NOT IN('complete','partial','unknown') OR(i->>'coverage'='complete' AND i->'count'='null'::jsonb)
  THEN RAISE EXCEPTION 'Typed inquiry count required' USING ERRCODE='22023'; END IF;
 END IF;
 IF a<>'null'::jsonb THEN
  IF NOT coalesce(builderad_private.inquiry_keys(a,ARRAY['count','pending_count','criterion_code','criterion_version','criterion_definition','cutoff','maturity','coverage']),false)
  OR NOT coalesce(builderad_private.inquiry_count(a->'count'),false) OR NOT coalesce(builderad_private.inquiry_count(a->'pending_count'),false)
  OR coalesce(a->>'criterion_code','') NOT IN('operator_defined','within_service_area','response_ready')
  OR jsonb_typeof(a->'criterion_version') IS DISTINCT FROM 'number' OR coalesce(a->>'criterion_version','')!~'^[1-9][0-9]{0,8}$'
  OR coalesce(a->>'maturity','') NOT IN('mature','pending','unknown') OR coalesce(a->>'coverage','') NOT IN('complete','partial','unknown')
  OR jsonb_typeof(a->'cutoff') IS DISTINCT FROM 'string' OR(a->>'cutoff')!~'(Z|[+-][0-9]{2}:[0-9]{2})$'
  THEN RAISE EXCEPTION 'Typed declared acceptance required' USING ERRCODE='22023'; END IF;
  -- Abstract rule only: no contact identifiers, markup, executable expressions or external references.
  -- This is source-asserted text, not an automatic guarantee of semantic truth or PII detection.
  IF a->>'criterion_code'='operator_defined' THEN
   IF jsonb_typeof(a->'criterion_definition') IS DISTINCT FROM 'string'
    OR char_length(a->>'criterion_definition') NOT BETWEEN 8 AND 500
    OR btrim(a->>'criterion_definition')<>a->>'criterion_definition'
    OR (a->>'criterion_definition') ~ '[[:cntrl:]<>@{}\[\]\\]'
    OR (a->>'criterion_definition') ~* '(https?[:/]|www[.]|javascript:|data:|=>|[[:digit:]][[:digit:] .()+-]{5,}[[:digit:]])'
   THEN RAISE EXCEPTION 'Bounded abstract criterion definition required; no personal data, code or external references' USING ERRCODE='22023'; END IF;
  ELSIF a->'criterion_definition' IS DISTINCT FROM 'null'::jsonb THEN
   RAISE EXCEPTION 'Fixed criterion cannot be redefined' USING ERRCODE='22023';
  END IF;
  IF(a->>'cutoff')::timestamptz>(o->>'as_of')::timestamptz OR(a->>'cutoff')::timestamptz<(o->>'period_start')::timestamptz
  OR(a->>'maturity'='mature' AND coalesce((a->>'pending_count')::numeric,0)>0)
  OR(a->>'coverage'='complete' AND a->'count'='null'::jsonb)
  OR(i<>'null'::jsonb AND i->'count'<>'null'::jsonb AND a->'count'<>'null'::jsonb AND(a->>'count')::numeric>(i->>'count')::numeric)
  THEN RAISE EXCEPTION 'Inconsistent declared acceptance' USING ERRCODE='22023'; END IF;
 END IF;
 IF v->'cost'='null'::jsonb AND i='null'::jsonb AND a='null'::jsonb THEN RAISE EXCEPTION 'At least one declared axis required' USING ERRCODE='22023'; END IF;
 RETURN v;
END $_$;


ALTER FUNCTION builderad_private.inquiry_value(v jsonb) OWNER TO builderad_scope_executor;


-- Name: intent_confirmations(jsonb, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.intent_confirmations(prior_value jsonb, next_value jsonb, p_confirmations jsonb) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN IF p_confirmations IS NULL OR jsonb_typeof(p_confirmations)<>'object' THEN
  RAISE EXCEPTION 'Explicit boolean confirmation object required' USING ERRCODE='22023';
 END IF;
 IF EXISTS (SELECT 1 FROM jsonb_each(p_confirmations) c WHERE c.key NOT IN
  ('declaration','business_wide','outcome','acceptance_clear','clarification_required','quantity','horizon')
  OR jsonb_typeof(c.value)<>'boolean') OR NOT coalesce(p_confirmations @> '{"declaration":true}',false) THEN
  RAISE EXCEPTION 'Recognized boolean declaration confirmations required' USING ERRCODE='22023';
 END IF;

 IF next_value IS NULL THEN
  IF EXISTS (SELECT 1 FROM jsonb_each(p_confirmations) c WHERE c.key<>'declaration' AND c.value='true'::jsonb) THEN
   RAISE EXCEPTION 'Clear cannot confirm replacement fields' USING ERRCODE='22023';
  END IF;
 ELSE
  IF NOT p_confirmations @> '{"business_wide":true}' THEN
   RAISE EXCEPTION 'Whole business confirmation required' USING ERRCODE='22023';
  END IF;
  IF next_value->'outcome' IS DISTINCT FROM prior_value->'outcome' THEN
   IF NOT p_confirmations @> '{"outcome":true,"acceptance_clear":true}'
    OR coalesce(p_confirmations @> '{"clarification_required":true}',false)<>
       (next_value->'outcome'->'clarification'<>'null'::jsonb) THEN
    RAISE EXCEPTION 'Independent clear outcome and consistent clarification confirmation required' USING ERRCODE='22023';
   END IF;
  END IF;
  IF next_value->'quantity'<>'null'::jsonb AND
   (next_value->'quantity' IS DISTINCT FROM prior_value->'quantity' OR next_value->'outcome' IS DISTINCT FROM prior_value->'outcome')
   AND NOT p_confirmations @> '{"quantity":true}' THEN
   RAISE EXCEPTION 'Quantity with its outcome and count unit must be confirmed' USING ERRCODE='22023';
  END IF;
 END IF;

END $$;


ALTER FUNCTION builderad_private.intent_confirmations(prior_value jsonb, next_value jsonb, p_confirmations jsonb) OWNER TO builderad_scope_executor;


-- Name: knowledge_completion_binding(uuid, uuid, uuid, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_completion_binding(p_run uuid, p_command uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb) RETURNS bytea
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT sha256(convert_to(jsonb_build_object('run',p_run,'command',p_command,'output',p_output,'finding',p_finding,'process',p_process,'publication',p_publication)::text,'UTF8'));
$$;


ALTER FUNCTION builderad_private.knowledge_completion_binding(p_run uuid, p_command uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb) OWNER TO builderad_scope_executor;

SET default_tablespace = '';

SET default_table_access_method = heap;


-- Name: knowledge_completion_ticket(text, public.market_knowledge_captures, uuid, uuid, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_completion_ticket(p_ticket text, r public.market_knowledge_captures, p_command uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb) RETURNS public.market_knowledge_completion_tickets
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE t public.market_knowledge_completion_tickets;
BEGIN
 IF p_ticket IS NULL OR p_ticket !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Independent execution attestation required' USING ERRCODE='42501'; END IF;
 SELECT * INTO t FROM public.market_knowledge_completion_tickets WHERE token_hash=encode(sha256(convert_to(p_ticket,'UTF8')),'hex') FOR UPDATE;
 IF t.token_hash IS NULL OR t.consumed_at IS NOT NULL OR t.expires_at<=clock_timestamp() OR t.created_at>clock_timestamp()
 OR t.run_id IS DISTINCT FROM r.run_id OR t.command_id IS DISTINCT FROM p_command OR t.actor_user_id IS DISTINCT FROM r.actor_user_id OR t.generation IS DISTINCT FROM r.generation
 OR t.method_ref IS DISTINCT FROM r.method_ref OR t.session_digest IS DISTINCT FROM r.session_digest OR t.backend_pid IS DISTINCT FROM pg_backend_pid() OR t.transaction_id IS DISTINCT FROM pg_current_xact_id()
 OR t.binding_digest IS DISTINCT FROM builderad_private.knowledge_completion_binding(r.run_id,p_command,p_output,p_finding,p_process,p_publication)
 THEN RAISE EXCEPTION 'Exact current one-use shared execution attestation required' USING ERRCODE='42501'; END IF;
 RETURN t;
END $_$;


ALTER FUNCTION builderad_private.knowledge_completion_ticket(p_ticket text, r public.market_knowledge_captures, p_command uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb) OWNER TO builderad_scope_executor;


-- Name: knowledge_consume_ticket(public.market_knowledge_completion_tickets); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_consume_ticket(t public.market_knowledge_completion_tickets) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.market_knowledge_completion_tickets;
BEGIN
 prior:=t;t.consumed_at:=clock_timestamp();
 IF t.consumed_at>=t.expires_at THEN RAISE EXCEPTION 'Shared completion attestation expired' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.permit('public.market_knowledge_completion_tickets','update',to_jsonb(prior),to_jsonb(t));
 UPDATE public.market_knowledge_completion_tickets SET consumed_at=t.consumed_at WHERE token_hash=t.token_hash AND consumed_at IS NULL;
 IF NOT FOUND THEN RAISE EXCEPTION 'Shared completion attestation already consumed' USING ERRCODE='42501'; END IF;
END $$;


ALTER FUNCTION builderad_private.knowledge_consume_ticket(t public.market_knowledge_completion_tickets) OWNER TO builderad_scope_executor;


-- Name: knowledge_continuation_constraints(jsonb, text, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_continuation_constraints(p_rights jsonb, p_operation text, p_purpose text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE entry jsonb;g public.security_action_grants;q public.authority_qualification_receipts;c public.market_source_custody;s public.data_use_scopes;
BEGIN
 FOR entry IN SELECT value FROM jsonb_array_elements(p_rights) WHERE value#>>'{scope,operation}' IN('forward','retain') LOOP
  SELECT * INTO g FROM public.security_action_grants WHERE id=(entry#>>'{grant_row,id}')::bigint;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(entry#>>'{scope,scope_id}')::uuid;
  SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
  PERFORM builderad_private.market_policy(s.scope_id);
  IF g.id IS NULL OR g.status IS DISTINCT FROM 'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
   OR g.data_use_scope_id IS DISTINCT FROM s.scope_id OR g.action IS DISTINCT FROM 'data_use.'||s.operation OR g.source IS DISTINCT FROM 'qualified_policy' OR g.parent_grant_id IS NOT NULL
   OR s.scope_id IS NULL OR s.status IS DISTINCT FROM 'active' OR s.resource IS DISTINCT FROM 'market.evidence' OR s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL
   OR s.operation IS DISTINCT FROM entry#>>'{scope,operation}' OR s.purpose IS DISTINCT FROM p_purpose OR to_jsonb(s)->'scope_digest' IS DISTINCT FROM entry#>'{scope,scope_digest}'
   OR q.qualification_id IS NULL OR q.status IS DISTINCT FROM 'active' OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
   OR q.target_kind IS DISTINCT FROM 'data_use' OR q.target_ref IS DISTINCT FROM s.scope_id OR q.subject_user_id IS DISTINCT FROM g.subject_user_id OR q.issuer_kind IS DISTINCT FROM 'qualified_policy' OR NOT g.action=ANY(q.allowed_actions)
   OR c.source_id IS NULL OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
   OR c.policy_id IS DISTINCT FROM q.policy_id OR c.policy_version IS DISTINCT FROM q.policy_version OR NOT s.operation=ANY(c.allowed_operations) OR NOT p_purpose=ANY(c.allowed_purposes)
   OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
   OR(s.operation='retain' AND(g.expires_at IS NOT NULL OR q.valid_until IS NOT NULL OR c.valid_until IS NOT NULL))
  THEN RAISE EXCEPTION 'Continuing derived-use or retention licence unavailable' USING ERRCODE='42501'; END IF;
 END LOOP;
 IF p_operation NOT IN('display','retained_read','reuse','process','combine','export') THEN RAISE EXCEPTION 'Unsupported derived operation' USING ERRCODE='42501'; END IF;
END $$;


ALTER FUNCTION builderad_private.knowledge_continuation_constraints(p_rights jsonb, p_operation text, p_purpose text) OWNER TO builderad_scope_executor;


-- Name: knowledge_descriptor(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_descriptor(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE r public.market_knowledge_captures;receipt public.business_command_receipts;reads jsonb;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace' IS DISTINCT FROM 'market.knowledge' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact shared producer reference required' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid AND state='published' AND material_digest=decode(p_ref->>'version','hex');
 SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=r.run_id;
 IF r.run_id IS NULL OR r.completion_attestation_digest IS NULL OR r.publication_manifest_id IS NULL OR r.output_scope_id IS NULL
 OR receipt.action IS DISTINCT FROM 'market.knowledge.capture' OR receipt.actor_user_id IS DISTINCT FROM r.actor_user_id OR receipt.generation IS DISTINCT FROM r.generation
 OR receipt.business_id IS NOT NULL OR receipt.result_ref->>'run_ref' IS DISTINCT FROM r.run_id::text
 OR r.finding->>'method_ref' IS DISTINCT FROM r.method_ref OR r.finding->'evidence_refs' IS DISTINCT FROM r.material_refs THEN RAISE EXCEPTION 'Sealed trusted capture receipt required' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg((value-'field_keys')||jsonb_build_object('read_command_id',r.run_id) ORDER BY value::text) INTO reads FROM jsonb_array_elements(r.input_reads);
 RETURN jsonb_build_object('ref',p_ref,'resolver_contract_version',2,'scope_kind','market','business_public_id',NULL,'processor_user_id',r.actor_user_id,
 'output_scope_id',r.output_scope_id,'purpose',r.purpose,'processing_operation','process','material_digest',p_ref->'version','producer_receipt_ref','market_knowledge:'||r.run_id,
 'coverage',CASE WHEN r.finding->>'status' IN('unresolved','not_comparable','requires_information') THEN 'PARTIAL' ELSE 'COMPLETE' END,
 'provenance',jsonb_build_object('kind','qualified_market_knowledge'),'input_reads',reads,'publication_manifest_id',r.publication_manifest_id);
END $_$;


ALTER FUNCTION builderad_private.knowledge_descriptor(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: knowledge_retention_withdrawal(); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_retention_withdrawal() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.market_knowledge_captures;r public.market_knowledge_captures;affected boolean;retained jsonb;private_prior public.market_business_finding_captures;private_row public.market_business_finding_captures;
BEGIN
 IF TG_TABLE_NAME='security_action_grants' THEN
  IF NEW.action IS DISTINCT FROM 'data_use.retain' AND OLD.action IS DISTINCT FROM 'data_use.retain' THEN RETURN NEW;END IF;
 ELSIF TG_TABLE_NAME='authority_qualification_receipts' THEN
  IF NOT 'data_use.retain'=ANY(NEW.allowed_actions) AND NOT 'data_use.retain'=ANY(OLD.allowed_actions) THEN RETURN NEW;END IF;
 ELSIF TG_TABLE_NAME='data_use_scopes' THEN
  IF NEW.operation IS DISTINCT FROM 'retain' AND OLD.operation IS DISTINCT FROM 'retain' THEN RETURN NEW;END IF;
 END IF;
 FOR prior IN SELECT c.* FROM public.market_knowledge_captures c WHERE c.state='published' AND EXISTS(
  SELECT 1 FROM jsonb_array_elements(c.continuation_rights) v WHERE v#>>'{scope,operation}'='retain' AND CASE TG_TABLE_NAME
   WHEN 'authority_qualification_receipts' THEN v#>>'{qualification_row,qualification_id}'=to_jsonb(NEW)->>'qualification_id'
   WHEN 'security_action_grants' THEN v#>>'{grant_row,id}'=to_jsonb(NEW)->>'id'
   WHEN 'market_source_custody' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'source_id'
   WHEN 'source_registry' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'id'
   WHEN 'data_use_scopes' THEN v#>>'{scope,scope_id}'=to_jsonb(NEW)->>'scope_id'
   ELSE false END
 ) ORDER BY c.run_id FOR UPDATE LOOP
  SELECT jsonb_agg(value) INTO retained FROM jsonb_array_elements(prior.continuation_rights) WHERE value#>>'{scope,operation}'='retain';
  affected:=false;
  BEGIN PERFORM builderad_private.knowledge_continuation_constraints(retained,'retained_read',prior.purpose);
  EXCEPTION WHEN insufficient_privilege THEN affected:=true;END;
  IF affected THEN
   r:=prior;r.state:='retention_removed';r.finding:=NULL;
   PERFORM builderad_private.permit('public.market_knowledge_captures','update',to_jsonb(prior),to_jsonb(r));
   UPDATE public.market_knowledge_captures SET finding=NULL,state=r.state WHERE run_id=r.run_id;
  END IF;
 END LOOP;
 FOR private_prior IN SELECT c.* FROM public.market_business_finding_captures c WHERE c.state='published' AND EXISTS(
  SELECT 1 FROM jsonb_array_elements(c.continuation_rights) v WHERE v#>>'{scope,operation}'='retain' AND CASE TG_TABLE_NAME
   WHEN 'authority_qualification_receipts' THEN v#>>'{qualification_row,qualification_id}'=to_jsonb(NEW)->>'qualification_id'
   WHEN 'security_action_grants' THEN v#>>'{grant_row,id}'=to_jsonb(NEW)->>'id'
   WHEN 'market_source_custody' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'source_id'
   WHEN 'source_registry' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'id'
   WHEN 'data_use_scopes' THEN v#>>'{scope,scope_id}'=to_jsonb(NEW)->>'scope_id'
   ELSE false END
 ) ORDER BY c.artifact_id FOR UPDATE LOOP
  SELECT jsonb_agg(value) INTO retained FROM jsonb_array_elements(private_prior.continuation_rights) WHERE value#>>'{scope,operation}'='retain';
  affected:=false;
  BEGIN PERFORM builderad_private.knowledge_continuation_constraints(retained,'retained_read',private_prior.purpose);
  EXCEPTION WHEN insufficient_privilege THEN affected:=true;END;
  IF affected THEN
   private_row:=private_prior;private_row.state:='retention_removed';
   PERFORM builderad_private.permit('public.market_business_finding_captures','update',to_jsonb(private_prior),to_jsonb(private_row));
   UPDATE public.market_business_finding_captures SET state=private_row.state WHERE artifact_id=private_row.artifact_id;
  END IF;
 END LOOP;
 RETURN NEW;
END $$;


ALTER FUNCTION builderad_private.knowledge_retention_withdrawal() OWNER TO builderad_scope_executor;


-- Name: knowledge_validity(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.knowledge_validity(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_knowledge_captures;
BEGIN
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 RETURN builderad_private.captured_knowledge_validity(r.input_reads,r.method_ref);
END $$;


ALTER FUNCTION builderad_private.knowledge_validity(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: market_evidence_fields(jsonb, jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_evidence_fields(p_ref jsonb, p_keys jsonb, p_operation text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;result jsonb;
BEGIN
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 IF e.evidence_id IS NULL OR (p_operation NOT IN('retained_read','display') AND
 (e.revision<>h.current_revision OR e.effective_at>clock_timestamp() OR e.valid_until IS NULL OR e.valid_until<=clock_timestamp() OR e.coverage NOT IN('COMPLETE','PARTIAL')))
 THEN RAISE EXCEPTION 'Current qualified market evidence required for processing' USING ERRCODE='42501'; END IF;
 SELECT jsonb_object_agg(k,e.fields->k ORDER BY k COLLATE "C") INTO result FROM jsonb_array_elements_text(p_keys) k WHERE e.fields ? k;
 RETURN result;
END $$;


ALTER FUNCTION builderad_private.market_evidence_fields(p_ref jsonb, p_keys jsonb, p_operation text) OWNER TO builderad_scope_executor;


-- Name: market_evidence_resolve(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_evidence_resolve(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;empty_hash bytea;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace'<>'market.evidence' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
 OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact market evidence reference required' USING ERRCODE='22023'; END IF;
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 IF e.evidence_id IS NULL THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: market original unavailable' USING ERRCODE='42501'; END IF;
 empty_hash:=builderad_private.lineage_digest('dependency-set:1','[]');
 RETURN jsonb_build_object('ref',p_ref,'kind','original','resolver_contract_version',1,'material_digest',p_ref->>'version',
 'producer_receipt_ref','market_evidence:'||e.command_id,'direct_dependency_count',0,'direct_dependency_digest',encode(empty_hash,'hex'),
 'closure_digest',NULL,'dependencies','[]'::jsonb,'origin',jsonb_build_object('source_id',h.source_id,'resource','market.evidence',
 'operation','retained_read','purpose','market_research','coverage',e.coverage,'provenance',e.provenance,
 'evidence',jsonb_build_object('source_ref',h.source_id,'schema',e.evidence_type,'revision',e.revision,
 'observed_at',e.observed_at,'effective_at',e.effective_at,'valid_until',e.valid_until)));
END $_$;


ALTER FUNCTION builderad_private.market_evidence_resolve(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: market_keys(jsonb, text[]); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_keys(v jsonb, keys text[]) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF jsonb_typeof(v) IS DISTINCT FROM 'object' OR
 (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(v) k) IS DISTINCT FROM
 (SELECT array_agg(k ORDER BY k COLLATE "C") FROM unnest(keys) k)
 THEN RAISE EXCEPTION 'Exact market contract keys required' USING ERRCODE='22023'; END IF;
END $$;


ALTER FUNCTION builderad_private.market_keys(v jsonb, keys text[]) OWNER TO builderad_scope_executor;


-- Name: market_knowledge_operational_outcome(text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_knowledge_operational_outcome(p_method text, p_finding jsonb) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'pg_temp'
    AS $$
DECLARE outcome text:='unknown';reason text:='unknown';status text;code text;
BEGIN
 IF jsonb_typeof(p_finding) IS DISTINCT FROM 'object' OR jsonb_typeof(p_finding->'method_ref') IS DISTINCT FROM 'string'
  OR p_finding->>'method_ref' IS DISTINCT FROM p_method OR jsonb_typeof(p_finding->'status') IS DISTINCT FROM 'string'
 THEN RETURN jsonb_build_object('processing_outcome',outcome,'reason_category',reason); END IF;
 status:=p_finding->>'status';
 IF jsonb_typeof(p_finding->'reason_codes')='array' THEN
  IF jsonb_array_length(p_finding->'reason_codes')=1 AND jsonb_typeof(p_finding#>'{reason_codes,0}')='string'
  THEN code:=p_finding#>>'{reason_codes,0}'; END IF;
 END IF;
 IF (p_method='offer_equivalence:1' AND status='equivalent' AND code='QUALIFIED_IDENTITY_AND_TERMS_MATCH')
  OR(p_method='offer_equivalence:1' AND status='different' AND code IN('PRODUCT_MANUFACTURER_DIFFERS','PRODUCT_MODEL_DIFFERS','PRODUCT_VARIANT_DIFFERS','PRODUCT_UNIT_DIFFERS','PRODUCT_CONDITION_DIFFERS',
   'SERVICE_COVERAGE_DIFFERS','SERVICE_INCLUSIONS_DIFFER','SERVICE_EXCLUSIONS_DIFFER','SERVICE_WARRANTY_MONTHS_DIFFER','SERVICE_FULFILLMENT_DAYS_DIFFER'))
  OR(p_method='qualified_interest_change:1' AND status='comparable' AND code='QUALIFIED_COMPARABLE_PERIODS')
  OR(p_method IN('qualified_price_observation:1','qualified_geography_observation:1') AND status='observed' AND code='QUALIFIED_SOURCE_OBSERVATION')
 THEN outcome:='completed';reason:='none';
 ELSIF(p_method='offer_equivalence:1' AND status='unresolved')
  OR(p_method='qualified_interest_change:1' AND status='not_comparable')
  OR(p_method IN('qualified_price_observation:1','qualified_geography_observation:1') AND status='requires_information')
 THEN
  outcome:='abstained';reason:='generic_abstention';
  IF (p_method='offer_equivalence:1' AND code IN('TWO_OFFERS_REQUIRED','QUALIFIED_PRODUCT_AND_SERVICE_TERMS_REQUIRED','LICENSED_COMPARISON_FIELDS_REQUIRED','PRODUCT_IDENTITY_INCOMPLETE','COVERAGE_UNKNOWN','SERVICE_TERMS_INCOMPLETE'))
   OR(p_method='qualified_interest_change:1' AND code IN('TWO_SIGNALS_REQUIRED','LICENSED_COMPARISON_FIELDS_REQUIRED','VALUE_UNKNOWN'))
   OR(p_method IN('qualified_price_observation:1','qualified_geography_observation:1') AND code='QUALIFIED_FACT_FIELDS_REQUIRED')
  THEN reason:='required_information_missing';
  ELSIF p_method='qualified_interest_change:1' AND code IN('QUALIFIED_COMPARISON_BASIS_REQUIRED','SOURCE_COMPARABILITY_UNQUALIFIED','SUBJECT_NOT_EQUIVALENT','GEOGRAPHY_NOT_EQUIVALENT','METRIC_NOT_EQUIVALENT',
   'COMPARISON_BASIS_NOT_EQUIVALENT','PERIODS_NOT_COMPARABLE','VALUE_OUTSIDE_METHOD_RANGE','ZERO_BASELINE')
  THEN reason:='comparison_basis_insufficient'; END IF;
 END IF;
 RETURN jsonb_build_object('processing_outcome',outcome,'reason_category',reason);
END $$;


ALTER FUNCTION builderad_private.market_knowledge_operational_outcome(p_method text, p_finding jsonb) OWNER TO builderad_scope_executor;


-- Name: market_scope_digest(uuid, text, text, text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_scope_digest(p_source uuid, p_resource text, p_operation text, p_purpose text, p_selection jsonb) RETURNS bytea
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF p_source IS NULL OR p_resource IS NULL OR length(p_resource) NOT BETWEEN 1 AND 180
 OR p_operation IS NULL OR p_operation NOT IN('retained_read','display','reuse','process','combine','export','train','retain','delete','forward','ingest','correct')
 OR p_purpose IS NULL OR length(p_purpose) NOT BETWEEN 1 AND 128
 THEN RAISE EXCEPTION 'Typed market scope required' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.digest('data-use-market-scope:1',jsonb_build_object('rights_domain','market','source_id',p_source,'resource',p_resource,
 'operation',p_operation,'purpose',p_purpose,'selection',builderad_private.canonical_data_selection(p_selection)),'{"source_id":"U"}');
END $$;


ALTER FUNCTION builderad_private.market_scope_digest(p_source uuid, p_resource text, p_operation text, p_purpose text, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: market_staff_eligible(bigint); Type: FUNCTION; Schema: builderad_private; Owner: builderad_owner
--

CREATE FUNCTION builderad_private.market_staff_eligible(p_actor bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 PERFORM 1 FROM public.internal_personnel WHERE user_id=p_actor FOR SHARE;
 RETURN EXISTS(SELECT 1 FROM public.internal_personnel WHERE user_id=p_actor AND status='active');
END $$;


ALTER FUNCTION builderad_private.market_staff_eligible(p_actor bigint) OWNER TO builderad_owner;


-- Name: market_validate_evidence(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_validate_evidence(p_payload jsonb) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE f jsonb;v jsonb;k text;old_payload jsonb;
BEGIN
 IF p_payload->>'evidence_type' IN('market.offer:1','market.interest_signal:1') THEN
  PERFORM builderad_private.market_validate_evidence_v1(p_payload);RETURN;
 END IF;
 f:=p_payload->'fields';old_payload:=p_payload;
 IF p_payload->>'evidence_type'='market.offer:2' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','currency','price','inclusions','product','service']);
  PERFORM builderad_private.market_keys(f->'product',ARRAY['manufacturer','model','variant','unit','condition']);
  FOR k,v IN SELECT key,value FROM jsonb_each(f->'product') LOOP
   IF v<>'null'::jsonb AND(jsonb_typeof(v)<>'string' OR length(v#>>'{}') NOT BETWEEN 1 AND 180) THEN RAISE EXCEPTION 'Typed product identity required' USING ERRCODE='22023'; END IF;
  END LOOP;
  PERFORM builderad_private.market_keys(f->'service',ARRAY['inclusions','exclusions','warranty_months','fulfillment_days']);
  FOREACH k IN ARRAY ARRAY['inclusions','exclusions'] LOOP
   v:=f->'service'->k;
   IF v<>'null'::jsonb THEN
    IF jsonb_typeof(v)<>'array' OR jsonb_array_length(v)>128 THEN RAISE EXCEPTION 'Finite service terms required' USING ERRCODE='22023'; END IF;
    IF EXISTS(SELECT 1 FROM jsonb_array_elements(v) x WHERE jsonb_typeof(x)<>'string' OR length(x#>>'{}') NOT BETWEEN 1 AND 180)
    OR jsonb_array_length(v)<>(SELECT count(DISTINCT x) FROM jsonb_array_elements(v) x) THEN RAISE EXCEPTION 'Unique typed service terms required' USING ERRCODE='22023'; END IF;
   END IF;
  END LOOP;
  FOREACH k IN ARRAY ARRAY['warranty_months','fulfillment_days'] LOOP
   v:=f->'service'->k;
   IF v<>'null'::jsonb AND(jsonb_typeof(v)<>'number' OR v::text !~ '^[0-9]{1,5}$') THEN RAISE EXCEPTION 'Finite integer or unknown service term required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF f->'inclusions' IS DISTINCT FROM f#>'{service,inclusions}' THEN RAISE EXCEPTION 'Offer inclusion assertions must agree' USING ERRCODE='22023'; END IF;
  old_payload:=jsonb_set(jsonb_set(p_payload,'{evidence_type}','"market.offer:1"'),'{fields}',f-'product'-'service');
 ELSIF p_payload->>'evidence_type'='market.interest_signal:2' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','metric','value','comparison_basis']);
  PERFORM builderad_private.market_keys(f->'comparison_basis',ARRAY['sampling','normalization','population','aggregation','ratio_scale','period_start','period_end']);
  FOREACH k IN ARRAY ARRAY['sampling','normalization','population','aggregation'] LOOP
   v:=f->'comparison_basis'->k;
   IF v<>'null'::jsonb AND(jsonb_typeof(v)<>'string' OR length(v#>>'{}') NOT BETWEEN 1 AND 180) THEN RAISE EXCEPTION 'Explicit comparison basis required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF jsonb_typeof(f#>'{comparison_basis,ratio_scale}') IS DISTINCT FROM 'boolean' THEN RAISE EXCEPTION 'Explicit ratio-scale applicability required' USING ERRCODE='22023'; END IF;
  FOREACH k IN ARRAY ARRAY['period_start','period_end'] LOOP
   v:=f->'comparison_basis'->k;
   IF jsonb_typeof(v) IS DISTINCT FROM 'string' OR v#>>'{}' !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$'
    OR NOT isfinite((v#>>'{}')::timestamptz) THEN RAISE EXCEPTION 'Explicit comparison period required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF (f#>>'{comparison_basis,period_end}')::timestamptz<=(f#>>'{comparison_basis,period_start}')::timestamptz THEN RAISE EXCEPTION 'Positive comparison period required' USING ERRCODE='22023'; END IF;
  old_payload:=jsonb_set(jsonb_set(p_payload,'{evidence_type}','"market.interest_signal:1"'),'{fields}',f-'comparison_basis');
 ELSE RAISE EXCEPTION 'Unsupported evidence schema' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_validate_evidence_v1(old_payload);
 -- Validate the complete payload too: old envelope validation cannot bound removed fields.
 PERFORM builderad_private.bounded(p_payload);
 IF octet_length(p_payload::text)>262144 THEN RAISE EXCEPTION 'Market evidence byte limit' USING ERRCODE='22023'; END IF;
END $_$;


ALTER FUNCTION builderad_private.market_validate_evidence(p_payload jsonb) OWNER TO builderad_scope_executor;


-- Name: market_validate_evidence_v1(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_validate_evidence_v1(p_payload jsonb) RETURNS void
    LANGUAGE plpgsql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE f jsonb;p text;
BEGIN
 PERFORM builderad_private.bounded(p_payload);
 IF octet_length(p_payload::text)>262144 THEN RAISE EXCEPTION 'Market evidence byte limit' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_payload,ARRAY['record_key','evidence_type','fields','coverage','provenance','effective_at','observed_at','valid_until','reason']);
 f:=p_payload->'fields';
 IF p_payload->>'evidence_type'='market.offer:1' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','currency','price','inclusions']);
  IF jsonb_typeof(f->'currency') IS DISTINCT FROM 'string' OR f->>'currency' !~ '^[A-Z]{3}$'
  OR(f->'price'<>'null'::jsonb AND(jsonb_typeof(f->'price') IS DISTINCT FROM 'number' OR(f->>'price')::numeric<0))
 OR jsonb_typeof(f->'inclusions') IS DISTINCT FROM 'array'
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(f->'inclusions')='array' THEN f->'inclusions' ELSE '[]'::jsonb END) v WHERE jsonb_typeof(v)<>'string')
 THEN RAISE EXCEPTION 'Typed offer fields required' USING ERRCODE='22023'; END IF;
 ELSIF p_payload->>'evidence_type'='market.interest_signal:1' THEN
  PERFORM builderad_private.market_keys(f,ARRAY['subject','geography','metric','value']);
  PERFORM builderad_private.market_keys(f->'metric',ARRAY['name','unit','definition_version']);
  FOREACH p IN ARRAY ARRAY['name','unit','definition_version'] LOOP
   IF jsonb_typeof(f->'metric'->p) IS DISTINCT FROM 'string' OR length(f->'metric'->>p) NOT BETWEEN 1 AND 180 THEN RAISE EXCEPTION 'Explicit metric meaning required' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF f->'value'<>'null'::jsonb AND jsonb_typeof(f->'value') IS DISTINCT FROM 'number' THEN RAISE EXCEPTION 'Numeric or unknown signal required' USING ERRCODE='22023'; END IF;
 ELSE RAISE EXCEPTION 'Unsupported market evidence schema' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(f->'subject',ARRAY['key','label','identity_state']);
 PERFORM builderad_private.market_keys(f->'geography',ARRAY['ref','level']);
 IF jsonb_typeof(f#>'{subject,identity_state}') IS DISTINCT FROM 'string' OR f#>>'{subject,identity_state}' NOT IN('unresolved','source_asserted') OR jsonb_typeof(f#>'{subject,label}') IS DISTINCT FROM 'string'
 OR length(f#>>'{subject,label}') NOT BETWEEN 1 AND 180
 OR(f#>'{subject,key}'<>'null'::jsonb AND jsonb_typeof(f#>'{subject,key}') IS DISTINCT FROM 'string')
 OR(f#>>'{subject,identity_state}'='source_asserted' AND jsonb_typeof(f#>'{subject,key}') IS DISTINCT FROM 'string')
 OR jsonb_typeof(f#>'{geography,level}') IS DISTINCT FROM 'string' OR f#>>'{geography,level}' NOT IN('unknown','country','region','city','district')
 OR(f#>>'{geography,level}'='unknown' AND f#>'{geography,ref}'<>'null'::jsonb)
 OR(f#>>'{geography,level}'<>'unknown' AND jsonb_typeof(f#>'{geography,ref}') IS DISTINCT FROM 'string')
 OR jsonb_typeof(p_payload->'coverage') IS DISTINCT FROM 'string' OR p_payload->>'coverage' NOT IN('COMPLETE','PARTIAL','FAILED','UNKNOWN','PENDING')
 OR jsonb_typeof(p_payload->'provenance') IS DISTINCT FROM 'object'
 OR jsonb_typeof(p_payload->'record_key') IS DISTINCT FROM 'string' OR length(p_payload->>'record_key') NOT BETWEEN 1 AND 180
 OR jsonb_typeof(p_payload->'reason') IS DISTINCT FROM 'string' OR length(p_payload->>'reason') NOT BETWEEN 1 AND 600
 THEN RAISE EXCEPTION 'Qualified market meaning required' USING ERRCODE='22023'; END IF;
 FOREACH p IN ARRAY ARRAY['observed_at','effective_at'] LOOP
  IF jsonb_typeof(p_payload->p) IS DISTINCT FROM 'string' OR (p_payload->>p) !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$' THEN RAISE EXCEPTION 'Explicit timezone required' USING ERRCODE='22023'; END IF;
 END LOOP;
 IF p_payload->'valid_until'<>'null'::jsonb AND(jsonb_typeof(p_payload->'valid_until') IS DISTINCT FROM 'string' OR (p_payload->>'valid_until') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$') THEN RAISE EXCEPTION 'Explicit expiry timezone required' USING ERRCODE='22023'; END IF;
END $_$;


ALTER FUNCTION builderad_private.market_validate_evidence_v1(p_payload jsonb) OWNER TO builderad_scope_executor;


-- Name: market_validity(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.market_validity(p_ref jsonb) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE e public.market_evidence_revisions;h public.market_evidence_heads;v text;
BEGIN
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=(p_ref->>'key')::uuid;
 SELECT * INTO e FROM public.market_evidence_revisions WHERE evidence_id=h.evidence_id AND content_digest=decode(p_ref->>'version','hex');
 v:=CASE WHEN e.revision<>h.current_revision THEN 'historical' WHEN e.valid_until IS NULL THEN 'unknown'
 WHEN e.effective_at>clock_timestamp() OR e.valid_until<=clock_timestamp() OR e.coverage NOT IN('COMPLETE','PARTIAL') THEN 'review_required' ELSE 'current' END;
 RETURN jsonb_build_object('status',v,'currentness_revision',h.current_revision,'checked_at',clock_timestamp());
END $$;


ALTER FUNCTION builderad_private.market_validity(p_ref jsonb) OWNER TO builderad_scope_executor;


-- Name: operating_mandate_basis(bigint, bigint, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.operating_mandate_basis(p_business bigint, p_actor bigint, p_proof jsonb) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT (p_proof#>>'{qualification,policy_id}'='self_declared_draft'
  AND p_actor=(SELECT created_by FROM public.businesses WHERE id=p_business AND origin_kind='self_declared_draft')
  AND p_proof#>>'{grants,0,parent_grant_id}' IS NULL)
 OR (p_proof#>>'{realm,realm_kind}'='agency'
  AND p_proof#>>'{qualification,issuer_kind}'='qualified_policy')
$$;


ALTER FUNCTION builderad_private.operating_mandate_basis(p_business bigint, p_actor bigint, p_proof jsonb) OWNER TO builderad_scope_executor;


-- Name: output_use_plan(bigint, uuid, jsonb, text, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.output_use_plan(p_actor bigint, p_business uuid, p_ref jsonb, p_purpose text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE d jsonb;s public.data_use_scopes;matches integer;leaf bigint;
BEGIN
 d:=builderad_private.producer_descriptor(p_ref);
 IF d->>'business_public_id' IS DISTINCT FROM p_business::text OR d->>'purpose' IS DISTINCT FROM p_purpose THEN RAISE EXCEPTION 'Exact producer Business/purpose required' USING ERRCODE='42501'; END IF;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(d->>'output_scope_id')::uuid;
 IF NOT FOUND OR s.operation<>'display' OR s.purpose<>p_purpose
 OR NOT(s.selection->'native_namespaces' ? (p_ref->>'namespace')) OR NOT(s.selection->'native_keys' ? (p_ref->>'key'))
 THEN RAISE EXCEPTION 'Exact independent output-display scope required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.authority_footprint(p_actor,p_selection);
 SELECT count(*),min(g.id) INTO matches,leaf FROM public.security_action_grants g JOIN jsonb_array_elements(p_selection->'requirements') r
 ON r->>'requirement_kind'='data_use' AND r->>'leaf_grant_id'=g.id::text
 WHERE g.scope_kind='data_use' AND g.subject_user_id=p_actor AND g.data_use_scope_id=s.scope_id AND g.action='data_use.display';
 IF matches<>1 THEN RAISE EXCEPTION 'Exactly one explicitly selected output entitlement required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('descriptor',d,'scope',to_jsonb(s),'grant_id',leaf);
END $$;


ALTER FUNCTION builderad_private.output_use_plan(p_actor bigint, p_business uuid, p_ref jsonb, p_purpose text, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: permit(regclass, text, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.permit(relation regclass, operation text, old_image jsonb, new_image jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE inv builderad_private.invocations; schema_name text; relation_name text;
BEGIN
 SELECT * INTO STRICT inv FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 SELECT n.nspname,c.relname INTO schema_name,relation_name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=relation;
 INSERT INTO builderad_private.command_permits VALUES (
  uuidv7(),pg_backend_pid(),pg_current_xact_id(),inv.nonce,inv.action,schema_name,relation_name,operation,inv.target_tuple,
  builderad_private.image_digest(relation,old_image,operation),builderad_private.image_digest(relation,new_image,operation),1,inv.authority_selection_digest,NULL
 );
END $$;


ALTER FUNCTION builderad_private.permit(relation regclass, operation text, old_image jsonb, new_image jsonb) OWNER TO builderad_scope_executor;


-- Name: practice_dependency(public.business_practice_revisions); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.practice_dependency(r public.business_practice_revisions) RETURNS bytea
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT sha256(convert_to(jsonb_build_object('state',r.state,'value',CASE WHEN nullif(btrim(r.value->>'offering'),'') IS NOT NULL THEN r.value-'name'-'channels' ELSE r.value-'channels' END)::text,'UTF8'));
$$;


ALTER FUNCTION builderad_private.practice_dependency(r public.business_practice_revisions) OWNER TO builderad_scope_executor;


-- Name: practice_projection(public.business_practice_revisions); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.practice_projection(r public.business_practice_revisions) RETURNS jsonb
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('practice_id',r.practice_id,'revision',r.revision,'operation',r.operation,'corrects_revision',r.corrects_revision,'correction_reason',r.correction_reason,'state',r.state,'value',r.value,'recorded_at',r.recorded_at);
$$;


ALTER FUNCTION builderad_private.practice_projection(r public.business_practice_revisions) OWNER TO builderad_scope_executor;


-- Name: practice_value(jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.practice_value(p_value jsonb) RETURNS jsonb
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
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


ALTER FUNCTION builderad_private.practice_value(p_value jsonb) OWNER TO builderad_scope_executor;


-- Name: principal(text, boolean); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.principal(p_session text, p_lock boolean DEFAULT true) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;
BEGIN
 IF session_user<>'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM public.builderad_signed_context() c WHERE c.phase='principal';
 IF actor IS NULL THEN RAISE EXCEPTION 'Clean signed principal required' USING ERRCODE='42501'; END IF;
 IF p_lock THEN
 PERFORM 1 FROM public.principal_lifecycles WHERE user_id=actor FOR SHARE;
 PERFORM 1 FROM public.users WHERE id=actor FOR SHARE;
 PERFORM 1 FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
 END IF;
 IF NOT EXISTS (
  SELECT 1 FROM public.principal_lifecycles l JOIN public.users u ON u.id=l.user_id
  JOIN public.security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='advertiser'
   AND u.email_verified_at IS NOT NULL AND s.session_key_hash=p_session AND s.revoked_at IS NULL
   AND s.auth_method<>'internal_passkey' AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
 ) THEN RAISE EXCEPTION 'Current Business actor required' USING ERRCODE='42501'; END IF;
 RETURN actor;
END $$;


ALTER FUNCTION builderad_private.principal(p_session text, p_lock boolean) OWNER TO builderad_scope_executor;


-- Name: processing_authorize(text, uuid, jsonb, text, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_authorize(p_session text, p_business uuid, p_refs jsonb, p_purpose text, p_selection jsonb, p_context_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;plan jsonb;fences jsonb;proof jsonb;combined jsonb;footprint jsonb;item jsonb;fields jsonb;inputs jsonb:='[]';context jsonb;h public.business_context_heads;
BEGIN
 IF p_refs IS NULL OR jsonb_typeof(p_refs)<>'array' OR jsonb_array_length(p_refs) NOT BETWEEN 1 AND 2
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(p_refs) x WHERE x->>'namespace'<>'market.evidence')
 THEN RAISE EXCEPTION 'Finite original market inputs required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 plan:=builderad_private.material_use_plan(actor,p_business,p_refs,'process',p_purpose,p_selection);
 fences:=builderad_private.lock_material_resources(plan);
 b:=builderad_private.lock_business(p_business);
 combined:=p_selection;
 IF p_context_selection IS NOT NULL THEN
  IF p_context_selection-'requirements' IS DISTINCT FROM p_selection-'requirements' THEN RAISE EXCEPTION 'Same explicit authority realm required' USING ERRCODE='42501'; END IF;
  combined:=jsonb_set(p_selection,'{requirements}',(p_selection->'requirements')||(p_context_selection->'requirements'));
 END IF;
 footprint:=builderad_private.authority_footprint(actor,combined);
 IF EXISTS(SELECT 1 FROM public.security_action_grants WHERE id IN(SELECT value::bigint FROM jsonb_array_elements_text(footprint->'grants')) AND scope_kind='business' AND business_id IS DISTINCT FROM b) THEN RAISE EXCEPTION 'Exact Business required' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.lock_authority_footprint(actor,combined,p_session);
 IF builderad_private.material_use_plan(actor,p_business,p_refs,'process',p_purpose,p_selection) IS DISTINCT FROM plan THEN RAISE EXCEPTION 'Processing input set changed' USING ERRCODE='40001'; END IF;
 proof:=builderad_private.require_material_use(actor,b,p_session,'process',plan,p_selection)||jsonb_build_object('dependencies',fences);
 IF p_context_selection IS NOT NULL THEN
  proof:=proof||jsonb_build_object('business_context_proof',builderad_private.require_business(b,actor,'business.context.read',p_context_selection,p_session));
  SELECT * INTO STRICT h FROM public.business_context_heads WHERE business_id=b FOR SHARE;
  context:=jsonb_build_object('context_version',h.context_version,'declared_markets',
   (SELECT coalesce(jsonb_agg(country_code::text ORDER BY country_code COLLATE "C"),'[]') FROM public.business_declared_markets WHERE business_id=b));
 END IF;
 -- Preserve caller reference order (before/after is semantic), not closure sort order.
 FOR item IN SELECT x.value FROM jsonb_array_elements(p_refs) WITH ORDINALITY x(value,ord) ORDER BY x.ord LOOP
  SELECT value INTO STRICT item FROM jsonb_array_elements(plan->'items') WHERE value->'ref'=item;
  fields:=builderad_private.market_evidence_fields(item->'ref',item#>'{scope,selection,field_keys}','process');
  IF fields IS NULL OR(SELECT count(*) FROM jsonb_object_keys(fields))<>jsonb_array_length(item#>'{scope,selection,field_keys}') THEN RAISE EXCEPTION 'Exact licensed processing fields required' USING ERRCODE='42501'; END IF;
  inputs:=inputs||jsonb_build_array(jsonb_build_object('ref',item->'ref','fields',fields,'coverage',item#>'{origin,coverage}','evidence',item#>'{origin,evidence}'));
 END LOOP;
 PERFORM builderad_private.require_material_use(actor,b,p_session,'process',plan,p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(b,actor,'business.context.read',p_context_selection,p_session); END IF;
 RETURN jsonb_build_object('actor',actor,'business_id',b,'plan',plan,'proof',proof,'materials',inputs,'business_context',context,
  'input_digest',encode(sha256(convert_to(jsonb_build_object('materials',inputs,'business_context',context)::text,'UTF8')),'hex'));
END $$;


ALTER FUNCTION builderad_private.processing_authorize(p_session text, p_business uuid, p_refs jsonb, p_purpose text, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: processing_completion_binding(uuid, uuid, text, jsonb, bigint, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_completion_binding(p_run uuid, p_command uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) RETURNS bytea
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT sha256(convert_to(jsonb_build_object('run',p_run,'command',p_command,'effect',p_effect,'outcome',p_outcome,
  'elapsed',p_elapsed,'selection',p_selection,'context_selection',p_context_selection)::text,'UTF8'));
$$;


ALTER FUNCTION builderad_private.processing_completion_binding(p_run uuid, p_command uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: processing_completion_ticket(text, text, uuid, public.market_processing_runs, uuid, text, jsonb, bigint, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_completion_ticket(p_token text, p_session text, p_business uuid, r public.market_processing_runs, p_command uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) RETURNS public.market_processing_completion_tickets
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE t public.market_processing_completion_tickets;
BEGIN
 IF p_token IS NULL OR p_token !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Trusted completion attestation required' USING ERRCODE='42501'; END IF;
 SELECT * INTO t FROM public.market_processing_completion_tickets WHERE token_hash=encode(sha256(convert_to(p_token,'UTF8')),'hex') FOR UPDATE;
 IF t.token_hash IS NULL OR t.consumed_at IS NOT NULL OR t.expires_at<=clock_timestamp() OR t.created_at>clock_timestamp()
 OR t.backend_pid<>pg_backend_pid() OR t.transaction_id<>pg_current_xact_id() OR t.run_id<>r.run_id
 OR t.command_id<>p_command OR t.actor_user_id<>r.actor_user_id OR t.generation<>r.generation
 OR t.business_public_id<>p_business OR t.method_ref<>r.method_ref
 OR t.session_digest<>sha256(convert_to(p_session,'UTF8'))
 OR t.binding_digest<>builderad_private.processing_completion_binding(r.run_id,p_command,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection)
 THEN RAISE EXCEPTION 'Exact current single-use trusted completion attestation required' USING ERRCODE='42501'; END IF;
 RETURN t;
END $_$;


ALTER FUNCTION builderad_private.processing_completion_ticket(p_token text, p_session text, p_business uuid, r public.market_processing_runs, p_command uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: processing_consume_completion_ticket(public.market_processing_completion_tickets); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_consume_completion_ticket(t public.market_processing_completion_tickets) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE prior public.market_processing_completion_tickets;
BEGIN
 prior:=t;t.consumed_at:=clock_timestamp();
 IF prior.expires_at<=t.consumed_at THEN RAISE EXCEPTION 'Completion attestation expired' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.permit('public.market_processing_completion_tickets','update',to_jsonb(prior),to_jsonb(t));
 UPDATE public.market_processing_completion_tickets SET consumed_at=t.consumed_at WHERE token_hash=t.token_hash AND consumed_at IS NULL;
 IF NOT FOUND THEN RAISE EXCEPTION 'Completion attestation already consumed' USING ERRCODE='42501'; END IF;
END $$;


ALTER FUNCTION builderad_private.processing_consume_completion_ticket(t public.market_processing_completion_tickets) OWNER TO builderad_scope_executor;


-- Name: processing_input(text, uuid, uuid, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_input(p_session text, p_business uuid, p_run uuid, p_selection jsonb, p_context_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_processing_runs;q jsonb;actor bigint;
BEGIN
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO r FROM public.market_processing_runs WHERE run_id=p_run AND actor_user_id=actor AND business_id=(SELECT id FROM public.businesses WHERE public_id=p_business);
 IF r.run_id IS NULL OR r.session_digest<>sha256(convert_to(p_session,'UTF8'))
 OR r.generation<>(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 OR r.selection_digest<>sha256(convert_to(jsonb_build_array(p_selection,p_context_selection)::text,'UTF8')) THEN RAISE EXCEPTION 'Exact captured actor, session and selected rights required' USING ERRCODE='42501'; END IF;
 q:=builderad_private.processing_authorize(p_session,p_business,r.material_refs,r.purpose,p_selection,p_context_selection);
 IF r.input_digest<>decode(q->>'input_digest','hex') OR r.dependency_digest<>decode(q#>>'{plan,closure,dependency_digest}','hex') THEN RAISE EXCEPTION 'Captured input or context changed' USING ERRCODE='40001'; END IF;
 RETURN q||jsonb_build_object('run',builderad_private.processing_surface(r,p_business));
END $$;


ALTER FUNCTION builderad_private.processing_input(p_session text, p_business uuid, p_run uuid, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: processing_surface(public.market_processing_runs, uuid); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.processing_surface(r public.market_processing_runs, p_business uuid) RETURNS jsonb
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
BEGIN
 IF r.state<>'prepared' AND r.completion_attestation_digest IS NULL THEN RAISE EXCEPTION 'Historical completion lacks trusted execution evidence' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('run_ref',r.run_id,'business_public_id',p_business,'question',r.question,'method_ref',r.method_ref,
  'purpose',r.purpose,'state',r.state,'finding',r.finding,'reason_code',r.reason_code,'material_refs',r.material_refs,
  'capture_ref',r.run_id,'dependency_digest',encode(r.dependency_digest,'hex'),'published',false,
  'usage',jsonb_build_object('executions',r.execution_count,'elapsed_microseconds',r.elapsed_microseconds,'external_cost','NOT_INCURRED','unmeasured_cost','UNKNOWN','billable',false),
  'versions',jsonb_build_object('processing_contract',1));
END $$;


ALTER FUNCTION builderad_private.processing_surface(r public.market_processing_runs, p_business uuid) OWNER TO builderad_scope_executor;


-- Name: record_command(bigint, bigint, uuid, text, bytea, jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.record_command(p_business bigint, p_actor bigint, p_command uuid, p_action text, p_payload bytea, p_result jsonb, p_state text DEFAULT 'committed'::text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.business_command_receipts; i builderad_private.invocations;
BEGIN
 SELECT * INTO STRICT i FROM builderad_private.invocations WHERE backend_pid=pg_backend_pid() AND transaction_id=pg_current_xact_id() AND phase='authorized';
 r.command_id:=p_command;r.business_id:=p_business;r.actor_user_id:=p_actor;r.generation:=i.generation;r.action:=p_action;r.payload_digest:=p_payload;
 r.state:=p_state;r.result_ref:=p_result;r.returned_versions:=p_result->'versions';r.recorded_at:=clock_timestamp();
 r.authority_selection_digest:=i.authority_selection_digest;r.dependency_digest:=i.dependency_digest;
 PERFORM builderad_private.permit('public.business_command_receipts','insert',NULL,to_jsonb(r));
 INSERT INTO public.business_command_receipts SELECT (r).*;
 PERFORM builderad_private.assert_invocation_current();
END $$;


ALTER FUNCTION builderad_private.record_command(p_business bigint, p_actor bigint, p_command uuid, p_action text, p_payload bytea, p_result jsonb, p_state text) OWNER TO builderad_scope_executor;


-- Name: registered_output_constraints(jsonb, text, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.registered_output_constraints(p_ref jsonb, p_operation text, p_purpose text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE r public.market_knowledge_captures;entry jsonb;g public.security_action_grants;q public.authority_qualification_receipts;c public.market_source_custody;
BEGIN
 IF p_ref->>'namespace'='business.expenditure_summary' THEN PERFORM builderad_private.expenditure_summary_constraints(p_ref,p_operation,p_purpose);RETURN; END IF;
 IF p_ref->>'namespace'='market.business_finding' THEN
  PERFORM builderad_private.business_finding_descriptor(p_ref);
  IF p_purpose IS DISTINCT FROM(SELECT purpose FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid) THEN RAISE EXCEPTION 'Exact private finding purpose required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.knowledge_continuation_constraints((SELECT continuation_rights FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid),p_operation,p_purpose);RETURN;
 END IF;
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 IF p_purpose IS DISTINCT FROM r.purpose THEN RAISE EXCEPTION 'New purpose needs qualified recapture and publication' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.knowledge_continuation_constraints(r.continuation_rights,p_operation,p_purpose);
END $$;


ALTER FUNCTION builderad_private.registered_output_constraints(p_ref jsonb, p_operation text, p_purpose text) OWNER TO builderad_scope_executor;


-- Name: require_business(bigint, bigint, text, jsonb, text); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.require_business(p_business bigint, p_actor bigint, p_action text, p_selection jsonb, p_session text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE result jsonb;
BEGIN
 IF jsonb_typeof(p_selection->'requirements')<>'array' OR jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact Business authority selection required' USING ERRCODE='42501'; END IF;
 result:=builderad_private.require_scope('business',p_business::text,p_actor,p_action,p_selection,p_session);
 IF (SELECT lifecycle FROM public.businesses WHERE id=p_business) IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required' USING ERRCODE='42501'; END IF;
 RETURN result;
END $$;


ALTER FUNCTION builderad_private.require_business(p_business bigint, p_actor bigint, p_action text, p_selection jsonb, p_session text) OWNER TO builderad_scope_executor;


-- Name: require_output_use(bigint, bigint, text, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.require_output_use(p_actor bigint, p_business bigint, p_session text, p_plan jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;proof jsonb;
BEGIN
 SELECT * INTO STRICT s FROM public.data_use_scopes WHERE scope_id=(p_plan#>>'{scope,scope_id}')::uuid;
 IF p_plan#>>'{descriptor,ref,namespace}'='market.business_finding' AND s.rights_domain='market' THEN
  IF to_jsonb(s) IS DISTINCT FROM p_plan->'scope' OR s.status<>'active' OR s.resource<>'market.business_finding'
   OR p_plan#>>'{descriptor,business_public_id}' IS DISTINCT FROM(SELECT public_id::text FROM public.businesses WHERE id=p_business)
   OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
  THEN RAISE EXCEPTION 'Exact private output Business and independent binding required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.market_policy(s.scope_id);
 ELSE
 IF to_jsonb(s) IS DISTINCT FROM p_plan->'scope' OR s.status<>'active' OR NOT EXISTS(SELECT 1 FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=p_business AND branch_id IS NULL AND status='active')
 OR NOT EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=p_business AND data_use_scope_id=s.scope_id AND status='active')
 THEN RAISE EXCEPTION 'Current output boundary and use binding required' USING ERRCODE='42501'; END IF;
 END IF;
 proof:=builderad_private.require_scope('data_use',s.scope_id::text,p_actor,'data_use.display',p_selection,p_session);
 PERFORM builderad_private.registered_output_constraints(p_plan#>'{descriptor,ref}','display',s.purpose);
 RETURN proof;
END $$;


ALTER FUNCTION builderad_private.require_output_use(p_actor bigint, p_business bigint, p_session text, p_plan jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: require_publish_use(bigint, bigint, text, jsonb, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.require_publish_use(p_actor bigint, p_business bigint, p_session text, p_plan jsonb, p_input_selection jsonb, p_output jsonb, p_full_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE proof jsonb;item jsonb;edge jsonb;policy jsonb;
BEGIN
 proof:=builderad_private.require_material_use(p_actor,p_business,p_session,'process',p_plan,p_input_selection);
 FOR edge IN SELECT value FROM jsonb_array_elements(builderad_private.producer_dependencies(p_output->'descriptor')) LOOP
  SELECT value INTO item FROM jsonb_array_elements(p_plan->'items') WHERE value->'ref'=edge->'input';
  IF item IS NULL OR item#>'{scope,scope_id}' IS DISTINCT FROM edge->'data_use_scope_id' THEN RAISE EXCEPTION 'Captured input license cannot be silently replaced' USING ERRCODE='42501'; END IF;
  policy:=builderad_private.require_scope('data_use',edge->>'data_use_scope_id',p_actor,'data_use.process',p_input_selection,p_session);
  IF policy#>'{qualification,policy_id}' IS DISTINCT FROM edge->'policy_id' OR policy#>'{qualification,policy_version}' IS DISTINCT FROM edge->'policy_version'
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: captured processing policy changed' USING ERRCODE='42501'; END IF;
 END LOOP;
 proof:=proof||jsonb_build_object('output',builderad_private.require_output_use(p_actor,p_business,p_session,p_output,p_full_selection));
 PERFORM builderad_private.principal(p_session);RETURN proof;
END $$;


ALTER FUNCTION builderad_private.require_publish_use(p_actor bigint, p_business bigint, p_session text, p_plan jsonb, p_input_selection jsonb, p_output jsonb, p_full_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: row_image(regclass, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.row_image(relation regclass, value jsonb) RETURNS text
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE tags jsonb; expected text[]; actual text[];
BEGIN
 IF value IS NULL THEN RETURN 'N0:'; END IF;
 value:=value-'runtime_lock_marker'-'scope_lock_marker';
 SELECT array_agg(a.attname::text ORDER BY convert_to(a.attname::text,'UTF8')),
  coalesce(jsonb_object_agg(a.attname,
   CASE t.typname WHEN 'int2' THEN 'I' WHEN 'int4' THEN 'I' WHEN 'int8' THEN 'I'
    WHEN 'uuid' THEN 'U' WHEN 'timestamptz' THEN 'T' WHEN 'bytea' THEN 'X' WHEN 'xid8' THEN 'S' ELSE 'J' END),'{}')
 INTO expected,tags
 FROM pg_attribute a JOIN pg_type t ON t.oid=a.atttypid WHERE a.attrelid=relation AND a.attnum>0 AND NOT a.attisdropped
 AND a.attname NOT IN ('runtime_lock_marker','scope_lock_marker');
 SELECT array_agg(k ORDER BY convert_to(k,'UTF8')) INTO actual FROM jsonb_object_keys(value) k;
 IF actual IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Complete controlled row image required' USING ERRCODE='22023'; END IF;
 RETURN builderad_private.fields(value,tags);
END $$;


ALTER FUNCTION builderad_private.row_image(relation regclass, value jsonb) OWNER TO builderad_scope_executor;


-- Name: start_command(bigint, text, uuid, text, jsonb, jsonb); Type: FUNCTION; Schema: builderad_private; Owner: builderad_scope_executor
--

CREATE FUNCTION builderad_private.start_command(p_actor bigint, p_session text, p_command uuid, p_action text, p_target jsonb, p_selection jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE g bigint; sd bytea;
BEGIN
 IF p_command IS NULL OR p_action IS NULL OR p_selection IS NULL THEN RAISE EXCEPTION 'Explicit bounded command required' USING ERRCODE='22023'; END IF;
 SELECT generation INTO g FROM public.principal_lifecycles WHERE user_id=p_actor;
 sd:=builderad_private.authority_digest(p_selection);
 INSERT INTO builderad_private.invocations VALUES (
  pg_backend_pid(),pg_current_xact_id(),uuidv7(),p_command,p_actor,g,sha256(convert_to(p_session,'UTF8')),
  p_action,p_target,p_selection,sd,builderad_private.resource_dependency_digest(coalesce(p_selection->'dependencies','[]'::jsonb)),'authorized'
 );
END $$;


ALTER FUNCTION builderad_private.start_command(p_actor bigint, p_session text, p_command uuid, p_action text, p_target jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_activate_principal_context(text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_activate_principal_context(p_ticket text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE
    v_actor bigint;
    v_payload text;
    v_secret bytea;
BEGIN
    IF session_user <> 'builderad_app'
       OR NULLIF(current_setting('app.signed_context', true), '') IS NOT NULL
       OR NULLIF(current_setting('app.authorization_phase', true), '') IS NOT NULL
       OR NULLIF(current_setting('app.principal_user_id', true), '') IS NOT NULL THEN
        RAISE EXCEPTION 'Clean runtime transaction required' USING ERRCODE = '42501';
    END IF;
    SELECT actor_user_id INTO v_actor FROM builderad_context_tickets
    WHERE token_hash = encode(sha256(p_ticket::bytea), 'hex')
      AND backend_pid = pg_backend_pid()
      AND transaction_id = pg_current_xact_id()
      AND consumed_at IS NULL AND expires_at > clock_timestamp()
    FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Valid single-use context ticket required' USING ERRCODE = '42501';
    END IF;
    UPDATE builderad_context_tickets SET consumed_at = clock_timestamp()
    WHERE token_hash = encode(sha256(p_ticket::bytea), 'hex');
    SELECT secret INTO v_secret FROM builderad_context_secret WHERE singleton;
    v_payload := concat_ws('|', v_actor, 'principal',
        pg_current_xact_id()::text, pg_backend_pid()::text);
    PERFORM set_config('app.signed_context',
        v_payload || '|' || encode(hmac(convert_to(v_payload, 'UTF8'), v_secret, 'sha256'), 'hex'), true);
    PERFORM set_config('app.principal_user_id', v_actor::text, true);
    PERFORM set_config('app.authorization_phase', 'principal', true);
END
$$;


ALTER FUNCTION public.builderad_activate_principal_context(p_ticket text) OWNER TO builderad_owner;


-- Name: builderad_advertiser_goal_event_immutable(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_advertiser_goal_event_immutable() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN RAISE EXCEPTION 'Preference audit history is immutable' USING ERRCODE='23514'; END $$;


ALTER FUNCTION public.builderad_advertiser_goal_event_immutable() OWNER TO builderad_owner;


-- Name: builderad_business_admin(text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_business_admin(p_session text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE actor bigint;
BEGIN
 IF session_user<>'builderad_app' THEN RAISE EXCEPTION 'Runtime caller required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM builderad_signed_context() c WHERE c.phase='principal';
 PERFORM 1 FROM principal_lifecycles WHERE user_id=actor FOR SHARE;
 PERFORM 1 FROM users WHERE id=actor FOR SHARE;
 PERFORM 1 FROM internal_personnel WHERE user_id=actor FOR SHARE;
 PERFORM 1 FROM security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
 PERFORM 1 FROM security_action_grants WHERE subject_user_id=actor AND action='business.manage' FOR SHARE;
 IF NOT EXISTS (SELECT 1 FROM principal_lifecycles l JOIN users u ON u.id=l.user_id
  JOIN internal_personnel p ON p.user_id=l.user_id AND p.status='active'
  JOIN security_sessions s ON s.user_id=l.user_id AND s.lifecycle_generation=l.generation
  JOIN security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='staff' AND u.email_verified_at IS NOT NULL
  AND s.session_key_hash=p_session AND s.auth_method='internal_passkey' AND s.revoked_at IS NULL
  AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
  AND s.authenticated_at BETWEEN clock_timestamp()-interval '5 minutes' AND clock_timestamp()
  AND g.action='business.manage' AND g.scope_kind='platform' AND g.status='active' AND g.revoked_at IS NULL
  AND (g.expires_at IS NULL OR g.expires_at>clock_timestamp())) THEN
  RAISE EXCEPTION 'Current business configuration authority required' USING ERRCODE='42501';
 END IF;
 RETURN actor;
END $$;


ALTER FUNCTION public.builderad_business_admin(p_session text) OWNER TO builderad_owner;


-- Name: builderad_business_config(text, text, bigint, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_business_config(p_session text, p_kind text, p_type bigint, p_input jsonb) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE actor bigint; row_type business_types; old_prior business_promotion_priors;
 result_id bigint; semantic_change boolean; approval boolean;
BEGIN
 actor:=builderad_business_admin(p_session);
 IF p_kind='type_create' THEN
  INSERT INTO business_types(key,name_ar,name_en,active) VALUES
   (p_input->>'key',p_input->>'name_ar',p_input->>'name_en',(p_input->>'active')::boolean) RETURNING id INTO result_id;
 ELSE
  SELECT * INTO row_type FROM business_types WHERE id=p_type FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Business type missing' USING ERRCODE='22023'; END IF;
  IF p_kind='type_update' THEN
   IF row_type.key<>p_input->>'key' THEN RAISE EXCEPTION 'Stable key cannot change' USING ERRCODE='22023'; END IF;
   IF row_type.version<>(p_input->>'version')::bigint THEN RAISE EXCEPTION 'Configuration changed' USING ERRCODE='40001'; END IF;
   UPDATE business_types SET name_ar=p_input->>'name_ar',name_en=p_input->>'name_en',active=(p_input->>'active')::boolean,
    version=version+1 WHERE id=p_type;
   result_id:=p_type;
  ELSIF p_kind='guidance' THEN
   INSERT INTO business_source_guidance VALUES(p_type,p_input->>'provider',(p_input->>'priority')::integer,
    p_input->>'rationale_ar',p_input->>'rationale_en',(p_input->>'active')::boolean)
    ON CONFLICT(business_type_id,provider) DO UPDATE SET priority=excluded.priority,rationale_ar=excluded.rationale_ar,
    rationale_en=excluded.rationale_en,active=excluded.active;
   result_id:=p_type;
  ELSIF p_kind='prior' THEN
   IF p_input ? 'id' THEN
    SELECT * INTO old_prior FROM business_promotion_priors WHERE id=(p_input->>'id')::bigint AND business_type_id=p_type FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Prior missing' USING ERRCODE='22023'; END IF;
    IF old_prior.version<>(p_input->>'version')::bigint THEN RAISE EXCEPTION 'Prior changed' USING ERRCODE='40001'; END IF;
    -- Whitespace/formatting is cosmetic; changed claims or applicability require a new approval.
    semantic_change := builderad_business_prior_semantics(to_jsonb(old_prior)) IS DISTINCT FROM builderad_business_prior_semantics(p_input);
    approval:=(p_input->>'approved')::boolean AND NOT semantic_change;
    UPDATE business_promotion_priors SET provider=p_input->>'provider',product=p_input->>'product',basis=p_input->>'basis',
     documented_basis=p_input->>'documented_basis',applicability=p_input->>'applicability',limits=p_input->>'limits',
     rationale_ar=p_input->>'rationale_ar',rationale_en=p_input->>'rationale_en',internal_rationale=p_input->>'internal_rationale',
     approved=approval,active=(p_input->>'active')::boolean,
     approved_by=CASE WHEN approval THEN COALESCE(old_prior.approved_by,actor) END,
     approved_at=CASE WHEN approval THEN COALESCE(old_prior.approved_at,clock_timestamp()) END,
     version=version+1 WHERE id=old_prior.id;
    result_id:=old_prior.id;
   ELSE
    approval:=(p_input->>'approved')::boolean;
    INSERT INTO business_promotion_priors(business_type_id,provider,product,basis,documented_basis,applicability,limits,
     rationale_ar,rationale_en,internal_rationale,approved,active,approved_by,approved_at)
    VALUES(p_type,p_input->>'provider',p_input->>'product',p_input->>'basis',p_input->>'documented_basis',
     p_input->>'applicability',p_input->>'limits',p_input->>'rationale_ar',p_input->>'rationale_en',p_input->>'internal_rationale',
     approval,(p_input->>'active')::boolean,CASE WHEN approval THEN actor END,CASE WHEN approval THEN clock_timestamp() END)
     RETURNING id INTO result_id;
   END IF;
  ELSE RAISE EXCEPTION 'Invalid configuration command' USING ERRCODE='22023'; END IF;
 END IF;
 actor:=builderad_business_admin(p_session);
 INSERT INTO security_audit_events(actor_user_id,subject_user_id,event,reason,new_status,new_generation,occurred_at)
 VALUES(actor,actor,'business_config_changed',p_kind||':'||result_id,'active',
  (SELECT generation FROM principal_lifecycles WHERE user_id=actor),clock_timestamp());
 RETURN result_id;
END $$;


ALTER FUNCTION public.builderad_business_config(p_session text, p_kind text, p_type bigint, p_input jsonb) OWNER TO builderad_owner;


-- Name: builderad_business_prior_semantics(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_business_prior_semantics(p_input jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $$
 SELECT jsonb_object_agg(key,regexp_replace(trim(value),'\s+',' ','g')) FROM jsonb_each_text(p_input)
 WHERE key IN ('provider','product','basis','documented_basis','applicability','limits','internal_rationale','rationale_ar','rationale_en')
$$;


ALTER FUNCTION public.builderad_business_prior_semantics(p_input jsonb) OWNER TO builderad_owner;


-- Name: builderad_commercial_preparation_consumed(uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_commercial_preparation_consumed(target uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
    SELECT EXISTS (SELECT 1 FROM commercial_markets WHERE id = target)
$$;


ALTER FUNCTION public.builderad_commercial_preparation_consumed(target uuid) OWNER TO builderad_owner;


-- Name: builderad_configure_commercial_market(uuid, uuid, text, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_configure_commercial_market(p_command uuid, p_market uuid, p_label text, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r jsonb; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('ConfigureCommercialMarket',p_market,p_label,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 r := builderad_sa01_prior(p_command,'ConfigureCommercialMarket',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 INSERT INTO commercial_markets(id,label) VALUES(p_market,p_label);
 INSERT INTO commercial_market_assignability(market_id) VALUES(p_market);
 RETURN builderad_sa01_finish(p_command,'ConfigureCommercialMarket',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'label',p_label));
END $$;


ALTER FUNCTION public.builderad_configure_commercial_market(p_command uuid, p_market uuid, p_label text, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_content_unique_slug_guard(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_content_unique_slug_guard() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $_$
DECLARE slug text; conflict_found boolean;
BEGIN
  IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
  FOR slug IN SELECT DISTINCT lower(value) FROM unnest(ARRAY[NEW.slug_ar, NEW.slug_en]) AS value ORDER BY 1 LOOP
    PERFORM pg_advisory_xact_lock(hashtextextended(TG_TABLE_NAME || ':' || slug, 0));
  END LOOP;
  EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE id <> $1 AND deleted_at IS NULL AND (lower(slug_ar) = ANY($2) OR lower(slug_en) = ANY($2)))', TG_TABLE_NAME)
    INTO conflict_found USING NEW.id, ARRAY[lower(NEW.slug_ar), lower(NEW.slug_en)];
  IF conflict_found THEN RAISE EXCEPTION 'Slug is already used in %', TG_TABLE_NAME USING ERRCODE = '23505'; END IF;
  RETURN NEW;
END $_$;


ALTER FUNCTION public.builderad_content_unique_slug_guard() OWNER TO builderad_owner;


-- Name: builderad_content_write_audit(bigint, text, text); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_content_write_audit(p_actor bigint, p_event text, p_reason text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
DECLARE generation_number bigint;
BEGIN
  IF p_event IS NULL OR p_event NOT IN (
    'content_section_saved', 'content_section_deleted', 'content_link_saved',
    'content_link_deleted', 'content_page_created', 'content_page_draft_saved',
    'content_page_published', 'content_page_unpublished', 'content_page_deleted',
    'content_page_restored', 'content_page_revision_restored',
    'content_article_created', 'content_article_draft_saved',
    'content_article_published', 'content_article_unpublished',
    'content_article_deleted', 'content_article_restored',
    'content_article_revision_restored', 'content_category_saved',
    'content_category_deleted', 'content_footer_published', 'content_reordered'
  ) THEN RAISE EXCEPTION 'Unsupported content audit event'; END IF;
  SELECT generation INTO generation_number FROM public.principal_lifecycles WHERE user_id = p_actor;
  IF generation_number IS NULL THEN RAISE EXCEPTION 'Unknown content audit actor'; END IF;
  INSERT INTO public.security_audit_events(actor_user_id, subject_user_id, event, reason,
    prior_status, new_status, prior_generation, new_generation, occurred_at)
  VALUES (p_actor, p_actor, p_event, left(p_reason, 255), 'current', 'current',
    generation_number, generation_number, now());
END $$;


ALTER FUNCTION public.builderad_content_write_audit(p_actor bigint, p_event text, p_reason text) OWNER TO builderad_scope_executor;


-- Name: builderad_create_market_configuration(uuid, uuid, uuid, uuid, text, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_create_market_configuration(p_command uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r jsonb; v_version integer; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('ConfigureCommercialMarketVersion',p_market,p_configuration,p_catalog_reference,p_denomination,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 r := builderad_sa01_prior(p_command,'ConfigureCommercialMarketVersion',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM 1 FROM commercial_market_assignability WHERE market_id = p_market FOR UPDATE;
 IF p_catalog_reference IS NOT NULL THEN PERFORM builderad_sa01_require_committed_catalog(p_catalog_reference,p_market); END IF;
 SELECT coalesce(max(version),0)+1 INTO v_version FROM commercial_market_configuration_versions WHERE market_id = p_market;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 INSERT INTO commercial_market_configuration_versions(id,market_id,version,catalog_reference_id,denomination_code)
 VALUES(p_configuration,p_market,v_version,p_catalog_reference,p_denomination);
 RETURN builderad_sa01_finish(p_command,'ConfigureCommercialMarketVersion',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'configuration_id',p_configuration,'version',v_version,'catalog_reference_id',p_catalog_reference,'denomination_code',p_denomination));
END $$;


ALTER FUNCTION public.builderad_create_market_configuration(p_command uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_current_intent_event_immutable(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_event_immutable() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN RAISE EXCEPTION 'Current Intent journal is immutable' USING ERRCODE='23514'; END $$;


ALTER FUNCTION public.builderad_current_intent_event_immutable() OWNER TO builderad_owner;


-- Name: builderad_current_intent_timezone_name_allowed(text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_timezone_name_allowed(p_zone text) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'public', 'pg_temp'
    AS $_$
 SELECT coalesce(length(p_zone)<=120
  AND (p_zone='UTC' OR p_zone ~ '^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)+$')
  AND p_zone !~ '^(posix|right)/',false)
$_$;


ALTER FUNCTION public.builderad_current_intent_timezone_name_allowed(p_zone text) OWNER TO builderad_owner;


-- Name: builderad_current_intent_timezone_names(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_timezone_names() RETURNS TABLE(name text)
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog', 'public', 'pg_temp'
    AS $$
DECLARE reference public.current_intent_timezone_catalog;
BEGIN
    SELECT * INTO reference FROM public.current_intent_timezone_catalog WHERE id=1;
    IF NOT FOUND OR reference.postgres_version<>current_setting('server_version_num')::integer THEN
        RAISE EXCEPTION 'CURRENT_INTENT_TIMEZONE_REFERENCE_REFRESH_REQUIRED' USING ERRCODE='55000';
    END IF;
    RETURN QUERY SELECT value FROM jsonb_array_elements_text(reference.names);
END;
$$;


ALTER FUNCTION public.builderad_current_intent_timezone_names() OWNER TO builderad_owner;


-- Name: builderad_current_intent_timezone_supported(text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    SET "TimeZone" TO 'UTC'
    AS $$
BEGIN
 IF NOT builderad_current_intent_timezone_name_allowed(p_zone) THEN RETURN false; END IF;
 -- A leading colon requires a timezone file, without POSIX-spec fallback.
 -- PostgreSQL returns its canonical filename, preserving exact-name acceptance.
 -- The function-local GUC scope restores the caller's timezone on every exit.
 RETURN set_config('TimeZone',':'||p_zone,true)=p_zone;
EXCEPTION WHEN invalid_parameter_value THEN RETURN false;
END;
$$;


ALTER FUNCTION public.builderad_current_intent_timezone_supported(p_zone text) OWNER TO builderad_owner;


-- Name: builderad_current_intent_validate_horizon(jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_current_intent_validate_horizon(p_horizon jsonb) RETURNS void
    LANGUAGE plpgsql STABLE
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE zone_name text; civil_date date;
BEGIN
 zone_name:=p_horizon->>'timezone';
 IF NOT builderad_current_intent_timezone_supported(zone_name) THEN
  RAISE EXCEPTION 'Recognized named timezone required' USING ERRCODE='22023';
 END IF;
 -- Lexical/Gregorian shape has already been statically validated. A midnight
 -- shift within the same date is valid; a skipped civil date is not.
 civil_date:=(p_horizon->>'deadline_date')::date;
 IF ((civil_date::timestamp AT TIME ZONE zone_name) AT TIME ZONE zone_name)::date<>civil_date THEN
  RAISE EXCEPTION 'Selected civil date does not exist in this timezone' USING ERRCODE='22023';
 END IF;
END $$;


ALTER FUNCTION public.builderad_current_intent_validate_horizon(p_horizon jsonb) OWNER TO builderad_owner;


-- Name: builderad_customer_directory_witness(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_customer_directory_witness() RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 PERFORM builderad_customer_administrator();
 RETURN true;
END $$;


ALTER FUNCTION public.builderad_customer_directory_witness() OWNER TO builderad_owner;


-- Name: builderad_customer_owner_continuity(bigint, bigint); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_customer_owner_continuity(p_user bigint, p_generation bigint) RETURNS TABLE(prior_generation bigint, occurred_at timestamp with time zone)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE cursor_generation bigint:=p_generation; latest_restore timestamptz; restore customer_administration_events%ROWTYPE; suspension customer_administration_events%ROWTYPE;
BEGIN
 LOOP
  SELECT * INTO restore FROM customer_administration_events WHERE subject_user_id=p_user
   AND decision='reactivate' AND new_status='current' AND new_revision=cursor_generation;
  EXIT WHEN NOT FOUND;
  SELECT * INTO suspension FROM customer_administration_events WHERE subject_user_id=p_user
   AND decision='suspend' AND prior_status='current' AND new_status='disabled'
   AND new_revision=restore.expected_revision AND expected_revision=new_revision-1;
  EXIT WHEN NOT FOUND OR restore.new_revision<>restore.expected_revision+1;
  latest_restore:=coalesce(latest_restore,restore.occurred_at);
  cursor_generation:=suspension.expected_revision;
 END LOOP;
 IF latest_restore IS NOT NULL THEN
  -- SessionAuthority records authentication with second precision. The current
  -- generation and revoked-session fences still exclude every pre-restore session.
  RETURN QUERY SELECT cursor_generation,date_trunc('second',latest_restore);
 ELSE
  RETURN QUERY SELECT p_generation-1,e.occurred_at FROM security_audit_events e
   WHERE e.subject_user_id=p_user AND e.event='primary_email_changed' AND e.prior_generation<p_generation
   ORDER BY e.occurred_at DESC LIMIT 1;
 END IF;
END $$;


ALTER FUNCTION public.builderad_customer_owner_continuity(p_user bigint, p_generation bigint) OWNER TO builderad_owner;


-- Name: builderad_founder_configure_country_market(uuid, uuid, text, text, text, text, uuid, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_founder_configure_country_market(p_command uuid, p_market uuid, p_label text, p_reason text, p_country text, p_currency text, p_configuration_command uuid, p_configuration uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE result jsonb; configuration jsonb;
BEGIN
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 IF p_country IS NULL OR p_country NOT IN ('SA','AE','KW','QA','BH','OM','JO')
 OR p_currency IS NULL OR p_currency NOT IN ('SAR','AED','KWD','QAR','BHD','OMR','JOD','USD') THEN
   RAISE EXCEPTION 'Supported country and currency required' USING ERRCODE='23514';
 END IF;
 -- Country, currency and stable child identities participate in the authoritative
 -- parent command fingerprint, including replays from a changed PHP session.
 result:=builderad_configure_commercial_market(p_command,p_market,p_label,
   'founder_country_'||substr(encode(sha256(convert_to(jsonb_build_array(p_reason,p_country,p_currency,p_configuration_command,p_configuration)::text,'UTF8')),'hex'),1,32));
 INSERT INTO commercial_founder_market_notes(command_id,reason) VALUES(p_command,p_reason) ON CONFLICT DO NOTHING;
 INSERT INTO commercial_market_countries(market_id,country_code,command_id) VALUES(p_market,p_country,p_command) ON CONFLICT DO NOTHING;
 configuration:=builderad_create_market_configuration(p_configuration_command,p_market,p_configuration,NULL,p_currency,'founder_country_configuration');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 RETURN result||jsonb_build_object('country_code',p_country,'configuration_id',p_configuration,'denomination_code',p_currency);
END $$;


ALTER FUNCTION public.builderad_founder_configure_country_market(p_command uuid, p_market uuid, p_label text, p_reason text, p_country text, p_currency text, p_configuration_command uuid, p_configuration uuid) OWNER TO builderad_owner;


-- Name: builderad_founder_configure_market(uuid, uuid, text, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_founder_configure_market(p_command uuid, p_market uuid, p_label text, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE result jsonb;
BEGIN
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 result:=builderad_configure_commercial_market(p_command,p_market,p_label,
   'founder_market_'||substr(encode(sha256(convert_to(p_reason,'UTF8')),'hex'),1,32));
 INSERT INTO commercial_founder_market_notes(command_id,reason) VALUES(p_command,p_reason) ON CONFLICT DO NOTHING;
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_founder_configure_market(p_command uuid, p_market uuid, p_label text, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_guard_identity_transition(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_guard_identity_transition() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.epoch <> 0 THEN
            RAISE EXCEPTION 'A lineage must start anonymous' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.lineage_id <> OLD.lineage_id OR NEW.boundary <> OLD.boundary
        OR NEW.created_at <> OLD.created_at OR NEW.epoch <> OLD.epoch + 1
        OR NEW.last_nonce IS NOT DISTINCT FROM OLD.last_nonce THEN
        RAISE EXCEPTION 'A transition must advance exactly one epoch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;


ALTER FUNCTION public.builderad_guard_identity_transition() OWNER TO builderad_owner;


-- Name: builderad_guard_lifecycle_generation(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_guard_lifecycle_generation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
    IF NEW.user_id <> OLD.user_id OR NEW.generation < OLD.generation OR
       (NEW.status <> OLD.status AND NEW.status IN ('current', 'disabled')
        AND NEW.generation <= OLD.generation) THEN
        RAISE EXCEPTION 'Lifecycle generation must advance monotonically'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;


ALTER FUNCTION public.builderad_guard_lifecycle_generation() OWNER TO builderad_owner;


-- Name: builderad_record_commercial_catalog(uuid, uuid, uuid, uuid, text, text, text, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_record_commercial_catalog(p_command uuid, p_market uuid, p_reference uuid, p_catalog uuid, p_version text, p_approval text, p_source text, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r jsonb; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('RecordOrVerifyApprovedMarketReference',p_market,p_reference,p_catalog,p_version,p_approval,p_source,p_reason)::text,'UTF8')),'hex');
 PERFORM builderad_sa01_require('RecordOrVerifyApprovedMarketReference',p_market,NULL,p_reference);
 r := builderad_sa01_prior(p_command,'RecordOrVerifyApprovedMarketReference',p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 -- Shared marker does not serialize independent producers. Hash collision can
 -- only cause a conservative denial in this open transaction, never eligibility.
 PERFORM pg_advisory_xact_lock_shared(hashtextextended('sa01.catalog.producer.'||p_reference::text,0));
 PERFORM builderad_sa01_require('RecordOrVerifyApprovedMarketReference',p_market,NULL,p_reference);
 INSERT INTO commercial_catalog_references(id,market_id,catalog_identity,catalog_version,approval_reference,source_sha256)
 VALUES(p_reference,p_market,p_catalog,p_version,p_approval,p_source);
 RETURN builderad_sa01_finish(p_command,'RecordOrVerifyApprovedMarketReference',p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('catalog_reference_id',p_reference,'market_id',p_market,'catalog_identity',p_catalog,'catalog_version',p_version));
END $$;


ALTER FUNCTION public.builderad_record_commercial_catalog(p_command uuid, p_market uuid, p_reference uuid, p_catalog uuid, p_version text, p_approval text, p_source text, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_sa01_assignability_complete(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_assignability_complete() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN
 IF NEW.configuration_id IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM commercial_market_configuration_versions c
   JOIN commercial_catalog_references r ON r.id = c.catalog_reference_id AND r.market_id = c.market_id
   WHERE c.id = NEW.configuration_id AND c.market_id = NEW.market_id AND c.denomination_code IS NOT NULL
 ) THEN RAISE EXCEPTION 'Incomplete configuration cannot be assignable' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;


ALTER FUNCTION public.builderad_sa01_assignability_complete() OWNER TO builderad_owner;


-- Name: builderad_sa01_immutable(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_immutable() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'pg_temp'
    AS $$
BEGIN RAISE EXCEPTION 'SA-01 immutable historical fact' USING ERRCODE = '23514'; END $$;


ALTER FUNCTION public.builderad_sa01_immutable() OWNER TO builderad_owner;


-- Name: builderad_sa01_require_committed_catalog(uuid, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_require_committed_catalog(p_reference uuid, p_market uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE v_key bigint := hashtextextended('sa01.catalog.producer.'||p_reference::text,0);
BEGIN
 IF NOT EXISTS (SELECT 1 FROM commercial_catalog_references r WHERE r.id = p_reference AND r.market_id = p_market)
 OR EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted
   AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
   AND classid::bigint = ((v_key >> 32) & 4294967295::bigint)
   AND objid::bigint = (v_key & 4294967295::bigint) AND objsubid = 1) THEN
   RAISE EXCEPTION 'Separately committed exact Catalog required' USING ERRCODE = '23514';
 END IF;
END $$;


ALTER FUNCTION public.builderad_sa01_require_committed_catalog(p_reference uuid, p_market uuid) OWNER TO builderad_owner;


-- Name: builderad_set_market_assignability(uuid, uuid, uuid, bigint, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_set_market_assignability(p_command uuid, p_market uuid, p_configuration uuid, p_expected_revision bigint, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r jsonb; v_effect text; v_revision bigint; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('MarketAssignability',p_market,p_configuration,p_expected_revision,p_reason)::text,'UTF8')),'hex');
 v_effect := CASE WHEN p_configuration IS NULL THEN 'DisableCommercialMarket' ELSE 'EnableCommercialMarket' END;
 PERFORM builderad_sa01_require(v_effect,p_market);
 r := builderad_sa01_prior(p_command,v_effect,p_market,NULL,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 SELECT revision INTO v_revision FROM commercial_market_assignability WHERE market_id = p_market FOR UPDATE;
 IF v_revision IS NULL OR v_revision <> p_expected_revision THEN RAISE EXCEPTION 'Assignability revision conflict' USING ERRCODE = '23505'; END IF;
 IF p_configuration IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM commercial_market_configuration_versions c JOIN commercial_catalog_references r ON r.id = c.catalog_reference_id AND r.market_id = c.market_id
   WHERE c.id = p_configuration AND c.market_id = p_market AND c.denomination_code IS NOT NULL
 ) THEN RAISE EXCEPTION 'Complete exact approved configuration required' USING ERRCODE = '23514'; END IF;
 IF p_configuration IS NOT NULL THEN
   PERFORM builderad_sa01_require_committed_catalog((SELECT catalog_reference_id FROM commercial_market_configuration_versions WHERE id = p_configuration),p_market);
 END IF;
 PERFORM builderad_sa01_require(v_effect,p_market);
 UPDATE commercial_market_assignability SET configuration_id = p_configuration,revision = revision+1,changed_at = clock_timestamp() WHERE market_id = p_market;
 RETURN builderad_sa01_finish(p_command,v_effect,p_market,NULL,p_fingerprint,p_reason,jsonb_build_object('market_id',p_market,'configuration_id',p_configuration,'revision',v_revision+1));
END $$;


ALTER FUNCTION public.builderad_set_market_assignability(p_command uuid, p_market uuid, p_configuration uuid, p_expected_revision bigint, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_signed_context(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_signed_context() RETURNS TABLE(actor_user_id bigint, phase text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE
    v_parts text[];
    v_payload text;
    v_expected text;
BEGIN
    v_parts := string_to_array(current_setting('app.signed_context', true), '|');
    IF array_length(v_parts, 1) IS DISTINCT FROM 5
       OR pg_current_xact_id_if_assigned() IS NULL
       OR v_parts[3] <> pg_current_xact_id_if_assigned()::text
       OR v_parts[4] <> pg_backend_pid()::text
       OR v_parts[2] IS DISTINCT FROM 'principal'
       OR current_setting('app.principal_user_id', true) IS DISTINCT FROM v_parts[1]
       OR current_setting('app.authorization_phase', true) IS DISTINCT FROM v_parts[2] THEN
        RETURN;
    END IF;
    v_payload := array_to_string(v_parts[1:4], '|');
    SELECT encode(hmac(convert_to(v_payload, 'UTF8'), secret, 'sha256'), 'hex')
    INTO v_expected FROM builderad_context_secret WHERE singleton;
    IF v_expected IS NULL OR v_expected <> v_parts[5] THEN
        RETURN;
    END IF;
    RETURN QUERY SELECT v_parts[1]::bigint, v_parts[2];
EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN
    RETURN;
END
$$;


ALTER FUNCTION public.builderad_signed_context() OWNER TO builderad_owner;


-- Name: builderad_v2_boundary_surface(text, uuid, bigint); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_boundary_surface(p_session text, p_boundary uuid, p_read bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;selection jsonb;proof jsonb;result jsonb;rows_value jsonb;has_more boolean;
BEGIN
 PERFORM builderad_private.lock_boundary(p_boundary,false);actor:=builderad_private.principal(p_session,false);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND scope_kind='client_data' AND boundary_id=p_boundary AND subject_user_id=actor AND action='client_data.manage')
 THEN RAISE EXCEPTION 'Explicit own boundary management path required' USING ERRCODE='42501';END IF;
 selection:=builderad_private.boundary_selection(p_read);
 proof:=builderad_private.require_scope('client_data',p_boundary::text,actor,'client_data.manage',selection,p_session);
 -- Exact IDs are administrative references. Business names/profile facts remain under Business reading.
 WITH latest AS MATERIALIZED(SELECT DISTINCT ON(business_id,branch_id) * FROM public.boundary_subjects WHERE boundary_id=p_boundary ORDER BY business_id,branch_id,revision DESC,membership_id DESC),
 page AS MATERIALIZED(SELECT * FROM latest ORDER BY membership_id DESC LIMIT 65)
 SELECT coalesce(jsonb_agg(jsonb_build_object('membership_id',x.membership_id,'business_public_id',b.public_id,'branch_public_id',br.public_id,
  'state',x.status,'revision',x.revision) ORDER BY x.membership_id DESC) FILTER(WHERE x.position<=64),'[]'::jsonb),count(*)>64 INTO rows_value,has_more
 FROM(SELECT *,row_number() OVER(ORDER BY membership_id DESC) position FROM page) x
 JOIN public.businesses b ON b.id=x.business_id LEFT JOIN public.business_branches br ON br.id=x.branch_id AND br.business_id=x.business_id;
 SELECT jsonb_build_object('boundary',jsonb_build_object('boundary_id',b.boundary_id,'state',b.status,'revision',b.revision),
  'authority_selection',selection,'memberships',rows_value,'memberships_limit',64,'memberships_more',has_more) INTO result FROM public.client_data_boundaries b WHERE boundary_id=p_boundary;
 PERFORM builderad_private.require_scope('client_data',p_boundary::text,actor,'client_data.manage',selection,p_session);PERFORM builderad_private.principal(p_session);
 RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_boundary_surface(p_session text, p_boundary uuid, p_read bigint) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_business_data_use_binding(text, uuid, uuid, uuid, text, bigint, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_business_data_use_binding(p_session text, p_business uuid, p_command uuid, p_scope uuid, p_effect text, p_expected bigint, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;b bigint;actor bigint;proof jsonb;use_proof jsonb;payload bytea;result jsonb;prior public.business_data_use_bindings;r public.business_data_use_bindings;changed boolean;
BEGIN
 IF p_effect NOT IN('add','end') OR p_command IS NULL OR p_scope IS NULL OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed binding command required' USING ERRCODE='22023'; END IF;
 IF p_effect='add' THEN s:=builderad_private.lock_data_scope(p_scope);ELSE s:=builderad_private.lock_binding_withdrawal(p_business,p_scope);END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 IF jsonb_array_length(p_selection->'requirements')<>(CASE WHEN p_effect='add' THEN 2 ELSE 1 END) THEN RAISE EXCEPTION 'Exact rights for binding effect required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
 IF p_effect='add' AND s.rights_domain='market' THEN PERFORM builderad_private.market_policy(s.scope_id); END IF;
 IF p_effect='add' THEN use_proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);END IF;PERFORM builderad_private.principal(p_session);
 IF p_effect='add' AND((SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') OR(s.rights_domain='client' AND NOT EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=s.boundary_id AND business_id=b AND branch_id IS NULL AND status='active'))) THEN RAISE EXCEPTION 'Qualified Business boundary coverage required' USING ERRCODE='42501'; END IF;
 proof:=proof||jsonb_build_object('data_use_authority',use_proof,'dependencies',(SELECT jsonb_agg(to_jsonb(f) ORDER BY fence_id) FROM public.resource_right_fences f WHERE f.source_id=s.source_id OR(s.rights_domain='client' AND f.boundary_id=s.boundary_id) OR(s.rights_domain='market' AND f.data_use_scope_id=s.scope_id)));
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('scope',p_scope,'effect',p_effect,'expected',p_expected),'{"scope":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.binding.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO prior FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=p_scope ORDER BY revision DESC,binding_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Binding changed before effect' USING ERRCODE='B4090'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.binding.'||p_effect,jsonb_build_object('business_id',b,'scope_id',p_scope),proof);
 r:=prior;changed:=false;
 IF p_effect='add' AND(prior.binding_id IS NULL OR prior.status='ended') THEN
  r.binding_id:=uuidv7();r.business_id:=b;r.data_use_scope_id:=p_scope;r.status:='active';r.revision:=coalesce(prior.revision,0)+1;r.qualification_ref:=(use_proof#>>'{qualification,qualification_id}')::uuid;
  PERFORM builderad_private.permit('public.business_data_use_bindings','insert',NULL,to_jsonb(r));INSERT INTO public.business_data_use_bindings SELECT(r).*;changed:=true;
 ELSIF p_effect='end' AND prior.status='active' THEN
  r.status:='ended';r.revision:=prior.revision+1;PERFORM builderad_private.permit('public.business_data_use_bindings','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.business_data_use_bindings SET status=r.status,revision=r.revision WHERE binding_id=r.binding_id;changed:=true;
 END IF;
 IF changed THEN PERFORM builderad_private.advance_business_epoch(b); END IF;
 PERFORM builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session);
 IF p_effect='add' THEN PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||s.operation,p_selection,p_session);END IF;PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('binding_id',r.binding_id,'status',CASE WHEN p_effect='end' THEN 'ended' ELSE 'active' END,'versions',jsonb_build_object('binding',coalesce(r.revision,0)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.binding.'||p_effect,payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_data_use_binding(p_session text, p_business uuid, p_command uuid, p_scope uuid, p_effect text, p_expected bigint, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_business_finding_capture(text, uuid, uuid, uuid, uuid, uuid, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_business_finding_capture(p_session text, p_business uuid, p_command uuid, p_run uuid, p_read uuid, p_output uuid, p_process jsonb, p_context jsonb, p_continuation jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE actor bigint;b bigint;run public.market_processing_runs;r public.market_business_finding_captures;receipt public.business_command_receipts;plan jsonb;extra jsonb;combined jsonb;fences jsonb;q jsonb;rights jsonb;reads jsonb:='[]';item jsonb;right_value jsonb;proof jsonb;result jsonb;payload bytea;old public.business_command_receipts;op text;
BEGIN
 IF p_command IS NULL OR p_run IS NULL OR p_read IS NULL OR p_output IS NULL THEN RAISE EXCEPTION 'Exact private producer command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT * INTO run FROM public.market_processing_runs WHERE run_id=p_run AND actor_user_id=actor AND business_id=(SELECT id FROM public.businesses WHERE public_id=p_business);
 IF run.run_id IS NULL OR run.state NOT IN('validated','abstained') OR run.finding IS NULL OR run.completion_attestation_digest IS NULL THEN RAISE EXCEPTION 'Trusted completed private processing required' USING ERRCODE='42501'; END IF;
 plan:=builderad_private.material_use_plan(actor,p_business,run.material_refs,'process',run.purpose,p_process);
 IF p_continuation-'requirements' IS DISTINCT FROM p_process-'requirements' THEN RAISE EXCEPTION 'Same explicit producer authority realm required' USING ERRCODE='42501'; END IF;
 SELECT coalesce(jsonb_agg(to_jsonb(s) ORDER BY s.scope_id),'[]') INTO extra FROM public.data_use_scopes s WHERE s.scope_id IN(
  SELECT g.data_use_scope_id FROM public.security_action_grants g JOIN jsonb_array_elements(p_continuation->'requirements') req ON req->>'leaf_grant_id'=g.id::text);
 plan:=jsonb_set(plan,'{lineage_scopes}',(plan->'lineage_scopes')||extra);
 fences:=builderad_private.lock_material_resources(plan,(SELECT to_jsonb(s) FROM public.data_use_scopes s WHERE scope_id=p_output));
 PERFORM 1 FROM public.market_evidence_heads WHERE evidence_id IN(SELECT(value->>'key')::uuid FROM jsonb_array_elements(run.material_refs)) ORDER BY evidence_id FOR SHARE;
 b:=builderad_private.lock_business(p_business);
 combined:=jsonb_set(p_process,'{requirements}',(p_process->'requirements')||(p_continuation->'requirements')||coalesce(p_context->'requirements','[]'));
 PERFORM builderad_private.lock_authority_footprint(actor,combined,p_session);
 q:=builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
 rights:=builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
 SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=p_read;
 IF receipt.action IS DISTINCT FROM 'material.process' OR receipt.actor_user_id IS DISTINCT FROM actor OR receipt.business_id IS DISTINCT FROM b OR receipt.generation IS DISTINCT FROM run.generation
 OR receipt.result_ref->>'purpose' IS DISTINCT FROM run.purpose THEN RAISE EXCEPTION 'Actual same-Business processing read receipt required' USING ERRCODE='42501'; END IF;
 FOR item IN SELECT value FROM jsonb_array_elements(q#>'{proof,material_uses}') LOOP
  IF NOT(receipt.result_ref->'material_uses') @> jsonb_build_array(item) THEN RAISE EXCEPTION 'Original input read receipt mismatch' USING ERRCODE='42501'; END IF;
  FOREACH op IN ARRAY ARRAY['forward','retain'] LOOP
   SELECT value INTO right_value FROM jsonb_array_elements(rights) v WHERE v#>>'{scope,operation}'=op AND(v#>'{scope,selection,native_keys}') ? (item#>>'{ref,key}');
   IF NOT(right_value#>'{scope,selection,field_keys}') @> (SELECT selection->'field_keys' FROM public.data_use_scopes WHERE scope_id=(item->>'data_use_scope_id')::uuid) THEN RAISE EXCEPTION 'Continuing field scope cannot narrow the captured finding licence' USING ERRCODE='42501'; END IF;
  END LOOP;
  reads:=reads||jsonb_build_array(jsonb_build_object('input',item->'ref','data_use_scope_id',item->'data_use_scope_id','policy_id',item->'policy_id','policy_version',item->'policy_version','read_command_id',p_read));
 END LOOP;
 IF NOT EXISTS(SELECT 1 FROM public.data_use_scopes s WHERE scope_id=p_output AND s.rights_domain='market' AND s.resource='market.business_finding' AND s.operation='display'
  AND s.purpose=run.purpose AND s.selection->'native_namespaces'='["market.business_finding"]'::jsonb AND s.selection->'native_keys'=jsonb_build_array(p_command::text) AND s.selection->'field_keys'='["finding"]'::jsonb)
 THEN RAISE EXCEPTION 'Exact independent private output licence required' USING ERRCODE='42501'; END IF;
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'read',p_read,'output',p_output,'process',p_process,'context',p_context,'continuation',p_continuation)::text,'UTF8'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.private.finding.'||p_command,0));
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF old.action IS DISTINCT FROM 'market.business_finding.capture' OR old.actor_user_id IS DISTINCT FROM actor OR old.business_id IS DISTINCT FROM b OR old.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Private capture command conflict' USING ERRCODE='40001'; END IF;
  -- Replay has the same current processing/continuation contract after a command-lock wait.
  PERFORM builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
  PERFORM builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
  RETURN old.result_ref;
 END IF;
 r.artifact_id:=p_command;r.processing_run_id:=run.run_id;r.business_id:=b;r.processor_user_id:=actor;r.output_scope_id:=p_output;r.purpose:=run.purpose;r.input_reads:=reads;r.continuation_rights:=rights;
 r.context_snapshot:=q->'business_context';r.state:='prepared';r.created_at:=clock_timestamp();
 r.material_digest:=sha256(convert_to(jsonb_build_object('artifact',r.artifact_id,'run',run.run_id,'finding',run.finding,'attestation',encode(run.completion_attestation_digest,'hex'),'business',p_business,'purpose',r.purpose,'reads',reads,'context',r.context_snapshot)::text,'UTF8'));
 proof:=(q->'proof')||jsonb_build_object('continuation_rights',rights,'dependencies',fences);
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.business_finding.capture',jsonb_build_object('business_id',b,'artifact',r.artifact_id),proof);
 PERFORM builderad_private.permit('public.market_business_finding_captures','insert',NULL,to_jsonb(r));INSERT INTO public.market_business_finding_captures SELECT(r).*;
 result:=jsonb_build_object('artifact_ref',jsonb_build_object('namespace','market.business_finding','key',r.artifact_id,'version',encode(r.material_digest,'hex')),
  'dependencies',builderad_private.producer_dependencies(jsonb_build_object('input_reads',reads)),'versions',jsonb_build_object('capture',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'market.business_finding.capture',payload,result);
 PERFORM builderad_private.processing_input(p_session,p_business,p_run,p_process,p_context);
 PERFORM builderad_private.business_finding_continuation(p_session,actor,run.material_refs,run.purpose,p_continuation);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_business_finding_capture(p_session text, p_business uuid, p_command uuid, p_run uuid, p_read uuid, p_output uuid, p_process jsonb, p_context jsonb, p_continuation jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_customer_discovery_practice(text, uuid, uuid, uuid, bigint, jsonb, text, jsonb, jsonb, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_customer_discovery_practice(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_expected bigint, p_ref jsonb, p_purpose text, p_relation jsonb, p_question jsonb, p_read jsonb, p_material jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
END $_$;


ALTER FUNCTION public.builderad_v2_customer_discovery_practice(p_session text, p_business uuid, p_command uuid, p_practice uuid, p_expected bigint, p_ref jsonb, p_purpose text, p_relation jsonb, p_question jsonb, p_read jsonb, p_material jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_inquiry_eligibility(text, uuid, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_inquiry_eligibility(p_session text, p_business uuid, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;basis uuid;rev bigint;rights jsonb;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_basis(b,actor,p_selection,p_session);
 basis:=(proof#>>'{qualification,qualification_id}')::uuid;rev:=(proof#>>'{qualification,revision}')::bigint;
 SELECT coalesce(jsonb_object_agg(g.action,g.id::text),'{}'::jsonb) INTO rights
 FROM public.inquiry_evidence_enrollments en JOIN public.authority_qualification_receipts q ON q.qualification_id=en.qualification_id
 JOIN public.security_action_grants g ON g.qualification_id=en.qualification_id
 WHERE en.business_id=b AND en.actor_user_id=actor AND en.generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor)
 AND en.basis_qualification_id=basis AND en.basis_revision=rev AND en.status='active'
 AND q.status='active' AND q.valid_from<=clock_timestamp() AND(q.valid_until IS NULL OR q.valid_until>clock_timestamp())
 AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp());
 PERFORM builderad_private.principal(p_session,false);
 RETURN jsonb_build_object('business',p_business,'basis_qualification',basis,'basis_revision',rev,'policy','inquiry_evidence_custody_v1','policy_version',1,'mode','own_private_statement','grants',rights);
END $$;


ALTER FUNCTION public.builderad_v2_inquiry_eligibility(p_session text, p_business uuid, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_inquiry_enroll(text, uuid, uuid, bigint, boolean, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_inquiry_enroll(p_session text, p_business uuid, p_command uuid, p_expected_basis bigint, p_confirm boolean, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;basis public.authority_qualification_receipts;q public.authority_qualification_receipts;
 en public.inquiry_evidence_enrollments;prior_en public.inquiry_evidence_enrollments;new_en public.inquiry_evidence_enrollments;
 g public.security_action_grants;old_g public.security_action_grants;payload bytea;result jsonb;rights jsonb:='{}';a text;
BEGIN
 IF p_command IS NULL OR p_expected_basis IS NULL OR p_expected_basis<1 OR p_confirm IS DISTINCT FROM true THEN RAISE EXCEPTION 'Explicit inquiry opt-in required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_basis(b,actor,p_selection,p_session,p_expected_basis),actor,p_session);
 SELECT * INTO STRICT basis FROM public.authority_qualification_receipts WHERE qualification_id=(proof#>>'{qualification,qualification_id}')::uuid;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'basis',basis.qualification_id,'basis_revision',p_expected_basis,'confirm',true,'policy_version',1),'{"business":"U","basis":"U","basis_revision":"I","policy_version":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'inquiry.enroll',payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 -- Old enrollments are serialized by the existing Business K3 write gate; their original actor is the only eligible issuer.
 PERFORM builderad_private.start_command(actor,p_session,p_command,'inquiry.enroll',jsonb_build_object('business_id',b),proof);
 FOR prior_en IN SELECT * FROM public.inquiry_evidence_enrollments WHERE business_id=b AND actor_user_id=actor AND status='active' LOOP
  new_en:=prior_en;new_en.status:='withdrawn';
  PERFORM builderad_private.permit('public.inquiry_evidence_enrollments','update',to_jsonb(prior_en),to_jsonb(new_en));
  UPDATE public.inquiry_evidence_enrollments SET status='withdrawn' WHERE qualification_id=prior_en.qualification_id;
 END LOOP;
 q.qualification_id:=uuidv7();q.policy_id:='inquiry_evidence_custody_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';q.issuer_grant_id:=NULL;
 q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read'];
 q.evidence_ref:='command:'||p_command::text;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=basis.valid_until;
 q.provenance:='Own private source assertions only; exact original qualification dependency; no analytical or external-source authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 en.qualification_id:=q.qualification_id;en.business_id:=b;en.actor_user_id:=actor;en.generation:=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor);
 en.basis_qualification_id:=basis.qualification_id;en.basis_revision:=basis.revision;en.policy_version:=1;en.status:='active';en.created_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.inquiry_evidence_enrollments','insert',NULL,to_jsonb(en));INSERT INTO public.inquiry_evidence_enrollments SELECT(en).*;
 FOREACH a IN ARRAY q.allowed_actions LOOP
  g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.granted_by:=NULL;g.source:='qualified_policy';g.action:=a;g.scope_kind:='business';g.business_id:=b;
  g.subject_generation:=en.generation;g.reason:='Explicit private inquiry statement opt-in';g.status:='active';g.version:=1;g.expires_at:=basis.valid_until;
  g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.parent_grant_id:=NULL;
  g.delegable:=false;g.delegation_realm_kind:='direct';
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  rights:=rights||jsonb_build_object(a,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 result:=jsonb_build_object('command_id',p_command,'policy','inquiry_evidence_custody_v1','qualification',q.qualification_id,'grants',rights,'versions',jsonb_build_object('policy',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'inquiry.enroll',payload,result);
 PERFORM builderad_private.inquiry_fresh(proof,actor,p_session);PERFORM builderad_private.principal(p_session,false);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_inquiry_enroll(p_session text, p_business uuid, p_command uuid, p_expected_basis bigint, p_confirm boolean, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_inquiry_read(text, uuid, jsonb, uuid, bigint, uuid); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_inquiry_read(p_session text, p_business uuid, p_selection jsonb, p_declaration uuid DEFAULT NULL::uuid, p_after bigint DEFAULT 0, p_cursor uuid DEFAULT NULL::uuid) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;items jsonb;more boolean;cursor_value bigint;collection_cursor uuid;
BEGIN
 IF p_after IS NULL OR p_after<0 OR(p_declaration IS NULL AND p_after<>0) OR(p_declaration IS NOT NULL AND p_cursor IS NOT NULL) THEN RAISE EXCEPTION 'Bounded history cursor required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_require(b,actor,'business.inquiry_evidence.read',p_selection,p_session);
 IF p_declaration IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.inquiry_evidence_heads WHERE declaration_id=p_declaration AND business_id=b AND issuer_user_id=actor)
 THEN RAISE EXCEPTION 'Inquiry declaration unavailable' USING ERRCODE='42501'; END IF;
 IF p_declaration IS NULL THEN
  -- Bounded private current collection; no public count, source search or cross-actor aggregation.
  SELECT coalesce(jsonb_agg(entry ORDER BY declaration_key DESC),'[]'::jsonb) INTO items FROM(
   SELECT h.declaration_id AS declaration_key,jsonb_build_object('declaration_id',h.declaration_id,'revision',v.revision,'state',v.state,'reason',v.reason,'value',v.value,'recorded_at',v.recorded_at,'original_issuer',u.public_id,'correcting_actor',c.public_id) AS entry
   FROM public.inquiry_evidence_heads h JOIN public.inquiry_evidence_revisions v ON v.declaration_id=h.declaration_id AND v.revision=h.revision
   JOIN public.users u ON u.id=h.issuer_user_id JOIN public.users c ON c.id=v.actor_user_id
   WHERE h.business_id=b AND h.issuer_user_id=actor AND(p_cursor IS NULL OR h.declaration_id<p_cursor) ORDER BY h.declaration_id DESC LIMIT 25
  ) scoped;
  collection_cursor:=CASE WHEN jsonb_array_length(items)>0 THEN(items#>>ARRAY[(jsonb_array_length(items)-1)::text,'declaration_id'])::uuid ELSE NULL END;
  more:=collection_cursor IS NOT NULL AND EXISTS(SELECT 1 FROM public.inquiry_evidence_heads WHERE business_id=b AND issuer_user_id=actor AND declaration_id<collection_cursor);
  cursor_value:=NULL;
 ELSE
  SELECT coalesce(jsonb_agg(entry ORDER BY revision_key),'[]'::jsonb),max(revision_key) INTO items,cursor_value FROM(
   SELECT v.revision AS revision_key,jsonb_build_object('declaration_id',v.declaration_id,'revision',v.revision,'state',v.state,'reason',v.reason,'value',v.value,'recorded_at',v.recorded_at,'original_issuer',u.public_id,'correcting_actor',c.public_id) AS entry
   FROM public.inquiry_evidence_revisions v JOIN public.inquiry_evidence_heads h ON h.declaration_id=v.declaration_id
   JOIN public.users u ON u.id=h.issuer_user_id JOIN public.users c ON c.id=v.actor_user_id
   WHERE h.business_id=b AND h.issuer_user_id=actor AND v.declaration_id=p_declaration AND v.revision>p_after ORDER BY v.revision LIMIT 25
  ) scoped;
  more:=EXISTS(SELECT 1 FROM public.inquiry_evidence_revisions WHERE declaration_id=p_declaration AND revision>coalesce(cursor_value,p_after));
 END IF;
 PERFORM builderad_private.inquiry_require(b,actor,'business.inquiry_evidence.read',p_selection,p_session);PERFORM builderad_private.principal(p_session,false);
 RETURN jsonb_build_object('business',p_business,'mode','own_private_statement','assurance','SOURCE_ASSERTED','independently_verified',false,
 'items',items,'more',more,'next_declaration',CASE WHEN p_declaration IS NULL AND more THEN collection_cursor ELSE NULL END,'next_revision',CASE WHEN p_declaration IS NOT NULL AND more THEN cursor_value ELSE NULL END,'collection_limit',25);
END $$;


ALTER FUNCTION public.builderad_v2_inquiry_read(p_session text, p_business uuid, p_selection jsonb, p_declaration uuid, p_after bigint, p_cursor uuid) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_inquiry_write(text, uuid, uuid, uuid, bigint, text, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_inquiry_write(p_session text, p_business uuid, p_command uuid, p_declaration uuid, p_expected bigint, p_effect text, p_value jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;action_name text;head public.inquiry_evidence_heads;old_head public.inquiry_evidence_heads;v public.inquiry_evidence_revisions;
BEGIN
 IF p_command IS NULL OR p_expected IS NULL OR p_effect IS NULL OR p_effect NOT IN('record','correct','withdraw')
 OR(p_effect='record' AND(p_declaration IS NOT NULL OR p_expected<>0)) OR(p_effect<>'record' AND(p_declaration IS NULL OR p_expected<1))
 OR(p_effect='withdraw' AND p_value IS NOT NULL)
 THEN RAISE EXCEPTION 'Exact bounded declaration command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 action_name:=CASE WHEN p_effect='record' THEN 'business.inquiry_evidence.record' ELSE 'business.inquiry_evidence.correct' END;
 proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session),actor,p_session);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'declaration',p_declaration,'expected',p_expected,'effect',p_effect,'value',p_value),'{"business":"U","declaration":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'inquiry.'||p_effect,payload);
 IF result IS NOT NULL THEN
  PERFORM builderad_private.inquiry_fresh(builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session),actor,p_session);RETURN result;
 END IF;
 IF p_effect<>'withdraw' THEN p_value:=builderad_private.inquiry_value(p_value); END IF;
 IF p_effect<>'record' THEN
  SELECT * INTO head FROM public.inquiry_evidence_heads WHERE declaration_id=p_declaration AND business_id=b AND issuer_user_id=actor;
  IF head.declaration_id IS NULL THEN RAISE EXCEPTION 'Inquiry declaration unavailable' USING ERRCODE='42501'; END IF;
  IF head.revision<>p_expected THEN RAISE EXCEPTION 'Declaration revision conflict' USING ERRCODE='I4090'; END IF;
  IF EXISTS(SELECT 1 FROM public.inquiry_evidence_revisions WHERE declaration_id=head.declaration_id AND revision=head.revision AND state='withdrawn') THEN RAISE EXCEPTION 'Withdrawn declaration cannot be rewritten' USING ERRCODE='I4090'; END IF;
 END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'inquiry.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect='record' THEN
  head.declaration_id:=uuidv7();head.business_id:=b;head.issuer_user_id:=actor;head.revision:=1;head.created_at:=clock_timestamp();
  PERFORM builderad_private.permit('public.inquiry_evidence_heads','insert',NULL,to_jsonb(head));INSERT INTO public.inquiry_evidence_heads SELECT(head).*;
 ELSE
  old_head:=head;head.revision:=head.revision+1;
  PERFORM builderad_private.permit('public.inquiry_evidence_heads','update',to_jsonb(old_head),to_jsonb(head));UPDATE public.inquiry_evidence_heads SET revision=head.revision WHERE declaration_id=head.declaration_id;
 END IF;
 v.declaration_id:=head.declaration_id;v.revision:=head.revision;v.actor_user_id:=actor;v.qualification_id:=(proof#>>'{qualification,qualification_id}')::uuid;v.command_id:=p_command;
 v.state:=CASE WHEN p_effect='withdraw' THEN 'withdrawn' ELSE 'source_asserted' END;v.reason:=CASE p_effect WHEN 'record' THEN 'initial' WHEN 'correct' THEN 'correction' ELSE 'withdrawal' END;v.value:=p_value;v.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.inquiry_evidence_revisions','insert',NULL,to_jsonb(v));INSERT INTO public.inquiry_evidence_revisions SELECT(v).*;
 -- An effect acknowledgement is NOT a display grant. Never echo protected values through a write-only action.
 result:=jsonb_build_object('command_id',p_command,'declaration_id',head.declaration_id,'revision',head.revision,'state',v.state,'assurance','SOURCE_ASSERTED','independently_verified',false,'versions',jsonb_build_object('declaration',head.revision));
 PERFORM builderad_private.record_command(b,actor,p_command,'inquiry.'||p_effect,payload,result);
 PERFORM builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session);PERFORM builderad_private.inquiry_fresh(proof,actor,p_session);
 PERFORM builderad_private.principal(p_session,false);PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_inquiry_write(p_session text, p_business uuid, p_command uuid, p_declaration uuid, p_expected bigint, p_effect text, p_value jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_evidence_write(text, uuid, uuid, uuid, bigint, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_evidence_write(p_session text, p_command uuid, p_evidence uuid, p_scope uuid, p_expected bigint, p_payload jsonb, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE s public.data_use_scopes;h public.market_evidence_heads;e public.market_evidence_revisions;t public.market_evidence_transitions;
 actor bigint;proof jsonb;policy jsonb;payload bytea;result jsonb;prior_fields jsonb;prior_meta jsonb;next_meta jsonb;unchanged boolean;operation text;f record;stamp timestamptz;
BEGIN
 IF p_evidence IS NULL OR uuid_extract_version(p_evidence)<>7 OR p_command IS NULL OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed market command required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_validate_evidence(p_payload);
 actor:=builderad_private.principal(p_session,false);
 operation:=CASE WHEN p_expected=0 THEN 'ingest' ELSE 'correct' END;
 SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_scope;
 IF s.rights_domain IS DISTINCT FROM 'market' OR s.operation IS DISTINCT FROM operation
 OR NOT(s.selection->'native_keys' ? p_evidence::text) OR s.resource<>'market.evidence'
 OR jsonb_array_length(p_selection->'requirements') IS DISTINCT FROM 1
 OR NOT EXISTS(SELECT 1 FROM public.security_action_grants g WHERE g.subject_user_id=actor
  AND g.scope_kind='data_use' AND g.data_use_scope_id=p_scope AND g.status='active' AND g.revoked_at IS NULL
  AND g.action='data_use.'||operation AND g.id::text=p_selection#>>'{requirements,0,leaf_grant_id}'
  AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp()))
 THEN RAISE EXCEPTION 'Exact market write scope required' USING ERRCODE='42501'; END IF;
 -- Freeze source and exact use scope before any authority or evidence-head lock.
 FOR f IN SELECT fence_id FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id) ORDER BY fence_id LOOP
  PERFORM 1 FROM public.resource_right_fences WHERE fence_id=f.fence_id FOR UPDATE;
 END LOOP;
 IF (SELECT count(*) FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id))<>2 THEN RAISE EXCEPTION 'Market resource fences required' USING ERRCODE='42501'; END IF;
 actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||operation,p_selection,p_session);
 policy:=builderad_private.market_policy(p_scope);
 IF (SELECT to_jsonb(x) FROM public.data_use_scopes x WHERE scope_id=p_scope) IS DISTINCT FROM to_jsonb(s)
 OR EXISTS(SELECT 1 FROM jsonb_object_keys(p_payload->'fields') k WHERE NOT(s.selection->'field_keys' ? k))
 THEN RAISE EXCEPTION 'Exact licensed write fields required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('market-command:1',jsonb_build_object('evidence',p_evidence,'scope',p_scope,'expected',p_expected,'payload',p_payload,'selection',p_selection),'{}');
 result:=builderad_private.command_replay(NULL,actor,p_command,'market.'||operation,payload);
 IF result IS NOT NULL THEN RETURN result; END IF;
 SELECT * INTO h FROM public.market_evidence_heads WHERE evidence_id=p_evidence FOR UPDATE;
 IF coalesce(h.current_revision,0)<>p_expected OR(h.evidence_id IS NOT NULL AND(h.source_id<>s.source_id OR h.record_key<>p_payload->>'record_key'))
 THEN RAISE EXCEPTION 'Market head changed' USING ERRCODE='40001'; END IF;
 stamp:=clock_timestamp();
 e.evidence_id:=p_evidence;e.revision:=p_expected+1;e.prior_revision:=nullif(p_expected,0);e.evidence_type:=p_payload->>'evidence_type';
 e.fields:=p_payload->'fields';e.coverage:=p_payload->>'coverage';e.provenance:=p_payload->'provenance';
 e.observed_at:=(p_payload->>'observed_at')::timestamptz;e.effective_at:=(p_payload->>'effective_at')::timestamptz;e.valid_until:=(p_payload->>'valid_until')::timestamptz;
 IF e.observed_at IS NULL OR e.effective_at IS NULL OR NOT isfinite(e.observed_at) OR NOT isfinite(e.effective_at) OR e.observed_at>stamp
 OR(e.valid_until IS NOT NULL AND(NOT isfinite(e.valid_until) OR e.valid_until<=e.effective_at)) THEN RAISE EXCEPTION 'Explicit finite evidence times required' USING ERRCODE='22023'; END IF;
 e.command_id:=p_command;e.recorded_at:=stamp;
 SELECT fields,jsonb_build_object('evidence_type',evidence_type,'coverage',coverage,'provenance',provenance,
  'observed_at',observed_at,'effective_at',effective_at,'valid_until',valid_until) INTO prior_fields,prior_meta
  FROM public.market_evidence_revisions WHERE evidence_id=p_evidence AND revision=p_expected;
 next_meta:=jsonb_build_object('evidence_type',e.evidence_type,'coverage',e.coverage,'provenance',e.provenance,
  'observed_at',e.observed_at,'effective_at',e.effective_at,'valid_until',e.valid_until);
 unchanged:=p_expected>0 AND e.fields IS NOT DISTINCT FROM prior_fields AND next_meta IS NOT DISTINCT FROM prior_meta;
 e.content_digest:=builderad_private.digest('market-evidence:1',jsonb_build_object('source',s.source_id,'evidence',p_evidence,'revision',e.revision,'content',p_payload),'{}');
 proof:=jsonb_build_object('proofs',jsonb_build_array(proof),'market_policy',policy,'dependencies',(SELECT jsonb_agg(to_jsonb(x) ORDER BY fence_id) FROM public.resource_right_fences x WHERE(kind='source' AND source_id=s.source_id) OR(kind='data_use' AND data_use_scope_id=s.scope_id)));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.'||operation,jsonb_build_object('source_id',s.source_id,'evidence_id',p_evidence),proof);
 IF unchanged THEN
  e.revision:=p_expected;
  SELECT content_digest INTO e.content_digest FROM public.market_evidence_revisions WHERE evidence_id=p_evidence AND revision=p_expected;
 ELSE
 IF h.evidence_id IS NULL THEN
  h.evidence_id:=p_evidence;h.source_id:=s.source_id;h.record_key:=p_payload->>'record_key';h.current_revision:=1;
  PERFORM builderad_private.permit('public.market_evidence_heads','insert',NULL,to_jsonb(h));INSERT INTO public.market_evidence_heads SELECT(h).*;
 ELSE
  PERFORM builderad_private.permit('public.market_evidence_heads','update',to_jsonb(h),jsonb_set(to_jsonb(h),'{current_revision}',to_jsonb(e.revision)));
  UPDATE public.market_evidence_heads SET current_revision=e.revision WHERE evidence_id=p_evidence;
 END IF;
 PERFORM builderad_private.permit('public.market_evidence_revisions','insert',NULL,to_jsonb(e));INSERT INTO public.market_evidence_revisions SELECT(e).*;
 t.command_id:=p_command;t.evidence_id:=p_evidence;t.from_revision:=nullif(p_expected,0);t.to_revision:=e.revision;t.reason:=p_payload->>'reason';t.recorded_at:=stamp;
 SELECT array_agg(k ORDER BY k COLLATE "C") INTO t.changed_fields FROM(SELECT jsonb_object_keys(e.fields||coalesce(prior_fields,'{}')) k) keys WHERE e.fields->k IS DISTINCT FROM prior_fields->k;
 t.changed_fields:=coalesce(t.changed_fields,'{}');
 -- Metadata corrections also invalidate an inference even when field values did not change.
 SELECT t.changed_fields||coalesce(array_agg('@'||k ORDER BY k COLLATE "C"),'{}') INTO t.changed_fields
  FROM jsonb_object_keys(next_meta) k WHERE next_meta->k IS DISTINCT FROM prior_meta->k;
 PERFORM builderad_private.permit('public.market_evidence_transitions','insert',NULL,to_jsonb(t));INSERT INTO public.market_evidence_transitions SELECT(t).*;
 END IF;
 result:=jsonb_build_object('material_ref',jsonb_build_object('namespace','market.evidence','key',p_evidence,'version',encode(e.content_digest,'hex')),'versions',jsonb_build_object('evidence',e.revision));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'market.'||operation,payload,result,CASE WHEN unchanged THEN 'no_material_change' ELSE 'committed' END);
 PERFORM builderad_private.require_scope('data_use',p_scope::text,actor,'data_use.'||operation,p_selection,p_session);
 PERFORM builderad_private.market_policy(p_scope);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_market_evidence_write(p_session text, p_command uuid, p_evidence uuid, p_scope uuid, p_expected bigint, p_payload jsonb, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_knowledge_capture(text, uuid, text, jsonb, text, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_knowledge_capture(p_session text, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE inputs jsonb;r public.market_knowledge_captures;actor bigint;payload bytea;prior public.business_command_receipts;result jsonb;
BEGIN
 IF p_command IS NULL OR p_question IS NULL OR p_question NOT IN('compare_offers','compare_interest','record_price','record_geography') THEN RAISE EXCEPTION 'Registered shared market method required' USING ERRCODE='22023'; END IF;
 IF jsonb_array_length(p_refs) IS DISTINCT FROM(CASE WHEN p_question IN('compare_offers','compare_interest') THEN 2 ELSE 1 END) THEN RAISE EXCEPTION 'Exact registered method input count required' USING ERRCODE='22023'; END IF;
 inputs:=builderad_private.knowledge_inputs(p_session,'market.knowledge.capture',p_refs,p_purpose,p_selection);actor:=(inputs#>>'{proof,actor}')::bigint;
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.market.knowledge.capture.'||p_command,0));
 payload:=sha256(convert_to(jsonb_build_object('question',p_question,'refs',p_refs,'purpose',p_purpose,'selection',p_selection)::text,'UTF8'));
 SELECT * INTO prior FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF prior.action IS DISTINCT FROM 'market.knowledge.capture' OR prior.actor_user_id IS DISTINCT FROM actor OR prior.payload_digest IS DISTINCT FROM payload THEN RAISE EXCEPTION 'Capture command conflict' USING ERRCODE='40001'; END IF;
  SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=p_command;
  IF r.generation IS DISTINCT FROM (SELECT generation FROM public.principal_lifecycles WHERE user_id=actor) OR r.session_digest IS DISTINCT FROM sha256(convert_to(p_session,'UTF8'))
   OR r.input_digest IS DISTINCT FROM decode(inputs->>'digest','hex') THEN RAISE EXCEPTION 'Capture input or actor changed' USING ERRCODE='42501'; END IF;
  -- The command lock can outlive a previously qualified licence or session.
  -- Recheck within the already frozen footprint before disclosing a durable receipt.
  PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.capture',p_selection);
  RETURN prior.result_ref;
 END IF;
 r.run_id:=p_command;r.actor_user_id:=actor;SELECT generation INTO r.generation FROM public.principal_lifecycles WHERE user_id=actor;
 r.session_digest:=sha256(convert_to(p_session,'UTF8'));r.question:=p_question;r.method_ref:=CASE p_question WHEN 'compare_offers' THEN 'offer_equivalence:1' WHEN 'compare_interest' THEN 'qualified_interest_change:1' WHEN 'record_price' THEN 'qualified_price_observation:1' ELSE 'qualified_geography_observation:1' END;
 r.purpose:=p_purpose;r.material_refs:=p_refs;r.input_digest:=decode(inputs->>'digest','hex');r.input_reads:=inputs->'reads';r.state:='prepared';r.created_at:=clock_timestamp();
 PERFORM builderad_private.start_command(actor,p_session,p_command,'market.knowledge.capture',jsonb_build_object('run',r.run_id),inputs->'proof');
 PERFORM builderad_private.permit('public.market_knowledge_captures','insert',NULL,to_jsonb(r));INSERT INTO public.market_knowledge_captures SELECT(r).*;
 result:=jsonb_build_object('run_ref',r.run_id,'state',r.state,'method_ref',r.method_ref,'versions',jsonb_build_object('capture',1));
 PERFORM builderad_private.record_command(NULL,actor,p_command,'market.knowledge.capture',payload,result);
 PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.capture',p_selection);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_market_knowledge_capture(p_session text, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_knowledge_enroll(uuid, uuid, bigint, text, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_knowledge_enroll(p_command uuid, p_source uuid, p_expected bigint, p_resource text, p_payload jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
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
END $_$;


ALTER FUNCTION public.builderad_v2_market_knowledge_enroll(p_command uuid, p_source uuid, p_expected bigint, p_resource text, p_payload jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_knowledge_input(text, uuid, uuid, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_knowledge_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) RETURNS jsonb
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('run',q->'run','materials',q->'materials') FROM
  (SELECT builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication) q) qualified_input;
$$;


ALTER FUNCTION public.builderad_v2_market_knowledge_input(p_session text, p_run uuid, p_output uuid, p_process jsonb, p_publication jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_knowledge_publish(text, uuid, uuid, uuid, jsonb, jsonb, jsonb, text); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_knowledge_publish(p_session text, p_command uuid, p_run uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb, p_ticket text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE inputs jsonb;prior public.market_knowledge_captures;r public.market_knowledge_captures;t public.market_knowledge_completion_tickets;old_ticket public.market_knowledge_completion_tickets;
 old public.business_command_receipts;payload bytea;result jsonb;ref jsonb;expected_edges jsonb;input_closure jsonb;root_key text;nodes jsonb;edges jsonb;closure bytea;m public.material_lineage_manifests;dep public.material_use_dependencies;edge jsonb;proof jsonb;
BEGIN
 IF p_command IS NULL OR p_run IS NULL OR p_output IS NULL THEN RAISE EXCEPTION 'Typed publication identity required' USING ERRCODE='22023'; END IF;
 inputs:=builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 SELECT * INTO STRICT prior FROM public.market_knowledge_captures WHERE run_id=p_run FOR UPDATE;r:=prior;
 -- The run-row wait can outlive process authority even when publication rights remain current.
 -- The complete K1/K2/K4 footprint is already frozen; requalification cannot expand it.
 inputs:=builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 PERFORM builderad_private.market_operator_authorize(p_session,'market.knowledge.publish',p_publication);
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'output',p_output,'process',p_process,'publication',p_publication)::text,'UTF8'));
 SELECT * INTO old FROM public.business_command_receipts WHERE command_id=p_command;
 IF p_ticket IS NOT NULL OR r.state='prepared' THEN t:=builderad_private.knowledge_completion_ticket(p_ticket,r,p_command,p_output,p_finding,p_process,p_publication); END IF;
 IF old.command_id IS NOT NULL THEN
  IF old.action IS DISTINCT FROM 'market.knowledge.publish' OR old.actor_user_id IS DISTINCT FROM r.actor_user_id OR old.payload_digest IS DISTINCT FROM payload OR r.state<>'published' THEN RAISE EXCEPTION 'Publication command conflict' USING ERRCODE='40001'; END IF;
  PERFORM builderad_private.material_closure(jsonb_build_array(old.result_ref->'artifact_ref'));
  IF t.token_hash IS NOT NULL THEN
   proof:=(inputs->'publication_proof')||jsonb_build_object('processing',inputs->'proof','completion_attestation',to_jsonb(t)-'token_hash');
   PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.knowledge.publish',jsonb_build_object('run',r.run_id,'artifact',old.result_ref->'artifact_ref'),proof);
   PERFORM builderad_private.knowledge_consume_ticket(t);
   PERFORM builderad_private.assert_invocation_current();
   IF t.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Shared replay attestation expired' USING ERRCODE='42501'; END IF;
   PERFORM builderad_private.finish_command();
  END IF;
  RETURN old.result_ref;
 END IF;
 IF r.state<>'prepared' THEN RAISE EXCEPTION 'Capture already completed; original publication command required' USING ERRCODE='40001'; END IF;
 PERFORM builderad_private.market_keys(p_finding,ARRAY['method_ref','status','reason_codes','values','limitations','evidence_refs']);
 IF p_finding->>'method_ref' IS DISTINCT FROM r.method_ref OR p_finding->'evidence_refs' IS DISTINCT FROM r.material_refs OR octet_length(p_finding::text)>65536
 OR NOT(CASE r.method_ref WHEN 'offer_equivalence:1' THEN p_finding->>'status' IN('equivalent','different','unresolved') WHEN 'qualified_interest_change:1' THEN p_finding->>'status' IN('comparable','not_comparable') WHEN 'qualified_price_observation:1' THEN p_finding->>'status' IN('observed','requires_information') WHEN 'qualified_geography_observation:1' THEN p_finding->>'status' IN('observed','requires_information') ELSE false END)
 THEN RAISE EXCEPTION 'Exact validated method output required' USING ERRCODE='22023'; END IF;
 r.continuation_rights:=(inputs#>'{publication_proof,rights}');r.finding:=p_finding;r.completed_at:=clock_timestamp();r.completion_attestation_digest:=t.binding_digest;r.output_scope_id:=p_output;
 r.material_digest:=sha256(convert_to(jsonb_build_object('method',r.method_ref,'purpose',r.purpose,'finding',p_finding,'capture',r.run_id,'inputs',r.input_reads)::text,'UTF8'));
 ref:=jsonb_build_object('namespace','market.knowledge','key',r.run_id,'version',encode(r.material_digest,'hex'));
 SELECT jsonb_agg(v ORDER BY convert_to(v::text,'UTF8')) INTO expected_edges FROM(SELECT DISTINCT value-'field_keys' v FROM jsonb_array_elements(r.input_reads)) x;
 input_closure:=builderad_private.material_closure(r.material_refs);root_key:=encode(builderad_private.lineage_digest('lineage-manifest:1',ref),'hex');
 SELECT jsonb_agg(v ORDER BY k) INTO nodes FROM(
  SELECT encode(builderad_private.lineage_digest('lineage-manifest:1',value->'ref'),'hex') k,jsonb_build_object('ref',value->'ref','kind',value->'kind','material_digest',value->'material_digest','resolver_contract_version',value->'resolver_contract_version') v FROM jsonb_array_elements(input_closure->'materials')
  UNION ALL SELECT root_key,jsonb_build_object('ref',ref,'kind','derived','material_digest',ref->'version','resolver_contract_version',2)
 ) x;
 SELECT jsonb_agg(value ORDER BY convert_to(value::text,'UTF8')) INTO edges FROM jsonb_array_elements((input_closure->'dependencies')||
  (SELECT jsonb_agg(jsonb_build_object('from',root_key,'to',encode(builderad_private.lineage_digest('lineage-manifest:1',value->'input'),'hex'),'edge',value)) FROM jsonb_array_elements(expected_edges)));
 closure:=builderad_private.lineage_digest('lineage-manifest:1',jsonb_build_object('materials',nodes,'dependencies',edges));
 proof:=inputs->'publication_proof';proof:=proof||jsonb_build_object('processing',inputs->'proof','completion_attestation',to_jsonb(t)-'token_hash');
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.knowledge.publish',jsonb_build_object('run',r.run_id,'artifact',ref),proof);
 PERFORM builderad_private.knowledge_consume_ticket(t);
 m.manifest_id:=uuidv7();m.material_namespace:='market.knowledge';m.material_key:=r.run_id::text;m.material_version:=ref->>'version';m.kind:='derived';m.producer_receipt_ref:='market_knowledge:'||r.run_id;
 m.resolver_contract_version:=2;m.material_digest:=r.material_digest;m.direct_dependency_count:=jsonb_array_length(expected_edges);m.direct_dependency_digest:=builderad_private.lineage_digest('dependency-set:1',expected_edges);m.closure_digest:=closure;m.state:='sealed';m.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.material_lineage_manifests','insert',NULL,to_jsonb(m));INSERT INTO public.material_lineage_manifests SELECT(m).*;
 FOR edge IN SELECT value FROM jsonb_array_elements(expected_edges) LOOP
  dep.dependency_id:=uuidv7();dep.material_namespace:=m.material_namespace;dep.material_key:=m.material_key;dep.material_version:=m.material_version;
  dep.input_namespace:=edge#>>'{input,namespace}';dep.input_key:=edge#>>'{input,key}';dep.input_version:=edge#>>'{input,version}';dep.data_use_scope_id:=(edge->>'data_use_scope_id')::uuid;
  dep.policy_id:=edge->>'policy_id';dep.policy_version:=(edge->>'policy_version')::bigint;dep.lineage_digest:=closure;
  PERFORM builderad_private.permit('public.material_use_dependencies','insert',NULL,to_jsonb(dep));INSERT INTO public.material_use_dependencies SELECT(dep).*;
 END LOOP;
 r.state:='published';r.publication_manifest_id:=m.manifest_id;
 PERFORM builderad_private.permit('public.market_knowledge_captures','update',to_jsonb(prior),to_jsonb(r));
 UPDATE public.market_knowledge_captures SET state=r.state,finding=r.finding,continuation_rights=r.continuation_rights,completed_at=r.completed_at,completion_attestation_digest=r.completion_attestation_digest,
  output_scope_id=r.output_scope_id,publication_manifest_id=r.publication_manifest_id,material_digest=r.material_digest WHERE run_id=r.run_id;
 result:=jsonb_build_object('artifact_ref',ref,'manifest_id',m.manifest_id,'state','published','versions',jsonb_build_object('publication',1));
 PERFORM builderad_private.record_command(NULL,r.actor_user_id,p_command,'market.knowledge.publish',payload,result);
 PERFORM builderad_private.knowledge_publication_input(p_session,p_run,p_output,p_process,p_publication);
 PERFORM builderad_private.material_closure(jsonb_build_array(ref));
 IF t.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Trusted shared execution expired before release' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_market_knowledge_publish(p_session text, p_command uuid, p_run uuid, p_output uuid, p_finding jsonb, p_process jsonb, p_publication jsonb, p_ticket text) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_knowledge_withdraw(uuid, uuid, bigint, bigint, text); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_knowledge_withdraw(p_command uuid, p_scope uuid, p_grant bigint, p_expected bigint, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
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


ALTER FUNCTION public.builderad_v2_market_knowledge_withdraw(p_command uuid, p_scope uuid, p_grant bigint, p_expected bigint, p_reason text) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_processing_complete(text, uuid, uuid, uuid, text, jsonb, bigint, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) RETURNS jsonb
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT public.builderad_v2_market_processing_complete(p_session,p_business,p_command,p_run,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection,NULL::text);
$$;


ALTER FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_processing_complete(text, uuid, uuid, uuid, text, jsonb, bigint, jsonb, jsonb, text); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb, p_ticket text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $_$
DECLARE q jsonb;r public.market_processing_runs;prior public.market_processing_runs;result jsonb;payload bytea;known jsonb;state text;finding jsonb;reason text;v jsonb;ticket public.market_processing_completion_tickets;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('complete','cancel') OR p_elapsed IS NULL OR p_elapsed NOT BETWEEN 0 AND 30000000 THEN RAISE EXCEPTION 'Bounded completion required' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.market_keys(p_outcome,ARRAY['state','finding','reason_code']);
 state:=p_outcome->>'state';finding:=nullif(p_outcome->'finding','null');reason:=p_outcome->>'reason_code';
 IF state IS NULL OR state NOT IN('validated','abstained','failed','cancelled','unavailable')
 OR (state IN('validated','abstained'))<>(finding IS NOT NULL) OR (p_effect='cancel' AND state<>'cancelled')
 OR(state IN('failed','cancelled','unavailable') AND coalesce(reason,'') !~ '^[A-Z][A-Z0-9_]{1,100}$')
 OR octet_length(p_outcome::text)>65536 THEN RAISE EXCEPTION 'Typed bounded processing outcome required' USING ERRCODE='22023'; END IF;
 q:=builderad_private.processing_input(p_session,p_business,p_run,p_selection,p_context_selection);
 SELECT * INTO STRICT prior FROM public.market_processing_runs WHERE run_id=p_run FOR UPDATE;r:=prior;
 -- A concurrent completion may have held the run row until selected rights expired.
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 payload:=sha256(convert_to(jsonb_build_object('run',p_run,'effect',p_effect)::text,'UTF8'));
 known:=builderad_private.command_replay(r.business_id,r.actor_user_id,p_command,'market.processing.'||p_effect,payload);
 IF p_ticket IS NOT NULL OR r.state='prepared' THEN
  ticket:=builderad_private.processing_completion_ticket(p_ticket,p_session,p_business,r,p_command,p_effect,p_outcome,p_elapsed,p_selection,p_context_selection);
 END IF;
 IF ticket.token_hash IS NOT NULL THEN q:=jsonb_set(q,'{proof}',(q->'proof')||jsonb_build_object('completion_attestation',to_jsonb(ticket)-'token_hash')); END IF;
 IF known IS NOT NULL THEN
  IF ticket.token_hash IS NOT NULL THEN
   PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.'||p_effect,jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
   PERFORM builderad_private.processing_consume_completion_ticket(ticket);
   PERFORM builderad_private.assert_invocation_current();
   PERFORM builderad_private.finish_command();
  END IF;
  RETURN builderad_private.processing_surface(r,p_business);
 END IF;
 IF r.state='prepared' THEN
  IF p_effect='complete' AND state='cancelled' THEN RAISE EXCEPTION 'Cancellation requires its explicit effect' USING ERRCODE='22023'; END IF;
  IF finding IS NOT NULL THEN
   PERFORM builderad_private.market_keys(finding,ARRAY['method_ref','status','reason_codes','values','limitations','evidence_refs']);
   IF finding->>'method_ref' IS DISTINCT FROM r.method_ref OR finding->'evidence_refs' IS DISTINCT FROM r.material_refs
   OR jsonb_typeof(finding->'values') IS DISTINCT FROM 'object' OR jsonb_typeof(finding->'reason_codes') IS DISTINCT FROM 'array'
   OR jsonb_typeof(finding->'limitations') IS DISTINCT FROM 'array'
   OR EXISTS(SELECT 1 FROM jsonb_array_elements((finding->'reason_codes')||(finding->'limitations')) x WHERE jsonb_typeof(x)<>'string' OR x#>>'{}' !~ '^[A-Z][A-Z0-9_]{1,100}$')
   OR NOT(CASE r.method_ref WHEN 'offer_equivalence:1' THEN finding->>'status' IN('equivalent','different','unresolved')
    WHEN 'qualified_interest_change:1' THEN finding->>'status' IN('comparable','not_comparable')
    WHEN 'business_feasibility:1' THEN finding->>'status' IN('within_declared_market','outside_declared_market','requires_information') ELSE false END)
   THEN RAISE EXCEPTION 'Exact typed method handoff required' USING ERRCODE='22023'; END IF;
   IF finding->>'status'='comparable' THEN
    PERFORM builderad_private.market_keys(finding->'values',ARRAY['delta','percent_change']);
    IF jsonb_typeof(finding#>'{values,delta}') IS DISTINCT FROM 'number'
    OR(finding#>'{values,percent_change}'<>'null'::jsonb AND jsonb_typeof(finding#>'{values,percent_change}') IS DISTINCT FROM 'number') THEN RAISE EXCEPTION 'Typed numeric handoff required' USING ERRCODE='22023'; END IF;
   ELSE PERFORM builderad_private.market_keys(finding->'values','{}'); END IF;
   IF(state='abstained') IS DISTINCT FROM(finding->>'status' IN('unresolved','not_comparable','requires_information')) THEN RAISE EXCEPTION 'Finding and terminal state must agree' USING ERRCODE='22023'; END IF;
  END IF;
  r.state:=state;r.finding:=finding;r.reason_code:=reason;r.elapsed_microseconds:=p_elapsed;r.completion_attestation_digest:=ticket.binding_digest;
  r.execution_count:=CASE WHEN state IN('cancelled','unavailable') THEN 0 ELSE 1 END;r.completed_at:=clock_timestamp();
 END IF;
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.'||p_effect,jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
 IF ticket.token_hash IS NOT NULL THEN PERFORM builderad_private.processing_consume_completion_ticket(ticket); END IF;
 IF prior.state='prepared' THEN
  PERFORM builderad_private.permit('public.market_processing_runs','update',to_jsonb(prior),to_jsonb(r));
  UPDATE public.market_processing_runs SET state=r.state,finding=r.finding,reason_code=r.reason_code,elapsed_microseconds=r.elapsed_microseconds,
   execution_count=r.execution_count,completed_at=r.completed_at,completion_attestation_digest=r.completion_attestation_digest WHERE run_id=r.run_id;
 END IF;
 result:=builderad_private.processing_surface(r,p_business);
 -- Receipts never retain derived/private result fields; delivery requalifies the run.
 PERFORM builderad_private.record_command(r.business_id,r.actor_user_id,p_command,'market.processing.'||p_effect,payload,result-'finding');
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 IF ticket.token_hash IS NOT NULL AND ticket.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Completion attestation expired before final commit' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $_$;


ALTER FUNCTION public.builderad_v2_market_processing_complete(p_session text, p_business uuid, p_command uuid, p_run uuid, p_effect text, p_outcome jsonb, p_elapsed bigint, p_selection jsonb, p_context_selection jsonb, p_ticket text) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_processing_input(text, uuid, uuid, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_processing_input(p_session text, p_business uuid, p_run uuid, p_selection jsonb, p_context_selection jsonb) RETURNS jsonb
    LANGUAGE sql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
 SELECT jsonb_build_object('run',q->'run','materials',q->'materials','business_context',q->'business_context') FROM
  (SELECT builderad_private.processing_input(p_session,p_business,p_run,p_selection,p_context_selection) q) x;
$$;


ALTER FUNCTION public.builderad_v2_market_processing_input(p_session text, p_business uuid, p_run uuid, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: builderad_v2_market_processing_prepare(text, uuid, uuid, text, jsonb, text, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_scope_executor
--

CREATE FUNCTION public.builderad_v2_market_processing_prepare(p_session text, p_business uuid, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb, p_context_selection jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'builderad_private', 'pg_temp'
    AS $$
DECLARE q jsonb;r public.market_processing_runs;payload bytea;known jsonb;result jsonb;method text;
BEGIN
 method:=CASE p_question WHEN 'compare_offers' THEN 'offer_equivalence:1' WHEN 'compare_interest' THEN 'qualified_interest_change:1' WHEN 'assess_business_feasibility' THEN 'business_feasibility:1' END;
 IF p_command IS NULL OR method IS NULL OR (p_question='assess_business_feasibility')<>(p_context_selection IS NOT NULL)
 OR jsonb_array_length(p_refs)<>(CASE WHEN p_question='assess_business_feasibility' THEN 1 ELSE 2 END) THEN RAISE EXCEPTION 'Exact question and context contract required' USING ERRCODE='22023'; END IF;
 q:=builderad_private.processing_authorize(p_session,p_business,p_refs,p_purpose,p_selection,p_context_selection);
 payload:=sha256(convert_to(jsonb_build_object('question',p_question,'method',method,'refs',p_refs,'purpose',p_purpose,'selection',p_selection,'context_selection',p_context_selection)::text,'UTF8'));
 PERFORM pg_advisory_xact_lock(hashtextextended('builderad.processing.'||p_command::text,0));
 known:=builderad_private.command_replay((q->>'business_id')::bigint,(q->>'actor')::bigint,p_command,'market.processing.prepare',payload);
 SELECT * INTO r FROM public.market_processing_runs WHERE run_id=p_command;
 IF known IS NOT NULL THEN
  PERFORM builderad_private.require_material_use((q->>'actor')::bigint,(q->>'business_id')::bigint,p_session,'process',q->'plan',p_selection);
  IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business((q->>'business_id')::bigint,(q->>'actor')::bigint,'business.context.read',p_context_selection,p_session); END IF;
  IF r.input_digest<>decode(q->>'input_digest','hex') OR r.session_digest<>sha256(convert_to(p_session,'UTF8')) THEN RAISE EXCEPTION 'Captured input changed' USING ERRCODE='40001'; END IF;
  RETURN known;
 END IF;
 r.run_id:=p_command;r.business_id:=(q->>'business_id')::bigint;r.actor_user_id:=(q->>'actor')::bigint;
 SELECT generation INTO r.generation FROM public.principal_lifecycles WHERE user_id=r.actor_user_id;
 r.session_digest:=sha256(convert_to(p_session,'UTF8'));r.question:=p_question;r.method_ref:=method;r.purpose:=p_purpose;r.material_refs:=p_refs;
 r.request_digest:=payload;r.selection_digest:=sha256(convert_to(jsonb_build_array(p_selection,p_context_selection)::text,'UTF8'));
 r.input_digest:=decode(q->>'input_digest','hex');r.dependency_digest:=decode(q#>>'{plan,closure,dependency_digest}','hex');
 r.state:='prepared';r.finding:=NULL;r.reason_code:=NULL;r.elapsed_microseconds:=0;r.execution_count:=0;r.prepared_at:=clock_timestamp();r.completed_at:=NULL;
 PERFORM builderad_private.start_command(r.actor_user_id,p_session,p_command,'market.processing.prepare',jsonb_build_object('business_id',r.business_id,'run_id',r.run_id),q->'proof');
 PERFORM builderad_private.permit('public.market_processing_runs','insert',NULL,to_jsonb(r));INSERT INTO public.market_processing_runs SELECT(r).*;
 result:=builderad_private.processing_surface(r,p_business);
 PERFORM builderad_private.record_command(r.business_id,r.actor_user_id,p_command,'market.processing.prepare',payload,result);
 PERFORM builderad_private.require_material_use(r.actor_user_id,r.business_id,p_session,'process',q->'plan',p_selection);
 IF p_context_selection IS NOT NULL THEN PERFORM builderad_private.require_business(r.business_id,r.actor_user_id,'business.context.read',p_context_selection,p_session); END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;


ALTER FUNCTION public.builderad_v2_market_processing_prepare(p_session text, p_business uuid, p_command uuid, p_question text, p_refs jsonb, p_purpose text, p_selection jsonb, p_context_selection jsonb) OWNER TO builderad_scope_executor;


-- Name: FUNCTION market_staff_eligible(p_actor bigint); Type: ACL; Schema: builderad_private; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION builderad_private.market_staff_eligible(p_actor bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION builderad_private.market_staff_eligible(p_actor bigint) TO builderad_scope_executor;



-- Name: FUNCTION builderad_activate_principal_context(p_ticket text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_activate_principal_context(p_ticket text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_activate_principal_context(p_ticket text) TO builderad_app;



-- Name: FUNCTION builderad_advertiser_goal_event_immutable(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_advertiser_goal_event_immutable() FROM PUBLIC;



-- Name: FUNCTION builderad_advertiser_goals_normalize(v jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_advertiser_goals_normalize(v jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_advertiser_goals_normalize(v jsonb) TO builderad_scope_executor;



-- Name: FUNCTION builderad_business_admin(p_session text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_business_admin(p_session text) FROM PUBLIC;



-- Name: FUNCTION builderad_business_config(p_session text, p_kind text, p_type bigint, p_input jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_business_config(p_session text, p_kind text, p_type bigint, p_input jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_business_config(p_session text, p_kind text, p_type bigint, p_input jsonb) TO builderad_app;



-- Name: FUNCTION builderad_business_prior_semantics(p_input jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_business_prior_semantics(p_input jsonb) FROM PUBLIC;



-- Name: FUNCTION builderad_commercial_preparation_consumed(target uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_commercial_preparation_consumed(target uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_commercial_preparation_consumed(target uuid) TO builderad_authority;



-- Name: FUNCTION builderad_configure_commercial_market(p_command uuid, p_market uuid, p_label text, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_configure_commercial_market(p_command uuid, p_market uuid, p_label text, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_configure_commercial_market(p_command uuid, p_market uuid, p_label text, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_create_market_configuration(p_command uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_create_market_configuration(p_command uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_create_market_configuration(p_command uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_current_intent_event_immutable(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_event_immutable() FROM PUBLIC;



-- Name: FUNCTION builderad_current_intent_normalize(p_value jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_normalize(p_value jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_current_intent_normalize(p_value jsonb) TO builderad_scope_executor;



-- Name: FUNCTION builderad_current_intent_timezone_name_allowed(p_zone text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_timezone_name_allowed(p_zone text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_name_allowed(p_zone text) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_name_allowed(p_zone text) TO builderad_scope_executor;



-- Name: FUNCTION builderad_current_intent_timezone_names(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_timezone_names() FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_names() TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_names() TO builderad_scope_executor;



-- Name: FUNCTION builderad_current_intent_timezone_supported(p_zone text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_timezone_supported(p_zone text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_supported(p_zone text) TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_current_intent_timezone_supported(p_zone text) TO builderad_scope_executor;



-- Name: FUNCTION builderad_current_intent_validate_horizon(p_horizon jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_current_intent_validate_horizon(p_horizon jsonb) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_current_intent_validate_horizon(p_horizon jsonb) TO builderad_scope_executor;



-- Name: FUNCTION builderad_customer_directory_witness(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_customer_directory_witness() FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_customer_directory_witness() TO builderad_app;



-- Name: FUNCTION builderad_customer_owner_continuity(p_user bigint, p_generation bigint); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_customer_owner_continuity(p_user bigint, p_generation bigint) FROM PUBLIC;



-- Name: FUNCTION builderad_founder_configure_country_market(p_command uuid, p_market uuid, p_label text, p_reason text, p_country text, p_currency text, p_configuration_command uuid, p_configuration uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_founder_configure_country_market(p_command uuid, p_market uuid, p_label text, p_reason text, p_country text, p_currency text, p_configuration_command uuid, p_configuration uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_founder_configure_country_market(p_command uuid, p_market uuid, p_label text, p_reason text, p_country text, p_currency text, p_configuration_command uuid, p_configuration uuid) TO builderad_app;



-- Name: FUNCTION builderad_founder_configure_market(p_command uuid, p_market uuid, p_label text, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_founder_configure_market(p_command uuid, p_market uuid, p_label text, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_founder_configure_market(p_command uuid, p_market uuid, p_label text, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_guard_identity_transition(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_guard_identity_transition() FROM PUBLIC;



-- Name: FUNCTION builderad_record_commercial_catalog(p_command uuid, p_market uuid, p_reference uuid, p_catalog uuid, p_version text, p_approval text, p_source text, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_record_commercial_catalog(p_command uuid, p_market uuid, p_reference uuid, p_catalog uuid, p_version text, p_approval text, p_source text, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_record_commercial_catalog(p_command uuid, p_market uuid, p_reference uuid, p_catalog uuid, p_version text, p_approval text, p_source text, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_sa01_assignability_complete(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_assignability_complete() FROM PUBLIC;



-- Name: FUNCTION builderad_sa01_require_committed_catalog(p_reference uuid, p_market uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_require_committed_catalog(p_reference uuid, p_market uuid) FROM PUBLIC;



-- Name: FUNCTION builderad_set_market_assignability(p_command uuid, p_market uuid, p_configuration uuid, p_expected_revision bigint, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_set_market_assignability(p_command uuid, p_market uuid, p_configuration uuid, p_expected_revision bigint, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_set_market_assignability(p_command uuid, p_market uuid, p_configuration uuid, p_expected_revision bigint, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_signed_context(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_signed_context() FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_signed_context() TO builderad_app;
GRANT ALL ON FUNCTION public.builderad_signed_context() TO builderad_scope_executor;
