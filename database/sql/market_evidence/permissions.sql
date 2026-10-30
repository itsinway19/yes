SET LOCAL ROLE builderad_scope_executor;
GRANT EXECUTE ON FUNCTION builderad_private.market_scope_identity(text,uuid,uuid,text,text,text,jsonb,bytea) TO builderad_owner;
RESET ROLE;
ALTER TABLE data_use_scopes ADD CONSTRAINT scope_canonical_identity CHECK(
 builderad_private.market_scope_identity(rights_domain,boundary_id,source_id,resource,operation,purpose,selection,scope_digest));

GRANT INSERT ON public.source_registry TO builderad_scope_executor;
CREATE POLICY market_source_insert ON public.source_registry FOR INSERT TO builderad_scope_executor WITH CHECK(
 custody_kind='platform_market' AND workspace_id IS NULL AND builderad_private.effect_allowed('public.source_registry','insert',to_jsonb(source_registry.*)));
-- Legacy runtime source paths may never treat platform custody as Workspace data.
CREATE POLICY market_source_runtime_closed ON public.source_registry AS RESTRICTIVE TO builderad_app,builderad_authority
 USING(custody_kind='workspace_native') WITH CHECK(custody_kind='workspace_native');

SET LOCAL ROLE builderad_scope_executor;
DO $acl$
DECLARE f record;
BEGIN
 FOR f IN SELECT p.oid::regprocedure signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE (n.nspname='builderad_private' AND p.proname LIKE 'market_%') OR(n.nspname='public' AND p.proname IN('builderad_v2_market_admit','builderad_v2_market_evidence_write')) LOOP
  EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC,builderad_app,builderad_authority',f.signature);
 END LOOP;
END $acl$;
GRANT EXECUTE ON FUNCTION public.builderad_v2_market_admit(uuid,text,uuid,bigint,jsonb) TO builderad_owner;
GRANT EXECUTE ON FUNCTION public.builderad_v2_market_evidence_write(text,uuid,uuid,uuid,bigint,jsonb,jsonb) TO builderad_app;
REVOKE EXECUTE ON FUNCTION builderad_private.market_scope_identity(text,uuid,uuid,text,text,text,jsonb,bytea) FROM builderad_owner;
RESET ROLE;
