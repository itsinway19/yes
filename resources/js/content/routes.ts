import type { RouteRecordRaw } from 'vue-router';
import {
    clearArticleEditor, clearArticles, clearCategories, clearFooter, clearLinks, clearPageEditor,
    clearPages, clearSections, readArticleEditor, readArticles, readCategories, readFooter, readLinks,
    readPageEditor, readPages, readSections, type ListQuery,
} from './state';
import './content.css';

function listQuery(route: { query: Record<string, unknown> }): ListQuery {
    const status = route.query.status;
    return {
        search: typeof route.query.search === 'string' ? route.query.search : '',
        status: status === 'draft' || status === 'published' || status === 'deleted' ? status : '',
        page: typeof route.query.page === 'string' ? Number(route.query.page) || 1 : 1,
    };
}

export const contentRoutes: RouteRecordRaw[] = [
    {
        path: '/internal/administration/content/sections',
        component: () => import('./SectionsView.vue'),
        meta: { presentation: { clear: clearSections, load: (_route, passive, admission) => readSections(passive, false, admission) } },
    },
    {
        path: '/internal/administration/content/sections/:section/links',
        component: () => import('./SectionLinksView.vue'),
        meta: {
            presentation: {
                clear: clearLinks,
                load: (route, passive, admission) => readLinks(String(route.params.section), passive, false, admission),
            },
        },
    },
    {
        path: '/internal/administration/content/pages',
        component: () => import('./PagesView.vue'),
        meta: { presentation: { clear: clearPages, load: (route, passive, admission) => readPages(listQuery(route), passive, false, admission) } },
    },
    {
        path: '/internal/administration/content/pages/:page',
        component: () => import('./PageEditorView.vue'),
        meta: {
            presentation: {
                clear: clearPageEditor,
                load: (route, passive, admission) => readPageEditor(String(route.params.page), passive, false, admission),
            },
        },
    },
    {
        path: '/internal/administration/content/articles',
        component: () => import('./ArticlesView.vue'),
        meta: { presentation: { clear: clearArticles, load: (route, passive, admission) => readArticles(listQuery(route), passive, false, admission) } },
    },
    {
        path: '/internal/administration/content/articles/:article',
        component: () => import('./ArticleEditorView.vue'),
        meta: {
            presentation: {
                clear: clearArticleEditor,
                load: (route, passive, admission) => readArticleEditor(String(route.params.article), passive, false, admission),
            },
        },
    },
    {
        path: '/internal/administration/content/categories',
        component: () => import('./CategoriesView.vue'),
        meta: { presentation: { clear: clearCategories, load: (_route, passive, admission) => readCategories(passive, false, admission) } },
    },
    {
        path: '/internal/administration/content/footer',
        component: () => import('./FooterSettingsView.vue'),
        meta: { presentation: { clear: clearFooter, load: (_route, passive, admission) => readFooter(passive, false, admission) } },
    },
];
