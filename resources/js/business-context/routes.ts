import type { RouteRecordRaw } from 'vue-router';
import { load, clear } from './state';

export const businessContextRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace/business', component: () => import('./BusinessContext.vue'), meta: { presentation: { load, clear } } },
];
