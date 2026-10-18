<script setup lang="ts">
import { providers } from './api';
import { submit, commandsFor } from './commands';
import { TransportError } from '../spa/transport';
import type { ProviderSnapshot } from './providers';
import PendingCommands from './PendingCommands.vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import { useProviderPresentation, type ProviderSummary } from '@/Components/Business/providerPresentation';
import ProviderReportRequest from './ProviderReportRequest.vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type Access = { reporting?: { can_report: boolean; why_not: string | null }; version: number; connection: string; effective: { state: string }; axes?: Record<string, string> };
type Connection = { source_id: string; label: string; declared_account_id: string; access: Access };
type Snapshot = ProviderSnapshot;
type Account = { account_id: string; label: string; existing_source_label: string | null; selection_handle: string | null; hierarchy_handle: string | null; manager: boolean | null };
type Page = { accounts: Account[]; continuation: string | null; coverage: string };
const props = defineProps<{ workspaceId: string; canManage: boolean }>();
const emit = defineEmits<{ summary: [summary: ProviderSummary] }>();
const snapshot = ref<Snapshot | null>(null);
const accounts = ref<Account[]>([]);
const next = ref<string | null>(null);
const busy = ref(false);
const error = ref('');
useProviderPresentation(snapshot, busy, error, emit);
const result = ref('');
const offlineGrantMissing = ref(false);
const currentPage = ref<string | null>(null);
type PageKind = 'discovery' | 'hierarchy';
const pageKind = ref<PageKind>('discovery');
const history = ref<{ handle: string; kind: PageKind }[]>([]);
const disconnectTarget = ref<string | null>(null);
const disconnectCommands = new Map<string, string>();
const cancellation = new AbortController();
let active = true;

function connectionLabel(state: string) {
    return ({ CONNECTED: 'ربط محفوظ؛ جاهزية التحليل تحتاج تحققًا مستقلًا', NOT_CONNECTED: 'لم يُربط بعد',
        LOCALLY_DISCONNECTED: 'مفصول محليًا', TOKEN_REFRESH_REQUIRED: 'انتهى رمز الوصول؛ يحتاج تجديدًا',
        VERIFICATION_NEEDED: 'تحتاج صلاحية الربط إلى تحقق' } as Record<string, string>)[state] ?? 'حالة غير متحققة';
}

async function refreshSnapshot(passive = true) {
    const response = await providers.snapshot('google', props.workspaceId, cancellation.signal, passive);
    if (active) snapshot.value = response;
}

async function refresh(passive = false) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try { await refreshSnapshot(passive); } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
}

async function disconnect(connection: Connection) {
    if (busy.value || !props.canManage || disconnectTarget.value !== connection.source_id) return;
    busy.value = true;
    error.value = '';
    try {
        await submit({ workspace: props.workspaceId, effect: 'disconnect', provider: 'google', source: connection.source_id, body: { command_id: crypto.randomUUID(), expected_version: connection.access.version } }, cancellation.signal);
        if (!active) return;
        await refreshSnapshot(true);
        disconnectTarget.value = null;
        result.value = 'تم فصل هذا الحساب في Builderad وإزالة بيانات تفويضه المحلية. تفويض Google نفسه لم يُلغَ؛ قد تستخدمه حسابات أخرى.';
    } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
}

async function renew(connection: Connection) {
    if (busy.value || !props.canManage) return;
    busy.value = true;
    error.value = '';
    try {
        await submit({ workspace: props.workspaceId, effect: 'renew', provider: 'google', source: connection.source_id, body: { command_id: crypto.randomUUID(), expected_version: connection.access.version } }, cancellation.signal);
        if (!active) return;
        await refreshSnapshot(true);
        result.value = 'تم تجديد الربط والتحقق من حساب Google نفسه. جاهزية التحليل تحتاج تحققًا مستقلًا.';
    } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
}

