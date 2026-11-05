-- One canonical Source registry, with three finite custody kinds. Existing
-- Workspace and platform-market identities, values and history are retained.
ALTER TABLE public.source_registry ADD COLUMN business_id bigint REFERENCES public.businesses(id) ON DELETE RESTRICT;
ALTER TABLE public.source_registry DROP CONSTRAINT source_registry_custody_kind_check;
ALTER TABLE public.source_registry DROP CONSTRAINT source_custody_shape;
ALTER TABLE public.source_registry ADD CONSTRAINT source_registry_custody_kind_check
 CHECK(custody_kind IN('workspace_native','business_native','platform_market'));
ALTER TABLE public.source_registry ADD CONSTRAINT source_custody_shape CHECK(
 (custody_kind='workspace_native' AND workspace_id IS NOT NULL AND business_id IS NULL) OR
 (custody_kind='business_native' AND workspace_id IS NULL AND business_id IS NOT NULL) OR
 (custody_kind='platform_market' AND workspace_id IS NULL AND business_id IS NULL));
CREATE UNIQUE INDEX business_native_source_identity ON public.source_registry(business_id,family,namespace,declared_external_id)
 WHERE custody_kind='business_native';

-- Source UUIDs were already globally unique. Child identities therefore need
-- no invented Workspace. Preserve legacy tuple uniqueness and foreign keys;
-- add non-null canonical references so NULL metadata can never skip integrity.
DO $references$
DECLARE child regclass;foreign_key record;
BEGIN
 FOREACH child IN ARRAY ARRAY['public.provider_access'::regclass,'public.provider_observation_streams'::regclass,'public.snapchat_renewal_failures'::regclass] LOOP
  IF (SELECT count(*) FROM pg_constraint WHERE conrelid=child AND confrelid='public.source_capabilities'::regclass AND contype='f')<>1
  THEN RAISE EXCEPTION 'SOURCE_CAPABILITY_DEPENDENCY_REVIEW_REQUIRED' USING ERRCODE='42501'; END IF;
  FOR foreign_key IN SELECT conname FROM pg_constraint WHERE conrelid=child AND confrelid='public.source_capabilities'::regclass AND contype='f' LOOP
   EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I',child,foreign_key.conname);
  END LOOP;
 END LOOP;
 IF (SELECT count(*) FROM pg_constraint WHERE conrelid='public.provider_access_receipts'::regclass AND confrelid='public.provider_access'::regclass AND contype='f')<>1
 THEN RAISE EXCEPTION 'PROVIDER_ACCESS_DEPENDENCY_REVIEW_REQUIRED' USING ERRCODE='42501'; END IF;
 FOR foreign_key IN SELECT conname FROM pg_constraint WHERE conrelid='public.provider_access_receipts'::regclass AND confrelid='public.provider_access'::regclass AND contype='f' LOOP
  EXECUTE format('ALTER TABLE public.provider_access_receipts DROP CONSTRAINT %I',foreign_key.conname);
 END LOOP;
END $references$;

