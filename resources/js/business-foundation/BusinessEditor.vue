<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { beginDelivery, definitiveRejection, drafts, key, type EditorDraft } from './drafts';
import { write, type Surface } from './api';
const props = defineProps<{ surface: Surface; refreshing?: boolean; embedded?: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const resourceKey = key(props.surface.business.business_public_id, props.surface.authority_selection);
const business = computed(() => props.surface.business);
function concealDraft(value: EditorDraft) { Object.assign(value, { name: '', type: null, baselineName: '', baselineType: null, version: 0, selected: '', blocked: true }); }
const retained = drafts.get(resourceKey);
if (retained && !props.surface.context_write_options.some(option => option.grant_id === retained.selected)) { if (retained.pending) concealDraft(retained); else drafts.delete(resourceKey); }
const draft = reactive<EditorDraft>(drafts.get(resourceKey) ?? { name: business.value.name, type: business.value.business_type_id, baselineName: business.value.name,
    baselineType: business.value.business_type_id, version: business.value.versions.context, selected: props.surface.context_write_options.length === 1 ? props.surface.context_write_options[0].grant_id : '', notice: '' });
drafts.set(resourceKey, draft);
draft.uncertain ??= !!draft.pending;
const busy = ref(false), confirmed = ref(false), denied = ref(false);
const observedVersion = ref(business.value.versions.context), displayedName = ref(business.value.name);
let disposed = false;
const options = computed(() => props.surface.context_write_options.filter(x => Number.isSafeInteger(Number(x.grant_id))));
const canEdit = computed(() => !props.refreshing && !draft.blocked && !denied.value && options.value.some(x => x.grant_id === draft.selected));
const dirty = computed(() => draft.name !== draft.baselineName || draft.type !== draft.baselineType);
const conflict = computed(() => dirty.value && draft.version !== observedVersion.value);
watch(() => props.surface, () => {
    observedVersion.value = business.value.versions.context; displayedName.value = business.value.name;
    if (!options.value.some(x => x.grant_id === draft.selected)) {
        if (!draft.pending) drafts.delete(resourceKey); concealDraft(draft); denied.value = true;
    } else if (!dirty.value && !draft.pending) {
        Object.assign(draft, { name: business.value.name, type: business.value.business_type_id, baselineName: business.value.name, baselineType: business.value.business_type_id, version: business.value.versions.context });
    }
});
function adoptLatest() {
    if (draft.pending || busy.value) return;
    Object.assign(draft, { name: business.value.name, type: business.value.business_type_id, baselineName: business.value.name, baselineType: business.value.business_type_id, version: business.value.versions.context });
}
onBeforeUnmount(() => { disposed = true; });
async function save() {
    if (busy.value || !canEdit.value || conflict.value && !draft.pending) return;
    const captured = stamp(), id = business.value.business_public_id;
    draft.pending ??= { command_id: crypto.randomUUID(), name: draft.name, business_type_id: draft.type, expected_context: draft.version,
        authority_selection: { ...JSON.parse(JSON.stringify(props.surface.authority_selection)), requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(draft.selected) }] } };
    const command = JSON.parse(JSON.stringify(draft.pending)) as NonNullable<EditorDraft['pending']>;
    const uncertain = beginDelivery(draft);
    busy.value = true; confirmed.value = false; draft.notice = '';
    try {
        const result = await write(id, command);
        if (disposed || !owned(captured)) return;
        if (result.business_public_id !== id) throw Error('Business acknowledgement mismatch');
        Object.assign(draft, { name: result.name, type: result.business_type_id, baselineName: result.name, baselineType: result.business_type_id,
            version: result.versions.context, pending: undefined, uncertain: false });
        observedVersion.value = result.versions.context; displayedName.value = result.name; confirmed.value = true;
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            denied.value = true; concealDraft(draft);
            draft.notice = t('لم يعد هذا التفويض يسمح بالتعديل. ارجع إلى قائمة الأنشطة للتحقق من وصولك الحالي.', 'This delegation no longer permits editing. Return to the business list to check current access.');
        } else if (definitiveRejection(error, uncertain)) {
            draft.pending = undefined; draft.uncertain = false; draft.notice = t('راجع الاسم والمجال؛ لم يُقبل الإدخال.', 'Review the name and field; this input was rejected.');
        } else {
            draft.notice = t('لم تُثبت نتيجة الطلب. لم نغيّر الإصدار أو نستبدل الطلب الأصلي. راجع المعلومات الحالية قبل اتخاذ إجراء جديد.', 'The command outcome is unconfirmed. Its original version and command are retained. Review current information before a new action.');
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <header v-if="!embedded" class="bf-heading"><div><p class="bf-eyebrow">{{ t('معلومات النشاط', 'Business details') }}</p><h1 data-business-name>{{ displayedName }}</h1><p>{{ t('هذه المعلومات تخص النشاط المختار وحده.', 'These details belong only to the selected business.') }}</p></div></header>
    <section class="bf-card bf-form" :data-business-public="embedded ? undefined : business.business_public_id">
        <p v-if="!options.length || denied" role="status" data-business-readonly>{{ t('يمكنك الاطلاع على المعلومات. لا يوجد تفويض متاح لتعديلها هنا.', 'You can view these details. No editing delegation is available here.') }}</p>
        <label v-if="options.length > 1" for="bf-write-path">{{ t('تفويض التعديل', 'Editing delegation') }}<select id="bf-write-path" v-model="draft.selected" :disabled="busy || !!draft.pending"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, i) in options" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ i + 1 }}</option></select></label>
        <p v-if="draft.blocked && draft.pending" role="status">{{ t('الطلب الأصلي محفوظ وغير محسوم. راجع وصولك الحالي قبل المتابعة.', 'The unresolved original request is retained. Review current access before continuing.') }}</p>
        <form v-if="!draft.blocked" @submit.prevent="save">
            <fieldset :disabled="!canEdit || busy || !!draft.pending">
                <label for="bf-name">{{ t('اسم النشاط', 'Business name') }}</label><input id="bf-name" v-model="draft.name" required minlength="2" maxlength="120" autocomplete="organization" />
                <label for="bf-type">{{ t('تصنيف عام أولي', 'Initial general classification') }}</label><select id="bf-type" v-model="draft.type"><option :value="null">{{ t('غير محدد', 'Not specified') }}</option><option v-for="type in props.surface.catalog.business_types.filter(x => x.active || x.id === draft.type)" :key="type.id" :value="type.id" :disabled="!type.active && type.id !== draft.baselineType">{{ locale === 'ar' ? type.name_ar : type.name_en }}{{ type.active ? '' : t(' — غير متاح لاختيار جديد', ' — unavailable for new selection') }}</option></select>
            </fieldset>
            <p v-if="conflict" role="alert" data-business-conflict>{{ t('تغيرت معلومات النشاط منذ بدأت هذه المسودة. لم ننقل تعديلاتك إلى الإصدار الجديد.', 'Business details changed after this draft began. Your edits have not been moved to the new version.') }}<button v-if="!draft.pending" type="button" @click="adoptLatest">{{ t('استبدال المسودة بالمعلومات الحالية', 'Replace draft with current details') }}</button></p>
            <p v-if="draft.pending && !busy" role="status" data-business-unknown>{{ t('لم نتأكد من نتيجة الحفظ. يمكنك التحقق بالطلب الأصلي.', 'Saving could not be confirmed. You can check using the original request.') }}</p>
            <p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="confirmed" role="status" data-business-saved>{{ t('تم حفظ المعلومات بتأكيد من الخادم.', 'Details saved with server confirmation.') }}</p>
            <button v-if="canEdit" class="bf-primary" :disabled="busy || conflict && !draft.pending || !dirty && !draft.pending">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Check using original command') : t('حفظ المعلومات', 'Save details') }}</button>
        </form>
    </section>
</template>
