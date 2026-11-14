-- Name: builderad_commercial_business_reference(uuid, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_commercial_business_reference(p_scope uuid, p_business uuid) RETURNS TABLE(public_id uuid, name text)
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE v_business bigint;
BEGIN
 SELECT id INTO v_business FROM businesses WHERE businesses.public_id=p_business AND lifecycle IN ('declared_draft','active') FOR SHARE;
 IF v_business IS NULL THEN RAISE EXCEPTION 'Unavailable commercial target' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_business);
 RETURN QUERY SELECT w.public_id,h.display_name FROM businesses w JOIN business_context_heads h ON h.business_id=w.id WHERE w.id=v_business;
END $$;


ALTER FUNCTION public.builderad_commercial_business_reference(p_scope uuid, p_business uuid) OWNER TO builderad_owner;


-- Name: builderad_record_commercial_audit_delivery(uuid, text, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_record_commercial_audit_delivery(p_obligation uuid, p_state text, p_ack uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE o commercial_audit_obligations; d commercial_audit_delivery;
BEGIN
 SELECT * INTO o FROM commercial_audit_obligations WHERE id = p_obligation;
 IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable obligation' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.business_id);
 SELECT * INTO d FROM commercial_audit_delivery WHERE obligation_id = p_obligation FOR UPDATE;
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.business_id);
 IF d.state = 'acknowledged' THEN RETURN; END IF;
 IF p_state = 'acknowledged' AND NOT EXISTS (
   SELECT 1 FROM security_commercial_audit_custody c WHERE c.obligation_id = o.id AND c.fingerprint = o.fingerprint AND c.acknowledgment_id = p_ack
 ) THEN RAISE EXCEPTION 'Actual custody acknowledgment required' USING ERRCODE = '23514'; END IF;
 UPDATE commercial_audit_delivery SET state = p_state,acknowledgment_id = p_ack,attempts = attempts+1,updated_at = clock_timestamp() WHERE obligation_id = p_obligation;
 -- Any wait during the UPDATE must also be fenced; denial rolls back the local effect.
 PERFORM builderad_sa01_require('RetryOrRecoverExistingAuditDelivery',o.target_id,o.business_id);
END $$;


ALTER FUNCTION public.builderad_record_commercial_audit_delivery(p_obligation uuid, p_state text, p_ack uuid) OWNER TO builderad_owner;


-- Name: builderad_register_commercial_scope(uuid, uuid, uuid, uuid, uuid, uuid, text, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_register_commercial_scope(p_command uuid, p_scope uuid, p_business uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r jsonb; v_business bigint; v_owner bigint; c commercial_market_configuration_versions; v_now timestamptz; p_fingerprint text;
BEGIN
 p_fingerprint := encode(sha256(convert_to(jsonb_build_array('RegisterCommercialScope',p_scope,p_business,p_market,p_configuration,p_catalog_reference,p_denomination,p_reason)::text,'UTF8')),'hex');
 SELECT id INTO v_business FROM businesses WHERE public_id=p_business AND lifecycle IN ('declared_draft','active') FOR SHARE;
 IF v_business IS NULL THEN RAISE EXCEPTION 'Active valid beneficiary required' USING ERRCODE = '42501'; END IF;
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_business);
 PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_scope,v_business);
 PERFORM builderad_sa01_require('AssignCommercialMarket',p_scope,v_business,p_configuration);
 SELECT created_by INTO v_owner FROM businesses WHERE id=v_business;
 PERFORM 1 FROM principal_lifecycles WHERE user_id=v_owner FOR SHARE;
 PERFORM 1 FROM users WHERE id=v_owner FOR SHARE;
 IF NOT EXISTS(SELECT 1 FROM principal_lifecycles l JOIN users u ON u.id=l.user_id WHERE l.user_id=v_owner AND l.status='current' AND l.principal_class='advertiser' AND u.email_verified_at IS NOT NULL) THEN
   RAISE EXCEPTION 'Current beneficiary principal required' USING ERRCODE='42501';
 END IF;
 r := builderad_sa01_prior(p_command,'RegisterCommercialScope',p_scope,v_business,p_fingerprint); IF r IS NOT NULL THEN RETURN r; END IF;
 PERFORM 1 FROM commercial_market_assignability WHERE market_id = p_market AND configuration_id = p_configuration FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Exact market configuration is not assignable' USING ERRCODE = '23514'; END IF;
 SELECT * INTO c FROM commercial_market_configuration_versions WHERE id = p_configuration AND market_id = p_market;
 IF NOT FOUND OR c.catalog_reference_id IS DISTINCT FROM p_catalog_reference OR c.denomination_code IS DISTINCT FROM p_denomination OR c.catalog_reference_id IS NULL OR c.denomination_code IS NULL THEN
   RAISE EXCEPTION 'Exact Catalog and denomination configuration required' USING ERRCODE = '23514';
 END IF;
 PERFORM builderad_sa01_require_committed_catalog(p_catalog_reference,p_market);
 PERFORM builderad_sa01_require('RegisterCommercialScope',p_scope,v_business);
 PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_scope,v_business);
 PERFORM builderad_sa01_require('AssignCommercialMarket',p_scope,v_business,p_configuration);
 v_now := clock_timestamp();
 INSERT INTO commercial_scopes(id) VALUES(p_scope);
 INSERT INTO commercial_beneficiary_bindings(scope_id,business_id,decided_at,effective_at) VALUES(p_scope,v_business,v_now,v_now);
 INSERT INTO commercial_market_assignments(scope_id,business_id,market_id,configuration_id,catalog_reference_id,denomination_code,decided_at,effective_at)
 VALUES(p_scope,v_business,p_market,p_configuration,p_catalog_reference,p_denomination,v_now,v_now);
 RETURN builderad_sa01_finish(p_command,'RegisterCommercialScope',p_scope,v_business,p_fingerprint,p_reason,
   jsonb_build_object('scope_id',p_scope,'business_public_id',p_business,'market_id',p_market,'configuration_id',p_configuration,
   'catalog_reference_id',p_catalog_reference,'catalog_identity',(SELECT catalog_identity FROM commercial_catalog_references WHERE id = p_catalog_reference),
   'catalog_version',(SELECT catalog_version FROM commercial_catalog_references WHERE id = p_catalog_reference),
   'configuration_version',c.version,'denomination_code',p_denomination,'formation_state','CONTEXT_RECORDED','agreement_state','UNKNOWN','amount_state','UNKNOWN'));
