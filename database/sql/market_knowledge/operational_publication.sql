-- Pure operational classification, not a material reader. Only validated method/status/reason
-- combinations map to a specific category; original values/text/references are never returned.
CREATE FUNCTION builderad_private.market_knowledge_operational_outcome(p_method text,p_finding jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,pg_temp AS $$
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
REVOKE ALL ON FUNCTION builderad_private.market_knowledge_operational_outcome(text,jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE OR REPLACE FUNCTION builderad_v2_market_operations_read(p_session text,p_cursor uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE result jsonb;after_ref uuid;actor bigint;item record;summary jsonb:='[]';validity text;artifact jsonb;outcome jsonb;
BEGIN
 actor:=builderad_private.market_operator(p_session,'market.knowledge.observe');
 -- The entitlement covers only these operational source-custody fields. It is not data-use, processing or publication authority.
 SELECT coalesce(jsonb_agg(jsonb_build_object('source_ref',source_id,'custody_revision',revision,'state',status,
  'rights_window',CASE WHEN valid_from>clock_timestamp() THEN 'not_started' WHEN valid_until<=clock_timestamp() THEN 'expired' ELSE 'current' END,
  'valid_until',valid_until) ORDER BY source_id),'[]') INTO result FROM(
  SELECT c.source_id,c.revision,CASE WHEN s.status='REVOKED' THEN 'revoked' ELSE c.status END AS status,c.valid_from,c.valid_until
  FROM public.market_source_custody c JOIN public.source_registry s ON s.id=c.source_id
  WHERE s.custody_kind='platform_market' AND s.workspace_id IS NULL AND(p_cursor IS NULL OR c.source_id>p_cursor) ORDER BY c.source_id LIMIT 50
 ) allowed_metadata;
 IF jsonb_array_length(result)=50 THEN
  after_ref:=(result->49->>'source_ref')::uuid;
  IF NOT EXISTS(SELECT 1 FROM public.market_source_custody c JOIN public.source_registry s ON s.id=c.source_id WHERE s.custody_kind='platform_market' AND s.workspace_id IS NULL AND c.source_id>after_ref) THEN after_ref:=NULL;END IF;
 END IF;
 -- Bounded operational window over the shared owner only; private producer/runs never participate.
 FOR item IN SELECT run_id,method_ref,state,purpose,material_digest FROM public.market_knowledge_captures
  WHERE state IN('prepared','published','retention_removed') ORDER BY created_at DESC,run_id DESC LIMIT 100 LOOP
  validity:=CASE item.state WHEN 'prepared' THEN 'not_published' WHEN 'retention_removed' THEN 'unavailable' ELSE 'unknown' END;
  outcome:=CASE item.state WHEN 'prepared' THEN jsonb_build_object('processing_outcome','unknown','reason_category','none')
   WHEN 'retention_removed' THEN jsonb_build_object('processing_outcome','unavailable','reason_category','unavailable')
   ELSE jsonb_build_object('processing_outcome','unknown','reason_category','unknown') END;
  IF item.state='published' THEN
   artifact:=jsonb_build_object('namespace','market.knowledge','key',item.run_id,'version',encode(item.material_digest,'hex'));
   BEGIN
    PERFORM builderad_private.registered_output_constraints(artifact,'display',item.purpose);
    validity:=builderad_private.knowledge_validity(artifact)->>'status';
    -- Do not load a finding until the existing source/output continuation constraints qualify it.
    IF validity IN('current','review_required') THEN
     SELECT builderad_private.market_knowledge_operational_outcome(item.method_ref,c.finding) INTO outcome
      FROM public.market_knowledge_captures c WHERE c.run_id=item.run_id;
    END IF;
   EXCEPTION WHEN insufficient_privilege THEN validity:='unavailable';
    outcome:=jsonb_build_object('processing_outcome','unavailable','reason_category','unavailable'); END;
  END IF;
  summary:=summary||jsonb_build_array(jsonb_build_object('method_ref',item.method_ref,'publication_state',item.state,'validity',validity)||outcome);
 END LOOP;
 SELECT coalesce(jsonb_agg(jsonb_build_object('method_ref',method_ref,'publication_state',publication_state,'validity',validity_state,'processing_outcome',processing_outcome,'reason_category',reason_category,'count',n)
  ORDER BY method_ref,publication_state,validity_state,processing_outcome,reason_category),'[]') INTO summary FROM(
  SELECT value->>'method_ref' method_ref,value->>'publication_state' publication_state,value->>'validity' validity_state,value->>'processing_outcome' processing_outcome,value->>'reason_category' reason_category,count(*) n
  FROM jsonb_array_elements(summary) GROUP BY value->>'method_ref',value->>'publication_state',value->>'validity',value->>'processing_outcome',value->>'reason_category'
 ) grouped_shared_operations;
 PERFORM builderad_private.market_operator(p_session,'market.knowledge.observe',false);
 RETURN jsonb_build_object('sources',result,'next_cursor',after_ref,'shared_knowledge',summary,'shared_knowledge_window',100);
END $$;
REVOKE ALL ON FUNCTION builderad_v2_market_operations_read(text,uuid) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_market_operations_read(text,uuid) TO builderad_app;
