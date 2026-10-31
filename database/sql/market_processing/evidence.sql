-- Preserve v1 exactly; v2 explicitly adds the semantics its comparisons require.
ALTER FUNCTION builderad_private.market_validate_evidence(jsonb) RENAME TO market_validate_evidence_v1;
CREATE FUNCTION builderad_private.market_validate_evidence(p_payload jsonb) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path=pg_catalog,builderad_private,pg_temp AS $$
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
END $$;
