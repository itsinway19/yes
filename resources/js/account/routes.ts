import type { RouteRecordRaw } from 'vue-router';
import { clear, load } from './state';
export const accountRoutes: RouteRecordRaw[] = ['/profile', '/security/devices'].map(path => ({ path, component: () => import('./AccountView.vue'), meta: { presentation: { clear, load } } }));
