CREATE OR REPLACE FUNCTION builderad_private.registered_producer(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_ref->>'namespace'='market.business_finding' THEN RETURN builderad_private.business_finding_descriptor(p_ref); END IF;
 IF p_ref->>'namespace'='market.knowledge' THEN RETURN builderad_private.knowledge_descriptor(p_ref); END IF;
 RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered canonical producer' USING ERRCODE='42501';
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_publish(p_ref jsonb,p_manifest uuid,p_closure bytea) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 IF p_ref->>'namespace'='market.business_finding' THEN PERFORM builderad_private.business_finding_publish(p_ref,p_manifest,p_closure);RETURN; END IF;
 RAISE EXCEPTION 'Qualified producer coordinator owns atomic publication' USING ERRCODE='42501';
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_fields(p_ref jsonb,p_fields jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_knowledge_captures;
BEGIN
 IF p_ref->>'namespace'='market.business_finding' THEN RETURN builderad_private.business_finding_fields(p_ref,p_fields); END IF;
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 IF p_fields IS DISTINCT FROM '["finding"]'::jsonb THEN RAISE EXCEPTION 'Exact registered output fields required' USING ERRCODE='42501'; END IF;
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 -- Original references remain internal lineage; a derived field licence does not imply their disclosure.
 RETURN jsonb_build_object('finding',(r.finding-'evidence_refs')||jsonb_build_object('validity',builderad_private.knowledge_validity(p_ref)));
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_output_constraints(p_ref jsonb,p_operation text,p_purpose text) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_knowledge_captures;entry jsonb;g public.security_action_grants;q public.authority_qualification_receipts;c public.market_source_custody;
BEGIN
 IF p_ref->>'namespace'='market.business_finding' THEN
  PERFORM builderad_private.business_finding_descriptor(p_ref);
  IF p_purpose IS DISTINCT FROM(SELECT purpose FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid) THEN RAISE EXCEPTION 'Exact private finding purpose required' USING ERRCODE='42501'; END IF;
  PERFORM builderad_private.knowledge_continuation_constraints((SELECT continuation_rights FROM public.market_business_finding_captures WHERE artifact_id=(p_ref->>'key')::uuid),p_operation,p_purpose);RETURN;
 END IF;
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 IF p_purpose IS DISTINCT FROM r.purpose THEN RAISE EXCEPTION 'New purpose needs qualified recapture and publication' USING ERRCODE='42501'; END IF;
 PERFORM builderad_private.knowledge_continuation_constraints(r.continuation_rights,p_operation,p_purpose);
END $$;
