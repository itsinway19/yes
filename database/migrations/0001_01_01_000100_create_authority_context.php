<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement(<<<'SQL'
            CREATE TABLE builderad_context_secret (
                singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
                secret bytea NOT NULL CHECK (octet_length(secret) = 32)
            )
            SQL);
        DB::statement('INSERT INTO builderad_context_secret (singleton, secret) VALUES (true, gen_random_bytes(32))');
        DB::statement(<<<'SQL'
            CREATE TABLE builderad_context_tickets (
                token_hash char(64) PRIMARY KEY,
                actor_user_id bigint NOT NULL CHECK (actor_user_id > 0),
                backend_pid integer NOT NULL,
                transaction_id xid8 NOT NULL,
                expires_at timestamptz NOT NULL,
                consumed_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT builderad_context_ticket_lifetime CHECK (expires_at <= created_at + interval '30 seconds')
            )
            SQL);
        DB::statement('CREATE INDEX builderad_context_tickets_expires_at_idx ON builderad_context_tickets (expires_at)');
        DB::statement('REVOKE ALL ON builderad_context_secret, builderad_context_tickets FROM PUBLIC, builderad_app');
        DB::statement(<<<'SQL'
            DO $grant$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_authority') THEN
                    GRANT INSERT, UPDATE, DELETE ON builderad_context_tickets TO builderad_authority;
                    GRANT SELECT (expires_at) ON builderad_context_tickets TO builderad_authority;
                END IF;
            END
            $grant$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_signed_context()
            RETURNS TABLE (actor_user_id bigint, phase text, workspace_id bigint)
            LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_parts text[];
                v_payload text;
                v_expected text;
            BEGIN
                v_parts := string_to_array(current_setting('app.signed_context', true), '|');
                IF array_length(v_parts, 1) <> 6
                   OR pg_current_xact_id_if_assigned() IS NULL
                   OR v_parts[4] <> pg_current_xact_id_if_assigned()::text
                   OR v_parts[5] <> pg_backend_pid()::text
                   OR v_parts[2] NOT IN ('principal', 'workspace')
                   OR current_setting('app.principal_user_id', true) IS DISTINCT FROM v_parts[1]
                   OR current_setting('app.authorization_phase', true) IS DISTINCT FROM v_parts[2]
                   OR NULLIF(current_setting('app.workspace_id', true), '')::text
                      IS DISTINCT FROM NULLIF(v_parts[3], '0') THEN
                    RETURN;
                END IF;
                v_payload := array_to_string(v_parts[1:5], '|');
                SELECT encode(hmac(convert_to(v_payload, 'UTF8'), secret, 'sha256'), 'hex')
                INTO v_expected FROM builderad_context_secret WHERE singleton;
                IF v_expected IS NULL OR v_expected <> v_parts[6] THEN
                    RETURN;
                END IF;
                RETURN QUERY SELECT v_parts[1]::bigint, v_parts[2], NULLIF(v_parts[3], '0')::bigint;
            EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN
                RETURN;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_signed_context() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_signed_context() TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_activate_principal_context(p_ticket text)
            RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_actor bigint;
                v_payload text;
                v_secret bytea;
            BEGIN
                IF session_user <> 'builderad_app'
                   OR NULLIF(current_setting('app.signed_context', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.authorization_phase', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.principal_user_id', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.workspace_id', true), '') IS NOT NULL THEN
                    RAISE EXCEPTION 'Clean runtime transaction required' USING ERRCODE = '42501';
                END IF;
                SELECT actor_user_id INTO v_actor FROM builderad_context_tickets
                WHERE token_hash = encode(sha256(p_ticket::bytea), 'hex')
                  AND backend_pid = pg_backend_pid()
                  AND transaction_id = pg_current_xact_id()
                  AND consumed_at IS NULL AND expires_at > clock_timestamp()
                FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Valid single-use context ticket required' USING ERRCODE = '42501';
                END IF;
                UPDATE builderad_context_tickets SET consumed_at = clock_timestamp()
                WHERE token_hash = encode(sha256(p_ticket::bytea), 'hex');
                SELECT secret INTO v_secret FROM builderad_context_secret WHERE singleton;
                v_payload := concat_ws('|', v_actor, 'principal', 0,
                    pg_current_xact_id()::text, pg_backend_pid()::text);
                PERFORM set_config('app.signed_context',
                    v_payload || '|' || encode(hmac(convert_to(v_payload, 'UTF8'), v_secret, 'sha256'), 'hex'), true);
                PERFORM set_config('app.principal_user_id', v_actor::text, true);
                PERFORM set_config('app.authorization_phase', 'principal', true);
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_activate_principal_context(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_activate_principal_context(text) TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_select_workspace_context(p_workspace_id bigint)
            RETURNS void LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_actor bigint;
                v_generation bigint;
                v_payload text;
                v_secret bytea;
            BEGIN
                IF session_user <> 'builderad_app' THEN
                    RAISE EXCEPTION 'Runtime caller required' USING ERRCODE = '42501';
                END IF;
                SELECT actor_user_id INTO v_actor FROM builderad_signed_context()
                WHERE phase = 'principal' AND workspace_id IS NULL;
                IF v_actor IS NULL THEN
                    RAISE EXCEPTION 'Signed principal context required' USING ERRCODE = '42501';
                END IF;
                SELECT generation INTO v_generation FROM principal_lifecycles
                WHERE user_id = v_actor AND status = 'current' AND principal_class = 'advertiser'
                FOR SHARE;
                IF v_generation IS NULL THEN
                    RAISE EXCEPTION 'Current Workspace authority required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspaces workspace
                JOIN workspace_memberships membership ON membership.workspace_id = workspace.id
                JOIN security_action_grants grant_record ON grant_record.workspace_id = workspace.id
                WHERE workspace.id = p_workspace_id AND workspace.status = 'active'
                  AND membership.user_id = v_actor AND membership.status = 'active'
                  AND grant_record.subject_user_id = v_actor
                  AND grant_record.subject_generation = v_generation
                  AND grant_record.action = 'workspace.view'
                  AND grant_record.scope_kind = 'workspace'
                  AND grant_record.status = 'active' AND grant_record.revoked_at IS NULL
                  AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                FOR SHARE OF workspace, membership, grant_record;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current Workspace authority required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspace_ownerships ownership
                JOIN workspace_memberships owner_membership
                  ON owner_membership.workspace_id = ownership.workspace_id
                 AND owner_membership.user_id = ownership.responsible_owner_user_id
                JOIN principal_lifecycles owner_lifecycle
                  ON owner_lifecycle.user_id = ownership.responsible_owner_user_id
                JOIN users owner_identity ON owner_identity.id = ownership.responsible_owner_user_id
                WHERE ownership.workspace_id = p_workspace_id AND ownership.version >= 1
                  AND owner_membership.role = 'owner' AND owner_membership.status = 'active'
                  AND owner_lifecycle.status = 'current'
                  AND owner_lifecycle.principal_class = 'advertiser'
                  AND owner_identity.email_verified_at IS NOT NULL
                FOR SHARE OF ownership, owner_membership, owner_lifecycle, owner_identity;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current responsible Workspace Owner required' USING ERRCODE = '42501';
                END IF;
                SELECT secret INTO v_secret FROM builderad_context_secret WHERE singleton;
                v_payload := concat_ws('|', v_actor, 'workspace', p_workspace_id,
                    pg_current_xact_id()::text, pg_backend_pid()::text);
                PERFORM set_config('app.signed_context',
                    v_payload || '|' || encode(hmac(convert_to(v_payload, 'UTF8'), v_secret, 'sha256'), 'hex'), true);
                PERFORM set_config('app.workspace_id', p_workspace_id::text, true);
                PERFORM set_config('app.authorization_phase', 'workspace', true);
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_select_workspace_context(bigint) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_select_workspace_context(bigint) TO builderad_app');

        DB::statement('DROP POLICY workspaces_select ON workspaces');
        DB::statement(<<<'SQL'
            CREATE POLICY workspaces_select ON workspaces FOR SELECT TO builderad_app USING (
                EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    JOIN workspace_memberships membership
                      ON membership.workspace_id = workspaces.id
                     AND membership.user_id = context.actor_user_id
                     AND membership.status = 'active'
                    WHERE workspaces.status = 'active'
                      AND (context.phase = 'principal' AND context.workspace_id IS NULL
                           OR context.phase = 'workspace' AND context.workspace_id = workspaces.id)
                )
            )
            SQL);
        DB::statement('DROP POLICY workspace_memberships_select ON workspace_memberships');
        DB::statement(<<<'SQL'
            CREATE POLICY workspace_memberships_select ON workspace_memberships FOR SELECT TO builderad_app USING (
                EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    WHERE context.actor_user_id = user_id AND status = 'active'
                      AND (context.phase = 'principal' AND context.workspace_id IS NULL
                           OR context.phase = 'workspace' AND context.workspace_id = workspace_id)
                )
            )
            SQL);
        DB::statement('DROP POLICY workspace_ownerships_select ON workspace_ownerships');
        DB::statement(<<<'SQL'
            CREATE POLICY workspace_ownerships_select ON workspace_ownerships FOR SELECT TO builderad_app USING (
                EXISTS (
                    SELECT 1 FROM builderad_signed_context() context
                    JOIN workspace_memberships membership
                      ON membership.workspace_id = workspace_ownerships.workspace_id
                     AND membership.user_id = context.actor_user_id
                     AND membership.status = 'active'
                    WHERE context.phase = 'workspace'
                      AND context.workspace_id = workspace_ownerships.workspace_id
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS workspace_ownerships_select ON workspace_ownerships');
        DB::statement('DROP POLICY IF EXISTS workspace_memberships_select ON workspace_memberships');
        DB::statement('DROP POLICY IF EXISTS workspaces_select ON workspaces');
        DB::statement('DROP FUNCTION IF EXISTS builderad_select_workspace_context(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_activate_principal_context(text)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_signed_context()');
        DB::statement('DROP TABLE IF EXISTS builderad_context_tickets');
        DB::statement('DROP TABLE IF EXISTS builderad_context_secret');
    }
};
