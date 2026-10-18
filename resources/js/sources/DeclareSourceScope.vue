<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { sources } from './api';
import { read } from './state';
import { bootstrap, status, TransportError } from '../spa/transport';

type Scope = { resource: string; operation: string; purpose: string };
type Command = { command_id: string; source_id: string; effect: 'declare_scope'; expected_version: number;
    resource: string; operation: string; purpose: string; reason: 'owner_scope_declaration' };
type Pending = { context: string; payload: string };
const prefix = 'builderad.bb1.declare_scope.pending.v1:';
const props = defineProps<{ workspaceId: string; sourceId: string; version: number; choices: Scope[] }>();

const selected = ref('');
const busy = ref(false);
const commandId = ref(crypto.randomUUID());
const errors = ref<Record<string, string>>({});
const message = ref('');
const needsRefresh = ref(false);
const context = ref('');
const pending = ref<Pending | null>(null);
const pendingCommand = computed<Command | null>(() => pending.value ? JSON.parse(pending.value.payload) as Command : null);
const storageReady = ref(false);
const cancellation = new AbortController();
let active = true;
onBeforeUnmount(() => { active = false; cancellation.abort(); });

function currentIdentity(): { user: number; session: string } | null {
    const binding = bootstrap.identity.binding;
    return status.value !== 'closed' && Number.isSafeInteger(binding.principal) && /^[0-9a-f]{64}$/.test(binding.context)
        ? { user: binding.principal, session: binding.context } : null;
}

function currentKey(): string | null {
    const identity = currentIdentity();
    return identity ? `${prefix}${identity.user}:${identity.session}:` + encodeURIComponent(props.workspaceId)
        + ':' + encodeURIComponent(props.sourceId) : null;
}

function restore() {
    pending.value = null;
    context.value = '';
    storageReady.value = false;
    const identity = currentIdentity();
    if (!identity) {
        message.value = 'تعذّر التحقق من سياق الجلسة الحالي. أعد تحميل الصفحة قبل إعلان نطاق.';
        return;
    }
    const ownerPrefix = `${prefix}${identity.user}:${identity.session}:`;
    const key = currentKey()!;
    try {
        const storage = globalThis.sessionStorage;
        // A different login or session cannot restore this tab's earlier command.
        for (const candidate of Array.from({ length: storage.length }, (_, i) => storage.key(i))) {
            if (candidate?.startsWith(prefix) && !candidate.startsWith(ownerPrefix)) storage.removeItem(candidate);
        }
        const saved = storage.getItem(key);
        if (saved) {
            try {
                const record = JSON.parse(saved) as Pending;
                const command = JSON.parse(record.payload) as Command;
                if (record.context === key && command.effect === 'declare_scope' && command.source_id === props.sourceId
                    && typeof command.command_id === 'string' && command.resource && command.operation && command.purpose
                    && Number.isSafeInteger(command.expected_version)) {
                    pending.value = record;
                    message.value = 'نتيجة الطلب السابق غير مؤكدة. أعد إرسال الطلب الأصلي نفسه لحسمها.';
                } else storage.removeItem(key);
            } catch { storage.removeItem(key); }
        }
        context.value = key;
        storageReady.value = true;
    } catch {
        message.value = 'تعذّر حفظ هوية الطلب في هذه الجلسة. فعّل تخزين الجلسة وأعد تحميل الصفحة.';
    }
}
onMounted(restore);
watch(() => [currentIdentity()?.user, currentIdentity()?.session, props.workspaceId, props.sourceId], restore);
watch(() => [props.version, JSON.stringify(props.choices)], () => {
    if (!pending.value) {
        selected.value = '';
        commandId.value = crypto.randomUUID();
        errors.value = {};
        needsRefresh.value = false;
    }
});
watch(selected, () => {
    if (!pending.value) {
        commandId.value = crypto.randomUUID();
        errors.value = {};
        message.value = '';
    }
});

