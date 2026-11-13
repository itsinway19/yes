import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { describeFailure } from './feedback';

type Schemas = components['schemas'];
export type SectionCommand = Schemas['ContentSectionCommand'];
export type LinkCommand = Schemas['ContentLinkCommand'];
export type PageCommand = Schemas['ContentPageCommand'];
export type PageUpdate = Schemas['ContentPageUpdateCommand'];
export type ArticleCommand = Schemas['ContentArticleCommand'];
export type ArticleUpdate = Schemas['ContentArticleUpdateCommand'];
export type CategoryCommand = Schemas['ContentCategoryCommand'];
export type FooterCommand = Schemas['ContentFooterCommand'];
export type ReorderItem = Schemas['ContentReorderCommand']['items'][number];

/**
 * Every content command returns one localized, recoverable failure. The server
 * remains authoritative; this layer only explains the authoritative rejection.
 */
async function send<T>(fallbackAr: string, fallbackEn: string, command: () => Promise<{ data?: unknown }>): Promise<T> {
    try {
        const response = await command();
        if (!response.data) throw new Error('Missing server acknowledgement');
        return response.data as T;
    } catch (error) {
        throw describeFailure(error, fallbackAr, fallbackEn);
    }
}

const saveFailed = ['تعذر حفظ القسم.', 'The section could not be saved.'] as const;
const linkFailed = ['تعذر حفظ الرابط.', 'The link could not be saved.'] as const;
const pageFailed = ['تعذر حفظ الصفحة.', 'The page could not be saved.'] as const;
const articleFailed = ['تعذر حفظ المقالة.', 'The article could not be saved.'] as const;
const categoryFailed = ['تعذر حفظ التصنيف.', 'The category could not be saved.'] as const;
const footerFailed = ['تعذر حفظ إعدادات الفوتر.', 'Footer settings could not be saved.'] as const;
const deleteFailed = ['تعذر تنفيذ الحذف.', 'The deletion could not be completed.'] as const;
const publishFailed = ['تعذر تغيير حالة النشر.', 'The publication state could not be changed.'] as const;
const reorderFailed = ['تعذر حفظ الترتيب.', 'The order could not be saved.'] as const;
const restoreFailed = ['تعذر استعادة السجل.', 'The record could not be restored.'] as const;

export function saveSection(body: SectionCommand, section?: string) {
    return send<Schemas['ContentSectionSaved']>(...saveFailed, () => section
        ? api.POST('/internal/administration/content/sections/{section}', { params: { path: { section } }, body })
        : api.POST('/internal/administration/content/sections', { body }));
}

export function reorderSections(items: ReorderItem[]) {
    return send<Schemas['ContentAcknowledgement']>(...reorderFailed, () =>
        api.POST('/internal/administration/content/sections/reorder', { body: { items } }));
}

export function deleteSection(section: string) {
    return send<Schemas['ContentAcknowledgement']>(...deleteFailed, () =>
        api.POST('/internal/administration/content/sections/{section}/delete', { params: { path: { section } } }));
}

export function saveLink(section: string, body: LinkCommand) {
    return send<Schemas['ContentLinkSaved']>(...linkFailed, () =>
        api.POST('/internal/administration/content/sections/{section}/links', { params: { path: { section } }, body }));
}

export function updateLink(link: string, body: Schemas['ContentLinkUpdateCommand']) {
    return send<Schemas['ContentLinkSaved']>(...linkFailed, () =>
        api.POST('/internal/administration/content/links/{link}', { params: { path: { link } }, body }));
}

export function deleteLink(link: string) {
    return send<Schemas['ContentAcknowledgement']>(...deleteFailed, () =>
        api.POST('/internal/administration/content/links/{link}/delete', { params: { path: { link } } }));
}

export function reorderLinks(section: string, items: ReorderItem[]) {
    return send<Schemas['ContentAcknowledgement']>(...reorderFailed, () =>
        api.POST('/internal/administration/content/sections/{section}/links/reorder', { params: { path: { section } }, body: { items } }));
}

