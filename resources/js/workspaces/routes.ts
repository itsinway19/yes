import type { RouteRecordRaw } from 'vue-router';
import './workspaces.css';
import { load, clear } from './state';
import { loadCommercialHistory, clearCommercialHistory } from './commercialHistory';

const meta = { presentation: { load, clear } };
export const workspaceRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace/commercial-history', name: 'application.organization.history', component: () => import('./CommercialHistory.vue'), meta: { presentation: { load: loadCommercialHistory, clear: clearCommercialHistory } } },
    { path: '/workspaces', name: 'application.workspaces', component: () => import('./WorkspaceIndex.vue'), meta },
    { path: '/organizations/new', name: 'application.organization.create', component: () => import('./OrganizationCreate.vue'), meta },
    { path: '/business/start', redirect: '/organizations/new' },
];
