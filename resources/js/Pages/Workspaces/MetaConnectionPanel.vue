<script setup lang="ts">
import axios from 'axios';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import { useProviderPresentation, type ProviderSummary } from '@/Components/Business/providerPresentation';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ProviderReportRequest from './ProviderReportRequest.vue';

type Access = {
    version: number;
    connection: string;
    native_observation: { state?: string; observed_at?: string } | null;
    axes: Record<string, unknown>;
    effective: { state: string; reason?: string; last_verified_at?: string | null };
};
type Connection = { source_id: string; label: string; declared_account_id: string; registration: string; access: Access };
type Account = { account_id: string; label: string; existing_source_label: string | null; selection_handle: string };
type Snapshot = {
    connections: Connection[];
    connect_intent: string | null;
    availability: string;
    notice: { privacy_url: string; deletion_instructions_url: string; revision: string; purpose: string } | null;
};
const props = defineProps<{ workspaceId: string; canManage: boolean }>();
const emit = defineEmits<{ summary: [summary: ProviderSummary] }>();
const base = `/workspaces/${encodeURIComponent(props.workspaceId)}/providers/meta`;
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
const requestOptions = { signal: abort.signal, headers: { Accept: 'application/json' } };
const handlePattern = /^[A-Za-z0-9_-]{43}$/;
const canConnect = computed(() => props.canManage && !disconnect.value && !selectionRetry.value && snapshot.value?.availability === 'CONNECT_AVAILABLE' && !!snapshot.value.connect_intent);
const availabilityText = computed(() => {
    switch (snapshot.value?.availability) {
        case 'CONFIGURATION_REQUIRED': return 'الربط غير متاح حاليًا. يلزم استكمال إعداد الخدمة قبل متابعة ربط الحساب.';
        case 'FRESH_AUTHENTICATION_REQUIRED': return 'جدّد المصادقة على حسابك في Builderad لتتمكن من إدارة الربط.';
        case 'OWNER_REQUIRED': return 'يستطيع مالك المساحة المسؤول إدارة ربط الحسابات.';
        default: return props.canManage ? 'اربط حسابك عبر Facebook ثم اختر الحساب الإعلاني الذي تريد إضافته لهذه المساحة.' : 'يمكنك مشاهدة الحسابات المتاحة لك. إدارة الربط تحتاج صلاحية المالك المسؤول.';
    }
});

