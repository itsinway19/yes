<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import OperationsShell from './OperationsShell.vue';
import BusinessTypeEditor from './BusinessTypeEditor.vue';
import { businessTypes, businessBusy as busy, businessOutcome as outcome, readBusinessTypes, writeBusiness, type BusinessCommand } from './businessState';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
import { locale } from '../spa/locale';
import { confirmDeparture } from '../spa/presentation';
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const types = computed(() => businessTypes.value!.types), providers = computed(() => businessTypes.value!.providers), products = computed(() => businessTypes.value!.products), canManage = computed(() => businessTypes.value!.canManage);
const form = reactive({ key: '', name_ar: '', name_en: '', active: true, errors: {} as Record<string, string>, recentlySuccessful: false, processing: false });
const error = ref(''), readbackFailed = ref(false), reviewed = ref(false);
const locked = computed(() => busy.value || outcome.value === 'unknown' || readbackFailed.value);
const cancellation = new AbortController(); let active = true;
const canLeave = () => !['pending', 'unknown'].includes(outcome.value) || confirm(copy('يوجد أمر غير محسوم. المغادرة لا تعيد تنفيذه. هل تريد المتابعة؟', 'An operation is unresolved. Leaving never replays it. Continue?'));
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { if (['pending', 'unknown'].includes(outcome.value)) { event.preventDefault(); event.returnValue = ''; } }
window.addEventListener('beforeunload', beforeUnload);
const clearPrivate = () => { form.key = ''; form.name_ar = ''; form.name_en = ''; error.value = ''; };
const closures = onClose(clearPrivate);
onBeforeUnmount(() => { active = false; cancellation.abort(); if (outcome.value === 'pending') outcome.value = 'unknown'; busy.value = false; closures.delete(clearPrivate); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); });
async function review() { if (busy.value) return; const value = await readBusinessTypes(false, true); if (active) { readbackFailed.value = !value; reviewed.value = !!value; if (value && outcome.value === 'unknown') outcome.value = ''; } }
async function perform(command: BusinessCommand) {
    if (locked.value) return;
    const captured = stamp(); busy.value = true; outcome.value = 'pending'; error.value = ''; reviewed.value = false; form.errors = {};
    try {
        await writeBusiness(command, cancellation.signal);
        if (!active || !owned(captured)) return;
        outcome.value = 'acknowledged';
        if (command.kind === 'create') { form.key = ''; form.name_ar = ''; form.name_en = ''; form.active = true; form.recentlySuccessful = true; }
        readbackFailed.value = !(await readBusinessTypes(false, true));
    } catch (failure) {
        if (!active || !owned(captured)) return;
        if (failure instanceof TransportError && [403, 404, 409, 422, 429].includes(failure.status)) {
            outcome.value = 'rejected'; error.value = failure.body.message ?? copy('رفض الخادم الأمر.', 'The server rejected the command.');
            form.errors = Object.fromEntries(Object.entries(failure.body.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
            if (failure.status === 403) close();
        } else outcome.value = 'unknown';
    } finally { if (active) busy.value = false; }
}
async function create() { form.processing = true; try { await perform({ kind: 'create', body: { key: form.key, name_ar: form.name_ar, name_en: form.name_en, active: form.active } }); } finally { form.processing = false; } }
</script>
<template><OperationsShell><template #header><h1>{{ copy('أنواع الأنشطة', 'Business types') }}</h1><p>{{ copy('تعريف السياق وتوجيه المصادر ومراجعة الاعتبارات الأولية.', 'Define context, guide sources, and review initial promotion priors.') }}</p></template><div data-business-types class="business-catalog"><p v-if="outcome === 'acknowledged'" role="status" data-business-ack>{{ copy('وصل إقرار نجاح الأمر من الخادم.', 'The server acknowledged command success.') }}</p><p v-if="outcome === 'unknown'" role="alert" data-business-unknown>{{ copy('نتيجة الأمر غير مؤكدة. لن يعاد تلقائيًا؛ راجع الحالة قبل قرار جديد.', 'Command outcome is unknown. It will not replay; review state before a new decision.') }}</p><p v-if="readbackFailed" role="alert">{{ copy('تعذر تحديث العرض؛ إقرار الأمر مستقل عن القراءة.', 'Readback failed; command acknowledgement is separate.') }}</p><p v-if="reviewed" role="status">{{ copy('هذه قراءة حالية وليست إقرارًا بالأمر السابق.', 'This is a current read, not acknowledgement of the earlier command.') }}</p><p v-if="error" role="alert">{{ error }}</p><button v-if="outcome === 'unknown' || readbackFailed" data-business-review type="button" :disabled="busy" @click="review">{{ copy('مراجعة الحالة دون إعادة الأمر', 'Review state without replay') }}</button><fieldset :disabled="locked"><p v-if="!canManage" class="catalog-note">{{ copy('عرض فقط. تعديل الأنواع يحتاج إلى منحة إدارة صريحة.', 'Read only. Editing types requires an explicit management grant.') }}</p><details v-if="canManage" class="catalog-create"><summary>{{ copy('إضافة نوع نشاط', 'Add a business type') }}</summary><form class="catalog-form" @submit.prevent="create"><label>{{ copy('المفتاح الثابت', 'Stable key') }}<input v-model="form.key" required dir="ltr" /></label><label>{{ copy('الاسم بالعربية', 'Arabic name') }}<input v-model="form.name_ar" required dir="rtl" /></label><label>{{ copy('الاسم بالإنجليزية', 'English name') }}<input v-model="form.name_en" required dir="ltr" /></label><label class="catalog-check"><input v-model="form.active" type="checkbox" />{{ copy('متاح للاختيار', 'Available for selection') }}</label><p v-for="(message, field) in form.errors" :key="field" role="alert" class="catalog-error">{{ message }}</p><p v-if="form.recentlySuccessful" role="status">{{ copy('أُضيف النوع.', 'Type added.') }}</p><button :disabled="form.processing">{{ form.processing ? copy('جارٍ الحفظ…', 'Saving…') : copy('إضافة النوع', 'Add type') }}</button></form></details><p v-if="!types.length" class="catalog-note">{{ copy('لا توجد أنواع أنشطة بعد.', 'No business types yet.') }}</p><BusinessTypeEditor v-for="type in types" :key="type.id" :type="type" :providers="providers" :products="products" :can-manage="canManage" @save="perform" /></fieldset></div></OperationsShell></template>
<style>
.business-catalog{padding:24px 32px 64px;max-width:1050px;color:#203439}.business-catalog h2{font-size:21px;font-weight:650}.business-catalog summary{cursor:pointer;font-size:14px;font-weight:600;padding:16px 0}.business-catalog details[open]>summary{border-bottom:1px solid #dce4e1;margin-bottom:18px}.business-catalog .catalog-type{border-top:1px solid #d3dfda;margin-top:30px;padding-top:24px}.catalog-type>header{display:flex;gap:24px;justify-content:space-between;align-items:start}.catalog-type>header p{font-size:12px;color:#53666a;margin-top:5px}.catalog-state{font-size:12px;padding:6px 10px;background:#e9f1ec;color:#28534b}.catalog-form{display:grid;gap:18px;max-width:640px;margin:20px 0 30px}.catalog-form label{display:grid;gap:7px;font-size:14px;font-weight:550}.catalog-form input:not([type=checkbox]),.catalog-form select,.catalog-form textarea{width:100%;min-width:0;border:1px solid #a4b5ad;border-radius:4px;padding:10px;font-size:14px;background:white}.catalog-form textarea{min-height:90px;resize:vertical}.catalog-form .catalog-check{display:flex;align-items:center;gap:10px}.business-catalog button{font-size:13px;font-weight:600;color:#155f53;border:1px solid #9db7aa;border-radius:4px;padding:9px 15px;width:fit-content}.business-catalog button:disabled{opacity:.5}.catalog-form>button:last-child{background:#155f53;color:white;border-color:#155f53}.catalog-error{color:#a52b28;font-size:13px}.catalog-note{font-size:14px;line-height:1.9;color:#526660;max-width:720px}.catalog-records>li{display:flex;justify-content:space-between;gap:20px;padding:18px 0;border-bottom:1px solid #dce4e1;font-size:14px}.catalog-records p{margin:6px 0;line-height:1.8}.catalog-records span{font-size:12px;color:#526660}.catalog-records button{flex-shrink:0;align-self:start}.business-catalog :is(button,summary):focus-visible{outline:2px solid #155f53;outline-offset:4px}@media(max-width:640px){.business-catalog{padding:20px 20px 40px}.catalog-type>header{display:block}.catalog-state{display:inline-block;margin-top:12px}.catalog-records>li{flex-wrap:wrap}}
</style>
