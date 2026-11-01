-- Fixed producer-owned effects under the already authorized resource command/permit.
CREATE OR REPLACE FUNCTION builderad_private.knowledge_retention_withdrawal() RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE prior public.market_knowledge_captures;r public.market_knowledge_captures;affected boolean;retained jsonb;private_prior public.market_business_finding_captures;private_row public.market_business_finding_captures;
BEGIN
 IF TG_TABLE_NAME='security_action_grants' THEN
  IF NEW.action IS DISTINCT FROM 'data_use.retain' AND OLD.action IS DISTINCT FROM 'data_use.retain' THEN RETURN NEW;END IF;
 ELSIF TG_TABLE_NAME='authority_qualification_receipts' THEN
  IF NOT 'data_use.retain'=ANY(NEW.allowed_actions) AND NOT 'data_use.retain'=ANY(OLD.allowed_actions) THEN RETURN NEW;END IF;
 ELSIF TG_TABLE_NAME='data_use_scopes' THEN
  IF NEW.operation IS DISTINCT FROM 'retain' AND OLD.operation IS DISTINCT FROM 'retain' THEN RETURN NEW;END IF;
 END IF;
 FOR prior IN SELECT c.* FROM public.market_knowledge_captures c WHERE c.state='published' AND EXISTS(
  SELECT 1 FROM jsonb_array_elements(c.continuation_rights) v WHERE v#>>'{scope,operation}'='retain' AND CASE TG_TABLE_NAME
   WHEN 'authority_qualification_receipts' THEN v#>>'{qualification_row,qualification_id}'=to_jsonb(NEW)->>'qualification_id'
   WHEN 'security_action_grants' THEN v#>>'{grant_row,id}'=to_jsonb(NEW)->>'id'
   WHEN 'market_source_custody' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'source_id'
   WHEN 'source_registry' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'id'
   WHEN 'data_use_scopes' THEN v#>>'{scope,scope_id}'=to_jsonb(NEW)->>'scope_id'
   ELSE false END
 ) ORDER BY c.run_id FOR UPDATE LOOP
  SELECT jsonb_agg(value) INTO retained FROM jsonb_array_elements(prior.continuation_rights) WHERE value#>>'{scope,operation}'='retain';
  affected:=false;
  BEGIN PERFORM builderad_private.knowledge_continuation_constraints(retained,'retained_read',prior.purpose);
  EXCEPTION WHEN insufficient_privilege THEN affected:=true;END;
  IF affected THEN
   r:=prior;r.state:='retention_removed';r.finding:=NULL;
   PERFORM builderad_private.permit('public.market_knowledge_captures','update',to_jsonb(prior),to_jsonb(r));
   UPDATE public.market_knowledge_captures SET finding=NULL,state=r.state WHERE run_id=r.run_id;
  END IF;
 END LOOP;
 FOR private_prior IN SELECT c.* FROM public.market_business_finding_captures c WHERE c.state='published' AND EXISTS(
  SELECT 1 FROM jsonb_array_elements(c.continuation_rights) v WHERE v#>>'{scope,operation}'='retain' AND CASE TG_TABLE_NAME
   WHEN 'authority_qualification_receipts' THEN v#>>'{qualification_row,qualification_id}'=to_jsonb(NEW)->>'qualification_id'
   WHEN 'security_action_grants' THEN v#>>'{grant_row,id}'=to_jsonb(NEW)->>'id'
   WHEN 'market_source_custody' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'source_id'
   WHEN 'source_registry' THEN v#>>'{scope,source_id}'=to_jsonb(NEW)->>'id'
   WHEN 'data_use_scopes' THEN v#>>'{scope,scope_id}'=to_jsonb(NEW)->>'scope_id'
   ELSE false END
 ) ORDER BY c.artifact_id FOR UPDATE LOOP
  SELECT jsonb_agg(value) INTO retained FROM jsonb_array_elements(private_prior.continuation_rights) WHERE value#>>'{scope,operation}'='retain';
  affected:=false;
  BEGIN PERFORM builderad_private.knowledge_continuation_constraints(retained,'retained_read',private_prior.purpose);
  EXCEPTION WHEN insufficient_privilege THEN affected:=true;END;
  IF affected THEN
   private_row:=private_prior;private_row.state:='retention_removed';
   PERFORM builderad_private.permit('public.market_business_finding_captures','update',to_jsonb(private_prior),to_jsonb(private_row));
   UPDATE public.market_business_finding_captures SET state=private_row.state WHERE artifact_id=private_row.artifact_id;
  END IF;
 END LOOP;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION builderad_private.knowledge_retention_withdrawal() FROM PUBLIC,builderad_app,builderad_authority;
