<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE browser_identity_transitions (
                lineage_id uuid PRIMARY KEY,
                boundary varchar(16) NOT NULL CHECK (boundary IN ('advertiser', 'internal')),
                epoch bigint NOT NULL DEFAULT 0 CHECK (epoch >= 0),
                current_session_key_hash varchar(64) UNIQUE,
                principal_id bigint REFERENCES users(id) ON DELETE RESTRICT,
                status varchar(16) NOT NULL DEFAULT 'anonymous',
                kind varchar(16) NOT NULL DEFAULT 'bootstrap',
                last_nonce varchar(64) UNIQUE,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT identity_transition_binding CHECK (
                    (status = 'active' AND principal_id IS NOT NULL AND current_session_key_hash IS NOT NULL
                        AND current_session_key_hash ~ '^[0-9a-f]{64}$') OR
                    (status IN ('anonymous', 'closed') AND principal_id IS NULL
                        AND current_session_key_hash IS NULL)
                ),
                CONSTRAINT identity_transition_phase CHECK (
                    (epoch = 0 AND kind = 'bootstrap' AND status = 'anonymous' AND last_nonce IS NULL) OR
                    (epoch > 0 AND last_nonce IS NOT NULL AND last_nonce ~ '^[0-9a-f]{64}$' AND (
                        (kind IN ('login', 'rebind') AND status = 'active') OR
                        (kind IN ('logout', 'expired') AND status = 'closed')
                    ))
                )
            );
            CREATE FUNCTION builderad_guard_identity_transition() RETURNS trigger
            LANGUAGE plpgsql SET search_path = public, pg_temp AS $function$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.epoch <> 0 THEN
                        RAISE EXCEPTION 'A lineage must start anonymous' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.lineage_id <> OLD.lineage_id OR NEW.boundary <> OLD.boundary
                    OR NEW.created_at <> OLD.created_at OR NEW.epoch <> OLD.epoch + 1
                    OR NEW.last_nonce IS NOT DISTINCT FROM OLD.last_nonce THEN
                    RAISE EXCEPTION 'A transition must advance exactly one epoch' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $function$;
            CREATE TRIGGER identity_transition_guard BEFORE INSERT OR UPDATE
                ON browser_identity_transitions FOR EACH ROW
                EXECUTE FUNCTION builderad_guard_identity_transition();
            REVOKE ALL ON browser_identity_transitions FROM PUBLIC, builderad_app;
            GRANT SELECT ON browser_identity_transitions TO builderad_app;
            REVOKE ALL ON FUNCTION builderad_guard_identity_transition() FROM PUBLIC;
            GRANT SELECT, INSERT, UPDATE ON browser_identity_transitions TO builderad_authority;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('browser_identity_transitions')->exists()) {
            throw new LogicException('Retained lineage ordering requires a governed forward migration.');
        }
        DB::statement('DROP TABLE browser_identity_transitions');
        DB::statement('DROP FUNCTION builderad_guard_identity_transition()');
    }
};
