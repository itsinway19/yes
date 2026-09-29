\set ON_ERROR_STOP on
SELECT current_database(),current_user,host(inet_server_addr()),inet_server_port(),version();
SELECT count(*) AS migration_count FROM migrations;
SELECT migration FROM migrations ORDER BY id;
SELECT c.relname,r.rolname AS owner,c.relrowsecurity,c.relforcerowsecurity,
 has_table_privilege('builderad_app',c.oid,'SELECT') AS runtime_read,
 has_table_privilege('builderad_app',c.oid,'INSERT') AS runtime_insert,
 has_table_privilege('builderad_authority',c.oid,'INSERT') AS authority_insert
FROM pg_class c JOIN pg_roles r ON r.oid=c.relowner
WHERE c.relname LIKE 'commercial_%' OR c.relname LIKE 'security_commercial_%' ORDER BY c.relname;
SELECT tablename,policyname,roles,cmd FROM pg_policies
WHERE tablename LIKE 'commercial_%' OR tablename LIKE 'security_commercial_%' ORDER BY tablename,policyname;
SELECT conrelid::regclass,conname,pg_get_constraintdef(oid) FROM pg_constraint
WHERE conrelid::regclass::text LIKE 'commercial_%' ORDER BY conrelid::regclass::text,conname;
SELECT tablename,indexname,indexdef FROM pg_indexes
WHERE tablename LIKE 'commercial_%' OR indexname LIKE 'security_action_grants_commercial_%' ORDER BY tablename,indexname;
SELECT tgrelid::regclass,tgname FROM pg_trigger WHERE tgname='sa01_immutable' ORDER BY tgrelid::regclass::text;
