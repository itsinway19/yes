-- Fixed private producer boundary. No production producer is registered by this foundation slice.
-- A product-specific owner must supply its exact resolver/capture/publication contract; clients cannot register one.
CREATE OR REPLACE FUNCTION builderad_private.registered_producer(p_ref jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered canonical producer' USING ERRCODE='42501';
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_publish(p_ref jsonb,p_manifest uuid,p_closure bytea) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered publication owner' USING ERRCODE='42501';
END $$;
CREATE OR REPLACE FUNCTION builderad_private.registered_producer_fields(p_ref jsonb,p_fields jsonb) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 RAISE EXCEPTION 'MATERIAL_KIND_UNAVAILABLE: no registered output reader' USING ERRCODE='42501';
END $$;
-- Continuing input constraints are owned by the registered producer/source policy, not inferred from a live processing grant.
CREATE OR REPLACE FUNCTION builderad_private.registered_output_constraints(p_ref jsonb,p_operation text,p_purpose text) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,builderad_private,pg_temp AS $$
BEGIN
 RAISE EXCEPTION 'LINEAGE_UNVERIFIED: continuing output policy unavailable' USING ERRCODE='42501';
END $$;
