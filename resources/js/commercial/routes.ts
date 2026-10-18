import type { RouteRecordRaw } from 'vue-router';
import { clearPreparation, readPreparation } from './preparationState';
import { clearOperations, readOperations } from './operationsState';

export const commercialRoutes: RouteRecordRaw[] = [
    { path: '/internal/commercial', component: () => import('./OperationsView.vue'), meta: { presentation: { clear: clearOperations, load: (_route, passive) => readOperations(passive) } } },
    { path: '/internal/administration/commercial-markets', component: () => import('./PreparationView.vue'), meta: { presentation: { clear: clearPreparation, load: (_route, passive) => readPreparation(passive) } } },
];
