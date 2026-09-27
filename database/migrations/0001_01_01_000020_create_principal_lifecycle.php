<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS builderad_guard_lifecycle_generation()');
        Schema::create('principal_lifecycles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('cause', 64)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE principal_lifecycles ADD CONSTRAINT principal_lifecycles_status_check CHECK (status IN ('pending', 'current', 'disabled'))");
        DB::statement("ALTER TABLE principal_lifecycles ADD CONSTRAINT principal_lifecycles_current_generation_check CHECK (status <> 'current' OR generation > 0)");
        DB::statement(<<<'SQL'
            CREATE FUNCTION builderad_guard_lifecycle_generation() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $function$
            BEGIN
                IF NEW.user_id <> OLD.user_id OR NEW.generation < OLD.generation OR
                   (NEW.status <> OLD.status AND NEW.status IN ('current', 'disabled')
                    AND NEW.generation <= OLD.generation) THEN
                    RAISE EXCEPTION 'Lifecycle generation must advance monotonically'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$
            SQL);
        DB::statement('CREATE TRIGGER principal_lifecycle_generation_guard BEFORE UPDATE ON principal_lifecycles FOR EACH ROW EXECUTE FUNCTION builderad_guard_lifecycle_generation()');

        Schema::create('security_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->string('event', 64);
            $table->string('reason', 128);
            $table->string('prior_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->unsignedBigInteger('prior_generation')->nullable();
            $table->unsignedBigInteger('new_generation');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['subject_user_id', 'occurred_at']);
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE ON principal_lifecycles TO builderad_app');
        DB::statement('GRANT INSERT, SELECT ON security_audit_events TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE security_audit_events_id_seq TO builderad_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_events');
        Schema::dropIfExists('principal_lifecycles');
        DB::statement('DROP FUNCTION IF EXISTS builderad_guard_lifecycle_generation()');
    }
};
