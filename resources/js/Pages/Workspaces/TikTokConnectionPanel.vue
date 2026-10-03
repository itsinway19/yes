<script setup lang="ts">
import axios from 'axios';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import { useProviderPresentation, type ProviderSummary } from '@/Components/Business/providerPresentation';
import ProviderReportRequest from './ProviderReportRequest.vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type Access = { version: number; connection: string; axes?: Record<string, string> };
type Connection = { source_id: string; label: string; declared_account_id: string; access: Access };
type Snapshot = { availability: string; connections: Connection[]; connect_intent: string | null; notice: { privacy_url: string } | null };
type Account = { account_id: string; label: string; selection_handle: string };
const props = defineProps<{ workspaceId: string; canManage: boolean }>();
const emit = defineEmits<{ summary: [summary: ProviderSummary] }>();
const snapshot = ref<Snapshot | null>(null);
const accounts = ref<Account[]>([]);
const current = ref<string | null>(null);
const next = ref<string | null>(null);
const busy = ref(false);
const error = ref('');
useProviderPresentation(snapshot, busy, error, emit);
const result = ref('');
const confirming = ref<string | null>(null);
const commands = new Map<string, string>();
const base = `/workspaces/${encodeURIComponent(props.workspaceId)}/providers/tiktok`;
const cancellation = new AbortController();
let active = true;

function fail(failure: unknown) {
    if (!active || axios.isCancel(failure)) return;
    const status = axios.isAxiosError(failure) ? failure.response?.status : null;
    error.value = status === 403 ? 'انتهى الإذن لهذه الخطوة. حدّث الصفحة وأكّد تسجيل دخولك عند الحاجة.'
        : status === 503 ? 'إعداد ربط TikTok غير مكتمل. راجع مسؤول الخدمة.'
            : status === 409 ? 'تغيّرت حالة الربط. حدّث الحالة قبل إعادة المحاولة.'
                : 'تعذّر إكمال العملية. يمكنك إعادة المحاولة أو بدء التفويض مجددًا.';
}

async function loadSnapshot() {
    const response = await axios.get<Snapshot>(base, { signal: cancellation.signal });
    if (active) snapshot.value = response.data;
}

