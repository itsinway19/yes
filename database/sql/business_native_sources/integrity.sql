-- Nullable legacy metadata never selects custody. The canonical Source does.
CREATE FUNCTION builderad_private.source_custody_integrity() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE source_ref uuid;expected_workspace bigint;source_kind text;actual_workspace bigint;
BEGIN
 IF TG_TABLE_NAME='source_registry' THEN
  IF NEW.custody_kind IS DISTINCT FROM OLD.custody_kind OR NEW.business_id IS DISTINCT FROM OLD.business_id
   OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id OR NEW.id IS DISTINCT FROM OLD.id
  THEN RAISE EXCEPTION 'Source custody is immutable' USING ERRCODE='42501'; END IF;
  RETURN NEW;
 END IF;
 actual_workspace:=NEW.workspace_id;
 IF TG_TABLE_NAME='source_audit_delivery' THEN
  SELECT o.source_id,o.workspace_id INTO source_ref,expected_workspace FROM public.source_audit_obligations o WHERE o.id=NEW.obligation_id;
  IF NOT FOUND OR actual_workspace IS DISTINCT FROM expected_workspace THEN
   RAISE EXCEPTION 'Exact source audit custody required' USING ERRCODE='23514';
  END IF;
 ELSE source_ref:=NEW.source_id; END IF;
 SELECT s.workspace_id,s.custody_kind INTO expected_workspace,source_kind FROM public.source_registry s WHERE s.id=source_ref;
 IF NOT FOUND OR source_kind NOT IN('workspace_native','business_native') OR actual_workspace IS DISTINCT FROM expected_workspace THEN
  RAISE EXCEPTION 'Child custody must match its canonical native Source' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION builderad_private.source_custody_integrity() FROM PUBLIC,builderad_app,builderad_authority;
CREATE TRIGGER source_custody_immutable BEFORE UPDATE ON public.source_registry
 FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity();
DO $children$
DECLARE child text;
BEGIN
 FOREACH child IN ARRAY ARRAY['source_capabilities','source_command_receipts','source_versions','source_audit_obligations',
 'source_audit_delivery','provider_access','provider_access_receipts','provider_observation_streams','snapchat_renewal_failures'] LOOP
  EXECUTE format('CREATE TRIGGER source_custody_integrity BEFORE INSERT OR UPDATE ON public.%I FOR EACH ROW EXECUTE FUNCTION builderad_private.source_custody_integrity()',child);
 END LOOP;
END $children$;
