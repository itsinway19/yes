<script setup lang="ts">
import { ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

type Scope = { resource: string; operation: string; purpose: string };
const props = defineProps<{ workspaceId: string; sourceId: string; version: number; choices: Scope[] }>();
const selected = ref('');
const busy = ref(false);
const commandId = ref(crypto.randomUUID());
const errors = ref<Record<string, string>>({});
const message = ref('');
const needsRefresh = ref(false);
watch(() => [props.version, JSON.stringify(props.choices)], () => {
    selected.value = '';
    commandId.value = crypto.randomUUID();
    errors.value = {};
    needsRefresh.value = false;
});
watch(selected, () => { commandId.value = crypto.randomUUID(); errors.value = {}; message.value = ''; });

function refresh() {
    router.reload({ only: ['sources', 'canRequestSourceAuthority'] });
}
async function declareScope() {
    if (busy.value || needsRefresh.value) return;
    const scope = props.choices[Number(selected.value)];
    if (selected.value === '' || !scope) {
        errors.value = { scope: 'اختر نطاقاً مصرحاً به من القائمة.' };
        return;
    }
    busy.value = true;
    errors.value = {};
    message.value = '';
    try {
        await axios.post(route('workspaces.sources.command', props.workspaceId), {
            command_id: commandId.value, source_id: props.sourceId, effect: 'declare_scope',
            expected_version: props.version, ...scope, reason: 'owner_scope_declaration',
        }, { headers: { Accept: 'application/json' } });
        needsRefresh.value = true;
        message.value = 'تم إعلان النطاق محلياً. يظل غير متحقق؛ عرضه يتطلب صلاحية قراءة مطابقة.';
        refresh();
    } catch (error) {
        if (axios.isAxiosError(error)) {
            const status = error.response?.status;
            if (status === 422) {
                const fields = error.response?.data?.errors ?? {};
                errors.value = Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, Array.isArray(value) ? String(value[0]) : String(value)]));
                if (!Object.keys(errors.value).length) errors.value = { scope: 'تعذر قبول هذا النطاق؛ راجع الاختيار والحالة الحالية.' };
            } else if (status === 409) {
                needsRefresh.value = true;
                message.value = 'تغير إصدار المصدر أو سبق إعلان النطاق. حدّث الحالة قبل المحاولة.';
            } else if (status === 401 || status === 403 || status === 419) {
                needsRefresh.value = true;
                message.value = 'لم تعد الصلاحية أو الجلسة صالحة. حدّث الحالة وتحقق من المصادقة قبل المحاولة.';
            } else {
                message.value = 'لم يصل تأكيد التنفيذ. يمكنك إعادة إرسال الطلب نفسه بأمان أو تحديث الحالة.';
            }
        } else {
            message.value = 'لم يصل تأكيد التنفيذ. يمكنك إعادة إرسال الطلب نفسه بأمان أو تحديث الحالة.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form v-if="choices.length" class="mt-4 rounded-lg border border-slate-200 p-3" dir="rtl" :aria-busy="busy" @submit.prevent="declareScope">
        <h4 class="font-medium">إعلان نطاق إضافي للمصدر</h4>
        <p class="mt-1 text-sm text-slate-600">اختر المورد والعملية والغرض المصرح بها معاً. الإعلان لا يثبت إذن المزوّد أو جاهزية الاستخدام.</p>
        <label class="mt-3 block">النطاق المصرح به
            <select v-model="selected" required :disabled="busy || needsRefresh" class="mt-1 w-full rounded border-slate-300" :aria-invalid="Object.keys(errors).length > 0">
                <option value="" disabled>اختر نطاقاً</option>
                <option v-for="(scope, index) in choices" :key="JSON.stringify(scope)" :value="String(index)" dir="ltr">{{ scope.resource }} / {{ scope.operation }} / {{ scope.purpose }}</option>
            </select>
        </label>
        <p v-for="(error, field) in errors" :key="field" role="alert" class="mt-1 text-sm text-red-700">{{ error }}</p>
        <p class="mt-2 text-xs">إصدار المصدر الحالي: {{ version }}</p>
        <button type="submit" :disabled="busy || needsRefresh || selected === ''" class="mt-3 rounded bg-indigo-700 px-4 py-2 text-white disabled:opacity-50">{{ busy ? 'جارٍ إعلان النطاق…' : 'تأكيد إعلان النطاق' }}</button>
        <p v-if="message" role="status" aria-live="polite" class="mt-2 text-sm">{{ message }}</p>
        <button v-if="needsRefresh" type="button" :disabled="busy" class="mt-2 text-sm text-indigo-700 underline" @click="refresh">تحديث الحالة من الخادم</button>
    </form>
</template>