export function createPage(body: PageCommand) {
    return send<Schemas['ContentPageSaved']>(...pageFailed, () =>
        api.POST('/internal/administration/content/pages', { body }));
}

export function savePage(page: string, body: PageUpdate) {
    return send<Schemas['ContentPageSaved']>(...pageFailed, () =>
        api.POST('/internal/administration/content/pages/{page}', { params: { path: { page } }, body }));
}

export function publishPage(page: string, revision: number) {
    return send<Schemas['ContentPageSaved']>(...publishFailed, () =>
        api.POST('/internal/administration/content/pages/{page}/publish', { params: { path: { page } }, body: { revision } }));
}

export function unpublishPage(page: string) {
    return send<Schemas['ContentPageSaved']>(...publishFailed, () =>
        api.POST('/internal/administration/content/pages/{page}/unpublish', { params: { path: { page } } }));
}

export function deletePage(page: string) {
    return send<Schemas['ContentAcknowledgement']>(...deleteFailed, () =>
        api.POST('/internal/administration/content/pages/{page}/delete', { params: { path: { page } } }));
}

export function restorePage(page: string) {
    return send<Schemas['ContentPageSaved']>(...restoreFailed, () =>
        api.POST('/internal/administration/content/pages/{page}/restore', { params: { path: { page } } }));
}

export function restorePageRevision(page: string, revision: number) {
    return send<Schemas['ContentPageSaved']>(...restoreFailed, () =>
        api.POST('/internal/administration/content/pages/{page}/revisions/{revision}/restore', { params: { path: { page, revision } } }));
}

export function createArticle(body: ArticleCommand) {
    return send<Schemas['ContentArticleSaved']>(...articleFailed, () =>
        api.POST('/internal/administration/content/articles', { body }));
}

export function saveArticle(article: string, body: ArticleUpdate) {
    return send<Schemas['ContentArticleSaved']>(...articleFailed, () =>
        api.POST('/internal/administration/content/articles/{article}', { params: { path: { article } }, body }));
}

export function publishArticle(article: string, revision: number) {
    return send<Schemas['ContentArticleSaved']>(...publishFailed, () =>
        api.POST('/internal/administration/content/articles/{article}/publish', { params: { path: { article } }, body: { revision } }));
}

export function unpublishArticle(article: string) {
    return send<Schemas['ContentArticleSaved']>(...publishFailed, () =>
        api.POST('/internal/administration/content/articles/{article}/unpublish', { params: { path: { article } } }));
}

export function deleteArticle(article: string) {
    return send<Schemas['ContentAcknowledgement']>(...deleteFailed, () =>
        api.POST('/internal/administration/content/articles/{article}/delete', { params: { path: { article } } }));
}

export function restoreArticle(article: string) {
    return send<Schemas['ContentArticleSaved']>(...restoreFailed, () =>
        api.POST('/internal/administration/content/articles/{article}/restore', { params: { path: { article } } }));
}

export function restoreArticleRevision(article: string, revision: number) {
    return send<Schemas['ContentArticleSaved']>(...restoreFailed, () =>
        api.POST('/internal/administration/content/articles/{article}/revisions/{revision}/restore', { params: { path: { article, revision } } }));
}

export function saveCategory(body: CategoryCommand, category?: string) {
    return send<Schemas['ContentCategoriesSaved']>(...categoryFailed, () => category
        ? api.POST('/internal/administration/content/categories/{category}', { params: { path: { category } }, body })
        : api.POST('/internal/administration/content/categories', { body }));
}

export function deleteCategory(category: string) {
    return send<Schemas['ContentAcknowledgement']>(...deleteFailed, () =>
        api.POST('/internal/administration/content/categories/{category}/delete', { params: { path: { category } } }));
}

export function saveFooter(body: FooterCommand) {
    return send<Schemas['ContentFooterSaved']>(...footerFailed, () =>
        api.POST('/internal/administration/content/footer', { body }));
}
