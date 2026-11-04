-- Organization metadata is independent of the commercial legacy cohort.
-- The raw legacy row and its commercial columns retain their existing fences.
CREATE FUNCTION builderad_v2_organization_metadata() RETURNS TABLE(
 id bigint,public_id uuid,name text,slug text,status text,currency_code text,timezone text,
 administration_revision bigint,scope_lock_marker boolean,created_at timestamptz,updated_at timestamptz)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
 SELECT w.id,w.public_id,w.name::text,w.slug::text,w.status::text,w.currency_code::text,w.timezone::text,
 w.administration_revision,w.scope_lock_marker,w.created_at,w.updated_at
 FROM public.workspaces w WHERE w.status='active' AND (
 EXISTS(SELECT 1 FROM public.builderad_signed_context() a
  JOIN public.principal_lifecycles l ON l.user_id=a.actor_user_id AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.workspace_memberships m ON m.user_id=l.user_id AND m.workspace_id=w.id AND m.status='active'
  JOIN public.security_action_grants g ON g.subject_user_id=l.user_id AND g.subject_generation=l.generation
   AND g.workspace_id=w.id AND g.scope_kind='workspace' AND g.action='workspace.view'
   AND g.status='active' AND g.revoked_at IS NULL AND(g.expires_at IS NULL OR g.expires_at>clock_timestamp())
  WHERE (a.phase='principal' AND a.workspace_id IS NULL) OR(a.phase='workspace' AND a.workspace_id=w.id))
 OR EXISTS(SELECT 1 FROM public.builderad_signed_service_context() s WHERE s.workspace_id=w.id));
$$;
REVOKE ALL ON FUNCTION builderad_v2_organization_metadata() FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_organization_metadata() TO builderad_app;
CREATE VIEW organization_workspaces WITH(security_barrier=true) AS SELECT * FROM builderad_v2_organization_metadata();
REVOKE ALL ON organization_workspaces FROM PUBLIC,builderad_app,builderad_authority;
GRANT SELECT ON organization_workspaces TO builderad_app;
