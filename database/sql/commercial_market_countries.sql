-- Country metadata is immutable; currency belongs to versioned configuration.
CREATE TABLE commercial_market_countries (
 market_id uuid PRIMARY KEY REFERENCES commercial_markets(id) ON DELETE RESTRICT,
 country_code char(2) NOT NULL CHECK (country_code IN ('SA','AE','KW','QA','BH','OM','JO')),
 command_id uuid NOT NULL UNIQUE REFERENCES commercial_command_results(command_id) ON DELETE RESTRICT
);
ALTER TABLE commercial_market_countries ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_market_countries FORCE ROW LEVEL SECURITY;
CREATE POLICY country_owner ON commercial_market_countries TO builderad_owner USING(true) WITH CHECK(true);
CREATE POLICY country_read ON commercial_market_countries FOR SELECT TO builderad_app USING (
 builderad_sa01_has('ReadCommercialMarketRegistry',market_id)
 OR EXISTS (SELECT 1 FROM commercial_market_assignments a WHERE a.market_id=commercial_market_countries.market_id)
);
REVOKE ALL ON commercial_market_countries FROM PUBLIC,builderad_app,builderad_authority;
GRANT SELECT ON commercial_market_countries TO builderad_app;
CREATE TRIGGER country_immutable BEFORE UPDATE OR DELETE ON commercial_market_countries
FOR EACH ROW EXECUTE FUNCTION builderad_sa01_immutable();

CREATE FUNCTION builderad_founder_configure_country_market(p_command uuid,p_market uuid,p_label text,p_reason text,
 p_country text,p_currency text,p_configuration_command uuid,p_configuration uuid)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
DECLARE result jsonb; configuration jsonb;
BEGIN
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 IF p_country IS NULL OR p_country NOT IN ('SA','AE','KW','QA','BH','OM','JO')
 OR p_currency IS NULL OR p_currency NOT IN ('SAR','AED','KWD','QAR','BHD','OMR','JOD','USD') THEN
   RAISE EXCEPTION 'Supported country and currency required' USING ERRCODE='23514';
 END IF;
 -- Country, currency and stable child identities participate in the authoritative
 -- parent command fingerprint, including replays from a changed PHP session.
 result:=builderad_configure_commercial_market(p_command,p_market,p_label,
   'founder_country_'||substr(encode(sha256(convert_to(jsonb_build_array(p_reason,p_country,p_currency,p_configuration_command,p_configuration)::text,'UTF8')),'hex'),1,32));
 INSERT INTO commercial_founder_market_notes(command_id,reason) VALUES(p_command,p_reason) ON CONFLICT DO NOTHING;
 INSERT INTO commercial_market_countries(market_id,country_code,command_id) VALUES(p_market,p_country,p_command) ON CONFLICT DO NOTHING;
 configuration:=builderad_create_market_configuration(p_configuration_command,p_market,p_configuration,NULL,p_currency,'founder_country_configuration');
 PERFORM builderad_sa01_require('ConfigureCommercialMarket',p_market);
 IF NOT builderad_sa01_founder() THEN RAISE EXCEPTION 'Active Founder required' USING ERRCODE='42501'; END IF;
 RETURN result||jsonb_build_object('country_code',p_country,'configuration_id',p_configuration,'denomination_code',p_currency);
END $$;
REVOKE ALL ON FUNCTION builderad_founder_configure_country_market(uuid,uuid,text,text,text,text,uuid,uuid) FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_founder_configure_country_market(uuid,uuid,text,text,text,text,uuid,uuid) TO builderad_app;
