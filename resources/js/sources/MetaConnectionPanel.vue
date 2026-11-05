<script setup lang="ts">
import { nativeSourceReference, commandOwner, ownerKey } from './nativeOwner';
import { providerCopy } from './providerCopy';
import { locale } from '../spa/locale';
import { providers } from './api';
import { submit, commandsFor } from './commands';
import { TransportError } from '../spa/transport';
import type { ProviderSnapshot } from './providers';
import PendingCommands from './PendingCommands.vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import { useProviderPresentation, type ProviderSummary } from '@/Components/Business/providerPresentation';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ProviderReportRequest from './ProviderReportRequest.vue';

type Access = {
    reporting?: { can_report: boolean; why_not: string | null };
    version: number;
    connection: string;
    native_observation: { state?: string; observed_at?: string } | null;
    axes: Record<string, unknown>;
    effective: { state: string; reason?: string; last_verified_at?: string | null };
};
type Connection = { source_id: string; label: string; declared_account_id: string; registration: string; access: Access };
type Account = { account_id: string; label: string; existing_source_label: string | null; selection_handle: string };
type Snapshot = ProviderSnapshot;
const props = defineProps<{ workspaceId?: string; businessId?: string; canManage: boolean }>();
const owner = nativeSourceReference(props);
const emit = defineEmits<{ summary: [summary: ProviderSummary] }>();
const snapshot = ref<Snapshot | null>(null);
const busy = ref('');
const error = ref('');
useProviderPresentation(snapshot, busy, error, emit);
const announcement = ref('');
const accounts = ref<Account[]>([]);
const coverage = ref('');
const continuation = ref<string | null>(null);
const discoveryStarted = ref(false);
const discoveryRetry = ref<string | null>(null);
const selectionRetry = ref<string | null>(null);
const disconnect = ref<{ source: Connection; command: string; expectedVersion: number } | null>(null);
let alive = true;
const abort = new AbortController();
const handlePattern = /^[A-Za-z0-9_-]{43}$/;
const canConnect = computed(() => props.canManage && !disconnect.value && !selectionRetry.value && snapshot.value?.availability === 'CONNECT_AVAILABLE' && !!snapshot.value.connect_intent);
const availabilityText = computed(() => {
    switch (snapshot.value?.availability) {
        case 'CONFIGURATION_REQUIRED': return 'الربط غير متاح حاليًا. يلزم استكمال إعداد الخدمة قبل متابعة ربط الحساب.';
        case 'FRESH_AUTHENTICATION_REQUIRED': return 'جدّد المصادقة على حسابك في Builderad لتتمكن من إدارة الربط.';
        case 'OWNER_REQUIRED': return 'يستطيع مسؤول الحساب المخول إدارة ربط الحسابات.';
        default: return props.canManage ? 'اربط حسابك عبر Facebook ثم اختر الحساب الإعلاني الذي تريد إضافته لهذا الربط.' : 'يمكنك مشاهدة الحسابات المتاحة لك. إدارة الربط تحتاج صلاحية المالك المسؤول.';
    }
});

function safeError(failure: unknown): string {
    if (!(failure instanceof TransportError)) return 'تعذّر إكمال الطلب. يمكنك المحاولة مجددًا.';
    switch (failure.status) {
        case 403: return 'تعذّر تأكيد صلاحية الطلب. جدّد المصادقة وتأكد من صلاحيتك هنا، ثم حدّث الحالة.';
        case 409: return 'تغيّرت حالة الربط أثناء الطلب. حدّث الحالة قبل متابعة الإجراء.';
        case 422: return 'تعذّر قبول هذا الطلب. حدّث الحالة وابدأ الإجراء مجددًا.';
        case 502: return 'تعذّر إكمال الاتصال بـ Meta حاليًا. يمكنك إعادة المحاولة.';
        case 503: return 'خدمة الربط غير متاحة حاليًا. يمكنك تحديث الحالة لاحقًا.';
        default: return 'لم نتمكن من تأكيد نتيجة الطلب. يمكنك إعادة المحاولة بنفس الإجراء.';
    }
}

