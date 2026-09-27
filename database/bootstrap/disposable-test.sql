-- Run on a new, separate PostgreSQL 18 cluster on 127.0.0.1:5433.
-- First run postgresql.sql and test-authority-role.sql on that cluster.
-- Supply a new UUIDv4 with psql -v isolation_token=<uuid>; keep the same value
-- in the isolated test process as BUILDERAD_TEST_ISOLATION_TOKEN.
\set ON_ERROR_STOP on

DO $guard$
BEGIN
    IF current_database() <> 'postgres' OR inet_server_port() <> 5433
       OR host(inet_server_addr()) <> '127.0.0.1'
       OR NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user)
       OR EXISTS (SELECT 1 FROM pg_database WHERE datname = 'builderad_test_disposable') THEN
        RAISE EXCEPTION 'Disposable setup requires a fresh local cluster on port 5433 and an absent target database';
    END IF;
END
$guard$;

SELECT :'isolation_token' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' AS valid_token \gset
\if :valid_token
\else
    DO $$ BEGIN RAISE EXCEPTION 'A UUIDv4 disposable test token is required'; END $$;
\endif

CREATE DATABASE builderad_test_disposable OWNER builderad_owner;
REVOKE ALL PRIVILEGES ON DATABASE builderad_test_disposable FROM PUBLIC, builderad_app;
GRANT CONNECT ON DATABASE builderad_test_disposable TO builderad_owner, builderad_app, builderad_authority;
SELECT format('COMMENT ON DATABASE builderad_test_disposable IS %L',
    'builderad-disposable-test:v1:' || :'isolation_token') \gexec

\connect builderad_test_disposable
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC, builderad_app, builderad_authority;
GRANT USAGE ON SCHEMA public TO builderad_app, builderad_authority;
