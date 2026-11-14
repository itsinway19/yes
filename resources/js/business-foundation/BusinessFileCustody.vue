<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { internalTeamSurface, writeInternalTeam, type BusinessTeam, type InternalTeamCommand, type InternalTeamSurface, type Surface } from './api';
import { internalTeamDrafts, key, type InternalTeamDraft } from './drafts';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';

const props = defineProps<{ surface: Surface; active: boolean; refreshing?: boolean; members: BusinessTeam['members'] }>();
const emit = defineEmits<{ refresh: []; pendingChange: [pending: boolean]; administration: [qualified: boolean] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id, basis = JSON.stringify(props.surface.authority_selection), admission = stamp();
const resource = `${key(business, props.surface.authority_selection)}:business-file-custody:2`;
const draft = reactive<InternalTeamDraft>(internalTeamDrafts.get(resource) ?? { recipient: '', confirmed: false, notice: '' });
draft.uncertain ||= !!draft.pending;
internalTeamDrafts.set(resource, draft);
type CustodySurface = InternalTeamSurface & { can_appoint: boolean; custodians: NonNullable<InternalTeamSurface['custodians']>; versions: NonNullable<InternalTeamSurface['versions']> };
const data = ref<CustodySurface>(), loading = ref(false), busy = ref(false), unavailable = ref(false);
const mode = ref<'appoint' | 'remove'>(draft.pending?.effect === 'remove' ? 'remove' : 'appoint');
let disposed = false, generation = 0;
const currentContext = () => !disposed && owned(admission) && props.active && !props.refreshing
    && props.surface.business.business_public_id === business && JSON.stringify(props.surface.authority_selection) === basis;
const editable = computed(() => currentContext() && !!data.value?.can_appoint && !busy.value && !loading.value && !unavailable.value);
const candidates = computed(() => props.members.filter(member => !data.value?.custodians.some(custodian => custodian.public_id === member.public_id)));
const selected = computed(() => (mode.value === 'appoint' ? candidates.value : data.value?.custodians ?? []).find(member => member.public_id === draft.recipient));
watch(() => !!draft.pending, pending => emit('pendingChange', pending), { immediate: true, flush: 'sync' });
watch(() => [draft.recipient, mode.value], () => { draft.confirmed = false; }, { flush: 'sync' });
function conceal() { generation++; data.value = undefined; loading.value = false; draft.confirmed = false; emit('administration', false); }
async function load() {
    if (!currentContext()) return;
    const attempt = ++generation, captured = stamp(); data.value = undefined; loading.value = true; unavailable.value = false; emit('administration', false);
    try {
        const result = await internalTeamSurface(business, Number(props.surface.authority_selection.requirements[0].leaf_grant_id));
        if (attempt !== generation || !owned(captured) || !currentContext()) return;
        if (result.business_public_id !== business || typeof result.can_appoint !== 'boolean' || typeof result.can_admin !== 'boolean'
            || !Array.isArray(result.custodians) || !result.versions || !Number.isSafeInteger(result.versions.authority_epoch) || result.versions.authority_epoch < 0
            || result.custodians.some(row => row.status !== 'recorded' || !row.public_id || typeof row.name !== 'string' || typeof row.email !== 'string')) throw Error('Custody projection mismatch');
        data.value = result as CustodySurface; emit('administration', result.can_appoint);
    } catch { if (attempt === generation && currentContext()) { unavailable.value = true; emit('administration', false); } }
    finally { if (attempt === generation && !disposed) loading.value = false; }
}
watch(() => [props.active, props.refreshing, props.surface], () => { conceal(); if (currentContext()) void load(); }, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { if (draft.pending) draft.uncertain = true; disposed = true; conceal(); });
function remove(recipient: string) { if (!editable.value || draft.pending) return; mode.value = 'remove'; draft.recipient = recipient; }
function appoint() { if (!editable.value || draft.pending) return; mode.value = 'appoint'; draft.recipient = ''; }
async function send() {
    if (!editable.value) return;
    if (!draft.pending) {
        if (!draft.confirmed || !selected.value || !data.value) return;
        draft.pending = { command_id: crypto.randomUUID(), effect: mode.value, recipient_public_id: selected.value.public_id,
            expected_revision: data.value.versions.authority_epoch, authority_selection: JSON.parse(basis) };
    }
    const command = JSON.parse(JSON.stringify(draft.pending)) as InternalTeamCommand, captured = stamp(), attempt = generation, uncertain = !!draft.uncertain;
    busy.value = true; draft.uncertain = true; draft.confirmed = false; draft.notice = '';
    try {
        const result = await writeInternalTeam(business, command);
        if (attempt !== generation || !owned(captured) || !currentContext()) return;
        if (result.business_public_id !== business || result.recipient_public_id !== command.recipient_public_id
            || result.state !== 'active' || !('authority_epoch' in result.versions)
            || !Number.isSafeInteger(result.versions.authority_epoch) || result.versions.authority_epoch < 0) throw Error('Custody acknowledgement mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.recipient = ''; mode.value = 'appoint';
        draft.notice = t('تم تأكيد العملية. نتحقق من المسؤوليات المسجلة حاليًا.', 'Operation confirmed. Checking the currently recorded responsibilities.');
        emit('refresh'); void load();
    } catch (error) {
        if (attempt !== generation || !owned(captured) || !currentContext()) return;
        const rejected = error instanceof TransportError && !uncertain && ([403, 422].includes(error.status)
            || error.status === 409 && error.body && 'error' in error.body && error.body.error === 'business_relation_revision_rejected');
        if (rejected) {
            draft.pending = undefined; draft.uncertain = false; conceal(); unavailable.value = true;
            draft.notice = t('رُفض الطلب. حدّث الصلاحيات والحالة ثم راجع الشخص المقصود.', 'The request was rejected. Refresh authority and state, then review the named person.');
        } else {
            draft.notice = t('لم تتأكد نتيجة الطلب. احتفظنا بالطلب الأصلي للتحقق؛ لا يُعاد تلقائيًا.', 'The outcome is unconfirmed. The original command is retained for checking and is never retried automatically.');
            if (error instanceof TransportError && error.status === 403) { conceal(); unavailable.value = true; emit('refresh'); }
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>

<template>
    <section v-if="currentContext()" class="workspace-task-editor" data-business-file-custody :aria-busy="loading || busy">
        <h3>{{ t('استمرارية حفظ النشاط', 'Activity custody continuity') }}</h3>
        <p>{{ t('عيّن شخصًا مسجلًا من موظفي النشاط لاستمرار إدارة معلومات النشاط، وهدفه الحالي، وأسواقه وأولوياته. تشمل المسؤولية قراءة المعلومات وتعديل هذه التصريحات الخمسة فقط؛ ولا تمنح مصادر أو سجلات خاصة أو تفويض وكالة.', 'Appoint a named Business employee to continue managing its current details, goal, markets and priorities. Responsibility covers only the five current declaration permissions; it grants no sources, private history or agency mandate.') }}</p>
        <p>{{ t('تبقى هذه المسؤولية مستقلة بعد إزالة صلاحيات الموظف العادية. اسحب مسؤولية الحفظ من هنا صراحةً عند الحاجة.', 'This responsibility remains independent after ordinary employee permissions are removed. Withdraw custodianship explicitly here when needed.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من مسؤوليات الحفظ…', 'Checking custody responsibilities…') }}</p>
        <p v-if="unavailable" role="alert" data-custody-unavailable>{{ t('تعذر التحقق من المسؤوليات الحالية؛ لا يعني ذلك عدم وجود مسؤول. حدّث الحالة والصلاحيات.', 'Current responsibilities could not be checked; this does not mean no custodian exists. Refresh state and authority.') }}</p>
        <button v-if="unavailable" type="button" class="workspace-button" data-custody-refresh :disabled="busy" @click="load">{{ t('تحديث الحالة', 'Refresh state') }}</button>
        <template v-if="data">
            <p v-if="!data.can_appoint">{{ t('تعيين مسؤول الحفظ متاح للمنشئ المؤهل أو لمسؤول نشاط مباشر عُيّن صراحةً.', 'Custodian appointment requires the qualified creator or an explicitly appointed direct Business custodian.') }}</p>
            <template v-else>
                <ul v-if="data.custodians.length" class="bf-issued-list"><li v-for="custodian in data.custodians" :key="custodian.qualification_ref" class="bf-issued">
                    <strong>{{ custodian.name }}</strong> <span dir="ltr">{{ custodian.email }}</span>
                    <button type="button" class="workspace-button" data-custody-remove :disabled="!editable || !!draft.pending" @click="remove(custodian.public_id)">{{ t('سحب مسؤولية الحفظ', 'Withdraw custodianship') }}</button>
                </li></ul>
                <p v-else data-custody-empty>{{ t('لا توجد مسؤوليات حفظ مباشرة مسجلة حاليًا.', 'No direct custodianships are currently recorded.') }}</p>
                <fieldset v-if="!draft.pending" class="bf-form" :disabled="!editable">
                    <label v-if="mode === 'appoint'">{{ t('الموظف المقصود', 'Named employee') }}<select v-model="draft.recipient" data-custody-recipient><option value="">{{ t('اختر موظفًا بالاسم', 'Choose an employee by name') }}</option><option v-for="member in candidates" :key="member.public_id" :value="member.public_id">{{ member.name }} — {{ member.email }}</option></select></label>
                    <p v-if="mode === 'appoint' && !candidates.length">{{ t('أضف الموظف وتحقق من حسابه في قسم الموظفين أولًا.', 'Add and verify the employee in Business employees first.') }}</p>
                    <p v-if="selected" data-custody-named><strong>{{ selected.name }}</strong> <span dir="ltr">{{ selected.email }}</span></p>
                    <label v-if="selected" class="bf-check"><input v-model="draft.confirmed" type="checkbox" data-custody-confirm />{{ mode === 'remove' ? t('أؤكد سحب مسؤولية الحفظ المستقلة من ' + selected.name + ' في هذا النشاط.', 'Confirm withdrawing ' + selected.name + '’s independent custodianship in this Business.') : t('أؤكد تعيين ' + selected.name + ' مسؤولًا مستقلًا لاستمرارية حفظ هذا النشاط ضمن الصلاحيات المعروضة.', 'Confirm appointing ' + selected.name + ' as an independent custodian of this Business within the displayed permissions.') }}</label>
                    <button v-if="mode === 'remove'" type="button" class="workspace-button" @click="appoint">{{ t('العودة إلى التعيين', 'Back to appointment') }}</button>
                </fieldset>
            </template>
        </template>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
        <p v-if="draft.pending" data-custody-pending>{{ t('يوجد طلب غير محسوم. تحقّق بالطلب الأصلي قبل أي عملية أخرى.', 'A command is unresolved. Check the original request before another operation.') }}</p>
        <button v-if="draft.pending || data?.can_appoint" type="button" class="workspace-button" data-custody-save :disabled="!editable || (!draft.pending && (!selected || !draft.confirmed))" @click="send">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق بالطلب الأصلي', 'Check original request') : mode === 'remove' ? t('تأكيد سحب المسؤولية', 'Confirm withdrawal') : t('تعيين مسؤول الحفظ', 'Appoint custodian') }}</button>
    </section>
</template>
