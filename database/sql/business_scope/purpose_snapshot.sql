CREATE FUNCTION builderad_v2_business_purpose_snapshot(p_session text,p_business uuid,p_read bigint,p_purpose uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;selection jsonb;context_version bigint;heads jsonb;result jsonb;now_value timestamptz;proof jsonb;history_available boolean;
BEGIN
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);selection:=builderad_private.business_selection(p_read);
 IF NOT EXISTS(SELECT 1 FROM public.security_action_grants WHERE id=p_read AND business_id=b AND subject_user_id=actor AND action='business.context.read') THEN RAISE EXCEPTION 'Exact own Business read path required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_business(b,actor,'business.context.read',selection,p_session);PERFORM builderad_private.principal(p_session);
 history_available:=selection->>'realm_kind'<>'internal_team' AND proof#>>'{qualification,policy_id}' IS DISTINCT FROM 'business_declaration_team_admin_v1';
 SELECT h.context_version INTO context_version FROM public.business_context_heads h WHERE business_id=b FOR SHARE;
 PERFORM 1 FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose) ORDER BY id FOR SHARE;
 IF (SELECT count(*) FROM public.object_purposes WHERE business_id=b AND(p_purpose IS NULL OR id=p_purpose))>64 THEN RAISE EXCEPTION 'Select exact Purpose; bounded projection unavailable' USING ERRCODE='22023'; END IF;
 IF p_purpose IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.object_purposes WHERE business_id=b AND id=p_purpose) THEN RAISE EXCEPTION 'Purpose unavailable in this Business' USING ERRCODE='42501'; END IF;
 now_value:=clock_timestamp();
 -- Current record authority never releases the temporal journal to the app.
 -- The last accepted transition starting by now cuts all earlier segments,
 -- including when it withdraws or has already reached its exclusive end.
 SELECT coalesce(jsonb_agg(jsonb_build_object('id',h.id,'head_version',h.head_version,'history',
  CASE WHEN history_available THEN (SELECT coalesce(jsonb_agg((to_jsonb(r)-ARRAY['workspace_id','business_id','actor_user_id','actor_generation','ownership_version','business_authority_epoch','command_id']) ORDER BY r.version),'[]') FROM public.object_purpose_revisions r WHERE r.business_id=b AND r.purpose_id=h.id) ELSE '[]'::jsonb END,
  'current',CASE WHEN NOT history_available THEN (SELECT jsonb_build_object('purpose_id',r.purpose_id,'revision_id',r.revision_id,'version',r.version,
   'operation',r.operation,'effective_from',r.effective_from,'effective_until',r.effective_until,'accepted_at',r.accepted_at,'snapshot',r.snapshot)
   FROM (SELECT * FROM public.object_purpose_revisions WHERE business_id=b AND purpose_id=h.id AND accepted_at<=now_value AND effective_from<=now_value ORDER BY version DESC LIMIT 1) r
   WHERE r.operation<>'withdraw' AND(r.effective_until IS NULL OR now_value<r.effective_until)) ELSE NULL END) ORDER BY h.id),'[]') INTO heads
 FROM public.object_purposes h WHERE h.business_id=b AND(p_purpose IS NULL OR h.id=p_purpose);
 result:=jsonb_build_object('business_public_id',p_business,'heads',heads,'history_available',history_available,'context_version',context_version,'dtm_version',builderad_private.purpose_latest_markets(NULL,b),'scope','native_business_purposes_only');
 IF octet_length(result::text)>2097152 THEN RAISE EXCEPTION 'Complete Purpose projection exceeds bound' USING ERRCODE='22023'; END IF;
 PERFORM builderad_private.principal(p_session);PERFORM builderad_private.require_business(b,actor,'business.context.read',selection,p_session);
 RETURN result||jsonb_build_object('read_at',now_value);
END $$;
