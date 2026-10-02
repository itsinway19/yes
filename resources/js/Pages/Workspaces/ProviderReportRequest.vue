<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ workspaceId: string; sourceId: string; version: number; allowed: boolean; provider: 'meta' | 'google' | 'tiktok' | 'snapchat' | 'x' }>();
type Status = { generation: number; timezone: string; deadline: string; download?: { generation: number; url: string } | null; last_run: { coverage: string; recorded_at: string } | null };
type Command = { campaign_ids?: string[]; placement?: string; mode?: string; start_date: string; end_date: string; command_id: string; expected_version: number; expected_generation: number; deadline: string };
const start = ref('');
const end = ref('');
const campaignIds = ref('');
const placement = ref('ALL_ON_TWITTER');
const mode = ref('ASYNC');
const campaigns = ref<{ id: string; label: string }[]>([]);
const campaignContinuation = ref<string | null>(null);
const periodInput = () => ({ start_date: start.value, end_date: end.value, ...(props.provider === 'x' ? { campaign_ids: campaignIds.value.split(/[\s,]+/).filter(Boolean), placement: placement.value, mode: mode.value } : props.provider === 'meta' ? { mode: mode.value } : {}) });
const busy = ref(false);
const waiting = ref(false);
const status = ref<Status | null>(null);
const googleMetricOptions = [
    { id: 'clicks', label: 'النقرات', required: true }, { id: 'impressions', label: 'الظهور', required: true },
    { id: 'conversions', label: 'التحويلات', required: true }, { id: 'all_conversions', label: 'جميع التحويلات', required: true },
    { id: 'conversions_value', label: 'قيمة التحويلات', required: false },
];
const googleMetrics = ref(googleMetricOptions.map(metric => metric.id));
const googleDownloadUrl = computed(() => {
    const url = status.value?.download?.url;
    if (!url) return null;
    const selected = googleMetricOptions.filter(metric => metric.required || googleMetrics.value.includes(metric.id));
    if (selected.length === googleMetricOptions.length) return url;
    const query = new URLSearchParams();
    for (const metric of selected) query.append('metrics[]', metric.id);
    return `${url}${url.includes('?') ? '&' : '?'}${query.toString()}`;
});
const command = ref<Command | null>(null);
const submittedGeneration = ref<number | null>(null);
const submittedDeadline = ref<string | null>(null);
const error = ref('');
const message = ref('');
const cancellation = new AbortController();
let active = true;
const base = `/workspaces/${encodeURIComponent(props.workspaceId)}/providers/${props.provider}/${encodeURIComponent(props.sourceId)}/report`;
const key = () => `${props.version}:${start.value}:${end.value}:${campaignIds.value}:${placement.value}:${mode.value}`;

watch([start, end, campaignIds, placement, mode, () => props.version], () => {
    command.value = null; status.value = null; waiting.value = false;
    submittedGeneration.value = null; submittedDeadline.value = null; error.value = ''; message.value = '';
});

function fail(failure: unknown) {
    if (!active || axios.isCancel(failure)) return;
    const code = axios.isAxiosError(failure) ? failure.response?.status : null;
    error.value = code === 403 ? 'لا يتوفر الإذن الحالي لسحب هذه البيانات. حدّث حالة الربط وتحقق من الصلاحيات.'
        : code === 422 ? 'راجع الفترة المحددة. قد تحتاج تحديث الحالة لبدء طلب جديد.'
            : code === 503 ? 'إعداد سحب تقارير المنصة غير مكتمل. راجع مسؤول الخدمة.'
                : 'تعذّر تأكيد نتيجة الطلب. أعد المحاولة للتحقق دون إنشاء أمر مختلف.';
}

function coverageLabel(coverage: string): string {
    return ({ COMPLETE: 'مكتمل', PENDING: 'قيد المعالجة لدى المنصة', PARTIAL: 'غير مكتمل', FAILED: 'لم يكتمل السحب' } as Record<string, string>)[coverage] ?? 'تحتاج تحققًا';
}

async function fetchStatus() {
    return (await axios.get<Status>(base, { params: periodInput(), signal: cancellation.signal })).data;
}

