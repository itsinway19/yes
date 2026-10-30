-- Guarded synthetic custodian only. Not included by any application migration.
CREATE OR REPLACE FUNCTION builderad_private.registered_producer(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r builderad_private.test_registered_producer;
BEGIN
 IF current_database()<>'builderad_test_disposable' OR (SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()) NOT LIKE 'builderad-disposable-test:v1:%'
 OR p_ref->>'namespace'<>'test.fixture_report' THEN RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no fixture producer here' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM builderad_private.test_registered_producer WHERE artifact_key=p_ref->>'key';
 IF NOT FOUND OR r.descriptor->'ref' IS DISTINCT FROM p_ref OR p_ref->>'version'<>encode(sha256(convert_to(r.payload::text,'UTF8')),'hex') THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: exact producer-owned output absent' USING ERRCODE='42501'; END IF;
 RETURN r.descriptor||jsonb_build_object('publication_manifest_id',r.publication_manifest_id);
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_publish(p_ref jsonb,p_manifest uuid,p_closure bytea) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 PERFORM builderad_private.registered_producer(p_ref);
 IF EXISTS(SELECT 1 FROM builderad_private.test_registered_producer WHERE artifact_key=p_ref->>'key' AND fail_publish) THEN RAISE EXCEPTION 'Synthetic owner publication failure' USING ERRCODE='40001'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.material_lineage_manifests WHERE manifest_id=p_manifest AND material_namespace=p_ref->>'namespace' AND material_key=p_ref->>'key' AND material_version=p_ref->>'version' AND closure_digest=p_closure) THEN RAISE EXCEPTION 'Missing exact core publication' USING ERRCODE='42501'; END IF;
 UPDATE builderad_private.test_registered_producer SET publication_manifest_id=p_manifest WHERE artifact_key=p_ref->>'key' AND(publication_manifest_id IS NULL OR publication_manifest_id=p_manifest);
 IF NOT FOUND THEN RAISE EXCEPTION 'Producer already published another version' USING ERRCODE='40001'; END IF;
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_fields(p_ref jsonb,p_fields jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE result jsonb;
BEGIN
 PERFORM builderad_private.registered_producer(p_ref);
 SELECT jsonb_object_agg(k.key,r.payload->k.key) INTO result FROM builderad_private.test_registered_producer r
 CROSS JOIN LATERAL jsonb_array_elements_text(p_fields) k(key) WHERE r.artifact_key=p_ref->>'key' AND r.publication_manifest_id IS NOT NULL AND r.payload ? k.key;
 RETURN result;
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_output_constraints(p_ref jsonb,p_operation text,p_purpose text) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE d jsonb;edge jsonb;
BEGIN
 d:=builderad_private.registered_producer(p_ref);
 IF NOT EXISTS(SELECT 1 FROM builderad_private.test_registered_producer WHERE artifact_key=p_ref->>'key' AND policy_current) THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: current output policy unavailable' USING ERRCODE='42501'; END IF;
 IF p_operation NOT IN('display','retained_read','process','reuse','combine','export') OR p_purpose IS DISTINCT FROM d->>'purpose' THEN RAISE EXCEPTION 'No fixture output policy for that operation/purpose' USING ERRCODE='42501'; END IF;
 -- Explicit fixture policy v1: retained display survives withdrawal of processing/source-read grants.
 -- Retirement of the governing scope/boundary or an unknown policy/version remains a continuing prohibition.
 FOR edge IN SELECT value FROM jsonb_array_elements(d->'input_reads') LOOP
  IF edge->>'policy_id'<>'test.synthetic.qualified_root' OR edge->'policy_version'<>'1'::jsonb
  OR NOT EXISTS(SELECT 1 FROM public.data_use_scopes s JOIN public.client_data_boundaries b ON b.boundary_id=s.boundary_id
    WHERE s.scope_id=(edge->>'data_use_scope_id')::uuid AND s.status='active' AND b.status='active')
  THEN RAISE EXCEPTION 'LINEAGE_UNVERIFIED: continuing display policy unavailable' USING ERRCODE='42501'; END IF;
 END LOOP;
END $$;
