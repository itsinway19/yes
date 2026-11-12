CREATE TABLE content_pages (
    id bigserial PRIMARY KEY,
    public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    published_revision_id bigint,
    draft_revision_id bigint,
    published_at timestamptz,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    deleted_at timestamptz,
    created_by bigint REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX content_pages_slug_ar_unique ON content_pages (lower(slug_ar)) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX content_pages_slug_en_unique ON content_pages (lower(slug_en)) WHERE deleted_at IS NULL;
CREATE INDEX content_pages_public_order ON content_pages (position, id) WHERE deleted_at IS NULL;

CREATE TABLE content_page_revisions (
    id bigserial PRIMARY KEY,
    page_id bigint NOT NULL REFERENCES content_pages(id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision >= 1),
    title_ar text NOT NULL,
    title_en text NOT NULL,
    excerpt_ar text,
    excerpt_en text,
    body_ar text NOT NULL DEFAULT '',
    body_en text NOT NULL DEFAULT '',
    seo_title_ar text,
    seo_title_en text,
    seo_description_ar text,
    seo_description_en text,
    state text NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published', 'superseded')),
    created_by bigint REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (page_id, revision)
);
ALTER TABLE content_pages ADD CONSTRAINT content_pages_published_revision_fk
    FOREIGN KEY (published_revision_id) REFERENCES content_page_revisions(id) ON DELETE SET NULL;
ALTER TABLE content_pages ADD CONSTRAINT content_pages_draft_revision_fk
    FOREIGN KEY (draft_revision_id) REFERENCES content_page_revisions(id) ON DELETE SET NULL;

CREATE TABLE content_categories (
    id bigserial PRIMARY KEY,
    public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    name_ar text NOT NULL,
    name_en text NOT NULL,
    description_ar text,
    description_en text,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    visible boolean NOT NULL DEFAULT true,
    deleted_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX content_categories_slug_ar_unique ON content_categories (lower(slug_ar)) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX content_categories_slug_en_unique ON content_categories (lower(slug_en)) WHERE deleted_at IS NULL;

CREATE TABLE content_articles (
    id bigserial PRIMARY KEY,
    public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    category_id bigint REFERENCES content_categories(id) ON DELETE RESTRICT,
    slug_ar text NOT NULL,
    slug_en text NOT NULL,
    published_revision_id bigint,
    draft_revision_id bigint,
    published_at timestamptz,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    deleted_at timestamptz,
    created_by bigint REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX content_articles_slug_ar_unique ON content_articles (lower(slug_ar)) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX content_articles_slug_en_unique ON content_articles (lower(slug_en)) WHERE deleted_at IS NULL;
CREATE INDEX content_articles_public_order ON content_articles (position, published_at DESC NULLS LAST, id DESC) WHERE deleted_at IS NULL;

CREATE TABLE content_article_revisions (
    id bigserial PRIMARY KEY,
    article_id bigint NOT NULL REFERENCES content_articles(id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision >= 1),
    title_ar text NOT NULL,
    title_en text NOT NULL,
    summary_ar text,
    summary_en text,
    body_ar text NOT NULL DEFAULT '',
    body_en text NOT NULL DEFAULT '',
    image_path text,
    image_alt_ar text,
    image_alt_en text,
    seo_title_ar text,
    seo_title_en text,
    seo_description_ar text,
    seo_description_en text,
    state text NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'published', 'superseded')),
    created_by bigint REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (article_id, revision)
);
ALTER TABLE content_articles ADD CONSTRAINT content_articles_published_revision_fk
    FOREIGN KEY (published_revision_id) REFERENCES content_article_revisions(id) ON DELETE SET NULL;
ALTER TABLE content_articles ADD CONSTRAINT content_articles_draft_revision_fk
    FOREIGN KEY (draft_revision_id) REFERENCES content_article_revisions(id) ON DELETE SET NULL;

CREATE TABLE content_navigation_sections (
    id bigserial PRIMARY KEY,
    public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    name_ar text NOT NULL,
    name_en text NOT NULL,
    placement text NOT NULL CHECK (placement IN ('header', 'footer')),
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    visible boolean NOT NULL DEFAULT true,
    deleted_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX content_navigation_sections_order ON content_navigation_sections (placement, position, id) WHERE deleted_at IS NULL;

CREATE TABLE content_navigation_links (
    id bigserial PRIMARY KEY,
    public_id uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    section_id bigint NOT NULL REFERENCES content_navigation_sections(id) ON DELETE CASCADE,
    label_ar text,
    label_en text,
    link_type text NOT NULL CHECK (link_type IN ('page', 'article', 'internal', 'external')),
    page_id bigint REFERENCES content_pages(id) ON DELETE RESTRICT,
    article_id bigint REFERENCES content_articles(id) ON DELETE RESTRICT,
    internal_path text,
    external_url text,
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    visible boolean NOT NULL DEFAULT true,
    open_in_new_tab boolean NOT NULL DEFAULT false,
    deleted_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT content_navigation_links_target CHECK (
(link_type = 'page' AND page_id IS NOT NULL AND article_id IS NULL AND internal_path IS NULL AND external_url IS NULL)
OR (link_type = 'article' AND article_id IS NOT NULL AND page_id IS NULL AND internal_path IS NULL AND external_url IS NULL)
OR (link_type = 'internal' AND internal_path IS NOT NULL AND page_id IS NULL AND article_id IS NULL AND external_url IS NULL)
OR (link_type = 'external' AND external_url IS NOT NULL AND page_id IS NULL AND article_id IS NULL AND internal_path IS NULL)
    ),
    CONSTRAINT content_navigation_links_external_https CHECK (external_url IS NULL OR external_url ~ '^https://[^[:space:]]+$')
);
CREATE INDEX content_navigation_links_order ON content_navigation_links (section_id, position, id) WHERE deleted_at IS NULL;

CREATE TABLE content_footer_settings (
    id smallint PRIMARY KEY CHECK (id = 1),
    revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
    description_ar text NOT NULL DEFAULT '',
    description_en text NOT NULL DEFAULT '',
    copyright_ar text NOT NULL DEFAULT '',
    copyright_en text NOT NULL DEFAULT '',
    social_links jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(social_links) = 'array'),
    show_sections boolean NOT NULL DEFAULT true,
    published_by bigint REFERENCES users(id) ON DELETE SET NULL,
    published_at timestamptz
);
INSERT INTO content_footer_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE ON content_pages, content_page_revisions,
    content_categories, content_articles, content_article_revisions,
    content_navigation_sections, content_navigation_links TO builderad_app;
GRANT SELECT, INSERT, UPDATE ON content_footer_settings TO builderad_app;
GRANT USAGE, SELECT ON SEQUENCE content_pages_id_seq, content_page_revisions_id_seq,
    content_categories_id_seq, content_articles_id_seq, content_article_revisions_id_seq,
    content_navigation_sections_id_seq, content_navigation_links_id_seq TO builderad_app;
REVOKE ALL ON content_pages, content_page_revisions, content_categories, content_articles,
    content_article_revisions, content_navigation_sections, content_navigation_links,
    content_footer_settings FROM PUBLIC, builderad_authority;
