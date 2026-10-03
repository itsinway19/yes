-- Narrow Founder-only customer administration. No tenant content or provider credentials.
ALTER TABLE workspaces DROP CONSTRAINT workspaces_status_check;
ALTER TABLE workspaces ADD CONSTRAINT workspaces_status_check CHECK(status IN ('active','archived','suspended'));
ALTER TABLE workspaces ADD COLUMN administration_revision bigint NOT NULL DEFAULT 0;

CREATE TABLE customer_administration_events (
 command_id uuid PRIMARY KEY,
 actor_user_id bigint NOT NULL REFERENCES users(id),
 subject_user_id bigint REFERENCES users(id),
 workspace_id bigint REFERENCES workspaces(id),
 decision text NOT NULL CHECK(decision IN ('suspend','reactivate')),
 reason varchar(128) NOT NULL CHECK(length(trim(reason))>0),
 expected_status text NOT NULL,
 expected_revision bigint NOT NULL,
 prior_status text NOT NULL,
 new_status text NOT NULL,
 new_revision bigint NOT NULL,
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK((subject_user_id IS NULL) <> (workspace_id IS NULL))
);
CREATE INDEX customer_administration_user_history ON customer_administration_events(subject_user_id,occurred_at DESC);
CREATE INDEX customer_administration_workspace_history ON customer_administration_events(workspace_id,occurred_at DESC);
ALTER TABLE customer_administration_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_administration_events FORCE ROW LEVEL SECURITY;
CREATE POLICY customer_events_owner ON customer_administration_events TO builderad_owner USING(true) WITH CHECK(true);
CREATE TRIGGER customer_events_immutable BEFORE UPDATE OR DELETE ON customer_administration_events
 FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();

