-- A Business sees only source metadata that its current operator may manage in
-- an explicitly linked Workspace. Source custody and customer-data rights stay
-- with their existing owners; this read creates no source-to-Business grant.
CREATE FUNCTION builderad_v2_business_source_choices(p_session text,p_business uuid,p_read bigint,p_after bigint DEFAULT 0) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE native_choices jsonb; business_projection jsonb; item jsonb; matched jsonb:='[]'::jsonb;
 source_row public.source_registry; workspace_public uuid; workspace_slug text;
BEGIN
 IF p_after IS NULL OR p_after<0 OR p_read IS NULL OR p_read<1 THEN
  RAISE EXCEPTION 'Exact Business source read required' USING ERRCODE='22023';
 END IF;
 -- Native source admission gates are acquired before the Business authority
 -- fence. Neither read returns its data to the caller until both qualify.
 native_choices:=public.builderad_v2_source_choices(p_session,p_after);
 business_projection:=public.builderad_v2_business_read(p_session,p_business,builderad_private.business_selection(p_read));
 FOR item IN SELECT value FROM jsonb_array_elements(native_choices->'sources') LOOP
  SELECT * INTO source_row FROM public.source_registry WHERE id=(item->>'source_id')::uuid;
  IF NOT FOUND THEN CONTINUE; END IF;
  SELECT public_id,slug INTO workspace_public,workspace_slug FROM public.workspaces WHERE id=source_row.workspace_id;
  IF NOT FOUND OR NOT EXISTS(
   SELECT 1 FROM jsonb_array_elements(business_projection->'links') link
   WHERE link->>'workspace_public_id'=workspace_public::text
  ) THEN CONTINUE; END IF;
  matched:=matched||jsonb_build_array(item||jsonb_build_object(
   'family',source_row.family,'namespace',source_row.namespace,
   'workspace_public_id',workspace_public,'workspace_slug',workspace_slug));
 END LOOP;
 RETURN jsonb_build_object('business_public_id',p_business,'sources',matched,
  'next_cursor',native_choices->'next_cursor','scope','current_operator_in_linked_workspaces');
END $$;
