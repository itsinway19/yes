<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON jobs TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE jobs_id_seq TO builderad_app');

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON failed_jobs TO builderad_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE failed_jobs_id_seq TO builderad_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
