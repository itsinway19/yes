<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared("DO $$ BEGIN IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN RAISE EXCEPTION 'PURPOSE_RETIREMENT_BASELINE_REQUIRED' USING ERRCODE='42501'; END IF; END $$;");
        DB::statement('RESET ROLE');
        DB::statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(file_get_contents(database_path('sql/business_scope/purpose_retirement.sql')));
        DB::statement('RESET ROLE');
        // The finite historical writer executes under the same effect owner as
        // Business; do not grant the migration owner the generic permit issuer.
        DB::statement('GRANT EXECUTE ON FUNCTION builderad_object_purpose_actor(bigint,text,boolean) TO builderad_scope_executor');
        DB::statement('SET LOCAL ROLE builderad_scope_executor');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION builderad_v2_legacy_purpose_history_revise(w bigint,s text,c jsonb) RETURNS jsonb
            LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public,builderad_private,pg_temp AS $$
            DECLARE actor bigint;result jsonb;
            BEGIN
             IF coalesce(c->>'operation','') NOT IN('correct','withdraw') THEN RAISE EXCEPTION 'WORKSPACE_PURPOSE_DECLARATION_RETIRED' USING ERRCODE='42501'; END IF;
             PERFORM builderad_private.legacy_admitted(w);
             actor:=public.builderad_object_purpose_actor(w,s,true);
             PERFORM pg_advisory_xact_lock(hashtextextended('builderad.object-purpose.'||w,0));
             actor:=public.builderad_object_purpose_actor(w,s,true);
             result:=builderad_private.apply_object_purpose(w,NULL,actor,c);
             PERFORM public.builderad_object_purpose_actor(w,s,true);
             PERFORM builderad_private.legacy_admitted(w);
             RETURN result;
            END $$;
            REVOKE ALL ON FUNCTION builderad_v2_legacy_purpose_history_revise(bigint,text,jsonb) FROM PUBLIC,builderad_authority;
            GRANT EXECUTE ON FUNCTION builderad_v2_legacy_purpose_history_revise(bigint,text,jsonb) TO builderad_app;
            SQL);
        DB::statement('RESET ROLE');
        DB::statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
        DB::statement('DROP FUNCTION builderad_object_purpose_write(bigint,text,jsonb) RESTRICT');
        DB::unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Historical Purpose rights require forward recovery, never restored legacy declarations.');
    }
};
