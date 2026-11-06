<script setup lang="ts">
import { ref, reactive, onBeforeUnmount } from 'vue';
import { locale } from '../spa/locale';
import { commandsFor, submit, remember, restore } from './commands';
import { stamp, owned, TransportError } from '../spa/transport';
import { read } from './state';
import PendingCommands from './PendingCommands.vue';
import AdvertisingAccounts from './AdvertisingAccounts.vue';
import { useBusinessCopy } from '@/localization/businessCopy';
import SourceRegistryPanel from './SourceRegistryPanel.vue';
const props = defineProps<{
    workspace: { public_id: string; name: string; slug: string };
    canRequestSourceAuthority: boolean; canManageServices: boolean; sourceCandidateId: string;
    sources: InstanceType<typeof SourceRegistryPanel>['$props']['sources'];
    services: { public_id: string; name: string; purpose: string }[];
    commercial: { state: string; context: { scope_id: string; market_id: string; market_label: string; configuration_id: string; catalog_identity: string; catalog_version: string; denomination_code: string; effective_at: string } | null; agreement_state: string; amount_state: string };
}>();
const { t } = useBusinessCopy(locale);
const c = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
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
        <header class="source-heading"><div><p class="biz-eyebrow">{{ c('موارد جهة العمل', 'Organization resources') }}</p><h1>{{ c('الحسابات والمصادر', 'Accounts and sources') }}</h1><p class="biz-lead">{{ c('راجع مصادر هذه الجهة، واختر المنصة لفتح تفاصيلها والأفعال المتاحة لك.', 'Review this organization’s sources. Choose a platform to open its details and your available actions.') }}</p></div></header>
        <div class="source-business"><div><span>{{ c('الجهة التنظيمية', 'Organization') }}</span><strong>{{ workspace.name }}</strong></div></div>
        <p class="biz-lead" data-source-administration-only>{{ c('إدارة اتصال أو حساب هنا لا تمنح أي نشاط حق استعمال بياناته. راجع حقوق الاستعمال داخل النشاط المعني.', 'Managing a connection or account here grants no business the right to use its data. Review use rights within the relevant business.') }}</p>
        <AdvertisingAccounts :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
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
.source-heading{margin-bottom:20px}.source-business{display:flex;gap:12px;align-items:center;padding:12px 0;border-block:1px solid var(--ad-line,#dce5e0)}.source-business>div{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.source-business span{font-size:12px;color:var(--ad-muted,#52665f)}.source-business strong{font-size:14px}.source-note{font-size:12px;color:var(--ad-muted,#52665f);line-height:1.8}.service-list{list-style:none;padding:0}.service-list li{display:flex;gap:16px;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--ad-line,#dce5e0)}.commercial-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;overflow-wrap:anywhere}.commercial-fields dt{font-size:12px;color:var(--ad-muted,#52665f)}.commercial-fields dd{font-size:13px}@media(max-width:640px){.commercial-fields{grid-template-columns:1fr}}
</style>
