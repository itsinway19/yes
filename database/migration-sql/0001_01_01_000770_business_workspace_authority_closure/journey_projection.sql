-- Forward from the exact journey projection installed by710; only new-enrollment availability changes.
CREATE OR REPLACE FUNCTION builderad_v2_data_license_journey(p_session text,p_business uuid,p_access bigint) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE actor bigint;b bigint;read_selection jsonb;proof jsonb;selection jsonb;paths jsonb;all_paths jsonb;
 source_ids uuid[];boundary_ids uuid[];sources jsonb:='[]';licenses jsonb:='[]';uses jsonb:='[]';bindings jsonb:='[]';
 source_row record;dataset_row record;license_row record;grant_row public.security_action_grants;manager_id bigint;reader_id bigint;datasets jsonb;refs jsonb;
 record_metadata jsonb;records_complete boolean;datasets_more boolean;
 row_value jsonb;coverage_row public.boundary_subjects;binding_row public.business_data_use_bindings;previous_grant bigint;can_enroll boolean;sources_more boolean;licenses_more boolean;
 beneficiaries jsonb:='[]';assignments jsonb:='[]';assigned_licenses jsonb:='[]';beneficiary_row record;
 licensed_grant bigint;licence_until timestamptz;reader_operation text;reader_purpose text;summary_info jsonb;summary_ref jsonb;
