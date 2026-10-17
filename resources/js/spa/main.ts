import '../../css/app.css';
import { createApp, defineComponent, h, ref, watch } from 'vue';
import { RouterLink, RouterView, type RouteLocationNormalized } from 'vue-router';
import { createI18n } from 'vue-i18n';
import { messages } from '../localization/messages';
import { bootstrap, status, suspend, logout } from './transport';
import { confirmDeparture } from './presentation';
import { createApplicationRouter, type ApplicationPresentation } from './router';
import { goalsRoutes } from '../goals-spa/routes';
import { exposeGoalsProof } from '../goals-spa/proof';
import { workspaceRoutes } from '../workspaces/routes';
import { businessContextRoutes } from '../business-context/routes';
import { objectPurposeRoutes } from '../object-purpose/routes';
import { locale, localeBusy, switchLocale } from './locale';
const root = (document.getElementById('application-spa') ?? document.getElementById('goals-spa'))!;
watch(status, value => { root.dataset.state = value; }, { immediate: true, flush: 'sync' });
const style=document.createElement('style');style.textContent='[data-application-spa]:not([data-state="ready"]) [data-spa-private]{display:none!important} .business-main{max-width:1040px;margin:auto;padding:32px}';document.head.append(style);
let activePresentation: ApplicationPresentation | undefined;
const clearDisplay = () => { suspend(); activePresentation?.clear(); };
const load = (to: RouteLocationNormalized, passive: boolean) => {
    activePresentation = to.meta.presentation;
    if (!activePresentation) { status.value = 'unavailable'; return; }
    return activePresentation.load(to, passive);
};
const router = createApplicationRouter([...workspaceRoutes, ...goalsRoutes, ...businessContextRoutes, ...objectPurposeRoutes], {
    suspend: clearDisplay,
    load,
    unavailable: () => { status.value = 'unavailable'; },
});
const renderFailure = ref(false);
const localeError = ref(false);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const i18n = createI18n({ legacy: false, locale: locale.value, fallbackLocale: 'en', messages });
watch(locale, value => { i18n.global.locale.value = value; });
const app = createApp(defineComponent({ setup: () => () => h('div', { dir: locale.value === 'ar' ? 'rtl' : 'ltr' }, [
    h('header', { style: 'display:flex;gap:20px;padding:18px;flex-wrap:wrap;align-items:center' }, [h('strong', 'BuilderAd'),
        bootstrap.application ? h(RouterLink, { to: '/workspaces' }, () => copy('أنشطتي', 'My businesses')) : null,
        bootstrap.application && typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/business` }, () => copy('سياق النشاط', 'Business context')) : null,
        bootstrap.application && typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/goals` }, () => copy('الأهداف', 'Goals')) : null,
        bootstrap.application && typeof router.currentRoute.value.params.workspace === 'string' ? h(RouterLink, { to: `/workspaces/${encodeURIComponent(router.currentRoute.value.params.workspace)}/object-purposes` }, () => copy('الغرض الإعلاني', 'Object purpose')) : null,
        bootstrap.application ? h('a', { href: '/profile' }, copy('الحساب', 'Account')) : null,
        bootstrap.application ? h('a', { href: '/security/devices' }, copy('الأمان', 'Security')) : null,
        bootstrap.application ? h('button', { disabled: localeBusy.value || status.value !== 'ready', 'data-application': 'locale', onClick: async () => { localeError.value = false; try { await switchLocale(); } catch { localeError.value = true; } } }, locale.value === 'ar' ? 'English' : 'العربية') : null,
        h('button', { onClick: () => { if(confirmDeparture.value())void logout(); }, disabled: status.value !== 'ready', 'data-spa': 'logout' }, copy('تسجيل الخروج', 'Sign out'))]),
    localeError.value ? h('p', { role: 'alert' }, copy('تعذّر حفظ اللغة. حاول مجددًا.', 'The language change could not be confirmed. Try again.')) : null,
    status.value === 'ready' && !renderFailure.value
        ? h(RouterView, {}, { default: ({ Component }: { Component: object }) => h(Component, { key: String(router.currentRoute.value.params.workspace) }) })
        : h('section', { role: 'status', 'data-spa': 'status', style: 'padding:30px' }, [status.value === 'checking' ? copy('جارٍ التحقق…', 'Checking…') : copy('تعذّر التحقق من الصفحة. لم نؤكد حفظ أي طلب معلّق.', 'The page could not be verified. Pending writes have not been confirmed.'),
            status.value !== 'checking' ? h('button', { onClick: () => location.reload(), 'data-spa': 'retry' }, copy('إعادة التحقق من الخادم', 'Verify with the server again')) : null]),
]) }));
app.config.errorHandler = error => { console.error(error); renderFailure.value = true; clearDisplay(); status.value = 'unavailable'; };
app.use(i18n); app.use(router); app.mount(root);
const resume = () => { if (status.value !== 'closed') { clearDisplay(); void load(router.currentRoute.value, true); } };
addEventListener('pagehide', clearDisplay);
addEventListener('pageshow', event => { if (event.persisted) resume(); });
document.addEventListener('visibilitychange', () => { if (document.hidden) clearDisplay(); else resume(); });
if (typeof BroadcastChannel !== 'undefined') {
    let hints: BroadcastChannel;
    const connect = () => { hints?.close(); hints = new BroadcastChannel('builderad-visibility'); hints.onmessage = resume; };
    connect(); // Hints only trigger authoritative reads; they grant nothing.
    addEventListener('pagehide', () => hints.close());
    addEventListener('pageshow', event => { if (event.persisted) connect(); });
}
if (import.meta.env.MODE === 'proof') exposeGoalsProof(router, resume);
