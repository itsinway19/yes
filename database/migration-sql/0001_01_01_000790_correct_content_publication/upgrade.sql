-- Refuse an ambiguous pre-existing route rather than silently selecting a winner.
DO $check$
DECLARE kind text; found boolean;
BEGIN
  FOREACH kind IN ARRAY ARRAY['content_pages', 'content_articles', 'content_categories'] LOOP
    EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I a JOIN %I b ON a.id < b.id AND a.deleted_at IS NULL AND b.deleted_at IS NULL AND (lower(a.slug_ar) = lower(b.slug_en) OR lower(a.slug_en) = lower(b.slug_ar)))', kind, kind)
      INTO STRICT found;
    IF found THEN RAISE EXCEPTION 'Existing cross-language slug collision in %', kind; END IF;
  END LOOP;
END $check$;

ALTER TABLE content_page_revisions ADD COLUMN slug_ar text, ADD COLUMN slug_en text, ADD COLUMN position integer;
ALTER TABLE content_article_revisions ADD COLUMN slug_ar text, ADD COLUMN slug_en text,
  ADD COLUMN category_id bigint REFERENCES content_categories(id) ON DELETE RESTRICT, ADD COLUMN position integer;

UPDATE content_page_revisions r SET slug_ar = p.slug_ar, slug_en = p.slug_en, position = p.position
  FROM content_pages p WHERE r.page_id = p.id;
UPDATE content_article_revisions r SET slug_ar = a.slug_ar, slug_en = a.slug_en,
  category_id = a.category_id, position = a.position FROM content_articles a WHERE r.article_id = a.id;

ALTER TABLE content_page_revisions ALTER COLUMN slug_ar SET NOT NULL, ALTER COLUMN slug_en SET NOT NULL,
  ALTER COLUMN position SET NOT NULL, ADD CONSTRAINT content_page_revisions_position_check CHECK (position >= 0);
ALTER TABLE content_article_revisions ALTER COLUMN slug_ar SET NOT NULL, ALTER COLUMN slug_en SET NOT NULL,
  ALTER COLUMN position SET NOT NULL, ADD CONSTRAINT content_article_revisions_position_check CHECK (position >= 0);

CREATE FUNCTION public.builderad_content_unique_slug_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $guard$
DECLARE slug text; conflict_found boolean;
BEGIN
  IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
  FOR slug IN SELECT DISTINCT lower(value) FROM unnest(ARRAY[NEW.slug_ar, NEW.slug_en]) AS value ORDER BY 1 LOOP
    PERFORM pg_advisory_xact_lock(hashtextextended(TG_TABLE_NAME || ':' || slug, 0));
  END LOOP;
  EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE id <> $1 AND deleted_at IS NULL AND (lower(slug_ar) = ANY($2) OR lower(slug_en) = ANY($2)))', TG_TABLE_NAME)
    INTO conflict_found USING NEW.id, ARRAY[lower(NEW.slug_ar), lower(NEW.slug_en)];
  IF conflict_found THEN RAISE EXCEPTION 'Slug is already used in %', TG_TABLE_NAME USING ERRCODE = '23505'; END IF;
  RETURN NEW;
END $guard$;

CREATE TRIGGER content_pages_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON content_pages
  FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();
CREATE TRIGGER content_articles_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON content_articles
  FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();
CREATE TRIGGER content_categories_unique_slug_guard BEFORE INSERT OR UPDATE OF slug_ar, slug_en, deleted_at ON content_categories
  FOR EACH ROW EXECUTE FUNCTION public.builderad_content_unique_slug_guard();

CREATE FUNCTION public.builderad_content_write_audit(p_actor bigint, p_event text, p_reason text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $audit$
DECLARE generation_number bigint;
BEGIN
  IF p_event IS NULL OR p_event NOT IN (
    'content_section_saved', 'content_section_deleted', 'content_link_saved',
    'content_link_deleted', 'content_page_created', 'content_page_draft_saved',
    'content_page_published', 'content_page_unpublished', 'content_page_deleted',
    'content_page_restored', 'content_page_revision_restored',
    'content_article_created', 'content_article_draft_saved',
    'content_article_published', 'content_article_unpublished',
    'content_article_deleted', 'content_article_restored',
    'content_article_revision_restored', 'content_category_saved',
    'content_category_deleted', 'content_footer_published', 'content_reordered'
  ) THEN RAISE EXCEPTION 'Unsupported content audit event'; END IF;
  SELECT generation INTO generation_number FROM public.principal_lifecycles WHERE user_id = p_actor;
  IF generation_number IS NULL THEN RAISE EXCEPTION 'Unknown content audit actor'; END IF;
  INSERT INTO public.security_audit_events(actor_user_id, subject_user_id, event, reason,
    prior_status, new_status, prior_generation, new_generation, occurred_at)
  VALUES (p_actor, p_actor, p_event, left(p_reason, 255), 'current', 'current',
    generation_number, generation_number, now());
END $audit$;
GRANT CREATE ON SCHEMA public TO builderad_scope_executor;
ALTER FUNCTION public.builderad_content_write_audit(bigint, text, text) OWNER TO builderad_scope_executor;
REVOKE ALL ON FUNCTION public.builderad_content_write_audit(bigint, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.builderad_content_write_audit(bigint, text, text) TO builderad_app;
REVOKE CREATE ON SCHEMA public FROM builderad_scope_executor;

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
