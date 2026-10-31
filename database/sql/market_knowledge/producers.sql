CREATE FUNCTION builderad_private.knowledge_descriptor(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_knowledge_captures;receipt public.business_command_receipts;reads jsonb;
BEGIN
 PERFORM builderad_private.material_reference(p_ref);
 IF p_ref->>'namespace' IS DISTINCT FROM 'market.knowledge' OR coalesce(p_ref->>'key','') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR coalesce(p_ref->>'version','') !~ '^[0-9a-f]{64}$' THEN RAISE EXCEPTION 'Exact shared producer reference required' USING ERRCODE='42501'; END IF;
 SELECT * INTO r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid AND state='published' AND material_digest=decode(p_ref->>'version','hex');
 SELECT * INTO receipt FROM public.business_command_receipts WHERE command_id=r.run_id;
 IF r.run_id IS NULL OR r.completion_attestation_digest IS NULL OR r.publication_manifest_id IS NULL OR r.output_scope_id IS NULL
 OR receipt.action IS DISTINCT FROM 'market.knowledge.capture' OR receipt.actor_user_id IS DISTINCT FROM r.actor_user_id OR receipt.generation IS DISTINCT FROM r.generation
 OR receipt.business_id IS NOT NULL OR receipt.result_ref->>'run_ref' IS DISTINCT FROM r.run_id::text
 OR r.finding->>'method_ref' IS DISTINCT FROM r.method_ref OR r.finding->'evidence_refs' IS DISTINCT FROM r.material_refs THEN RAISE EXCEPTION 'Sealed trusted capture receipt required' USING ERRCODE='42501'; END IF;
 SELECT jsonb_agg((value-'field_keys')||jsonb_build_object('read_command_id',r.run_id) ORDER BY value::text) INTO reads FROM jsonb_array_elements(r.input_reads);
 RETURN jsonb_build_object('ref',p_ref,'resolver_contract_version',2,'scope_kind','market','business_public_id',NULL,'processor_user_id',r.actor_user_id,
 'output_scope_id',r.output_scope_id,'purpose',r.purpose,'processing_operation','process','material_digest',p_ref->'version','producer_receipt_ref','market_knowledge:'||r.run_id,
 'coverage',CASE WHEN r.finding->>'status' IN('unresolved','not_comparable','requires_information') THEN 'PARTIAL' ELSE 'COMPLETE' END,
 'provenance',jsonb_build_object('kind','qualified_market_knowledge'),'input_reads',reads,'publication_manifest_id',r.publication_manifest_id);
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_descriptor(jsonb) FROM PUBLIC,builderad_app,builderad_authority;

CREATE FUNCTION builderad_private.captured_knowledge_validity(p_reads jsonb,p_method text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE edge jsonb;before_row public.market_evidence_revisions;current_row public.market_evidence_revisions;keys text[];key text;status text:='current';
BEGIN
 keys:=CASE p_method WHEN 'offer_equivalence:1' THEN ARRAY['product','service','geography'] WHEN 'qualified_interest_change:1' THEN ARRAY['subject','geography','metric','comparison_basis','value'] WHEN 'qualified_price_observation:1' THEN ARRAY['price','currency','product'] WHEN 'qualified_geography_observation:1' THEN ARRAY['geography'] WHEN 'business_feasibility:1' THEN ARRAY['geography'] ELSE NULL END;
 IF keys IS NULL THEN RETURN jsonb_build_object('status','unknown'); END IF;
 FOR edge IN SELECT value FROM jsonb_array_elements(p_reads) LOOP
  SELECT * INTO before_row FROM public.market_evidence_revisions WHERE evidence_id=(edge#>>'{input,key}')::uuid AND content_digest=decode(edge#>>'{input,version}','hex');
  SELECT e.* INTO current_row FROM public.market_evidence_heads h JOIN public.market_evidence_revisions e ON e.evidence_id=h.evidence_id AND e.revision=h.current_revision WHERE h.evidence_id=before_row.evidence_id;
  IF current_row.evidence_id IS NULL OR current_row.evidence_type IS DISTINCT FROM before_row.evidence_type OR current_row.coverage NOT IN('COMPLETE','PARTIAL')
   OR current_row.valid_until IS NULL OR current_row.valid_until<=clock_timestamp() OR current_row.effective_at>clock_timestamp() THEN status:='review_required'; END IF;
  FOREACH key IN ARRAY keys LOOP
   IF before_row.fields->key IS DISTINCT FROM current_row.fields->key THEN status:='review_required'; END IF;
  END LOOP;
 END LOOP;
 RETURN jsonb_build_object('status',status);
END $$;
REVOKE ALL ON FUNCTION builderad_private.captured_knowledge_validity(jsonb,text) FROM PUBLIC,builderad_app,builderad_authority;
CREATE FUNCTION builderad_private.knowledge_validity(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE r public.market_knowledge_captures;
BEGIN
 PERFORM builderad_private.knowledge_descriptor(p_ref);
 SELECT * INTO STRICT r FROM public.market_knowledge_captures WHERE run_id=(p_ref->>'key')::uuid;
 RETURN builderad_private.captured_knowledge_validity(r.input_reads,r.method_ref);
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_validity(jsonb) FROM PUBLIC,builderad_app,builderad_authority;
-- A published platform licence follows its source policy and explicit licence records.
-- The historical processor's account/session is not custody; current actors still qualify independently at every command/read.
CREATE FUNCTION builderad_private.knowledge_continuation_constraints(p_rights jsonb,p_operation text,p_purpose text) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE entry jsonb;g public.security_action_grants;q public.authority_qualification_receipts;c public.market_source_custody;s public.data_use_scopes;
BEGIN
 FOR entry IN SELECT value FROM jsonb_array_elements(p_rights) WHERE value#>>'{scope,operation}' IN('forward','retain') LOOP
  SELECT * INTO g FROM public.security_action_grants WHERE id=(entry#>>'{grant_row,id}')::bigint;
  SELECT * INTO q FROM public.authority_qualification_receipts WHERE qualification_id=g.qualification_id;
  SELECT * INTO s FROM public.data_use_scopes WHERE scope_id=(entry#>>'{scope,scope_id}')::uuid;
  SELECT * INTO c FROM public.market_source_custody WHERE source_id=s.source_id;
  PERFORM builderad_private.market_policy(s.scope_id);
  IF g.id IS NULL OR g.status IS DISTINCT FROM 'active' OR g.revoked_at IS NOT NULL OR(g.expires_at IS NOT NULL AND g.expires_at<=clock_timestamp())
   OR g.data_use_scope_id IS DISTINCT FROM s.scope_id OR g.action IS DISTINCT FROM 'data_use.'||s.operation OR g.source IS DISTINCT FROM 'qualified_policy' OR g.parent_grant_id IS NOT NULL
   OR s.scope_id IS NULL OR s.status IS DISTINCT FROM 'active' OR s.resource IS DISTINCT FROM 'market.evidence' OR s.rights_domain IS DISTINCT FROM 'market' OR s.boundary_id IS NOT NULL
   OR s.operation IS DISTINCT FROM entry#>>'{scope,operation}' OR s.purpose IS DISTINCT FROM p_purpose OR to_jsonb(s)->'scope_digest' IS DISTINCT FROM entry#>'{scope,scope_digest}'
   OR q.qualification_id IS NULL OR q.status IS DISTINCT FROM 'active' OR q.valid_from>clock_timestamp() OR(q.valid_until IS NOT NULL AND q.valid_until<=clock_timestamp())
   OR q.target_kind IS DISTINCT FROM 'data_use' OR q.target_ref IS DISTINCT FROM s.scope_id OR q.subject_user_id IS DISTINCT FROM g.subject_user_id OR q.issuer_kind IS DISTINCT FROM 'qualified_policy' OR NOT g.action=ANY(q.allowed_actions)
   OR c.source_id IS NULL OR c.status IS DISTINCT FROM 'active' OR c.valid_from>clock_timestamp() OR(c.valid_until IS NOT NULL AND c.valid_until<=clock_timestamp())
   OR c.policy_id IS DISTINCT FROM q.policy_id OR c.policy_version IS DISTINCT FROM q.policy_version OR NOT s.operation=ANY(c.allowed_operations) OR NOT p_purpose=ANY(c.allowed_purposes)
   OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(s.selection->'field_keys') f WHERE NOT f=ANY(c.allowed_fields))
   OR(s.operation='retain' AND(g.expires_at IS NOT NULL OR q.valid_until IS NOT NULL OR c.valid_until IS NOT NULL))
  THEN RAISE EXCEPTION 'Continuing derived-use or retention licence unavailable' USING ERRCODE='42501'; END IF;
 END LOOP;
 IF p_operation NOT IN('display','retained_read','reuse','process','combine','export') THEN RAISE EXCEPTION 'Unsupported derived operation' USING ERRCODE='42501'; END IF;
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_continuation_constraints(jsonb,text,text) FROM PUBLIC,builderad_app,builderad_authority;
