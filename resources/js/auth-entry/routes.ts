import type { RouteRecordRaw } from 'vue-router';
import { clear, load } from './state';
const presentation = { clear, load };
export const authEntryRoutes: RouteRecordRaw[] = ['/login', '/register', '/identity/switch', '/forgot-password', '/reset-password/:token', '/verify-email', '/confirm-password'].map(path => ({ path, component: () => import('./AuthEntryView.vue'), meta: { presentation } }));
