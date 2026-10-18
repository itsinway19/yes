import type { RouteRecordRaw } from 'vue-router';
import { load, clear } from './state';
export const sourceRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace', component: () => import('./SourceView.vue'), meta: { presentation: { load, clear } } },
];
