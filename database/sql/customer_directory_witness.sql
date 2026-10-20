-- Reuse the directory's canonical private authority owner without building its rows.
CREATE OR REPLACE FUNCTION builderad_customer_directory_witness() RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=public,pg_temp AS $$
BEGIN
 PERFORM builderad_customer_administrator();
 RETURN true;
END $$;
REVOKE ALL ON FUNCTION builderad_customer_directory_witness() FROM PUBLIC,builderad_authority;
GRANT EXECUTE ON FUNCTION builderad_customer_directory_witness() TO builderad_app;
