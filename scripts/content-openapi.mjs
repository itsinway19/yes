export function contentContracts({ ref, text, uuid, object, list, nullable, response, json, errors, security, headers, resource, envelope }) {
    const boolean = { type: 'boolean' };
    const integer = { type: 'integer', minimum: 0 };
    const placement = { enum: ['header', 'footer'] };
    const linkType = { enum: ['page', 'article', 'internal', 'external'] };
    const path = (name, schema = uuid) => ({ in: 'path', name, required: true, schema });
    const id = { type: 'integer', minimum: 1, maximum: 1000000000 };
    const slug = { type: 'string', pattern: '^[\\p{L}\\p{N}][\\p{L}\\p{N}-]{0,119}$' };
    const bounded = (min, max) => ({ type: 'string', minLength: min, maxLength: max });
    const write = (operationId, schema, parameters = [], extra = {}) => ({ operationId, security, parameters,
        ...(schema ? { requestBody: { required: true, ...json(ref(schema)) } } : {}),
        responses: { 200: response(ref(extra.response ?? 'ContentAcknowledgement')), ...errors } });

    const revisionSummary = object({ revision: id, state: { enum: ['draft', 'published', 'superseded'] }, created_at: nullable(text) });
    const pageEntity = object({ public_id: uuid, slug_ar: text, slug_en: text, position: integer, deleted: boolean,
        published_at: nullable(text), url_ar: text, url_en: text });
    const pageRevision = object({ revision: id, slug_ar: text, slug_en: text, position: integer,
        title_ar: text, title_en: text, excerpt_ar: nullable(text), excerpt_en: nullable(text),
        body_ar: text, body_en: text, seo_title_ar: nullable(text), seo_title_en: nullable(text),
        seo_description_ar: nullable(text), seo_description_en: nullable(text),
        state: { enum: ['draft', 'published', 'superseded'] }, created_at: nullable(text) });
    const pageRow = object({ public_id: uuid, title_ar: nullable(text), title_en: nullable(text), slug_ar: text, slug_en: text,
        position: integer, status: { enum: ['draft', 'published', 'deleted'] }, deleted: boolean,
        published_at: nullable(text), updated_at: nullable(text), url_ar: text, url_en: text });
    const articleEntity = object({ public_id: uuid, slug_ar: text, slug_en: text, position: integer, deleted: boolean,
        category_public_id: nullable(uuid), published_at: nullable(text), url_ar: text, url_en: text });
    const articleRevision = object({ revision: id, slug_ar: text, slug_en: text, position: integer,
        category_public_id: nullable(uuid), title_ar: text, title_en: text, summary_ar: nullable(text), summary_en: nullable(text),
        body_ar: text, body_en: text, image_path: nullable(text), image_alt_ar: nullable(text), image_alt_en: nullable(text),
        seo_title_ar: nullable(text), seo_title_en: nullable(text), seo_description_ar: nullable(text), seo_description_en: nullable(text),
        state: { enum: ['draft', 'published', 'superseded'] }, created_at: nullable(text) });
    const articleRow = object({ public_id: uuid, title_ar: nullable(text), title_en: nullable(text), slug_ar: text, slug_en: text,
        position: integer, category_public_id: nullable(uuid), category_name_ar: nullable(text), category_name_en: nullable(text),
        status: { enum: ['draft', 'published', 'deleted'] }, deleted: boolean,
        published_at: nullable(text), updated_at: nullable(text), url_ar: text, url_en: text });
    const categoryRow = object({ public_id: uuid, slug_ar: text, slug_en: text, name_ar: text, name_en: text,
        description_ar: nullable(text), description_en: nullable(text), position: integer, visible: boolean,
        article_count: integer, deleted: boolean, updated_at: nullable(text) });
    const section = object({ public_id: uuid, name_ar: text, name_en: text, placement, position: integer,
        visible: boolean, link_count: integer, updated_at: nullable(text) });
    const link = object({ public_id: uuid, label_ar: nullable(text), label_en: nullable(text), link_type: linkType,
        target: nullable(uuid), internal_path: nullable(text), external_url: nullable(text), position: integer,
        visible: boolean, open_in_new_tab: boolean, target_title_ar: nullable(text), target_title_en: nullable(text),
        target_published: boolean, preview_url: nullable(text), preview_url_en: nullable(text) });
    const choice = object({ public_id: uuid, title_ar: nullable(text), title_en: nullable(text), published: boolean,
        url_ar: text, url_en: text });
    const pageCommand = { slug_ar: slug, slug_en: slug, title_ar: bounded(1, 200), title_en: bounded(1, 200),
        excerpt_ar: nullable(bounded(0, 600)), excerpt_en: nullable(bounded(0, 600)),
        body_ar: nullable({ type: 'string', maxLength: 200000 }), body_en: nullable({ type: 'string', maxLength: 200000 }),
        seo_title_ar: nullable(bounded(0, 200)), seo_title_en: nullable(bounded(0, 200)),
        seo_description_ar: nullable(bounded(0, 300)), seo_description_en: nullable(bounded(0, 300)),
        position: nullable(integer) };
    const articleCommand = { ...pageCommand,
        summary_ar: nullable(bounded(0, 600)), summary_en: nullable(bounded(0, 600)),
        image_path: nullable({ type: 'string', maxLength: 2048 }), image_alt_ar: nullable(bounded(0, 300)),
        image_alt_en: nullable(bounded(0, 300)), category_public_id: nullable(uuid) };
    const categoryCommand = { slug_ar: slug, slug_en: slug, name_ar: bounded(1, 120), name_en: bounded(1, 120),
        description_ar: nullable(bounded(0, 600)), description_en: nullable(bounded(0, 600)),
        position: nullable(integer), visible: boolean };
    const sectionCommand = { name_ar: bounded(1, 120), name_en: bounded(1, 120), placement,
        position: nullable(integer), visible: boolean };
    const linkCommand = { label_ar: nullable(bounded(0, 120)), label_en: nullable(bounded(0, 120)), link_type: linkType,
        target: nullable(uuid), internal_path: nullable(bounded(0, 200)), external_url: nullable(bounded(0, 2048)),
        position: nullable(integer), visible: boolean, open_in_new_tab: boolean };
    const reorder = { items: list(object({ public_id: uuid, position: integer })) };
    const footer = object({ revision: integer, description_ar: text, description_en: text, copyright_ar: text,
        copyright_en: text, social_links: list(object({ label_ar: text, label_en: text, url: text })),
        show_sections: boolean, published_at: nullable(text) });

    return {
        paths: {
            '/internal/administration/content/sections': {
                get: { operationId: 'readContentSections', security, parameters: headers, responses: { 200: response(resource('ContentSectionsResource')), ...errors } },
                post: write('createContentSection', 'ContentSectionCommand', [], { response: 'ContentSectionSaved' }),
            },
            '/internal/administration/content/sections/reorder': { post: write('reorderContentSections', 'ContentReorderCommand') },
            '/internal/administration/content/sections/{section}': { post: write('updateContentSection', 'ContentSectionCommand', [path('section')], { response: 'ContentSectionSaved' }) },
            '/internal/administration/content/sections/{section}/delete': { post: write('deleteContentSection', null, [path('section')]) },
            '/internal/administration/content/sections/{section}/links': {
                get: { operationId: 'readContentSectionLinks', security, parameters: [path('section'), ...headers], responses: { 200: response(resource('ContentLinksResource')), ...errors } },
                post: write('createContentLink', 'ContentLinkCommand', [path('section')], { response: 'ContentLinkSaved' }),
            },
            '/internal/administration/content/sections/{section}/links/reorder': { post: write('reorderContentLinks', 'ContentReorderCommand', [path('section')]) },
            '/internal/administration/content/links/{link}': { post: write('updateContentLink', 'ContentLinkUpdateCommand', [path('link')], { response: 'ContentLinkSaved' }) },
            '/internal/administration/content/links/{link}/delete': { post: write('deleteContentLink', null, [path('link')]) },
            '/internal/administration/content/pages': {
                get: { operationId: 'readContentPages', security, parameters: [...headers,
                    { in: 'query', name: 'search', schema: { type: 'string', maxLength: 120 } },
                    { in: 'query', name: 'status', schema: { enum: ['', 'draft', 'published', 'deleted'] } },
                    { in: 'query', name: 'page', schema: { type: 'integer', minimum: 1 } }],
                    responses: { 200: response(resource('ContentPagesResource')), ...errors } },
                post: write('createContentPage', 'ContentPageCommand', [], { response: 'ContentPageSaved' }),
            },
            '/internal/administration/content/pages/{page}': {
                get: { operationId: 'readContentPage', security, parameters: [path('page'), ...headers], responses: { 200: response(resource('ContentPageEditorResource')), ...errors } },
                post: write('saveContentPageDraft', 'ContentPageUpdateCommand', [path('page')], { response: 'ContentPageSaved' }),
            },
            '/internal/administration/content/pages/{page}/publish': { post: write('publishContentPage', 'ContentPublishCommand', [path('page')], { response: 'ContentPageSaved' }) },
            '/internal/administration/content/pages/{page}/unpublish': { post: write('unpublishContentPage', null, [path('page')], { response: 'ContentPageSaved' }) },
            '/internal/administration/content/pages/{page}/delete': { post: write('deleteContentPage', null, [path('page')]) },
            '/internal/administration/content/pages/{page}/restore': { post: write('restoreContentPage', null, [path('page')], { response: 'ContentPageSaved' }) },
            '/internal/administration/content/pages/{page}/revisions/{revision}/restore': { post: write('restoreContentPageRevision', null, [path('page'), path('revision', { type: 'integer', minimum: 1 })], { response: 'ContentPageSaved' }) },
            '/internal/administration/content/articles': {
                get: { operationId: 'readContentArticles', security, parameters: [...headers,
                    { in: 'query', name: 'search', schema: { type: 'string', maxLength: 120 } },
                    { in: 'query', name: 'status', schema: { enum: ['', 'draft', 'published', 'deleted'] } },
                    { in: 'query', name: 'page', schema: { type: 'integer', minimum: 1 } }],
                    responses: { 200: response(resource('ContentArticlesResource')), ...errors } },
                post: write('createContentArticle', 'ContentArticleCommand', [], { response: 'ContentArticleSaved' }),
            },
            '/internal/administration/content/articles/{article}': {
                get: { operationId: 'readContentArticle', security, parameters: [path('article'), ...headers], responses: { 200: response(resource('ContentArticleEditorResource')), ...errors } },
                post: write('saveContentArticleDraft', 'ContentArticleUpdateCommand', [path('article')], { response: 'ContentArticleSaved' }),
            },
            '/internal/administration/content/articles/{article}/publish': { post: write('publishContentArticle', 'ContentPublishCommand', [path('article')], { response: 'ContentArticleSaved' }) },
            '/internal/administration/content/articles/{article}/unpublish': { post: write('unpublishContentArticle', null, [path('article')], { response: 'ContentArticleSaved' }) },
            '/internal/administration/content/articles/{article}/delete': { post: write('deleteContentArticle', null, [path('article')]) },
            '/internal/administration/content/articles/{article}/restore': { post: write('restoreContentArticle', null, [path('article')], { response: 'ContentArticleSaved' }) },
            '/internal/administration/content/articles/{article}/revisions/{revision}/restore': { post: write('restoreContentArticleRevision', null, [path('article'), path('revision', { type: 'integer', minimum: 1 })], { response: 'ContentArticleSaved' }) },
            '/internal/administration/content/categories': {
                get: { operationId: 'readContentCategories', security, parameters: headers, responses: { 200: response(resource('ContentCategoriesResource')), ...errors } },
                post: write('createContentCategory', 'ContentCategoryCommand', [], { response: 'ContentCategoriesSaved' }),
            },
            '/internal/administration/content/categories/{category}': { post: write('updateContentCategory', 'ContentCategoryCommand', [path('category')], { response: 'ContentCategoriesSaved' }) },
            '/internal/administration/content/categories/{category}/delete': { post: write('deleteContentCategory', null, [path('category')]) },
            '/internal/administration/content/footer': {
                get: { operationId: 'readContentFooter', security, parameters: headers, responses: { 200: response(resource('ContentFooterResource')), ...errors } },
                post: write('publishContentFooter', 'ContentFooterCommand', [], { response: 'ContentFooterSaved' }),
            },
        },
        schemas: {
            ContentSection: section,
            ContentSectionsData: object({ canManage: boolean, sections: list(ref('ContentSection')),
                placements: list(object({ value: placement, label_ar: text, label_en: text })) }),
            ContentSectionsResource: envelope('ContentSectionsData'),
            ContentSectionCommand: object(sectionCommand),
            ContentSectionSaved: object({ saved: { const: true }, section: ref('ContentSection') }),
            ContentLink: link,
            ContentLinkChoices: object({ pages: list(ref('ContentChoice')), articles: list(ref('ContentChoice')),
                internal_paths: list(object({ path: text, label_ar: text, label_en: text })) }),
            ContentChoice: choice,
            ContentLinksData: object({ canManage: boolean, section: ref('ContentSection'), links: list(ref('ContentLink')),
                choices: ref('ContentLinkChoices') }),
            ContentLinksResource: envelope('ContentLinksData'),
            ContentLinkCommand: object(linkCommand),
            ContentLinkUpdateCommand: object({ ...linkCommand, section: uuid }),
            ContentLinkSaved: object({ saved: { const: true }, links: ref('ContentLink') }),
            ContentReorderCommand: object(reorder),
            ContentDeleteCommand: object({}),
            ContentAcknowledgement: object({ saved: { const: true } }),
            ContentPageRow: pageRow,
            ContentPagesData: object({ canManage: boolean, canPublish: boolean, total: integer, page: integer, perPage: integer, rows: list(ref('ContentPageRow')) }),
            ContentPagesResource: envelope('ContentPagesData'),
            ContentPageEntity: pageEntity,
            ContentPageRevision: pageRevision,
            ContentRevisionSummary: revisionSummary,
            ContentPageEditorData: object({ canManage: boolean, canPublish: boolean, page: ref('ContentPageEntity'), draft: nullable(ref('ContentPageRevision')),
                published: nullable(ref('ContentPageRevision')), revisions: list(ref('ContentRevisionSummary')),
                sections: list(object({ public_id: uuid, name_ar: text, name_en: text, placement: placement })),
                placements: list(object({ link_public_id: uuid, section_public_id: uuid, section_name_ar: text,
                    section_name_en: text, visible: boolean, position: integer })) }),
            ContentPageEditorResource: envelope('ContentPageEditorData'),
            ContentPageCommand: object(pageCommand),
            ContentPageUpdateCommand: object({ ...pageCommand, expected_revision: { type: 'integer', minimum: 0, maximum: 1000000000 } }),
            ContentPageSaved: object({ saved: { const: true }, page: ref('ContentPageEditorData') }),
            ContentPublishCommand: object({ revision: id }),
            ContentArticleRow: articleRow,
            ContentArticlesData: object({ canManage: boolean, canPublish: boolean, total: integer, page: integer, perPage: integer, rows: list(ref('ContentArticleRow')) }),
            ContentArticlesResource: envelope('ContentArticlesData'),
            ContentArticleEntity: articleEntity,
            ContentArticleRevision: articleRevision,
            ContentArticleEditorData: object({ canManage: boolean, canPublish: boolean, article: ref('ContentArticleEntity'),
                draft: nullable(ref('ContentArticleRevision')), published: nullable(ref('ContentArticleRevision')),
                revisions: list(ref('ContentRevisionSummary')),
                categories: list(object({ public_id: uuid, name_ar: text, name_en: text })) }),
            ContentArticleEditorResource: envelope('ContentArticleEditorData'),
            ContentArticleCommand: object(articleCommand),
            ContentArticleUpdateCommand: object({ ...articleCommand, expected_revision: { type: 'integer', minimum: 0, maximum: 1000000000 } }),
            ContentArticleSaved: object({ saved: { const: true }, article: ref('ContentArticleEditorData') }),
            ContentCategoryRow: categoryRow,
            ContentCategoriesData: object({ canManage: boolean, rows: list(ref('ContentCategoryRow')) }),
            ContentCategoriesResource: envelope('ContentCategoriesData'),
            ContentCategoryCommand: object(categoryCommand),
            ContentCategoriesSaved: object({ saved: { const: true }, categories: ref('ContentCategoriesData') }),
            ContentFooter: footer,
            ContentFooterData: object({ canManage: boolean, footer: ref('ContentFooter') }),
            ContentFooterResource: envelope('ContentFooterData'),
            ContentFooterCommand: object({ revision: integer,
                description_ar: nullable(bounded(0, 2000)), description_en: nullable(bounded(0, 2000)),
                copyright_ar: nullable(bounded(0, 300)), copyright_en: nullable(bounded(0, 300)),
                show_sections: boolean,
                social_links: nullable(list(object({ label_ar: nullable(bounded(0, 120)),
                    label_en: nullable(bounded(0, 120)), url: bounded(8, 2048) }))) }),
            ContentFooterSaved: object({ saved: { const: true }, footer: ref('ContentFooterData') }),
        },
    };
}