async function refresh() {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try { await loadSnapshot(); } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

async function loadAccounts(handle: string) {
    if (busy.value || !/^[A-Za-z0-9_-]{43}$/.test(handle)) return;
    busy.value = true; error.value = ''; current.value = handle;
    try {
        const response = await axios.get<{ accounts: Account[]; continuation: string | null }>(`${base}/discovery/${encodeURIComponent(handle)}`, { signal: cancellation.signal });
        if (active) { accounts.value = response.data.accounts; next.value = response.data.continuation; }
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

async function connect() {
    if (busy.value || !props.canManage || !snapshot.value?.connect_intent) return;
    busy.value = true; error.value = '';
    try {
        const response = await axios.post<{ authorization_url: string }>(`${base}/connect`, { intent: snapshot.value.connect_intent }, { signal: cancellation.signal });
        if (!active) return;
        const url = new URL(response.data.authorization_url);
        if (url.origin !== 'https://business-api.tiktok.com' || url.pathname !== '/portal/auth') throw new Error('Invalid authorization URL');
        window.location.assign(url.href);
    } catch (failure) { fail(failure); if (active) busy.value = false; }
}

async function select(account: Account) {
    if (busy.value || !props.canManage) return;
    busy.value = true; error.value = '';
    try {
        await axios.post(`${base}/select`, { selection: account.selection_handle }, { signal: cancellation.signal });
        if (!active) return;
        accounts.value = []; current.value = null; next.value = null;
        result.value = 'تم حفظ ربط حساب TikTok. جاهزية بياناته للتحليل تحتاج تحققًا مستقلًا.';
        await loadSnapshot();
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

async function disconnect(connection: Connection) {
    if (busy.value || !props.canManage || confirming.value !== connection.source_id) return;
    busy.value = true; error.value = '';
    const key = `${connection.source_id}:${connection.access.version}`;
    let command = commands.get(key);
    if (!command) { command = crypto.randomUUID(); commands.set(key, command); }
    try {
        const response = await axios.post<{ access: Access }>(`${base}/${encodeURIComponent(connection.source_id)}/disconnect`,
            { command_id: command, expected_version: connection.access.version }, { signal: cancellation.signal });
        if (!active) return;
        connection.access = response.data.access; confirming.value = null;
        result.value = 'تم فصل المصدر محليًا وإزالة بيانات تفويضه المحلية. تفويض TikTok نفسه لم يُلغَ.';
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

function stateLabel(state: string) {
    return ({ CONNECTED: 'ربط محفوظ؛ لا يثبت جاهزية التحليل', NOT_CONNECTED: 'لم يُربط بعد', LOCALLY_DISCONNECTED: 'مفصول محليًا',
        REAUTHORIZATION_REQUIRED: 'يحتاج تفويضًا جديدًا', VERIFICATION_NEEDED: 'صلاحية الربط تحتاج تحققًا' } as Record<string, string>)[state] ?? 'حالة غير متحققة';
}

onMounted(async () => {
    await refresh();
    if (!active) return;
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const handle = fragment.get('tiktok-flow');
    if (handle || fragment.has('tiktok-result')) {
        fragment.delete('tiktok-flow'); fragment.delete('tiktok-result');
        const remaining = fragment.toString();
        window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + (remaining ? `#${remaining}` : ''));
        if (handle) await loadAccounts(handle);
        else result.value = 'لم يكتمل تفويض TikTok. يمكنك بدء الربط مجددًا.';
    }
});
onBeforeUnmount(() => { active = false; cancellation.abort(); });
</script>

<template>
    <section dir="rtl" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="tiktok-connection-heading" :aria-busy="busy">
        <h2 id="tiktok-connection-heading" class="text-xl font-semibold text-slate-900 flex items-center gap-3"><ProviderBrand provider="tiktok" :size="40" />ربط TikTok Ads</h2>
        <p class="mt-2 text-sm leading-7 text-slate-600">ابدأ التفويض عبر منصة TikTok الرسمية، ثم اختر حساب المعلن لهذه المساحة.</p>
        <button v-if="canManage && snapshot?.availability === 'CONNECT_AVAILABLE'" type="button" :disabled="busy" @click="connect()" class="mt-4 rounded-lg bg-indigo-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">ربط حساب TikTok</button>
        <p v-if="snapshot?.availability === 'CONFIGURATION_REQUIRED'" class="mt-4 text-sm text-slate-600">إعداد التطبيق وعنوان التفويض والعودة وإشعار الخصوصية غير مكتمل.</p>
        <p v-else-if="snapshot?.availability === 'OWNER_REQUIRED' || (snapshot && !canManage)" class="mt-4 text-sm text-slate-600">بدء الربط واختيار الحساب متاحان للمسؤول الحالي عن المساحة.</p>
        <p v-else-if="snapshot?.availability === 'FRESH_AUTHENTICATION_REQUIRED'" class="mt-4 text-sm text-slate-600">أكّد تسجيل دخولك ثم حدّث الصفحة لإتاحة الربط.</p>
        <p v-if="snapshot?.notice" class="mt-4 text-sm leading-7 text-slate-600"><a :href="snapshot.notice.privacy_url" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline">اطّلع على سياسة الخصوصية قبل الربط</a>. يرسل هذا المسار قراءات الحساب والتقارير فقط؛ نجاح التفويض لا يثبت موافقة المنصة أو جاهزية التحليل.</p>
        <p v-if="error" role="alert" class="mt-4 text-sm text-red-700">{{ error }}</p>
        <p v-if="result" role="status" class="mt-4 text-sm leading-7 text-slate-700">{{ result }}</p>
        <div v-if="current" class="mt-5 border-t border-slate-100 pt-4">
            <h3 class="font-semibold text-slate-900">اختر حساب المعلن</h3>
            <button v-if="error" type="button" :disabled="busy" @click="loadAccounts(current)" class="mt-3 text-sm text-indigo-700 underline">إعادة تحميل الحسابات</button>
            <p v-if="!busy && !error && !accounts.length" class="mt-3 text-sm text-slate-600">لا توجد حسابات متاحة في هذه الصفحة.</p>
            <ul class="mt-3 divide-y divide-slate-100"><li v-for="account in accounts" :key="account.selection_handle" class="flex flex-wrap items-center justify-between gap-4 py-4"><div><p class="font-medium">{{ account.label }}</p><p dir="ltr" class="text-xs text-slate-500">{{ account.account_id }}</p></div><button type="button" :disabled="busy || !canManage" @click="select(account)" class="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700 disabled:opacity-50">اختيار الحساب</button></li></ul>
            <button v-if="next" type="button" :disabled="busy" @click="loadAccounts(next)" class="mt-3 text-sm text-indigo-700 underline">الحسابات التالية</button>
        </div>
        <div v-if="snapshot?.connections.length" class="mt-5 border-t border-slate-100 pt-4">
            <h3 class="font-semibold text-slate-900">الحسابات المسجلة</h3><button type="button" :disabled="busy" @click="refresh()" class="mt-3 text-sm text-indigo-700 underline">تحديث حالة الربط</button>
            <ul class="mt-3 divide-y divide-slate-100">
                <li v-for="connection in snapshot.connections" :key="connection.source_id" class="py-4">
                    <p class="font-medium">{{ connection.label }}</p><p dir="ltr" class="text-xs text-slate-500">{{ connection.declared_account_id }}</p>
                    <p class="mt-2 text-sm text-slate-600">{{ stateLabel(connection.access.connection) }}</p>
                    <ProviderReportRequest provider="tiktok" :workspace-id="workspaceId" :source-id="connection.source_id" :version="connection.access.version"
                        :allowed="canManage && connection.access.connection === 'CONNECTED' && ['provider_support', 'provider_permission', 'credential', 'privacy_purpose'].every(axis => connection.access.axes?.[axis] === 'VERIFIED')" />
                    <button v-if="canManage && connection.access.version > 0 && connection.access.connection !== 'LOCALLY_DISCONNECTED'" type="button" :disabled="busy" @click="confirming = connection.source_id" class="mt-3 text-sm text-red-700 underline">فصل المصدر محليًا</button>
                    <div v-if="confirming === connection.source_id" class="mt-3 rounded-lg bg-slate-50 p-4 text-sm leading-7"><p>سيُفصل هذا المصدر وتُزال بيانات تفويضه المحلية. لا يُلغى تفويض TikTok ولا الربط بالمصادر الأخرى.</p><button type="button" :disabled="busy" @click="disconnect(connection)" class="me-4 text-red-700 underline">تأكيد الفصل المحلي</button><button type="button" :disabled="busy" @click="confirming = null" class="text-slate-600 underline">إلغاء</button></div>
                </li>
            </ul>
        </div>
    </section>
</template>
