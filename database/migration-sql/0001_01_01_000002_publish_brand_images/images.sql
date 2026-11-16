SET LOCAL ROLE builderad_scope_executor;
DO $verify$
BEGIN
 IF builderad_private.catalog_digest() IS DISTINCT FROM builderad_private.installation_digest() THEN
  RAISE EXCEPTION 'Brand image migration requires a sealed predecessor catalog';
 END IF;
END $verify$;
RESET ROLE;

CREATE TABLE public.global_appearance_images (
    sha256 text PRIMARY KEY CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    content_base64 text NOT NULL CHECK (length(content_base64) <= 699052),
    width integer NOT NULL CHECK (width BETWEEN 1 AND 2048),
    height integer NOT NULL CHECK (height BETWEEN 1 AND 2048),
    published_by bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
    published_at timestamptz NOT NULL DEFAULT now(),
    CHECK (octet_length(decode(content_base64, 'base64')) BETWEEN 1 AND 524288),
    CHECK (encode(sha256(decode(content_base64, 'base64')), 'hex') = sha256)
);
ALTER TABLE public.global_appearance_images OWNER TO builderad_owner;
REVOKE ALL ON public.global_appearance_images FROM PUBLIC;
GRANT SELECT ON public.global_appearance_images TO builderad_app;
GRANT SELECT, INSERT ON public.global_appearance_images TO builderad_authority;

ALTER TABLE public.global_appearance
    ADD COLUMN logo_sha256 text REFERENCES public.global_appearance_images(sha256) ON DELETE RESTRICT,
    ADD COLUMN favicon_sha256 text REFERENCES public.global_appearance_images(sha256) ON DELETE RESTRICT;

DO $seal$
DECLARE seal bytea;
BEGIN
 SET LOCAL ROLE builderad_scope_executor;
 seal:=builderad_private.catalog_digest();
 RESET ROLE;
 EXECUTE format('CREATE OR REPLACE FUNCTION builderad_private.installation_digest() RETURNS bytea LANGUAGE sql IMMUTABLE SECURITY INVOKER SET search_path=pg_catalog,builderad_private,pg_temp AS %L', 'SELECT decode('''||encode(seal,'hex')||''',''hex'')');
END $seal$;
