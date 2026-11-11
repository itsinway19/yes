-- Native names are an environment reference. Offsets, dates and write admission remain native.
CREATE TABLE public.current_intent_timezone_catalog (
    id smallint PRIMARY KEY CHECK (id=1),
    names jsonb NOT NULL CHECK (jsonb_typeof(names)='array' AND jsonb_array_length(names)>0),
    postgres_version integer NOT NULL,
    captured_at timestamptz NOT NULL
);
REVOKE ALL ON public.current_intent_timezone_catalog FROM PUBLIC,builderad_app,builderad_authority,builderad_scope_executor;
GRANT SELECT ON public.current_intent_timezone_catalog TO builderad_app,builderad_scope_executor;

CREATE FUNCTION public.builderad_current_intent_timezone_names() RETURNS TABLE(name text)
LANGUAGE plpgsql STABLE SECURITY INVOKER SET search_path=pg_catalog,public,pg_temp AS $names$
DECLARE reference public.current_intent_timezone_catalog;
BEGIN
    SELECT * INTO reference FROM public.current_intent_timezone_catalog WHERE id=1;
    IF NOT FOUND OR reference.postgres_version<>current_setting('server_version_num')::integer THEN
        RAISE EXCEPTION 'CURRENT_INTENT_TIMEZONE_REFERENCE_REFRESH_REQUIRED' USING ERRCODE='55000';
    END IF;
    RETURN QUERY SELECT value FROM jsonb_array_elements_text(reference.names);
END;
$names$;
REVOKE ALL ON FUNCTION public.builderad_current_intent_timezone_names() FROM PUBLIC,builderad_app,builderad_authority;
GRANT EXECUTE ON FUNCTION public.builderad_current_intent_timezone_names() TO builderad_app,builderad_scope_executor;

SET LOCAL ROLE builderad_scope_executor;
CREATE OR REPLACE FUNCTION public.builderad_v2_business_catalog(p_session text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $catalog$
DECLARE actor bigint; result jsonb;
BEGIN
 actor:=builderad_private.principal(p_session);
 SELECT jsonb_build_object('business_types',coalesce(jsonb_agg(jsonb_build_object('id',id,'key',key,'name_ar',name_ar,'name_en',name_en,'active',active,'version',version) ORDER BY id),'[]'::jsonb)) INTO result FROM public.business_types;
 SELECT result||jsonb_build_object('countries',coalesce(jsonb_agg(jsonb_build_object('code',code,'name_ar',name_ar,'name_en',name_en) ORDER BY code COLLATE "C"),'[]'::jsonb)) INTO result FROM public.country_references;
 SELECT result||jsonb_build_object('intent_timezones',coalesce(jsonb_agg(name ORDER BY name COLLATE "C"),'[]'::jsonb)) INTO result
 FROM(SELECT DISTINCT name FROM public.builderad_current_intent_timezone_names() WHERE public.builderad_current_intent_timezone_name_allowed(name)) accepted;
 PERFORM builderad_private.principal(p_session);
 RETURN result;
END;
$catalog$;
RESET ROLE;
