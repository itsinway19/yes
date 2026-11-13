SET ROLE builderad_scope_executor;
DROP FUNCTION IF EXISTS public.builderad_content_write_audit(bigint, text, text);
RESET ROLE;
DROP TRIGGER IF EXISTS content_pages_unique_slug_guard ON content_pages;
DROP TRIGGER IF EXISTS content_articles_unique_slug_guard ON content_articles;
DROP TRIGGER IF EXISTS content_categories_unique_slug_guard ON content_categories;
DROP FUNCTION IF EXISTS public.builderad_content_unique_slug_guard();
ALTER TABLE content_article_revisions DROP COLUMN IF EXISTS position, DROP COLUMN IF EXISTS category_id,
  DROP COLUMN IF EXISTS slug_en, DROP COLUMN IF EXISTS slug_ar;
ALTER TABLE content_page_revisions DROP COLUMN IF EXISTS position, DROP COLUMN IF EXISTS slug_en,
  DROP COLUMN IF EXISTS slug_ar;
DO $install$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.legacy_catalog_digest();
 RESET ROLE;
 EXECUTE format('CREATE OR REPLACE FUNCTION builderad_private.legacy_installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L',
  'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
 REVOKE ALL ON FUNCTION builderad_private.legacy_installation_digest() FROM PUBLIC,builderad_app,builderad_authority;
 GRANT EXECUTE ON FUNCTION builderad_private.legacy_installation_digest() TO builderad_scope_executor;
END $install$;