ALTER TABLE public.source_capabilities DROP CONSTRAINT source_capabilities_pkey;
ALTER TABLE public.source_capabilities ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.source_capabilities ADD PRIMARY KEY(source_id,resource,operation,purpose);
ALTER TABLE public.source_capabilities ADD UNIQUE(workspace_id,source_id,resource,operation,purpose);
ALTER TABLE public.source_capabilities ADD FOREIGN KEY(source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;

ALTER TABLE public.source_command_receipts DROP CONSTRAINT source_command_receipts_pkey;
ALTER TABLE public.source_command_receipts ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.source_command_receipts ADD PRIMARY KEY(source_id,command_id);
ALTER TABLE public.source_command_receipts ADD UNIQUE(workspace_id,command_id);
ALTER TABLE public.source_command_receipts ADD FOREIGN KEY(source_id) REFERENCES public.source_registry(id) ON DELETE RESTRICT;
-- Business command identity additionally uses the existing canonical Business
-- receipt. Historical command UUIDs remain independently scoped per Workspace.
ALTER TABLE public.source_versions DROP CONSTRAINT source_versions_pkey;
ALTER TABLE public.source_versions ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.source_versions ADD PRIMARY KEY(source_id,version);
ALTER TABLE public.source_versions ADD FOREIGN KEY(source_id,command_id) REFERENCES public.source_command_receipts(source_id,command_id) ON DELETE RESTRICT;
ALTER TABLE public.source_audit_obligations ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.source_audit_obligations ADD UNIQUE(source_id,command_id);
ALTER TABLE public.source_audit_obligations ADD FOREIGN KEY(source_id,command_id) REFERENCES public.source_command_receipts(source_id,command_id) ON DELETE RESTRICT;
ALTER TABLE public.source_audit_delivery ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.source_audit_delivery ADD FOREIGN KEY(obligation_id) REFERENCES public.source_audit_obligations(id) ON DELETE RESTRICT;

ALTER TABLE public.provider_access DROP CONSTRAINT provider_access_pkey;
ALTER TABLE public.provider_access ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.provider_access ADD PRIMARY KEY(source_id,provider,resource,operation,purpose);
ALTER TABLE public.provider_access ADD UNIQUE(workspace_id,source_id,provider,resource,operation,purpose);
ALTER TABLE public.provider_access ADD FOREIGN KEY(source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(source_id,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.provider_access ADD FOREIGN KEY(workspace_id,source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(workspace_id,source_id,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.provider_access_receipts ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.provider_access_receipts ADD FOREIGN KEY(source_id,provider,resource,operation,purpose)
 REFERENCES public.provider_access(source_id,provider,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.provider_access_receipts ADD FOREIGN KEY(workspace_id,source_id,provider,resource,operation,purpose)
 REFERENCES public.provider_access(workspace_id,source_id,provider,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.provider_observation_streams ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.provider_observation_streams ADD UNIQUE(source_id,provider,resource,operation,purpose,stream);
ALTER TABLE public.provider_observation_streams ADD FOREIGN KEY(source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(source_id,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.provider_observation_streams ADD FOREIGN KEY(workspace_id,source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(workspace_id,source_id,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.snapchat_renewal_failures DROP CONSTRAINT snapchat_renewal_failures_pkey;
ALTER TABLE public.snapchat_renewal_failures ALTER COLUMN workspace_id DROP NOT NULL;
ALTER TABLE public.snapchat_renewal_failures ADD PRIMARY KEY(source_id,resource,operation,purpose,command_id);
ALTER TABLE public.snapchat_renewal_failures ADD UNIQUE(workspace_id,source_id,resource,operation,purpose,command_id);
ALTER TABLE public.snapchat_renewal_failures ADD FOREIGN KEY(source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(source_id,resource,operation,purpose) ON DELETE RESTRICT;
ALTER TABLE public.snapchat_renewal_failures ADD FOREIGN KEY(workspace_id,source_id,resource,operation,purpose)
 REFERENCES public.source_capabilities(workspace_id,source_id,resource,operation,purpose) ON DELETE RESTRICT;

-- Existing Source grants remain Source grants. This adds only the explicitly
-- qualified, nondelegable Business-native custody root to the current shape.
DO $grant_shape$
DECLARE prior text;
BEGIN
 SELECT pg_get_constraintdef(oid) INTO STRICT prior FROM pg_constraint
 WHERE conrelid='public.security_action_grants'::regclass AND conname='security_action_grants_scope_check';
 prior:=substring(prior FROM 8 FOR length(prior)-8);
 ALTER TABLE public.security_action_grants DROP CONSTRAINT security_action_grants_scope_check;
 EXECUTE 'ALTER TABLE public.security_action_grants ADD CONSTRAINT security_action_grants_scope_check CHECK(('||prior||') OR
 (scope_kind=''source'' AND workspace_id IS NULL AND business_id IS NOT NULL
 AND commercial_target_id IS NULL AND commercial_version_id IS NULL AND boundary_id IS NULL AND data_use_scope_id IS NULL
 AND source_target_id IS NOT NULL AND source=''source_owner_request'' AND granted_by=subject_user_id
 AND qualification_id IS NOT NULL AND parent_grant_id IS NULL AND NOT delegable
 AND delegation_realm_kind IS NULL AND delegation_workspace_id IS NULL))';
END $grant_shape$;
CREATE UNIQUE INDEX business_source_grants_active_unique ON public.security_action_grants
 (subject_user_id,subject_generation,source_target_id,source_resource,source_operation,source_purpose,action)
 WHERE scope_kind='source' AND workspace_id IS NULL AND status='active';
