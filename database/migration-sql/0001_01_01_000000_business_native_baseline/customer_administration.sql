SET LOCAL search_path=public,pg_catalog;
CREATE FUNCTION builderad_customer_administrator(p_session text DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint;
BEGIN
 IF session_user <> 'builderad_app' THEN RAISE EXCEPTION 'Runtime required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM builderad_signed_context() c WHERE c.phase='principal';
 PERFORM 1 FROM principal_lifecycles WHERE user_id=actor FOR SHARE;
 PERFORM 1 FROM users WHERE id=actor FOR SHARE;
 PERFORM 1 FROM internal_personnel WHERE user_id=actor FOR SHARE;
 IF NOT EXISTS(SELECT 1 FROM principal_lifecycles l JOIN users u ON u.id=l.user_id
  JOIN internal_personnel p ON p.user_id=l.user_id
  WHERE l.user_id=actor AND l.status='current' AND l.principal_class='staff'
   AND u.email_verified_at IS NOT NULL AND p.kind='founder' AND p.status='active') THEN
  RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501';
 END IF;
 IF p_session IS NOT NULL THEN
  PERFORM 1 FROM security_sessions WHERE user_id=actor AND session_key_hash=p_session FOR SHARE;
  IF NOT EXISTS(SELECT 1 FROM security_sessions s JOIN principal_lifecycles l ON l.user_id=s.user_id
   WHERE s.user_id=actor AND s.session_key_hash=p_session AND s.lifecycle_generation=l.generation
    AND s.auth_method='internal_passkey' AND s.revoked_at IS NULL
    AND s.idle_expires_at>clock_timestamp() AND s.absolute_expires_at>clock_timestamp()
    AND s.authenticated_at BETWEEN clock_timestamp()-interval '5 minutes' AND clock_timestamp()) THEN
   RAISE EXCEPTION 'Fresh Founder session required' USING ERRCODE='42501';
  END IF;
 END IF;
 RETURN actor;
END $$;
REVOKE ALL ON FUNCTION builderad_customer_administrator(text) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_customer_directory(p_kind text,p_search text,p_status text,p_page integer,p_selected uuid DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE rows jsonb; total bigint; details jsonb;
BEGIN
 PERFORM builderad_customer_administrator();
 IF p_kind IS NULL OR p_search IS NULL OR p_status IS NULL OR p_page IS NULL OR p_kind NOT IN ('users','businesses') OR p_page<1 OR p_page>100000 OR length(p_search)>120 THEN
  RAISE EXCEPTION 'Invalid directory filter' USING ERRCODE='22023'; END IF;
 IF p_kind='users' THEN
  SELECT count(*) INTO total FROM users u JOIN principal_lifecycles l ON l.user_id=u.id
   WHERE l.principal_class='advertiser' AND (p_search='' OR position(lower(p_search) IN lower(u.name||' '||u.email))>0)
    AND (p_status='' OR l.status=p_status);
  SELECT coalesce(jsonb_agg(to_jsonb(r)),'[]') INTO rows FROM (
   SELECT u.public_id,u.name,u.email,u.email_verified_at,l.status,l.generation AS revision,
    (SELECT count(*) FROM businesses b WHERE b.created_by=u.id) AS business_count,
    EXISTS(SELECT 1 FROM customer_administration_events e WHERE e.subject_user_id=u.id
      AND e.decision='suspend' AND e.new_revision=l.generation AND l.status='disabled') AS can_reactivate
   FROM users u JOIN principal_lifecycles l ON l.user_id=u.id WHERE l.principal_class='advertiser'
    AND (p_search='' OR position(lower(p_search) IN lower(u.name||' '||u.email))>0) AND (p_status='' OR l.status=p_status)
   ORDER BY u.name,u.id LIMIT 25 OFFSET (p_page-1)*25) r;
  SELECT jsonb_build_object('public_id',u.public_id,'name',u.name,'email',u.email,
   'businesses',coalesce((SELECT jsonb_agg(jsonb_build_object('public_id',b.public_id,'name',h.display_name,'status',b.lifecycle,'role','creator') ORDER BY b.id) FROM businesses b JOIN business_context_heads h ON h.business_id=b.id WHERE b.created_by=u.id),'[]'),
   'history',coalesce((SELECT jsonb_agg(to_jsonb(h)) FROM (SELECT e.decision,e.reason,e.occurred_at,a.name AS actor_name
    FROM customer_administration_events e JOIN users a ON a.id=e.actor_user_id WHERE e.subject_user_id=u.id ORDER BY occurred_at DESC LIMIT 20) h),'[]'))
   INTO details FROM users u JOIN principal_lifecycles l ON l.user_id=u.id WHERE u.public_id=p_selected AND l.principal_class='advertiser';
 ELSE
  SELECT count(*) INTO total FROM businesses b JOIN business_context_heads h ON h.business_id=b.id JOIN users u ON u.id=b.created_by
   WHERE (p_search='' OR position(lower(p_search) IN lower(h.display_name||' '||u.name||' '||u.email))>0) AND (p_status='' OR b.lifecycle=p_status);
  SELECT coalesce(jsonb_agg(to_jsonb(r)),'[]') INTO rows FROM (
   SELECT b.public_id,h.display_name AS name,b.lifecycle AS status,b.lifecycle_revision AS revision,u.name AS creator_name,u.email AS creator_email
   FROM businesses b JOIN business_context_heads h ON h.business_id=b.id JOIN users u ON u.id=b.created_by
   WHERE (p_search='' OR position(lower(p_search) IN lower(h.display_name||' '||u.name||' '||u.email))>0) AND (p_status='' OR b.lifecycle=p_status)
   ORDER BY h.display_name,b.id LIMIT 25 OFFSET (p_page-1)*25) r;
  SELECT jsonb_build_object('public_id',b.public_id,'name',h.display_name,
   'history',coalesce((SELECT jsonb_agg(to_jsonb(event)) FROM (
    SELECT e.decision,e.reason,e.occurred_at,a.name AS actor_name FROM customer_administration_events e
    JOIN users a ON a.id=e.actor_user_id WHERE e.business_id=b.id ORDER BY e.occurred_at DESC LIMIT 20) event),'[]'))
  INTO details FROM businesses b JOIN business_context_heads h ON h.business_id=b.id WHERE b.public_id=p_selected;
 END IF;
 RETURN jsonb_build_object('rows',rows,'total',total,'details',details);
END $$;
REVOKE ALL ON FUNCTION builderad_customer_directory(text,text,text,integer,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_customer_directory(text,text,text,integer,uuid) TO builderad_app;

CREATE FUNCTION builderad_customer_status(p_session text,p_command uuid,p_kind text,p_target uuid,p_decision text,p_reason text,p_status text,p_revision bigint)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint; subject bigint; business bigint; current_status text; current_revision bigint;
 next_status text; original customer_administration_events%ROWTYPE; verified boolean;
BEGIN
 IF p_kind='businesses' THEN
  SELECT id INTO business FROM businesses WHERE public_id=p_target;
  PERFORM 1 FROM migration_cohort_control c JOIN migration_cohort_members m ON m.cohort_id=c.cohort_id WHERE m.business_id=business FOR SHARE OF c;
  PERFORM 1 FROM business_authority_fences WHERE business_id=business FOR UPDATE;
 END IF;
 actor:=builderad_customer_administrator(p_session);
 IF p_session IS NULL OR p_kind IS NULL OR p_decision IS NULL OR p_status IS NULL OR p_kind NOT IN ('users','businesses') OR p_decision NOT IN ('suspend','reactivate') OR p_command IS NULL
  OR p_target IS NULL OR p_revision IS NULL OR p_revision<0 OR p_reason IS NULL OR length(trim(p_reason))=0 OR length(p_reason)>128 THEN
  RAISE EXCEPTION 'Invalid status decision' USING ERRCODE='22023'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('customer-admin:'||p_command::text,0));
 IF p_kind='users' THEN
  SELECT l.user_id,l.status,l.generation,u.email_verified_at IS NOT NULL INTO subject,current_status,current_revision,verified
   FROM principal_lifecycles l JOIN users u ON u.id=l.user_id WHERE u.public_id=p_target AND l.principal_class='advertiser' FOR UPDATE OF l,u;
 ELSE
  SELECT id,lifecycle,lifecycle_revision INTO business,current_status,current_revision FROM businesses WHERE id=business FOR UPDATE;
 END IF;
 IF subject IS NULL AND business IS NULL THEN RAISE EXCEPTION 'Customer not found' USING ERRCODE='P0002'; END IF;
 SELECT * INTO original FROM customer_administration_events WHERE command_id=p_command;
 IF FOUND THEN
  IF original.actor_user_id<>actor OR original.subject_user_id IS DISTINCT FROM subject OR original.business_id IS DISTINCT FROM business
   OR original.decision<>p_decision OR original.reason<>p_reason OR original.expected_status<>p_status OR original.expected_revision<>p_revision THEN
   RAISE EXCEPTION 'Command changed' USING ERRCODE='40001'; END IF;
  RETURN jsonb_build_object('status',original.new_status,'revision',original.new_revision,'replayed',true);
 END IF;
 IF current_status<>p_status OR current_revision<>p_revision THEN RAISE EXCEPTION 'State changed; reload' USING ERRCODE='40001'; END IF;
 IF p_decision='suspend' THEN
  IF (subject IS NOT NULL AND current_status NOT IN ('current','pending')) OR (business IS NOT NULL AND current_status NOT IN ('declared_draft','active')) THEN
   RAISE EXCEPTION 'Cannot suspend this state' USING ERRCODE='22023'; END IF;
  next_status:=CASE WHEN subject IS NOT NULL THEN 'disabled' ELSE 'suspended' END;
 ELSE
  IF subject IS NOT NULL THEN
   SELECT * INTO original FROM customer_administration_events WHERE subject_user_id=subject AND decision='suspend' AND new_revision=current_revision;
   IF NOT FOUND OR current_status<>'disabled' THEN RAISE EXCEPTION 'Not an administrative suspension' USING ERRCODE='22023'; END IF;
   next_status:=CASE WHEN verified THEN 'current' ELSE 'pending' END;
  ELSE
   IF current_status<>'suspended' THEN RAISE EXCEPTION 'Not suspended' USING ERRCODE='22023'; END IF;
   SELECT prior_status INTO next_status FROM customer_administration_events WHERE business_id=business AND decision='suspend' AND new_revision=current_revision;
   IF next_status IS NULL OR next_status NOT IN ('declared_draft','active') THEN RAISE EXCEPTION 'Trusted suspension state unavailable' USING ERRCODE='42501'; END IF;
  END IF;
 END IF;
 IF subject IS NOT NULL THEN
  UPDATE principal_lifecycles SET status=next_status,generation=generation+1,cause=CASE WHEN next_status='disabled' THEN 'administrative_suspension' WHEN next_status='pending' THEN 'primary_email_unverified' ELSE NULL END,updated_at=clock_timestamp() WHERE user_id=subject;
  UPDATE security_sessions SET revoked_at=clock_timestamp() WHERE user_id=subject AND revoked_at IS NULL;
  INSERT INTO security_audit_events(actor_user_id,subject_user_id,event,reason,prior_status,new_status,prior_generation,new_generation,occurred_at)
   VALUES(actor,subject,'customer_'||p_decision,p_reason,current_status,next_status,current_revision,current_revision+1,clock_timestamp());
 ELSE
  PERFORM builderad_private.admin_business_status(actor,p_session,p_command,business,next_status);
 END IF;
 INSERT INTO customer_administration_events(command_id,actor_user_id,subject_user_id,business_id,decision,reason,expected_status,expected_revision,prior_status,new_status,new_revision)
  VALUES(p_command,actor,subject,business,p_decision,p_reason,p_status,p_revision,current_status,next_status,current_revision+1);
 PERFORM builderad_customer_administrator(p_session);
 RETURN jsonb_build_object('status',next_status,'revision',current_revision+1,'replayed',false);
END $$;
REVOKE ALL ON FUNCTION builderad_customer_status(text,uuid,text,uuid,text,text,text,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_customer_status(text,uuid,text,uuid,text,text,text,bigint) TO builderad_app;
CREATE FUNCTION builderad_private.admin_business_status(p_actor bigint,p_session text,p_command uuid,p_business bigint,p_state text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE old_business public.businesses; new_business public.businesses; proof jsonb;
BEGIN
 IF p_actor IS DISTINCT FROM public.builderad_customer_administrator(p_session) THEN
  RAISE EXCEPTION 'Current customer administrator required' USING ERRCODE='42501';
 END IF;
 SELECT * INTO STRICT old_business FROM public.businesses WHERE id=p_business FOR UPDATE;
 new_business:=old_business;
 new_business.lifecycle:=p_state;
 new_business.lifecycle_revision:=old_business.lifecycle_revision+1;
 proof:=jsonb_build_object('customer_administration',true);
 PERFORM builderad_private.start_command(p_actor,p_session,p_command,'customer.business.status',jsonb_build_object('business_id',p_business),proof);
 PERFORM builderad_private.permit('public.businesses','update',to_jsonb(old_business),to_jsonb(new_business));
 UPDATE public.businesses SET lifecycle=p_state,lifecycle_revision=new_business.lifecycle_revision WHERE id=p_business;
 PERFORM builderad_private.advance_business_epoch(p_business);
 PERFORM builderad_private.finish_command();
END $$;
ALTER FUNCTION builderad_private.admin_business_status(bigint,text,uuid,bigint,text) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION builderad_private.admin_business_status(bigint,text,uuid,bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION builderad_private.admin_business_status(bigint,text,uuid,bigint,text) TO builderad_owner;
GRANT EXECUTE ON FUNCTION public.builderad_customer_administrator(text) TO builderad_scope_executor;
