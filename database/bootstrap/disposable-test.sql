-- Run on a new, separate PostgreSQL 18 cluster on 127.0.0.1:5433.
-- CI may use -v ci_docker_service=1 when host port 5433 maps to container port 5432.
-- First run postgresql.sql and test-authority-role.sql on that cluster.
-- Supply a new UUIDv4 with psql -v isolation_token=<uuid>; keep the same value
-- in the isolated test process as BUILDERAD_TEST_ISOLATION_TOKEN.
\set ON_ERROR_STOP on
\if :{?ci_docker_service}
    \set expected_server_port 5432
    \set expected_loopback false
\else
    \set expected_server_port 5433
    \set expected_loopback true
\endif

SELECT current_database() = 'postgres' AND inet_server_port() = :expected_server_port
    AND (host(inet_server_addr()) = '127.0.0.1') = :expected_loopback
    AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user)
    AND NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'builderad_test_disposable')
    AS safe_target \gset
\if :safe_target
\else
    DO $$ BEGIN RAISE EXCEPTION 'Disposable setup requires a fresh isolated local cluster and an absent target database'; END $$;
\endif

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