END $$;


ALTER FUNCTION public.builderad_register_commercial_scope(p_command uuid, p_scope uuid, p_business uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) OWNER TO builderad_owner;


-- Name: builderad_sa01_context_visible(uuid, bigint); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_context_visible(p_scope uuid, p_business bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
 SELECT builderad_sa01_has('ReadCommercialContextAdvertiser', p_scope, p_business)
     OR builderad_sa01_has('ReadCommercialContextInternal', p_scope, p_business)
$$;


ALTER FUNCTION public.builderad_sa01_context_visible(p_scope uuid, p_business bigint) OWNER TO builderad_owner;

CREATE FUNCTION builderad_private.commercial_business_reader(p_business bigint, p_actor bigint) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
DECLARE grant_id bigint;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.businesses WHERE id=p_business AND lifecycle IN('declared_draft','active')) THEN
  RETURN false;
 END IF;
 FOR grant_id IN SELECT id FROM public.security_action_grants
  WHERE business_id=p_business AND subject_user_id=p_actor AND scope_kind='business'
   AND action='business.context.read' AND status='active' AND revoked_at IS NULL ORDER BY id
 LOOP
  BEGIN
   PERFORM builderad_private.verify_scope_path('business',p_business::text,p_actor,'business.context.read',
    builderad_private.business_selection(grant_id),NULL,'{}',false);
   RETURN true;
  EXCEPTION WHEN insufficient_privilege THEN CONTINUE;
  END;
 END LOOP;
 RETURN false;
END $$;
ALTER FUNCTION builderad_private.commercial_business_reader(bigint,bigint) OWNER TO builderad_scope_executor;


