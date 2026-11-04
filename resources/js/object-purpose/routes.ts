import type { RouteRecordRaw } from 'vue-router';
import { load, clear } from './state';

const meta = { presentation: { load, clear } };
export const objectPurposeRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace/purpose-history', component: () => import('./ObjectPurposes.vue'), meta },
    { path: '/workspaces/:workspace/purpose-history/:purpose', component: () => import('./ObjectPurposes.vue'), meta },
];
