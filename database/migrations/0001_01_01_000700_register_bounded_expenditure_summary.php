<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'EXPENDITURE_SUMMARY_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_private.guard_effect() TO builderad_owner');
        DB::statement('RESET ROLE');
        DB::unprepared(<<<'SQL'
CREATE TABLE business_expenditure_summaries (
 summary_id uuid PRIMARY KEY,business_id bigint NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
 processor_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 output_scope_id uuid NOT NULL UNIQUE REFERENCES data_use_scopes(scope_id) ON DELETE RESTRICT,
 purpose text NOT NULL CHECK(length(purpose) BETWEEN 1 AND 128),material_digest bytea NOT NULL CHECK(octet_length(material_digest)=32),
 input_reads jsonb NOT NULL CHECK(jsonb_array_length(input_reads)=1),input_selection jsonb NOT NULL,
 consent jsonb NOT NULL CHECK(consent='{"derive":true,"deliver_summary":true,"retain_payload":false}'::jsonb),
 valid_until timestamptz NOT NULL,publication_manifest_id uuid REFERENCES material_lineage_manifests(manifest_id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL
);
REVOKE ALL ON business_expenditure_summaries FROM PUBLIC,builderad_app,builderad_authority;
ALTER TABLE business_expenditure_summaries ENABLE ROW LEVEL SECURITY;
ALTER TABLE business_expenditure_summaries FORCE ROW LEVEL SECURITY;
CREATE POLICY expenditure_summary_owner ON business_expenditure_summaries TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY expenditure_summary_read ON business_expenditure_summaries FOR SELECT TO builderad_scope_executor USING(true);
CREATE POLICY expenditure_summary_insert ON business_expenditure_summaries FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed('public.business_expenditure_summaries','insert',to_jsonb(business_expenditure_summaries.*)));
CREATE POLICY expenditure_summary_update ON business_expenditure_summaries FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed('public.business_expenditure_summaries','update',to_jsonb(business_expenditure_summaries.*)));
GRANT SELECT,INSERT,UPDATE ON business_expenditure_summaries TO builderad_scope_executor;
CREATE TRIGGER zz_expenditure_summary_effect BEFORE INSERT OR UPDATE OR DELETE ON business_expenditure_summaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect();
CREATE TRIGGER zz_expenditure_summary_truncate BEFORE TRUNCATE ON business_expenditure_summaries FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect();
SQL);
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('GRANT TRIGGER ON business_expenditure_summaries TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path('sql/expenditure_summary.sql'))));
        DB::unprepared('CREATE TRIGGER expenditure_summary_immutable BEFORE UPDATE ON public.business_expenditure_summaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_expenditure_summary()');
        $sourceAuthority = file_get_contents(database_path('sql/business_native_sources/authority.sql'));
        $sourceStart = strpos($sourceAuthority, 'CREATE FUNCTION builderad_private.business_source_basis_current(');
        $sourceEnd = strpos($sourceAuthority, 'CREATE FUNCTION builderad_private.business_source_proof(');
        if ($sourceStart === false || $sourceEnd === false || $sourceEnd <= $sourceStart) {
            throw new RuntimeException('Canonical Source basis and signed-session composition required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($sourceAuthority, $sourceStart, $sourceEnd - $sourceStart)));
        DB::unprepared(file_get_contents(database_path('sql/market_knowledge/dispatch.sql')));
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', file_get_contents(database_path('sql/data_license_beneficiaries.sql'))));
        $licensing = file_get_contents(database_path('sql/data_use_licensing.sql'));
        $licensingStart = strpos($licensing, 'CREATE FUNCTION builderad_v2_data_use_license(');
        if ($licensingStart === false) {
            throw new RuntimeException('Canonical original-consent withdrawal required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($licensing, $licensingStart)));
        $journey = file_get_contents(database_path('sql/data_license_journey.sql'));
        $journeyStart = strpos($journey, 'CREATE FUNCTION builderad_v2_data_license_journey(');
        if ($journeyStart === false) {
            throw new RuntimeException('Canonical licence journey required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($journey, $journeyStart)));
        $surfaces = file_get_contents(database_path('sql/business_scope/surfaces.sql'));
        $choices = strpos($surfaces, 'CREATE FUNCTION builderad_v2_data_use_choices(');
        if ($choices === false) {
            throw new RuntimeException('Canonical data-use choices required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($surfaces, $choices)));
        $authority = file_get_contents(database_path('sql/business_scope/scope_authority.sql'));
        $end = strpos($authority, 'CREATE FUNCTION builderad_private.lock_authority_footprint(');
        if ($end === false) {
            throw new RuntimeException('Canonical complete authority footprint required.');
        }
        DB::unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', substr($authority, 0, $end)));
        DB::statement('REVOKE EXECUTE ON FUNCTION builderad_private.guard_effect() FROM builderad_owner');
        DB::statement('RESET ROLE');
        DB::statement('REVOKE TRIGGER ON business_expenditure_summaries FROM builderad_scope_executor');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Expenditure consent and lineage require forward recovery.');
    }
};
