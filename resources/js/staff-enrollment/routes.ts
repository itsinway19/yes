import type { RouteRecordRaw } from 'vue-router';
import { clear, load } from './state';
export const staffEnrollmentRoutes: RouteRecordRaw[] = [{ path: '/staff/onboarding', component: () => import('./StaffEnrollmentView.vue'), meta: { presentation: { clear, load } } }];