function connectionLabel(access: Access): string {
    switch (access.connection) {
        case 'CONNECTED': return 'الحساب متصل';
        case 'RECONNECT_REQUIRED': return 'يلزم إعادة الربط';
        case 'LOCALLY_DISCONNECTED': return 'الوصول المحلي مفصول';
        case 'VERIFICATION_NEEDED': return 'التحقق من الربط غير مكتمل';
        default: return 'الحساب غير متصل';
    }
}

function readiness(access: Access): string {
    if (access.connection === 'LOCALLY_DISCONNECTED') return 'تم إيقاف الوصول المحلي. تبقى الملاحظات التاريخية مستقلة عن الربط.';
    if (access.connection === 'RECONNECT_REQUIRED') return 'تحتاج صلاحية الوصول إلى التحقق مجددًا. أعد الربط لمتابعة ذلك.';
    if (access.connection === 'CONNECTED' && access.effective.state === 'READY') return 'البيانات جاهزة للاستخدام ضمن النطاق الذي تم التحقق منه.';
    if (access.connection === 'CONNECTED') return 'الحساب متصل، لكن التحقق من جاهزية استخدام البيانات لم يكتمل بعد.';
    return 'ربط الحساب وحده لا يثبت جاهزية بياناته للتحليل.';
}

function safeLink(value: string | undefined): string | null {
    if (!value) return null;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.password ? url.href : null;
    } catch { return null; }
}

async function fetchSnapshot(passive = true): Promise<boolean> {
    try {
        const response = await providers.snapshot('meta', owner, abort.signal, passive);
        if (alive) snapshot.value = response;
        return true;
    } catch (failure) {
        if (alive) error.value = safeError(failure);
        return false;
    }
}

async function refresh() {
    if (busy.value) return;
    busy.value = 'refresh';
    error.value = '';
    const refreshed = await fetchSnapshot(false);
    if (alive) {
        busy.value = '';
        if (refreshed) announcement.value = 'تم تحديث حالة الحسابات.';
    }
}

function settled() {
    selectionRetry.value = null;
    disconnect.value = null;
    void refresh();
}

async function connect() {
    if (busy.value || !canConnect.value) return;
    busy.value = 'connect';
    error.value = '';
    try {
        const response = await providers.connect('meta', owner, snapshot.value!.connect_intent!, abort.signal);
        const url = new URL(response.authorization_url);
        if (url.protocol !== 'https:' || url.hostname !== 'www.facebook.com' || url.port || url.username || url.password || !/^\/(?:v\d+\.\d+\/)?dialog\/oauth\/?$/.test(url.pathname)) {
            throw new Error('Invalid authorization destination');
        }
        if (alive) window.location.assign(url.href);
    } catch (failure) {
        if (alive) error.value = safeError(failure);
    } finally {
        if (alive) busy.value = '';
    }
}

async function discover(flow: string) {
    if (busy.value || !handlePattern.test(flow)) return;
    busy.value = 'discovery';
    error.value = '';
    discoveryRetry.value = flow;
    discoveryStarted.value = true;
    try {
        const response = await providers.discover('meta', owner, flow, abort.signal);
        if (!alive) return;
        const known = new Set(accounts.value.map(account => account.selection_handle));
        for (const account of response.accounts) {
            if (handlePattern.test(account.selection_handle) && !known.has(account.selection_handle)) {
                known.add(account.selection_handle);
                accounts.value.push(account);
            }
        }
        coverage.value = response.coverage;
        continuation.value = response.continuation && handlePattern.test(response.continuation) ? response.continuation : null;
        discoveryRetry.value = null;
        announcement.value = accounts.value.length ? 'اختر الحساب الإعلاني المطلوب من القائمة.' : 'لم تظهر حسابات في هذه الصفحة.';
    } catch (failure) {
        if (alive) error.value = safeError(failure);
    } finally {
        if (alive) busy.value = '';
    }
}

