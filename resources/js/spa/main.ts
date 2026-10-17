import '../../css/app.css';
import { createApp, defineComponent, h, ref, watch } from 'vue';
import { RouterView, type RouteLocationNormalized } from 'vue-router';
import { createI18n } from 'vue-i18n';
import { messages } from '../localization/messages';
import { bootstrap, status, suspend, logout } from './transport';
import { confirmDeparture } from './presentation';
import { createApplicationRouter, type ApplicationPresentation } from './router';
import { goalsRoutes } from '../goals-spa/routes';
import { exposeGoalsProof } from '../goals-spa/proof';
const root = document.getElementById('goals-spa')!;
watch(status, value => { root.dataset.state = value; }, { immediate: true, flush: 'sync' });
const style=document.createElement('style');style.textContent='#goals-spa:not([data-state="ready"]) [data-spa-private]{display:none!important} .business-main{max-width:1040px;margin:auto;padding:32px}';document.head.append(style);
let activePresentation: ApplicationPresentation | undefined;
const clearDisplay = () => { suspend(); activePresentation?.clear(); };
const load = (to: RouteLocationNormalized, passive: boolean) => {
    activePresentation = to.meta.presentation;
    if (!activePresentation) { status.value = 'unavailable'; return; }
    return activePresentation.load(to, passive);
};
const router = createApplicationRouter(goalsRoutes, {
    suspend: clearDisplay,
    load,
    unavailable: () => { status.value = 'unavailable'; },
});
const renderFailure = ref(false);
const app = createApp(defineComponent({ setup: () => () => h('div', { dir: bootstrap.locale === 'ar' ? 'rtl' : 'ltr' }, [
    h('header', { style: 'display:flex;gap:20px;padding:18px' }, [h('strong', 'BuilderAd'), h('button', { onClick: () => { if(confirmDeparture.value())void logout(); }, disabled: status.value !== 'ready', 'data-spa': 'logout' }, 'تسجيل الخروج')]),
    status.value === 'ready' && !renderFailure.value
        ? h(RouterView, {}, { default: ({ Component }: { Component: object }) => h(Component, { key: String(router.currentRoute.value.params.workspace) }) })
        : h('section', { role: 'status', 'data-spa': 'status', style: 'padding:30px' }, [status.value === 'checking' ? 'جارٍ التحقق…' : 'تعذّر التحقق من الصفحة. لم نؤكد حفظ أي طلب معلّق.',
            status.value !== 'checking' ? h('button', { onClick: () => location.reload(), 'data-spa': 'retry' }, 'إعادة التحقق من الخادم') : null]),
]) }));
app.config.errorHandler = error => { console.error(error); renderFailure.value = true; clearDisplay(); status.value = 'unavailable'; };
app.use(createI18n({ legacy: false, locale: bootstrap.locale, fallbackLocale: 'en', messages })); app.use(router); app.mount(root);
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
