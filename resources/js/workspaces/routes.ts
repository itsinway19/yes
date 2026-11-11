import type { RouteRecordRaw } from 'vue-router';
export const workspaceRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:pathMatch(.*)*', redirect: '/businesses' },
    { path: '/organizations/new', redirect: '/businesses/new' },
    { path: '/business/start', redirect: '/businesses/new' },
];
