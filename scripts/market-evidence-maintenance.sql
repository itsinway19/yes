-- Dedicated deployment tool. Supply a separate psql service/credential profile;
-- never call this from Laravel, a web worker or the runtime credential profile.
-- Required psql variables: command_id, action, source_ref, expected_revision, payload.
-- psql's :'variable' form quotes values as SQL literals; no caller SQL is executed.
\set ON_ERROR_STOP on
BEGIN;
SELECT session_user = 'builderad_owner' AS market_maintenance_authorized \gset
\if :market_maintenance_authorized
SELECT builderad_v2_market_admit(
    :'command_id'::uuid, :'action', :'source_ref'::uuid,
    :'expected_revision'::bigint, :'payload'::jsonb
);
\else
DO $$ BEGIN RAISE EXCEPTION 'Isolated deployment operator required'; END $$;
\endif
COMMIT;
