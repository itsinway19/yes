<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { businessChoices, businessSurface, type Choices, type Surface } from './api';
import { agencyTeamDrafts, delegationDrafts, key, type EmployeeAssignmentReference } from './drafts';
import BusinessAgencyTeam from './BusinessAgencyTeam.vue';
import BusinessDelegation from './BusinessDelegation.vue';

const props = defineProps<{ business: string; employee: string; employeeName?: string; businessName?: string; active: boolean; refreshing: boolean; remembered?: EmployeeAssignmentReference }>();
const emit = defineEmits<{ remember: [reference: EmployeeAssignmentReference]; pendingChange: [pending: boolean] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.business, employee = props.employee, admission = stamp();
const choices = ref<Choices['items']>([]), nextCursor = ref<string | null>(null), selected = ref('');
const client = ref<Surface>(), reference = ref<EmployeeAssignmentReference>();
const loading = ref(false), readingClient = ref(false), agencySignal = ref(false), delegationSignal = ref(false);
const listUnavailable = ref(false), clientUnavailable = ref(false);
let disposed = false, listGeneration = 0, clientGeneration = 0;
const currentContext = () => !disposed && owned(admission) && props.active && props.business === business && props.employee === employee;
const pointer = () => reference.value ?? props.remembered;
const pendingAgency = () => { const current = pointer(); return current ? agencyTeamDrafts.get(current.resource)?.pending : undefined; };
const pendingDelegation = () => { const current = pointer(); return current ? delegationDrafts.get(`${current.resource}:delegations:1`)?.pending : undefined; };
const pendingCommand = () => pendingAgency() ?? pendingDelegation();
const pendingTarget = () => pendingAgency()?.recipient_public_id ?? pendingDelegation()?.grantee_public_id;
const agencyPending = computed(() => agencySignal.value || !!pendingAgency());
const delegationPending = computed(() => delegationSignal.value || !!pendingDelegation());
const hasPending = computed(() => agencyPending.value || delegationPending.value);
watch(hasPending, value => emit('pendingChange', value), { immediate: true, flush: 'sync' });
const reviewSurface = computed<Surface | undefined>(() => client.value ? { ...client.value, delegation: { ...client.value.delegation,
    issued: client.value.delegation.issued.filter(row => row.recipient_public_id === employee && row.agency_business_public_id === business),
} } : undefined);
const choiceKey = (choice: Choices['items'][number]) => JSON.stringify([choice.business_public_id, choice.read_grant_id]);
function choiceLabel(choice: Choices['items'][number]) {
    const matches = choices.value.filter(row => row.business_public_id === choice.business_public_id);
    return choice.name + (matches.length > 1 ? ` · ${t('مسار تفويض', 'Mandate path')} ${matches.findIndex(row => row.read_grant_id === choice.read_grant_id) + 1}` : '');
}
function conceal() { listGeneration++; clientGeneration++; choices.value = []; nextCursor.value = null; client.value = undefined; loading.value = false; readingClient.value = false; }
async function load(more = false) {
    if (!currentContext() || props.refreshing || loading.value || more && !nextCursor.value) return;
    const after = more ? Number(nextCursor.value) : undefined;
    if (after !== undefined && (!Number.isSafeInteger(after) || after < 1)) { listUnavailable.value = true; return; }
    const attempt = ++listGeneration, captured = stamp(); loading.value = true; listUnavailable.value = false;
    try {
        const result = await businessChoices(after);
        if (attempt !== listGeneration || !owned(captured) || !currentContext() || props.refreshing) return;
        const eligible = result.items.filter(item => item.agency_business_public_id === business && item.business_public_id !== business);
        const byPath = new Map((more ? [...choices.value, ...eligible] : eligible).map(item => [choiceKey(item), item]));
        choices.value = [...byPath.values()]; nextCursor.value = result.next_cursor;
    } catch {
        if (attempt === listGeneration && owned(captured) && currentContext()) { choices.value = []; listUnavailable.value = true; }
    } finally { if (attempt === listGeneration && !disposed) loading.value = false; }
}
async function loadClient(target: Pick<EmployeeAssignmentReference, 'business' | 'readGrant'>) {
    if (!currentContext() || props.refreshing) return;
    const old = pointer();
    if (pendingCommand() && old && (old.business !== target.business || old.readGrant !== target.readGrant)) return;
    const access = Number(target.readGrant);
    if (!Number.isSafeInteger(access) || access < 1) { clientUnavailable.value = true; return; }
    const attempt = ++clientGeneration, captured = stamp(); client.value = undefined; readingClient.value = true; clientUnavailable.value = false;
    try {
        const result = await businessSurface(target.business, access);
        if (attempt !== clientGeneration || !owned(captured) || !currentContext() || props.refreshing) return;
        if (result.business.business_public_id !== target.business || result.agency_task?.can_assign !== true
            || result.agency_task.agency_business_public_id !== business
            || result.authority_selection.requirements.length !== 1
            || result.authority_selection.requirements[0].requirement_kind !== 'business'
            || String(result.authority_selection.requirements[0].leaf_grant_id) !== target.readGrant) throw Error('Qualified client assignment context unavailable');
        const resource = key(target.business, result.authority_selection);
        const pending = agencyTeamDrafts.get(resource)?.pending;
        const withdrawing = delegationDrafts.get(`${resource}:delegations:1`)?.pending;
        reference.value = { business: target.business, readGrant: target.readGrant, resource, employee: pending?.recipient_public_id ?? withdrawing?.grantee_public_id ?? employee };
        selected.value = JSON.stringify([target.business, target.readGrant]);
        emit('remember', { ...reference.value }); client.value = result;
    } catch {
        if (attempt === clientGeneration && owned(captured) && currentContext()) { client.value = undefined; clientUnavailable.value = true; }
    } finally { if (attempt === clientGeneration && !disposed) readingClient.value = false; }
}
function selectClient(value: string) {
    if (hasPending.value || readingClient.value) return;
    const choice = choices.value.find(row => choiceKey(row) === value);
    if (!choice) { selected.value = ''; client.value = undefined; return; }
    selected.value = value; void loadClient({ business: choice.business_public_id, readGrant: choice.read_grant_id });
}
const selectedValue = computed({ get: () => selected.value, set: selectClient });
function refreshClient() { const current = pointer(); if (current) void loadClient(current); }
function agencyChanged(value: boolean) { agencySignal.value = value; }
function delegationChanged(value: boolean) { delegationSignal.value = value; }
watch(() => [props.active, props.refreshing, props.business, props.employee], () => {
    conceal();
    if (currentContext() && !props.refreshing) {
        void load();
        if (props.remembered && (agencyTeamDrafts.get(props.remembered.resource)?.pending || delegationDrafts.get(`${props.remembered.resource}:delegations:1`)?.pending)) {
            reference.value = { ...props.remembered }; void loadClient(props.remembered);
        }
    }
}, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { disposed = true; conceal(); });
</script>
<template>
    <section v-if="currentContext()" class="workspace-task-editor" data-business-employee-assignments :aria-busy="loading || readingClient">
        <h4>{{ t('إسناد أنشطة العملاء إلى الموظف', 'Assign client businesses to the employee') }}</h4>
        <p>{{ t('اختر نشاط عميل فوّض نشاطك، ثم حدد عمل الموظف ومدته. هذه صلاحيات معلومات النشاط وأسواقه وأهدافه فقط؛ المصادر والبيانات الخاصة وتنفيذ الإعلانات تحتاج حقوقًا مستقلة.', 'Choose a client that has authorized your Business, then select the employee’s task and duration. This covers Business details, markets and goals only; sources, private data and advertising execution require separate authority.') }}</p>
        <label>{{ t('نشاط العميل', 'Client business') }}<select v-model="selectedValue" data-employee-client :disabled="loading || readingClient || hasPending || refreshing"><option value="">{{ t('اختر نشاط العميل المؤهل', 'Choose a qualified client business') }}</option><option v-for="choice in choices" :key="choiceKey(choice)" :value="choiceKey(choice)">{{ choiceLabel(choice) }}</option></select></label>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من أنشطة العملاء…', 'Checking client businesses…') }}</p>
        <p v-else-if="!choices.length && !listUnavailable">{{ nextCursor ? t('لا أنشطة مؤهلة ظاهرة في هذه الصفحة؛ قد توجد في الصفحات التالية.', 'No qualified clients appear on this page; later pages may contain clients.') : t('لا تظهر أنشطة عملاء بتفويض مؤهل لنشاطك حاليًا.', 'No client businesses with a qualified mandate to your Business are currently visible.') }}</p>
        <p v-if="listUnavailable" role="status">{{ t('تعذر التحقق من القائمة؛ لا يعني هذا عدم وجود تفويضات.', 'The list could not be checked; this does not mean no mandates exist.') }}</p>
        <button v-if="listUnavailable" type="button" class="workspace-button" @click="load()">{{ t('تحديث الأنشطة', 'Refresh businesses') }}</button>
        <button v-if="nextCursor" type="button" class="workspace-button" data-employee-clients-more :disabled="loading || readingClient || refreshing" @click="load(true)">{{ t('عرض المزيد من الأنشطة', 'Show more businesses') }}</button>
        <p v-if="readingClient" role="status">{{ t('جارٍ إعادة تأهيل الوصول إلى العميل…', 'Rechecking client access…') }}</p>
        <p v-if="clientUnavailable" role="status">{{ t('تكليف الموظف غير متاح بهذا المسار حاليًا. لم نفترض صلاحية بديلة أو نتيجة طلب محفوظ.', 'Employee assignment is unavailable through this path. No replacement authority or saved-command outcome is inferred.') }}</p>
        <p v-if="hasPending" role="status" data-employee-assignment-pending>{{ t('يوجد طلب تكليف غير محسوم للعميل المحدد. لا ننشئ تكليفًا بديلًا قبل التحقق بالطلب الأصلي.', 'The selected client has an unresolved assignment request. No replacement is created before checking the original request.') }}</p>
        <p v-if="hasPending && pendingTarget() && pendingTarget() !== employee" role="status" data-employee-original-recipient>{{ t('الطلب الأصلي يخص موظفًا آخر. يبقى التحقق للمستفيد الأصلي دون استبداله:', 'The original request concerns another employee. Recovery retains the original recipient without replacement:') }} <code>{{ pendingTarget() }}</code></p>
        <button v-if="clientUnavailable && pointer()" type="button" class="workspace-button" :disabled="readingClient || refreshing" @click="refreshClient">{{ t('إعادة فحص وصول العميل', 'Recheck client access') }}</button>
        <template v-if="client && reference">
            <h4>{{ client.business.name }}</h4>
            <BusinessAgencyTeam v-if="!delegationPending || agencyPending" :key="reference.resource" :surface="client" :recipient-public-id="employee" :recipient-name="employeeName" :agency-name="businessName" :active="active && !readingClient" :refreshing="refreshing || (delegationPending && !agencyPending)"
                @refresh="refreshClient" @pending-change="agencyChanged" />
            <section v-if="reviewSurface" data-employee-client-permissions>
                <h4>{{ t('صلاحيات هذا الموظف على العميل', 'This employee’s permissions for the client') }}</h4>
                <p>{{ t('تظهر فقط الحقوق المعروضة لهذا الموظف عبر نشاطك. الحقوق المستقلة أو حقوق الموظفين الآخرين خارج هذه المراجعة. قد تكون قائمة الحقوق محدودة.', 'Only displayed rights for this employee through your Business appear here. Independent rights and other employees’ rights are outside this review. The displayed inventory may be limited.') }}</p>
                <BusinessDelegation :surface="reviewSurface" :show-issuance="false" :show-own-surrender="false" :refreshing="refreshing || (agencyPending && !delegationPending)"
                    @refresh="refreshClient" @pending-change="delegationChanged" />
            </section>
        </template>
    </section>
</template>
