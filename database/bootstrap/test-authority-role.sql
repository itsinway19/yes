-- Run as a PostgreSQL superuser while connected to builderad_test only.
-- Assign the password interactively afterward with \password builderad_authority.
\set ON_ERROR_STOP on

DO $guard$
BEGIN
    IF current_database() <> 'builderad_test'
       OR NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'Test authority setup requires a superuser on builderad_test';
    END IF;
END
$guard$;

SELECT 'CREATE ROLE builderad_authority LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_authority')
\gexec

ALTER ROLE builderad_authority LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
ALTER ROLE builderad_authority SET search_path TO public;
ALTER ROLE builderad_authority SET statement_timeout TO '15s';
ALTER ROLE builderad_authority SET lock_timeout TO '3s';
ALTER ROLE builderad_authority SET idle_in_transaction_session_timeout TO '15s';

GRANT CONNECT ON DATABASE builderad_test TO builderad_authority;
GRANT USAGE ON SCHEMA public TO builderad_authority;
REVOKE CREATE ON SCHEMA public FROM builderad_authority;

DO $guard$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_auth_members membership
        JOIN pg_roles member_role ON member_role.oid = membership.member
        WHERE member_role.rolname = 'builderad_authority'
    ) OR has_database_privilege('builderad_authority', 'builderad', 'CONNECT') THEN
        RAISE EXCEPTION 'Authority role must have no inherited roles or service database access';
    END IF;
END
$guard$;
