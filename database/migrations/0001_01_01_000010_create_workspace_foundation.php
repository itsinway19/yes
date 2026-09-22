<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->default(DB::raw('uuidv7()'))->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 16)->default('active');
            $table->char('currency_code', 3)->nullable();
            $table->string('timezone')->default('UTC');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_currency_code_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE workspaces ADD CONSTRAINT workspaces_public_id_uuidv7_check CHECK (uuid_extract_version(public_id) = 7)');

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->primary(['workspace_id', 'user_id']);
            $table->index(['user_id', 'status'], 'workspace_memberships_user_status_index');
        });

        DB::statement("ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_role_check CHECK (role IN ('owner', 'admin', 'member'))");
        DB::statement("ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_status_check CHECK (status IN ('active', 'revoked'))");
        DB::statement("ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_revocation_check CHECK ((status = 'active' AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))");

        DB::statement('ALTER TABLE workspaces ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE workspaces FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE workspace_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE workspace_memberships FORCE ROW LEVEL SECURITY');

        DB::statement('CREATE POLICY workspaces_owner_all ON workspaces FOR ALL TO builderad_owner USING (true) WITH CHECK (true)');
        DB::statement('CREATE POLICY workspace_memberships_owner_all ON workspace_memberships FOR ALL TO builderad_owner USING (true) WITH CHECK (true)');

        $phase = "current_setting('app.authorization_phase', true)";
        $principal = "NULLIF(current_setting('app.principal_user_id', true), '')::bigint";
        $workspace = "NULLIF(current_setting('app.workspace_id', true), '')::bigint";
        $workspaceIsEmpty = "NULLIF(current_setting('app.workspace_id', true), '') IS NULL";

        $membershipVisibility = "CASE {$phase}
            WHEN 'principal' THEN {$workspaceIsEmpty} AND user_id = {$principal} AND status = 'active'
            WHEN 'workspace' THEN workspace_id = {$workspace} AND user_id = {$principal} AND status = 'active'
            ELSE false
        END";

        DB::statement("CREATE POLICY workspace_memberships_select ON workspace_memberships FOR SELECT TO builderad_app USING ({$membershipVisibility})");

        $activeMembership = "EXISTS (
            SELECT 1
            FROM workspace_memberships membership
            WHERE membership.workspace_id = workspaces.id
              AND membership.user_id = {$principal}
              AND membership.status = 'active'
        )";
        $workspaceVisibility = "CASE {$phase}
            WHEN 'principal' THEN {$workspaceIsEmpty} AND status = 'active' AND {$activeMembership}
            WHEN 'workspace' THEN id = {$workspace} AND status = 'active' AND {$activeMembership}
            ELSE false
        END";

        DB::statement("CREATE POLICY workspaces_select ON workspaces FOR SELECT TO builderad_app USING ({$workspaceVisibility})");

        DB::statement('REVOKE ALL PRIVILEGES ON workspaces, workspace_memberships FROM builderad_app');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE workspaces_id_seq FROM builderad_app');
        DB::statement('GRANT SELECT ON workspaces, workspace_memberships TO builderad_app');

        DB::statement("COMMENT ON INDEX workspace_memberships_user_status_index IS 'Principal bootstrap: active memberships by user without workspace context'");
        DB::statement("COMMENT ON CONSTRAINT workspace_memberships_pkey ON workspace_memberships IS 'One explicit membership per user and workspace; supports workspace-scoped membership access'");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
    }
};