function refresh() {
    if (active) void read(props.workspaceId, true);
}
function clearPending(key: string): boolean {
    try {
        globalThis.sessionStorage.removeItem(key);
        if (context.value === key) pending.value = null;
        return true;
    } catch { return false; }
}
async function sendOriginal(record: Pending, wasUncertain: boolean) {
    if (busy.value || !storageReady.value || context.value !== record.context || currentKey() !== record.context) return;
    const command = JSON.parse(record.payload) as Command;
    const key = record.context;
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        await sources.command(props.workspaceId, command, cancellation.signal);
        if (!active) return;
        const cleared = clearPending(key);
        if (context.value === key) {
            commandId.value = crypto.randomUUID();
            needsRefresh.value = true;
            message.value = cleared
                ? 'تم إعلان النطاق محلياً. يظل غير متحقق؛ عرضه يتطلب صلاحية قراءة مطابقة.'
                : 'تم إعلان النطاق، لكن تعذّر إزالة سجل الطلب من هذه الجلسة. أعد تحميل الصفحة بعد تفعيل تخزين الجلسة.';
            refresh();
        }
    } catch (error) {
        if (!active) return;
        const status = error instanceof TransportError ? error.status : undefined;
        if (status === 422 || status === 409 || (!wasUncertain && [401, 403, 419].includes(status ?? 0))
            || (status !== undefined && status >= 400 && status < 500 && ![408, 419, 425, 429].includes(status)
                && !(wasUncertain && [401, 403].includes(status)))) {
            clearPending(key);
        }
        if (context.value !== key) return;
        if (status === 422) {
            const fields = error instanceof TransportError ? error.body.errors ?? {} : {};
            errors.value = Object.fromEntries(Object.entries(fields).map(([field, value]) =>
                [field, Array.isArray(value) ? String(value[0]) : String(value)]));
            if (!Object.keys(errors.value).length) errors.value = { scope: 'تعذر قبول هذا النطاق؛ راجع الاختيار والحالة الحالية.' };
        } else if (status === 409) {
            needsRefresh.value = true;
            message.value = 'تغير إصدار المصدر أو سبق إعلان النطاق. حدّث الحالة قبل المحاولة.';
        } else if (status === 401 || status === 403 || status === 419) {
            needsRefresh.value = true;
            message.value = wasUncertain
                ? 'تعذّر حسم الطلب الأصلي بسبب صلاحية الجلسة. جدّدها ثم أعد إرسال الطلب الأصلي إن بقيت الجلسة نفسها.'
                : 'لم تعد الصلاحية أو الجلسة صالحة. حدّث الحالة وتحقق من المصادقة قبل المحاولة.';
        } else if (pending.value) {
            message.value = 'لم يصل تأكيد التنفيذ. يبقى الطلب الأصلي محفوظاً؛ أعد إرساله لحسم النتيجة.';
        } else {
            message.value = 'رُفض الطلب. حدّث الحالة قبل تقديم طلب جديد.';
        }
    } finally {
        if (active) busy.value = false;
    }
}
async function declareScope() {
    if (busy.value || needsRefresh.value || pending.value || !storageReady.value || currentKey() !== context.value) return;
    const scope = props.choices[Number(selected.value)];
    if (selected.value === '' || !scope) {
        errors.value = { scope: 'اختر نطاقاً مصرحاً به من القائمة.' };
        return;
    }
    try {
        const payload = JSON.stringify({
            command_id: commandId.value, source_id: props.sourceId, effect: 'declare_scope',
            expected_version: props.version, ...scope, reason: 'owner_scope_declaration',
        } satisfies Command);
        const record = { context: context.value, payload };
        globalThis.sessionStorage.setItem(record.context, JSON.stringify(record));
        pending.value = record;
        await sendOriginal(record, false);
    } catch {
        message.value = 'تعذّر حفظ هوية الطلب. لم يُرسَل أي طلب؛ أعد تحميل الصفحة.';
    }
}
function retryOriginal() {
    if (pending.value && !busy.value) void sendOriginal(pending.value, true);
}
</script>

<template>
    <form v-if="choices.length || pending" class="mt-4 rounded-lg border border-slate-200 p-3" dir="rtl" :aria-busy="busy" @submit.prevent="declareScope">
        <h4 class="font-medium">إعلان نطاق إضافي للمصدر</h4>
        <p class="mt-1 text-sm text-slate-600">اختر المورد والعملية والغرض المصرح بها معاً. الإعلان لا يثبت إذن المزوّد أو جاهزية الاستخدام.</p>
        <div v-if="pending" class="mt-3 text-sm">
            <p>الطلب الأصلي غير محسوم:</p>
            <p dir="ltr" class="break-all">{{ pendingCommand?.resource }} / {{ pendingCommand?.operation }} / {{ pendingCommand?.purpose }} — v{{ pendingCommand?.expected_version }}</p>
            <button type="button" :disabled="busy" class="mt-2 text-indigo-700 underline disabled:opacity-50" @click="retryOriginal">{{ busy ? 'جارٍ حسم الطلب الأصلي…' : 'إعادة إرسال الطلب الأصلي' }}</button>
            <button type="button" :disabled="busy" class="ms-3 text-indigo-700 underline disabled:opacity-50" @click="refresh">تحديث الحالة من الخادم</button>
        </div>
        <label v-else class="mt-3 block">النطاق المصرح به
            <select v-model="selected" required :disabled="busy || needsRefresh" class="mt-1 w-full rounded border-slate-300" :aria-invalid="Object.keys(errors).length > 0">
                <option value="" disabled>اختر نطاقاً</option>
                <option v-for="(scope, index) in choices" :key="JSON.stringify(scope)" :value="String(index)" dir="ltr">{{ scope.resource }} / {{ scope.operation }} / {{ scope.purpose }}</option>
            </select>
        </label>
        <p v-for="(error, field) in errors" :key="field" role="alert" class="mt-1 text-sm text-red-700">{{ error }}</p>
        <p class="mt-2 text-xs">إصدار المصدر الحالي: {{ version }}</p>
        <button v-if="!pending" type="submit" :disabled="busy || needsRefresh || selected === '' || !storageReady" class="mt-3 rounded bg-indigo-700 px-4 py-2 text-white disabled:opacity-50">{{ busy ? 'جارٍ إعلان النطاق…' : 'تأكيد إعلان النطاق' }}</button>
        <p v-if="message" role="status" aria-live="polite" class="mt-2 text-sm">{{ message }}</p>
        <button v-if="needsRefresh && !pending" type="button" :disabled="busy" class="mt-2 text-sm text-indigo-700 underline" @click="refresh">تحديث الحالة من الخادم</button>
    </form>
</template>
