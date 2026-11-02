DO $$ DECLARE f record; BEGIN
 FOR f IN SELECT n.nspname,p.oid::regprocedure AS signature,p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE(n.nspname='builderad_private' AND p.proname LIKE 'inquiry_%') OR(n.nspname='public' AND p.proname LIKE 'builderad_v2_inquiry_%') LOOP
  EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC,builderad_app,builderad_authority',f.signature);
  IF f.nspname='public' THEN EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO builderad_app',f.signature); END IF;
 END LOOP;
END $$;
