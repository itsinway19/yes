-- Seal the actual installed catalog only after ownership, RLS, triggers and ACL installation.
-- Unqualified plugin/definer/ACL drift cannot be silently promoted into migration readiness.
DO $install$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.legacy_catalog_digest();
 RESET ROLE;
 -- Installer-owned immutable bytes only; SECURITY INVOKER conveys no deployment privilege.
 -- Do not regrant CREATE to the non-login executor after the permission boundary was closed.
 EXECUTE format('CREATE FUNCTION builderad_private.legacy_installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L', 'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
 REVOKE ALL ON FUNCTION builderad_private.legacy_installation_digest() FROM PUBLIC,builderad_app,builderad_authority;
 GRANT EXECUTE ON FUNCTION builderad_private.legacy_installation_digest() TO builderad_scope_executor;
END $install$;
