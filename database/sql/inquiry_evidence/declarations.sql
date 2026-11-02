CREATE FUNCTION builderad_v2_inquiry_write(p_session text,p_business uuid,p_command uuid,p_declaration uuid,p_expected bigint,p_effect text,p_value jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;payload bytea;result jsonb;action_name text;head public.inquiry_evidence_heads;old_head public.inquiry_evidence_heads;v public.inquiry_evidence_revisions;
BEGIN
 IF p_command IS NULL OR p_expected IS NULL OR p_effect IS NULL OR p_effect NOT IN('record','correct','withdraw')
 OR(p_effect='record' AND(p_declaration IS NOT NULL OR p_expected<>0)) OR(p_effect<>'record' AND(p_declaration IS NULL OR p_expected<1))
 OR(p_effect='withdraw' AND p_value IS NOT NULL)
 THEN RAISE EXCEPTION 'Exact bounded declaration command required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 action_name:=CASE WHEN p_effect='record' THEN 'business.inquiry_evidence.record' ELSE 'business.inquiry_evidence.correct' END;
 proof:=builderad_private.inquiry_fresh(builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session),actor,p_session);
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('business',p_business,'declaration',p_declaration,'expected',p_expected,'effect',p_effect,'value',p_value),'{"business":"U","declaration":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'inquiry.'||p_effect,payload);
 IF result IS NOT NULL THEN
  PERFORM builderad_private.inquiry_fresh(builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session),actor,p_session);RETURN result;
 END IF;
 IF p_effect<>'withdraw' THEN p_value:=builderad_private.inquiry_value(p_value); END IF;
 IF p_effect<>'record' THEN
  SELECT * INTO head FROM public.inquiry_evidence_heads WHERE declaration_id=p_declaration AND business_id=b AND issuer_user_id=actor;
  IF head.declaration_id IS NULL THEN RAISE EXCEPTION 'Inquiry declaration unavailable' USING ERRCODE='42501'; END IF;
  IF head.revision<>p_expected THEN RAISE EXCEPTION 'Declaration revision conflict' USING ERRCODE='I4090'; END IF;
  IF EXISTS(SELECT 1 FROM public.inquiry_evidence_revisions WHERE declaration_id=head.declaration_id AND revision=head.revision AND state='withdrawn') THEN RAISE EXCEPTION 'Withdrawn declaration cannot be rewritten' USING ERRCODE='I4090'; END IF;
 END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'inquiry.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect='record' THEN
  head.declaration_id:=uuidv7();head.business_id:=b;head.issuer_user_id:=actor;head.revision:=1;head.created_at:=clock_timestamp();
  PERFORM builderad_private.permit('public.inquiry_evidence_heads','insert',NULL,to_jsonb(head));INSERT INTO public.inquiry_evidence_heads SELECT(head).*;
 ELSE
  old_head:=head;head.revision:=head.revision+1;
  PERFORM builderad_private.permit('public.inquiry_evidence_heads','update',to_jsonb(old_head),to_jsonb(head));UPDATE public.inquiry_evidence_heads SET revision=head.revision WHERE declaration_id=head.declaration_id;
 END IF;
 v.declaration_id:=head.declaration_id;v.revision:=head.revision;v.actor_user_id:=actor;v.qualification_id:=(proof#>>'{qualification,qualification_id}')::uuid;v.command_id:=p_command;
 v.state:=CASE WHEN p_effect='withdraw' THEN 'withdrawn' ELSE 'source_asserted' END;v.reason:=CASE p_effect WHEN 'record' THEN 'initial' WHEN 'correct' THEN 'correction' ELSE 'withdrawal' END;v.value:=p_value;v.recorded_at:=clock_timestamp();
 PERFORM builderad_private.permit('public.inquiry_evidence_revisions','insert',NULL,to_jsonb(v));INSERT INTO public.inquiry_evidence_revisions SELECT(v).*;
 -- An effect acknowledgement is NOT a display grant. Never echo protected values through a write-only action.
 result:=jsonb_build_object('command_id',p_command,'declaration_id',head.declaration_id,'revision',head.revision,'state',v.state,'assurance','SOURCE_ASSERTED','independently_verified',false,'versions',jsonb_build_object('declaration',head.revision));
 PERFORM builderad_private.record_command(b,actor,p_command,'inquiry.'||p_effect,payload,result);
 PERFORM builderad_private.inquiry_require(b,actor,action_name,p_selection,p_session);PERFORM builderad_private.inquiry_fresh(proof,actor,p_session);
 PERFORM builderad_private.principal(p_session,false);PERFORM builderad_private.finish_command();RETURN result;
END $$;
CREATE FUNCTION builderad_v2_inquiry_read(p_session text,p_business uuid,p_selection jsonb,p_declaration uuid DEFAULT NULL,p_after bigint DEFAULT 0,p_cursor uuid DEFAULT NULL) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;proof jsonb;items jsonb;more boolean;cursor_value bigint;collection_cursor uuid;
BEGIN
 IF p_after IS NULL OR p_after<0 OR(p_declaration IS NULL AND p_after<>0) OR(p_declaration IS NOT NULL AND p_cursor IS NOT NULL) THEN RAISE EXCEPTION 'Bounded history cursor required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.inquiry_require(b,actor,'business.inquiry_evidence.read',p_selection,p_session);
 IF p_declaration IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.inquiry_evidence_heads WHERE declaration_id=p_declaration AND business_id=b AND issuer_user_id=actor)
 THEN RAISE EXCEPTION 'Inquiry declaration unavailable' USING ERRCODE='42501'; END IF;
 IF p_declaration IS NULL THEN
  -- Bounded private current collection; no public count, source search or cross-actor aggregation.
  SELECT coalesce(jsonb_agg(entry ORDER BY declaration_key DESC),'[]'::jsonb) INTO items FROM(
   SELECT h.declaration_id AS declaration_key,jsonb_build_object('declaration_id',h.declaration_id,'revision',v.revision,'state',v.state,'reason',v.reason,'value',v.value,'recorded_at',v.recorded_at,'original_issuer',u.public_id,'correcting_actor',c.public_id) AS entry
   FROM public.inquiry_evidence_heads h JOIN public.inquiry_evidence_revisions v ON v.declaration_id=h.declaration_id AND v.revision=h.revision
   JOIN public.users u ON u.id=h.issuer_user_id JOIN public.users c ON c.id=v.actor_user_id
   WHERE h.business_id=b AND h.issuer_user_id=actor AND(p_cursor IS NULL OR h.declaration_id<p_cursor) ORDER BY h.declaration_id DESC LIMIT 25
  ) scoped;
  collection_cursor:=CASE WHEN jsonb_array_length(items)>0 THEN(items#>>ARRAY[(jsonb_array_length(items)-1)::text,'declaration_id'])::uuid ELSE NULL END;
  more:=collection_cursor IS NOT NULL AND EXISTS(SELECT 1 FROM public.inquiry_evidence_heads WHERE business_id=b AND issuer_user_id=actor AND declaration_id<collection_cursor);
  cursor_value:=NULL;
 ELSE
  SELECT coalesce(jsonb_agg(entry ORDER BY revision_key),'[]'::jsonb),max(revision_key) INTO items,cursor_value FROM(
   SELECT v.revision AS revision_key,jsonb_build_object('declaration_id',v.declaration_id,'revision',v.revision,'state',v.state,'reason',v.reason,'value',v.value,'recorded_at',v.recorded_at,'original_issuer',u.public_id,'correcting_actor',c.public_id) AS entry
   FROM public.inquiry_evidence_revisions v JOIN public.inquiry_evidence_heads h ON h.declaration_id=v.declaration_id
   JOIN public.users u ON u.id=h.issuer_user_id JOIN public.users c ON c.id=v.actor_user_id
   WHERE h.business_id=b AND h.issuer_user_id=actor AND v.declaration_id=p_declaration AND v.revision>p_after ORDER BY v.revision LIMIT 25
  ) scoped;
  more:=EXISTS(SELECT 1 FROM public.inquiry_evidence_revisions WHERE declaration_id=p_declaration AND revision>coalesce(cursor_value,p_after));
 END IF;
 PERFORM builderad_private.inquiry_require(b,actor,'business.inquiry_evidence.read',p_selection,p_session);PERFORM builderad_private.principal(p_session,false);
 RETURN jsonb_build_object('business',p_business,'mode','own_private_statement','assurance','SOURCE_ASSERTED','independently_verified',false,
 'items',items,'more',more,'next_declaration',CASE WHEN p_declaration IS NULL AND more THEN collection_cursor ELSE NULL END,'next_revision',CASE WHEN p_declaration IS NOT NULL AND more THEN cursor_value ELSE NULL END,'collection_limit',25);
END $$;
