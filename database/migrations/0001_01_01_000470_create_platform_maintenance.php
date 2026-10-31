<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE platform_maintenance (
 id smallint PRIMARY KEY CHECK (id = 1), revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
 active boolean NOT NULL DEFAULT false,
 message_ar text NOT NULL DEFAULT 'نجري تحسينات على المنصة. يرجى المحاولة بعد قليل.' CHECK (length(message_ar) BETWEEN 1 AND 500),
 message_en text NOT NULL DEFAULT 'We are improving the platform. Please try again shortly.' CHECK (length(message_en) BETWEEN 1 AND 500),
 retry_after integer NOT NULL DEFAULT 60 CHECK (retry_after BETWEEN 30 AND 3600),
 changed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
INSERT INTO platform_maintenance(id) VALUES (1);
CREATE TABLE platform_maintenance_commands (
 command_id uuid PRIMARY KEY, actor_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 actor_generation bigint, fingerprint text NOT NULL CHECK (length(fingerprint) = 64),
 previous_revision bigint NOT NULL, resulting_revision bigint NOT NULL UNIQUE,
 reason text NOT NULL CHECK (length(reason) BETWEEN 1 AND 500),
 result jsonb NOT NULL, occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
REVOKE ALL ON platform_maintenance, platform_maintenance_commands FROM PUBLIC, builderad_app, builderad_authority;
GRANT SELECT ON platform_maintenance TO builderad_app;
GRANT SELECT, UPDATE ON platform_maintenance TO builderad_authority;
GRANT SELECT, INSERT ON platform_maintenance_commands TO builderad_authority;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE platform_maintenance_commands; DROP TABLE platform_maintenance;');
    }
};
