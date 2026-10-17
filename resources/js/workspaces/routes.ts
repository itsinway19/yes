import type { RouteRecordRaw } from 'vue-router';
import './workspaces.css';
import { load, clear } from './state';

const meta = { presentation: { load, clear } };
export const workspaceRoutes: RouteRecordRaw[] = [
    { path: '/workspaces', name: 'application.workspaces', component: () => import('./WorkspaceIndex.vue'), meta },
    { path: '/business/start', name: 'application.business.create', component: () => import('./BusinessCreate.vue'), meta },
];
