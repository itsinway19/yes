import type { RouteRecordRaw } from 'vue-router';
import { load, clear } from './state';

const meta = { presentation: { load, clear } };
export const objectPurposeRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace/object-purposes', component: () => import('./ObjectPurposes.vue'), meta },
    { path: '/workspaces/:workspace/object-purposes/:purpose', component: () => import('./ObjectPurposes.vue'), meta },
];
