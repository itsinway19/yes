<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'REGISTRATION_EMAIL_PROOF_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::unprepared(<<<'SQL'
            CREATE TABLE registration_email_proofs (
                attempt_id uuid PRIMARY KEY,
                lineage_id uuid UNIQUE REFERENCES browser_identity_transitions(lineage_id) ON DELETE CASCADE,
                epoch bigint,
                credential_hash char(64),
                email text,
                code_digest char(64),
                attempts smallint NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
                resend_at timestamptz,
                expires_at timestamptz NOT NULL,
                verified_at timestamptz,
                user_id bigint UNIQUE REFERENCES users(id) ON DELETE CASCADE,
                CHECK (
                    (user_id IS NULL AND lineage_id IS NOT NULL AND epoch IS NOT NULL AND credential_hash IS NOT NULL
                        AND email IS NOT NULL AND resend_at IS NOT NULL
                        AND (verified_at IS NULL OR code_digest IS NULL))
                    OR
                    (user_id IS NOT NULL AND lineage_id IS NULL AND epoch IS NULL AND credential_hash IS NULL
                        AND email IS NULL AND resend_at IS NULL AND verified_at IS NULL AND code_digest IS NULL)
                )
            );
            CREATE INDEX registration_email_proofs_expiry ON registration_email_proofs (expires_at);
            REVOKE ALL ON registration_email_proofs FROM PUBLIC, builderad_app, builderad_authority;
            GRANT SELECT, INSERT, UPDATE, DELETE ON registration_email_proofs TO builderad_authority;
            SQL);
        DB::unprepared(file_get_contents(database_path('migration-sql/0001_01_01_000730_create_registration_email_proofs/catalog_seal.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $recovery$
            BEGIN
                RAISE EXCEPTION 'Registration proofs and account recovery require forward recovery.';
            END;
            $recovery$;
            SQL);
    }
};