CREATE FUNCTION builderad_customer_administrator(p_session text DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint;
BEGIN
 IF session_user <> 'builderad_app' THEN RAISE EXCEPTION 'Runtime required' USING ERRCODE='42501'; END IF;
 SELECT c.actor_user_id INTO actor FROM builderad_signed_context() c WHERE c.phase='principal' AND c.workspace_id IS NULL;
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
 IF p_kind IS NULL OR p_search IS NULL OR p_status IS NULL OR p_page IS NULL OR p_kind NOT IN ('users','workspaces') OR p_page<1 OR p_page>100000 OR length(p_search)>120 THEN
  RAISE EXCEPTION 'Invalid directory filter' USING ERRCODE='22023'; END IF;
 IF p_kind='users' THEN
  SELECT count(*) INTO total FROM users u JOIN principal_lifecycles l ON l.user_id=u.id
   WHERE l.principal_class='advertiser' AND (p_search='' OR position(lower(p_search) IN lower(u.name||' '||u.email))>0)
    AND (p_status='' OR l.status=p_status);
  SELECT coalesce(jsonb_agg(to_jsonb(r)),'[]') INTO rows FROM (
   SELECT u.public_id,u.name,u.email,u.email_verified_at,l.status,l.generation AS revision,
    (SELECT count(*) FROM workspace_memberships m WHERE m.user_id=u.id AND m.status='active') AS workspace_count,
    EXISTS(SELECT 1 FROM customer_administration_events e WHERE e.subject_user_id=u.id
      AND e.decision='suspend' AND e.new_revision=l.generation AND l.status='disabled') AS can_reactivate
   FROM users u JOIN principal_lifecycles l ON l.user_id=u.id WHERE l.principal_class='advertiser'
    AND (p_search='' OR position(lower(p_search) IN lower(u.name||' '||u.email))>0) AND (p_status='' OR l.status=p_status)
   ORDER BY u.name,u.id LIMIT 25 OFFSET (p_page-1)*25) r;
  SELECT jsonb_build_object('public_id',u.public_id,'name',u.name,'email',u.email,
   'workspaces',coalesce((SELECT jsonb_agg(jsonb_build_object('public_id',w.public_id,'name',w.name,'status',w.status,'role',m.role))
    FROM workspace_memberships m JOIN workspaces w ON w.id=m.workspace_id WHERE m.user_id=u.id AND m.status='active'),'[]'),
   'history',coalesce((SELECT jsonb_agg(to_jsonb(h)) FROM (SELECT e.decision,e.reason,e.occurred_at,a.name AS actor_name
    FROM customer_administration_events e JOIN users a ON a.id=e.actor_user_id WHERE e.subject_user_id=u.id ORDER BY occurred_at DESC LIMIT 20) h),'[]'))
   INTO details FROM users u JOIN principal_lifecycles l ON l.user_id=u.id WHERE u.public_id=p_selected AND l.principal_class='advertiser';
 ELSE
  SELECT count(*) INTO total FROM workspaces w WHERE (p_search='' OR position(lower(p_search) IN lower(w.name))>0
   OR EXISTS(SELECT 1 FROM workspace_ownerships wo JOIN users u ON u.id=wo.responsible_owner_user_id WHERE wo.workspace_id=w.id AND position(lower(p_search) IN lower(u.name||' '||u.email))>0)) AND (p_status='' OR w.status=p_status);
  SELECT coalesce(jsonb_agg(to_jsonb(r)),'[]') INTO rows FROM (
   SELECT w.public_id,w.name,w.status,w.administration_revision AS revision,b.name_ar AS business_type_ar,b.name_en AS business_type_en,
    o.name AS owner_name,o.email AS owner_email,
    (SELECT market.label FROM commercial_market_assignments a JOIN commercial_markets market ON market.id=a.market_id WHERE a.workspace_id=w.id) AS market_label,
    (SELECT count(*) FROM workspace_memberships m WHERE m.workspace_id=w.id AND m.status='active') AS member_count
   FROM workspaces w LEFT JOIN workspace_ownerships wo ON wo.workspace_id=w.id LEFT JOIN users o ON o.id=wo.responsible_owner_user_id
    LEFT JOIN business_types b ON b.id=w.business_type_id
   WHERE (p_search='' OR position(lower(p_search) IN lower(w.name))>0 OR position(lower(p_search) IN lower(coalesce(o.name,'')||' '||coalesce(o.email,'')))>0) AND (p_status='' OR w.status=p_status)
   ORDER BY w.name,w.id LIMIT 25 OFFSET (p_page-1)*25) r;
  SELECT jsonb_build_object('public_id',w.public_id,'name',w.name,
   'members',coalesce((SELECT jsonb_agg(jsonb_build_object('name',u.name,'email',u.email,'role',m.role,'status',l.status))
    FROM workspace_memberships m JOIN users u ON u.id=m.user_id JOIN principal_lifecycles l ON l.user_id=u.id
    WHERE m.workspace_id=w.id AND m.status='active'),'[]'),
   'history',coalesce((SELECT jsonb_agg(to_jsonb(h)) FROM (SELECT e.decision,e.reason,e.occurred_at,a.name AS actor_name
    FROM customer_administration_events e JOIN users a ON a.id=e.actor_user_id WHERE e.workspace_id=w.id ORDER BY occurred_at DESC LIMIT 20) h),'[]'))
   INTO details FROM workspaces w WHERE w.public_id=p_selected;
 END IF;
 RETURN jsonb_build_object('rows',rows,'total',total,'details',details);
END $$;
REVOKE ALL ON FUNCTION builderad_customer_directory(text,text,text,integer,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_customer_directory(text,text,text,integer,uuid) TO builderad_app;

CREATE FUNCTION builderad_customer_status(p_session text,p_command uuid,p_kind text,p_target uuid,p_decision text,p_reason text,p_status text,p_revision bigint)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE actor bigint; subject bigint; workspace bigint; current_status text; current_revision bigint;
 next_status text; original customer_administration_events%ROWTYPE; verified boolean;
BEGIN
 actor:=builderad_customer_administrator(p_session);
 IF p_session IS NULL OR p_kind IS NULL OR p_decision IS NULL OR p_status IS NULL OR p_kind NOT IN ('users','workspaces') OR p_decision NOT IN ('suspend','reactivate') OR p_command IS NULL
  OR p_target IS NULL OR p_revision IS NULL OR p_revision<0 OR p_reason IS NULL OR length(trim(p_reason))=0 OR length(p_reason)>128 THEN
  RAISE EXCEPTION 'Invalid status decision' USING ERRCODE='22023'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('customer-admin:'||p_command::text,0));
 IF p_kind='users' THEN
  SELECT l.user_id,l.status,l.generation,u.email_verified_at IS NOT NULL INTO subject,current_status,current_revision,verified
   FROM principal_lifecycles l JOIN users u ON u.id=l.user_id WHERE u.public_id=p_target AND l.principal_class='advertiser' FOR UPDATE OF l,u;
 ELSE
  SELECT id,status,administration_revision INTO workspace,current_status,current_revision FROM workspaces WHERE public_id=p_target FOR UPDATE;
 END IF;
 IF subject IS NULL AND workspace IS NULL THEN RAISE EXCEPTION 'Customer not found' USING ERRCODE='P0002'; END IF;
 SELECT * INTO original FROM customer_administration_events WHERE command_id=p_command;
 IF FOUND THEN
  IF original.actor_user_id<>actor OR original.subject_user_id IS DISTINCT FROM subject OR original.workspace_id IS DISTINCT FROM workspace
   OR original.decision<>p_decision OR original.reason<>p_reason OR original.expected_status<>p_status OR original.expected_revision<>p_revision THEN
   RAISE EXCEPTION 'Command changed' USING ERRCODE='40001'; END IF;
  RETURN jsonb_build_object('status',original.new_status,'revision',original.new_revision,'replayed',true);
 END IF;
 IF current_status<>p_status OR current_revision<>p_revision THEN RAISE EXCEPTION 'State changed; reload' USING ERRCODE='40001'; END IF;
 IF p_decision='suspend' THEN
  IF (subject IS NOT NULL AND current_status NOT IN ('current','pending')) OR (workspace IS NOT NULL AND current_status<>'active') THEN
   RAISE EXCEPTION 'Cannot suspend this state' USING ERRCODE='22023'; END IF;
  next_status:=CASE WHEN subject IS NOT NULL THEN 'disabled' ELSE 'suspended' END;
 ELSE
  IF subject IS NOT NULL THEN
   SELECT * INTO original FROM customer_administration_events WHERE subject_user_id=subject AND decision='suspend' AND new_revision=current_revision;
   IF NOT FOUND OR current_status<>'disabled' THEN RAISE EXCEPTION 'Not an administrative suspension' USING ERRCODE='22023'; END IF;
   next_status:=CASE WHEN verified THEN 'current' ELSE 'pending' END;
  ELSE
   IF current_status<>'suspended' THEN RAISE EXCEPTION 'Not suspended' USING ERRCODE='22023'; END IF;
   next_status:='active';
  END IF;
 END IF;
 IF subject IS NOT NULL THEN
  UPDATE principal_lifecycles SET status=next_status,generation=generation+1,cause=CASE WHEN next_status='disabled' THEN 'administrative_suspension' WHEN next_status='pending' THEN 'primary_email_unverified' ELSE NULL END,updated_at=clock_timestamp() WHERE user_id=subject;
  UPDATE security_sessions SET revoked_at=clock_timestamp() WHERE user_id=subject AND revoked_at IS NULL;
  IF next_status='current' THEN
   INSERT INTO security_action_grants(subject_user_id,granted_by,source,action,scope_kind,subject_generation,reason,status,version,created_at,updated_at)
    VALUES(subject,NULL,'system_registration','workspace.create','platform',current_revision+1,'verified_advertiser_registration','active',1,clock_timestamp(),clock_timestamp());
  END IF;
  INSERT INTO security_audit_events(actor_user_id,subject_user_id,event,reason,prior_status,new_status,prior_generation,new_generation,occurred_at)
   VALUES(actor,subject,'customer_'||p_decision,p_reason,current_status,next_status,current_revision,current_revision+1,clock_timestamp());
 ELSE
  UPDATE workspaces SET status=next_status,administration_revision=administration_revision+1,updated_at=clock_timestamp() WHERE id=workspace;
 END IF;
 INSERT INTO customer_administration_events(command_id,actor_user_id,subject_user_id,workspace_id,decision,reason,expected_status,expected_revision,prior_status,new_status,new_revision)
  VALUES(p_command,actor,subject,workspace,p_decision,p_reason,p_status,p_revision,current_status,next_status,current_revision+1);
 PERFORM builderad_customer_administrator(p_session);
 RETURN jsonb_build_object('status',next_status,'revision',current_revision+1,'replayed',false);
END $$;
REVOKE ALL ON FUNCTION builderad_customer_status(text,uuid,text,uuid,text,text,text,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_customer_status(text,uuid,text,uuid,text,text,text,bigint) TO builderad_app;
