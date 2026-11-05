<script setup lang="ts">
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { enrollDirectTeam, type Surface, type DirectTeamEnrollment } from './api';
import { directTeamDrafts, key } from './drafts';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id;
const selection = JSON.stringify(props.surface.authority_selection);
const resource = key(business, props.surface.authority_selection);
type EnrollmentDraft = NonNullable<ReturnType<typeof directTeamDrafts.get>> & {
    uncertain?: boolean; review?: 'conflict' | 'denied'; completed?: boolean;
};
const draft = reactive<EnrollmentDraft>(directTeamDrafts.get(resource) ?? { confirmed: false, blocked: false, notice: '' });
// An unfinished request restored after leaving this panel has an unknown outcome.
draft.uncertain ??= !!draft.pending;
directTeamDrafts.set(resource, draft);
const busy = ref(false);
let disposed = false;
let reviewedSurface = props.surface;
const currentContext = () => business === props.surface.business.business_public_id && selection === JSON.stringify(props.surface.authority_selection);
function refreshAuthority() {
    if (busy.value || props.refreshing || !currentContext()) return;
    reviewedSurface = props.surface;
    emit('refresh');
}
watch(() => [props.surface, props.refreshing], () => {
    if (!currentContext()) { if (draft.pending) draft.uncertain = true; return; }
    if (props.refreshing || !draft.review || reviewedSurface === props.surface) return;
    draft.review = undefined; draft.blocked = false; draft.confirmed = false;
    draft.notice = draft.pending
        ? t('تم تحديث الصلاحية. تحقّق من نتيجة الطلب الأصلي قبل أي طلب جديد.', 'Authority refreshed. Check the original request before any new command.')
        : props.surface.direct_team_enrollment_available
            ? t('تم تحديث الحالة. راجع تفعيل الفريق وأكّده من جديد.', 'State refreshed. Review team activation and confirm again.')
            : t('تم تحديث حالة الفريق. راجع الصلاحيات المتاحة أدناه.', 'Team state refreshed. Review the available permissions below.');
});
onBeforeUnmount(() => { if (draft.pending) draft.uncertain = true; disposed = true; });
async function enroll() {
    if (!currentContext() || busy.value || props.refreshing || draft.blocked || draft.review || draft.completed
        || (!draft.pending && (!props.surface.direct_team_enrollment_available || !draft.confirmed))) return;
    draft.pending ??= { command_id: crypto.randomUUID(), authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) };
    const original = JSON.parse(JSON.stringify(draft.pending)) as DirectTeamEnrollment, captured = stamp();
    busy.value = true; draft.notice = '';
    try {
        const receipt = await enrollDirectTeam(business, original);
        if (disposed || !owned(captured) || !currentContext()) return;
        if (receipt.business_public_id !== business || !receipt.grant_refs['business.context.read']) throw Error('Team receipt mismatch');
        draft.pending = undefined; draft.confirmed = false; draft.uncertain = false; draft.completed = true;
        draft.notice = t('تم تفعيل إدارة فريق النشاط. اختر الصلاحية والموظف أدناه.', 'Business team administration enabled. Choose the right and employee below.');
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured) || !currentContext()) return;
        const rejection = error instanceof TransportError && [403, 409, 422].includes(error.status);
        if (rejection && draft.uncertain) {
            // A replay rejection says nothing about whether the earlier attempt committed.
            draft.blocked = true; draft.review = 'denied'; draft.confirmed = false;
            draft.notice = t('نتيجة الطلب الأصلي ما زالت غير محسومة. حدّث الصلاحية ثم تحقّق بالطلب نفسه؛ لن ننشئ طلبًا بديلًا.', 'The original outcome remains unknown. Refresh authority, then check the same request; no replacement will be created.');
        } else if (rejection && error.status === 422) {
            draft.pending = undefined; draft.confirmed = false;
            draft.notice = t('رُفض التفعيل دون تنفيذ. راجع الاختيار وأكّده قبل إرسال طلب جديد.', 'Activation was rejected without execution. Review and confirm before submitting a new request.');
        } else if (rejection) {
            draft.pending = undefined; draft.confirmed = false; draft.blocked = true;
            draft.review = error.status === 409 ? 'conflict' : 'denied';
            reviewedSurface = props.surface;
            draft.notice = error.status === 409
                ? t('تغيّرت حالة الفريق. جارٍ تحديثها قبل المتابعة.', 'Team state changed. Refreshing it before continuing.')
                : t('لم تسمح الصلاحية الحالية بالتفعيل. حدّثها قبل المتابعة.', 'Current authority did not permit activation. Refresh it before continuing.');
            if (error.status === 409) emit('refresh');
        } else {
            draft.uncertain = true;
            draft.notice = t('لم نتأكد من النتيجة. تحقّق بإرسال الطلب الأصلي نفسه.', 'The outcome is unknown. Check using the exact original request.');
        }
    } finally { if (!disposed && owned(captured) && currentContext()) busy.value = false; }
}
</script>
<template>
    <section v-if="currentContext() && (surface.direct_team_enrollment_available || draft.pending || draft.notice)" class="bf-card" data-business-direct-team>
        <h3>{{ t('موظفو النشاط دون جهة تنظيمية', 'Business employees without an organization') }}</h3>
        <p>{{ t('يمكنك منح موظف مسجل صلاحيات محددة على معلومات هذا النشاط وأسواقه ونيته وأولوياته، دون إنشاء مساحة عمل. لا يشمل ذلك البيانات الخاصة أو حقوق المصادر أو تفويضًا عن عميل خارجي.', 'Grant a registered employee exact rights over this business’s details, markets, intent and preferences without creating a workspace. This excludes private records, source rights and external-client representation.') }}</p>
        <label v-if="surface.direct_team_enrollment_available && !draft.pending && !draft.blocked && !draft.completed"><input v-model="draft.confirmed" type="checkbox" :disabled="busy || refreshing" data-direct-team-confirm />{{ t('أوافق على تفعيل إدارة فريق لهذا النشاط ضمن هذه الحدود.', 'Enable team administration for this business within these limits.') }}</label>
        <button v-if="surface.direct_team_enrollment_available || draft.pending" type="button" class="workspace-button" :disabled="busy || refreshing || draft.blocked || !!draft.review || draft.completed || (!draft.pending && !draft.confirmed)" data-direct-team-submit @click="enroll">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق من النتيجة', 'Check the outcome') : t('تفعيل إدارة الموظفين', 'Enable employee administration') }}</button>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
        <button v-if="draft.review" type="button" class="workspace-button" :disabled="busy || refreshing" data-direct-team-refresh @click="refreshAuthority">{{ t('تحديث حالة الفريق والصلاحية', 'Refresh team state and authority') }}</button>
    </section>
</template>
