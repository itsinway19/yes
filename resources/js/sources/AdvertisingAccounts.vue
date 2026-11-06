<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ProviderBrand from '../Components/ProviderBrand.vue';
import type { ProviderSummary } from '../Components/Business/providerPresentation';
import { locale } from '../spa/locale';
import { advertisingPlatforms, initialAdvertisingPlatform, type AdvertisingPlatform } from './providerDestination';
import MetaConnectionPanel from './MetaConnectionPanel.vue';
import GoogleConnectionPanel from './GoogleConnectionPanel.vue';
import TikTokConnectionPanel from './TikTokConnectionPanel.vue';
import SnapchatConnectionPanel from './SnapchatConnectionPanel.vue';
import XConnectionPanel from './XConnectionPanel.vue';

const props = defineProps<{ workspaceId?: string; businessId?: string; canManage?: boolean; initialProvider?: AdvertisingPlatform }>();
const hasOwner = computed(() => Boolean(props.workspaceId || props.businessId));
const ownerId = computed(() => props.businessId ? 'business:' + props.businessId : props.workspaceId);
const emit = defineEmits<{ chooseWorkspace: [provider: AdvertisingPlatform]; selected: [provider: AdvertisingPlatform] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const panels = { meta: MetaConnectionPanel, google: GoogleConnectionPanel, tiktok: TikTokConnectionPanel, snapchat: SnapchatConnectionPanel, x: XConnectionPanel };
const summaries = ref<Partial<Record<AdvertisingPlatform, ProviderSummary>>>({});
const selected = ref<AdvertisingPlatform>();
const dialog = ref<HTMLDialogElement>();
const activeButton = ref<HTMLElement>();
const platformButtons = new Map<AdvertisingPlatform, HTMLElement>();
function retainButton(provider: AdvertisingPlatform, element: unknown) {
    if (element) platformButtons.set(provider, element as HTMLElement);
    else platformButtons.delete(provider);
}
const route = useRoute();
const router = useRouter();
const mounted = ref(false);
const selectedLabel = computed(() => advertisingPlatforms.find(platform => platform.id === selected.value)?.label ?? '');
function state(provider: AdvertisingPlatform) { return hasOwner.value ? summaries.value[provider]?.state ?? 'loading' : 'unselected'; }
const labels: Record<string, [string, string]> = {
    unselected: ['اختر إدارة الحساب للتحقق', 'Choose account administration to check'], loading: ['جارٍ التحقق…', 'Checking…'],
    unknown: ['تعذر تأكيد الحالة', 'Status could not be confirmed'], unconnected: ['لم يُربط حساب بعد', 'No account connected yet'],
    incomplete: ['الربط غير متاح حاليًا', 'Connection currently unavailable'], authenticate: ['يحتاج تأكيد تسجيل الدخول', 'Confirm sign-in'],
    restricted: ['الربط متاح لمسؤول الحساب', 'Account administrator required'], disconnected: ['مفصول في BuilderAd', 'Disconnected in BuilderAd'],
    attention: ['يحتاج مراجعة الربط', 'Connection needs review'], revoked: ['صلاحية البيانات مسحوبة', 'Data access withdrawn'],
    unsupported: ['العملية المطلوبة غير مدعومة', 'Requested operation unsupported'], failed: ['لم ينجح فحص البيانات', 'Data check failed'],
    blocked: ['استعمال البيانات غير متاح', 'Data use unavailable'], ready: ['بيانات جاهزة ضمن الصلاحية', 'Data ready within its permission'],
    connected: ['متصل؛ جاهزية البيانات غير مؤكدة', 'Connected; data readiness unconfirmed'],
};
function label(provider: AdvertisingPlatform) { const copy = labels[state(provider)] ?? labels.unknown; return copy[locale.value === 'ar' ? 0 : 1]; }
async function open(provider: AdvertisingPlatform, event?: MouseEvent) {
    activeButton.value = event?.currentTarget as HTMLElement | undefined ?? platformButtons.get(provider) ?? activeButton.value;
    emit('selected', provider);
    if (!hasOwner.value) { emit('chooseWorkspace', provider); return; }
    selected.value = provider;
    await nextTick();
    dialog.value?.showModal();
}
function close(dismissResult = false) {
    const provider = selected.value;
    const trigger = provider ? platformButtons.get(provider) ?? activeButton.value : activeButton.value;
    dialog.value?.close(); selected.value = undefined; trigger?.focus();
    const fragment = new URLSearchParams(route.hash.slice(1));
    if (dismissResult && provider && fragment.has(`${provider}-result`)) {
        // A dismissed result is presentation state. Keep opaque selection flows
        // and pending commands intact, and let the router requalify the URL.
        fragment.delete(`${provider}-result`);
        const remaining = fragment.toString(), target = route.path, owner = ownerId.value;
        void router.replace({ path: target, query: route.query, hash: remaining ? `#${remaining}` : '' }).then(() => {
            if (mounted.value && ownerId.value === owner && route.path === target) trigger?.focus();
        }).catch(() => {});
    }
}
watch(ownerId, () => { summaries.value = {}; close(); if (hasOwner.value && props.initialProvider) void open(props.initialProvider); });
onMounted(() => {
    mounted.value = true;
    const requested = initialAdvertisingPlatform(route.query.provider, window.location.hash);
    if (requested && hasOwner.value) void open(requested);
});
onBeforeUnmount(() => { mounted.value = false; });
</script>

<template>
    <section class="advertising-accounts" data-advertising-accounts>
        <ul class="advertising-platforms" :aria-label="t('المنصات الإعلانية', 'Advertising platforms')">
            <li v-for="platform in advertisingPlatforms" :key="platform.id" class="advertising-platform" :data-provider="platform.id">
                <div class="advertising-platform-brand"><ProviderBrand :provider="platform.id" :size="40" /><h3 dir="ltr">{{ platform.label }}</h3></div>
                <p class="advertising-platform-status" role="status" :data-state="state(platform.id)">{{ label(platform.id) }}</p>
                <button :ref="element => retainButton(platform.id, element)" type="button" class="workspace-button" :aria-haspopup="hasOwner ? 'dialog' : undefined" @click="open(platform.id, $event)">{{ !hasOwner ? t('اختيار إدارة الحساب', 'Choose account administration') : summaries[platform.id]?.canConnect && !summaries[platform.id]?.hasConnections && canManage ? t('ربط حساب', 'Connect an account') : t('فتح الحسابات', 'Open accounts') }}</button>
            </li>
        </ul>
        <p class="advertising-account-note">{{ t('ظهور المنصة لا يعني وجود حساب متصل أو إتاحة بياناته لهذا النشاط.', 'A listed platform does not mean an account is connected or its data is available to this business.') }}</p>
        <dialog ref="dialog" class="advertising-dialog" :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-label="selectedLabel" @cancel.prevent="close(true)" @click="event => { if (event.target === dialog) close(true); }">
            <header class="advertising-dialog-heading"><strong>{{ selectedLabel }}</strong><button type="button" @click="close(true)">{{ t('إغلاق', 'Close') }} <span aria-hidden="true">×</span></button></header>
            <template v-if="hasOwner && mounted">
                <div v-for="platform in advertisingPlatforms" v-show="selected === platform.id" :key="`${ownerId}:${platform.id}`">
                    <component :is="panels[platform.id]" :workspace-id="workspaceId" :business-id="businessId" :can-manage="canManage === true" @summary="value => summaries[platform.id] = value" />
                </div>
            </template>
        </dialog>
    </section>
</template>

<style scoped>
.advertising-platforms{list-style:none;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));margin:18px 0 0;padding:0;border-block-start:1px solid var(--ad-line,#dce5e0)}.advertising-platform{display:flex;flex-direction:column;align-items:flex-start;gap:12px;min-width:0;padding:22px 18px;border-block-end:1px solid var(--ad-line,#dce5e0)}.advertising-platform-brand{display:flex;align-items:center;gap:12px}.advertising-platform h3{font-size:15px;font-weight:650;margin:0}.advertising-platform-status{font-size:12px;line-height:1.8;color:var(--ad-muted,#52665f);margin:0;flex:1}.advertising-platform .workspace-button{min-height:44px}.advertising-account-note{font-size:12px;color:var(--ad-muted,#52665f);line-height:1.8;margin-top:16px}.advertising-dialog{inset:0;margin:auto;width:min(880px,calc(100% - 28px));max-height:88dvh;border:1px solid var(--ad-line,#dce5e0);border-radius:10px;padding:0;background:var(--ad-paper,#fff);color:var(--ad-ink,#182f39);overflow:auto}.advertising-dialog::backdrop{background:#122b3570}.advertising-dialog-heading{position:sticky;top:0;z-index:3;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 20px;border-bottom:1px solid var(--ad-line,#dce5e0);background:var(--ad-paper,#fff);font-size:14px}.advertising-dialog-heading button{min-height:44px;padding:7px 12px;font:inherit;background:none;border:0;color:inherit;cursor:pointer}.advertising-dialog-heading span{font-size:22px;margin-inline-start:12px}.advertising-dialog :deep(section){border:0;border-radius:0;box-shadow:none;margin:0}.advertising-dialog :deep(section>header){background:var(--ad-paper,#fff)}.advertising-accounts :focus-visible{outline:3px solid var(--ad-accent,#155f53);outline-offset:3px}@media(min-width:1200px){.advertising-platforms{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:480px){.advertising-platforms{grid-template-columns:1fr}.advertising-platform{padding:18px 4px}.advertising-platform .workspace-button{width:100%}.advertising-dialog{width:calc(100% - 16px);max-height:94dvh}}
</style>
