<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import type { ProviderSummary } from '@/Components/Business/providerPresentation';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { locale } from '../spa/locale';
import { commandsFor, submit, remember, restore } from './commands';
import { stamp, owned, TransportError } from '../spa/transport';
import { read } from './state';
import PendingCommands from './PendingCommands.vue';
import { useBusinessCopy } from '@/localization/businessCopy';
import SourceRegistryPanel from './SourceRegistryPanel.vue';
import MetaConnectionPanel from './MetaConnectionPanel.vue';
import GoogleConnectionPanel from './GoogleConnectionPanel.vue';
import TikTokConnectionPanel from './TikTokConnectionPanel.vue';
import SnapchatConnectionPanel from './SnapchatConnectionPanel.vue';
import XConnectionPanel from './XConnectionPanel.vue';
import { initialAdvertisingPlatform } from './providerDestination';
const props = defineProps<{
    workspace: { public_id: string; name: string; slug: string };
    canRequestSourceAuthority: boolean; canManageServices: boolean; sourceCandidateId: string;
    sources: InstanceType<typeof SourceRegistryPanel>['$props']['sources'];
    services: { public_id: string; name: string; purpose: string }[];
    commercial: { state: string; context: { scope_id: string; market_id: string; market_label: string; configuration_id: string; catalog_identity: string; catalog_version: string; denomination_code: string; effective_at: string } | null; agreement_state: string; amount_state: string };
}>();
const { t } = useBusinessCopy(locale);
const providers = { meta: { label: 'Meta Ads', component: MetaConnectionPanel }, google: { label: 'Google Ads', component: GoogleConnectionPanel }, tiktok: { label: 'TikTok Ads', component: TikTokConnectionPanel }, snapchat: { label: 'Snapchat Ads', component: SnapchatConnectionPanel }, x: { label: 'X Ads', component: XConnectionPanel } };
type ProviderKey = keyof typeof providers;
const keyFor = (provider: string) => provider.toLowerCase() as ProviderKey;
const ordered = Object.keys(providers).map(provider => ({ provider }));
const c = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const summaries = ref<Record<string, ProviderSummary>>({});
const dialog = ref<HTMLDialogElement | null>(null);
const selected = ref<ProviderKey | null>(null);
const callbackProvider = /^#(meta|google|tiktok|snapchat|x)-(flow|result)=/.exec(window.location.hash)?.[1];
const sourceGrid = ref<HTMLElement | null>(null);
const panelsReady = ref(Boolean(callbackProvider));
function openSource(provider: string) { panelsReady.value = true; selected.value = keyFor(provider); dialog.value?.showModal(); }
const router = useRouter();
const route = useRoute();
function closeSource() { dialog.value?.close(); selected.value = null; if (/^#(meta|google|tiktok|snapchat|x)-(flow|result)=/.test(location.hash)) void router.replace(location.pathname + location.search); }
const statusCopy: Record<string, [string, string]> = {
    loading: ['جارٍ التحقق…', 'Checking…'], unknown: ['حالة غير متحققة', 'Status unverified'],
    unconnected: ['غير متصل', 'Not connected'], incomplete: ['الربط غير متاح حاليًا', 'Connection unavailable'],
    authenticate: ['يحتاج تأكيد الدخول', 'Confirm sign-in'], restricted: ['يحتاج صلاحية الربط', 'Connection permission needed'],
    disconnected: ['مفصول محليًا', 'Locally disconnected'], attention: ['يحتاج مراجعة الربط', 'Connection needs review'],
    revoked: ['صلاحية استخدام البيانات مسحوبة', 'Data access revoked'], unsupported: ['القدرة المطلوبة غير مدعومة', 'Required capability unsupported'], failed: ['لم ينجح التحقق من البيانات', 'Data verification failed'], blocked: ['استخدام البيانات غير متاح', 'Data use unavailable'], ready: ['بيانات جاهزة ضمن النطاق', 'Data ready within scope'],
    connected: ['متصل · الجاهزية تحتاج تحققًا', 'Connected · readiness unverified'],
};
function stateFor(provider: string) { return summaries.value[keyFor(provider)]?.state ?? 'loading'; }
function stateLabel(provider: string) { return (statusCopy[stateFor(provider)] ?? statusCopy.unknown)[locale.value === 'ar' ? 0 : 1]; }
function actionLabel(provider: string) {
    const summary = summaries.value[keyFor(provider)];
    return summary?.canConnect && !summary.hasConnections && props.canRequestSourceAuthority
        ? c('ربط المصدر', 'Connect source') : c('عرض المصدر', 'View source');
}
onMounted(async () => {
    const requested = initialAdvertisingPlatform(route.query.provider, window.location.hash);
    if (requested) {
        openSource(requested);
        return;
    }
    // Visible branding gets a head start over the five snapshot requests on
    // the single-worker local preview. Slow/broken images cannot block actions.
    const logos = Array.from(sourceGrid.value?.querySelectorAll('img') ?? []);
    let timeout: ReturnType<typeof setTimeout> | undefined;
    try {
        await Promise.race([
            Promise.all(logos.map(image => image.decode().catch(() => undefined))),
            new Promise<void>(resolve => { timeout = setTimeout(resolve, 3000); }),
        ]);
    } finally {
        clearTimeout(timeout);
        panelsReady.value = true;
    }
});
const form = reactive({ service: '', purpose: '', errors: {} as Record<string, string>, processing: false, recentlySuccessful: false });
const busy = ref('');
const error = ref('');
const saved = restore<{ service: string; purpose: string }>(props.workspace.public_id, 'service-form');
if (saved) { form.service = saved.service; form.purpose = saved.purpose; }
onBeforeUnmount(() => remember(props.workspace.public_id, 'service-form', { service: form.service, purpose: form.purpose }));
async function authorizeService() {
    if (form.processing) return;
    const captured = stamp(); form.processing = true; form.errors = {}; form.recentlySuccessful = false;
    try {
        await submit({ workspace: props.workspace.public_id, effect: 'service', body: { service: form.service, purpose: form.purpose } });
        if (!owned(captured)) return;
        await read(props.workspace.public_id, true);
        if (!owned(captured)) return;
        form.service = ''; form.purpose = ''; form.recentlySuccessful = true;
    } catch (failure) {
        if (!owned(captured)) return;
        if (failure instanceof TransportError) form.errors = Object.fromEntries(Object.entries(failure.body.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        error.value = t('revokeError');
    } finally { form.processing = false; }
}
async function revokeService(service: string) {
    if (busy.value) return;
    const captured = stamp(); busy.value = service; error.value = '';
    try { await submit({ workspace: props.workspace.public_id, effect: 'revoke-service', source: service }); if (owned(captured)) await read(props.workspace.public_id, true); }
    catch { if (owned(captured)) error.value = t('revokeError'); }
    finally { busy.value = ''; }
}
</script>
<template>
    <div>
        <header class="source-heading"><div><p class="biz-eyebrow">{{ c('موارد مساحة العمل', 'Workspace resources') }}</p><h1>{{ c('الحسابات والمصادر', 'Accounts and sources') }}</h1><p class="biz-lead">{{ c('راجع مصادر هذه المساحة، واختر المنصة لفتح تفاصيلها والأفعال المتاحة لك.', 'Review this workspace’s sources. Choose a platform to open its details and your available actions.') }}</p></div></header>
        <div class="source-business"><div><span>{{ c('الجهة التنظيمية', 'Organization') }}</span><strong>{{ workspace.name }}</strong></div></div>
        <p class="biz-lead" data-source-administration-only>{{ c('إدارة اتصال أو حساب هنا لا تمنح أي نشاط حق استعمال بياناته. راجع حقوق الاستعمال داخل النشاط المعني.', 'Managing a connection or account here grants no business the right to use its data. Review use rights within the relevant business.') }}</p>
        <section aria-labelledby="source-collection-title" class="source-collection">
            <div class="source-collection-heading"><h2 id="source-collection-title">{{ c('أين تعلن؟', 'Where do you advertise?') }}</h2><p>{{ c('ابدأ بما تستخدمه بالفعل. يمكنك إضافة مصادر أخرى لاحقًا.', 'Start with what you already use. You can add other sources later.') }}</p></div>
            <div ref="sourceGrid" class="source-grid"><article v-for="source in ordered" :key="source.provider" class="source-tile" :data-provider="keyFor(source.provider)">
                <header><ProviderBrand :provider="keyFor(source.provider)" :size="48" /><h3 dir="ltr">{{ providers[keyFor(source.provider)].label }}</h3></header>
                <p class="source-usefulness" dir="auto">{{ c('الحملات والإنفاق وبيانات النتائج التي توفرها المنصة.', 'Campaigns, spending and result data supplied by the platform.') }}</p>
                <p class="source-state" :data-state="stateFor(source.provider)" role="status"><span aria-hidden="true">{{ stateFor(source.provider) === 'ready' ? '✓' : stateFor(source.provider) === 'attention' || stateFor(source.provider) === 'blocked' ? '!' : '•' }}</span>{{ stateLabel(source.provider) }}</p>
                <button class="source-action" type="button" aria-haspopup="dialog" @click="openSource(source.provider)">{{ actionLabel(source.provider) }}<span aria-hidden="true">{{ locale === 'ar' ? '←' : '→' }}</span></button>
            </article></div>
        </section>
        <aside class="source-note"><span aria-hidden="true">ⓘ</span><p>{{ c('الاتصال وحده لا يعني جاهزية البيانات للتحليل. افتح المصدر للتحقق من الحسابات والصلاحيات.', 'A connection alone does not establish data readiness. Open a source to check accounts and permissions.') }}</p></aside>
        <dialog ref="dialog" class="source-dialog" :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-label="selected ? providers[selected].label : t('sources')" @cancel="selected = null" @click="event => { if (event.target === dialog) closeSource(); }">
            <div class="source-dialog-heading"><span>{{ c('تفاصيل المصدر', 'Source details') }}</span><button type="button" class="source-dialog-close" @click="closeSource">{{ c('إغلاق', 'Close') }} <span aria-hidden="true">×</span></button></div>
            <div v-for="(provider, key) in providers" v-show="selected === key" :key="`${key}-${workspace.public_id}`"><component v-if="panelsReady" :is="provider.component" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" @summary="summary => summaries[key] = summary" /></div>
        </dialog>
        <PendingCommands :workspace-id="workspace.public_id" @settled="read(workspace.public_id, true)" />
        <details class="biz-divider"><summary>{{ t('technical') }}</summary>
            <fieldset class="min-w-0 border-0 p-0" :disabled="commandsFor(workspace.public_id).length > 0"><SourceRegistryPanel :workspace-id="workspace.public_id" :candidate-id="sourceCandidateId" :can-request-authority="canRequestSourceAuthority" :sources="sources" />
            <section v-if="canManageServices" class="biz-divider"><h2>{{ t('services') }}</h2><form class="biz-form" @submit.prevent="authorizeService"><label>{{ t('serviceId') }}<input v-model="form.service" required autocomplete="off" dir="ltr" /><span class="biz-error">{{ form.errors.service }}</span></label><label>{{ t('purpose') }}<input v-model="form.purpose" required minlength="10" maxlength="128" /><span class="biz-error">{{ form.errors.purpose }}</span></label><button class="biz-button" :disabled="form.processing">{{ form.processing ? t('saving') : t('authorize') }}</button></form><p v-if="form.recentlySuccessful" class="biz-success" role="status">{{ t('saved') }}</p><p v-if="error" role="alert" class="biz-error">{{ error }}</p><ul class="service-list"><li v-for="service in services" :key="service.public_id"><div><strong>{{ service.name }}</strong><p>{{ service.purpose }}</p><small dir="ltr">{{ service.public_id }}</small></div><button class="biz-link" :disabled="!!busy" @click="revokeService(service.public_id)">{{ t('revoke') }}</button></li><li v-if="!services.length">{{ t('noServices') }}</li></ul></section>
            <section class="biz-divider"><h2>{{ t('commercial') }}</h2><p v-if="!commercial.context" class="biz-lead">{{ t('noCommercial') }}</p><dl v-else class="commercial-fields"><div v-for="(value, key) in commercial.context" :key="key"><dt>{{ key }}</dt><dd dir="auto">{{ value }}</dd></div></dl><p v-if="commercial.context" class="biz-lead">{{ t('commercialNote') }}</p></section>
            </fieldset>
        </details>
    </div>
</template>
<style scoped>
.source-heading{margin-bottom:24px}.source-heading .biz-lead{margin-top:8px}.source-business{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 20px;background:#eff3ef;border:1px solid #e2e8e2;border-radius:8px}.source-business>div{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.source-business>div>span:first-child{font-size:12px;color:var(--biz-muted)}.source-business strong{font-size:16px}.source-business-type{font-size:13px;border-inline-start:1px solid #cad6cd;padding-inline-start:14px;color:var(--biz-accent)}.source-business>.biz-link{font-size:13px;flex-shrink:0}.source-collection{margin-top:30px}.source-collection-heading{margin-bottom:18px}.source-collection-heading p{font-size:14px;color:var(--biz-muted);margin-top:3px}.source-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.source-tile{display:flex;flex-direction:column;padding:24px 22px 20px;border:1px solid var(--biz-line);background:white;border-radius:10px;min-width:0;transition:border-color .15s,box-shadow .15s}.source-tile:hover{border-color:#9db6aa;box-shadow:0 4px 14px #182d3008}.source-tile header{display:flex;align-items:center;gap:14px}.source-tile h3{font-size:17px;font-weight:700;line-height:1.5}.source-usefulness{font-size:14px;color:var(--biz-muted);margin-top:18px;margin-bottom:20px;flex:1}.source-state{display:flex;align-items:center;gap:7px;color:#53666a;font-size:12px;min-height:24px}.source-state>span{font-size:16px}.source-state[data-state=ready]{color:#14635b}.source-state[data-state=attention],.source-state[data-state=blocked],.source-state[data-state=revoked]{color:#925121}.source-action{margin-top:16px;display:flex;align-items:center;justify-content:space-between;width:100%;padding:10px 14px;font-size:14px;font-weight:650;min-height:44px;color:var(--biz-accent);border:1px solid #bdcec7;border-radius:5px;background:white}.source-action:hover{background:#f0f6f2;border-color:var(--biz-accent)}.source-note{display:flex;gap:10px;align-items:start;color:var(--biz-muted);margin-top:20px;font-size:13px}.source-note>span{color:var(--biz-accent);font-size:17px}.source-note p{max-width:780px}.source-dialog{position:fixed;inset:0;margin:auto;width:calc(100% - 40px);max-width:880px;max-height:85vh;max-height:85dvh;padding:0;border:1px solid var(--biz-line);border-radius:12px;background:white;color:var(--biz-ink);overflow:auto;box-shadow:0 20px 80px #182d302e}.source-dialog::backdrop{background:#182d3070}.source-dialog-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;background:white;padding:14px 24px;border-bottom:1px solid var(--biz-line);position:sticky;top:0;z-index:2;font-size:13px}.source-dialog-close{display:flex;gap:12px;align-items:center;min-height:40px;padding:4px 10px;color:var(--biz-accent)}.source-dialog-close span{font-size:24px}.source-dialog :deep(section){border:0;border-radius:0;box-shadow:none;margin:0}.source-dialog :deep(p),.source-dialog :deep(li){overflow-wrap:anywhere}.prior{padding:24px 0;border-bottom:1px solid var(--biz-line);max-width:850px}.prior h3{font-size:17px;font-weight:650}.prior-brand{display:flex;align-items:center;gap:10px}.prior>p{color:var(--biz-muted);margin:8px 0 18px}.prior dl{display:grid;grid-template-columns:1fr 1fr;gap:24px;font-size:14px}.prior dt{font-weight:650;margin-bottom:7px}.prior dd{color:var(--biz-muted);line-height:1.8}.service-list{margin-top:24px}.service-list li{display:flex;justify-content:space-between;gap:20px;padding:16px 0;border-bottom:1px solid var(--biz-line)}.commercial-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px;font-size:13px;overflow-wrap:anywhere}.commercial-fields dt{color:var(--biz-muted)}@media(min-width:1280px){.source-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.source-tile{padding:22px 16px 18px}.source-tile header{flex-direction:column;gap:12px;text-align:center}.source-tile h3{font-size:16px}.source-usefulness{text-align:center;font-size:13px;margin-top:16px}.source-state{justify-content:center;text-align:center;min-height:42px;line-height:1.7}.source-action{padding:10px 12px;font-size:13px}}@media(max-width:900px){.source-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.source-business{padding:14px 16px;align-items:start}.source-business>div{gap:6px 12px}.source-business strong{width:100%;order:-1}.source-business-type{border:0;padding:0}.source-grid{grid-template-columns:1fr;gap:12px}.source-tile{padding:18px 20px}.source-usefulness{margin-top:12px;margin-bottom:12px}.source-tile h3{font-size:17px}.source-collection{margin-top:24px}.source-dialog{width:calc(100% - 20px);max-height:92vh;max-height:92dvh}.source-dialog-heading{padding:10px 16px}.source-dialog :deep(section){padding:20px}.source-dialog :deep(section>header){padding:0 0 20px}.source-dialog :deep(section>div.p-6){padding:20px 0 0}.prior dl,.commercial-fields{grid-template-columns:1fr}}
</style>
