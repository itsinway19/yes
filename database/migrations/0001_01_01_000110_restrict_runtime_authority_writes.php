<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'users', 'password_reset_tokens', 'principal_lifecycles',
            'security_sessions', 'passkeys', 'internal_personnel',
            'security_action_grants', 'security_audit_events',
            'service_identities', 'service_action_grants',
            'service_workspace_scopes', 'service_audit_events',
        ];

        DB::statement('REVOKE INSERT, UPDATE, DELETE ON TABLE '.implode(', ', $tables).' FROM builderad_app');
        // PostgreSQL requires UPDATE privilege for SELECT ... FOR SHARE. The runtime
        // may lock rows, while authority-bearing columns remain unwritable.
        foreach (['principal_lifecycles', 'security_sessions', 'internal_personnel',
            'security_action_grants', 'service_identities'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN runtime_lock_marker boolean NOT NULL DEFAULT false");
            DB::statement("GRANT UPDATE (runtime_lock_marker) ON {$table} TO builderad_app");
        }
        DB::statement(<<<'SQL'
            REVOKE USAGE ON SEQUENCE users_id_seq, security_sessions_id_seq,
                passkeys_id_seq, security_action_grants_id_seq,
                security_audit_events_id_seq, service_identities_id_seq,
                service_action_grants_id_seq, service_audit_events_id_seq
            FROM builderad_app
            SQL);

        DB::statement(<<<'SQL'
            DO $grant$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_authority') THEN
                    GRANT SELECT, INSERT, UPDATE ON users, principal_lifecycles,
                        security_sessions, internal_personnel, security_action_grants,
                        security_audit_events, service_identities, service_action_grants,
                        service_workspace_scopes, service_audit_events TO builderad_authority;
                    GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens, passkeys
                        TO builderad_authority;
                    GRANT USAGE, SELECT ON SEQUENCE users_id_seq, security_sessions_id_seq,
                        passkeys_id_seq, security_action_grants_id_seq,
                        security_audit_events_id_seq, service_identities_id_seq,
                        service_action_grants_id_seq, service_audit_events_id_seq
                        TO builderad_authority;
                END IF;
            END
            $grant$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_signed_service_context()
            RETURNS TABLE(service_identity_id bigint, workspace_id bigint)
            LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_parts text[];
                v_expected text;
            BEGIN
                v_parts := string_to_array(current_setting('app.signed_service_context', true), '|');
                IF array_length(v_parts, 1) <> 5
                   OR pg_current_xact_id_if_assigned() IS NULL
                   OR v_parts[3] <> pg_current_xact_id_if_assigned()::text
                   OR v_parts[4] <> pg_backend_pid()::text
                   OR current_setting('app.service_identity_id', true) IS DISTINCT FROM v_parts[1]
                   OR current_setting('app.workspace_id', true) IS DISTINCT FROM v_parts[2]
                   OR current_setting('app.authorization_phase', true) IS DISTINCT FROM 'service' THEN
                    RETURN;
                END IF;
                SELECT encode(hmac(convert_to(array_to_string(v_parts[1:4], '|'), 'UTF8'), secret, 'sha256'), 'hex')
                INTO v_expected FROM builderad_context_secret WHERE singleton;
                IF v_expected IS NULL OR v_expected <> v_parts[5] THEN
                    RETURN;
                END IF;
                RETURN QUERY SELECT v_parts[1]::bigint, v_parts[2]::bigint;
            EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN
                RETURN;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_signed_service_context() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_signed_service_context() TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_activate_service_context(
                p_service_public_id uuid, p_credential text, p_workspace_public_id uuid
            ) RETURNS bigint LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_service_id bigint;
                v_workspace_id bigint;
                v_payload text;
                v_secret bytea;
            BEGIN
                IF session_user <> 'builderad_app'
                   OR NULLIF(current_setting('app.signed_context', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.signed_service_context', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.authorization_phase', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.workspace_id', true), '') IS NOT NULL
                   OR NULLIF(current_setting('app.service_identity_id', true), '') IS NOT NULL THEN
                    RAISE EXCEPTION 'Clean runtime service transaction required' USING ERRCODE = '42501';
                END IF;
                v_workspace_id := builderad_authorize_service_workspace(
                    p_service_public_id, p_credential, p_workspace_public_id);
                SELECT id INTO v_service_id FROM service_identities
                WHERE public_id = p_service_public_id;
                SELECT secret INTO v_secret FROM builderad_context_secret WHERE singleton;
                v_payload := concat_ws('|', v_service_id, v_workspace_id,
                    pg_current_xact_id()::text, pg_backend_pid()::text);
                PERFORM set_config('app.signed_service_context',
                    v_payload || '|' || encode(hmac(convert_to(v_payload, 'UTF8'), v_secret, 'sha256'), 'hex'), true);
                PERFORM set_config('app.service_identity_id', v_service_id::text, true);
                PERFORM set_config('app.workspace_id', v_workspace_id::text, true);
                PERFORM set_config('app.authorization_phase', 'service', true);
                RETURN v_workspace_id;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_activate_service_context(uuid, text, uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_activate_service_context(uuid, text, uuid) TO builderad_app');

        DB::statement('DROP POLICY workspaces_service_select ON workspaces');
        DB::statement(<<<'SQL'
            CREATE POLICY workspaces_service_select ON workspaces FOR SELECT TO builderad_app USING (
                status = 'active' AND builderad_has_valid_workspace_owner(id)
                AND EXISTS (
                    SELECT 1 FROM builderad_signed_service_context() context
                    JOIN service_identities identity ON identity.id = context.service_identity_id
                    JOIN service_workspace_scopes scope
                      ON scope.service_identity_id = identity.id AND scope.workspace_id = workspaces.id
                     AND scope.status = 'active' AND scope.revoked_at IS NULL
                    JOIN service_action_grants grant_record
                      ON grant_record.service_identity_id = identity.id
                     AND grant_record.workspace_id = workspaces.id
                     AND grant_record.action = 'workspace.view'
                     AND grant_record.status = 'active' AND grant_record.revoked_at IS NULL
                     AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                    WHERE context.workspace_id = workspaces.id AND identity.status = 'enabled'
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_record_service_workspace_access()
            RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_service_id bigint;
                v_workspace_id bigint;
                v_version bigint;
            BEGIN
                IF session_user <> 'builderad_app' THEN
                    RAISE EXCEPTION 'Runtime service caller required' USING ERRCODE = '42501';
                END IF;
                SELECT service_identity_id, workspace_id INTO v_service_id, v_workspace_id
                FROM builderad_signed_service_context();
                IF v_service_id IS NULL THEN
                    RAISE EXCEPTION 'Signed service authority required' USING ERRCODE = '42501';
                END IF;
                SELECT version INTO v_version FROM service_identities
                WHERE id = v_service_id AND status = 'enabled' FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current service authority required' USING ERRCODE = '42501';
                END IF;
                INSERT INTO service_audit_events
                    (actor_user_id, service_identity_id, workspace_id, event, reason,
                     prior_status, new_status, prior_version, new_version, occurred_at)
                VALUES (NULL, v_service_id, v_workspace_id, 'service_workspace_access',
                        'workspace.view', 'enabled', 'enabled', v_version,
                        v_version, clock_timestamp());
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_record_service_workspace_access() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_record_service_workspace_access() TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_lock_and_has_active_workspace_membership(p_user_id bigint)
            RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            BEGIN
                IF session_user <> 'builderad_authority' THEN
                    RAISE EXCEPTION 'Authority role required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspace_memberships WHERE user_id = p_user_id FOR UPDATE;
                RETURN EXISTS (SELECT 1 FROM workspace_memberships
                               WHERE user_id = p_user_id AND status = 'active');
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_lock_and_has_active_workspace_membership(bigint) FROM PUBLIC');
        DB::statement(<<<'SQL'
            DO $grant$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'builderad_authority') THEN
                    GRANT EXECUTE ON FUNCTION builderad_lock_and_has_active_workspace_membership(bigint)
                        TO builderad_authority;
                END IF;
            END
            $grant$
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reopening the runtime authority boundary requires an explicit migration.');
    }
};
