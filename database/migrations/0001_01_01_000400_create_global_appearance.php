<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE global_appearance (
 id smallint PRIMARY KEY CHECK (id = 1),
 revision bigint NOT NULL CHECK (revision >= 0),
 primary_color text NOT NULL CHECK (primary_color ~ '^#[0-9a-f]{6}$'),
 secondary_color text NOT NULL CHECK (secondary_color ~ '^#[0-9a-f]{6}$'),
 published_by bigint REFERENCES users(id) ON DELETE RESTRICT,
 published_at timestamptz
);
REVOKE ALL ON global_appearance FROM PUBLIC, builderad_app, builderad_authority;
GRANT SELECT ON global_appearance TO builderad_app;
GRANT SELECT, INSERT, UPDATE ON global_appearance TO builderad_authority;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE global_appearance');
    }
};
