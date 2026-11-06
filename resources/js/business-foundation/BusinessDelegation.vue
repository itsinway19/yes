<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { beginDelivery, definitiveRejection, delegationDrafts, key, type DelegationDraft } from './drafts';
import { writeGrant, type GrantCommand, type Surface } from './api';

const props = defineProps<{ surface: Surface; refreshing?: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const labels: Record<string, [string, string]> = {
    'business.context.read': ['قراءة معلومات النشاط', 'Read business information'],
    'business.context.manage': ['تعديل معلومات النشاط', 'Edit business information'],
    'business.markets.declare': ['التصريح بالأسواق', 'Declare markets'],
    'business.intent.declare': ['التصريح بالنية التجارية', 'Declare commercial intent'],
    'business.preferences.manage': ['تعديل أولويات المعلن', 'Edit advertiser preferences'],
    'business.purpose.declare': ['تصريحات غرض النشاط', 'Business purpose statements'],
    'business.link.manage': ['إدارة علاقات التعاون', 'Manage collaboration relationships'],
    'business.draft.archive': ['أرشفة المسودة', 'Archive draft'],
    'business.lifecycle.manage': ['إدارة حالة النشاط', 'Manage business lifecycle'],
    'business.branch.manage': ['إدارة الفروع', 'Manage branches'],
    'business.data.use': ['استعمال بيانات مؤهلة للنشاط', 'Use qualified business data'],
};
const label = (action: string) => labels[action] ? t(...labels[action]) : action;
const metadata = computed(() => props.surface.delegation);
const options = computed(() => metadata.value.options.filter(o => Number.isSafeInteger(Number(o.grant_id))));
const ownStatement = computed(() => props.surface.purpose_statement?.current_grant);
const ownConfirmed = ref(false);
const ownsSelection = (id: string) => ownStatement.value?.id === id || options.value.some(o => o.grant_id === id);
const resourceKey = `${key(props.surface.business.business_public_id, props.surface.authority_selection)}:delegations:1`;
function concealDraft(value: DelegationDraft) { Object.assign(value, { selected: '', recipient: '', agency: '', expires: '', delegable: false, confirmed: false, blocked: true }); }
const retained = delegationDrafts.get(resourceKey);
if (retained && !ownsSelection(retained.selected)) { if (retained.pending) concealDraft(retained); else delegationDrafts.delete(resourceKey); }
const draft = reactive<DelegationDraft>(delegationDrafts.get(resourceKey) ?? { selected: options.value.length === 1 ? options.value[0].grant_id : '',
    recipient: '', agency: '', expires: '', delegable: false, notice: '', confirmed: false, blocked: false });
delegationDrafts.set(resourceKey, draft);
draft.uncertain ??= !!draft.pending;
const busy = ref(false);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
const selected = computed(() => options.value.find(o => o.grant_id === draft.selected));
const enabled = computed(() => !busy.value && !props.refreshing && !draft.blocked);
const uuid = (value: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
const expiry = computed(() => draft.expires && Number.isFinite(Date.parse(draft.expires)) ? new Date(draft.expires).toISOString() : null);
const valid = computed(() => enabled.value && !!selected.value?.can_issue && uuid(draft.recipient.trim())
    && draft.recipient.trim().toLowerCase() !== metadata.value.actor_public_id.toLowerCase()
    && (!selected.value?.can_choose_agency || !draft.agency.trim() || uuid(draft.agency.trim()))
    && (!draft.expires || !!expiry.value)
    && (!selected.value.max_expires_at || !!draft.expires && Date.parse(expiry.value!) <= Date.parse(selected.value.max_expires_at))
    && (!expiry.value || Date.parse(expiry.value) > Date.now()));
watch(() => props.surface, () => {
    if (draft.selected && !ownsSelection(draft.selected)) {
        if (!draft.pending) delegationDrafts.delete(resourceKey); concealDraft(draft);
        draft.notice = t('لم يعد السند المختار مؤهلًا. لم نستبدله بسند آخر أو نفترض نتيجة الطلب.', 'The selected path is no longer qualified. No replacement authority or command outcome was inferred.');
    }
});
function selection(parent: string) {
    return { ...JSON.parse(JSON.stringify(props.surface.authority_selection)), requirements: [{ requirement_kind: 'business' as const, leaf_grant_id: Number(parent) }] };
}
async function execute(command: GrantCommand) {
    if (!enabled.value) return;
    draft.pending ??= JSON.parse(JSON.stringify(command));
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as GrantCommand;
    const business = props.surface.business.business_public_id, captured = stamp();
    const uncertain = beginDelivery(draft);
    busy.value = true; draft.notice = ''; draft.confirmed = false;
    try {
        const result = await writeGrant(business, frozen);
        if (disposed || !owned(captured)) return;
        if (result.business_public_id !== business || result.status !== (frozen.effect === 'issue' ? 'active' : 'revoked')
            || frozen.effect === 'revoke' && result.grant_id !== frozen.grant_id) throw Error('Delegation acknowledgement target mismatch');
        if (frozen.effect === 'revoke' && frozen.action === 'business.purpose.declare' && frozen.grantee_public_id === metadata.value.actor_public_id
            && frozen.grant_id === frozen.authority_selection.requirements[0]?.leaf_grant_id) draft.selected = '';
        draft.pending = undefined; draft.uncertain = false; draft.confirmed = true; emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            concealDraft(draft);
            draft.notice = t('لم يعد هذا الطلب مخولًا. لم ننشئ حقًا بديلًا ولم نفترض نجاحه.', 'This command is no longer authorized. No replacement right or success was inferred.'); emit('refresh');
        } else if (definitiveRejection(error, uncertain)) {
            draft.pending = undefined; draft.uncertain = false; draft.notice = t('لم يقبل الخادم بيانات الطلب. راجع الحساب والسند والمدة والنطاق.', 'The server rejected the command fields. Review account, authority, expiry and realm.');
        } else {
            draft.notice = t('نتيجة الطلب غير محسومة. احتفظنا بالأمر الأصلي للتحقق دون تكرار تلقائي.', 'The outcome is unresolved. Its original command is retained without automatic retry.');
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
function issue() {
    if (draft.pending) { void execute(draft.pending); return; }
    if (!valid.value || !selected.value) return;
    void execute({ command_id: crypto.randomUUID(), effect: 'issue', grantee_public_id: draft.recipient.trim(), action: selected.value.action,
        parent_grant_id: Number(selected.value.grant_id), grant_id: null, expected_grant: 0, expires_at: expiry.value,
        delegable: selected.value?.can_delegate_onward === true && draft.delegable, realm_workspace_public_id: props.surface.authority_selection.realm_workspace_public_id ?? (selected.value?.can_choose_agency ? (draft.agency.trim() || null) : null),
        authority_selection: selection(selected.value.grant_id) });
}
function revoke(row: Surface['delegation']['issued'][number]) {
    if (!enabled.value || draft.pending || row.recorded_status !== 'active' || !options.value.some(o => o.grant_id === row.parent_grant_id)) return;
    draft.selected = row.parent_grant_id;
    void execute({ command_id: crypto.randomUUID(), effect: 'revoke', grantee_public_id: row.recipient_public_id, action: row.action,
        parent_grant_id: null, grant_id: Number(row.grant_id), expected_grant: row.revision, expires_at: null, delegable: false,
        realm_workspace_public_id: row.realm_workspace_public_id, authority_selection: selection(row.parent_grant_id) });
}
function surrenderOwnStatement() {
    const grant = ownStatement.value;
    if (!grant || !ownConfirmed.value || !enabled.value || draft.pending || !Number.isSafeInteger(Number(grant.id)) || !Number.isSafeInteger(grant.version)) return;
    draft.selected = grant.id; ownConfirmed.value = false;
    void execute({ command_id: crypto.randomUUID(), effect: 'revoke', grantee_public_id: metadata.value.actor_public_id, action: 'business.purpose.declare',
        parent_grant_id: null, grant_id: Number(grant.id), expected_grant: grant.version, expires_at: null, delegable: false,
        realm_workspace_public_id: null, authority_selection: selection(grant.id) });
}
</script>

<template>
    <section class="bf-card bf-delegation" data-business-delegation>
        <h2>{{ t('من يستطيع العمل على هذا النشاط؟', 'Who can work on this business?') }}</h2>
        <section v-if="ownStatement" data-own-purpose-right>
            <h3>{{ t('حق إدارة تصريحات غرض نشاطك', 'Your business purpose statement right') }}</h3>
            <p>{{ t('يمكنك التنازل عن هذا الحق وحده. يبقى تاريخ التصريحات والتصحيحات وحق القراءة المستقل محفوظين. لا يتضمن الحق صلاحية على مصدر أو عنصر خارجي.', 'Surrender only this right. Statement and correction history and independent reading remain preserved. This right grants no source or external-object authority.') }}</p>
            <label class="bf-check"><input v-model="ownConfirmed" type="checkbox" data-purpose-surrender-confirm :disabled="!enabled || !!draft.pending">{{ t('أؤكد التنازل عن حق التصريحات المحدد فقط.', 'I confirm surrendering only this statement right.') }}</label>
            <button type="button" data-purpose-surrender :disabled="!enabled || !!draft.pending || !ownConfirmed || !Number.isSafeInteger(Number(ownStatement.id))" @click="surrenderOwnStatement">{{ t('إيقاف حق التصريحات', 'Surrender statement right') }}</button>
        </section>
        <div v-if="!options.length && draft.pending" role="status" data-purpose-surrender-unresolved>
            <p>{{ t('طلب محفوظ غير محسوم. غياب الحق الحالي لا يثبت نتيجة طلب السحب السابق، ولا يعيد تفعيله.', 'An unresolved request is retained. Current absence of authority does not prove the earlier surrender outcome or reactivate it.') }}</p>
            <p>{{ draft.notice }}</p><button type="button" data-purpose-surrender-retry :disabled="!enabled" @click="execute(draft.pending!)">{{ t('التحقق بالطلب الأصلي', 'Check original request') }}</button>
        </div>
        <p>{{ t('امنح شخصًا صلاحية محددة على هذا النشاط، أو اسحب صلاحية سبق أن منحتها. حدّد الصلاحيات لكل نشاط على حدة.', 'Give someone a specific permission for this business, or withdraw one you previously granted. Set permissions separately for each business.') }}</p>
        <p v-if="!options.length || draft.blocked" role="status" data-delegation-readonly>{{ t('إدارة صلاحيات الآخرين غير متاحة لك هنا.', 'You cannot manage other people’s permissions here.') }}</p>
        <template v-else>
            <p v-if="metadata.options_may_be_partial || metadata.issued_more" role="status">{{ t('هذه قائمة محدودة بالسندات المعروضة وأحدث 64 حقًا مباشرًا صادرًا منها، وليست دليلًا شاملًا لكل الأشخاص أو السلاسل.', 'This bounded view shows selected paths and their latest 64 direct issued rights, not a directory of all people or chains.') }}</p>
            <p v-if="refreshing" role="status">{{ t('جارٍ التحقق من التفويضات الحالية…', 'Checking current delegations…') }}</p>
            <div v-else class="bf-issued-list">
                <h3>{{ t('الصلاحيات التي منحتها', 'Permissions you granted') }}</h3>
                <p>{{ t('الحالة المسجلة لا تكفي لإثبات إمكان الاستعمال الآن؛ تُفحص السلسلة والمدة والوكالة وحقوق البيانات عند كل عملية.', 'Recorded status alone does not establish current usability; chain, expiry, agency and data rights are checked for each operation.') }}</p>
                <p v-if="!metadata.issued.length">{{ t('لا حقوق صادرة معروضة.', 'No issued rights displayed.') }}</p>
                <article v-for="row in metadata.issued" :key="row.grant_id" class="bf-issued" :data-issued-grant="row.grant_id" :data-issued-status="row.recorded_status">
                    <strong>{{ label(row.action) }}</strong><p>{{ t('الحساب', 'Account') }}: <code>{{ row.recipient_public_id }}</code></p>
                    <p>{{ row.realm_kind === 'internal_team' ? t('إدارة ملف داخلي', 'Internal file administration') : row.realm_kind === 'agency' ? t('عبر وكالة', 'Through an agency') : t('وصول مباشر', 'Direct access') }}</p>
                    <details class="advertiser-context-note"><summary>{{ t('مراجع الصلاحية', 'Permission references') }}</summary><p>{{ t('الصلاحية', 'Permission') }} {{ row.grant_id }} · {{ t('مرجع الصلاحية الأصلية', 'Parent permission') }} {{ row.parent_grant_id }}</p></details>
                    <p>{{ row.recorded_status === 'revoked' ? t('مسحوب', 'Withdrawn') : t('مسجل نشطًا — الاستعمال مشروط', 'Recorded active — use remains conditional') }} · {{ row.delegable ? t('يسمح بتفويض تابع', 'Further delegation permitted') : t('للمستفيد فقط', 'Recipient only') }}</p>
                    <p v-if="row.expires_at">{{ t('انتهاء السند', 'Expiry') }}: <time>{{ row.expires_at }}</time></p>
                    <button v-if="row.recorded_status === 'active'" type="button" :disabled="!enabled || !!draft.pending || !Number.isSafeInteger(Number(row.grant_id))" @click="revoke(row)">{{ t('سحب هذا الحق', 'Withdraw this right') }}</button>
                </article>
            </div>
            <form data-delegation-form class="bf-form" @submit.prevent="issue">
                <fieldset :disabled="!enabled || !!draft.pending">
                    <label for="bf-delegation-parent">{{ t('الصلاحية التي تريد منحها أو مراجعتها', 'Permission to grant or review') }}</label>
                    <select id="bf-delegation-parent" v-model="draft.selected"><option value="">{{ t('اختر سندًا', 'Choose a path') }}</option><option v-for="option in options" :key="option.grant_id" :value="option.grant_id">{{ label(option.action) }} — {{ option.grant_id }}{{ !option.can_issue ? t(' — سحب الصادر فقط', ' — issued-right withdrawal only') : '' }}</option></select>
                    <p v-if="selected?.max_expires_at">{{ t('لا تتجاوز مدة السند', 'Must not exceed parent validity') }}: {{ selected.max_expires_at }}</p>
                    <label for="bf-delegation-recipient">{{ t('معرّف حساب المستفيد', 'Recipient account identifier') }}</label>
                    <input id="bf-delegation-recipient" v-model="draft.recipient" type="text" dir="ltr" autocomplete="off" maxlength="36" :placeholder="t('المعرّف الذي يزودك به صاحب الحساب', 'Identifier supplied by the account holder')" />
                    <p>{{ t('يجد صاحب الحساب معرّفه في «أنشطتي»، حتى قبل أن يتاح له أي نشاط.', 'The account holder can find their identifier in My businesses, even before receiving access to a business.') }}</p>
                    <label for="bf-delegation-expires">{{ t('ينتهي التفويض في — بتوقيت جهازك', 'Delegation expires — your device timezone') }}</label>
                    <input id="bf-delegation-expires" v-model="draft.expires" type="datetime-local" :required="!!selected?.max_expires_at" />
                    <p>{{ t('ترك المدة فارغة مسموح فقط إذا لم تحدد السلسلة سقفًا؛ تبقى الصلاحية قابلة للسحب ومشروطة بصحة سندها.', 'An empty expiry is allowed only when the chain imposes no upper bound; authority remains revocable and conditional.') }}</p>
                    <template v-if="selected?.can_choose_agency"><label for="bf-delegation-agency">{{ t('معرّف مساحة الوكالة — اختياري', 'Agency workspace identifier — optional') }}</label><input id="bf-delegation-agency" v-model="draft.agency" type="text" dir="ltr" maxlength="36" autocomplete="off" /><p>{{ t('اتركه فارغًا لوصول مباشر. نطاق الوكالة يحتاج علاقة مؤهلة وعضوية حالية للمستفيد.', 'Leave empty for direct access. An agency realm requires a qualified relationship and current recipient membership.') }}</p></template>
                    <p v-else-if="surface.authority_selection.realm_kind === 'direct'">{{ t('الصلاحية مباشرة لهذا النشاط، ولا تنتقل إلى وكالة أو مصدر بيانات.', 'Authority is direct for this business and cannot extend to an agency or data source.') }}</p>
                    <p v-else>{{ surface.authority_selection.realm_kind === 'internal_team' ? t('التفويض مقيد بإدارة الملف داخل هذا الفريق، ولا يمنح حقوقًا خارجية.', 'Delegation is limited to file administration in this team and grants no external rights.') : t('يبقى التفويض داخل نطاق الوكالة الحالي.', 'Delegation stays within the current agency realm.') }}</p>
                    <label v-if="selected?.can_delegate_onward" class="bf-check"><input v-model="draft.delegable" type="checkbox" />{{ t('السماح للمستفيد بتفويض الحق نفسه ضمن قيود السلسلة', 'Allow the recipient to delegate the same right within chain limits') }}</label>
                </fieldset>
                <p v-if="draft.pending && !busy" role="status" data-delegation-unknown>{{ t('يوجد طلب غير محسوم. لا نرسل طلبًا جديدًا قبل التحقق بالأمر الأصلي.', 'A command is unresolved. No new command is sent before original-command reconciliation.') }}</p>
                <p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="draft.confirmed" role="status" data-delegation-confirmed>{{ t('استُلم تأكيد الأمر. الحالة المعروضة تُعاد قراءتها بصلاحياتك الحالية.', 'The command was acknowledged. Displayed state is read back under current authority.') }}</p>
                <button class="bf-primary" :disabled="draft.pending ? !enabled : !valid">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Reconcile original command') : t('منح الحق المحدد', 'Delegate selected right') }}</button>
            </form>
        </template>
    </section>
</template>