async function loadCampaigns(continuation: string | null = null) {
    if (busy.value || !props.allowed || props.provider !== 'x') return;
    busy.value = true; error.value = '';
    try {
        const response = await axios.get<{ campaigns: { id: string; label: string }[]; continuation: string | null }>(base.replace(/\/report$/, '/campaigns'),
            { params: continuation ? { continuation } : {}, signal: cancellation.signal });
        if (active) { campaigns.value = response.data.campaigns; campaignContinuation.value = response.data.continuation; }
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

function toggleCampaign(id: string, checked: boolean) {
    const ids = campaignIds.value.split(/[\s,]+/).filter(Boolean);
    campaignIds.value = (checked ? [...new Set([...ids, id])] : ids.filter(value => value !== id)).join(',');
}

async function refresh() {
    if (busy.value || !props.allowed || !start.value || !end.value) return;
    const view = key(); busy.value = true; error.value = '';
    try {
        const current = await fetchStatus();
        if (!active || view !== key()) return;
        status.value = current;
        if (submittedGeneration.value !== null && current.generation > submittedGeneration.value && !['PARTIAL', 'PENDING'].includes(current.last_run?.coverage ?? '')) waiting.value = false;
        if (waiting.value && submittedDeadline.value && Date.parse(submittedDeadline.value) <= Date.now()) {
            waiting.value = false; submittedDeadline.value = null;
            message.value = 'انتهت مهلة تنفيذ الطلب السابق. الحالة المعروضة هي آخر نتيجة محفوظة؛ يمكنك طلب سحب جديد عند الحاجة.';
        }
        if (command.value && Date.parse(command.value.deadline) <= Date.now()) command.value = null;
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

async function submit() {
    if (busy.value || waiting.value || !props.allowed) return;
    const view = key(); busy.value = true; error.value = ''; message.value = '';
    try {
        if (!command.value) {
            const current = await fetchStatus();
            if (!active || view !== key()) return;
            status.value = current;
            command.value = { ...periodInput(), command_id: crypto.randomUUID(),
                expected_version: props.version, expected_generation: current.generation, deadline: current.deadline };
        }
        const pending = command.value;
        await axios.post(base, pending, { signal: cancellation.signal });
        if (!active || view !== key()) return;
        submittedGeneration.value = pending.expected_generation; submittedDeadline.value = pending.deadline; command.value = null; waiting.value = true;
        message.value = 'تم إرسال طلب السحب ليعمل في الخلفية. حدّث الحالة لاحقًا لمعرفة نتيجة هذه الفترة.';
    } catch (failure) { fail(failure); }
    finally { if (active) busy.value = false; }
}

onBeforeUnmount(() => { active = false; cancellation.abort(); });
</script>

<template>
    <div class="mt-4 rounded-xl border border-slate-200 p-4" :aria-busy="busy">
        <h4 class="text-sm font-semibold text-slate-900">سحب بيانات الحملات من {{ ({ meta: 'Meta', google: 'Google', tiktok: 'TikTok', snapchat: 'Snapchat', x: 'X' })[provider] }}</h4>
        <p v-if="!allowed" class="mt-2 text-sm leading-6 text-slate-600">يتطلب السحب ربطًا صالحًا وإذنًا حاليًا واكتمال إثبات استخدام البيانات. الربط وحده لا يكفي.</p>
        <form v-else class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <div><label :for="`${provider}-report-start-${sourceId}`" class="block text-sm text-slate-700">من تاريخ</label><input :id="`${provider}-report-start-${sourceId}`" v-model="start" required type="date" :disabled="busy" class="mt-1 rounded-lg border-slate-300 text-sm" /></div>
            <div><label :for="`${provider}-report-end-${sourceId}`" class="block text-sm text-slate-700">إلى تاريخ</label><input :id="`${provider}-report-end-${sourceId}`" v-model="end" required type="date" :min="start || undefined" :disabled="busy" class="mt-1 rounded-lg border-slate-300 text-sm" /></div>
            <div v-if="provider === 'meta'"><label :for="`meta-mode-${sourceId}`" class="block text-sm text-slate-700">نوع تقرير Meta</label><select :id="`meta-mode-${sourceId}`" v-model="mode" :disabled="busy" class="mt-1 rounded-lg border-slate-300 text-sm"><option value="ASYNC">تقرير تاريخي في الخلفية</option><option value="SYNC">قراءة مباشرة في عامل الخلفية</option></select>
                <p class="mt-1 text-xs text-slate-600">نتابع معالجة التقرير ضمن مهلة الطلب. عدم اكتماله خلال المهلة لا يعني توفر بيانات مكتملة.</p>
            </div>
            <template v-if="provider === 'x'">
                <div class="w-full"><button type="button" :disabled="busy" @click="loadCampaigns()" class="text-sm text-indigo-700 underline">اختيار الحملات من حساب X</button>
                    <ul v-if="campaigns.length" class="mt-2 max-h-48 overflow-auto"><li v-for="campaign in campaigns" :key="campaign.id" class="py-1"><label class="text-sm"><input type="checkbox" :disabled="busy" :checked="campaignIds.split(/[\s,]+/).includes(campaign.id)" @change="toggleCampaign(campaign.id, ($event.target as HTMLInputElement).checked)" class="me-2" />{{ campaign.label }} <span dir="ltr" class="text-xs text-slate-500">{{ campaign.id }}</span></label></li></ul>
                    <button v-if="campaignContinuation" type="button" :disabled="busy" @click="loadCampaigns(campaignContinuation)" class="mt-2 text-sm text-indigo-700 underline">الحملات التالية</button>
                </div>
                <div><label :for="`x-campaigns-${sourceId}`" class="block text-sm text-slate-700">معرّفات الحملات (حتى 20، مفصولة بفاصلة)</label><input :id="`x-campaigns-${sourceId}`" v-model="campaignIds" required :disabled="busy" dir="ltr" class="mt-1 rounded-lg border-slate-300 text-sm" /></div>
                <div><label :for="`x-placement-${sourceId}`" class="block text-sm text-slate-700">موضع الإعلان</label><select :id="`x-placement-${sourceId}`" v-model="placement" :disabled="busy" class="mt-1 rounded-lg border-slate-300 text-sm"><option value="ALL_ON_TWITTER">إعلانات X</option><option value="SPOTLIGHT">Spotlight</option><option value="TREND">Trend</option></select></div>
                <div><label :for="`x-mode-${sourceId}`" class="block text-sm text-slate-700">نوع السحب</label><select :id="`x-mode-${sourceId}`" v-model="mode" :disabled="busy" class="mt-1 rounded-lg border-slate-300 text-sm"><option value="ASYNC">تقرير تاريخي في الخلفية (حتى 90 يومًا)</option><option value="SYNC">فترة قصيرة (حتى 7 أيام)</option></select></div>
                <p class="w-full text-xs text-slate-600">تُحسب الحدود حسب المنطقة الزمنية للحساب. تبقى مواضع الإعلان منفصلة.</p>
            </template>
            <button type="submit" :disabled="busy || waiting" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ command ? 'إعادة تأكيد الطلب' : 'طلب سحب البيانات' }}</button>
            <button type="button" :disabled="busy || !start || !end" @click="refresh" class="text-sm font-medium text-indigo-700 underline disabled:opacity-50">تحديث حالة الفترة</button>
        </form>
        <p v-if="status" class="mt-3 text-xs text-slate-600">تعتمد الفترة على المنطقة الزمنية للحساب: <span dir="ltr">{{ status.timezone }}</span></p>
        <p v-if="status?.last_run" class="mt-2 text-sm text-slate-700">آخر نتيجة سحب لهذه الفترة: {{ coverageLabel(status.last_run.coverage) }}. اكتمال السحب لا يثبت جاهزية التحليل.</p>
        <div v-if="provider === 'google' && status?.download" class="mt-3">
            <fieldset class="mb-3"><legend class="text-sm font-medium text-slate-700">مقاييس التنزيل</legend>
                <div class="mt-2 flex flex-wrap gap-3"><label v-for="metric in googleMetricOptions" :key="metric.id" class="text-sm text-slate-700"><input v-model="googleMetrics" type="checkbox" :value="metric.id" :disabled="metric.required" class="me-1 rounded border-slate-300" />{{ metric.label }}</label></div>
                <p class="mt-2 text-xs text-slate-600">تبقى المقاييس المطلوبة في كل تنزيل وفق متطلبات Google. يمكنك اختيار إضافة قيمة التحويلات.</p>
            </fieldset>
            <a v-if="googleDownloadUrl" :href="googleDownloadUrl" class="inline-block rounded-lg border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-700">تنزيل تقرير حملات Google بالمقاييس المختارة (CSV)</a>
            <p v-else role="status" class="text-sm text-slate-700">اختر مقياسًا واحدًا على الأقل لتنزيل التقرير.</p>
            <p class="mt-2 text-xs leading-5 text-slate-600">نسخة محفوظة من هذا السحب؛ يبقى الإنفاق وحالة الحملة والفترة ومصدر البيانات في كل تنزيل. اختيار المقاييس لا يطلب سحبًا جديدًا. تبقى القيم غير المتاحة مجهولة، والتحويلات منسوبة للمنصة. قد تقرّب برامج الجداول الأرقام الطويلة عند فتح الملف؛ استورد أعمدتها كنص للحفاظ على دقتها.</p>
        </div>
        <p v-if="message" role="status" class="mt-3 text-sm text-slate-700">{{ message }}</p>
        <p v-if="error" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
