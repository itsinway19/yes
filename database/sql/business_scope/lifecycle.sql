CREATE FUNCTION builderad_v2_business_lifecycle_command(p_session text,p_business uuid,p_command uuid,p_effect text,p_target uuid,p_payload jsonb,p_expected bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE b bigint;actor bigint;action_name text;proof jsonb;payload bytea;result jsonb;prior public.businesses;x public.businesses;
 old_branch public.business_branches;r public.business_branches;changed boolean:=false;
BEGIN
 IF p_command IS NULL OR p_effect IS NULL OR p_effect NOT IN('suspend','archive','restore','branch_create','branch_archive') OR p_expected IS NULL OR p_expected<0 OR p_payload IS NULL OR jsonb_typeof(p_payload)<>'object' OR octet_length(p_payload::text)>1024 THEN RAISE EXCEPTION 'Typed lifecycle action required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 SELECT * INTO STRICT prior FROM public.businesses WHERE id=b;
 action_name:=CASE WHEN p_effect='archive' AND prior.origin_kind='self_declared_draft' THEN 'business.draft.archive'
  WHEN p_effect IN('branch_create','branch_archive') THEN 'business.branch.manage' ELSE 'business.lifecycle.manage' END;
 IF jsonb_array_length(p_selection->'requirements')<>1 THEN RAISE EXCEPTION 'Exact lifecycle authority selection required' USING ERRCODE='42501'; END IF;
 proof:=builderad_private.require_scope('business',b::text,actor,action_name,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 IF p_effect='branch_create' THEN
  IF p_target IS NOT NULL OR p_expected<>0 OR p_payload-ARRAY['label']<>'{}'::jsonb OR jsonb_typeof(p_payload->'label') IS DISTINCT FROM 'string'
   OR length(btrim(p_payload->>'label')) NOT BETWEEN 1 AND 120 OR octet_length(p_payload->>'label')>480 THEN RAISE EXCEPTION 'Bounded new branch identity required' USING ERRCODE='22023'; END IF;
 ELSE
  IF p_payload<>'{}'::jsonb OR(p_effect='branch_archive')<>(p_target IS NOT NULL) THEN RAISE EXCEPTION 'Exact lifecycle target required' USING ERRCODE='22023'; END IF;
 END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('effect',p_effect,'target',p_target,'payload',p_payload,'expected',p_expected),'{"target":"U","expected":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.lifecycle.'||p_effect,payload);IF result IS NOT NULL THEN RETURN result; END IF;
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.lifecycle.'||p_effect,jsonb_build_object('business_id',b),proof);
 IF p_effect IN('branch_create','branch_archive') THEN
  IF prior.lifecycle IN('suspended','archived') THEN RAISE EXCEPTION 'Current Business required for branch identity' USING ERRCODE='42501'; END IF;
  IF p_effect='branch_create' THEN
   r.id:=nextval('public.business_branches_id_seq');r.public_id:=uuidv7();r.business_id:=b;r.label:=btrim(p_payload->>'label');r.status:='active';r.revision:=1;
   PERFORM builderad_private.permit('public.business_branches','insert',NULL,to_jsonb(r));INSERT INTO public.business_branches SELECT(r).*;changed:=true;
  ELSE
   SELECT * INTO old_branch FROM public.business_branches WHERE public_id=p_target AND business_id=b FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'Branch identity denied' USING ERRCODE='42501'; END IF;
   IF old_branch.revision<>p_expected THEN RAISE EXCEPTION 'Branch changed' USING ERRCODE='40001'; END IF;r:=old_branch;
   IF r.status<>'archived' THEN
    r.status:='archived';r.revision:=r.revision+1;
    PERFORM builderad_private.permit('public.business_branches','update',to_jsonb(old_branch),to_jsonb(r));
    UPDATE public.business_branches SET status=r.status,revision=r.revision WHERE id=r.id;changed:=true;
   END IF;
  END IF;
  result:=jsonb_build_object('business_public_id',p_business,'branch_public_id',r.public_id,'status',r.status,'versions',jsonb_build_object('branch',r.revision));
 ELSE
  IF prior.lifecycle_revision<>p_expected THEN RAISE EXCEPTION 'Business lifecycle changed' USING ERRCODE='40001'; END IF;
  x:=prior;
  IF action_name='business.draft.archive' AND prior.lifecycle NOT IN('declared_draft','archived') THEN RAISE EXCEPTION 'Private draft archive authority only' USING ERRCODE='42501'; END IF;
  x.lifecycle:=CASE p_effect WHEN 'archive' THEN 'archived' WHEN 'suspend' THEN 'suspended' WHEN 'restore' THEN CASE prior.origin_kind WHEN 'self_declared_draft' THEN 'declared_draft' ELSE 'active' END END;
  changed:=x.lifecycle<>prior.lifecycle;
  IF changed THEN
   x.lifecycle_revision:=prior.lifecycle_revision+1;
   PERFORM builderad_private.permit('public.businesses','update',to_jsonb(prior),to_jsonb(x));UPDATE public.businesses SET lifecycle=x.lifecycle,lifecycle_revision=x.lifecycle_revision WHERE id=b;
  END IF;
  result:=jsonb_build_object('business_public_id',p_business,'state',x.lifecycle,'versions',jsonb_build_object('lifecycle',x.lifecycle_revision));
 END IF;
 IF changed THEN PERFORM builderad_private.advance_business_epoch(b); END IF;
 PERFORM builderad_private.require_scope('business',b::text,actor,action_name,p_selection,p_session);PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'business.lifecycle.'||p_effect,payload,result,CASE WHEN changed THEN 'committed' ELSE 'no_material_change' END);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
