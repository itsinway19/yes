-- No new Workspace relationships. Recover exact historical receipts, or end a qualified prior relationship.
CREATE OR REPLACE FUNCTION builderad_v2_business_link_command(p_session text,p_business uuid,p_workspace uuid,p_command uuid,p_effect text,p_expected bigint,p_selection jsonb,p_kind text DEFAULT 'collaboration') RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint; actor bigint; w bigint; snapshot jsonb; payload bytea; l public.business_workspace_links; prior public.business_workspace_links;
 f public.business_authority_fences; old_f public.business_authority_fences; result jsonb; r public.business_command_receipts; changed boolean:=false;
BEGIN
 IF p_kind IS NULL OR p_kind NOT IN('collaboration','agency') OR p_command IS NULL OR p_effect NOT IN ('add','end') OR p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Typed relationship command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true); actor:=builderad_private.principal(p_session,false);
 SELECT id INTO w FROM public.workspaces WHERE public_id=p_workspace;
 IF w IS NULL OR jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact collaboration target and Business authority required' USING ERRCODE='42501'; END IF;
 snapshot:=builderad_private.require_scope('business',b::text,actor,'business.link.manage',p_selection,p_session,'{}',ARRAY[w]);
 PERFORM builderad_private.principal(p_session);
 IF (SELECT lifecycle FROM public.businesses WHERE id=b) IN('suspended','archived') OR(p_kind='agency' AND snapshot#>>'{qualification,issuer_kind}'='self_declared_draft') THEN RAISE EXCEPTION 'Qualified agency relationship authority required' USING ERRCODE='42501'; END IF;
 IF w IS NULL OR NOT builderad_private.workspace_allowed(w,actor,true) THEN RAISE EXCEPTION 'Qualified collaboration authority required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'workspace',p_workspace,'effect',p_effect,'expected',p_expected,'kind',p_kind),
 '{"business":"U","workspace":"U","expected":"I"}');
 SELECT * INTO r FROM public.business_command_receipts WHERE command_id=p_command;
 IF FOUND THEN
  IF r.actor_user_id<>actor OR r.business_id<>b OR r.action<>'business.link.manage' OR r.payload_digest<>payload THEN RAISE EXCEPTION 'Command identity conflict' USING ERRCODE='40001'; END IF;
  RETURN r.result_ref;
 END IF;
 IF p_effect='add' THEN RAISE EXCEPTION 'Workspace relationship creation retired; use explicit Business mandates and resource rights' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.link.manage',jsonb_build_object('business_id',b,'workspace_id',w),snapshot);
 -- The logical tuple keeps its CAS watermark across independent relationship identities.
 -- Resetting to zero/one on recreation lets an old prepared end target a different relationship.
 SELECT * INTO prior FROM public.business_workspace_links WHERE business_id=b AND workspace_id=w AND kind=p_kind ORDER BY revision DESC,link_id DESC LIMIT 1 FOR UPDATE;
 IF coalesce(prior.revision,0)<>p_expected THEN RAISE EXCEPTION 'Relationship changed before effect' USING ERRCODE='B4090'; END IF;
 l:=prior;
 IF prior.status='active' THEN
  l.status:='ended';l.revision:=prior.revision+1;l.valid_until:=clock_timestamp();
  PERFORM builderad_private.permit('public.business_workspace_links','update',to_jsonb(prior),to_jsonb(l));
  UPDATE public.business_workspace_links SET status=l.status,revision=l.revision,valid_until=l.valid_until WHERE link_id=l.link_id;changed:=true;
 END IF;
 IF changed THEN
  SELECT * INTO old_f FROM public.business_authority_fences WHERE business_id=b;f:=old_f;f.auth_epoch:=f.auth_epoch+1;
  PERFORM builderad_private.permit('public.business_authority_fences','update',to_jsonb(old_f),to_jsonb(f));
  UPDATE public.business_authority_fences SET auth_epoch=f.auth_epoch WHERE business_id=b;
 END IF;
 result:=jsonb_build_object('business_public_id',p_business,'workspace_public_id',p_workspace,'kind',p_kind,'state','ended',
 'versions',jsonb_build_object('link',coalesce(l.revision,0)));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.link.manage',payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.link.manage',p_selection,p_session);
 IF NOT builderad_private.workspace_allowed(w,actor,true) THEN RAISE EXCEPTION 'Collaboration authority changed' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