-- Name: builderad_sa01_exact_grant(text, uuid, bigint, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_exact_grant(p_action text, p_target uuid, p_business bigint DEFAULT NULL::bigint, p_version uuid DEFAULT NULL::uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
    SELECT EXISTS (
      SELECT 1 FROM builderad_signed_context() c
      JOIN principal_lifecycles l ON l.user_id = c.actor_user_id AND l.status = 'current'
      JOIN users u ON u.id = l.user_id AND u.email_verified_at IS NOT NULL
      JOIN security_action_grants g ON g.subject_user_id = l.user_id AND g.subject_generation = l.generation
      WHERE c.phase='principal'
        AND g.action = p_action AND g.scope_kind = 'commercial' AND g.commercial_target_id = p_target
        AND g.business_id IS NOT DISTINCT FROM p_business
        AND g.commercial_version_id IS NOT DISTINCT FROM p_version
        AND g.status = 'active' AND g.revoked_at IS NULL
        AND (g.expires_at IS NULL OR g.expires_at > clock_timestamp())
        AND ((l.principal_class = 'staff' AND p_action <> 'ReadCommercialContextAdvertiser'
          AND EXISTS (SELECT 1 FROM internal_personnel p WHERE p.user_id = l.user_id AND p.status = 'active'))
          OR (l.principal_class = 'advertiser' AND p_action = 'ReadCommercialContextAdvertiser'
          AND builderad_private.commercial_business_reader(p_business,l.user_id)))
    )
$$;


ALTER FUNCTION public.builderad_sa01_exact_grant(p_action text, p_target uuid, p_business bigint, p_version uuid) OWNER TO builderad_owner;


-- Name: builderad_sa01_finish(uuid, text, uuid, bigint, text, text, jsonb); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_finish(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text, p_reason text, p_result jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE v_actor bigint; v_generation bigint; v_envelope jsonb; v_obligation uuid; v_now timestamptz := clock_timestamp();
BEGIN
 SELECT c.actor_user_id,l.generation INTO v_actor,v_generation FROM builderad_signed_context() c JOIN principal_lifecycles l ON l.user_id = c.actor_user_id;
 INSERT INTO commercial_command_results(command_id,effect,target_id,business_id,request_fingerprint,result,actor_user_id,actor_generation,reason_code,decided_at)
 VALUES(p_command,p_effect,p_target,p_business,p_fingerprint,p_result,v_actor,v_generation,p_reason,v_now);
 v_envelope := jsonb_build_object('command_id',p_command,'effect',p_effect,'target_id',p_target,'business_id',p_business,
 'request_fingerprint',p_fingerprint,'result',p_result,'actor_user_id',v_actor,'actor_generation',v_generation,
 'reason_code',p_reason,'policy_version','SA01-V3','decided_at',v_now);
 INSERT INTO commercial_audit_obligations(command_id,target_id,business_id,envelope,fingerprint)
 VALUES(p_command,p_target,p_business,v_envelope,encode(sha256(convert_to(v_envelope::text,'UTF8')),'hex')) RETURNING id INTO v_obligation;
 INSERT INTO commercial_audit_delivery(obligation_id) VALUES(v_obligation);
 -- Effect-time fence AFTER all material/unique/FK/obligation waits. A failed fence
 -- rolls back the entire local command; no accepted fact survives expired authority.
 IF p_effect = 'RegisterCommercialScope' THEN
   PERFORM builderad_sa01_require('RegisterCommercialScope',p_target,p_business);
   PERFORM builderad_sa01_require('RecordBeneficiaryBinding',p_target,p_business);
   PERFORM builderad_sa01_require('AssignCommercialMarket',p_target,p_business,(p_result->>'configuration_id')::uuid);
 ELSE
   PERFORM builderad_sa01_require(CASE WHEN p_effect = 'ConfigureCommercialMarketVersion' THEN 'ConfigureCommercialMarket' ELSE p_effect END,
     p_target,p_business,CASE WHEN p_effect = 'RecordOrVerifyApprovedMarketReference' THEN (p_result->>'catalog_reference_id')::uuid ELSE NULL END);
 END IF;
 RETURN p_result;
END $$;


ALTER FUNCTION public.builderad_sa01_finish(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text, p_reason text, p_result jsonb) OWNER TO builderad_owner;


-- Name: builderad_sa01_founder(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_founder() RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
 SELECT EXISTS (
   SELECT 1 FROM builderad_signed_context() c
   JOIN principal_lifecycles l ON l.user_id=c.actor_user_id AND l.status='current' AND l.principal_class='staff'
   JOIN users u ON u.id=l.user_id AND u.email_verified_at IS NOT NULL
   JOIN internal_personnel p ON p.user_id=l.user_id AND p.kind='founder' AND p.status='active'
   WHERE c.phase='principal'
 )
$$;


ALTER FUNCTION public.builderad_sa01_founder() OWNER TO builderad_owner;


-- Name: builderad_sa01_founder_businesses(); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_founder_businesses() RETURNS TABLE(business_public_id uuid, scope_id uuid)
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
 SELECT w.public_id,b.scope_id FROM businesses w
 LEFT JOIN commercial_beneficiary_bindings b ON b.business_id=w.id
 WHERE builderad_sa01_founder() AND w.lifecycle IN ('declared_draft','active')
$$;


ALTER FUNCTION public.builderad_sa01_founder_businesses() OWNER TO builderad_owner;


-- Name: builderad_sa01_has(text, uuid, bigint, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_has(p_action text, p_target uuid, p_business bigint DEFAULT NULL::bigint, p_version uuid DEFAULT NULL::uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
 SELECT builderad_sa01_exact_grant(p_action,p_target,p_business,p_version) OR (
   builderad_sa01_founder() AND p_target IS NOT NULL
   AND (
     (p_action IN ('ReadCommercialMarketRegistry','ConfigureCommercialMarket','EnableCommercialMarket','DisableCommercialMarket') AND p_business IS NULL AND p_version IS NULL)
     OR (p_action='RecordOrVerifyApprovedMarketReference' AND p_business IS NULL AND p_version IS NOT NULL)
     OR (p_action IN ('RegisterCommercialScope','RecordBeneficiaryBinding','ReadCommercialContextInternal') AND p_business IS NOT NULL AND p_version IS NULL)
     OR (p_action='AssignCommercialMarket' AND p_business IS NOT NULL AND p_version IS NOT NULL)
     OR (p_action IN ('ReadCommercialRegistrationReceipt','ReadCommercialAuditDeliveryState','RetryOrRecoverExistingAuditDelivery') AND p_version IS NULL)
   )
 )
$$;


ALTER FUNCTION public.builderad_sa01_has(p_action text, p_target uuid, p_business bigint, p_version uuid) OWNER TO builderad_owner;


-- Name: builderad_sa01_prior(uuid, text, uuid, bigint, text); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_prior(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE r commercial_command_results;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('sa01.command.'||p_command::text,0));
 SELECT * INTO r FROM commercial_command_results WHERE command_id = p_command;
 IF FOUND THEN
   IF r.effect <> p_effect OR r.target_id <> p_target OR r.business_id IS DISTINCT FROM p_business OR r.request_fingerprint <> p_fingerprint THEN
     RAISE EXCEPTION 'Command payload conflict' USING ERRCODE = '23505';
   END IF;
   RETURN r.result;
 END IF;
 RETURN NULL;
END $$;


ALTER FUNCTION public.builderad_sa01_prior(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text) OWNER TO builderad_owner;


-- Name: builderad_sa01_require(text, uuid, bigint, uuid); Type: FUNCTION; Schema: public; Owner: builderad_owner
--

CREATE FUNCTION public.builderad_sa01_require(p_action text, p_target uuid, p_business bigint DEFAULT NULL::bigint, p_version uuid DEFAULT NULL::uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public', 'pg_temp'
    AS $$
DECLARE v_actor bigint;
BEGIN
    IF session_user <> 'builderad_app' OR NOT builderad_sa01_has(p_action, p_target, p_business, p_version) THEN
      RAISE EXCEPTION 'Exact current commercial effect required' USING ERRCODE = '42501';
    END IF;
    SELECT actor_user_id INTO v_actor FROM builderad_signed_context();
    -- Locks retained to caller commit: invalidation-first denies; commit-first preserves historical fact.
    PERFORM 1 FROM principal_lifecycles WHERE user_id = v_actor FOR SHARE;
    PERFORM 1 FROM internal_personnel WHERE user_id = v_actor FOR SHARE;
    PERFORM 1 FROM security_action_grants
      WHERE subject_user_id = v_actor AND action = p_action AND scope_kind = 'commercial'
        AND commercial_target_id = p_target AND business_id IS NOT DISTINCT FROM p_business
        AND commercial_version_id IS NOT DISTINCT FROM p_version FOR SHARE;
    IF NOT builderad_sa01_has(p_action, p_target, p_business, p_version) THEN
      RAISE EXCEPTION 'Invalidated commercial authority' USING ERRCODE = '42501';
    END IF;
END $$;


ALTER FUNCTION public.builderad_sa01_require(p_action text, p_target uuid, p_business bigint, p_version uuid) OWNER TO builderad_owner;


-- Name: FUNCTION builderad_commercial_business_reference(p_scope uuid, p_business uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_commercial_business_reference(p_scope uuid, p_business uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_commercial_business_reference(p_scope uuid, p_business uuid) TO builderad_app;



-- Name: FUNCTION builderad_record_commercial_audit_delivery(p_obligation uuid, p_state text, p_ack uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_record_commercial_audit_delivery(p_obligation uuid, p_state text, p_ack uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_record_commercial_audit_delivery(p_obligation uuid, p_state text, p_ack uuid) TO builderad_app;



-- Name: FUNCTION builderad_register_commercial_scope(p_command uuid, p_scope uuid, p_business uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_register_commercial_scope(p_command uuid, p_scope uuid, p_business uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_register_commercial_scope(p_command uuid, p_scope uuid, p_business uuid, p_market uuid, p_configuration uuid, p_catalog_reference uuid, p_denomination text, p_reason text) TO builderad_app;



-- Name: FUNCTION builderad_sa01_context_visible(p_scope uuid, p_business bigint); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_context_visible(p_scope uuid, p_business bigint) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_sa01_context_visible(p_scope uuid, p_business bigint) TO builderad_app;



-- Name: FUNCTION builderad_sa01_exact_grant(p_action text, p_target uuid, p_business bigint, p_version uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_exact_grant(p_action text, p_target uuid, p_business bigint, p_version uuid) FROM PUBLIC;



-- Name: FUNCTION builderad_sa01_finish(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text, p_reason text, p_result jsonb); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_finish(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text, p_reason text, p_result jsonb) FROM PUBLIC;



-- Name: FUNCTION builderad_sa01_founder(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_founder() FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_sa01_founder() TO builderad_app;



-- Name: FUNCTION builderad_sa01_founder_businesses(); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_founder_businesses() FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_sa01_founder_businesses() TO builderad_app;



-- Name: FUNCTION builderad_sa01_has(p_action text, p_target uuid, p_business bigint, p_version uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_has(p_action text, p_target uuid, p_business bigint, p_version uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_sa01_has(p_action text, p_target uuid, p_business bigint, p_version uuid) TO builderad_app;



-- Name: FUNCTION builderad_sa01_prior(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_prior(p_command uuid, p_effect text, p_target uuid, p_business bigint, p_fingerprint text) FROM PUBLIC;



-- Name: FUNCTION builderad_sa01_require(p_action text, p_target uuid, p_business bigint, p_version uuid); Type: ACL; Schema: public; Owner: builderad_owner
--

REVOKE ALL ON FUNCTION public.builderad_sa01_require(p_action text, p_target uuid, p_business bigint, p_version uuid) FROM PUBLIC;
GRANT ALL ON FUNCTION public.builderad_sa01_require(p_action text, p_target uuid, p_business bigint, p_version uuid) TO builderad_app;


CREATE FUNCTION public.builderad_commercial_business_public_by_id(p_business bigint) RETURNS uuid
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
 SELECT b.public_id FROM public.businesses b WHERE b.id=p_business AND (
  public.builderad_sa01_founder() OR EXISTS(SELECT 1 FROM public.commercial_beneficiary_bindings binding
    WHERE binding.business_id=b.id AND public.builderad_sa01_context_visible(binding.scope_id,b.id)))
$$;
REVOKE ALL ON FUNCTION public.builderad_commercial_business_public_by_id(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_commercial_business_public_by_id(bigint) TO builderad_app;
CREATE FUNCTION public.builderad_commercial_advertiser_context(p_business uuid)
RETURNS TABLE(scope_id uuid, market_id uuid, market_label varchar, configuration_id uuid,
 catalog_identity uuid, catalog_version varchar, denomination_code text, effective_at timestamptz)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
 SELECT a.scope_id,a.market_id,m.label,a.configuration_id,c.catalog_identity,c.catalog_version,
  a.denomination_code::text,a.effective_at
 FROM public.businesses b
 JOIN public.commercial_beneficiary_bindings x ON x.business_id=b.id
 JOIN public.commercial_market_assignments a ON a.scope_id=x.scope_id AND a.business_id=b.id
 JOIN public.commercial_markets m ON m.id=a.market_id
 JOIN public.commercial_catalog_references c ON c.id=a.catalog_reference_id
 WHERE b.public_id=p_business AND b.lifecycle IN('declared_draft','active')
  AND x.effective_at<=clock_timestamp() AND a.effective_at<=clock_timestamp()
  AND public.builderad_sa01_exact_grant('ReadCommercialContextAdvertiser',x.scope_id,b.id)
$$;
REVOKE ALL ON FUNCTION public.builderad_commercial_advertiser_context(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_commercial_advertiser_context(uuid) TO builderad_app;
