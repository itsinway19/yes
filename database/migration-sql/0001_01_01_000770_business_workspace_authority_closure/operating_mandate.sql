-- Forward issuance-only closure from680. Existing operating_mandate_basis and historical-right evaluation remain unchanged.
CREATE OR REPLACE FUNCTION builderad_v2_business_operating_mandate(p_session text,p_business uuid,p_command uuid,p_confirmed boolean,p_capacity text,p_reference text,p_until timestamptz,p_previous bigint,p_selection jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;proof jsonb;payload bytea;result jsonb;authenticated timestamptz;prior public.security_action_grants;
 q public.authority_qualification_receipts;g public.security_action_grants;action_name text;refs jsonb:='{}';
BEGIN
 IF p_command IS NULL OR p_confirmed IS DISTINCT FROM true OR p_capacity IS NULL OR p_capacity NOT IN('responsible_business','client_mandate')
 OR p_reference IS NULL OR length(btrim(p_reference)) NOT BETWEEN 8 AND 512 OR p_until IS NULL OR NOT isfinite(p_until)
 OR p_previous IS NULL OR p_previous<0 THEN RAISE EXCEPTION 'Explicit bounded Business mandate required' USING ERRCODE='22023'; END IF;
 b:=builderad_private.lock_business(p_business,true);actor:=builderad_private.principal(p_session,false);
 proof:=builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);
 IF NOT coalesce(builderad_private.operating_mandate_basis(b,actor,proof),false)
  OR (p_selection->>'realm_kind'='agency' AND p_capacity<>'client_mandate')
 THEN RAISE EXCEPTION 'Responsible actor must explicitly declare a qualified operating mandate' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements((proof->'qualifications')||(proof->'relationship_qualifications')||(proof->'relationships')) AS deadlines(value)
  WHERE value->>'valid_until' IS NOT NULL AND (value->>'valid_until')::timestamptz<p_until)
 OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') AS deadlines(value)
  WHERE value->>'expires_at' IS NOT NULL AND (value->>'expires_at')::timestamptz<p_until)
 THEN RAISE EXCEPTION 'The operating mandate cannot outlast its admitted basis' USING ERRCODE='42501'; END IF;
 SELECT authenticated_at INTO authenticated FROM public.security_sessions WHERE user_id=actor AND session_key_hash=p_session;
 IF authenticated IS NULL OR authenticated>clock_timestamp() OR authenticated+interval '15 minutes'<=clock_timestamp()
 THEN RAISE EXCEPTION 'Fresh independent Business responsibility confirmation required' USING ERRCODE='42501'; END IF;
 payload:=builderad_private.digest('command-payload:1',jsonb_build_object('kind','business_operating_mandate','business',p_business,'capacity',p_capacity,
  'confirmed',p_confirmed,'reference',p_reference,'until',p_until,'previous',p_previous,'selection',p_selection),'{"business":"U","until":"T","previous":"I"}');
 result:=builderad_private.command_replay(b,actor,p_command,'business.operating_mandate',payload);IF result IS NOT NULL THEN RETURN result;END IF;
 -- Preserve an exact completed result; neither replay nor a historical link issues a new right.
 IF p_selection->>'realm_kind' IS DISTINCT FROM 'direct' OR EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path_grant(value)
  WHERE path_grant.value->>'delegation_realm_kind' IS DISTINCT FROM 'direct' OR path_grant.value->>'delegation_workspace_id' IS NOT NULL)
 THEN RAISE EXCEPTION 'New Business operating mandates cannot derive from Workspace' USING ERRCODE='42501'; END IF;
 IF p_until<=clock_timestamp() THEN RAISE EXCEPTION 'Explicit bounded Business mandate required' USING ERRCODE='22023'; END IF;
 SELECT x.* INTO prior FROM public.security_action_grants x JOIN public.authority_qualification_receipts receipt ON receipt.qualification_id=x.qualification_id
  WHERE x.business_id=b AND x.subject_user_id=actor AND x.action='business.data.use' AND receipt.policy_id='business_operating_mandate_v1'
  AND x.delegation_realm_kind=p_selection->>'realm_kind'
  AND x.delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid)
  ORDER BY x.id DESC LIMIT 1;
 IF coalesce(prior.id,0)<>p_previous THEN RAISE EXCEPTION 'Reviewed Business mandate changed' USING ERRCODE='40001'; END IF;
 IF prior.id IS NOT NULL AND prior.status='active' AND prior.revoked_at IS NULL AND(prior.expires_at IS NULL OR prior.expires_at>clock_timestamp())
  AND NOT EXISTS(SELECT 1 FROM public.business_workspace_links link WHERE link.business_id=b
   AND link.workspace_id=prior.delegation_workspace_id AND link.kind='agency' AND link.status='active'
   AND link.revision>1 AND EXISTS(SELECT 1 FROM public.authority_qualification_receipts original
    WHERE original.qualification_id=prior.qualification_id AND original.valid_from<link.valid_from))
 THEN RAISE EXCEPTION 'Reconcile or explicitly surrender the current mandate' USING ERRCODE='40001'; END IF;
 proof:=proof||jsonb_build_object('mandate_freshness',jsonb_build_object('valid_from',authenticated,'valid_until',authenticated+interval '15 minutes'),
  'explicit_operating_mandate',jsonb_build_object('confirmed',p_confirmed,'capacity',p_capacity,'authority_reference',p_reference,'assurance','DECLARED_AUTHORITY','valid_until',p_until));
 PERFORM builderad_private.start_command(actor,p_session,p_command,'business.operating_mandate',jsonb_build_object('business_id',b),proof);
 q.qualification_id:=uuidv7();q.policy_id:='business_operating_mandate_v1';q.policy_version:=1;q.issuer_kind:='qualified_policy';
 q.issuer_grant_id:=(proof#>>'{grants,0,id}')::bigint;q.subject_user_id:=actor;q.target_kind:='business';q.target_ref:=p_business;
 q.allowed_actions:=ARRAY['business.data.use','business.link.manage'];q.evidence_ref:='command:'||p_command;q.evidence_digest:=payload;
 q.status:='active';q.revision:=1;q.valid_from:=clock_timestamp();q.valid_until:=p_until;
 q.provenance:='Explicit dated Business responsibility/client mandate; declared assurance only; licensed data use/binding, no Source, advertising or organizational authority';
 PERFORM builderad_private.permit('public.authority_qualification_receipts','insert',NULL,to_jsonb(q));INSERT INTO public.authority_qualification_receipts SELECT(q).*;
 FOREACH action_name IN ARRAY q.allowed_actions LOOP
  g:=NULL;g.id:=nextval('public.security_action_grants_id_seq');g.subject_user_id:=actor;g.source:='qualified_policy';g.action:=action_name;g.scope_kind:='business';g.business_id:=b;
  SELECT generation INTO g.subject_generation FROM public.principal_lifecycles WHERE user_id=actor;
  g.reason:='Explicit finite Business operating mandate';g.status:='active';g.version:=1;g.expires_at:=p_until;g.created_at:=clock_timestamp();g.updated_at:=g.created_at;
  g.runtime_lock_marker:=false;g.qualification_id:=q.qualification_id;g.delegable:=true;g.delegation_realm_kind:=p_selection->>'realm_kind';
  SELECT id INTO g.delegation_workspace_id FROM public.workspaces WHERE public_id=(p_selection->>'realm_workspace_public_id')::uuid;
  PERFORM builderad_private.permit('public.security_action_grants','insert',NULL,to_jsonb(g));INSERT INTO public.security_action_grants SELECT(g).*;
  refs:=refs||jsonb_build_object(action_name,g.id::text);
 END LOOP;
 PERFORM builderad_private.advance_business_epoch(b);
 PERFORM builderad_private.require_business(b,actor,'business.context.read',p_selection,p_session);PERFORM builderad_private.principal(p_session);
 result:=jsonb_build_object('business_public_id',p_business,'grant_refs',refs,'qualification_ref',q.qualification_id,'assurance','DECLARED_AUTHORITY','versions',jsonb_build_object('qualification',1));
 PERFORM builderad_private.record_command(b,actor,p_command,'business.operating_mandate',payload,result);PERFORM builderad_private.finish_command();RETURN result;
END $$;
