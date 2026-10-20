import type { PresentationAdmission } from '../spa/presentation';
import type { RouteRecordRaw } from 'vue-router';
import { read, clearDisplay } from './state';

const meta = {
    presentation: {
        load: (route: { params: Record<string, unknown> }, passive: boolean, admission?: PresentationAdmission) => read(String(route.params.workspace), passive, admission),
        clear: clearDisplay,
    },
};

export const goalsRoutes: RouteRecordRaw[] = [
    { path: '/workspaces/:workspace/goals', component: () => import('./GoalsEditor.vue'), meta },
    { path: '/workspaces/:workspace/goals/review', component: () => import('./GoalsReview.vue'), meta },
];
