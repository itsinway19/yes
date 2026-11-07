-- Owner-approved declared-authority admission. This issues no Business or Source right.
-- Initial operating recipient is the declaring actor; onward licensing is not inferred.
CREATE FUNCTION builderad_private.guard_declared_data_license() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE descriptor jsonb;boundary_ref uuid;business_ref uuid;
BEGIN
 IF TG_TABLE_NAME='client_data_boundaries' THEN
  IF OLD.descriptor->>'policy_id'='declared_data_license_v1' AND
   ((to_jsonb(NEW)-'status'-'revision') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'revision')
    OR OLD.status<>'active' OR NEW.status<>'archived' OR NEW.revision<>OLD.revision+1)
  THEN RAISE EXCEPTION 'Declared licence identity is immutable; withdrawal is final' USING ERRCODE='42501'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.status<>'active' THEN RETURN NEW; END IF;
 IF TG_TABLE_NAME='boundary_subjects' THEN boundary_ref:=NEW.boundary_id;
 ELSE SELECT boundary_id INTO boundary_ref FROM public.data_use_scopes WHERE scope_id=NEW.data_use_scope_id; END IF;
 SELECT b.descriptor INTO descriptor FROM public.client_data_boundaries b WHERE b.boundary_id=boundary_ref;
 IF descriptor->>'policy_id'='declared_data_license_v1' THEN
  SELECT public_id INTO business_ref FROM public.businesses WHERE id=NEW.business_id;
  IF business_ref::text IS DISTINCT FROM descriptor->>'business_public_id'
  THEN RAISE EXCEPTION 'Declared licence is restricted to its exact Business' USING ERRCODE='42501'; END IF;
 END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION builderad_private.guard_declared_data_license() FROM PUBLIC,builderad_app,builderad_authority;
CREATE TRIGGER declared_license_identity BEFORE UPDATE ON public.client_data_boundaries FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();
CREATE TRIGGER declared_license_subject BEFORE INSERT OR UPDATE ON public.boundary_subjects FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();
CREATE TRIGGER declared_license_binding BEFORE INSERT OR UPDATE ON public.business_data_use_bindings FOR EACH ROW EXECUTE FUNCTION builderad_private.guard_declared_data_license();

