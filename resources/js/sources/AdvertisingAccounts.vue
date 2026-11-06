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
function accountAction(provider: AdvertisingPlatform) {
    if (!hasOwner.value) return t('اختيار جهة الحساب', 'Choose the account owner');
    const summary = summaries.value[provider];
    if (summary?.canConnect && !summary.hasConnections && props.canManage) return t('ربط حساب', 'Connect an account');
    if (summary?.hasConnections) return t('عرض الحسابات', 'View accounts');
    return t('عرض التفاصيل', 'View details');
}
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
                <button :ref="element => retainButton(platform.id, element)" type="button" class="workspace-button" :aria-haspopup="hasOwner ? 'dialog' : undefined" :aria-label="`${accountAction(platform.id)} · ${platform.label}`" @click="open(platform.id, $event)">{{ accountAction(platform.id) }}</button>
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
.advertising-platforms {
    list-style: none;
    margin: 12px 0 0;
    padding: 0;
    border: 1px solid var(--ad-line, #dce5e0);
    border-radius: 9px;
    background: var(--ad-paper, #fff);
}
.advertising-platform {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(0, 1fr) auto;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
}
.advertising-platform + .advertising-platform { border-block-start: 1px solid var(--ad-line, #dce5e0); }
.advertising-platform-brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
.advertising-platform h3 { font-size: 14px; font-weight: 650; margin: 0; }
.advertising-platform-status, .advertising-account-note { font-size: 12px; line-height: 1.8; color: var(--ad-muted, #52665f); }
.advertising-platform-status { margin: 0; }
.advertising-platform .workspace-button { min-height: 44px; }
.advertising-account-note { margin-block: 12px 0; }
.advertising-dialog { inset: 0; margin: auto; width: min(880px, calc(100% - 28px)); max-height: 88dvh; border: 1px solid var(--ad-line, #dce5e0); border-radius: 10px; padding: 0; background: var(--ad-paper, #fff); color: var(--ad-ink, #182f39); overflow: auto; }
.advertising-dialog::backdrop { background: #122b3570; }
.advertising-dialog-heading { position: sticky; inset-block-start: 0; z-index: 3; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 20px; border-block-end: 1px solid var(--ad-line, #dce5e0); background: var(--ad-paper, #fff); font-size: 14px; }
.advertising-dialog-heading button { min-height: 44px; padding: 7px 12px; font: inherit; background: none; border: 0; color: inherit; cursor: pointer; }
.advertising-dialog-heading span { font-size: 22px; margin-inline-start: 12px; }
.advertising-dialog :deep(section) { border: 0; border-radius: 0; box-shadow: none; margin: 0; }
.advertising-dialog :deep(section > header) { background: var(--ad-paper, #fff); }
.advertising-accounts :focus-visible { outline: 3px solid var(--ad-accent, #155f53); outline-offset: 3px; }
@media (max-width: 560px) {
    .advertising-platform { grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px; padding: 10px 12px; }
    .advertising-platform-status { grid-row: 2; grid-column: 1; }
    .advertising-platform .workspace-button { grid-row: 1 / 3; grid-column: 2; }
    .advertising-dialog { width: calc(100% - 16px); max-height: 94dvh; }
}
</style>