function failed(failure: unknown) {
    if (!active || (failure instanceof Error && failure.name === 'AbortError')) return;
    const status = failure instanceof TransportError ? failure.status : null;
    const reason = failure instanceof TransportError ? ('reason' in failure.body ? failure.body.reason : null) : null;
    offlineGrantMissing.value = reason === 'google_offline_access_missing';
    error.value = reason === 'google_offline_access_missing'
        ? 'لم يمنح Google صلاحية الربط المستمر. استخدم إعادة طلب التفويض.'
        : reason === 'google_reconnect_required' ? 'لم يعد تفويض التجديد صالحًا. ابدأ ربط الحساب عبر Google مجددًا.'
        : reason === 'google_manager_is_not_report_account'
            ? 'هذا حساب مدير. استخدم عرض الحسابات التابعة لاختيار حساب معلن.'
            : reason === 'google_manager_context_unverified' ? 'لم يتحقق Google من أن هذا الحساب مدير متاح لك. يمكنك الرجوع واختيار حساب آخر.'
            : status === 403 ? 'انتهت صلاحية هذه الخطوة أو لا تملك الإذن الحالي. حدّث الصفحة وسجّل الدخول مجددًا عند الحاجة.'
                : status === 503 ? 'إعداد ربط Google غير مكتمل. راجع مسؤول الخدمة.'
                    : status === 409 ? 'تغيّرت حالة الحساب أثناء العملية. حدّث الصفحة قبل إعادة المحاولة.'
                        : 'تعذّر إكمال العملية. حاول مرة أخرى؛ لم تُثبت جاهزية بيانات الحساب للتحليل.';
}

async function loadAccounts(handle: string, kind: PageKind = 'discovery', remember = false) {
    if (busy.value || !/^[A-Za-z0-9_-]{43}$/.test(handle)) return;
    if (!currentPage.value) {
        currentPage.value = handle;
        pageKind.value = kind;
    }
    busy.value = true;
    error.value = '';
    try {
        const response = await providers.googlePage(props.workspaceId, kind, handle, cancellation.signal);
        if (!active) return;
        if (remember && currentPage.value) history.value.push({ handle: currentPage.value, kind: pageKind.value });
        currentPage.value = handle;
        pageKind.value = kind;
        accounts.value = response.accounts;
        next.value = response.continuation;
    } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
}

async function back() {
    const previous = history.value.at(-1);
    if (!previous || busy.value) return;
    await loadAccounts(previous.handle, previous.kind);
    if (!error.value) history.value.pop();
}

async function connect(recover = false) {
    if (!snapshot.value?.connect_intent || busy.value || !props.canManage) return;
    busy.value = true;
    error.value = '';
    try {
        const response = await providers.connectGoogle(props.workspaceId, { intent: snapshot.value.connect_intent, recover_offline_grant: recover }, cancellation.signal);
        if (!active) return;
        const url = new URL(response.authorization_url);
        if (url.origin !== 'https://accounts.google.com' || url.pathname !== '/o/oauth2/v2/auth') throw new Error('Invalid redirect');
        window.location.assign(url.href);
    } catch (failure) { failed(failure); if (active) busy.value = false; }
}

async function select(account: Account) {
    if (busy.value || !props.canManage || !account.selection_handle) return;
    busy.value = true;
    error.value = '';
    try {
        await submit({ workspace: props.workspaceId, effect: 'select', provider: 'google', body: { selection: account.selection_handle } }, cancellation.signal);
        if (!active) return;
        result.value = `تم حفظ ربط الحساب ${account.account_id}. جاهزية بياناته للتحليل تحتاج تحققًا مستقلًا.`;
        accounts.value = [];
        next.value = null;
        currentPage.value = null;
        history.value = [];
        await refreshSnapshot(true);
    } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
}

onMounted(async () => {
    busy.value = true;
    try {
        await refreshSnapshot(true);
    } catch (failure) { failed(failure); }
    finally { if (active) busy.value = false; }
    if (!active) return;
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const handle = fragment.get('google-flow');
    const status = fragment.get('google-result');
    if (handle || fragment.has('google-result')) {
        fragment.delete('google-flow');
        fragment.delete('google-result');
        const remaining = fragment.toString();
        if (handle) await loadAccounts(handle);
        else if (status === 'OFFLINE_GRANT_REQUIRED') {
            offlineGrantMissing.value = true;
            error.value = 'لم يمنح Google صلاحية الربط المستمر. استخدم إعادة طلب التفويض.';
        } else if (status === 'PROVIDER_ACTION_REQUIRED') {
            error.value = 'تعذّر إكمال الربط لدى Google. يمكنك بدء الربط من جديد.';
        } else result.value = 'لم يكتمل تفويض Google. يمكنك بدء الربط من جديد.';
    }
});
onBeforeUnmount(() => { active = false; cancellation.abort(); });
</script>

