-- Connection custody remains closed to market originals. Source RLS still
-- requires its exact native action; Business read is never a permissive policy.
DROP POLICY market_source_runtime_closed ON public.source_registry;
CREATE POLICY market_source_runtime_closed ON public.source_registry AS RESTRICTIVE TO builderad_app,builderad_authority
 USING(custody_kind IN('workspace_native','business_native')) WITH CHECK(custody_kind IN('workspace_native','business_native'));
DROP POLICY source_identity_read ON public.source_registry;
CREATE POLICY source_identity_read ON public.source_registry FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM public.source_capabilities c WHERE c.source_id=source_registry.id AND c.workspace_id IS NOT DISTINCT FROM source_registry.workspace_id));
DROP POLICY source_version_read ON public.source_versions;
CREATE POLICY source_version_read ON public.source_versions FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM public.source_command_receipts r WHERE r.source_id=source_versions.source_id AND r.command_id=source_versions.command_id
  AND r.workspace_id IS NOT DISTINCT FROM source_versions.workspace_id));
DROP POLICY source_obligation_read ON public.source_audit_obligations;
CREATE POLICY source_obligation_read ON public.source_audit_obligations FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM public.source_command_receipts r WHERE r.source_id=source_audit_obligations.source_id AND r.command_id=source_audit_obligations.command_id
  AND r.workspace_id IS NOT DISTINCT FROM source_audit_obligations.workspace_id));
DROP POLICY source_delivery_read ON public.source_audit_delivery;
CREATE POLICY source_delivery_read ON public.source_audit_delivery FOR SELECT TO builderad_app USING(
 EXISTS(SELECT 1 FROM public.source_audit_obligations o WHERE o.id=source_audit_delivery.obligation_id AND o.workspace_id IS NOT DISTINCT FROM source_audit_delivery.workspace_id));
CREATE POLICY business_native_source_insert ON public.source_registry FOR INSERT TO builderad_scope_executor WITH CHECK(
 custody_kind='business_native' AND workspace_id IS NULL AND business_id IS NOT NULL
 AND builderad_private.effect_allowed('public.source_registry','insert',to_jsonb(source_registry.*)));
DROP POLICY source_capability_executor_insert ON public.source_capabilities;
CREATE POLICY source_capability_executor_insert ON public.source_capabilities FOR INSERT TO builderad_scope_executor WITH CHECK(
 (EXISTS(SELECT 1 FROM public.resource_right_fences f WHERE f.source_id=source_capabilities.source_id AND f.kind='source' AND f.adoption_state='v2_adopted')
 OR EXISTS(SELECT 1 FROM public.source_registry s WHERE s.id=source_capabilities.source_id AND s.custody_kind='business_native'))
 AND builderad_private.effect_allowed('public.source_capabilities','insert',to_jsonb(source_capabilities.*)));

SET LOCAL ROLE builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.business_source_audit_delivery(text,uuid,uuid) FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_private.business_source_audit_delivery(text,uuid,uuid) TO builderad_owner;
REVOKE ALL ON FUNCTION builderad_private.source_custody_integrity(),
 builderad_private.require_business_source_creator(bigint,bigint,text,jsonb,boolean),
 builderad_private.business_source_basis_current(bigint,bigint),
 builderad_private.business_source_grant_current(bigint,bigint,text,boolean),
 builderad_private.business_source_proof(bigint,bigint,text,boolean),
 builderad_private.business_source_require(text,uuid,text,text,text,text)
 FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_private.business_source_grant_current(bigint,bigint,text,boolean),
 builderad_private.business_source_require(text,uuid,text,text,text,text),
 builderad_private.principal(text,boolean) TO builderad_owner;
REVOKE ALL ON FUNCTION public.builderad_v2_business_source_bootstrap(text,uuid,uuid,uuid,jsonb,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_v2_business_source_bootstrap(text,uuid,uuid,uuid,jsonb,jsonb) TO builderad_app;
REVOKE ALL ON FUNCTION public.builderad_v2_business_source_owner(text,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_v2_business_source_owner(text,uuid) TO builderad_app;
RESET ROLE;
GRANT UPDATE ON public.source_audit_delivery TO builderad_scope_executor;
GRANT SELECT ON public.security_source_audit_custody TO builderad_scope_executor;
CREATE POLICY business_source_custody_read ON public.security_source_audit_custody FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY business_source_delivery_update ON public.source_audit_delivery FOR UPDATE TO builderad_scope_executor USING(workspace_id IS NULL)
 WITH CHECK(workspace_id IS NULL AND builderad_private.effect_allowed('public.source_audit_delivery','update',to_jsonb(source_audit_delivery.*)));
GRANT TRIGGER ON public.source_audit_delivery TO builderad_scope_executor;
SET LOCAL ROLE builderad_scope_executor;
CREATE TRIGGER zz_business_source_delivery_update BEFORE UPDATE ON public.source_audit_delivery FOR EACH ROW
 WHEN(OLD.workspace_id IS NULL OR NEW.workspace_id IS NULL) EXECUTE FUNCTION builderad_private.guard_effect();
RESET ROLE;
REVOKE TRIGGER ON public.source_audit_delivery FROM builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_source_actor(bigint,text,boolean,boolean),
 public.builderad_source_require(text,uuid,bigint,text,text,text,text) FROM PUBLIC,builderad_app,builderad_authority;
REVOKE ALL ON FUNCTION public.builderad_source_owner_evidence(bigint,text),
 public.builderad_source_has(text,uuid,bigint,text,text,text) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_source_owner_evidence(bigint,text),
 public.builderad_source_has(text,uuid,bigint,text,text,text) TO builderad_app;
