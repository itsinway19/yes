import type { RouteRecordRaw } from 'vue-router';
import { clear, readAdministration } from './adminState';
import { clearCustomers, readCustomers, type CustomerQuery } from './customerState';
import { clearBusinessTypes, readBusinessTypes } from './businessState';
import { clearServices, readServices } from './serviceState';
export const internalOperationsRoutes: RouteRecordRaw[] = [
    { path: '/internal/services', component: () => import('./ServicesView.vue'), meta: { presentation: { clear: clearServices, load: (_route, passive, admission) => readServices(passive, false, admission) } } },
    { path: '/internal/business-types', component: () => import('./BusinessTypesView.vue'), meta: { presentation: { clear: clearBusinessTypes, load: (_route, passive, admission) => readBusinessTypes(passive, false, admission) } } },
    { path: '/internal/administration', component: () => import('./AdministrationView.vue'), meta: { presentation: { clear, load: (_route, passive, admission) => readAdministration(passive, false, admission) } } },
    { path: '/internal/administration/customers', component: () => import('./CustomersView.vue'), meta: { presentation: { clear: clearCustomers, load: (route, passive, admission) => readCustomers(Object.fromEntries(Object.entries(route.query).filter(([key, value]) => ['kind', 'search', 'status', 'page', 'selected'].includes(key) && typeof value === 'string')) as CustomerQuery, passive, false, admission) } } },
];
