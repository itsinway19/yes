-- Bind the PHP wire's declared Business to the same canonical Source that the
-- native effect guard qualifies. Numeric IDs and NULL metadata confer no right.
CREATE FUNCTION public.builderad_provider_custody_scope(p_workspace bigint,p_business bigint,p_session text,p_source uuid,p_provider text,p_resource text,p_operation text,p_purpose text,p_action text) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=public,pg_temp AS $$
BEGIN
 IF(p_workspace IS NULL AND(p_business IS NULL OR p_business<1)) OR(p_workspace IS NOT NULL AND p_business IS NOT NULL)
 THEN RAISE EXCEPTION 'One typed native custody context required' USING ERRCODE='42501'; END IF;
 IF p_action='source.read' THEN
  PERFORM public.builderad_source_require(p_action,p_source,p_workspace,p_resource,p_operation,p_purpose,p_session);
 ELSE PERFORM public.builderad_provider_require(p_workspace,p_session,p_source,p_provider,p_resource,p_operation,p_purpose,p_action); END IF;
 IF NOT EXISTS(SELECT 1 FROM public.source_registry s WHERE s.id=p_source AND s.family=p_provider AND s.declared_external_id=p_resource
  AND s.workspace_id IS NOT DISTINCT FROM p_workspace
  AND((p_business IS NULL AND s.custody_kind='workspace_native' AND s.business_id IS NULL)
   OR(p_workspace IS NULL AND s.custody_kind='business_native' AND s.business_id=p_business)))
 THEN RAISE EXCEPTION 'Declared provider custody does not match its exact Source' USING ERRCODE='42501'; END IF;
END $$;
REVOKE ALL ON FUNCTION public.builderad_provider_custody_scope(bigint,bigint,text,uuid,text,text,text,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_provider_custody_scope(bigint,bigint,text,uuid,text,text,text,text,text) TO builderad_app;