BEGIN
 actor:=builderad_private.principal(p_session,false);SELECT id INTO b FROM public.businesses WHERE public_id=p_business;
 read_selection:=builderad_private.business_selection(p_access);
 -- Candidate selection is not authority. Complete K1 set precedes Business/K4 locks.
 SELECT coalesce(array_agg(candidate.id ORDER BY candidate.id),'{}'::uuid[]) INTO source_ids FROM(SELECT DISTINCT sr.id FROM public.source_registry sr
  JOIN public.security_action_grants g ON g.source_target_id=sr.id AND g.scope_kind='source' AND g.subject_user_id=actor AND g.action IN('source.manage','source.read')
  WHERE sr.status<>'REVOKED' AND sr.namespace='ad_account' AND ((sr.custody_kind='business_native' AND sr.business_id=b) OR(sr.custody_kind='workspace_native'
   AND EXISTS(SELECT 1 FROM public.business_workspace_links l WHERE l.business_id=b AND l.workspace_id=sr.workspace_id AND l.status='active')))
  ORDER BY sr.id LIMIT 33) candidate;
 sources_more:=cardinality(source_ids)>32;
 IF sources_more THEN source_ids:=source_ids[1:32];END IF;
 SELECT coalesce(array_agg(c.boundary_id),'{}'::uuid[]) INTO boundary_ids FROM(
  SELECT boundary_id FROM public.client_data_boundaries WHERE descriptor->>'policy_id'='declared_data_license_v1'
   AND descriptor->>'business_public_id'=p_business::text AND((descriptor->>'declarant_user_id'=actor::text
    AND descriptor->>'declarant_generation'=(SELECT generation::text FROM public.principal_lifecycles WHERE user_id=actor)) OR EXISTS(
    SELECT 1 FROM public.data_use_scopes s JOIN public.security_action_grants assigned ON assigned.data_use_scope_id=s.scope_id
    JOIN public.authority_qualification_receipts assigned_q ON assigned_q.qualification_id=assigned.qualification_id
    WHERE s.boundary_id=client_data_boundaries.boundary_id AND assigned.subject_user_id=actor AND assigned_q.policy_id='declared_data_beneficiary_v1'
    AND assigned.status='active' AND assigned.revoked_at IS NULL AND assigned_q.status='active' AND assigned.expires_at>clock_timestamp()
    AND assigned.subject_generation=(SELECT generation FROM public.principal_lifecycles WHERE user_id=actor))) ORDER BY boundary_id DESC LIMIT 32
 ) c;
 SELECT count(*)>32 INTO licenses_more FROM public.client_data_boundaries WHERE descriptor->>'policy_id'='declared_data_license_v1'
  AND descriptor->>'business_public_id'=p_business::text AND descriptor->>'declarant_user_id'=actor::text;
 PERFORM 1 FROM public.resource_right_fences WHERE(kind='source' AND source_id=ANY(source_ids)) OR(kind='client_boundary' AND boundary_id=ANY(boundary_ids)) ORDER BY fence_id FOR SHARE;
 b:=builderad_private.lock_business(p_business,false);
 SELECT coalesce(jsonb_agg(jsonb_build_object('requirement_kind',CASE WHEN scope_kind='source' THEN 'native_source' ELSE scope_kind END,'leaf_grant_id',id)
  ORDER BY(id=p_access) DESC,(scope_kind='business') DESC,(scope_kind='source') DESC,id DESC),'[]') INTO all_paths
 FROM(SELECT id,scope_kind FROM public.security_action_grants WHERE subject_user_id=actor AND status='active' AND revoked_at IS NULL
  AND(expires_at IS NULL OR expires_at>clock_timestamp()) AND((scope_kind='source' AND source_target_id=ANY(source_ids) AND action IN('source.manage','source.read'))
  OR(scope_kind='business' AND business_id=b AND(action IN('business.data.use','business.link.manage') OR id=p_access))
  OR(scope_kind='data_use' AND data_use_scope_id IN(SELECT scope_id FROM public.data_use_scopes WHERE boundary_id=ANY(boundary_ids))))
  ORDER BY (id=p_access) DESC,(scope_kind='business') DESC,(scope_kind='source') DESC,id DESC LIMIT 33) candidates;
 IF jsonb_array_length(all_paths)>32 THEN
  licenses_more:=true;
  IF all_paths#>>'{32,requirement_kind}'<>'data_use' THEN sources_more:=true;END IF;
  all_paths:=all_paths-32;
 END IF;
 -- This inventory admits candidates, not an operation. A withdrawn producer
 -- basis removes that output candidate; it must not deny independent Business read.
 paths:='[]';
 FOR row_value IN SELECT value FROM jsonb_array_elements(all_paths) LOOP
  IF row_value->>'requirement_kind'='data_use' AND to_regclass('public.business_expenditure_summaries') IS NOT NULL THEN
   SELECT jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')) INTO summary_ref
    FROM public.business_expenditure_summaries WHERE output_scope_id=(SELECT data_use_scope_id FROM public.security_action_grants WHERE id=(row_value->>'leaf_grant_id')::bigint);
   IF summary_ref IS NOT NULL THEN
    BEGIN PERFORM builderad_private.expenditure_summary_constraints(summary_ref,'display',(SELECT purpose FROM public.business_expenditure_summaries WHERE summary_id=(summary_ref->>'key')::uuid));
    EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
   END IF;
  END IF;
  paths:=paths||jsonb_build_array(row_value);
 END LOOP;
 all_paths:=paths;
 PERFORM builderad_private.lock_authority_footprint(actor,jsonb_build_object('requirements',all_paths),p_session);
 licenses_more:=licenses_more OR EXISTS(SELECT 1 FROM public.data_use_scopes s JOIN public.client_data_boundaries boundary ON boundary.boundary_id=s.boundary_id
  WHERE boundary.boundary_id=ANY(boundary_ids) AND(boundary.descriptor->>'declarant_user_id'=actor::text OR EXISTS(
   SELECT 1 FROM public.security_action_grants own WHERE own.subject_user_id=actor AND own.data_use_scope_id=s.scope_id
    AND own.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)))) OFFSET 32 LIMIT 1);
 proof:=builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 -- Availability describes new issuance, independently of historical mandate validation.
 can_enroll:=coalesce(builderad_private.operating_mandate_basis(b,actor,proof),false)
  AND read_selection->>'realm_kind'='direct' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(proof->'grants') path_grant(value)
   WHERE path_grant.value->>'delegation_realm_kind' IS DISTINCT FROM 'direct' OR path_grant.value->>'delegation_workspace_id' IS NOT NULL);
 FOR grant_row IN SELECT * FROM public.security_action_grants WHERE subject_user_id=actor AND scope_kind='business' AND business_id=b
  AND action IN('business.data.use','business.link.manage') AND id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)) ORDER BY id LOOP
  IF grant_row.delegation_realm_kind IS DISTINCT FROM read_selection->>'realm_kind'
   OR grant_row.delegation_workspace_id IS DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid) THEN CONTINUE;END IF;
  BEGIN
   PERFORM builderad_private.require_scope('business',b::text,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
   row_value:=jsonb_build_object('grant_id',grant_row.id::text);
   IF grant_row.action='business.data.use' THEN uses:=uses||jsonb_build_array(row_value);ELSE bindings:=bindings||jsonb_build_array(row_value);END IF;
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
 END LOOP;
 FOR source_row IN SELECT * FROM public.source_registry WHERE id=ANY(source_ids) ORDER BY id LOOP
  manager_id:=NULL;reader_id:=NULL;reader_operation:=NULL;reader_purpose:=NULL;datasets:='[]';datasets_more:=false;
  FOR grant_row IN SELECT * FROM public.security_action_grants WHERE scope_kind='source' AND source_target_id=source_row.id AND subject_user_id=actor
   AND source_resource=source_row.declared_external_id AND action IN('source.manage','source.read')
   AND id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths)) ORDER BY id LOOP
   selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',grant_row.id)));
   BEGIN
    PERFORM builderad_private.require_native_source_grant(grant_row.id,actor,p_session,selection,grant_row.action='source.manage',false);
    IF grant_row.action='source.manage' THEN manager_id:=grant_row.id;ELSE reader_id:=grant_row.id;reader_operation:=grant_row.source_operation;reader_purpose:=grant_row.source_purpose;END IF;
   EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
  END LOOP;
  IF manager_id IS NULL AND reader_id IS NULL THEN CONTINUE;END IF;
  IF reader_id IS NOT NULL THEN
   FOR dataset_row IN SELECT stream.namespace,stream.generation,run.coverage FROM public.provider_observation_streams stream
    JOIN public.provider_observation_runs run ON run.namespace=stream.namespace AND run.generation=stream.generation
    WHERE stream.source_id=source_row.id AND stream.resource=source_row.declared_external_id AND stream.workspace_id IS NOT DISTINCT FROM source_row.workspace_id
    AND stream.operation=reader_operation AND stream.purpose=reader_purpose
    AND EXISTS(SELECT 1 FROM public.provider_observation_entries entry WHERE entry.namespace=stream.namespace AND entry.generation=stream.generation AND entry.observation ? 'spend')
    ORDER BY stream.namespace LIMIT 9 LOOP
    IF jsonb_array_length(datasets)=8 THEN datasets_more:=true;EXIT;END IF;
    SELECT coalesce(jsonb_agg(e.ref ORDER BY e.identity) FILTER(WHERE e.ordinal<=32),'[]'),count(*)<=32,
     coalesce(jsonb_agg(jsonb_build_object('ref',e.ref,
      'campaign_id',CASE WHEN jsonb_typeof(e.observation->'campaign_id')='string' THEN left(e.observation->>'campaign_id',180) END,
      'start',CASE WHEN jsonb_typeof(e.observation#>'{window,start}')='string' THEN left(e.observation#>>'{window,start}',64) END,
      'end',CASE WHEN jsonb_typeof(e.observation#>'{window,end}')='string' THEN left(e.observation#>>'{window,end}',64) END,
      'boundary',CASE WHEN e.observation#>>'{window,boundary}' IN('INCLUSIVE_DATES','HALF_OPEN_INSTANTS') THEN e.observation#>>'{window,boundary}' END,
      'grain',CASE WHEN e.observation#>>'{window,grain}' IN('TOTAL','DAY','HOUR') THEN e.observation#>>'{window,grain}' END,
      'timezone',CASE WHEN jsonb_typeof(e.observation#>'{window,timezone}')='string' THEN left(e.observation#>>'{window,timezone}',64) END) ORDER BY e.identity) FILTER(WHERE e.ordinal<=32),'[]')
     INTO refs,records_complete,record_metadata FROM(
      SELECT identity,observation,row_number() OVER(ORDER BY identity) ordinal,
       jsonb_build_object('namespace','provider_observation_entry','key',jsonb_build_object('namespace',namespace,'generation',generation,'identity',identity)::text,'version',content_hash) ref
      FROM public.provider_observation_entries WHERE namespace=dataset_row.namespace AND generation=dataset_row.generation AND observation ? 'spend' ORDER BY identity LIMIT 33) e;
    IF jsonb_array_length(refs)=0 THEN CONTINUE;END IF;
    datasets:=datasets||jsonb_build_array(jsonb_build_object('dataset_id',dataset_row.namespace||':'||dataset_row.generation,'coverage',dataset_row.coverage,
     'records_completeness',CASE WHEN records_complete THEN 'COMPLETE' ELSE 'PARTIAL' END,'record_metadata',record_metadata,
     'record_count',jsonb_array_length(refs),'material_refs',refs,'selection',jsonb_build_object('native_namespaces',jsonb_build_array(dataset_row.namespace),
      'native_keys',(SELECT jsonb_agg((value->>'key')::jsonb->>'identity') FROM jsonb_array_elements(refs)),'field_keys',jsonb_build_array('spend'),'partition_ref',NULL)));
   END LOOP;
  END IF;
  sources:=sources||jsonb_build_array(jsonb_build_object('source_id',source_row.id,'label',source_row.label,'family',source_row.family,'resource',source_row.declared_external_id,
   'manager_grant_id',manager_id::text,'read_grant_id',reader_id::text,'datasets',datasets,
   'datasets_completeness',CASE WHEN reader_id IS NULL THEN 'UNKNOWN' WHEN datasets_more THEN 'PARTIAL' ELSE 'COMPLETE' END));
 END LOOP;
 FOR license_row IN SELECT scope.*,c.descriptor,c.status boundary_status FROM public.data_use_scopes scope JOIN public.client_data_boundaries c ON c.boundary_id=scope.boundary_id
  WHERE c.boundary_id=ANY(boundary_ids) AND(c.descriptor->>'declarant_user_id'=actor::text OR EXISTS(
   SELECT 1 FROM public.security_action_grants own WHERE own.subject_user_id=actor AND own.data_use_scope_id=scope.scope_id
    AND own.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths))))
  ORDER BY scope.scope_id DESC LIMIT 32 LOOP
  licensed_grant:=NULL;licence_until:=(license_row.descriptor#>>'{declaration,valid_until}')::timestamptz;
  IF license_row.descriptor->>'declarant_user_id' IS DISTINCT FROM actor::text THEN
   IF jsonb_array_length(uses)=0 OR license_row.status<>'active' OR license_row.boundary_status<>'active' THEN CONTINUE;END IF;
   SELECT min(g.id) INTO licensed_grant FROM public.security_action_grants g
    JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
    WHERE g.scope_kind='data_use' AND g.data_use_scope_id=license_row.scope_id AND g.subject_user_id=actor
    AND g.status='active' AND g.revoked_at IS NULL AND g.id IN(SELECT(value->>'leaf_grant_id')::bigint FROM jsonb_array_elements(all_paths))
    AND q.issuer_grant_id IN(SELECT(value->>'grant_id')::bigint FROM jsonb_array_elements(uses));
   IF licensed_grant IS NULL THEN CONTINUE;END IF;
   BEGIN
    selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','data_use','leaf_grant_id',licensed_grant)));
    PERFORM builderad_private.require_scope('data_use',license_row.scope_id::text,actor,'data_use.'||license_row.operation,selection,p_session);
   EXCEPTION WHEN insufficient_privilege THEN CONTINUE;END;
   licence_until:=least(licence_until,(SELECT expires_at FROM public.security_action_grants WHERE id=licensed_grant));
   IF NOT EXISTS(SELECT 1 FROM jsonb_array_elements(sources) src WHERE src->>'source_id'=license_row.source_id::text AND src->>'read_grant_id' IS NOT NULL) THEN
    summary_info:='{}';
    IF license_row.resource='business.expenditure_summary' THEN
     SELECT jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')) INTO summary_ref
      FROM public.business_expenditure_summaries WHERE output_scope_id=license_row.scope_id AND publication_manifest_id IS NOT NULL;
     BEGIN
      IF summary_ref IS NOT NULL AND EXISTS(SELECT 1 FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=license_row.scope_id AND status='active')
       AND EXISTS(SELECT 1 FROM public.boundary_subjects WHERE boundary_id=license_row.boundary_id AND business_id=b AND branch_id IS NULL AND status='active') THEN
       PERFORM builderad_private.expenditure_summary_constraints(summary_ref,'display',license_row.purpose);
       summary_info:=jsonb_build_object('artifact_ref',summary_ref,'use_grant_id',licensed_grant::text);
      END IF;
     EXCEPTION WHEN insufficient_privilege THEN summary_info:='{}';END;
    END IF;
    assigned_licenses:=assigned_licenses||jsonb_build_array(jsonb_build_object('license_id',license_row.scope_id,
     'operation',license_row.operation,'purpose',license_row.purpose,'valid_until',licence_until)||summary_info);
    CONTINUE;
   END IF;
  ELSE
   SELECT min(id) INTO licensed_grant FROM public.security_action_grants WHERE scope_kind='data_use' AND data_use_scope_id=license_row.scope_id AND subject_user_id=actor;
  END IF;
  SELECT * INTO coverage_row FROM public.boundary_subjects WHERE boundary_id=license_row.boundary_id AND business_id=b AND branch_id IS NULL ORDER BY revision DESC LIMIT 1;
  SELECT * INTO binding_row FROM public.business_data_use_bindings WHERE business_id=b AND data_use_scope_id=license_row.scope_id ORDER BY revision DESC LIMIT 1;
  SELECT coalesce(jsonb_agg(ref),'[]') INTO refs FROM jsonb_array_elements(sources) src CROSS JOIN LATERAL jsonb_array_elements(src->'datasets') ds
   CROSS JOIN LATERAL jsonb_array_elements(ds->'material_refs') ref WHERE src->>'source_id'=license_row.source_id::text
   AND license_row.selection->'native_namespaces' ? ((ref->>'key')::jsonb->>'namespace') AND license_row.selection->'native_keys' ? ((ref->>'key')::jsonb->>'identity');
  SELECT coalesce(jsonb_agg(metadata),'[]') INTO record_metadata FROM jsonb_array_elements(sources) src CROSS JOIN LATERAL jsonb_array_elements(src->'datasets') ds
   CROSS JOIN LATERAL jsonb_array_elements(ds->'record_metadata') metadata WHERE src->>'source_id'=license_row.source_id::text AND refs @> jsonb_build_array(metadata->'ref');
  summary_info:=NULL;
  IF license_row.resource='business.expenditure_summary' THEN
   record_metadata:='[]';
   SELECT jsonb_build_array(jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex'))),
    jsonb_build_object('state',CASE WHEN publication_manifest_id IS NULL THEN 'prepared' ELSE 'published' END,
     'dependencies',builderad_private.producer_dependencies(builderad_private.expenditure_summary_descriptor(jsonb_build_object('namespace','business.expenditure_summary','key',summary_id::text,'version',encode(material_digest,'hex')))),
     'input_license_id',input_reads#>'{0,data_use_scope_id}',
     'process_grant_id',(SELECT x->>'leaf_grant_id' FROM jsonb_array_elements(input_selection->'requirements') x WHERE x->>'requirement_kind'='data_use'))
   INTO refs,summary_info FROM public.business_expenditure_summaries WHERE output_scope_id=license_row.scope_id
    AND EXISTS(SELECT 1 FROM jsonb_array_elements(sources) src WHERE src->>'source_id'=license_row.source_id::text AND src->>'read_grant_id' IS NOT NULL);
   refs:=coalesce(refs,'[]');
  END IF;
  licence_until:=least(licence_until,(SELECT expires_at FROM public.security_action_grants WHERE id=licensed_grant));
  licenses:=licenses||jsonb_build_array(jsonb_build_object('license_id',license_row.scope_id,'boundary_id',license_row.boundary_id,
   'boundary_grant_id',(SELECT min(id)::text FROM public.security_action_grants WHERE scope_kind='client_data' AND boundary_id=license_row.boundary_id AND subject_user_id=actor),
   'use_grant_id',licensed_grant::text,
   'source_id',license_row.source_id,'resource',license_row.resource,'operation',license_row.operation,'purpose',license_row.purpose,'valid_until',licence_until,
   'status',CASE WHEN license_row.boundary_status<>'active' THEN 'withdrawn' WHEN licence_until<=clock_timestamp() THEN 'expired' ELSE 'active' END,
   'selection',license_row.selection,'recipient_public_id',(SELECT public_id FROM public.users WHERE id=actor),'material_refs',refs,'record_metadata',record_metadata,'summary',summary_info,
   'is_declarant',license_row.descriptor->>'declarant_user_id'=actor::text,
   'covered',coalesce(coverage_row.status='active',false),'bound',coalesce(binding_row.status='active',false),
   'coverage_revision',coalesce(coverage_row.revision,0),'binding_revision',coalesce(binding_row.revision,0)));
 END LOOP;
 FOR beneficiary_row IN SELECT DISTINCT ON(child.subject_user_id) child.*,u.public_id,u.name,w.public_id workspace_public_id
  FROM public.security_action_grants child JOIN public.security_action_grants parent ON parent.id=child.parent_grant_id
  JOIN public.authority_qualification_receipts qualified ON qualified.qualification_id=child.qualification_id
  JOIN public.principal_lifecycles l ON l.user_id=child.subject_user_id AND l.generation=child.subject_generation AND l.status='current' AND l.principal_class='advertiser'
  JOIN public.users u ON u.id=child.subject_user_id AND u.email_verified_at IS NOT NULL
  LEFT JOIN public.workspaces w ON w.id=child.delegation_workspace_id
  WHERE child.business_id=b AND child.scope_kind='business' AND child.action='business.data.use' AND child.subject_user_id<>actor
  AND child.status='active' AND child.revoked_at IS NULL AND(child.expires_at IS NULL OR child.expires_at>clock_timestamp())
  AND parent.subject_user_id=actor AND parent.id IN(SELECT(value->>'grant_id')::bigint FROM jsonb_array_elements(uses)) AND parent.delegable
  AND qualified.status='active' AND qualified.valid_from<=clock_timestamp() AND(qualified.valid_until IS NULL OR qualified.valid_until>clock_timestamp())
  AND child.delegation_realm_kind IS NOT DISTINCT FROM read_selection->>'realm_kind'
  AND child.delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid)
  AND(child.delegation_workspace_id IS NULL OR EXISTS(SELECT 1 FROM public.workspace_memberships m WHERE m.workspace_id=child.delegation_workspace_id AND m.user_id=child.subject_user_id AND m.status='active'
    AND(coalesce((to_jsonb(m)->>'joined_revision')::bigint,0)=0 OR child.created_at>=m.created_at)))
  ORDER BY child.subject_user_id,child.id DESC LIMIT 32 LOOP
  beneficiaries:=beneficiaries||jsonb_build_array(jsonb_build_object('public_id',beneficiary_row.public_id,'name',beneficiary_row.name,
   'realm_kind',beneficiary_row.delegation_realm_kind,'realm_workspace_public_id',beneficiary_row.workspace_public_id));
 END LOOP;
 SELECT coalesce(jsonb_agg(to_jsonb(a)),'[]') INTO assignments FROM(
  SELECT DISTINCT ON(g.data_use_scope_id,g.subject_user_id) g.data_use_scope_id license_id,u.public_id recipient_public_id,g.id::text grant_id,g.status,g.version revision,g.expires_at valid_until
  FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  JOIN public.data_use_scopes s ON s.scope_id=g.data_use_scope_id JOIN public.client_data_boundaries boundary ON boundary.boundary_id=s.boundary_id
  JOIN public.users u ON u.id=g.subject_user_id
  WHERE q.policy_id='declared_data_beneficiary_v1' AND boundary.boundary_id=ANY(boundary_ids) AND boundary.descriptor->>'declarant_user_id'=actor::text
  ORDER BY g.data_use_scope_id,g.subject_user_id,g.id DESC LIMIT 64
 ) a;
 SELECT coalesce(max(g.id),0) INTO previous_grant FROM public.security_action_grants g JOIN public.authority_qualification_receipts q ON q.qualification_id=g.qualification_id
  WHERE g.business_id=b AND g.subject_user_id=actor AND g.action='business.data.use' AND q.policy_id='business_operating_mandate_v1'
  AND g.delegation_realm_kind=read_selection->>'realm_kind'
  AND g.delegation_workspace_id IS NOT DISTINCT FROM(SELECT id FROM public.workspaces WHERE public_id=(read_selection->>'realm_workspace_public_id')::uuid);
 -- Requalification after the final metadata wait, without substituting a new grant.
 PERFORM builderad_private.require_business(b,actor,'business.context.read',read_selection,p_session);
 FOR row_value IN SELECT value FROM jsonb_array_elements(sources) LOOP
  FOREACH manager_id IN ARRAY ARRAY[(row_value->>'manager_grant_id')::bigint,(row_value->>'read_grant_id')::bigint] LOOP
   IF manager_id IS NOT NULL THEN
    selection:=read_selection||jsonb_build_object('requirements',jsonb_build_array(jsonb_build_object('requirement_kind','native_source','leaf_grant_id',manager_id)));
    PERFORM builderad_private.require_native_source_grant(manager_id,actor,p_session,selection,false,false);
   END IF;
  END LOOP;
 END LOOP;
 FOR row_value IN SELECT value FROM jsonb_array_elements(uses||bindings) LOOP
  SELECT * INTO STRICT grant_row FROM public.security_action_grants WHERE id=(row_value->>'grant_id')::bigint;
  PERFORM builderad_private.require_scope('business',b::text,actor,grant_row.action,builderad_private.business_selection(grant_row.id),p_session);
 END LOOP;
 PERFORM builderad_private.principal(p_session);
 RETURN jsonb_build_object('business_public_id',p_business,'actor_public_id',(SELECT public_id FROM public.users WHERE id=actor),
  'sources',sources,'sources_more',sources_more,'licenses',licenses,'licenses_more',licenses_more,'business_use_options',uses,'binding_options',bindings,
  'beneficiaries',beneficiaries,'assignments',assignments,'assigned_licenses',assigned_licenses,
  'operating_mandate',jsonb_build_object('available',can_enroll AND jsonb_array_length(uses)=0,'previous_grant',previous_grant::text));
END $$;
REVOKE ALL ON FUNCTION builderad_v2_data_license_journey(text,uuid,bigint) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_v2_data_license_journey(text,uuid,bigint) TO builderad_app;
