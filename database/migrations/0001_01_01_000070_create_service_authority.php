<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->default(DB::raw('uuidv7()'))->unique();
            $table->string('name', 120);
            $table->string('status', 16);
            $table->char('credential_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE service_identities ADD CONSTRAINT service_identities_status_check CHECK ((status = 'enabled' AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON service_identities TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE service_identities_id_seq TO builderad_app');

        Schema::create('service_action_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_identity_id')->constrained('service_identities')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('action', 64);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 128);
            $table->string('status', 16);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE service_action_grants ADD CONSTRAINT service_action_grants_action_check CHECK (action = 'workspace.view')");
        DB::statement("ALTER TABLE service_action_grants ADD CONSTRAINT service_action_grants_status_check CHECK ((status = 'active' AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX service_action_grants_active_unique ON service_action_grants (service_identity_id, workspace_id, action) WHERE status = 'active'");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON service_action_grants TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE service_action_grants_id_seq TO builderad_app');

        Schema::create('service_workspace_scopes', function (Blueprint $table) {
            $table->foreignId('service_identity_id')->constrained('service_identities')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->primary(['service_identity_id', 'workspace_id']);
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 128);
            $table->string('status', 16);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE service_workspace_scopes ADD CONSTRAINT service_workspace_scopes_status_check CHECK ((status = 'active' AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON service_workspace_scopes TO builderad_app');

        Schema::create('service_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('service_identity_id')->constrained('service_identities')->restrictOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->string('event', 64);
            $table->string('reason', 128);
            $table->string('prior_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->unsignedBigInteger('prior_version')->nullable();
            $table->unsignedBigInteger('new_version');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['service_identity_id', 'occurred_at']);
        });
        DB::statement('GRANT INSERT, SELECT ON service_audit_events TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE service_audit_events_id_seq TO builderad_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_has_valid_workspace_owner(p_workspace_id bigint)
            RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
                SELECT EXISTS (
                    SELECT 1 FROM workspace_ownerships ownership
                    JOIN workspace_memberships membership
                      ON membership.workspace_id = ownership.workspace_id
                     AND membership.user_id = ownership.responsible_owner_user_id
                     AND membership.role = 'owner' AND membership.status = 'active'
                    JOIN principal_lifecycles lifecycle
                      ON lifecycle.user_id = ownership.responsible_owner_user_id
                     AND lifecycle.status = 'current'
                     AND lifecycle.principal_class = 'advertiser'
                    JOIN users identity ON identity.id = lifecycle.user_id
                     AND identity.email_verified_at IS NOT NULL
                    WHERE ownership.workspace_id = p_workspace_id
                      AND ownership.version >= 1
                )
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_has_valid_workspace_owner(bigint) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_has_valid_workspace_owner(bigint) TO builderad_app');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_workspace_id_by_public(p_public_id uuid)
            RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
                SELECT id FROM workspaces WHERE public_id = p_public_id AND status = 'active'
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_workspace_id_by_public(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_workspace_id_by_public(uuid) TO builderad_app');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_workspace_public_by_id(p_id bigint)
            RETURNS uuid LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $function$
                SELECT public_id FROM workspaces WHERE id = p_id AND status = 'active'
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_workspace_public_by_id(bigint) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_workspace_public_by_id(bigint) TO builderad_app');
        DB::statement(<<<'SQL'
            CREATE POLICY workspaces_service_select ON workspaces FOR SELECT TO builderad_app
            USING (
                current_setting('app.authorization_phase', true) = 'service'
                AND id = NULLIF(current_setting('app.workspace_id', true), '')::bigint
                AND status = 'active'
                AND builderad_has_valid_workspace_owner(id)
                AND EXISTS (
                    SELECT 1 FROM service_identities identity
                    JOIN service_workspace_scopes scope
                      ON scope.service_identity_id = identity.id
                     AND scope.workspace_id = workspaces.id AND scope.status = 'active'
                    JOIN service_action_grants grant_record
                      ON grant_record.service_identity_id = identity.id
                     AND grant_record.workspace_id = workspaces.id
                     AND grant_record.action = 'workspace.view'
                     AND grant_record.status = 'active'
                     AND grant_record.revoked_at IS NULL
                     AND (grant_record.expires_at IS NULL OR grant_record.expires_at > clock_timestamp())
                    WHERE identity.id = NULLIF(current_setting('app.service_identity_id', true), '')::bigint
                      AND identity.status = 'enabled'
                      AND identity.credential_hash = encode(sha256(current_setting('app.service_credential', true)::bytea), 'hex')
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION builderad_authorize_service_workspace(
                p_service_public_id uuid, p_credential text, p_workspace_public_id uuid
            ) RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $function$
            DECLARE
                v_service_id bigint;
                v_workspace_id bigint;
                v_owner_id bigint;
            BEGIN
                SELECT id INTO v_service_id FROM service_identities
                WHERE public_id = p_service_public_id
                  AND status = 'enabled'
                  AND credential_hash = encode(sha256(p_credential::bytea), 'hex')
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current service identity required' USING ERRCODE = '42501';
                END IF;

                SELECT id INTO v_workspace_id FROM workspaces
                WHERE public_id = p_workspace_public_id AND status = 'active'
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Active workspace required' USING ERRCODE = '42501';
                END IF;

                PERFORM 1 FROM service_workspace_scopes
                WHERE service_identity_id = v_service_id AND workspace_id = v_workspace_id
                  AND status = 'active' AND revoked_at IS NULL
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Owner-authorized service scope required' USING ERRCODE = '42501';
                END IF;

                PERFORM 1 FROM service_action_grants
                WHERE service_identity_id = v_service_id AND workspace_id = v_workspace_id
                  AND action = 'workspace.view' AND status = 'active' AND revoked_at IS NULL
                  AND (expires_at IS NULL OR expires_at > clock_timestamp())
                FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current service action grant required' USING ERRCODE = '42501';
                END IF;

                SELECT responsible_owner_user_id INTO v_owner_id FROM workspace_ownerships
                WHERE workspace_id = v_workspace_id AND version >= 1 FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Responsible Workspace Owner required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM workspace_memberships
                WHERE workspace_id = v_workspace_id AND user_id = v_owner_id
                  AND role = 'owner' AND status = 'active' FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current Owner membership required' USING ERRCODE = '42501';
                END IF;
                PERFORM 1 FROM principal_lifecycles lifecycle
                JOIN users identity ON identity.id = lifecycle.user_id
                WHERE lifecycle.user_id = v_owner_id
                  AND lifecycle.principal_class = 'advertiser'
                  AND lifecycle.status = 'current'
                  AND identity.email_verified_at IS NOT NULL
                FOR SHARE OF lifecycle, identity;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Current Owner lifecycle required' USING ERRCODE = '42501';
                END IF;

                RETURN v_workspace_id;
            END
            $function$
            SQL);
        DB::statement('REVOKE ALL ON FUNCTION builderad_authorize_service_workspace(uuid, text, uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_authorize_service_workspace(uuid, text, uuid) TO builderad_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS builderad_authorize_service_workspace(uuid, text, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_workspace_id_by_public(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS builderad_workspace_public_by_id(bigint)');
        DB::statement('DROP POLICY IF EXISTS workspaces_service_select ON workspaces');
        DB::statement('DROP FUNCTION IF EXISTS builderad_has_valid_workspace_owner(bigint)');
        Schema::dropIfExists('service_audit_events');
        Schema::dropIfExists('service_workspace_scopes');
        Schema::dropIfExists('service_action_grants');
        Schema::dropIfExists('service_identities');
    }
};
