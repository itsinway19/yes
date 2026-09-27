<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('session_key_hash', 64)->unique();
            $table->unsignedBigInteger('lifecycle_generation');
            $table->string('auth_method', 32);
            $table->string('client_description', 255)->nullable();
            $table->timestampTz('authenticated_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['user_id', 'revoked_at', 'absolute_expires_at']);
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE ON security_sessions TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE security_sessions_id_seq TO builderad_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_sessions');
    }
};
