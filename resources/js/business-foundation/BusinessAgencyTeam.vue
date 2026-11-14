<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { agencyOptions, writeAgencyGrant, type AgencyOptions, type AgencyGrantCommand, type Surface } from './api';
import { agencyTeamDrafts, key, type AgencyTeamDraft } from './drafts';
import { businessReference } from './businessReference';

const props = defineProps<{ surface: Surface; active: boolean; refreshing: boolean; recipientPublicId?: string; recipientName?: string; agencyName?: string }>();
const emit = defineEmits<{ refresh: []; pendingChange: [pending: boolean] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id;
const basis = JSON.stringify(props.surface.authority_selection), admission = stamp();
const resource = key(business, props.surface.authority_selection);
const allowedModes = computed(() => [
    ...(props.surface.agency_task?.can_mandate === true ? ['mandate' as const] : []),
    ...(props.surface.agency_task?.can_assign === true && !!props.surface.agency_task.agency_business_public_id ? ['assign' as const] : []),
]);
const draft = reactive<AgencyTeamDraft>(agencyTeamDrafts.get(resource) ?? {
    mode: allowedModes.value[0] ?? 'mandate', agency: props.surface.agency_task?.can_assign ? props.surface.agency_task.agency_business_public_id ?? '' : '',
    recipient: '', action: '', expires: '', confirmed: false, uncertain: false, blocked: false, outcome: '',
});
draft.uncertain ||= !!draft.pending;
if (!draft.pending && allowedModes.value.length === 1) {
    draft.mode = allowedModes.value[0];
    if (draft.mode === 'assign') draft.agency = props.surface.agency_task?.agency_business_public_id ?? '';
}
if (!draft.pending && draft.mode === 'assign' && props.recipientPublicId) draft.recipient = props.recipientPublicId;
agencyTeamDrafts.set(resource, draft);
watch(() => !!draft.pending, value => emit('pendingChange', value), { immediate: true, flush: 'sync' });
const projection = ref<AgencyOptions>();
const loading = ref(false), busy = ref(false);
let disposed = false, generation = 0;
const currentContext = () => !disposed && owned(admission) && props.active
    && props.surface.business.business_public_id === business && JSON.stringify(props.surface.authority_selection) === basis;
const uuid = (value: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
const agencyIdentity = computed(() => businessReference(draft.agency, globalThis.location?.origin ?? 'http://localhost'));
const access = computed(() => Number(props.surface.authority_selection.requirements.find(row => row.requirement_kind === 'business')?.leaf_grant_id));
const canInspect = computed(() => currentContext() && !props.refreshing && !loading.value && !busy.value && !draft.pending
    && allowedModes.value.includes(draft.mode)
    && (draft.mode !== 'assign' || draft.agency === props.surface.agency_task?.agency_business_public_id)
    && (draft.mode !== 'assign' || !props.recipientPublicId || draft.recipient === props.recipientPublicId)
    && Number.isSafeInteger(access.value) && access.value > 0 && !!agencyIdentity.value
    && (draft.mode === 'mandate' || uuid(draft.recipient.trim())));
const labels: Record<string, [string, string]> = {
    'business.context.read': ['قراءة معلومات النشاط', 'Read business details'],
    'business.context.manage': ['تعديل معلومات النشاط', 'Edit business details'],
    'business.intent.declare': ['تعديل الهدف الحالي', 'Edit current goal'],
    'business.markets.declare': ['تعديل الأسواق المستهدفة', 'Edit target markets'],
    'business.preferences.manage': ['تعديل أولويات المعلن', 'Edit advertiser preferences'],
};
const label = (action: string) => labels[action] ? t(...labels[action]) : action;
// Distinct admitted parents may allow the same action. Preserve the selected
// path identity instead of silently choosing the first matching action.
const selected = computed(() => projection.value?.options.find(option => String(option.parent_grant_id) === draft.action));
function optionLabel(option: AgencyOptions['options'][number]) {
    const peers = projection.value?.options.filter(row => row.action === option.action) ?? [];
    return label(option.action) + (peers.length > 1 ? ` · ${t('مسار تفويض', 'Authority path')} ${peers.findIndex(row => row.parent_grant_id === option.parent_grant_id) + 1}` : '');
}
const expiry = computed(() => draft.expires && Number.isFinite(Date.parse(draft.expires)) ? new Date(draft.expires).toISOString() : null);
const canSend = computed(() => currentContext() && !props.refreshing && !busy.value && !loading.value && !draft.blocked
    && allowedModes.value.includes(draft.mode)
    && !!selected.value && !!expiry.value && draft.confirmed && Date.parse(expiry.value) > Date.now()
    && (!selected.value.max_expires_at || Date.parse(expiry.value) <= Date.parse(selected.value.max_expires_at)));
const notice = computed(() => ({
    unavailable: t('تعذر التحقق الآن. أعد الفحص قبل تأكيد أي صلاحية.', 'Eligibility could not be checked. Review it before confirming a permission.'),
    empty: t('لا توجد صلاحية مؤهلة لهذا الطلب. راجع النشاط المفوَّض وتكليف الموظف.', 'No qualified permission is available for this request. Review the delegated business and employee assignment.'),
    unknown: t('نتيجة الطلب غير محسومة. احتفظنا بالطلب الأصلي؛ يمكنك التحقق به دون إنشاء تفويض آخر.', 'The outcome is unknown. The original request is retained; check it without creating another grant.'),
    denied: t('لم تسمح الصلاحية الحالية بالمتابعة. حدّث حالة النشاط قبل التحقق مجددًا. لا نستنتج نتيجة طلب سابق غير محسوم.', 'Current authority does not permit continuation. Refresh business authority before checking again. No earlier unknown outcome is inferred.'),
    rejected: t('لم تُقبل بيانات الطلب. افحص الأهلية وراجع الصلاحية والمدة ثم أكّد مجددًا.', 'The command fields were rejected. Check eligibility, permission and expiry, then confirm again.'),
    recorded: t('سُجل التفويض المحدد. الاستعمال يبقى مشروطًا بصلاحية السلسلة والعضوية والمدة، ولا يمنح حقوق مصادر أو بيانات.', 'The selected grant was recorded. Use remains conditional on its chain, membership and expiry; it grants no source or data rights.'),
    context: t('هذا المسار غير متاح في السياق المحدد. اختر تفويض نشاط آخر أو تكليف موظف ضمن التفويض المستلم.', 'This task is unavailable in the selected context. Delegate to another business or assign an employee within a received mandate.'),
    '': '',
})[draft.outcome]);
function concealOptions() { generation++; projection.value = undefined; loading.value = false; draft.confirmed = false; }
watch(() => [draft.mode, draft.agency, draft.recipient], () => { concealOptions(); if (!draft.pending) { draft.action = ''; draft.outcome = ''; } }, { flush: 'sync' });
watch(() => [props.active, props.refreshing, props.surface], () => {
    concealOptions();
    if (currentContext() && !props.refreshing) draft.blocked = false;
    if (!draft.pending && allowedModes.value.length === 1) {
        draft.mode = allowedModes.value[0];
        if (draft.mode === 'assign') draft.agency = props.surface.agency_task?.agency_business_public_id ?? '';
        if (draft.mode === 'assign' && props.recipientPublicId) draft.recipient = props.recipientPublicId;
    }
}, { flush: 'sync' });
watch(() => props.recipientPublicId, value => {
    concealOptions();
    if (!draft.pending && draft.mode === 'assign') draft.recipient = value ?? '';
}, { flush: 'sync' });
watch(() => [draft.action, draft.expires], () => { draft.confirmed = false; }, { flush: 'sync' });
onBeforeUnmount(() => { disposed = true; concealOptions(); });

async function inspect() {
    if (!canInspect.value) return;
    const attempt = ++generation, captured = stamp(), agency = agencyIdentity.value!, recipient = draft.mode === 'assign' ? draft.recipient.trim() : undefined;
    const effect = draft.mode;
    projection.value = undefined; draft.confirmed = false; draft.outcome = ''; loading.value = true;
    try {
        const result = await agencyOptions(business, access.value, agency, recipient);
        if (attempt !== generation || !owned(captured) || !currentContext() || props.refreshing) return;
        if (result.business_public_id !== business || result.agency_business_public_id.toLowerCase() !== agency.toLowerCase()
            || result.scope !== 'current_declaration_agency_only' || !uuid(result.recipient.public_id)
            || !Number.isSafeInteger(result.recipient.membership_grant_id) || result.recipient.membership_grant_id <= 0
            || result.options.length > 30 || result.options.some(option => !labels[option.action] || !Number.isSafeInteger(option.parent_grant_id) || option.parent_grant_id <= 0
                || option.authority_selection.realm_kind !== 'direct'
                || option.authority_selection.requirements.length !== 1 || option.authority_selection.requirements[0].requirement_kind !== 'business'
                || option.authority_selection.requirements[0].leaf_grant_id !== option.parent_grant_id
                || option.max_expires_at !== null && !Number.isFinite(Date.parse(option.max_expires_at)))) throw Error('Agency discovery identity mismatch');
        if (result.effect !== effect || recipient && result.recipient.public_id.toLowerCase() !== recipient.toLowerCase()) { draft.outcome = 'context'; return; }
        projection.value = result; draft.blocked = false;
        draft.action = result.options.length === 1 ? String(result.options[0].parent_grant_id) : '';
        if (!result.options.length) draft.outcome = 'empty';
    } catch (error) {
        if (attempt === generation && owned(captured) && currentContext()) draft.outcome = error instanceof TransportError && error.status === 403 ? 'denied' : 'unavailable';
    } finally { if (attempt === generation && currentContext()) loading.value = false; }
}
async function send() {
    if (!currentContext() || props.refreshing || busy.value || draft.blocked) return;
    if (!draft.pending) {
        if (!canSend.value || !projection.value || !selected.value || !expiry.value) return;
        draft.pending = structuredClone({ command_id: crypto.randomUUID(), effect: projection.value.effect,
            agency_business_public_id: projection.value.agency_business_public_id, recipient_public_id: projection.value.recipient.public_id,
            membership_grant_id: projection.value.recipient.membership_grant_id, action: selected.value.action as AgencyGrantCommand['action'],
            parent_grant_id: selected.value.parent_grant_id, expires_at: expiry.value, confirmed: true,
            authority_selection: JSON.parse(JSON.stringify(selected.value.authority_selection)),
        });
    }
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as AgencyGrantCommand, captured = stamp(), uncertain = draft.uncertain;
    draft.uncertain = true; busy.value = true; draft.confirmed = false; draft.outcome = '';
    try {
        const result = await writeAgencyGrant(business, frozen);
        if (!owned(captured) || !currentContext()) return;
        if (result.business_public_id !== business || result.agency_business_public_id !== frozen.agency_business_public_id
            || result.status !== 'active' || !Number.isSafeInteger(result.grant_id) || result.grant_id <= 0) throw Error('Agency grant acknowledgement mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.outcome = 'recorded'; concealOptions();
        if (draft.mode === 'assign' && props.recipientPublicId) draft.recipient = props.recipientPublicId;
        emit('refresh');
    } catch (error) {
        if (!owned(captured) || !currentContext()) return;
        if (error instanceof TransportError && [403, 409, 422].includes(error.status)) {
            if (!uncertain && [403, 422].includes(error.status)) { draft.pending = undefined; draft.uncertain = false; }
            draft.blocked = true; concealOptions(); draft.outcome = error.status === 422 && !uncertain ? 'rejected' : 'denied';
        } else draft.outcome = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section v-if="currentContext()" class="bf-card" data-business-agency-team :aria-busy="loading || busy">
        <h3>{{ t('التفويض بين الأنشطة وتكليف الموظفين', 'Business mandates and employee assignments') }}</h3>
        <p>{{ t('يمكن تفويض أي نشاط بعمل محدد، سواء كان متجرًا أو وكالة أو غير ذلك. يكلّف مسؤول النشاط المفوَّض موظفًا مؤهلًا بهذا العمل فقط، ضمن حدود التفويض.', 'Delegate to another business for a specific task, whether it is a shop, agency or another business. Its manager can assign an eligible employee to this work within the mandate.') }}</p>
        <p>{{ t('هذه الصلاحيات لمعلومات النشاط وأسواقه وأهدافه فقط. لا تفتح المصادر أو بيانات العملاء أو السجلات الخاصة، ولا تسمح بتنفيذ إعلانات.', 'These permissions cover business details, markets and goals only. They do not open sources, client data or private history, or authorize advertising execution.') }}</p>
        <fieldset v-if="!draft.pending && allowedModes.length" class="bf-form" :disabled="busy || loading || refreshing">
            <label v-if="allowedModes.length > 1">{{ t('العمل المطلوب', 'Task') }}<select v-model="draft.mode" data-agency-mode><option v-for="mode in allowedModes" :key="mode" :value="mode">{{ mode === 'mandate' ? t('تفويض نشاط آخر للعمل على نشاطي', 'Delegate to another business') : t('تكليف موظف بهذا النشاط المفوِّض', 'Assign an employee to this client business') }}</option></select></label>
            <p v-else data-agency-mode-label>{{ draft.mode === 'mandate' ? t('تفويض نشاط آخر للعمل على نشاطي', 'Delegate to another business') : t('تكليف موظف بهذا النشاط المفوِّض', 'Assign an employee to this client business') }}</p>
            <template v-if="draft.mode === 'assign' && agencyName"><p data-agency-known-business><strong>{{ agencyName }}</strong></p><details><summary>{{ t('تفاصيل النشاط', 'Business details') }}</summary><code>{{ draft.agency }}</code></details></template>
            <label v-else>{{ t('رابط النشاط', 'Business link') }}<input v-model="draft.agency" data-agency-business type="text" dir="ltr" maxlength="2048" autocomplete="off" :readonly="draft.mode === 'assign'" /></label>
            <p v-if="draft.mode === 'mandate'">{{ t('الصق رابط النشاط الذي يشاركه معك مسؤوله. يُستخدم الرابط لتحديد النشاط فقط؛ لا نفتح الرابط ولا نستنتج صلاحيات منه.', 'Paste the business link shared by its manager. The link identifies the business only; it is never opened and grants no authority.') }}</p>
            <template v-if="draft.mode === 'assign' && recipientPublicId && recipientName"><p data-agency-known-recipient><strong>{{ recipientName }}</strong></p><details><summary>{{ t('تفاصيل الموظف', 'Employee details') }}</summary><code>{{ draft.recipient }}</code></details></template>
            <label v-else-if="draft.mode === 'assign'">{{ t('معرّف حساب الموظف', 'Employee account identifier') }}<input v-model="draft.recipient" data-agency-recipient type="text" dir="ltr" maxlength="36" autocomplete="off" :readonly="!!recipientPublicId" /></label>
            <button type="button" class="workspace-button" data-agency-inspect :disabled="!canInspect" @click="inspect">{{ loading ? t('جارٍ الفحص…', 'Checking…') : t('فحص الصلاحيات المتاحة', 'Check available permissions') }}</button>
            <template v-if="projection?.options.length">
                <label>{{ t('الصلاحية المحددة', 'Specific permission') }}<select v-model="draft.action" data-agency-action><option value="">{{ t('اختر الصلاحية', 'Choose permission') }}</option><option v-for="option in projection.options" :key="option.parent_grant_id" :value="String(option.parent_grant_id)">{{ optionLabel(option) }}</option></select></label>
                <label>{{ t('ينتهي التفويض في — بتوقيت جهازك', 'Permission expires — your device timezone') }}<input v-model="draft.expires" data-agency-expiry type="datetime-local" required /></label>
                <p v-if="selected?.max_expires_at">{{ t('أقصى مدة يسمح بها التفويض الحالي', 'Current authority expires no later than') }}: <time>{{ selected.max_expires_at }}</time></p>
                <label class="bf-check"><input v-model="draft.confirmed" data-agency-confirm type="checkbox" />{{ t('أؤكد منح الصلاحية المحددة للنشاط والمستفيد المبيّنين حتى الموعد المحدد.', 'I confirm this permission for the specified business and recipient until the selected expiry.') }}</label>
            </template>
        </fieldset>
        <p v-if="draft.pending && recipientPublicId && draft.pending.recipient_public_id !== recipientPublicId" role="status" data-agency-original-recipient>{{ t('يوجد طلب غير محسوم لموظف آخر. تحقّق بالطلب الأصلي قبل تكليف الموظف المختار. المستفيد الأصلي:', 'An unresolved request belongs to another employee. Check the original request before assigning the selected employee. Original recipient:') }} <code>{{ draft.pending.recipient_public_id }}</code></p>
        <p v-if="!draft.pending && !allowedModes.length" role="status">{{ t('إنشاء تفويض جديد غير متاح في مسار وصولك الحالي.', 'A new mandate or assignment is unavailable through your current access path.') }}</p>
        <button v-if="draft.pending || projection?.options.length" type="button" class="workspace-button" data-agency-submit :disabled="busy || refreshing || draft.blocked || (!draft.pending && !canSend)" @click="send">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق بالطلب الأصلي', 'Check original request') : draft.mode === 'mandate' ? t('تأكيد تفويض النشاط', 'Confirm business mandate') : t('تأكيد تكليف الموظف', 'Confirm employee assignment') }}</button>
        <p v-if="notice" role="status" data-agency-status>{{ notice }}</p>
        <button v-if="draft.blocked" type="button" class="workspace-button" :disabled="busy || refreshing" data-agency-refresh @click="emit('refresh')">{{ t('تحديث حالة النشاط والصلاحيات', 'Refresh business authority') }}</button>
    </section>
</template>
