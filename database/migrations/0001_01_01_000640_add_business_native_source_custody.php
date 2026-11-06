<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Verify the installed630 catalog before changing any object or seal.
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'BUSINESS_SOURCE_CUSTODY_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::unprepared(file_get_contents(database_path('sql/business_native_sources/schema.sql')));
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        foreach (['integrity', 'authority'] as $part) {
            DB::unprepared(file_get_contents(database_path("sql/business_native_sources/{$part}.sql")));
        }
        foreach (['source_custody_integrity()', 'require_business_source_creator(bigint,bigint,text,jsonb,boolean)', 'business_source_grant_current(bigint,bigint,text,boolean)',
            'business_source_proof(bigint,bigint,text,boolean)', 'business_source_require(text,uuid,text,text,text,text)'] as $function) {
            DB::statement("ALTER FUNCTION builderad_private.{$function} OWNER TO builderad_scope_executor");
        }
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        foreach (['authority', 'scope_authority', 'source_rights', 'source_adoption', 'surfaces', 'purpose_retirement'] as $part) {
            DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path("sql/business_scope/{$part}.sql"))));
        }
        DB::statement('RESET ROLE');
        // 420 creates these private functions as the installer, revokes their
        // default PUBLIC ACL, then transfers ownership. On an initialized
        // catalog only the actual executor owner may replace them; choose that
        // role here rather than changing420's permission-installation context.
        // The shared file restores the installer before the public native entry.
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path('sql/business_scope/source_facts.sql'))));
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/business_native_sources/bootstrap.sql')));
        DB::unprepared(file_get_contents(database_path('sql/business_native_sources/audit.sql')));
        DB::statement('RESET ROLE');
        // Reload definitions only, in their accepted operational order. In
        // particular, the final credential function retains Snapchat renewal denial.
        foreach (['provider_runtime/provider_access', 'provider_runtime/provider_observation', 'provider_request_gate',
            'provider_read_prerequisites', 'provider_runtime/meta_read_attempts', 'google_connection_credential',
            'provider_runtime/google_read_attempts', 'provider_runtime/tiktok_read_attempts',
            'provider_runtime/snapchat_read_attempts', 'snapchat_connection_credential',
            'provider_runtime/snapchat_renewal_failures', 'provider_runtime/x_read_attempts', 'business_native_sources/providers'] as $part) {
            DB::unprepared(file_get_contents(database_path("sql/{$part}.sql")));
        }
        DB::unprepared(file_get_contents(database_path('sql/business_native_sources/permissions.sql')));
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Source custody, credentials and historical receipts require forward recovery.');
    }
};