async function selectAccount(handle: string) {
    if (busy.value || disconnect.value || !props.canManage || !handlePattern.test(handle)) return;
    busy.value = `select:${handle}`;
    error.value = '';
    selectionRetry.value = handle;
    try {
        await submit({ ...commandOwner(owner), effect: 'select', provider: 'meta', body: { selection: handle } }, abort.signal);
        if (!alive) return;
        selectionRetry.value = null;
        accounts.value = [];
        continuation.value = null;
        discoveryRetry.value = null;
        discoveryStarted.value = false;
        announcement.value = 'تم حفظ ربط الحساب. تُعرض جاهزية البيانات بشكل مستقل أدناه.';
        if (!await fetchSnapshot() && alive) error.value = 'تم حفظ الربط، لكن تعذّر تحديث القائمة. حدّث الحالة؛ لا تحتاج إلى إعادة اختيار الحساب.';
    } catch (failure) {
        if (!commandsFor(owner, 'meta').length) selectionRetry.value = null;
        if (alive) error.value = safeError(failure);
    } finally {
        if (alive) busy.value = '';
    }
}

function prepareDisconnect(source: Connection) {
    if (busy.value || selectionRetry.value || !props.canManage) return;
    disconnect.value = { source, command: crypto.randomUUID(), expectedVersion: source.access.version };
    error.value = '';
}

async function confirmDisconnect() {
    if (busy.value || !disconnect.value || !props.canManage) return;
    const action = disconnect.value;
    busy.value = 'disconnect';
    error.value = '';
    try {
        await submit({ ...commandOwner(owner), effect: 'disconnect', provider: 'meta', source: action.source.source_id, body: { command_id: action.command, expected_version: action.expectedVersion } }, abort.signal);
        if (!alive) return;
        disconnect.value = null;
        announcement.value = 'تم فصل الوصول المحلي لهذا الحساب.';
        if (!await fetchSnapshot() && alive) error.value = 'تم فصل الوصول المحلي، لكن تعذّر تحديث القائمة. حدّث الحالة للاطلاع على تفاصيلها.';
    } catch (failure) {
        if (alive) error.value = safeError(failure);
    } finally {
        if (alive) busy.value = '';
    }
}

onMounted(async () => {
    const fragment = window.location.hash;
    const flowMatch = /^#meta-flow=([A-Za-z0-9_-]{43})$/.exec(fragment);
    const cancelled = fragment === '#meta-result=AUTHORIZATION_NOT_COMPLETED';
    busy.value = 'refresh';
    await fetchSnapshot(true);
    if (!alive) return;
    busy.value = '';
    if (cancelled) announcement.value = 'لم يكتمل التفويض في Meta. يمكنك بدء الربط مجددًا عندما تكون جاهزًا.';
    if (flowMatch) await discover(flowMatch[1]);
});
onBeforeUnmount(() => { alive = false; abort.abort(); });
</script>

