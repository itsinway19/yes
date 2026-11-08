-- Requalify only the atomic D1 installation, after the verified G4 baseline and
-- all function ownership/ACL/RLS changes, including temporary install grants.
DO $install$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.legacy_catalog_digest();
 RESET ROLE;
 EXECUTE format('CREATE OR REPLACE FUNCTION builderad_private.legacy_installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L',
  'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
 REVOKE ALL ON FUNCTION builderad_private.legacy_installation_digest() FROM PUBLIC,builderad_app,builderad_authority;
 GRANT EXECUTE ON FUNCTION builderad_private.legacy_installation_digest() TO builderad_scope_executor;
END $install$;