<template>
    <section dir="rtl" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="google-connection-heading" :aria-busy="busy">
        <PendingCommands :workspace-id="workspaceId" provider="google" @settled="refresh()" />
        <fieldset class="min-w-0 border-0 p-0" :disabled="commandsFor(workspaceId, 'google').length > 0">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="google-connection-heading" class="text-xl font-semibold text-slate-900 flex items-center gap-3"><ProviderBrand provider="google" :size="40" />ربط Google Ads</h2>
                <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-600">اربط عبر صفحة Google الرسمية، ثم اختر الحساب الذي تريد استخدام بياناته في هذه المساحة.</p>
            </div>
            <button v-if="snapshot?.availability === 'CONNECT_AVAILABLE' && canManage" type="button" :disabled="busy" data-source-connect="google" @click="connect()"
                class="rounded-lg bg-indigo-700 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">
                {{ busy ? 'جارٍ إكمال الخطوة…' : 'ربط حساب Google Ads' }}
            </button>
        </div>
        <p v-if="snapshot?.availability === 'CONFIGURATION_REQUIRED'" class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">إعداد ربط Google غير مكتمل. يتطلب إعداد التطبيق وعنوان العودة وإشعار الخصوصية قبل إتاحة الربط.</p>
        <p v-else-if="snapshot?.availability === 'OWNER_REQUIRED' || (snapshot && !canManage)" class="mt-4 text-sm text-slate-600">بدء الربط واختيار الحساب متاحان للمسؤول الحالي عن مساحة العمل.</p>
        <p v-else-if="snapshot?.availability === 'FRESH_AUTHENTICATION_REQUIRED'" class="mt-4 text-sm text-slate-600">أكّد تسجيل دخولك ثم حدّث الصفحة لإتاحة الربط.</p>
        <div v-if="snapshot?.notice" class="mt-4 text-sm leading-7 text-slate-600">
            <a :href="snapshot.notice.privacy_url" target="_blank" rel="noopener noreferrer" class="font-medium text-indigo-700 underline">اطّلع على سياسة الخصوصية قبل الربط</a>
            <p>يطلب Google تفويض Ads الذي يشمل قدرات أوسع من القراءة. يستخدم Builderad هنا طلبات قراءة التقارير فقط. نجاح التفويض لا يثبت موافقة المنصة أو جاهزية بياناتك للتحليل.</p>
        </div>
        <p v-if="error" role="alert" class="mt-4 text-sm leading-7 text-red-700">{{ error }}</p>
        <button v-if="offlineGrantMissing && snapshot?.connect_intent && canManage" type="button" :disabled="busy" data-source-connect="recover" @click="connect(true)" class="mt-3 text-sm font-medium text-indigo-700 underline disabled:opacity-50">إعادة طلب تفويض الربط المستمر</button>
        <p v-if="result" role="status" class="mt-4 rounded-lg bg-slate-50 p-4 text-sm leading-7 text-slate-700">{{ result }}</p>
        <div v-if="snapshot?.connections.length" class="mt-6 border-t border-slate-100 pt-5">
            <h3 class="font-semibold text-slate-900">الحسابات المسجلة في هذه المساحة</h3>
            <button type="button" :disabled="busy" @click="refresh()" class="mt-3 text-sm font-medium text-indigo-700 underline disabled:opacity-50">تحديث حالة الربط</button>
            <ul class="mt-3 divide-y divide-slate-100">
                <li v-for="connection in snapshot.connections" :key="connection.source_id" class="py-4">
                    <p class="font-medium text-slate-900">{{ connection.label }}</p>
                    <p dir="ltr" class="mt-1 text-xs text-slate-500">{{ connection.declared_account_id }}</p>
                    <p class="mt-2 text-sm text-slate-600">{{ connectionLabel(connection.access.connection) }}</p>
                    <ProviderReportRequest provider="google" :workspace-id="workspaceId" :source-id="connection.source_id" :version="connection.access.version"
                        :allowed="canManage && connection.access.reporting?.can_report === true" />
                    <button v-if="canManage && snapshot.availability === 'CONNECT_AVAILABLE' && connection.access.version > 0 && connection.access.connection !== 'LOCALLY_DISCONNECTED'" type="button" :disabled="busy" data-source-renew @click="renew(connection)" class="mt-3 me-4 text-sm font-medium text-indigo-700 underline disabled:opacity-50">تجديد الربط والتحقق من الحساب</button>
                    <button v-if="canManage && connection.access.version > 0 && connection.access.connection !== 'LOCALLY_DISCONNECTED' && disconnectTarget !== connection.source_id" type="button" :disabled="busy" data-source-disconnect @click="disconnectTarget = connection.source_id" class="mt-3 text-sm font-medium text-red-700 underline disabled:opacity-50">فصل الحساب محليًا</button>
                    <div v-if="disconnectTarget === connection.source_id" class="mt-3 rounded-lg bg-slate-50 p-4 text-sm leading-7 text-slate-700">
                        <p>سيُفصل هذا المصدر وتُزال بيانات تفويضه المحلية. يبقى تفويض Google والربط بحسابات أخرى كما هما.</p>
                        <button type="button" :disabled="busy" data-source-confirm-disconnect @click="disconnect(connection)" class="mt-3 me-4 font-semibold text-red-700 underline disabled:opacity-50">تأكيد الفصل المحلي</button>
                        <button type="button" :disabled="busy" @click="disconnectTarget = null" class="mt-3 font-medium text-slate-600 underline disabled:opacity-50">إلغاء</button>
                    </div>
                </li>
            </ul>
        </div>
        <div v-if="currentPage" class="mt-6 border-t border-slate-100 pt-5">
            <h3 class="font-semibold text-slate-900">اختر الحساب لهذه المساحة</h3>
            <button v-if="history.length" type="button" :disabled="busy" @click="back()" class="mt-3 me-4 text-sm font-medium text-indigo-700 underline disabled:opacity-50">الرجوع إلى الحسابات السابقة</button>
            <button v-if="error" type="button" :disabled="busy" @click="loadAccounts(currentPage, pageKind)" class="mt-3 text-sm font-medium text-indigo-700 underline disabled:opacity-50">إعادة تحميل الحسابات</button>
            <p v-if="!busy && !accounts.length && !error" class="mt-3 text-sm text-slate-600">لا توجد حسابات متاحة في هذه الصفحة.</p>
            <ul class="mt-3 divide-y divide-slate-100">
                <li v-for="account in accounts" :key="account.selection_handle ?? account.hierarchy_handle ?? account.account_id" class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div><p class="font-medium text-slate-900">{{ account.label }}</p><p dir="ltr" class="mt-1 text-xs text-slate-500">{{ account.account_id }}</p><p v-if="account.existing_source_label" class="mt-1 text-sm text-slate-600">مصدر موجود: {{ account.existing_source_label }}</p></div>
                    <div class="flex flex-wrap gap-3">
                        <button v-if="account.hierarchy_handle" type="button" :disabled="busy || !canManage" data-source-hierarchy @click="loadAccounts(account.hierarchy_handle, 'hierarchy', true)" class="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">{{ account.manager === true ? 'عرض الحسابات التابعة' : 'عرض الحسابات التابعة إن كان مديرًا' }}</button>
                        <button v-if="account.selection_handle" type="button" :disabled="busy || !canManage" data-source-select @click="select(account)" class="rounded-lg border border-indigo-200 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">اختيار الحساب</button>
                        <p v-if="account.manager === true && !account.hierarchy_handle" class="text-sm text-slate-600">تعذّر متابعة هذا المسار. ابدأ من حساب مدير أقرب إلى حساب المعلن.</p>
                    </div>
                </li>
            </ul>
            <button v-if="next" type="button" :disabled="busy" @click="loadAccounts(next, pageKind, true)" class="mt-3 text-sm font-medium text-indigo-700 underline disabled:opacity-50">الحسابات التالية</button>
        </div>
    </fieldset>
    </section>
</template>
