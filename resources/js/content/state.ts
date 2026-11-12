import { shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';

type Schemas = components['schemas'];
export type SectionsData = Schemas['ContentSectionsData'];
export type LinksData = Schemas['ContentLinksData'];
export type PagesData = Schemas['ContentPagesData'];
export type PageEditorData = Schemas['ContentPageEditorData'];
export type ArticlesData = Schemas['ContentArticlesData'];
export type ArticleEditorData = Schemas['ContentArticleEditorData'];
export type CategoriesData = Schemas['ContentCategoriesData'];
export type FooterData = Schemas['ContentFooterData'];
export type ListQuery = { search?: string; status?: '' | 'draft' | 'published' | 'deleted'; page?: number };

export const sections = shallowRef<SectionsData>();
export const links = shallowRef<LinksData>();
export const pages = shallowRef<PagesData>();
export const pageEditor = shallowRef<PageEditorData>();
export const articles = shallowRef<ArticlesData>();
export const articleEditor = shallowRef<ArticleEditorData>();
export const categories = shallowRef<CategoriesData>();
export const footer = shallowRef<FooterData>();

type Stream = { serial: number; abort?: AbortController };
type Slot<T> = { value: T | undefined };

function envelope<T>(value: unknown): T | undefined {
    const body = value as { data?: T } | undefined;
    return body && typeof body === 'object' && 'data' in body ? body.data : undefined;
}

function begin(stream: Stream, admission?: PresentationAdmission) {
    const attempt = ++stream.serial;
    stream.abort?.abort();
    stream.abort = new AbortController();
    return {
        attempt,
        captured: stamp(),
        signal: admission ? AbortSignal.any([admission.signal, stream.abort.signal]) : stream.abort.signal,
    };
}

async function load<T>(stream: Stream, slot: Slot<T>, agent: (signal: AbortSignal) => Promise<{ data?: unknown }>,
    passive: boolean, admission?: PresentationAdmission): Promise<T | undefined> {
    const state = begin(stream, admission);
    try {
        const result = await agent(state.signal);
        const data = envelope<T>(result.data);
        if (!data || !owned(state.captured) || state.attempt !== stream.serial) return;
        publish(admission, () => { slot.value = data; status.value = 'ready'; });
        return data;
    } catch (error) {
        if (admission) throw error;
        if (!owned(state.captured) || state.attempt !== stream.serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else status.value = 'unavailable';
    }
}

function reset<T>(stream: Stream, slot: Slot<T>) {
    stream.serial++;
    stream.abort?.abort();
    slot.value = undefined;
}

const sectionStream: Stream = { serial: 0 };
export function clearSections() { reset(sectionStream, sections); }
export async function readSections(passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(sectionStream, sections, signal => api.GET('/internal/administration/content/sections', {
        headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const linkStream: Stream = { serial: 0 };
export function clearLinks() { reset(linkStream, links); }
export async function readLinks(section: string, passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(linkStream, links, signal => api.GET('/internal/administration/content/sections/{section}/links', {
        params: { path: { section } }, headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const pageStream: Stream = { serial: 0 };
export function clearPages() { reset(pageStream, pages); }
export async function readPages(query: ListQuery = {}, passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(pageStream, pages, signal => api.GET('/internal/administration/content/pages', {
        params: { query: { search: query.search ?? '', status: query.status ?? '', page: query.page ?? 1 } },
        headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const pageEditorStream: Stream = { serial: 0 };
export function clearPageEditor() { reset(pageEditorStream, pageEditor); }
export async function readPageEditor(page: string, passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(pageEditorStream, pageEditor, signal => api.GET('/internal/administration/content/pages/{page}', {
        params: { path: { page } }, headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const articleStream: Stream = { serial: 0 };
export function clearArticles() { reset(articleStream, articles); }
export async function readArticles(query: ListQuery = {}, passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(articleStream, articles, signal => api.GET('/internal/administration/content/articles', {
        params: { query: { search: query.search ?? '', status: query.status ?? '', page: query.page ?? 1 } },
        headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const articleEditorStream: Stream = { serial: 0 };
export function clearArticleEditor() { reset(articleEditorStream, articleEditor); }
export async function readArticleEditor(article: string, passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(articleEditorStream, articleEditor, signal => api.GET('/internal/administration/content/articles/{article}', {
        params: { path: { article } }, headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const categoryStream: Stream = { serial: 0 };
export function clearCategories() { reset(categoryStream, categories); }
export async function readCategories(passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(categoryStream, categories, signal => api.GET('/internal/administration/content/categories', {
        headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

const footerStream: Stream = { serial: 0 };
export function clearFooter() { reset(footerStream, footer); }
export async function readFooter(passive = false, _readback = false, admission?: PresentationAdmission) {
    return load(footerStream, footer, signal => api.GET('/internal/administration/content/footer', {
        headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal,
    }), passive, admission);
}

onClose(() => {
    clearSections(); clearLinks(); clearPages(); clearPageEditor();
    clearArticles(); clearArticleEditor(); clearCategories(); clearFooter();
});