CREATE FUNCTION builderad_v2_data_use_license(p_session text,p_business uuid,p_command uuid,p_license uuid,p_effect text,p_expected bigint,p_declaration jsonb,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;manager public.security_action_grants;source_row public.source_registry;
 s public.data_use_scopes;boundary_row public.client_data_boundaries;f public.resource_right_fences;
 q public.authority_qualification_receipts;g public.security_action_grants;prior_q public.authority_qualification_receipts;prior_g public.security_action_grants;
 proof jsonb;business_proof jsonb;source_proof jsonb;payload bytea;result jsonb;selected bigint;matches integer;
 expiry timestamptz;authenticated timestamptz;boundary_ref uuid;boundary_grant bigint;use_grant bigint;actor_public uuid;
 grant_kind text;target_ref uuid;action_name text;gate record;gate_count integer:=0;
BEGIN
 IF p_command IS NULL OR p_business IS NULL OR p_license IS NULL OR uuid_extract_version(p_license) IS DISTINCT FROM 7 OR p_expected IS NULL
 OR p_effect IS NULL OR p_effect NOT IN('issue','withdraw') OR p_declaration IS NULL OR jsonb_typeof(p_declaration)<>'object'
 OR octet_length(p_declaration::text)>65536 THEN RAISE EXCEPTION 'Exact bounded data licence command required' USING ERRCODE='22023'; END IF;
 actor:=builderad_private.principal(p_session,false);
 SELECT public_id INTO actor_public FROM public.users WHERE id=actor;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','declared_data_license','business',p_business,'license',p_license,'effect',p_effect,
  'expected',p_expected,'declaration',p_declaration,'selection',p_selection),'{"business":"U","license":"U","expected":"I"}');
 IF p_effect='issue' THEN
  IF p_expected<>0 OR (SELECT array_agg(k ORDER BY k COLLATE "C") FROM jsonb_object_keys(p_declaration) k) IS DISTINCT FROM
   ARRAY['authority_reference','capacity','confirmed','holder_name','operation','purpose','recipient_public_id','requires_review','resource','selection','source_id','valid_until']::text[]
   OR p_declaration->'confirmed' IS DISTINCT FROM 'true'::jsonb OR p_declaration->'requires_review' IS DISTINCT FROM 'false'::jsonb
   OR jsonb_typeof(p_declaration->'capacity') IS DISTINCT FROM 'string' OR p_declaration->>'capacity' NOT IN('data_holder','authorized_representative')
   OR jsonb_typeof(p_declaration->'holder_name') IS DISTINCT FROM 'string' OR length(btrim(p_declaration->>'holder_name')) NOT BETWEEN 2 AND 160
   OR jsonb_typeof(p_declaration->'authority_reference') IS DISTINCT FROM 'string' OR length(btrim(p_declaration->>'authority_reference')) NOT BETWEEN 8 AND 512
   OR p_declaration->>'operation' IS NULL OR p_declaration->>'operation' NOT IN('retained_read','display','process','reuse','combine','export')
   OR jsonb_typeof(p_declaration->'recipient_public_id') IS DISTINCT FROM 'string' OR coalesce(p_declaration->>'recipient_public_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR jsonb_typeof(p_declaration->'resource') IS DISTINCT FROM 'string' OR jsonb_typeof(p_declaration->'purpose') IS DISTINCT FROM 'string'
   OR jsonb_typeof(p_declaration->'source_id') IS DISTINCT FROM 'string' OR coalesce(p_declaration->>'source_id','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR jsonb_typeof(p_declaration->'valid_until') IS DISTINCT FROM 'string'
  THEN RAISE EXCEPTION 'Explicit complete declared authority required; ambiguous or unsupported recipient requires review' USING ERRCODE='22023'; END IF;
  BEGIN expiry:=(p_declaration->>'valid_until')::timestamptz;
  EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow THEN RAISE EXCEPTION 'Finite future licence expiry required' USING ERRCODE='22023'; END;
  IF NOT isfinite(expiry) OR expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Finite future licence expiry required' USING ERRCODE='22023'; END IF;
  -- Canonical bounded selectors are reused; no new data-selection codec.
  PERFORM builderad_private.canonical_data_selection(p_declaration->'selection');
  IF jsonb_typeof(p_selection->'requirements') IS DISTINCT FROM 'array' OR jsonb_array_length(p_selection->'requirements')<>2
  THEN RAISE EXCEPTION 'Independent Business context and Source manager required' USING ERRCODE='42501'; END IF;
  SELECT count(*),min(grant_row.id) INTO matches,selected FROM public.security_action_grants grant_row
   JOIN jsonb_array_elements(p_selection->'requirements') selected_path ON selected_path->>'leaf_grant_id'=grant_row.id::text
   AND selected_path->>'requirement_kind'='native_source'
   WHERE grant_row.subject_user_id=actor AND grant_row.scope_kind='source' AND grant_row.action='source.manage'
   AND grant_row.source_target_id=(p_declaration->>'source_id')::uuid;
  IF matches<>1 THEN RAISE EXCEPTION 'Exact selected Source manager required' USING ERRCODE='42501'; END IF;
  SELECT * INTO f FROM public.resource_right_fences WHERE kind='source' AND source_id=(p_declaration->>'source_id')::uuid FOR UPDATE;
  IF NOT FOUND OR f.adoption_state<>'v2_adopted' THEN RAISE EXCEPTION 'SOURCE_ADOPTION_REQUIRED' USING ERRCODE='42501'; END IF;
  SELECT * INTO source_row FROM public.source_registry WHERE id=f.source_id;
  SELECT * INTO manager FROM public.security_action_grants WHERE id=selected;
  SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
  IF source_row.custody_kind='business_native' AND source_row.business_id IS DISTINCT FROM b
  THEN RAISE EXCEPTION 'Exact native source Business required' USING ERRCODE='42501'; END IF;
  b:=builderad_private.lock_business(p_business,false);
  PERFORM builderad_private.lock_authority_footprint(actor,p_selection,p_session);
  business_proof:=builderad_private.require_scope('business',b::text,actor,'business.context.read',p_selection,p_session);
  source_proof:=builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
  IF p_declaration->>'recipient_public_id' IS DISTINCT FROM actor_public::text
  THEN RAISE EXCEPTION 'Explicit recipient delegation is not inferred by declaration' USING ERRCODE='22023'; END IF;
  IF manager.source_resource IS DISTINCT FROM p_declaration->>'resource' OR (SELECT lifecycle FROM public.businesses WHERE id=b) IN('archived','suspended')
  THEN RAISE EXCEPTION 'Exact current source resource and Business required' USING ERRCODE='42501'; END IF;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(p_declaration#>'{selection,native_namespaces}') selected(namespace)
   WHERE NOT EXISTS(SELECT 1 FROM public.provider_observation_streams stream WHERE stream.namespace=selected.namespace AND stream.source_id=source_row.id
    AND stream.resource=manager.source_resource AND stream.operation=manager.source_operation AND stream.purpose=manager.source_purpose))
  OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(p_declaration#>'{selection,native_keys}') selected(identity)
   WHERE NOT EXISTS(SELECT 1 FROM public.provider_observation_entries entry JOIN public.provider_observation_streams stream USING(namespace)
    WHERE stream.source_id=source_row.id AND stream.resource=manager.source_resource AND stream.operation=manager.source_operation AND stream.purpose=manager.source_purpose
    AND p_declaration#>'{selection,native_namespaces}' ? stream.namespace AND entry.identity=selected.identity))
  THEN RAISE EXCEPTION 'Licence selector must identify the exact qualified native report scope' USING ERRCODE='42501'; END IF;
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
  IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
  THEN RAISE EXCEPTION 'Fresh explicit data authority declaration required' USING ERRCODE='42501'; END IF;
  result:=builderad_private.command_replay(b,actor,p_command,'data_license.issue',payload);
  IF result IS NOT NULL THEN RETURN result; END IF;
  IF EXISTS(SELECT 1 FROM public.data_use_scopes WHERE scope_id=p_license) THEN RAISE EXCEPTION 'Reconcile the existing immutable licence' USING ERRCODE='40001'; END IF;
  proof:=jsonb_build_object('business_context',business_proof,'source_custody',source_proof,'data_authority_declaration',p_declaration,
   'dependencies',jsonb_build_array(to_jsonb(f)),'declaration_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'));
  PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.issue',jsonb_build_object('business_id',b,'scope_id',p_license),proof);
  boundary_ref:=uuidv7();
  FOREACH grant_kind IN ARRAY ARRAY['client_data','data_use'] LOOP
   target_ref:=CASE grant_kind WHEN 'client_data' THEN boundary_ref ELSE p_license END;
   action_name:=CASE grant_kind WHEN 'client_data' THEN 'client_data.manage' ELSE 'data_use.'||(p_declaration->>'operation') END;
   q:=NULL;q.qualification_id:=uuidv7();q.policy_id:='declared_data_license_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
   q.subject_user_id:=actor;q.target_kind:=grant_kind;q.target_ref:=target_ref;q.allowed_actions:=ARRAY[action_name];
   q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=expiry;
   q.provenance:='Explicit declared data-holder/representative authority; not verified legal ownership, Business use, Source delegation, external AI/training or ad-action authority';
   PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
   IF grant_kind='client_data' THEN
    boundary_row.boundary_id:=boundary_ref;boundary_row.status:='active';boundary_row.revision:=1;boundary_row.descriptor_schema_version:=1;boundary_row.qualification_ref:=q.qualification_id;
    boundary_row.descriptor:=jsonb_build_object('policy_id','declared_data_license_v1','policy_version',1,'business_public_id',p_business,'license_id',p_license,
     'declarant_user_id',actor,'declarant_generation',(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor),
     'assurance','DECLARED_AUTHORITY','declaration',p_declaration,'command_id',p_command);
    PERFORM builderad_private.permit('public.client_data_boundaries','insert',NULL,to_jsonb(boundary_row));INSERT INTO public.client_data_boundaries SELECT(boundary_row).*;
    f:=NULL;f.fence_id:=uuidv7();f.kind:='client_boundary';f.boundary_id:=boundary_ref;f.rights_epoch:=0;f.adoption_state:='v2_adopted';
    PERFORM builderad_private.permit('public.resource_right_fences','insert',NULL,to_jsonb(f));INSERT INTO public.resource_right_fences SELECT(f).*;
   ELSE
    s.scope_id:=p_license;s.boundary_id:=boundary_ref;s.source_id:=source_row.id;s.resource:=p_declaration->>'resource';
    s.selection:=builderad_private.canonical_data_selection(p_declaration->'selection');s.selection_schema_version:=1;s.operation:=p_declaration->>'operation';s.purpose:=p_declaration->>'purpose';
    s.scope_digest:=builderad_private.data_scope_digest(boundary_ref,source_row.id,s.resource,s.operation,s.purpose,s.selection);s.status:='active';s.revision:=1;s.rights_domain:='client';
    PERFORM builderad_private.permit('public.data_use_scopes','insert',NULL,to_jsonb(s));INSERT INTO public.data_use_scopes SELECT(s).*;
   END IF;
   g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.granted_by:=NULL;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:=grant_kind;
   IF grant_kind='client_data' THEN g.boundary_id:=boundary_ref;boundary_grant:=g.id;ELSE g.data_use_scope_id:=p_license;use_grant:=g.id;END IF;
   SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
   g.reason:='Explicit bounded declared data licence';g.status:='active';g.version:=1;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;g.runtime_lock_marker:=false;
   g.qualification_id:=q.qualification_id;g.expires_at:=expiry;g.delegable:=false;g.delegation_realm_kind:=p_selection->>'realm_kind';
   SELECT id INTO g.delegation_workspace_id FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid;
   PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  END LOOP;
  PERFORM builderad_private.require_scope('business',b::text,actor,'business.context.read',p_selection,p_session);
  PERFORM builderad_private.require_native_source_grant(selected,actor,p_session,p_selection,true,true);
  IF expiry<=clock_timestamp() THEN RAISE EXCEPTION 'Licence expired before effect' USING ERRCODE='42501'; END IF;
  result:=jsonb_build_object('license_id',p_license,'boundary_id',boundary_ref,'boundary_grant_id',boundary_grant::text,'use_grant_id',use_grant::text,
   'status','active','assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('license',1));
 ELSE
  IF p_expected<>1 OR p_declaration<>'{}'::jsonb THEN RAISE EXCEPTION 'Exact original licence withdrawal required' USING ERRCODE='22023'; END IF;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=p_license;
  SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
  IF boundary_row.descriptor->>'policy_id' IS DISTINCT FROM 'declared_data_license_v1'
   OR boundary_row.descriptor->>'license_id' IS DISTINCT FROM p_license::text
   OR boundary_row.descriptor->>'business_public_id' IS DISTINCT FROM p_business::text
   OR boundary_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text
   OR boundary_row.descriptor->>'declarant_generation' IS DISTINCT FROM (SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)
  THEN RAISE EXCEPTION 'Original current data authority declarant required' USING ERRCODE='42501'; END IF;
  FOR gate IN SELECT * FROM public.resource_right_fences WHERE(kind='source' AND source_id=s.source_id) OR(kind='client_boundary' AND boundary_id=s.boundary_id) ORDER BY fence_id LOOP
   IF gate.kind='client_boundary' THEN PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR UPDATE;
   ELSE PERFORM 1 FROM public.resource_right_fences WHERE fence_id=gate.fence_id FOR SHARE;END IF;
   gate_count:=gate_count+1;
  END LOOP;
  IF gate_count<>2 THEN RAISE EXCEPTION 'Existing licence resource gates required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.principal(p_session);
  SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
  IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
  THEN RAISE EXCEPTION 'Fresh consent withdrawal required' USING ERRCODE='42501'; END IF;
  SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
  result:=builderad_private.command_replay(b,actor,p_command,'data_license.withdraw',payload);IF result IS NOT NULL THEN RETURN result; END IF;
  SELECT * INTO boundary_row FROM public.client_data_boundaries WHERE boundary_id=s.boundary_id;
  IF boundary_row.status<>'active' OR boundary_row.revision<>p_expected THEN RAISE EXCEPTION 'Licence already withdrawn or changed' USING ERRCODE='40001'; END IF;
  -- Historical licence expiry is not current authority to withdraw consent.
  -- Preserve the complete declaration in its immutable row; bind its identity/digest here.
  proof:=jsonb_build_object('declaration_identity',jsonb_build_object('boundary_id',s.boundary_id,'license_id',p_license,'declarant_user_id',actor,
   'descriptor_digest',encode(builderad_private.digest('command-payload:1',boundary_row.descriptor),'hex')),
   'withdrawal_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
   'dependencies',(SELECT jsonb_agg(to_jsonb(x) ORDER BY fence_id) FROM public.resource_right_fences x WHERE x.source_id=s.source_id OR x.boundary_id=s.boundary_id));
  PERFORM builderad_private.start_command(actor,p_session,p_command,'data_license.withdraw',jsonb_build_object('scope_id',p_license),proof);
  FOR prior_g IN SELECT * FROM public.security_action_grants WHERE(scope_kind='client_data' AND boundary_id=s.boundary_id) OR(scope_kind='data_use' AND data_use_scope_id=p_license) ORDER BY id FOR UPDATE LOOP
   SELECT * INTO prior_q FROM public.authority_qualification_receipts WHERE qualification_id=prior_g.qualification_id;
   IF prior_q.status='active' THEN
    q:=prior_q;q.status:='revoked';q.revision:=q.revision+1;
    PERFORM builderad_private.permit('public.authority_qualification_receipts','update',to_jsonb(prior_q),to_jsonb(q));UPDATE public.authority_qualification_receipts SET status=q.status,revision=q.revision WHERE qualification_id=q.qualification_id;
   END IF;
   IF prior_g.status='active' THEN
    g:=prior_g;g.status:='revoked';g.version:=g.version+1;g.revoked_at:=clock_timestamp();g.updated_at:=g.revoked_at;
    PERFORM builderad_private.permit('public.security_action_grants','update',to_jsonb(prior_g),to_jsonb(g));UPDATE public.security_action_grants SET status=g.status,version=g.version,revoked_at=g.revoked_at,updated_at=g.updated_at WHERE id=g.id;
   END IF;
   IF prior_g.subject_user_id=actor THEN
    IF prior_g.scope_kind='client_data' THEN boundary_grant:=prior_g.id;ELSE use_grant:=prior_g.id;END IF;
   END IF;
  END LOOP;
  PERFORM builderad_private.permit('public.client_data_boundaries','update',to_jsonb(boundary_row),to_jsonb(boundary_row)||jsonb_build_object('status','archived','revision',2));
  UPDATE public.client_data_boundaries SET status='archived',revision=2 WHERE boundary_id=s.boundary_id;
  PERFORM builderad_private.advance_right_epoch((SELECT fence_id FROM public.resource_right_fences WHERE kind='client_boundary' AND boundary_id=s.boundary_id));
  result:=jsonb_build_object('license_id',p_license,'boundary_id',s.boundary_id,'boundary_grant_id',boundary_grant::text,'use_grant_id',use_grant::text,
   'status','withdrawn','assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('license',2));
 END IF;
 PERFORM builderad_private.principal(p_session);
 PERFORM builderad_private.record_command(b,actor,p_command,'data_license.'||p_effect,payload,result);
 PERFORM builderad_private.finish_command();RETURN result;
END $$;
REVOKE ALL ON FUNCTION builderad_v2_data_use_license(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_data_use_license(text,uuid,uuid,uuid,text,bigint,jsonb,jsonb) TO builderad_app;