function safeError(failure: unknown): string {
    if (!axios.isAxiosError(failure)) return 'تعذّر إكمال الطلب. يمكنك المحاولة مجددًا.';
    switch (failure.response?.status) {
        case 403: return 'تعذّر تأكيد صلاحية الطلب. جدّد المصادقة وتأكد من صلاحيتك في هذه المساحة، ثم حدّث الحالة.';
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

async function fetchSnapshot(): Promise<boolean> {
    try {
        const response = await axios.get<Snapshot>(base, requestOptions);
        if (alive) snapshot.value = response.data;
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
    const refreshed = await fetchSnapshot();
    if (alive) {
        busy.value = '';
        if (refreshed) announcement.value = 'تم تحديث حالة الحسابات.';
    }
}

async function connect() {
    if (busy.value || !canConnect.value) return;
    busy.value = 'connect';
    error.value = '';
    try {
        const response = await axios.post<{ authorization_url: string }>(`${base}/connect`, { intent: snapshot.value!.connect_intent }, requestOptions);
        const url = new URL(response.data.authorization_url);
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
        const response = await axios.get<{ coverage: string; accounts: Account[]; continuation: string | null }>(`${base}/discovery/${encodeURIComponent(flow)}`, requestOptions);
        if (!alive) return;
        const known = new Set(accounts.value.map(account => account.selection_handle));
        for (const account of response.data.accounts) {
            if (handlePattern.test(account.selection_handle) && !known.has(account.selection_handle)) {
                known.add(account.selection_handle);
                accounts.value.push(account);
            }
        }
        coverage.value = response.data.coverage;
        continuation.value = response.data.continuation && handlePattern.test(response.data.continuation) ? response.data.continuation : null;
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
        const response = await axios.post<{ source_id: string; access: Access }>(`${base}/select`, { selection: handle }, requestOptions);
        if (!alive) return;
        selectionRetry.value = null;
        const selected = accounts.value.find(account => account.selection_handle === handle);
        const existing = snapshot.value?.connections.find(source => source.source_id === response.data.source_id);
        if (existing) existing.access = response.data.access;
        else if (snapshot.value && selected) snapshot.value.connections.push({ source_id: response.data.source_id, label: selected.existing_source_label || selected.label, declared_account_id: selected.account_id, registration: 'UNVERIFIED', access: response.data.access });
        accounts.value = [];
        continuation.value = null;
        discoveryRetry.value = null;
        discoveryStarted.value = false;
        announcement.value = 'تم حفظ ربط الحساب. تُعرض جاهزية البيانات بشكل مستقل أدناه.';
        if (!await fetchSnapshot() && alive) error.value = 'تم حفظ الربط، لكن تعذّر تحديث القائمة. حدّث الحالة؛ لا تحتاج إلى إعادة اختيار الحساب.';
    } catch (failure) {
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
        const response = await axios.post<{ access?: Access }>(`${base}/${encodeURIComponent(action.source.source_id)}/disconnect`, { command_id: action.command, expected_version: action.expectedVersion }, requestOptions);
        if (!alive) return;
        disconnect.value = null;
        const current = snapshot.value?.connections.find(source => source.source_id === action.source.source_id);
        if (current) current.access = response.data.access ?? { ...current.access, connection: 'LOCALLY_DISCONNECTED', effective: { state: 'REVOKED' } };
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
    if (fragment.startsWith('#meta-flow=') || fragment.startsWith('#meta-result=')) window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
    busy.value = 'refresh';
    await fetchSnapshot();
    if (!alive) return;
    busy.value = '';
    if (cancelled) announcement.value = 'لم يكتمل التفويض في Meta. يمكنك بدء الربط مجددًا عندما تكون جاهزًا.';
    if (flowMatch) await discover(flowMatch[1]);
});
onBeforeUnmount(() => { alive = false; abort.abort(); });
</script>

<template>
    <section dir="rtl" class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="meta-connection-heading" :aria-busy="!!busy">
        <header class="flex flex-col gap-5 border-b border-slate-200 p-6 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <div class="flex items-center gap-4">
                <ProviderBrand provider="meta" :size="48" />
                <div><p class="text-xs font-semibold tracking-wide text-indigo-700">حسابات الإعلانات · Meta</p><h2 id="meta-connection-heading" class="mt-1 text-xl font-semibold text-slate-900">ربط حسابك الإعلاني</h2></div>
            </div>
            <button type="button" :disabled="!!busy" class="self-start rounded-lg px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 sm:self-auto" @click="refresh">{{ busy === 'refresh' ? 'جارٍ التحديث…' : 'تحديث الحالة' }}</button>
        </header>
        <div class="p-6 sm:p-8">
            <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
            <p v-if="announcement" class="mb-4 border-r-2 border-indigo-500 bg-indigo-50 px-4 py-3 text-sm leading-6 text-indigo-900" aria-hidden="true">{{ announcement }}</p>
            <p v-if="error" role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800">{{ error }}</p>
            <div v-if="!snapshot" class="py-3 text-sm leading-7 text-slate-600">{{ busy ? 'جارٍ قراءة حالة الربط…' : 'لم نتمكن من قراءة حالة الربط. استخدم تحديث الحالة لإعادة المحاولة.' }}</div>
            <template v-else>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="max-w-xl"><p class="text-sm leading-7 text-slate-700">{{ availabilityText }}</p><p v-if="snapshot.connections.length && canConnect" class="mt-1 text-xs leading-6 text-slate-600">لإعادة الربط، أكمل التفويض ثم اختر الحساب المسجّل نفسه من قائمة الحسابات.</p></div>
                    <button type="button" :disabled="!!busy || !canConnect" class="shrink-0 self-start rounded-lg bg-indigo-700 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-600" @click="connect">{{ busy === 'connect' ? 'جارٍ الانتقال إلى Facebook…' : snapshot.connections.length ? 'ربط حساب أو إعادة ربطه' : 'الربط عبر Facebook' }}</button>
                </div>
                <div v-if="safeLink(snapshot.notice?.privacy_url) || safeLink(snapshot.notice?.deletion_instructions_url)" class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-600">
                    <a v-if="safeLink(snapshot.notice?.privacy_url)" :href="safeLink(snapshot.notice?.privacy_url)!" target="_blank" rel="noopener noreferrer" class="underline underline-offset-4 hover:text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">معلومات الخصوصية</a>
                    <a v-if="safeLink(snapshot.notice?.deletion_instructions_url)" :href="safeLink(snapshot.notice?.deletion_instructions_url)!" target="_blank" rel="noopener noreferrer" class="underline underline-offset-4 hover:text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">تعليمات طلب حذف البيانات</a>
                </div>
                <div v-if="discoveryStarted" class="mt-6 border-t border-slate-200 pt-6" aria-labelledby="meta-account-choice-heading">
                    <h3 id="meta-account-choice-heading" class="font-semibold text-slate-900">اختر الحساب لهذه المساحة</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ coverage === 'COMPLETE' ? 'اكتملت قائمة الحسابات المتاحة لهذا التفويض.' : 'عرض الحسابات لم يكتمل بعد. الحسابات الظاهرة لا تمثل بالضرورة جميع حساباتك.' }}</p>
                    <ul class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                        <li v-for="account in accounts" :key="account.selection_handle" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0"><p class="break-words font-semibold text-slate-900">{{ account.label }}</p><p class="mt-1 text-xs text-slate-600">معرّف الحساب: <bdi>{{ account.account_id }}</bdi></p><p v-if="account.existing_source_label" class="mt-2 text-sm text-indigo-800">مسجّل باسم «{{ account.existing_source_label }}» — سيُعاد ربط السجل نفسه.</p></div>
                            <button type="button" :disabled="!!busy || !!disconnect || !canManage || (!!selectionRetry && selectionRetry !== account.selection_handle)" class="shrink-0 self-start rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50" @click="selectAccount(account.selection_handle)">{{ busy === `select:${account.selection_handle}` ? 'جارٍ حفظ الربط…' : selectionRetry === account.selection_handle ? 'إعادة تأكيد اختيار الحساب' : account.existing_source_label ? 'إعادة ربط هذا الحساب' : 'ربط هذا الحساب' }}</button>
                        </li>
                        <li v-if="!accounts.length" class="py-5 text-sm leading-7 text-slate-600">{{ busy === 'discovery' ? 'جارٍ تحميل الحسابات…' : 'لم تظهر حسابات متاحة في هذه الصفحة.' }}</li>
                    </ul>
                    <p v-if="selectionRetry" class="mt-3 text-sm leading-6 text-slate-700">لم تُؤكّد نتيجة الاختيار بعد. أعد تأكيد الحساب نفسه، أو حدّث الحالة للاطلاع على الربط المحفوظ.</p>
                    <button v-if="discoveryRetry || continuation" type="button" :disabled="!!busy || !!selectionRetry" class="mt-4 rounded-lg px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50" @click="discover((discoveryRetry || continuation)!)">{{ busy === 'discovery' ? 'جارٍ التحميل…' : discoveryRetry ? 'إعادة تحميل الحسابات' : 'عرض المزيد من الحسابات' }}</button>
                </div>
                <div class="mt-6 border-t border-slate-200 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">حسابات هذه المساحة <span class="mr-2 text-slate-500">{{ snapshot.connections.length }}</span></h3>
                    <p v-if="!snapshot.connections.length" class="mt-3 text-sm leading-7 text-slate-600">لا توجد حسابات Meta ظاهرة لك في هذه المساحة حتى الآن.</p>
                    <ul v-else class="mt-2 divide-y divide-slate-200">
                        <li v-for="source in snapshot.connections" :key="source.source_id" class="py-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0"><h4 class="break-words font-semibold text-slate-900">{{ source.label }}</h4><p class="mt-1 text-xs text-slate-600">معرّف الحساب: <bdi>{{ source.declared_account_id }}</bdi></p></div>
                                <span class="self-start rounded-md px-2.5 py-1 text-xs font-semibold" :class="source.access.connection === 'CONNECTED' ? 'bg-indigo-50 text-indigo-800' : source.access.connection === 'RECONNECT_REQUIRED' ? 'bg-amber-50 text-amber-900' : 'bg-slate-100 text-slate-700'">{{ connectionLabel(source.access) }}</span>
                            </div>
                            <p class="mt-3 text-sm leading-7 text-slate-600">{{ readiness(source.access) }}</p>
                            <ProviderReportRequest :workspace-id="workspaceId" :source-id="source.source_id" :version="source.access.version" provider="meta"
                                :allowed="source.access.connection === 'CONNECTED' && ['provider_support', 'provider_permission', 'credential', 'privacy_purpose'].every(axis => source.access.axes[axis] === 'VERIFIED')" />
                            <button v-if="canManage && source.access.version > 0 && source.access.connection !== 'LOCALLY_DISCONNECTED'" type="button" :disabled="!!busy || !!disconnect || !!selectionRetry" class="mt-3 rounded px-1 py-1 text-xs font-semibold text-red-700 underline underline-offset-4 hover:text-red-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-700 disabled:opacity-50" @click="prepareDisconnect(source)">فصل الوصول المحلي</button>
                        </li>
                    </ul>
                </div>
                <section v-if="disconnect" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-5" aria-labelledby="meta-disconnect-heading">
                    <h3 id="meta-disconnect-heading" class="font-semibold text-slate-900">فصل الوصول المحلي إلى «{{ disconnect.source.label }}»؟</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-800">يوقف هذا الإجراء وصول Builderad المحلي لهذا الحساب. لا يسحب التفويض من Meta ولا يحذف الملاحظات التاريخية.</p>
                    <div class="mt-4 flex flex-wrap gap-3"><button type="button" :disabled="!!busy" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-50" @click="confirmDisconnect">{{ busy === 'disconnect' ? 'جارٍ الفصل…' : 'تأكيد الفصل المحلي' }}</button><button type="button" :disabled="!!busy" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-700 disabled:opacity-50" @click="disconnect = null">إلغاء</button></div>
                </section>
            </template>
        </div>
    </section>
</template>
