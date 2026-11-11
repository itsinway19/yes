-- Run atomically as the deployment owner after PostgreSQL/tzdata/name-policy changes, before traffic.
DO $operator$
BEGIN
    IF session_user<>'builderad_owner' OR current_user<>'builderad_owner' THEN
        RAISE EXCEPTION 'Timezone reference refresh requires the deployment owner' USING ERRCODE='42501';
    END IF;
END;
$operator$;
SET LOCAL ROLE builderad_scope_executor;
DO $sealed$
BEGIN
    IF builderad_private.legacy_catalog_digest() IS DISTINCT FROM builderad_private.legacy_installation_digest() THEN
        RAISE EXCEPTION 'TIMEZONE_REFERENCE_SEALED_INSTALLATION_REQUIRED' USING ERRCODE='42501';
    END IF;
END;
$sealed$;
RESET ROLE;
INSERT INTO public.current_intent_timezone_catalog(id,names,postgres_version,captured_at)
SELECT 1,jsonb_agg(name ORDER BY name COLLATE "C"),current_setting('server_version_num')::integer,clock_timestamp()
FROM(SELECT DISTINCT name FROM pg_catalog.pg_timezone_names) native_names
ON CONFLICT(id) DO UPDATE SET names=EXCLUDED.names,postgres_version=EXCLUDED.postgres_version,captured_at=EXCLUDED.captured_at;
