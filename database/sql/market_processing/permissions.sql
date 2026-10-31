SET LOCAL ROLE builderad_scope_executor;
DO $acl$
DECLARE f record;
BEGIN
 FOR f IN SELECT p.oid::regprocedure signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE(n.nspname='builderad_private' AND p.proname IN('market_validate_evidence','market_validate_evidence_v1','processing_authorize','processing_surface','processing_input'))
 OR(n.nspname='public' AND p.proname LIKE 'builderad_v2_market_processing_%') LOOP
  EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC,builderad_app,builderad_authority',f.signature);
 END LOOP;
END $acl$;
GRANT EXECUTE ON FUNCTION builderad_v2_market_processing_prepare(text,uuid,uuid,text,jsonb,text,jsonb,jsonb),
 builderad_v2_market_processing_input(text,uuid,uuid,jsonb,jsonb),
 builderad_v2_market_processing_complete(text,uuid,uuid,uuid,text,jsonb,bigint,jsonb,jsonb) TO builderad_app;
RESET ROLE;
