\set ON_ERROR_STOP on
SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port(),version();
SELECT c.relname,r.rolname AS owner,c.relrowsecurity,c.relforcerowsecurity,
 has_table_privilege('builderad_app',c.oid,'SELECT') AS runtime_read,
 has_table_privilege('builderad_app',c.oid,'INSERT') AS runtime_insert,
 has_table_privilege('builderad_app',c.oid,'UPDATE') AS runtime_update,
 has_table_privilege('builderad_app',c.oid,'DELETE') AS runtime_delete,
 has_table_privilege('builderad_authority',c.oid,'INSERT') AS authority_insert
FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner
WHERE c.relname LIKE 'source_%' OR c.relname='security_source_audit_custody' ORDER BY c.relname;
SELECT tablename,policyname,roles,cmd,qual,with_check FROM pg_policies
WHERE tablename LIKE 'source_%' OR tablename='security_source_audit_custody' ORDER BY tablename,policyname;
SELECT p.oid::regprocedure,r.rolname,p.prosecdef,p.proconfig,
 has_function_privilege('builderad_app',p.oid,'EXECUTE') AS runtime_execute,
 has_function_privilege('builderad_authority',p.oid,'EXECUTE') AS authority_execute
FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.proname LIKE 'builderad_source_%' ORDER BY p.proname;
SELECT conrelid::regclass,conname,pg_get_constraintdef(oid) FROM pg_constraint
WHERE conrelid::regclass::text LIKE 'source_%' OR conname IN ('source_grant_shape','security_action_grants_source_check','security_action_grants_scope_check')
ORDER BY conrelid::regclass::text,conname;
SELECT tablename,indexname,indexdef FROM pg_indexes
WHERE tablename LIKE 'source_%' OR indexname='source_grants_active_unique' ORDER BY tablename,indexname;
SELECT tgrelid::regclass,tgname FROM pg_trigger WHERE tgname='source_immutable' ORDER BY tgrelid::regclass::text;
DO $$ BEGIN
 IF (SELECT count(*) FROM pg_class WHERE relname IN ('source_registry','source_capabilities','source_versions','source_command_receipts','source_audit_obligations','source_audit_delivery','security_source_audit_custody') AND relrowsecurity AND relforcerowsecurity)<>7 THEN
  RAISE EXCEPTION 'Source FORCE RLS catalog proof failed';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_class WHERE relname LIKE 'source_%' AND relkind='r'
  AND (has_table_privilege('builderad_app',oid,'INSERT') OR has_table_privilege('builderad_app',oid,'UPDATE') OR has_table_privilege('builderad_app',oid,'DELETE'))) THEN
  RAISE EXCEPTION 'Raw source runtime writer privilege found';
 END IF;
 IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname IN ('builderad_app','builderad_authority','builderad_owner') AND (rolsuper OR rolbypassrls OR rolinherit)) THEN
  RAISE EXCEPTION 'Unsafe role found';
 END IF;
END $$;
SELECT 'BB1_CATALOG_PROOF=PASS' AS proof;
