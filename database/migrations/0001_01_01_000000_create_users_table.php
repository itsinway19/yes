<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret_encrypted')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes_encrypted')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE ON users TO builderad_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE users_id_seq TO builderad_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
