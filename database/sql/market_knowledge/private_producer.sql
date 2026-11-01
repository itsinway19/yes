CREATE FUNCTION builderad_private.business_finding_descriptor(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace' IS DISTINCT FROM 'market.business_finding' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact private producer reference required' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid AND state IN('prepared','published') AND material_digest=decode(p_ref->>'version','hex');
 SELECT * INTO run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 IF r.artifact_id IS NULL OR run.completion_attestation_digest IS NULL OR run.state NOT IN('validated','abstained') OR run.finding IS NULL
 OR run.business_id IS DISTINCT FROM r.business_id OR run.actor_user_id IS DISTINCT FROM r.processor_user_id OR run.purpose IS DISTINCT FROM r.purpose THEN RAISE EXCEPTION 'Trusted same-Business producer required' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object('ref',p_ref,'resolver_contract_version',1,'business_public_id',(SELECT public_id FROM public.businesses WHERE id=r.business_id),
  'processor_user_id',r.processor_user_id,'output_scope_id',r.output_scope_id,'purpose',r.purpose,'processing_operation','process','material_digest',p_ref->'version',
  'producer_receipt_ref','market_business_finding:'||r.artifact_id,'coverage',CASE run.state WHEN 'validated' THEN 'COMPLETE' ELSE 'PARTIAL' END,
  'provenance',jsonb_build_object('kind','private_market_finding'),'input_reads',r.input_reads,'publication_manifest_id',r.publication_manifest_id);
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_finding_descriptor(jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_private.business_finding_validity(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;v jsonb;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid;
 SELECT * INTO STRICT run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 v:=builderad_private.captured_knowledge_validity(r.input_reads,run.method_ref);
 IF run.method_ref='business_feasibility:1' AND r.context_snapshot->>'context_version' IS DISTINCT FROM (SELECT context_version::text FROM public.business_context_heads WHERE business_id=r.business_id)
 THEN v:=jsonb_build_object('status','review_required'); END IF;
 RETURN v;
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_finding_validity(jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_private.business_finding_fields(p_ref jsonb,p_fields jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_business_finding_captures;run public.market_processing_runs;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 IF p_fields IS DISTINCT FROM '["finding"]'::jsonb THEN RAISE EXCEPTION 'Exact private output field licence required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid;
 SELECT * INTO STRICT run FROM public.market_processing_runs WHERE run_id=r.processing_run_id;
 RETURN jsonb_build_object('finding',(run.finding-'evidence_refs')||jsonb_build_object('validity',builderad_private.business_finding_validity(p_ref)));
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_finding_fields(jsonb,jsonb) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_private.business_finding_publish(p_ref jsonb,p_manifest uuid,p_closure bytea) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.market_business_finding_captures;r public.market_business_finding_captures;
BEGIN
 PERFORM builderad_private.business_finding_descriptor(p_ref);
 SELECT * INTO STRICT prior FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid FOR UPDATE;r:=prior;
 IF builderad_private.business_finding_validity(p_ref)->>'status' IS DISTINCT FROM 'current' THEN RAISE EXCEPTION 'Private finding dependencies changed before publication' USING ERRCODE='42501'; END IF;
 IF prior.state<>'prepared' OR NOT EXISTS(SELECT 1 FROM public.material_lineage_manifests m WHERE m.manifest_id=p_manifest AND m.material_namespace='market.business_finding'
  AND m.material_key=prior.artifact_id::text AND m.material_digest=prior.material_digest AND m.closure_digest=p_closure AND m.resolver_contract_version=1 AND m.state='sealed')
 THEN RAISE EXCEPTION 'Exact atomic private publication manifest required' USING ERRCODE='42501'; END IF;
 r.state:='published';r.publication_manifest_id:=p_manifest;
 PERFORM builderad_private.permit('public.market_business_finding_captures','update',to_jsonb(prior),to_jsonb(r));
 UPDATE public.market_business_finding_captures SET state=r.state,publication_manifest_id=r.publication_manifest_id WHERE artifact_id=r.artifact_id;
END $$;
REVOKE ALL ON FUNCTION builderad_private.business_finding_publish(jsonb,uuid,bytea) FROM PUBLIC,builderad_app,builderad_authority;
