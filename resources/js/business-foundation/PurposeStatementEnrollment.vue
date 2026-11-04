<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue';
import { enrollPurposeStatement, type PurposeStatementEnrollment, type Surface } from './api';
import { purposeEnrollmentDrafts, key } from './drafts';
import { owned, stamp, TransportError } from '../spa/transport';
import { locale } from '../spa/locale';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id;
const resource = `${key(business, props.surface.authority_selection)}:purpose-enrollment:1`;
const draft = reactive(purposeEnrollmentDrafts.get(resource) ?? { confirmed: false, blocked: false, notice: '' });
purposeEnrollmentDrafts.set(resource, draft);
const busy = ref(false);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
async function enroll() {
    if (disposed || busy.value || props.refreshing || draft.blocked || props.surface.business.business_public_id !== business || (!draft.pending && !draft.confirmed)) return;
    const policy = props.surface.purpose_statement;
    if (!draft.pending && (!policy?.enrollment_available || policy.current_grant)) return;
    draft.pending ??= { command_id: crypto.randomUUID(), authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)), confirmed: true, previous_grant: policy.previous_grant };
    const original = JSON.parse(JSON.stringify(draft.pending)) as PurposeStatementEnrollment, captured = stamp();
    busy.value = true; draft.notice = '';
    try {
        const receipt = await enrollPurposeStatement(business, original);
        if (disposed || !owned(captured) || props.surface.business.business_public_id !== business) return;
        if (receipt.business_public_id !== business || !/^[1-9][0-9]{0,18}$/.test(receipt.grant_refs['business.purpose.declare']) || receipt.authority_scope !== 'own_business_statement_only' || receipt.establishes_external_authority !== false) throw Error('Unexpected Purpose statement receipt');
        draft.pending = undefined; draft.confirmed = false;
        draft.notice = t('تم تفعيل إدارة تصريحات الغرض الخاصة بالنشاط. لم تُمنح صلاحية على أي عنصر أو مصدر خارجي.', 'Purpose statement management is enabled. No authority over an external object or source was granted.');
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && [403, 409, 422].includes(error.status)) {
            draft.blocked = true;
            draft.notice = t('تعذر تأهيل الطلب. راجع الصلاحية الحالية مع الاحتفاظ بنتيجة الطلب السابق.', 'The request could not be qualified. Review current authority while retaining the earlier command outcome.');
        } else draft.notice = t('لم نتأكد من النتيجة. تحقّق بالطلب الأصلي نفسه دون إنشاء طلب بديل.', 'The outcome is unknown. Check the exact original request without creating a replacement.');
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section v-if="surface.purpose_statement?.enrollment_available || draft.pending" data-purpose-enrollment>
        <h3>{{ t('تصريحات غرض النشاط', 'Business purpose statements') }}</h3>
        <p>{{ t('هذا الحق يتيح إدارة الغرض الذي يعلنه نشاطك فقط. لا يثبت ملكية حملة أو عنصر إعلاني، ولا يتيح قراءة مصدر أو استعمال بياناته أو تعديل إعلان أو نشره.', 'This right manages only the purpose your business declares. It does not establish ownership of a campaign or advertising object, grant source or data access, or allow editing or publishing ads.') }}</p>
        <p v-if="surface.purpose_statement.current_grant">{{ t('التصريحات مفعّلة. يمكنك إيقاف الحق من الفريق والصلاحيات؛ يبقى التاريخ محفوظًا.', 'Statements are enabled. You can surrender the right under Team and access; history remains preserved.') }}</p>
        <template v-else>
            <label><input v-model="draft.confirmed" type="checkbox" :disabled="busy || refreshing || !!draft.pending || draft.blocked" data-purpose-enrollment-confirm> {{ t('أؤكد بصفتي منشئ النشاط أنني أريد إدارة تصريحاته الخاصة ضمن هذه الحدود.', 'As the original creator, I confirm managing this business’s own statements within these limits.') }}</label>
            <button v-if="!draft.pending" type="button" :disabled="busy || refreshing || !draft.confirmed || draft.blocked" data-purpose-enrollment-submit @click="enroll">{{ t('تفعيل التصريحات', 'Enable statements') }}</button>
        </template>
        <button v-if="draft.pending" type="button" :disabled="busy || refreshing || draft.blocked" data-purpose-enrollment-retry @click="enroll">{{ t('التحقق بالطلب الأصلي', 'Check original request') }}</button>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
    </section>
</template>
