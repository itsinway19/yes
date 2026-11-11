<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { businessTeam, businessTeamRecipient, writeBusinessTeam, type BusinessTeam, type BusinessTeamCommand, type Surface } from './api';
import { agencyTeamDrafts, delegationDrafts, key, teamMembersDrafts, type TeamMembersDraft, type EmployeeAssignmentReference } from './drafts';
import BusinessEmployeeAssignments from './BusinessEmployeeAssignments.vue';
import BusinessFileCustody from './BusinessFileCustody.vue';

const props = defineProps<{ surface: Surface; active: boolean; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id, basis = JSON.stringify(props.surface.authority_selection), admission = stamp();
const resource = key(business, props.surface.authority_selection);
const draft = reactive<TeamMembersDraft>(teamMembersDrafts.get(resource) ?? { mode: '', email: '', recipient_public_id: '', permissions: [], expected: [],
    expiryMode: 'indefinite', expires: '', confirmed: false, uncertain: false, blocked: false, outcome: '' });
draft.uncertain ||= !!draft.pending;
if (draft.pending && !draft.outcome) draft.outcome = 'unknown';
teamMembersDrafts.set(resource, draft);
type Member = BusinessTeam['members'][number];
type Recipient = Pick<Member, 'public_id' | 'name' | 'email'>;
const roster = ref<BusinessTeam>(), recipient = ref<Recipient>();
const assignmentRecipient = ref<Recipient>(), assignmentSignal = ref(false);
const storedAssignmentPending = () => !!(draft.assignment && (agencyTeamDrafts.get(draft.assignment.resource)?.pending || delegationDrafts.get(`${draft.assignment.resource}:delegations:1`)?.pending));
const assignmentPending = computed(() => assignmentSignal.value || storedAssignmentPending());
if (draft.assignment && storedAssignmentPending()) assignmentRecipient.value = { public_id: draft.assignment.employee, name: '', email: '' };
const loading = ref(false), resolving = ref(false), busy = ref(false);
let disposed = false, readGeneration = 0, lookupGeneration = 0;
const currentContext = () => !disposed && owned(admission) && props.active && props.surface.business.business_public_id === business
    && JSON.stringify(props.surface.authority_selection) === basis;
const custodyAdministrator = ref(false), custodyPending = ref(false);
const manageable = computed(() => props.surface.agency_task?.can_mandate === true || custodyAdministrator.value);
function qualifyCustody(qualified: boolean) { custodyAdministrator.value = qualified; }
watch(manageable, qualified => { conceal(); if (qualified && currentContext() && !props.refreshing) void load(); });
const access = computed(() => Number(props.surface.authority_selection.requirements.find(row => row.requirement_kind === 'business')?.leaf_grant_id));
const labels: Record<string, [string, string]> = {
    'business.context.read': ['قراءة معلومات النشاط', 'Read business details'],
    'business.context.manage': ['تعديل معلومات النشاط', 'Edit business details'],
    'business.intent.declare': ['تعديل الهدف الحالي', 'Edit current goal'],
    'business.markets.declare': ['تعديل الأسواق المستهدفة', 'Edit target markets'],
    'business.preferences.manage': ['تعديل أولويات المعلن', 'Edit advertiser preferences'],
};
const label = (action: string) => labels[action] ? t(...labels[action]) : action;
const availableActions = computed(() => roster.value?.actions.filter(action => !!labels[action]) ?? []);
const editable = computed(() => currentContext() && manageable.value && !props.refreshing && !loading.value && !resolving.value && !busy.value && !draft.pending && !draft.blocked && !assignmentPending.value && !custodyPending.value);
const expiry = computed(() => draft.expiryMode === 'indefinite' ? null : draft.expires && Number.isFinite(Date.parse(draft.expires)) ? new Date(draft.expires).toISOString() : undefined);
const valid = computed(() => editable.value && !!recipient.value && draft.confirmed && (draft.mode === 'remove'
    || draft.permissions.length > 0 && draft.permissions.includes('business.context.read') && draft.permissions.every(action => availableActions.value.some(available => available === action))
        && draft.expiryMode !== 'unreviewed' && expiry.value !== undefined && (expiry.value === null || Date.parse(expiry.value) > Date.now())));
const notice = computed(() => ({
    unavailable: t('تعذر التحقق من فريق النشاط. أعد التحديث؛ لا يعني هذا عدم وجود موظفين.', 'The Business team could not be checked. Refresh it; this does not mean there are no employees.'),
    recipient_unavailable: t('تعذر تأهيل هذا البريد. يلزم حساب مسجل وبريد مؤكد وحالة حساب صالحة. اطلب من الموظف إتمام التسجيل والتأكيد؛ لم نرسل دعوة.', 'This email could not be qualified. A registered, verified and current account is required. Ask the employee to complete registration and verification; no invitation was sent.'),
    unknown: t('نتيجة الطلب غير محسومة. احتفظنا بالطلب الأصلي للتحقق دون إنشاء صلاحيات أخرى.', 'The outcome is unknown. The original command is retained for checking without creating other permissions.'),
    denied: t('توقفت أهلية إدارة الفريق. حدّث صلاحيات النشاط قبل المتابعة. لا نستنتج نتيجة طلب سابق غير محسوم.', 'Team administration is no longer qualified. Refresh Business authority before continuing. No prior unknown outcome is inferred.'),
    rejected: t('راجع الموظف والصلاحيات والمدة من الحالة الحالية، ثم أكّد الطلب مجددًا.', 'Review the employee, permissions and expiry against current state, then confirm again.'),
    recorded: t('سُجل تحديث صلاحيات الموظف لهذا النشاط. الحقوق المستقلة وحقوق المصادر والبيانات لا تتغير.', 'The employee permissions for this Business were recorded. Independent rights and source/data rights remain unchanged.'),
    '': '',
})[draft.outcome]);
function conceal() {
    readGeneration++; lookupGeneration++; roster.value = undefined; recipient.value = undefined; loading.value = false; resolving.value = false; draft.confirmed = false;
    assignmentRecipient.value = draft.assignment && storedAssignmentPending() ? { public_id: draft.assignment.employee, name: '', email: '' } : undefined;
}
const expected = (member: Member) => member.grants.map(grant => ({ grant_id: grant.grant_id, version: grant.version }));
const expectedKey = (values: { grant_id: string; version: number }[]) => JSON.stringify([...values].sort((a, b) => a.grant_id.localeCompare(b.grant_id)));
async function load(after?: number) {
    if (!currentContext() || !manageable.value || props.refreshing || !Number.isSafeInteger(access.value) || access.value < 1) return;
    const attempt = ++readGeneration, captured = stamp(); loading.value = true; if (!draft.pending) draft.outcome = '';
    if (!after) { roster.value = undefined; recipient.value = undefined; }
    try {
        const result = await businessTeam(business, access.value, after);
        if (attempt !== readGeneration || !owned(captured) || !currentContext() || !manageable.value || props.refreshing) return;
        if (result.business_public_id !== business || result.actions.some(action => !labels[action])
            || result.next_cursor !== null && (!Number.isSafeInteger(result.next_cursor) || result.next_cursor < 1)
            || result.members.some(member => member.grants.some(grant => !labels[grant.action] || !/^[1-9][0-9]{0,18}$/.test(grant.grant_id) || !Number.isSafeInteger(grant.version) || grant.version < 1
                || grant.expires_at !== null && !Number.isFinite(Date.parse(grant.expires_at))))) throw Error('Team projection mismatch');
        roster.value = after && roster.value ? { ...result, members: [...roster.value.members, ...result.members] } : result;
        if (draft.assignment && storedAssignmentPending()) {
            assignmentRecipient.value = roster.value.members.find(row => row.public_id === draft.assignment?.employee) ?? { public_id: draft.assignment.employee, name: '', email: '' };
        }
        if (!draft.pending && draft.recipient_public_id && draft.mode !== 'add') {
            const current = roster.value.members.find(row => row.public_id === draft.recipient_public_id);
            if (current && expectedKey(expected(current)) === expectedKey(draft.expected)) recipient.value = current;
        }
    } catch (error) {
        if (attempt === readGeneration && owned(captured) && currentContext()) {
            roster.value = undefined; recipient.value = undefined;
            draft.outcome = error instanceof TransportError && error.status === 403 ? 'denied' : 'unavailable';
        }
    } finally { if (attempt === readGeneration && !disposed) loading.value = false; }
}
watch(() => [props.active, props.refreshing, props.surface], () => {
    conceal();
    if (currentContext() && !props.refreshing) { draft.blocked = false; if (manageable.value) void load(); }
}, { immediate: true, flush: 'sync' });
watch(() => [draft.permissions.join('|'), draft.expiryMode, draft.expires], () => { draft.confirmed = false; }, { flush: 'sync' });
watch(() => draft.email, () => { if (draft.mode === 'add') { lookupGeneration++; resolving.value = false; recipient.value = undefined; draft.recipient_public_id = ''; draft.confirmed = false; } }, { flush: 'sync' });
onBeforeUnmount(() => { disposed = true; conceal(); });
function startAdd() {
    if (!editable.value || !roster.value) return;
    assignmentRecipient.value = undefined;
    recipient.value = undefined; Object.assign(draft, { mode: 'add', email: '', recipient_public_id: '', permissions: ['business.context.read'],
        expected: [], expiryMode: 'indefinite', expires: '', confirmed: false, outcome: '' });
}
function edit(member: Member, remove = false) {
    if (!editable.value) return;
    assignmentRecipient.value = undefined;
    const ends = [...new Set(member.grants.map(grant => grant.expires_at))];
    const common = ends.length === 1 ? ends[0] : undefined;
    const local = common ? new Date(Date.parse(common) - new Date(common).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '';
    Object.assign(draft, { mode: remove ? 'remove' : 'edit', email: member.email, recipient_public_id: member.public_id,
        permissions: remove ? [] : [...new Set(member.grants.map(grant => grant.action))], expected: expected(member),
        expiryMode: common === undefined ? 'unreviewed' : common === null ? 'indefinite' : 'until', expires: local, confirmed: false, outcome: '' });
    recipient.value = member;
}
async function lookup() {
    if (!editable.value || draft.mode !== 'add' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.email.trim())) return;
    const attempt = ++lookupGeneration, captured = stamp(), email = draft.email.trim(); resolving.value = true; recipient.value = undefined; draft.confirmed = false;
    try {
        const result = await businessTeamRecipient(business, { access: access.value, email });
        if (attempt !== lookupGeneration || !owned(captured) || !currentContext() || !manageable.value || props.refreshing) return;
        if (result.business_public_id !== business || result.recipient.email.toLowerCase() !== email.toLowerCase()) throw Error('Recipient projection mismatch');
        const existing = roster.value?.members.find(member => member.public_id === result.recipient.public_id);
        if (existing) { resolving.value = false; edit(existing); return; }
        recipient.value = result.recipient; draft.recipient_public_id = result.recipient.public_id; draft.outcome = '';
    } catch (error) {
        if (attempt === lookupGeneration && owned(captured) && currentContext()) draft.outcome = error instanceof TransportError && [403, 422].includes(error.status) ? 'recipient_unavailable' : 'unavailable';
    } finally { if (attempt === lookupGeneration && !disposed) resolving.value = false; }
}
function cancel() {
    if (!editable.value) return;
    recipient.value = undefined; Object.assign(draft, { mode: '', email: '', recipient_public_id: '', expected: [], permissions: [], confirmed: false, outcome: '' });
}
function assignClients(member: Member) { if (editable.value && props.surface.agency_task?.can_mandate === true) { assignmentRecipient.value = member; draft.mode = ''; recipient.value = undefined; } }
function rememberAssignment(value: EmployeeAssignmentReference) { draft.assignment = value; }
function assignmentChanged(value: boolean) { assignmentSignal.value = value; }
async function send() {
    if (!currentContext() || props.refreshing || busy.value || draft.blocked || custodyPending.value) return;
    if (!draft.pending) {
        if (!valid.value || !recipient.value) return;
        draft.pending = { command_id: crypto.randomUUID(), authority_selection: JSON.parse(basis), recipient_public_id: recipient.value.public_id,
            expected_grants: JSON.parse(JSON.stringify(draft.expected)), permissions: (draft.mode === 'remove' ? [] : [...draft.permissions]) as BusinessTeamCommand['permissions'],
            expires_at: draft.mode === 'remove' ? null : expiry.value ?? null, confirmed: true };
    }
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as BusinessTeamCommand, captured = stamp(), uncertain = draft.uncertain;
    draft.uncertain = true; draft.confirmed = false; busy.value = true; draft.outcome = '';
    try {
        const result = await writeBusinessTeam(business, frozen);
        if (!owned(captured) || !currentContext()) return;
        if (result.business_public_id !== business || result.recipient_public_id !== frozen.recipient_public_id || result.status !== 'recorded'
            || !Number.isSafeInteger(result.versions.authority_epoch) || result.versions.authority_epoch < 0) throw Error('Team acknowledgement mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.mode = ''; draft.outcome = 'recorded'; conceal(); emit('refresh');
    } catch (error) {
        if (!owned(captured) || !currentContext()) return;
        if (error instanceof TransportError && [403, 409, 422].includes(error.status)) {
            if (!uncertain && [403, 422].includes(error.status)) { draft.pending = undefined; draft.uncertain = false; }
            draft.blocked = true; conceal(); draft.outcome = error.status === 422 && !uncertain ? 'rejected' : 'denied';
        } else draft.outcome = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section v-if="currentContext()" class="bf-card" data-business-team-members :aria-busy="loading || resolving || busy">
        <h3>{{ t('موظفو النشاط', 'Business employees') }}</h3>
        <p>{{ t('أضف موظفًا بحسابه المسجل وحدد ما يستطيع فعله في هذا النشاط. صلاحياته على الأنشطة الأخرى والمصادر والبيانات تبقى مستقلة.', 'Add an employee using their registered account and choose what they can do in this Business. Their rights to other businesses, sources and data remain independent.') }}</p>
        <p>{{ t('تظهر هنا صلاحيات الفريق المسجلة. يُتحقق من سريانها ومدتها عند كل استعمال.', 'These are recorded team permissions. Their validity and expiry are checked whenever they are used.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من الموظفين…', 'Checking employees…') }}</p>
        <BusinessFileCustody :surface="surface" :active="active" :refreshing="refreshing || busy || !!draft.pending || assignmentPending" :members="roster?.members ?? []"
            @administration="qualifyCustody" @pending-change="custodyPending = $event" @refresh="emit('refresh')" />
        <template v-if="roster">
            <ul v-if="roster.members.length" class="bf-issued-list" data-team-list>
                <li v-for="member in roster.members" :key="member.public_id" class="bf-issued">
                    <strong>{{ member.name }}</strong><span dir="ltr">{{ member.email }}</span>
                    <p>{{ [...new Set(member.grants.map(grant => grant.action))].map(label).join(' · ') }}</p>
                    <button type="button" class="workspace-button" data-team-edit :disabled="!editable" @click="edit(member)">{{ t('تعديل الصلاحيات', 'Edit permissions') }}</button>
                    <button type="button" class="workspace-button" data-team-remove :disabled="!editable" @click="edit(member, true)">{{ t('إزالة صلاحياته هنا', 'Remove permissions here') }}</button>
                    <button type="button" v-if="surface.agency_task?.can_mandate === true" class="workspace-button" data-team-assign-clients :disabled="!editable" @click="assignClients(member)">{{ t('إسناد أنشطة العملاء', 'Assign client businesses') }}</button>
                </li>
            </ul>
            <p v-else data-team-empty>{{ t('لا موظفين ظاهرين في هذه الصفحة.', 'No employees are shown on this page.') }}</p>
            <button v-if="roster.next_cursor" type="button" class="workspace-button" :disabled="loading || !!draft.pending" @click="load(roster.next_cursor)">{{ t('عرض المزيد', 'Show more') }}</button>
            <button v-if="!draft.mode && !draft.pending" type="button" class="workspace-button" data-team-add :disabled="!editable" @click="startAdd">{{ t('إضافة موظف', 'Add employee') }}</button>
        </template>
        <section v-if="assignmentRecipient" class="workspace-task-editor" data-team-client-assignments>
            <header class="business-task-editor-heading"><h4>{{ assignmentRecipient.name ? t('تكليف أنشطة العملاء لـ', 'Client assignments for') + ' ' + assignmentRecipient.name : t('طلب تكليف موظف محفوظ', 'Retained employee assignment request') }}</h4>
                <button type="button" class="workspace-button" data-team-close-assignments :disabled="assignmentPending" @click="assignmentRecipient = undefined">{{ t('العودة إلى الموظفين', 'Back to employees') }}</button></header>
            <BusinessEmployeeAssignments :key="assignmentRecipient.public_id" :business="business" :employee="assignmentRecipient.public_id" :employee-name="assignmentRecipient.name" :business-name="surface.business.name" :active="active" :refreshing="refreshing" :remembered="draft.assignment"
                @remember="rememberAssignment" @pending-change="assignmentChanged" />
        </section>
        <fieldset v-if="draft.mode && !draft.pending && manageable" class="bf-form" :disabled="busy || resolving || refreshing || loading || draft.blocked">
            <h4>{{ draft.mode === 'add' ? t('إضافة موظف', 'Add employee') : draft.mode === 'edit' ? t('تعديل صلاحيات الموظف', 'Edit employee permissions') : t('إزالة صلاحيات الموظف من هذا النشاط', 'Remove employee permissions from this Business') }}</h4>
            <template v-if="draft.mode === 'add'">
                <label>{{ t('البريد المسجل للموظف', 'Employee registered email') }}<input v-model="draft.email" data-team-email type="email" dir="ltr" autocomplete="off" /></label>
                <button type="button" class="workspace-button" data-team-lookup :disabled="!editable" @click="lookup">{{ resolving ? t('جارٍ التحقق…', 'Checking…') : t('التحقق من الحساب', 'Check account') }}</button>
            </template>
            <template v-if="recipient">
                <p data-team-recipient><strong>{{ recipient.name }}</strong> <span dir="ltr">{{ recipient.email }}</span></p>
                <template v-if="draft.mode !== 'remove'">
                    <fieldset><legend>{{ t('صلاحياته في هذا النشاط', 'Permissions in this Business') }}</legend>
                        <label v-for="action in availableActions" :key="action" class="bf-check"><input v-model="draft.permissions" data-team-permissions type="checkbox" :value="action" />{{ label(action) }}</label>
                    </fieldset>
                    <p>{{ t('يلزم حق القراءة مع أي صلاحيات أخرى. للسحب الكامل استخدم إزالة صلاحيات الموظف.', 'Reading is required with other permissions. Use employee removal to withdraw all direct team permissions.') }}</p>
                    <label>{{ t('مدة الصلاحيات', 'Permission duration') }}<select v-model="draft.expiryMode" data-team-expiry-mode><option v-if="draft.expiryMode === 'unreviewed'" value="unreviewed">{{ t('المدد الحالية مختلفة — اختر مدة مشتركة', 'Existing expiries differ — choose a common duration') }}</option><option value="indefinite">{{ t('حتى سحبها', 'Until withdrawn') }}</option><option value="until">{{ t('حتى موعد محدد', 'Until a specified time') }}</option></select></label>
                    <label v-if="draft.expiryMode === 'until'">{{ t('تنتهي في — بتوقيت جهازك', 'Expires — your device timezone') }}<input v-model="draft.expires" data-team-expiry type="datetime-local" /></label>
                </template>
                <p v-else>{{ t('ستُسحب جميع صلاحيات فريق هذا النشاط المحددة لهذا الموظف. لا تُسحب حقوقه المستقلة أو تفويضاته الأخرى.', 'All returned direct team permissions for this employee in this Business will be withdrawn. Independent rights and other mandates remain intact.') }}</p>
                <label class="bf-check"><input v-model="draft.confirmed" data-team-confirm type="checkbox" />{{ draft.mode === 'remove' ? t('أؤكد إزالة صلاحيات فريق هذا النشاط فقط.', 'Confirm removal of this Business team’s permissions only.') : t('أؤكد تطبيق الصلاحيات والمدة المعروضة على هذا الموظف في هذا النشاط.', 'Confirm the displayed permissions and duration for this employee in this Business.') }}</label>
            </template>
            <button type="button" class="workspace-button" :disabled="!editable" @click="cancel">{{ t('إلغاء', 'Cancel') }}</button>
        </fieldset>
        <button v-if="draft.pending || recipient" type="button" class="workspace-button" data-team-save :disabled="busy || refreshing || custodyPending || draft.blocked || (!draft.pending && !valid)" @click="send">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق بالطلب الأصلي', 'Check original request') : draft.mode === 'remove' ? t('تأكيد الإزالة', 'Confirm removal') : t('حفظ صلاحيات الموظف', 'Save employee permissions') }}</button>
        <p v-if="notice" role="status">{{ notice }}</p>
        <button v-if="draft.blocked || draft.outcome === 'unavailable' || draft.outcome === 'denied'" type="button" class="workspace-button" :disabled="busy || refreshing" @click="emit('refresh')">{{ t('تحديث الفريق والصلاحيات', 'Refresh team and authority') }}</button>
    </section>
</template>
