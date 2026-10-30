import type { RouteRecordRaw } from 'vue-router';
import { load, clear } from './state';
import './businesses.css';
const meta = { presentation: { load, clear } };
export const independentBusinessRoutes: RouteRecordRaw[] = [
    { path: '/source-rights', name: 'application.source-rights', component: () => import('./SourceRights.vue'), meta },
    { path: '/source-rights/:source', name: 'application.source-rights.show', component: () => import('./SourceRights.vue'), meta },
    { path: '/data-rights', name: 'application.data-rights', component: () => import('./DataBoundaries.vue'), meta },
    { path: '/data-rights/:boundary', name: 'application.data-rights.show', component: () => import('./DataBoundaries.vue'), meta },
    { path: '/businesses', name: 'application.businesses', component: () => import('./BusinessSurface.vue'), meta },
    { path: '/businesses/new', name: 'application.businesses.new', component: () => import('./BusinessSurface.vue'), meta },
    { path: '/businesses/:business', name: 'application.businesses.show', component: () => import('./BusinessSurface.vue'), meta },
];
