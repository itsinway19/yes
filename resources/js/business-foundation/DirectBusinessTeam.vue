<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { enrollDirectTeam, type Surface, type DirectTeamEnrollment } from './api';
import { directTeamDrafts, key } from './drafts';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id;
const resource = key(business, props.surface.authority_selection);
const draft = reactive(directTeamDrafts.get(resource) ?? { confirmed: false, pending: undefined as DirectTeamEnrollment | undefined, blocked: false, notice: '' });
directTeamDrafts.set(resource, draft);
const busy = ref(false);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
async function enroll() {
    if (business !== props.surface.business.business_public_id || busy.value || props.refreshing || draft.blocked || (!draft.pending && !draft.confirmed)) return;
    draft.pending ??= { command_id: crypto.randomUUID(), authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) };
    const original = JSON.parse(JSON.stringify(draft.pending)) as DirectTeamEnrollment, captured = stamp();
    busy.value = true; draft.notice = '';
    try {
        const receipt = await enrollDirectTeam(business, original);
        if (disposed || !owned(captured) || business !== props.surface.business.business_public_id) return;
        if (receipt.business_public_id !== business || !receipt.grant_refs['business.context.read']) throw Error('Team receipt mismatch');
        draft.pending = undefined; draft.confirmed = false;
        draft.notice = t('تم تفعيل إدارة فريق النشاط. اختر الصلاحية والموظف أدناه.', 'Business team administration enabled. Choose the right and employee below.');
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && [403, 409, 422].includes(error.status)) {
            draft.blocked = true;
            draft.notice = t('تعذر اعتماد الطلب. احتفظ بنتيجته وراجع الصلاحية الحالية قبل المتابعة.', 'The request could not be admitted. Retain its outcome and review current authority before continuing.');
        } else draft.notice = t('لم نتأكد من النتيجة. تحقّق بإرسال الطلب الأصلي نفسه.', 'The outcome is unknown. Check using the exact original request.');
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section v-if="surface.direct_team_enrollment_available || draft.pending || draft.notice" class="bf-card" data-business-direct-team>
        <h3>{{ t('موظفو النشاط دون جهة تنظيمية', 'Business employees without an organization') }}</h3>
        <p>{{ t('يمكنك منح موظف مسجل صلاحيات محددة على معلومات هذا النشاط وأسواقه ونيته وأولوياته، دون إنشاء مساحة عمل. لا يشمل ذلك البيانات الخاصة أو حقوق المصادر أو تفويضًا عن عميل خارجي.', 'Grant a registered employee exact rights over this business’s details, markets, intent and preferences without creating a workspace. This excludes private records, source rights and external-client representation.') }}</p>
        <label v-if="!draft.pending && !draft.notice"><input v-model="draft.confirmed" type="checkbox" :disabled="busy || refreshing" />{{ t('أوافق على تفعيل إدارة فريق لهذا النشاط ضمن هذه الحدود.', 'Enable team administration for this business within these limits.') }}</label>
        <button v-if="surface.direct_team_enrollment_available || draft.pending" type="button" class="workspace-button" :disabled="busy || refreshing || draft.blocked || (!draft.pending && !draft.confirmed)" @click="enroll">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق من النتيجة', 'Check the outcome') : t('تفعيل إدارة الموظفين', 'Enable employee administration') }}</button>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
    </section>
</template>
