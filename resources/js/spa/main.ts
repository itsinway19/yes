import '../../css/app.css';
import { createApp, defineComponent, h, ref, watch } from 'vue';
import { RouterLink, RouterView, type RouteLocationNormalized } from 'vue-router';
import { createI18n } from 'vue-i18n';
import { messages } from '../localization/messages';
import { bootstrap, documentStatus, departure, startLease, logout, internalBoundary, onClose } from './transport';
import { confirmDeparture, status, admittedRoute, navigating, navigationError, conceal, prepare, commitRoute, failed } from './presentation';
import { installBrowserLifecycle } from './browserLifecycle';
import { createApplicationRouter } from './router';
import { independentBusinessRoutes } from '../business-foundation/routes';
import { sourceRoutes } from '../sources/routes';
import { accountRoutes } from '../account/routes';
import { authEntryRoutes } from '../auth-entry/routes';
import { staffEnrollmentRoutes } from '../staff-enrollment/routes';
import { internalOperationsRoutes } from '../internal-operations/routes';
import { commercialRoutes } from '../commercial/routes';
import { contentRoutes } from '../content/routes';
import { locale, localeBusy, switchLocale } from './locale';
import AdministrationLayout from '../internal-operations/AdministrationLayout.vue';
import BrandLogo from '../Components/BrandLogo.vue';
import ThemeToggle from '../Components/ThemeToggle.vue';
import { isAdvertiserWorkspacePath } from '../business-foundation/advertiserNavigation';
import '../business-foundation/advertiser.css';
const root = document.getElementById('application-spa')!;
watch(status, value => { root.dataset.state = value; }, { immediate: true, flush: 'sync' });
const concealment = document.createElement('style');
concealment.textContent = '[data-application-spa]:not([data-state="ready"]) :is([data-spa-view], [data-spa-private]){display:none!important}';
document.head.append(concealment);
const router = createApplicationRouter([...independentBusinessRoutes, ...sourceRoutes, ...accountRoutes, ...authEntryRoutes, ...staffEnrollmentRoutes, ...internalOperationsRoutes, ...commercialRoutes, ...contentRoutes], {
    pending: (active, passive) => { if (active && passive) conceal(); navigating.value = active; if (active) navigationError.value = false; },
    prepare, commit: commitRoute, failed,
});
onClose(() => router.cancelCandidate());
startLease();
const renderFailure = ref(false);
const localeError = ref(false);
const progressVisible = ref(false);
watch(() => documentStatus.value === 'active' && !renderFailure.value
    && (status.value === 'checking' || (status.value === 'ready' && navigating.value)), (pending, _previous, onCleanup) => {
    progressVisible.value = false;
    if (!pending) return;
    const timer = setTimeout(() => { progressVisible.value = true; }, 700);
    onCleanup(() => clearTimeout(timer));
}, { immediate: true });
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const i18n = createI18n({ legacy: false, locale: locale.value, fallbackLocale: 'en', messages });
watch(locale, value => { i18n.global.locale.value = value; });
const app = createApp(defineComponent({ setup: () => () => {
    const administrationLayout = internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0;
    const advertiserHeader = !internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 && isAdvertiserWorkspacePath(router.currentRoute.value.path);
    const registrationHeader = !internalBoundary && bootstrap.identity.binding.principal === 0 && router.currentRoute.value.path === '/register';
    const administrativePaths = administrationLayout ? bootstrap.navigation ?? [] : [];
    const administrationHome = administrativePaths.includes('/internal/administration') ? '/internal/administration' : administrativePaths[0];
    const headerItems = [h(BrandLogo, { to: administrationHome }), h(ThemeToggle),
        administrationLayout ? h('span', { 'data-admin-context': true }, copy('الإدارة المركزية', 'Central administration')) : null,
        !internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 && !advertiserHeader ? h(RouterLink, { to: '/businesses' }, () => copy('أنشطتي', 'My businesses')) : null,
        !internalBoundary && !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: '/profile' }, () => copy('الحساب', 'Account')) : null,
        !bootstrap.staffEnrollment && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: internalBoundary ? '/internal/security/devices' : '/security/devices' }, () => copy('الأمان', 'Security')) : null,
        internalBoundary && bootstrap.identity.binding.principal > 0 ? h(RouterLink, { to: '/internal/identity/switch', ...(administrationLayout ? { class: 'ba-admin-identity-switch', 'aria-label': copy('تبديل الهوية الداخلية', 'Switch internal identity') } : {}) }, () => administrationLayout ? copy('تبديل الهوية', 'Switch identity') : copy('تبديل الهوية الداخلية', 'Switch internal identity')) : null,
        h('button', { disabled: localeBusy.value || status.value !== 'ready', 'data-application': 'locale', onClick: async () => { localeError.value = false; try { await switchLocale(); } catch { localeError.value = true; } } }, locale.value === 'ar' ? 'English' : 'العربية'),
        registrationHeader ? h(RouterLink, { to: '/login', class: 'application-registration-signin' }, () => copy('لدي حساب', 'Sign in')) : null,
        bootstrap.identity.binding.principal > 0 ? h('button', { onClick: () => { if(confirmDeparture.value())void logout(); }, disabled: status.value !== 'ready', 'data-spa': 'logout' }, copy('تسجيل الخروج', 'Sign out')) : null];
    const header = h(administrationLayout ? 'div' : 'header', administrationLayout ? { class: 'ba-admin-header-controls' } : advertiserHeader ? { class: 'advertiser-appbar' } : { class: ['application-appbar', { 'application-appbar--registration': registrationHeader }] }, advertiserHeader
        ? [h('div', { id: 'advertiser-header-context', class: 'advertiser-header-context' }), headerItems[1], h('details', { class: 'advertiser-account-menu' }, [h('summary', {}, copy('حسابي', 'My account')), h('div', { class: 'advertiser-account-actions' }, headerItems.slice(2))])]
        : headerItems);
    const handoff = documentStatus.value === 'retiring' || (documentStatus.value === 'closed' && departure.value.reason === 'handoff');
    const unavailable = renderFailure.value || status.value === 'unavailable' || (status.value === 'closed' && !handoff);
    const checking = status.value === 'checking';
    const routeProgress = !handoff && !unavailable && progressVisible.value && (checking || navigating.value);
    const notice = handoff || unavailable || routeProgress ? h('div', {
        class: ['application-route-notice', { 'application-route-progress': routeProgress }],
        'data-spa': routeProgress && !checking ? 'navigation' : 'status',
    }, [
        h('p', { role: unavailable ? 'alert' : 'status', class: routeProgress ? 'sr-only' : undefined }, handoff
            ? copy('جارٍ فتح الصفحة الجديدة…', 'Opening a new document…')
            : unavailable
                ? copy('تعذّر التحقق من الصفحة. يمكنك فتحها مجددًا للتحقق من حالة طلبك.', 'The page could not be verified. Reopen it to check your request status.')
                : admittedRoute.value ? copy('جارٍ تحديث الصفحة…', 'Updating page…') : copy('جارٍ فتح الصفحة…', 'Loading page…')),
        handoff && departure.value.destination
            ? h('a', { href: departure.value.destination, 'data-spa': 'handoff' }, copy('فتح الصفحة الجديدة', 'Open the new document'))
            : unavailable ? h('button', { onClick: () => location.reload(), 'data-spa': 'retry' }, copy('فتح الصفحة مجددًا', 'Reopen page')) : null,
    ]) : null;
    const content = [
        localeError.value ? h('p', { role: 'alert' }, copy('تعذّر حفظ اللغة. حاول مجددًا.', 'The language change could not be confirmed. Try again.')) : null,
        navigationError.value && admittedRoute.value && status.value === 'ready' ? h('p', { role: 'alert', 'data-spa': 'navigation-error', class: 'application-route-notice' }, copy('تعذّر فتح الوجهة. ما زلت في الصفحة الحالية.', 'The destination could not be opened. You are still on the current page.')) : null,
        notice,
        admittedRoute.value && !['closed', 'retiring'].includes(documentStatus.value) && !renderFailure.value
            ? h(RouterView, {}, { default: ({ Component }: { Component: object }) => h('div', { 'data-spa-view': true, inert: navigating.value || status.value !== 'ready' }, [h(Component, { key: router.currentRoute.value.path })]) }) : null,
    ];
    return h('div', { dir: locale.value === 'ar' ? 'rtl' : 'ltr' }, administrationLayout
        ? [h(AdministrationLayout, { allowedPaths: administrativePaths }, { header: () => header, default: () => content })]
        : [header, ...content]);
} }));
app.config.errorHandler = error => { console.error(error); renderFailure.value = true; conceal(); router.cancelCandidate(); status.value = 'unavailable'; };
app.use(i18n); app.use(router); app.mount(root);
const browserLifecycle = installBrowserLifecycle(router);
app.onUnmount(() => browserLifecycle.dispose());
if (import.meta.env.MODE === 'proof') {
    void import('../goals-spa/proof').then(({ exposeGoalsProof }) => exposeGoalsProof(router, browserLifecycle.resume));
}
