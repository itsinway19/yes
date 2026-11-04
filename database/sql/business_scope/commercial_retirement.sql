-- Current facts have one owner: Business. Keep original rows, UUIDs, journals
-- and grants intact; neither membership nor a name selects a target Business.
CREATE FUNCTION builderad_private.retired_workspace_fact_effect() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF TG_TABLE_NAME='workspaces' THEN
  IF TG_OP='INSERT' THEN
   IF NEW.business_type_id IS NULL AND NEW.business_context_version=0 AND NEW.current_intent IS NULL THEN RETURN NEW; END IF;
  ELSIF TG_OP='UPDATE' THEN
   IF OLD.business_type_id IS NOT DISTINCT FROM NEW.business_type_id
    AND OLD.business_context_version=NEW.business_context_version AND OLD.current_intent IS NOT DISTINCT FROM NEW.current_intent THEN RETURN NEW; END IF;
  END IF;
 END IF;
 RAISE EXCEPTION 'WORKSPACE_COMMERCIAL_WRITER_RETIRED' USING ERRCODE='42501';
END $$;
REVOKE ALL ON FUNCTION builderad_private.retired_workspace_fact_effect() FROM PUBLIC,builderad_app,builderad_authority;
CREATE TRIGGER aa_retired_workspace_facts BEFORE INSERT OR UPDATE OR DELETE ON public.workspaces
 FOR EACH ROW EXECUTE FUNCTION builderad_private.retired_workspace_fact_effect();
DO $install$ DECLARE table_name text;
BEGIN
 FOREACH table_name IN ARRAY ARRAY['business_context_events','business_target_markets','business_target_market_events',
  'business_current_intent_events','advertiser_goal_preferences','advertiser_goal_preference_events'] LOOP
  EXECUTE format('CREATE TRIGGER aa_retired_commercial_write BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.retired_workspace_fact_effect()',table_name);
 END LOOP;
END $install$;
-- Remove callable implementations, not merely their navigation or ACL.
DROP FUNCTION public.builderad_business_context_write(bigint,text,bigint,text,bigint) RESTRICT;
DROP FUNCTION public.builderad_business_target_markets_write(bigint,text,text[],bigint) RESTRICT;
DROP FUNCTION public.builderad_current_intent_write(bigint,text,jsonb,jsonb,bigint) RESTRICT;
DROP FUNCTION public.builderad_advertiser_goals_write(bigint,text,jsonb,bigint) RESTRICT;
DROP FUNCTION public.builderad_business_context_begin(bigint,text) RESTRICT;
DROP FUNCTION public.builderad_business_context_require_prepared(bigint) RESTRICT;
SET LOCAL ROLE builderad_scope_executor;
DROP FUNCTION public.builderad_v2_legacy_context_grant(bigint,text) RESTRICT;
RESET ROLE;
-- Genesis no longer issues commercial authority. Existing historical grants
-- are neither erased nor promoted into Business or source permissions.
DO $adapt$ DECLARE body text;
BEGIN
 SELECT pg_get_functiondef('public.builderad_create_workspace(bigint,text,text,text)'::regprocedure) INTO body;
 IF position('IF v_workspace_id IS NOT NULL THEN PERFORM builderad_private.legacy_creation_grant(v_workspace_id,p_actor_user_id,v_generation); END IF;' in body)=0
 THEN RAISE EXCEPTION 'Expected historical creation hook missing'; END IF;
 EXECUTE replace(body,'IF v_workspace_id IS NOT NULL THEN PERFORM builderad_private.legacy_creation_grant(v_workspace_id,p_actor_user_id,v_generation); END IF;','');
END $adapt$;
SET LOCAL ROLE builderad_scope_executor;
DROP FUNCTION builderad_private.legacy_creation_grant(bigint,bigint,bigint) RESTRICT;
RESET ROLE;

CREATE FUNCTION builderad_private.retired_workspace_commercial_grant() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF coalesce(to_jsonb(NEW)->>'scope_kind',to_jsonb(OLD)->>'scope_kind')<>'workspace'
  OR coalesce(to_jsonb(NEW)->>'action',to_jsonb(OLD)->>'action')<>'business.context.manage'
 THEN IF TG_OP='DELETE' THEN RETURN OLD;ELSE RETURN NEW;END IF;END IF;
 IF TG_OP='UPDATE' THEN
  IF to_jsonb(OLD)-'runtime_lock_marker'=to_jsonb(NEW)-'runtime_lock_marker' THEN RETURN NEW; END IF;
  IF OLD.status='active' AND NEW.status='revoked' AND NEW.revoked_at IS NOT NULL AND NEW.version=OLD.version+1
   AND to_jsonb(OLD)-ARRAY['status','revoked_at','version','updated_at','runtime_lock_marker']=to_jsonb(NEW)-ARRAY['status','revoked_at','version','updated_at','runtime_lock_marker'] THEN RETURN NEW; END IF;
 END IF;
 RAISE EXCEPTION 'WORKSPACE_COMMERCIAL_AUTHORITY_RETIRED' USING ERRCODE='42501';
END $$;
REVOKE ALL ON FUNCTION builderad_private.retired_workspace_commercial_grant() FROM PUBLIC,builderad_app,builderad_authority;
CREATE TRIGGER aa_retired_workspace_commercial_grant BEFORE INSERT OR UPDATE OR DELETE ON public.security_action_grants
 FOR EACH ROW EXECUTE FUNCTION builderad_private.retired_workspace_commercial_grant();
