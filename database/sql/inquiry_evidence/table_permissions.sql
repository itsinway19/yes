DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['inquiry_evidence_enrollments','inquiry_evidence_heads','inquiry_evidence_revisions'] LOOP
  EXECUTE format('REVOKE ALL ON public.%I FROM PUBLIC,builderad_app,builderad_authority',t);
  EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY',t);
  EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY',t);
  EXECUTE format('CREATE POLICY inquiry_owner ON public.%I TO builderad_owner USING(true) WITH CHECK(true)',t);
  EXECUTE format('CREATE POLICY inquiry_executor_read ON public.%I FOR SELECT TO builderad_scope_executor USING(true)',t);
  EXECUTE format('CREATE POLICY inquiry_executor_insert ON public.%I FOR INSERT TO builderad_scope_executor WITH CHECK(builderad_private.effect_allowed(%L::regclass,''insert'',to_jsonb(%I.*)))',t,'public.'||t,t);
  EXECUTE format('GRANT SELECT,INSERT ON public.%I TO builderad_scope_executor',t);
  IF t<>'inquiry_evidence_revisions' THEN
   EXECUTE format('CREATE POLICY inquiry_executor_update ON public.%I FOR UPDATE TO builderad_scope_executor USING(true) WITH CHECK(builderad_private.effect_allowed(%L::regclass,''update'',to_jsonb(%I.*)))',t,'public.'||t,t);
   EXECUTE format('GRANT UPDATE ON public.%I TO builderad_scope_executor',t);
  END IF;
  EXECUTE 'SET LOCAL ROLE builderad_scope_executor';
  EXECUTE format('CREATE TRIGGER zz_inquiry_effect BEFORE INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_effect()',t);
  EXECUTE format('CREATE TRIGGER zz_inquiry_truncate BEFORE TRUNCATE ON public.%I FOR EACH STATEMENT EXECUTE FUNCTION builderad_private.guard_effect()',t);
  EXECUTE 'RESET ROLE';
 END LOOP;
END $$;
