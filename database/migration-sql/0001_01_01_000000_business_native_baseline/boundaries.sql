-- Runtime reads keep the exact Source capability and current signed principal.
CREATE FUNCTION public.builderad_source_capability_visible(p_source uuid,p_resource text,p_operation text,p_purpose text)
RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
    SELECT coalesce((SELECT public.builderad_source_has('source.read',s.id,s.business_id,p_resource,p_operation,p_purpose)
      FROM public.source_registry s WHERE s.id=p_source AND s.custody_kind='business_native'),false)
$$;
REVOKE ALL ON FUNCTION public.builderad_source_capability_visible(uuid,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_source_capability_visible(uuid,text,text,text) TO builderad_app;
CREATE POLICY source_capability_read ON public.source_capabilities FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.builderad_signed_context() c WHERE c.phase='principal')
    AND public.builderad_source_capability_visible(source_id,resource,operation,purpose)
);
CREATE POLICY source_identity_read ON public.source_registry FOR SELECT TO builderad_app USING (
    custody_kind='business_native' AND EXISTS (SELECT 1 FROM public.source_capabilities c WHERE c.source_id=source_registry.id)
);
CREATE POLICY business_native_source_insert ON public.source_registry FOR INSERT TO builderad_scope_executor WITH CHECK (
    custody_kind='business_native' AND business_id IS NOT NULL
    AND builderad_private.effect_allowed('public.source_registry','insert',to_jsonb(source_registry.*))
);
CREATE POLICY market_source_insert ON public.source_registry FOR INSERT TO builderad_scope_executor WITH CHECK (
    custody_kind='platform_market' AND business_id IS NULL
    AND builderad_private.effect_allowed('public.source_registry','insert',to_jsonb(source_registry.*))
);
CREATE POLICY market_source_runtime_closed ON public.source_registry AS RESTRICTIVE TO builderad_app,builderad_authority
    USING(custody_kind='business_native') WITH CHECK(custody_kind='business_native');
CREATE POLICY source_receipt_read ON public.source_command_receipts FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.source_registry s WHERE s.id=source_command_receipts.source_id)
    AND actor_user_id=(SELECT actor_user_id FROM public.builderad_signed_context())
);
CREATE POLICY source_version_read ON public.source_versions FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.source_command_receipts r WHERE r.source_id=source_versions.source_id AND r.command_id=source_versions.command_id)
);
CREATE POLICY source_obligation_read ON public.source_audit_obligations FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.source_command_receipts r WHERE r.source_id=source_audit_obligations.source_id AND r.command_id=source_audit_obligations.command_id)
);
CREATE POLICY source_delivery_read ON public.source_audit_delivery FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.source_audit_obligations o WHERE o.id=source_audit_delivery.obligation_id)
);
CREATE POLICY business_source_delivery_update ON public.source_audit_delivery FOR UPDATE TO builderad_scope_executor USING (true)
    WITH CHECK (builderad_private.effect_allowed('public.source_audit_delivery','update',to_jsonb(source_audit_delivery.*)));
CREATE TRIGGER zz_business_source_delivery_update BEFORE UPDATE ON public.source_audit_delivery FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();

CREATE POLICY grant_executor_insert ON public.security_action_grants FOR INSERT TO builderad_scope_executor WITH CHECK (
    (scope_kind IN('business','client_data','data_use') OR (scope_kind='source' AND EXISTS (
        SELECT 1 FROM public.resource_right_fences f WHERE f.source_id=source_target_id AND f.kind='source' AND f.adoption_state='v2_adopted')))
    AND builderad_private.effect_allowed('public.security_action_grants','insert',to_jsonb(security_action_grants.*))
);
CREATE POLICY grant_runtime_read ON public.security_action_grants FOR SELECT TO builderad_app USING (
    scope_kind IN('platform','commercial') AND EXISTS (
        SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
        WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation)
);
CREATE POLICY grant_runtime_lock ON public.security_action_grants FOR UPDATE TO builderad_app USING (
    scope_kind IN('platform','commercial') AND EXISTS (
        SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
        WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation)
) WITH CHECK (
    scope_kind IN('platform','commercial') AND EXISTS (
        SELECT 1 FROM public.builderad_signed_context() c JOIN public.principal_lifecycles l ON l.user_id=c.actor_user_id
        WHERE c.actor_user_id=subject_user_id AND l.status='current' AND l.generation=subject_generation)
);
CREATE POLICY grant_authority_no_native_source ON public.security_action_grants AS RESTRICTIVE TO builderad_authority
    USING (scope_kind<>'source') WITH CHECK (scope_kind<>'source');

REVOKE INSERT,UPDATE,DELETE ON public.service_business_scopes,public.service_action_grants FROM builderad_app;
REVOKE INSERT,UPDATE,DELETE ON public.service_business_scopes FROM builderad_authority;

CREATE POLICY provider_access_read ON public.provider_access FOR SELECT TO builderad_app USING (
    public.builderad_source_capability_visible(source_id,resource,operation,purpose)
);
CREATE POLICY provider_receipt_read ON public.provider_access_receipts FOR SELECT TO builderad_app USING (
    EXISTS (SELECT 1 FROM public.provider_access a WHERE a.source_id=provider_access_receipts.source_id
        AND a.provider=provider_access_receipts.provider AND a.resource=provider_access_receipts.resource
        AND a.operation=provider_access_receipts.operation AND a.purpose=provider_access_receipts.purpose)
);
CREATE POLICY observation_stream_read ON public.provider_observation_streams FOR SELECT TO builderad_app USING (
    public.builderad_source_capability_visible(source_id,resource,operation,purpose)
);
CREATE POLICY snapchat_renewal_read ON public.snapchat_renewal_failures FOR SELECT TO builderad_app USING (
    public.builderad_source_capability_visible(source_id,resource,operation,purpose)
);

REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor;