<template>
    <section :dir="locale === 'ar' ? 'rtl' : 'ltr'" class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="meta-connection-heading" :aria-busy="!!busy">
        <PendingCommands :workspace-id="workspaceId" :business-id="businessId" provider="meta" @settled="settled" />
        <fieldset class="min-w-0 border-0 p-0" :disabled="commandsFor(owner, 'meta').length > 0">
        <header class="flex flex-col gap-5 border-b border-slate-200 p-6 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div class="flex items-center gap-4">
                <ProviderBrand provider="meta" :size="48" />
                <div><p class="text-xs font-semibold tracking-wide text-[var(--ba-primary-ink)]">{{ providerCopy("حسابات الإعلانات · Meta") }}</p><h2 id="meta-connection-heading" class="mt-1 text-xl font-semibold text-slate-900">{{ providerCopy("ربط حسابك الإعلاني") }}</h2></div>
            </div>
            <button type="button" :disabled="!!busy" class="self-start rounded-lg px-3 py-2 text-sm font-semibold text-[var(--ba-primary-ink)] hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ba-primary-ink)] disabled:opacity-50 sm:self-auto" @click="refresh()">{{ providerCopy(busy === 'refresh' ? 'جارٍ التحديث…' : 'تحديث الحالة') }}</button>
        </header>
        <div class="p-6 sm:p-8">
            <p class="sr-only" role="status" aria-live="polite">{{ providerCopy(announcement) }}</p>
            <p v-if="announcement" class="mb-4 border-r-2 border-indigo-500 bg-indigo-50 px-4 py-3 text-sm leading-6 text-indigo-900" aria-hidden="true">{{ providerCopy(announcement) }}</p>
            <p v-if="error" role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800">{{ providerCopy(error) }}</p>
            <div v-if="!snapshot" class="py-3 text-sm leading-7 text-slate-600">{{ providerCopy(busy ? 'جارٍ قراءة حالة الربط…' : 'لم نتمكن من قراءة حالة الربط. استخدم تحديث الحالة لإعادة المحاولة.') }}</div>
            <template v-else>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="max-w-xl"><p class="text-sm leading-7 text-slate-700">{{ providerCopy(availabilityText) }}</p><p v-if="snapshot.connections.length && canConnect" class="mt-1 text-xs leading-6 text-slate-600">{{ providerCopy("لإعادة الربط، أكمل التفويض ثم اختر الحساب المسجّل نفسه من قائمة الحسابات.") }}</p></div>
                    <button type="button" :disabled="!!busy || !canConnect" class="shrink-0 self-start rounded-lg ba-brand-button px-5 py-3 text-sm font-semibold  focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ba-primary-ink)] disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-600" data-source-connect="meta" @click="connect">{{ providerCopy(busy === 'connect' ? 'جارٍ الانتقال إلى Facebook…' : snapshot.connections.length ? 'ربط حساب أو إعادة ربطه' : 'الربط عبر Facebook') }}</button>
                </div>
                <div v-if="safeLink(snapshot.notice?.privacy_url) || safeLink(snapshot.notice?.deletion_instructions_url)" class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-600">
                    <a v-if="safeLink(snapshot.notice?.privacy_url)" :href="safeLink(snapshot.notice?.privacy_url)!" target="_blank" rel="noopener noreferrer" class="underline underline-offset-4 hover:text-[var(--ba-primary-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--ba-primary-ink)]">{{ providerCopy("معلومات الخصوصية") }}</a>
                    <a v-if="safeLink(snapshot.notice?.deletion_instructions_url)" :href="safeLink(snapshot.notice?.deletion_instructions_url)!" target="_blank" rel="noopener noreferrer" class="underline underline-offset-4 hover:text-[var(--ba-primary-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--ba-primary-ink)]">{{ providerCopy("تعليمات طلب حذف البيانات") }}</a>
                </div>
                <div v-if="discoveryStarted" class="mt-6 border-t border-slate-200 pt-6" aria-labelledby="meta-account-choice-heading">
                    <h3 id="meta-account-choice-heading" class="font-semibold text-slate-900">{{ providerCopy("اختر الحساب لهذا الربط") }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ providerCopy(coverage === 'COMPLETE' ? 'اكتملت قائمة الحسابات المتاحة لهذا التفويض.' : 'عرض الحسابات لم يكتمل بعد. الحسابات الظاهرة لا تمثل بالضرورة جميع حساباتك.') }}</p>
                    <ul class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                        <li v-for="account in accounts" :key="account.selection_handle" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0"><p class="break-words font-semibold text-slate-900">{{ account.label }}</p><p class="mt-1 text-xs text-slate-600">{{ providerCopy("معرّف الحساب:") }} <bdi>{{ account.account_id }}</bdi></p><p v-if="account.existing_source_label" class="mt-2 text-sm text-indigo-800">{{ providerCopy("مسجّل باسم «") }}{{ account.existing_source_label }}{{ providerCopy("» — سيُعاد ربط السجل نفسه.") }} </p></div>
                            <button type="button" :disabled="!!busy || !!disconnect || !canManage || (!!selectionRetry && selectionRetry !== account.selection_handle)" class="shrink-0 self-start rounded-lg border border-[var(--ba-primary-ink)] px-4 py-2 text-sm font-semibold text-[var(--ba-primary-ink)] hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ba-primary-ink)] disabled:opacity-50" data-source-select @click="selectAccount(account.selection_handle)">{{ providerCopy(busy === `select:${account.selection_handle}` ? 'جارٍ حفظ الربط…' : selectionRetry === account.selection_handle ? 'إعادة تأكيد اختيار الحساب' : account.existing_source_label ? 'إعادة ربط هذا الحساب' : 'ربط هذا الحساب') }}</button>
                        </li>
                        <li v-if="!accounts.length" class="py-5 text-sm leading-7 text-slate-600">{{ providerCopy(busy === 'discovery' ? 'جارٍ تحميل الحسابات…' : 'لم تظهر حسابات متاحة في هذه الصفحة.') }}</li>
                    </ul>
                    <p v-if="selectionRetry" class="mt-3 text-sm leading-6 text-slate-700">{{ providerCopy("لم تُؤكّد نتيجة الاختيار بعد. أعد تأكيد الحساب نفسه، أو حدّث الحالة للاطلاع على الربط المحفوظ.") }}</p>
                    <button v-if="discoveryRetry || continuation" type="button" :disabled="!!busy || !!selectionRetry" class="mt-4 rounded-lg px-3 py-2 text-sm font-semibold text-[var(--ba-primary-ink)] hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--ba-primary-ink)] disabled:opacity-50" @click="discover((discoveryRetry || continuation)!)">{{ providerCopy(busy === 'discovery' ? 'جارٍ التحميل…' : discoveryRetry ? 'إعادة تحميل الحسابات' : 'عرض المزيد من الحسابات') }}</button>
                </div>
                <div class="mt-6 border-t border-slate-200 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">{{ providerCopy("الحسابات المتاحة") }} <span class="mr-2 text-slate-500">{{ snapshot.connections.length }}</span></h3>
                    <p v-if="!snapshot.connections.length" class="mt-3 text-sm leading-7 text-slate-600">{{ providerCopy("لا توجد حسابات Meta ظاهرة لك هنا حتى الآن.") }}</p>
                    <ul v-else class="mt-2 divide-y divide-slate-200">
                        <li v-for="source in snapshot.connections" :key="source.source_id" class="py-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0"><h4 class="break-words font-semibold text-slate-900">{{ source.label }}</h4><p class="mt-1 text-xs text-slate-600">{{ providerCopy("معرّف الحساب:") }} <bdi>{{ source.declared_account_id }}</bdi></p></div>
                                <span class="self-start rounded-md px-2.5 py-1 text-xs font-semibold" :class="source.access.connection === 'CONNECTED' ? 'bg-indigo-50 text-indigo-800' : source.access.connection === 'RECONNECT_REQUIRED' ? 'bg-amber-50 text-amber-900' : 'bg-slate-100 text-slate-700'">{{ providerCopy(connectionLabel(source.access)) }}</span>
                            </div>
                            <p class="mt-3 text-sm leading-7 text-slate-600">{{ readiness(source.access) }}</p>
                            <ProviderReportRequest :workspace-id="workspaceId" :business-id="businessId" :source-id="source.source_id" :version="source.access.version" provider="meta"
                                :allowed="source.access.reporting?.can_report === true" />
                            <button v-if="canManage && source.access.version > 0 && source.access.connection !== 'LOCALLY_DISCONNECTED'" type="button" :disabled="!!busy || !!disconnect || !!selectionRetry" class="mt-3 rounded px-1 py-1 text-xs font-semibold text-red-700 underline underline-offset-4 hover:text-red-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700 disabled:opacity-50" data-source-disconnect @click="prepareDisconnect(source)">{{ providerCopy("فصل الوصول المحلي") }}</button>
                        </li>
                    </ul>
                </div>
                <section v-if="disconnect" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-5" aria-labelledby="meta-disconnect-heading">
                    <h3 id="meta-disconnect-heading" class="font-semibold text-slate-900">{{ providerCopy("فصل الوصول المحلي إلى «") }}{{ disconnect.source.label }}{{ providerCopy("»؟") }} </h3>
                    <p class="mt-2 text-sm leading-7 text-slate-800">{{ providerCopy("يوقف هذا الإجراء وصول Builderad المحلي لهذا الحساب. لا يسحب التفويض من Meta ولا يحذف الملاحظات التاريخية.") }}</p>
                    <div class="mt-4 flex flex-wrap gap-3"><button type="button" :disabled="!!busy" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-50" data-source-confirm-disconnect @click="confirmDisconnect">{{ providerCopy(busy === 'disconnect' ? 'جارٍ الفصل…' : 'تأكيد الفصل المحلي') }}</button><button type="button" :disabled="!!busy" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-700 disabled:opacity-50" @click="disconnect = null">{{ providerCopy("إلغاء") }}</button></div>
                </section>
            </template>
        </div>
    </fieldset>
    </section>
</template>
