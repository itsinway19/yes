<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('principal_lifecycles', function (Blueprint $table) {
            $table->string('principal_class', 16)->default('advertiser');
        });
        DB::statement("ALTER TABLE principal_lifecycles ADD CONSTRAINT principal_lifecycles_class_check CHECK (principal_class IN ('advertiser', 'staff'))");

        Schema::create('internal_personnel', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->foreignId('attested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('attested_at')->nullable();
            $table->timestampTz('factor_independence_attested_at')->nullable();
            $table->foreignId('factor_independence_attested_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE internal_personnel ADD CONSTRAINT internal_personnel_kind_check CHECK (kind IN ('founder', 'employee'))");
        DB::statement("ALTER TABLE internal_personnel ADD CONSTRAINT internal_personnel_status_check CHECK (status IN ('pending', 'active', 'revoked'))");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON internal_personnel TO builderad_app');

        Schema::create('security_action_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 32)->default('human');
            $table->string('action', 64);
            $table->string('scope_kind', 16);
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->unsignedBigInteger('subject_generation');
            $table->string('reason', 255);
            $table->string('status', 16);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['subject_user_id', 'action', 'scope_kind', 'status']);
        });
        DB::statement("ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_status_check CHECK (status IN ('active', 'revoked'))");
        DB::statement("ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_source_check CHECK ((source = 'system_registration' AND granted_by IS NULL AND action = 'workspace.create' AND scope_kind = 'platform') OR (source = 'system_workspace_creation' AND granted_by IS NULL AND action IN ('workspace.view', 'service.scope') AND scope_kind = 'workspace') OR (source = 'founder_bootstrap' AND granted_by IS NULL AND scope_kind = 'platform') OR (source = 'human' AND granted_by IS NOT NULL AND granted_by <> subject_user_id))");
        DB::statement('CREATE UNIQUE INDEX security_action_grants_workspace_unique ON security_action_grants (subject_user_id, action, workspace_id, subject_generation) WHERE workspace_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX security_action_grants_platform_active_unique ON security_action_grants (subject_user_id, action, subject_generation) WHERE workspace_id IS NULL AND status = 'active'");
        DB::statement("ALTER TABLE security_action_grants ADD CONSTRAINT security_action_grants_scope_check CHECK ((scope_kind = 'platform' AND workspace_id IS NULL) OR (scope_kind = 'workspace' AND workspace_id IS NOT NULL))");
        DB::statement('GRANT SELECT, INSERT, UPDATE ON security_action_grants TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE security_action_grants_id_seq TO builderad_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_action_grants');
        Schema::dropIfExists('internal_personnel');
        Schema::table('principal_lifecycles', function (Blueprint $table) {
            $table->dropColumn('principal_class');
        });
    }
};
