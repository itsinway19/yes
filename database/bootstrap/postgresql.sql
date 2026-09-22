\set ON_ERROR_STOP on

SELECT current_setting('server_version_num')::integer >= 180000
    AND current_setting('server_version_num')::integer < 190000 AS supported_version \gset
\if :supported_version
\else
    DO $$
    BEGIN
        RAISE EXCEPTION 'Builderad requires PostgreSQL 18.x.';
    END
    $$;
\endif

SELECT rolsuper AS can_bootstrap
FROM pg_roles
WHERE rolname = current_user
\gset

\if :can_bootstrap
\else
    DO $$
    BEGIN
        RAISE EXCEPTION 'Builderad local/CI bootstrap requires a PostgreSQL superuser.';
    END
    $$;
\endif

SELECT 'CREATE ROLE builderad_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_owner')
\gexec

SELECT 'CREATE ROLE builderad_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_app')
\gexec

ALTER ROLE builderad_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
ALTER ROLE builderad_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;

SELECT format('REVOKE %I FROM builderad_app', granted_roles.rolname)
FROM pg_auth_members memberships
JOIN pg_roles granted_roles ON granted_roles.oid = memberships.roleid
JOIN pg_roles member_roles ON member_roles.oid = memberships.member
WHERE member_roles.rolname = 'builderad_app'
\gexec

ALTER ROLE builderad_app SET search_path TO public;
ALTER ROLE builderad_app SET statement_timeout TO '15s';
ALTER ROLE builderad_app SET lock_timeout TO '3s';
ALTER ROLE builderad_app SET idle_in_transaction_session_timeout TO '15s';

SELECT format('CREATE DATABASE %I OWNER builderad_owner', database_name)
FROM (VALUES ('builderad'), ('builderad_test')) AS databases(database_name)
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = database_name)
\gexec

ALTER DATABASE builderad OWNER TO builderad_owner;
ALTER DATABASE builderad_test OWNER TO builderad_owner;
REVOKE ALL PRIVILEGES ON DATABASE builderad, builderad_test FROM PUBLIC, builderad_app;
GRANT CONNECT ON DATABASE builderad, builderad_test TO builderad_owner, builderad_app;

\connect builderad
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC, builderad_app;
GRANT USAGE ON SCHEMA public TO builderad_app;

\connect builderad_test
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC, builderad_app;
GRANT USAGE ON SCHEMA public TO builderad_app;

\echo 'Builderad PostgreSQL authority model is ready. Set role passwords outside this repository before connecting.'
