import '../../css/app.css';
import { createApp, defineComponent, h, ref, watch } from 'vue';
import { RouterLink, RouterView, type RouteLocationNormalized } from 'vue-router';
import { createI18n } from 'vue-i18n';
import { messages } from '../localization/messages';
import { bootstrap, documentStatus, departure, startLease, logout, internalBoundary, onClose } from './transport';
import { confirmDeparture, status, admittedRoute, navigating, navigationError, conceal, prepare, commitRoute, failed } from './presentation';
import { installBrowserLifecycle } from './browserLifecycle';
import { createApplicationRouter } from './router';
import { goalsRoutes } from '../goals-spa/routes';
import { workspaceRoutes } from '../workspaces/routes';
import { independentBusinessRoutes } from '../business-foundation/routes';
import { businessContextRoutes } from '../business-context/routes';
import { objectPurposeRoutes } from '../object-purpose/routes';
import { sourceRoutes } from '../sources/routes';
import { accountRoutes } from '../account/routes';
import { authEntryRoutes } from '../auth-entry/routes';
import { staffEnrollmentRoutes } from '../staff-enrollment/routes';
import { internalOperationsRoutes } from '../internal-operations/routes';
import { commercialRoutes } from '../commercial/routes';
import { locale, localeBusy, switchLocale } from './locale';
import AdministrationLayout from '../internal-operations/AdministrationLayout.vue';
import BrandLogo from '../Components/BrandLogo.vue';
import ThemeToggle from '../Components/ThemeToggle.vue';
const root = document.getElementById('application-spa')!;
watch(status, value => { root.dataset.state = value; }, { immediate: true, flush: 'sync' });
const style=document.createElement('style');style.textContent='[data-application-spa]:not([data-state="ready"]) [data-spa-private]{display:none!important} .business-main{max-width:1040px;margin:auto;padding:32px}';document.head.append(style);
const router = createApplicationRouter([...independentBusinessRoutes, ...workspaceRoutes, ...goalsRoutes, ...businessContextRoutes, ...objectPurposeRoutes, ...sourceRoutes, ...accountRoutes, ...authEntryRoutes, ...staffEnrollmentRoutes, ...internalOperationsRoutes, ...commercialRoutes], {
    pending: (active, passive) => { if (active && passive) conceal(); navigating.value = active; if (active) navigationError.value = false; },
    prepare, commit: commitRoute, failed,
});
onClose(() => router.cancelCandidate());
startLease();
const renderFailure = ref(false);
const localeError = ref(false);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const i18n = createI18n({ legacy: false, locale: locale.value, fallbackLocale: 'en', messages });
watch(locale, value => { i18n.global.locale.value = value; });
const app = createApp(defineComponent({ setup: () => () => {
    const administrationLayout = internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0;
    const header = h(administrationLayout ? 'div' : 'header', administrationLayout ? { class: 'ba-admin-header-controls' } : { style: 'display:flex;gap:20px;padding:18px;flex-wrap:wrap;align-items:center' }, [h(BrandLogo), h(ThemeToggle),
        administrationLayout ? h('span', { 'data-admin-context': true }, copy('الإدارة المركزية', 'Central administration')) : null,
        !internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: '/businesses' }, () => copy('أنشطتي', 'My businesses')) : null,
        typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/business` }, () => copy('سياق النشاط', 'Business context')) : null,
        typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/goals` }, () => copy('الأهداف', 'Goals')) : null,
        typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/object-purposes` }, () => copy('الغرض الإعلاني', 'Object purpose')) : null,
        !internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: '/profile' }, () => copy('الحساب', 'Account')) : null,
        typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}` }, () => copy('المصادر', 'Sources')) : null,
        !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: internalBoundary ? '/internal/security/devices' : '/security/devices' }, () => copy('الأمان', 'Security')) : null,
        internalBoundary && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: '/internal/identity/switch', ...(administrationLayout ? { class: 'ba-admin-identity-switch', 'aria-label': copy('تبديل الهوية الداخلية', 'Switch internal identity') } : {}) }, () => administrationLayout ? copy('تبديل الهوية', 'Switch identity') : copy('تبديل الهوية الداخلية', 'Switch internal identity')) : null,
        internalBoundary && bootstrap.identity.binding.principal > 0 ? h('a', { href: '/internal/administration' }, copy('الإدارة', 'Administration')) : null,
        h('button', { disabled: localeBusy.value || status.value !== 'ready', 'data-application': 'locale', onClick: async () => { localeError.value = false; try { await switchLocale(); } catch { localeError.value = true; } } }, locale.value === 'ar' ? 'English' : 'العربية'),
        bootstrap.identity.binding.principal > 0 ? h('button', { onClick: () => { if(confirmDeparture.value())void logout(); }, disabled: status.value !== 'ready', 'data-spa': 'logout' }, copy('تسجيل الخروج', 'Sign out')) : null]);
    const content = [
    localeError.value ? h('p', { role: 'alert' }, copy('تعذّر حفظ اللغة. حاول مجددًا.', 'The language change could not be confirmed. Try again.')) : null,
    navigating.value ? h('p', { role: 'status', 'data-spa': 'navigation', style: 'padding:0 30px' }, copy('جارٍ تحميل الوجهة…', 'Loading destination…')) : null,
    navigationError.value && admittedRoute.value && status.value === 'ready' ? h('p', { role: 'alert', 'data-spa': 'navigation-error', style: 'padding:0 30px' }, copy('تعذر فتح الوجهة. بقيت الصفحة الحالية دون إعادة إرسال أي طلب.', 'The destination could not be opened. The current page was retained; no request was replayed.')) : null,
    admittedRoute.value && !['closed', 'retiring'].includes(documentStatus.value) && !renderFailure.value
        ? h(RouterView, {}, { default: ({ Component }: { Component: object }) => h('div', { 'data-spa-view': true, inert: navigating.value || status.value !== 'ready' }, [h(Component, { key: router.currentRoute.value.path })]) }) : null,
    status.value !== 'ready' || renderFailure.value
        ? h('section', { role: 'status', 'data-spa': 'status', style: 'padding:30px' }, [
            documentStatus.value === 'retiring' || (documentStatus.value === 'closed' && departure.value.reason === 'handoff')
                ? copy('جارٍ الانتقال إلى مستند جديد. لا تعِد إرسال الطلب.', 'Opening a new document. Do not resend the request.')
                : status.value === 'checking' ? copy('جارٍ التحقق…', 'Checking…') : copy('تعذر التحقق من الصفحة. نتيجة أي طلب معلّق لم تتغير.', 'The page could not be verified. Pending request outcomes have not changed.'),
            documentStatus.value === 'closed' && departure.value.destination
                ? h('a', { href: departure.value.destination, 'data-spa': 'handoff' }, copy('فتح المستند الجديد', 'Open the new document'))
                : status.value !== 'checking' ? h('button', { onClick: () => location.reload(), 'data-spa': 'retry' }, copy('فتح مستند جديد من الخادم', 'Open a new server document')) : null,
        ]) : null,
];
    return h('div', { dir: locale.value === 'ar' ? 'rtl' : 'ltr' }, administrationLayout
        ? [h(AdministrationLayout, {allowedPaths:bootstrap.navigation ?? []}, { header: () => header, default: () => content })]
        : [header, ...content]);
} }));
app.config.errorHandler = error => { console.error(error); renderFailure.value = true; conceal(); router.cancelCandidate(); status.value = 'unavailable'; };
app.use(i18n); app.use(router); app.mount(root);
const browserLifecycle = installBrowserLifecycle(router);
app.onUnmount(() => browserLifecycle.dispose());
if (import.meta.env.MODE === 'proof') {
    void import('../goals-spa/proof').then(({ exposeGoalsProof }) => exposeGoalsProof(router, browserLifecycle.resume));
}
