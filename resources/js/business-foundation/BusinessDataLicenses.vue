<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import type { Surface } from './api';
import { definitiveRejection } from './drafts';
import { clearJourneyPrivate, deliverJourney, emptyJourneyDraft, journeyClosed, journeyDeliveries, journeyDrafts, journeyKey, newLicenseId, readJourney, readLicensedMaterials, type Journey, type JourneyDraft, type License, type MaterialCommand, type MaterialResult, type Pending } from './dataLicenseJourney';

const props = defineProps<{ surface: Surface; active: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const sameRef = (a: { namespace?: string; key?: string; version?: string } | undefined, b: { namespace?: string; key?: string; version?: string } | undefined) => !!a && !!b && a.namespace === b.namespace && a.key === b.key && a.version === b.version;
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = computed(() => props.surface.business.business_public_id);
const closedDraft = emptyJourneyDraft();
const draft = computed(() => {
    if (journeyClosed.value) return closedDraft;
    const key = journeyKey(props.surface);
    if (!journeyDrafts.has(key)) journeyDrafts.set(key, emptyJourneyDraft());
    return journeyDrafts.get(key)!;
});
const inventory = ref<Journey>(), materials = ref<MaterialResult>(), loading = ref(false), busy = ref(false), reading = ref(false);
let generation = 0, consumerGeneration = 0, disposed = false;
let retainedDraft: JourneyDraft | undefined;
const source = computed(() => inventory.value?.sources.find(row => row.source_id === draft.value.source));
const dataset = computed(() => source.value?.datasets.find(row => row.dataset_id === draft.value.dataset));
const licenses = computed(() => inventory.value?.licenses.filter(row => row.source_id === source.value?.source_id) ?? []);
const historicalLicenses = computed(() => inventory.value?.licenses.filter(row => row.is_declarant === true && !inventory.value?.sources.some(item => item.source_id === row.source_id)) ?? []);
const historicalLicense = computed(() => historicalLicenses.value.find(row => row.license_id === draft.value.license));
const license = computed(() => licenses.value.find(row => row.license_id === draft.value.license) ?? historicalLicense.value);
const delivering = computed(() => busy.value || !!draft.value.pending && journeyDeliveries.has(draft.value.pending.body.command_id));
const canEdit = computed(() => props.active && !!inventory.value && !loading.value && !delivering.value && !draft.value.pending && !draft.value.blocked);
const qualifiedBinding = computed(() => inventory.value?.binding_options.some(row => row.grant_id === draft.value.binding));
const qualifiedUse = computed(() => inventory.value?.business_use_options.some(row => row.grant_id === draft.value.businessUse));
const exact = (value: string | null | undefined) => !!value && Number.isSafeInteger(Number(value)) && Number(value) > 0;
const currentLicense = computed(() => license.value?.status === 'active' && license.value.recipient_public_id === inventory.value?.actor_public_id);
const readable = (operation: string): operation is MaterialCommand['operation'] => operation === 'display' || operation === 'retained_read';
const canIssue = computed(() => canEdit.value && !!source.value && !!dataset.value && exact(source.value.manager_grant_id) && !draft.value.reviewNeeded && draft.value.confirmed && !!draft.value.holder.trim() && !!draft.value.reference.trim() && !!draft.value.purpose && !!draft.value.expires);
const canRead = computed(() => canEdit.value && currentLicense.value && license.value?.source_id === source.value?.source_id && license.value?.covered && license.value.bound && license.value.summary?.state !== 'prepared' && readable(license.value.operation) && exact(license.value.use_grant_id) && qualifiedUse.value && exact(draft.value.businessUse) && exact(source.value?.read_grant_id) && !reading.value);
const beneficiaries = computed(() => (inventory.value?.beneficiaries ?? []).filter(row => row.public_id !== inventory.value?.actor_public_id && row.realm_kind === props.surface.authority_selection.realm_kind && row.realm_workspace_public_id === props.surface.authority_selection.realm_workspace_public_id));
const beneficiary = computed(() => beneficiaries.value.find(row => row.public_id === draft.value.beneficiary));
const assignments = computed(() => (inventory.value?.assignments ?? []).filter(row => row.license_id === license.value?.license_id));
const latestAssignment = computed(() => assignments.value.find(row => row.recipient_public_id === draft.value.beneficiary));
const assignmentAuthority = computed(() => exact(draft.value.assignmentAuthority) && inventory.value?.business_use_options.some(row => row.grant_id === draft.value.assignmentAuthority));
const canAssign = computed(() => canEdit.value && currentLicense.value && license.value?.summary?.state !== 'prepared' && !!license.value && readable(license.value.operation) && license.value?.is_declarant === true && exact(license.value.boundary_grant_id) && !!beneficiary.value && assignmentAuthority.value && draft.value.assignmentConfirmed && latestAssignment.value?.status !== 'active' && Number.isSafeInteger(latestAssignment.value?.revision ?? 0) && Number.isFinite(new Date(draft.value.assignmentExpires).getTime()) && new Date(draft.value.assignmentExpires).getTime() > Date.now() && new Date(draft.value.assignmentExpires).getTime() <= new Date(license.value.valid_until).getTime());
const summaryInput = computed(() => license.value?.operation === 'process' && license.value.material_refs[Number(draft.value.summaryRecord)] && draft.value.summaryRecord !== '' ? license.value.material_refs[Number(draft.value.summaryRecord)] : undefined);
const summaryMetadata = computed(() => license.value?.record_metadata?.find(row => row.ref.namespace === summaryInput.value?.namespace && row.ref.key === summaryInput.value?.key && row.ref.version === summaryInput.value?.version));
const canProcessSummary = computed(() => canEdit.value && currentLicense.value && license.value?.operation === 'process' && license.value.covered && license.value.bound && exact(license.value.use_grant_id) && qualifiedUse.value && exact(source.value?.read_grant_id) && !!summaryInput.value && !!summaryMetadata.value && (!!summaryMetadata.value.campaign_id || !!summaryMetadata.value.start && !!summaryMetadata.value.end));
const canPrepareSummary = computed(() => canProcessSummary.value && qualifiedBinding.value && exact(license.value?.boundary_grant_id) && draft.value.summaryConfirmed && draft.value.summaryRead?.license === license.value?.license_id && JSON.stringify(draft.value.summaryRead?.input) === JSON.stringify(summaryInput.value) && new Date(draft.value.summaryExpires).getTime() > Date.now() && new Date(draft.value.summaryExpires).getTime() <= new Date(license.value!.valid_until).getTime());
const canPublishSummary = computed(() => canEdit.value && currentLicense.value && license.value?.summary?.state === 'prepared' && qualifiedUse.value && exact(license.value.summary.process_grant_id) && exact(source.value?.read_grant_id));
const beneficiaryName = (recipient: string) => beneficiaries.value.find(row => row.public_id === recipient)?.name ?? t('مستفيد سابق غير ظاهر في القائمة الحالية', 'Previous beneficiary absent from the current eligible list');
function money(value: unknown) {
    // NativeAmount already computes exact major units. Never convert its native
    // units or pass money through JavaScript Number/locale rounding.
    if (!value || typeof value !== 'object' || Array.isArray(value)
        || !('native_value' in value) || typeof value.native_value !== 'string' || !/^-?\d{1,60}(?:\.\d{1,30})?$/.test(value.native_value)
        || !('native_unit' in value) || typeof value.native_unit !== 'string' || !value.native_unit
        || !('decimal_scale' in value) || typeof value.decimal_scale !== 'number' || !Number.isInteger(value.decimal_scale) || value.decimal_scale < 0 || value.decimal_scale > 18
        || !('scale_basis' in value) || typeof value.scale_basis !== 'string' || !value.scale_basis.trim()
        || !('major_units' in value) || typeof value.major_units !== 'string' || !/^-?\d{1,60}(?:\.\d{1,48})?$/.test(value.major_units)
        || !('currency' in value) || value.currency !== null && (typeof value.currency !== 'string' || !/^[A-Z]{3}$/.test(value.currency))) return;
    return { value: value.major_units, currency: value.currency };
}
const displayedMaterials = computed(() => (materials.value?.materials ?? []).map(item => ({ ...item, amount: money(item.fields.spend) })));
function purposeLabel(value: string) {
    switch (value) {
        case 'advertising_performance': return t('مراجعة أداء الإعلانات', 'Review advertising performance');
        case 'market_research': return t('بحث السوق', 'Market research');
        default: return t('الغرض المسجّل في الترخيص', 'Purpose recorded in the licence');
    }
}
function operationLabel(value: string) {
    switch (value) {
        case 'display': return t('عرض البيانات داخل النشاط', 'Display data inside this business');
        case 'retained_read': return t('قراءة البيانات المحفوظة', 'Read retained data');
        case 'process': return t('معالجة البيانات', 'Process data');
        case 'reuse': return t('إعادة استعمال البيانات', 'Reuse data');
        case 'combine': return t('دمج البيانات', 'Combine data');
        case 'export': return t('تصدير البيانات', 'Export data');
        default: return t('الاستعمال المسجّل في الترخيص', 'Use recorded in the licence');
    }
}
function fieldLabel(value: string) {
    switch (value) {
        case 'spend': return t('الإنفاق', 'Expenditure');
        case 'impressions': return t('مرات الظهور', 'Impressions');
        case 'clicks': return t('النقرات', 'Clicks');
        default: return t('حقل مسجّل في الترخيص', 'Field recorded in the licence');
    }
}
function licenseStatus(value: License['status']) {
    return value === 'active' ? t('فعال', 'Active') : value === 'expired' ? t('منتهي', 'Expired') : t('مسحوب', 'Withdrawn');
}
function validUntil(value: string) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? t('التاريخ غير متاح', 'Date unavailable')
        : date.toLocaleString(locale.value === 'ar' ? 'ar-JO' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}
function coverage(value: string) {
    switch (value) {
        case 'COMPLETE': return t('مكتملة', 'Complete');
        case 'PARTIAL': return t('جزئية', 'Partial');
        case 'FAILED': return t('تعذر جمع البيانات', 'Collection failed');
        case 'PENDING': return t('قيد الانتظار', 'Pending');
        default: return t('غير معروفة', 'Unknown');
    }
}
function recordLabel(ref: License['material_refs'][number]) {
    const row = license.value?.record_metadata?.find(row => row.ref.namespace === ref.namespace && row.ref.key === ref.key && row.ref.version === ref.version);
    if (!row || !row.campaign_id && (!row.start || !row.end)) return t('تعريف السجل غير متاح؛ لا يمكن تأهيله الآن', 'Record identity unavailable; it cannot be qualified now');
    const campaign = row.campaign_id ? `${t('الحملة', 'Campaign')}: ${row.campaign_id}` : t('الحملة غير معروفة', 'Campaign unknown');
    const period = row.start && row.end ? `${row.start} — ${row.end}` : t('الفترة غير معروفة', 'Period unknown');
    return `${campaign} · ${period}`;
}
function windowBoundary(value: string | null) {
    return value === 'INCLUSIVE_DATES' ? t('يشمل تاريخ البداية والنهاية', 'Includes start and end dates') : value === 'HALF_OPEN_INSTANTS' ? t('يشمل البداية ولا يشمل لحظة النهاية', 'Includes start, excludes end instant') : t('حدود الفترة غير معروفة', 'Period boundaries unknown');
}
function windowGrain(value: string | null) {
    return value === 'TOTAL' ? t('إجمالي الفترة', 'Period total') : value === 'DAY' ? t('يومي', 'Daily') : value === 'HOUR' ? t('ساعي', 'Hourly') : t('تقسيم الفترة غير معروف', 'Period granularity unknown');
}
const messages = computed(() => ({ unknown: t('النتيجة غير محسومة. احتفظنا بالطلب الأصلي؛ تحقّق منه صراحة دون إنشاء بديل.', 'The outcome is unresolved. The original request is retained; explicitly reconcile it before creating another.'), rejected: t('لم تُقبل بيانات الطلب. راجع الحقول ثم أرسل طلبًا جديدًا.', 'The request fields were rejected. Review the fields before submitting a new request.'), revision: t('تغير الإصدار قبل أثر الطلب. راجع الحالة المحدثة ثم أكد طلبًا جديدًا عند الحاجة.', 'The revision changed before this request took effect. Review the refreshed state, then confirm a new request if needed.'), denied: t('لم تعد الصلاحية مؤهلة. أُخفيت البيانات والنموذج؛ لا يعني ذلك نجاح الطلب السابق.', 'Authority is no longer qualified. Data and form values were concealed; this does not confirm the previous request.'), acknowledged: t('تأكد إيصال الطلب. نتحقق من الحالة الحالية بصلاحياتها المستقلة.', 'Request receipt confirmed. Current state is checked under its independent permissions.'), unavailable: t('تعذر التحقق الآن. لا يعني ذلك عدم وجود مصادر أو تراخيص.', 'The current state could not be checked. This does not mean sources or licences are absent.'), '': '' }));
function authority(requirements: Surface['authority_selection']['requirements']) { return { ...JSON.parse(JSON.stringify(props.surface.authority_selection)), requirements }; }
function privatePurge() { consumerGeneration++; materials.value = undefined; inventory.value = undefined; clearJourneyPrivate(draft.value); }
function denied() { generation++; privatePurge(); loading.value = false; reading.value = false; draft.value.blocked = true; draft.value.outcome = 'denied'; emit('refresh'); }
async function load() {
    if (!props.active || disposed || journeyClosed.value) return;
    const attempt = ++generation, captured = stamp(), target = business.value, key = journeyKey(props.surface);
    consumerGeneration++; reading.value = false; materials.value = undefined; inventory.value = undefined; loading.value = true;
    draft.value.confirmed = false; draft.value.withdrawConfirmed = false; draft.value.mandateConfirmed = false; draft.value.assignmentConfirmed = false; draft.value.summaryConfirmed = false;
    try {
        const value = await readJourney(target, Number(props.surface.authority_selection.requirements.find(row => row.requirement_kind === 'business')?.leaf_grant_id));
        if (disposed || !owned(captured) || attempt !== generation || !props.active || target !== business.value || key !== journeyKey(props.surface)) return;
        if (value.business_public_id !== target || !value.actor_public_id) throw Error('Journey identity mismatch');
        inventory.value = value; draft.value.blocked = false;
        if (!exact(source.value?.read_grant_id) || !qualifiedUse.value || !summaryMetadata.value) draft.value.summaryRead = undefined;
        if (draft.value.outcome === 'unavailable' || draft.value.outcome === 'denied') draft.value.outcome = '';
        if (draft.value.source && !value.sources.some(row => row.source_id === draft.value.source)) clearJourneyPrivate(draft.value);
    } catch (error) {
        if (disposed || !owned(captured) || attempt !== generation || target !== business.value || key !== journeyKey(props.surface)) return;
        if (error instanceof TransportError && error.status === 403) denied();
        else { clearJourneyPrivate(draft.value); draft.value.outcome = 'unavailable'; }
    } finally { if (!disposed && owned(captured) && attempt === generation) loading.value = false; }
}
function receipt(command: Pending, result: Record<string, unknown>) {
    if (command.kind === 'summary') return result.business_public_id === command.business && result.license_id === command.body.summary_id && result.status === 'prepared' && (result.artifact_ref as {namespace?:string;key?:string})?.namespace === 'business.expenditure_summary' && (result.artifact_ref as {key?:string})?.key === command.body.summary_id && exact(String(result.use_grant_id)) && (result.versions as {summary?:number})?.summary === 1;
    if (command.kind === 'summary-process') return result.business_public_id === command.business && result.operation === 'process' && result.purpose === command.body.purpose && result.recipient_ref === command.body.recipient_ref && Array.isArray(result.materials) && result.materials.length === 1 && sameRef(result.materials[0].ref, command.body.material_refs[0]);
    if (command.kind === 'summary-publish') return result.business_public_id === command.business && sameRef(result.artifact_ref as {namespace?:string;key?:string;version?:string}, command.body.artifact_ref) && typeof result.manifest_id === 'string' && typeof result.closure_digest === 'string' && /^[a-f0-9]{64}$/.test(result.closure_digest) && (result.versions as {manifest?:number})?.manifest === 1;
    if (command.kind === 'license') return result.license_id === command.body.license_id && result.status === (command.body.effect === 'issue' ? 'active' : 'withdrawn') && result.assurance === 'DECLARED_AUTHORITY' && (result.versions as { license?: unknown } | undefined)?.license === (command.body.effect === 'issue' ? 1 : 2);
    if (command.kind === 'coverage') return typeof result.membership_id === 'string' && result.status === 'active' && (result.versions as { membership?: unknown } | undefined)?.membership === command.body.expected_revision + 1;
    if (command.kind === 'binding') return result.status === 'active' && (result.versions as { binding?: unknown } | undefined)?.binding === command.body.expected_relation + 1;
    if (command.kind === 'beneficiary') return result.license_id === command.body.license_id && result.recipient_public_id === command.body.recipient_public_id && exact(String(result.grant_id)) && result.status === (command.body.effect === 'assign' ? 'active' : 'revoked') && (result.versions as { grant?: unknown } | undefined)?.grant === command.body.expected_revision + 1 && (command.body.effect !== 'revoke' || result.grant_id === command.expectedGrant);
    const refs = result.grant_refs as Record<string, unknown> | undefined;
    return result.business_public_id === command.business && result.assurance === 'DECLARED_AUTHORITY' && typeof result.qualification_ref === 'string' && (result.versions as { qualification?: unknown } | undefined)?.qualification === 1 && !!refs && exact(String(refs['business.data.use'])) && exact(String(refs['business.link.manage']));
}
async function execute(command: Pending) {
    if (delivering.value || loading.value || !props.active || !inventory.value || draft.value.blocked) return;
    const owner = draft.value, target = business.value, key = journeyKey(props.surface), captured = stamp(), attempt = generation;
    if (owner.pending && JSON.stringify(owner.pending) !== JSON.stringify(command)) return;
    owner.pending ??= JSON.parse(JSON.stringify(command));
    const frozen = JSON.parse(JSON.stringify(owner.pending)) as Pending, previouslyUncertain = owner.uncertain;
    owner.uncertain = true; owner.outcome = ''; owner.confirmed = false; owner.withdrawConfirmed = false; owner.mandateConfirmed = false; owner.assignmentConfirmed = false; busy.value = true; reading.value = false; materials.value = undefined; consumerGeneration++;
    try {
        const result = await deliverJourney(frozen);
        if (!receipt(frozen, result)) throw Error('Exact command receipt unavailable');
        if (disposed || !owned(captured) || target !== business.value || key !== journeyKey(props.surface) || attempt !== generation || !props.active) return;
        const context = { source: owner.source, license: owner.license, businessUse: owner.businessUse, binding: owner.binding, summaryRecord: owner.summaryRecord, summaryExpires: owner.summaryExpires };
        owner.pending = undefined; owner.uncertain = false; owner.outcome = 'acknowledged'; clearJourneyPrivate(owner);
        if (frozen.kind === 'summary-process' || frozen.kind === 'summary' || frozen.kind === 'summary-publish') {
            Object.assign(owner, context);
            if (frozen.kind === 'summary') owner.license = frozen.body.summary_id;
            if (frozen.kind === 'summary-process') owner.summaryRead = {license:frozen.license,command:frozen.body.command_id,input:frozen.body.material_refs[0]};
        }
        emit('refresh'); await load();
    } catch (error) {
        if (disposed || !owned(captured) || target !== business.value || key !== journeyKey(props.surface) || attempt !== generation || !props.active) return;
        if (error instanceof TransportError && error.status === 403) denied();
        else if (definitiveRejection(error, previouslyUncertain)) { owner.pending = undefined; owner.uncertain = false; owner.outcome = error instanceof TransportError && error.status === 409 ? 'revision' : 'rejected'; if (owner.outcome === 'revision') await load(); }
        else owner.outcome = 'unknown';
    } finally { if (!disposed && owned(captured) && target === business.value && key === journeyKey(props.surface)) busy.value = false; }
}
function issue() {
    if (!canIssue.value) return;
    const row = source.value!, report = dataset.value!, until = new Date(draft.value.expires);
    if (!Number.isFinite(until.getTime()) || until.getTime() <= Date.now()) { draft.value.outcome = 'rejected'; return; }
    void execute({ kind: 'license', business: business.value, body: { command_id: crypto.randomUUID(), license_id: newLicenseId(), effect: 'issue', expected_revision: 0,
        authority_selection: authority([...props.surface.authority_selection.requirements.filter(row => row.requirement_kind === 'business'), { requirement_kind: 'native_source', leaf_grant_id: Number(row.manager_grant_id) }]),
        declaration: { source_id: row.source_id, resource: row.resource, operation: draft.value.operation, purpose: draft.value.purpose, selection: JSON.parse(JSON.stringify(report.selection)), recipient_public_id: inventory.value!.actor_public_id, valid_until: until.toISOString(), capacity: draft.value.capacity, holder_name: draft.value.holder.trim(), authority_reference: draft.value.reference.trim(), confirmed: true, requires_review: false } } });
}
function withdraw(row: License) {
    if (!canEdit.value || row.resource === 'business.expenditure_summary' || !draft.value.withdrawConfirmed || row.is_declarant !== true || row.status === 'withdrawn' || row.license_id !== license.value?.license_id) return;
    void execute({ kind: 'license', business: business.value, body: { command_id: crypto.randomUUID(), license_id: row.license_id, effect: 'withdraw', expected_revision: 1, declaration: {}, authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) } });
}
function processSummary() {
    if (!canProcessSummary.value) return;
    void execute({kind:'summary-process',business:business.value,license:license.value!.license_id,body:{command_id:crypto.randomUUID(),material_refs:[JSON.parse(JSON.stringify(summaryInput.value))],operation:'process',purpose:license.value!.purpose,recipient_ref:inventory.value!.actor_public_id,
        authority_selection:authority([{requirement_kind:'business',leaf_grant_id:Number(draft.value.businessUse)},{requirement_kind:'data_use',leaf_grant_id:Number(license.value!.use_grant_id)},{requirement_kind:'native_source',leaf_grant_id:Number(source.value!.read_grant_id)}])}});
}
function prepareSummary() {
    if (!canPrepareSummary.value) return;
    void execute({kind:'summary',business:business.value,body:{command_id:crypto.randomUUID(),summary_id:newLicenseId(),input_ref:JSON.parse(JSON.stringify(summaryInput.value)),input_license_id:license.value!.license_id,read_command_id:draft.value.summaryRead!.command,confirmed:true,valid_until:new Date(draft.value.summaryExpires).toISOString(),consent:{derive:true,deliver_summary:true,retain_payload:false},
        authority_selection:authority([{requirement_kind:'business',leaf_grant_id:Number(draft.value.businessUse)},{requirement_kind:'data_use',leaf_grant_id:Number(license.value!.use_grant_id)},{requirement_kind:'native_source',leaf_grant_id:Number(source.value!.read_grant_id)},{requirement_kind:'client_data',leaf_grant_id:Number(license.value!.boundary_grant_id)},{requirement_kind:'business',leaf_grant_id:Number(draft.value.binding)}])}});
}
function publishSummary() {
    const row = license.value;
    if (!canPublishSummary.value || !row?.summary || row.material_refs.length !== 1) return;
    void execute({kind:'summary-publish',business:business.value,body:{command_id:crypto.randomUUID(),artifact_ref:JSON.parse(JSON.stringify(row.material_refs[0])),dependencies:JSON.parse(JSON.stringify(row.summary.dependencies)),purpose:row.purpose,recipient_ref:inventory.value!.actor_public_id,
        authority_selection:authority([{requirement_kind:'business',leaf_grant_id:Number(draft.value.businessUse)},{requirement_kind:'data_use',leaf_grant_id:Number(row.summary.process_grant_id)},{requirement_kind:'data_use',leaf_grant_id:Number(row.use_grant_id)},{requirement_kind:'native_source',leaf_grant_id:Number(source.value!.read_grant_id)}])}});
}
async function readAssigned(row: Journey['assigned_licenses'][number]) {
    if (!canEdit.value || !qualifiedUse.value || !row.artifact_ref || row.artifact_ref.namespace !== 'business.expenditure_summary' || !exact(row.use_grant_id) || reading.value) return;
    const captured=stamp(),attempt=++consumerGeneration,target=business.value;reading.value=true;materials.value=undefined;
    try {
        const result=await readLicensedMaterials(target,{command_id:crypto.randomUUID(),material_refs:[row.artifact_ref],operation:'display',purpose:row.purpose,recipient_ref:inventory.value!.actor_public_id,authority_selection:authority([{requirement_kind:'business',leaf_grant_id:Number(draft.value.businessUse)},{requirement_kind:'data_use',leaf_grant_id:Number(row.use_grant_id)}])});
        if(disposed || !owned(captured) || attempt!==consumerGeneration || !props.active || target!==business.value)return;
        if(result.business_public_id!==target || result.operation!=='display' || result.purpose!==row.purpose || result.recipient_ref!==inventory.value?.actor_public_id || result.materials.length!==1 || result.materials[0].data_use_scope_id!==row.license_id || !sameRef(result.materials[0].ref,row.artifact_ref))throw Error('Summary receipt changed');
        materials.value=result;
    } catch(error) {if(disposed || !owned(captured) || attempt!==consumerGeneration || target!==business.value)return;if(error instanceof TransportError && error.status===403)denied();else draft.value.outcome='unavailable';}
    finally {if(attempt===consumerGeneration)reading.value=false;}
}
function qualify() {
    const until = new Date(draft.value.mandateExpires);
    if (!canEdit.value || !inventory.value?.operating_mandate.available || !draft.value.mandateConfirmed || !draft.value.mandateReference.trim() || !Number.isFinite(until.getTime()) || until.getTime() <= Date.now()) return;
    void execute({ kind: 'mandate', business: business.value, body: { command_id: crypto.randomUUID(), confirmed: true, capacity: draft.value.mandateCapacity, authority_reference: draft.value.mandateReference.trim(), valid_until: until.toISOString(), previous_grant: inventory.value.operating_mandate.previous_grant, authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) } });
}
function assignBeneficiary() {
    if (!canAssign.value) return;
    void execute({ kind: 'beneficiary', business: business.value, body: { command_id: crypto.randomUUID(), license_id: license.value!.license_id, effect: 'assign', recipient_public_id: beneficiary.value!.public_id, valid_until: new Date(draft.value.assignmentExpires).toISOString(), expected_revision: latestAssignment.value?.revision ?? 0, authority_selection: authority([{ requirement_kind: 'business', leaf_grant_id: Number(draft.value.assignmentAuthority) }, { requirement_kind: 'client_data', leaf_grant_id: Number(license.value!.boundary_grant_id) }]) } });
}
function revokeAssignment(row: Journey['assignments'][number]) {
    if (!canEdit.value || license.value?.is_declarant !== true || !draft.value.assignmentConfirmed || !assignmentAuthority.value || !exact(license.value.boundary_grant_id) || row.license_id !== license.value.license_id || row.status !== 'active' || !Number.isSafeInteger(row.revision) || !exact(row.grant_id)) return;
    void execute({ kind: 'beneficiary', business: business.value, expectedGrant: row.grant_id, body: { command_id: crypto.randomUUID(), license_id: row.license_id, effect: 'revoke', recipient_public_id: row.recipient_public_id, valid_until: row.valid_until, expected_revision: row.revision, authority_selection: authority([{ requirement_kind: 'business', leaf_grant_id: Number(draft.value.assignmentAuthority) }, { requirement_kind: 'client_data', leaf_grant_id: Number(license.value!.boundary_grant_id) }]) } });
}
function cover() {
    const row = license.value;
    if (!canEdit.value || !currentLicense.value || !row || row.covered || !qualifiedBinding.value || !exact(draft.value.binding) || !exact(row.boundary_grant_id) || !Number.isSafeInteger(row.coverage_revision)) return;
    void execute({ kind: 'coverage', business: business.value, body: { command_id: crypto.randomUUID(), target_kind: 'boundary_subject', target_ref: row.boundary_id, effect: 'attach_subject', expected_revision: row.coverage_revision, payload: { business_public_id: business.value, branch_id: null }, authority_selection: authority([{ requirement_kind: 'business', leaf_grant_id: Number(draft.value.binding) }, { requirement_kind: 'client_data', leaf_grant_id: Number(row.boundary_grant_id) }]) } });
}
function bind() {
    const row = license.value;
    if (!canEdit.value || !currentLicense.value || !row || row.bound || !row.covered || !qualifiedBinding.value || !exact(draft.value.binding) || !exact(row.use_grant_id) || !Number.isSafeInteger(row.binding_revision)) return;
    void execute({ kind: 'binding', business: business.value, body: { command_id: crypto.randomUUID(), data_use_scope_id: row.license_id, effect: 'add', expected_relation: row.binding_revision, authority_selection: authority([{ requirement_kind: 'business', leaf_grant_id: Number(draft.value.binding) }, { requirement_kind: 'data_use', leaf_grant_id: Number(row.use_grant_id) }]) } });
}
async function consume() {
    if (!canRead.value) return;
    const row = license.value!, captured = stamp(), attempt = ++consumerGeneration, target = business.value, selectedSource = source.value!.source_id;
    if (!readable(row.operation)) return;
    reading.value = true; materials.value = undefined;
    try {
        const result = await readLicensedMaterials(target, { command_id: crypto.randomUUID(), material_refs: JSON.parse(JSON.stringify(row.material_refs)), operation: row.operation, purpose: row.purpose, recipient_ref: row.recipient_public_id, authority_selection: authority([{ requirement_kind: 'business', leaf_grant_id: Number(draft.value.businessUse) }, { requirement_kind: 'data_use', leaf_grant_id: Number(row.use_grant_id) }, ...(row.summary ? [] : [{ requirement_kind: 'native_source' as const, leaf_grant_id: Number(source.value!.read_grant_id) }])]) });
        if (disposed || !owned(captured) || attempt !== consumerGeneration || !props.active || target !== business.value || selectedSource !== source.value?.source_id || license.value?.license_id !== row.license_id) return;
        if (result.business_public_id !== target || result.operation !== row.operation || result.purpose !== row.purpose || result.recipient_ref !== row.recipient_public_id || result.materials.some(item => item.data_use_scope_id !== row.license_id || !row.material_refs.some(ref => ref.namespace === item.ref.namespace && ref.key === item.ref.key && ref.version === item.ref.version))) throw Error('Material receipt changed');
        materials.value = result;
    } catch (error) {
        if (disposed || !owned(captured) || attempt !== consumerGeneration || target !== business.value) return;
        if (error instanceof TransportError && error.status === 403) denied(); else draft.value.outcome = 'unavailable';
    } finally { if (attempt === consumerGeneration) reading.value = false; }
}
watch(() => [draft.value.source, draft.value.license], () => { consumerGeneration++; reading.value = false; materials.value = undefined; draft.value.confirmed = false; draft.value.withdrawConfirmed = false; draft.value.summaryRead = undefined; draft.value.summaryConfirmed = false; Object.assign(draft.value, { beneficiary: '', assignmentAuthority: '', assignmentExpires: '', assignmentConfirmed: false }); }, { flush: 'sync' });
watch(() => draft.value.source, () => { Object.assign(draft.value, { dataset: '', license: '', holder: '', reference: '', expires: '', purpose: '', reviewNeeded: false, confirmed: false }); }, { flush: 'sync' });
watch(() => [draft.value.operation, draft.value.dataset, draft.value.purpose, draft.value.capacity, draft.value.holder, draft.value.reference, draft.value.expires, draft.value.reviewNeeded], () => { draft.value.confirmed = false; }, { flush: 'sync' });
watch(() => [draft.value.summaryRecord, draft.value.summaryExpires, draft.value.binding, draft.value.businessUse], () => { draft.value.summaryConfirmed = false; }, { flush: 'sync' });
watch(() => draft.value.summaryRecord, () => { draft.value.summaryRead = undefined; }, { flush: 'sync' });
watch(() => [draft.value.mandateCapacity, draft.value.mandateReference, draft.value.mandateExpires], () => { draft.value.mandateConfirmed = false; }, { flush: 'sync' });
watch(() => [draft.value.beneficiary, draft.value.assignmentAuthority, draft.value.assignmentExpires], () => { draft.value.assignmentConfirmed = false; draft.value.summaryConfirmed = false; }, { flush: 'sync' });
// A refreshed snapshot may replace the object while retaining the same authorization context.
watch([() => props.active, () => journeyKey(props.surface)], () => { generation++; consumerGeneration++; inventory.value = undefined; materials.value = undefined; loading.value = false; busy.value = false; reading.value = false; if (retainedDraft) clearJourneyPrivate(retainedDraft); retainedDraft = draft.value; if (props.active) void load(); }, { immediate: true, flush: 'sync' });
const focus = () => { if (props.active && !busy.value) void load(); };
window.addEventListener('focus', focus);
watch(journeyClosed, closed => { if (closed) { generation++; consumerGeneration++; inventory.value = undefined; materials.value = undefined; } }, { flush: 'sync' });
onBeforeUnmount(() => { disposed = true; generation++; consumerGeneration++; clearJourneyPrivate(draft.value); window.removeEventListener('focus', focus); });
</script>

<template>
<section v-if="!journeyClosed" class="bf-card bf-form bf-data-use" data-business-data-licenses :dir="locale === 'ar' ? 'rtl' : 'ltr'" :aria-busy="loading || busy || reading">
    <h2>{{ t('ترخيص بيانات المصدر واستعمالها', 'License and use source data') }}</h2>
    <p>{{ t('اختر تقريرًا متاحًا، وصرّح بصفتك وحدود استعماله لهذا النشاط. الاتصال وحده لا يمنح حق الاستعمال. التصريح بالصلاحية ليس تحققًا قانونيًا منها.', 'Choose an available report and declare your capacity and its use boundaries for this business. Connection alone grants no data-use right. Declared authority is not legally verified authority.') }}</p>
    <p v-if="loading" role="status">{{ t('جارٍ التحقق من الحقوق والتقارير…', 'Checking permissions and reports…') }}</p>
    <p v-if="draft.outcome" role="status" data-license-status>{{ messages[draft.outcome] }}</p>
    <button v-if="!busy && !loading" type="button" class="workspace-button" data-license-refresh @click="load">{{ t('تحديث الحالة والصلاحيات', 'Refresh state and permissions') }}</button>
    <button v-if="draft.pending" type="button" class="workspace-button" data-license-reconcile :disabled="delivering || loading || !inventory || draft.blocked" @click="execute(draft.pending)">{{ t('التحقق من الطلب الأصلي', 'Reconcile original request') }}</button>
    <template v-if="inventory && !draft.blocked">
        <p v-if="inventory.sources_more || inventory.licenses_more" role="status" data-license-partial>{{ t('القائمة المعروضة جزئية: تعرض حتى ٣٢ مصدرًا و٣٢ ترخيصًا مؤهلًا. عدم ظهور عنصر لا يثبت غيابه.', 'This list is partial: up to 32 qualified sources and 32 licences are displayed. An omitted item is not proven absent.') }}</p>
        <p v-if="!inventory.sources.length" data-license-empty>{{ t('لا تظهر مصادر مؤهلة الآن. لا نستنتج عدم وجود بيانات.', 'No qualified sources are visible now. This does not establish absence of data.') }}</p>
        <label for="license-source">{{ t('المصدر', 'Source') }}</label>
        <select id="license-source" v-model="draft.source" data-license-source :disabled="!canEdit"><option value="">{{ t('اختر المصدر', 'Choose source') }}</option><option v-for="row in inventory.sources" :key="row.source_id" :value="row.source_id">{{ row.label }} · {{ row.family }}</option></select>
        <template v-if="source">
            <p role="status" data-datasets-completeness>{{ source.datasets_completeness === 'PARTIAL' ? t('توجد تقارير مؤهلة أخرى خارج القائمة المعروضة (حتى ٨ تقارير).', 'More qualified reports exist beyond this displayed list (up to 8 reports).') : source.datasets_completeness === 'COMPLETE' ? t('قائمة التقارير المؤهلة المعروضة مكتملة حاليًا.', 'The displayed qualified report list is currently complete.') : t('اكتمال قائمة التقارير غير معروف.', 'Report list completeness is unknown.') }}</p>
            <label for="license-dataset">{{ t('التقرير والبيانات', 'Report and data') }}</label>
            <select id="license-dataset" v-model="draft.dataset" data-license-dataset :disabled="!canEdit"><option value="">{{ t('اختر تقريرًا', 'Choose report') }}</option><option v-for="row in source.datasets" :key="row.dataset_id" :value="row.dataset_id">{{ t('الإنفاق الإعلاني الأصلي', 'Native advertising expenditure') }} · {{ t('المعروض', 'Shown') }}: {{ row.record_count }} · {{ t('جمع التقرير', 'Report collection') }}: {{ coverage(row.coverage) }}{{ row.records_completeness === 'PARTIAL' ? t(' · سجلات أخرى غير معروضة', ' · More records not displayed') : row.records_completeness !== 'COMPLETE' ? t(' · اكتمال القائمة غير معروف', ' · List completeness unknown') : '' }}</option></select>
            <p v-if="dataset" data-report-acquisition>{{ t('الحقول: الإنفاق فقط. جمع التقرير', 'Fields: expenditure only. Report collection') }}: {{ coverage(dataset.coverage) }} · {{ t('السجلات المعروضة', 'Displayed records') }}: {{ dataset.record_count }}</p>
            <p v-if="dataset" role="status" data-records-completeness>{{ dataset.records_completeness === 'PARTIAL' ? t('توجد سجلات مؤهلة أخرى خارج المعروض (حتى ٣٢ سجلًا). اكتمال جمع التقرير لا يعني عرض جميع سجلاته.', 'More qualified records exist beyond the displayed selection (up to 32 records). Complete collection does not mean all records are displayed.') : dataset.records_completeness === 'COMPLETE' ? t('قائمة السجلات المعروضة لهذا التقرير مكتملة حاليًا.', 'The displayed record list for this report is currently complete.') : t('اكتمال قائمة السجلات غير معروف.', 'Record list completeness is unknown.') }}</p>
            <form v-if="exact(source.manager_grant_id)" @submit.prevent="issue">
                <fieldset :disabled="!canEdit">
                    <legend>{{ t('إصدار ترخيص جديد لنفسي', 'Issue a new licence for myself') }}</legend>
                    <label for="license-purpose">{{ t('الغرض', 'Purpose') }}</label><select id="license-purpose" v-model="draft.purpose" data-license-purpose><option value="">{{ t('اختر الغرض', 'Choose purpose') }}</option><option value="advertising_performance">{{ t('مراجعة أداء الإعلانات', 'Review advertising performance') }}</option></select>
                    <label for="license-operation">{{ t('الاستعمال المطلوب', 'Requested use') }}</label><select id="license-operation" v-model="draft.operation" data-license-operation><option value="display">{{ t('عرض البيانات المختارة', 'Display selected data') }}</option><option value="process">{{ t('معالجة الإنفاق لإعداد ملخص محدود', 'Process expenditure for a bounded summary') }}</option></select><p>{{ t('لا يجيز الترخيص التصدير أو التدريب. إتاحة ملخص لمستفيد تتطلب موافقة مستقلة ونشرًا وإسنادًا صريحًا.', 'This licence does not authorize export or training. Delivering a summary requires separate consent, publication and explicit assignment.') }}</p>
                    <label for="license-capacity">{{ t('صفتي بالنسبة للبيانات', 'My capacity for these data') }}</label><select id="license-capacity" v-model="draft.capacity"><option value="data_holder">{{ t('صاحب البيانات', 'Data holder') }}</option><option value="authorized_representative">{{ t('ممثل مفوض', 'Authorized representative') }}</option></select>
                    <label for="license-holder">{{ t('اسم صاحب البيانات', 'Data holder name') }}</label><input id="license-holder" v-model="draft.holder" data-license-holder autocomplete="off" maxlength="160">
                    <label for="license-reference">{{ t('مرجع التفويض أو التصريح', 'Mandate or declaration reference') }}</label><input id="license-reference" v-model="draft.reference" data-license-reference autocomplete="off" maxlength="500">
                    <p>{{ t('اكتب سند مسؤوليتك أو مرجع تفويض العميل، دون بيانات حساسة.', 'Enter the basis of your responsibility or the client mandate reference, without sensitive data.') }}</p>
                    <label for="license-expiry">{{ t('صالح حتى', 'Valid until') }}</label><input id="license-expiry" v-model="draft.expires" data-license-expiry type="datetime-local">
                    <label class="bf-check"><input v-model="draft.reviewNeeded" data-license-review-needed type="checkbox">{{ t('لست متأكدًا من أهليتي لمنح هذا الترخيص.', 'I am unsure of my eligibility to grant this licence.') }}</label>
                    <p v-if="draft.reviewNeeded" role="status" data-license-review-state>{{ t('لن يُرسل طلب إصدار وأنت غير متأكد. تحقق من سندك أو احصل على التفويض الصحيح. لا توجد مراجعة قانونية آلية أو طلب مراجعة مسجل هنا.', 'No issuance request will be sent while you are unsure. Check your authority or obtain the correct mandate. There is no automatic legal review or recorded review request here.') }}</p>
                    <label class="bf-check"><input v-model="draft.confirmed" data-license-confirm type="checkbox" :disabled="draft.reviewNeeded">{{ t('أؤكد صحة صفتي وحدود الطلب المحدد. لا أطلب حقوق إدارة المصدر أو الإعلان.', 'I confirm my capacity and this request’s boundaries. I do not request source or advertising management rights.') }}</label>
                    <button type="submit" class="workspace-button" data-license-issue :disabled="!canIssue">{{ t('إصدار الترخيص المحدد', 'Issue selected licence') }}</button>
                </fieldset>
            </form>
            <p v-else data-license-issue-unavailable>{{ t('إصدار ترخيص جديد يحتاج صلاحية مستقلة على المصدر. عرض الترخيص الحالي لا يمنح هذه الصلاحية.', 'Issuing a new licence requires independent source authority. Viewing the current licence does not grant it.') }}</p>
            <label for="license-current">{{ t('التراخيص المسجلة لهذا المصدر', 'Recorded licences for this source') }}</label><select id="license-current" v-model="draft.license" data-license-current :disabled="!canEdit"><option value="">{{ t('اختر ترخيصًا', 'Choose licence') }}</option><option v-for="row in licenses" :key="row.license_id" :value="row.license_id">{{ row.summary ? t('ملخص إنفاق', 'Expenditure summary') : operationLabel(row.operation) }} · {{ purposeLabel(row.purpose) }} · {{ licenseStatus(row.status) }} · {{ validUntil(row.valid_until) }}</option></select>
            <template v-if="license && license.source_id === source.source_id">
                <p data-license-selected-fields>{{ t('الحقول المشمولة', 'Licensed fields') }}
                    {{ license.selection.field_keys.map(fieldLabel).join(locale === 'ar' ? '، ' : ', ') }}
                    · {{ t('عدد السجلات المشمولة', 'Licensed records') }} {{ license.selection.native_keys.length }}
                </p>
                <p>{{ t('الغرض', 'Purpose') }}: {{ purposeLabel(license.purpose) }} · {{ t('الاستعمال المسموح', 'Permitted use') }}: {{ operationLabel(license.operation) }} · {{ t('صالح حتى', 'Valid until') }}: {{ validUntil(license.valid_until) }}</p>
                <p>{{ t('سحب الترخيص يمنع الاستعمال اللاحق المعتمد عليه. يحتفظ النظام بالتاريخ ولا يدّعي محو مشتقات سابقة.', 'Withdrawal prevents subsequent use relying on this licence. History is retained; prior derivatives are not claimed erased.') }}</p>
                <template v-if="license.is_declarant && license.resource !== 'business.expenditure_summary'">
                    <label class="bf-check"><input v-model="draft.withdrawConfirmed" data-license-withdraw-confirm type="checkbox" :disabled="!canEdit || license.status === 'withdrawn'">{{ t('أؤكد سحب هذا الترخيص المحدد، مع حفظ تاريخه. لا يتطلب السحب إقرارًا جديدًا بأهلية الإصدار.', 'I confirm withdrawing this exact licence while retaining its history. Withdrawal does not require a new declaration of eligibility to issue.') }}</label>
                    <button type="button" class="workspace-button" data-license-withdraw :disabled="!canEdit || license.status === 'withdrawn' || !draft.withdrawConfirmed" @click="withdraw(license)">{{ t('سحب هذا الترخيص', 'Withdraw this licence') }}</button>
                </template>
                <label for="license-binding">{{ t('صلاحية ربط البيانات بالنشاط', 'Permission to link data to this business') }}</label><select id="license-binding" v-model="draft.binding" data-license-binding :disabled="!canEdit"><option value="">{{ t('اختر صلاحية مؤهلة', 'Choose qualified permission') }}</option><option v-for="(row,index) in inventory.binding_options" :key="row.grant_id" :value="row.grant_id">{{ t('إدارة ربط هذا النشاط', 'Manage this business’s data links') }} {{ index + 1 }}</option></select>
                <p>{{ license.covered ? t('النشاط مشمول بالتغطية.', 'This business is covered.') : t('يحتاج النشاط إلى تغطية مستقلة.', 'Independent Business coverage is required.') }} {{ license.bound ? t('نطاق الاستعمال مربوط بالنشاط.', 'The use scope is bound to this business.') : t('يحتاج نطاق الاستعمال إلى ربط مستقل.', 'The use scope requires an independent binding.') }}</p>
                <button type="button" class="workspace-button" data-license-cover :disabled="!canEdit || !currentLicense || license.covered || !qualifiedBinding || !exact(license.boundary_grant_id) || !Number.isSafeInteger(license.coverage_revision)" @click="cover">{{ t('تحديد هذا النشاط ضمن الترخيص', 'Include this business in the licence') }}</button>
                <button type="button" class="workspace-button" data-license-bind :disabled="!canEdit || !currentLicense || !license.covered || license.bound || !qualifiedBinding || !exact(license.use_grant_id) || !Number.isSafeInteger(license.binding_revision)" @click="bind">{{ t('إتاحة الترخيص لهذا النشاط', 'Enable this licence for this business') }}</button>
                <label for="license-use">{{ t('صلاحية استعمال البيانات لهذا النشاط', 'Permission to use data for this business') }}</label><select id="license-use" v-model="draft.businessUse" data-license-use :disabled="!canEdit"><option value="">{{ t('اختر صلاحية مؤهلة', 'Choose qualified permission') }}</option><option v-for="(row,index) in inventory.business_use_options" :key="row.grant_id" :value="row.grant_id">{{ t('استعمال البيانات لهذا النشاط', 'Use data for this business') }} {{ index + 1 }}</option></select>
                <button type="button" class="workspace-button" data-license-read :disabled="!canRead" @click="consume">{{ t('عرض الإنفاق المرخّص', 'Display licensed expenditure') }}</button>
                <fieldset v-if="license.operation === 'process'" :disabled="!canEdit" data-expenditure-summary>
                    <legend>{{ t('إعداد ملخص إنفاق للمستفيدين', 'Prepare an expenditure summary for beneficiaries') }}</legend>
                    <p>{{ t('ملخص لسجل واحد يعرض الإنفاق وعملته وتغطية التقرير. لا يتيح الحساب أو تفاصيل الحملات، ولا يجمع السجلات أو يثبت نتائج المبيعات.', 'A single-record summary shows expenditure, currency and report coverage. It grants no account or campaign detail, combines no records and proves no sales outcome.') }}</p>
                    <label for="summary-record">{{ t('سجل الإنفاق', 'Expenditure record') }}</label><select id="summary-record" v-model="draft.summaryRecord" data-summary-record><option value="">{{ t('اختر سجلًا', 'Choose a record') }}</option><option v-for="(row,index) in license.material_refs" :key="row.key" :value="String(index)">{{ recordLabel(row) }}</option></select>
                    <section v-if="summaryInput" data-summary-record-review aria-live="polite">
                        <p>{{ recordLabel(summaryInput) }}</p>
                        <p v-if="summaryMetadata">{{ windowBoundary(summaryMetadata.boundary) }} · {{ windowGrain(summaryMetadata.grain) }} · {{ t('التوقيت', 'Timezone') }}: {{ summaryMetadata.timezone ?? t('غير معروف', 'Unknown') }}</p>
                        <p>{{ t('هذا تعريف للسجل من المصدر، وليس معاينة لقيمة الإنفاق. لا يمنح ترخيص المعالجة حق عرض الأصل أو تسليمه.', 'This identifies the source record; it is not an expenditure preview. A processing licence does not grant display or delivery of the original.') }}</p>
                    </section>
                    <button type="button" class="workspace-button" data-summary-process :disabled="!canProcessSummary" @click="processSummary">{{ t('تأهيل السجل للمعالجة', 'Qualify the record for processing') }}</button>
                    <p v-if="draft.summaryRead?.license === license.license_id" role="status" data-summary-process-ready>{{ t('تمت معالجة السجل تحت الترخيص المحدد. أكمل موافقتك لإعداد الملخص.', 'The record was processed under the selected licence. Complete your consent to prepare the summary.') }}</p>
                    <label for="summary-expiry">{{ t('صلاحية الملخص حتى، بتوقيت جهازك', 'Summary valid until, in your device’s timezone') }}</label><input id="summary-expiry" v-model="draft.summaryExpires" data-summary-expiry type="datetime-local">
                    <label class="bf-check"><input v-model="draft.summaryConfirmed" data-summary-confirm type="checkbox">{{ t('أوافق صراحة على اشتقاق هذا الملخص وربطه بالنشاط وتسليمه للمستفيدين الذين أعيّنهم لاحقًا ضمن المدة. لا تُحفظ نسخة إضافية من قيمة الإنفاق ولا يُسمح بتسليم الأصل.', 'I explicitly consent to deriving this summary, binding it to this business and delivering it to beneficiaries I later name within this period. No extra expenditure payload is retained and the original cannot be delivered.') }}</label>
                    <button type="button" class="workspace-button" data-summary-prepare :disabled="!canPrepareSummary" @click="prepareSummary">{{ t('تأكيد الموافقة وإعداد الملخص', 'Confirm consent and prepare summary') }}</button>
                </fieldset>
                <section v-if="license.summary" data-summary-publication>
                    <p>{{ license.summary.state === 'published' ? t('نُشر الملخص وأصبح مؤهلًا للإسناد الصريح.', 'The summary is published and eligible for explicit assignment.') : t('الملخص مُعدّ ولم يُنشر بعد. لا يستطيع المستفيد قراءته قبل النشر.', 'The summary is prepared and unpublished. A beneficiary cannot read it before publication.') }}</p>
                    <p>{{ t('سحب ترخيص المعالجة الأصلي يوقف هذا الملخص. يمكنك سحب إسناد مستفيد محدد أدناه.', 'Withdrawing the original processing licence stops this summary. You can revoke a named beneficiary’s assignment below.') }}</p>
                    <button v-if="license.summary.state === 'prepared'" type="button" class="workspace-button" data-summary-publish :disabled="!canPublishSummary" @click="publishSummary">{{ t('نشر الملخص ضمن الموافقة', 'Publish summary within consent') }}</button>
                </section>
                <details v-if="license.is_declarant && readable(license.operation)" class="workspace-disclosure" data-license-beneficiary-journey>
                    <summary>{{ t('إسناد الاستعمال المرخّص لمستفيد', 'Assign licensed use to a beneficiary') }}</summary>
                    <p data-assignment-limits>{{ t('تعيين الاستعمال لا يمنح إدارة الحساب أو قراءة الأصل؛ يعاد فحص شروط القراءة عند الاستخدام. يبقى التقرير والحقول والغرض والفعل كما في الترخيص المختار، ولا يُسمح للمستفيد بإعادة التفويض.', 'Assigning use grants neither account management nor original-data reading; reading conditions are checked again at use. The selected licence’s report, fields, purpose and operation remain fixed, and the beneficiary cannot delegate it onward.') }}</p>
                    <p>{{ t('الأسماء المتاحة تخص أشخاصًا فوضتهم صراحة باستعمال بيانات هذا النشاط ضمن السياق الحالي. عضوية الفريق وحدها لا تكفي.', 'Available names belong to people you explicitly delegated Business data use to in the current context. Team membership alone is insufficient.') }}</p>
                    <fieldset :disabled="!canEdit">
                        <legend>{{ t('إسناد محدد أو سحبه', 'Assign or revoke bounded use') }}</legend>
                        <label for="assignment-authority">{{ t('صلاحية إسناد الاستعمال لهذا النشاط', 'Authority to assign use for this business') }}</label><select id="assignment-authority" v-model="draft.assignmentAuthority" data-assignment-authority><option value="">{{ t('اختر صلاحية مؤهلة', 'Choose qualified authority') }}</option><option v-for="(row,index) in inventory.business_use_options" :key="row.grant_id" :value="row.grant_id">{{ t('استعمال بيانات هذا النشاط', 'Business data use') }} {{ index + 1 }}</option></select>
                        <label v-if="beneficiaries.length" for="assignment-beneficiary">{{ t('المستفيد المؤهل', 'Qualified beneficiary') }}</label><select v-if="beneficiaries.length" id="assignment-beneficiary" v-model="draft.beneficiary" data-assignment-beneficiary><option value="">{{ t('اختر مستفيدًا مؤهلًا', 'Choose a qualified beneficiary') }}</option><option v-for="row in beneficiaries" :key="row.public_id" :value="row.public_id">{{ row.name }} · {{ row.realm_kind === 'agency' ? t('تفويض جهة', 'Agency mandate') : t('تفويض مباشر', 'Direct mandate') }}</option></select>
                        <p v-if="!beneficiaries.length" data-assignment-unavailable>{{ t('لا يوجد مستفيد مؤهل ظاهر الآن. لا يمكن إدخال هوية بديلة أو استنتاج صلاحية من العضوية.', 'No qualified beneficiary is visible now. You cannot enter an alternative identity or infer permission from membership.') }}</p>
                        <label for="assignment-expiry">{{ t('صلاحية الإسناد حتى، بتوقيت جهازك', 'Assignment valid until, in your device’s timezone') }}</label><input id="assignment-expiry" v-model="draft.assignmentExpires" data-assignment-expiry type="datetime-local">
                        <p>{{ t('لا تتجاوز مدة الإسناد الترخيص أو تفويض النشاط؛ يتحقق الخادم من الحدين عند الطلب.', 'The assignment cannot outlast the licence or Business mandate; both limits are checked by the server when requested.') }}</p>
                        <label class="bf-check"><input v-model="draft.assignmentConfirmed" data-assignment-confirm type="checkbox">{{ t('أؤكد إسناد الاستعمال المحدد للمستفيد المختار، أو سحب الإسناد الذي أختاره أدناه، دون توسيع الحقوق.', 'I confirm assigning this bounded use to the chosen beneficiary, or revoking the assignment I choose below, without expanding rights.') }}</label>
                        <button type="button" class="workspace-button" data-assignment-assign :disabled="!canAssign" @click="assignBeneficiary">{{ t('إسناد الترخيص للمستفيد المختار', 'Assign licence to chosen beneficiary') }}</button>
                        <ul v-if="assignments.length" data-assignment-history><li v-for="row in assignments" :key="row.grant_id"><strong>{{ beneficiaryName(row.recipient_public_id) }}</strong> · <span>{{ row.status === 'active' ? t('الاستعمال المرخّص مسند', 'Licensed use assigned') : t('الإسناد مسحوب', 'Assignment revoked') }}</span> · {{ t('صالح حتى', 'Valid until') }}: {{ validUntil(row.valid_until) }}<button v-if="row.status === 'active'" type="button" class="workspace-button" data-assignment-revoke :disabled="!canEdit || !draft.assignmentConfirmed || !assignmentAuthority || !license.boundary_grant_id" :aria-label="t('سحب إسناد الاستعمال من ', 'Revoke assigned use from ') + beneficiaryName(row.recipient_public_id)" @click="revokeAssignment(row)">{{ t('سحب هذا الإسناد', 'Revoke this assignment') }}</button></li></ul>
                    </fieldset>
                </details>
            </template>
        </template>
        <section v-if="inventory.assigned_licenses.length" data-assigned-licenses>
            <h3>{{ t('تراخيص أُسندت إليك', 'Licences assigned to you') }}</h3>
            <p>{{ t('يمكنك مراجعة حدود الإسناد. عرض بيانات الحساب يحتاج صلاحية مستقلة.', 'You can review the assignment limits. Displaying account data requires separate permission.') }}</p>
            <template v-if="inventory.assigned_licenses.some(row => row.artifact_ref)">
                <label for="assigned-business-use">{{ t('صلاحية استعمال البيانات لهذا النشاط', 'Permission to use data for this business') }}</label><select id="assigned-business-use" v-model="draft.businessUse" data-assigned-business-use :disabled="!canEdit"><option value="">{{ t('اختر صلاحية مؤهلة', 'Choose qualified permission') }}</option><option v-for="(row,index) in inventory.business_use_options" :key="row.grant_id" :value="row.grant_id">{{ t('استعمال البيانات لهذا النشاط', 'Use data for this business') }} {{ index + 1 }}</option></select>
            </template>
            <ul><li v-for="row in inventory.assigned_licenses" :key="row.license_id">
                {{ purposeLabel(row.purpose) }} · {{ operationLabel(row.operation) }} · {{ t('صالح حتى', 'Valid until') }} {{ validUntil(row.valid_until) }}
                <button v-if="row.artifact_ref" type="button" class="workspace-button" data-assigned-summary-read :disabled="!canEdit || !qualifiedUse || reading" @click="readAssigned(row)">{{ t('عرض ملخص الإنفاق المسموح', 'Display permitted expenditure summary') }}</button>
            </li></ul>
        </section>
        <section v-if="historicalLicenses.length" data-license-own-history>
            <h3>{{ t('ترخيص سابق لهذا النشاط', 'Previous licence for this business') }}</h3>
            <p>{{ t('يمكنك مراجعة تراخيصك وسحبها مع بقاء بيانات المصدر غير متاحة. لا يتيح هذا السجل قراءة الأصل أو إدارة الحساب.', 'You can review and withdraw your licences while source data remains unavailable. This record grants neither original-data reading nor account management.') }}</p>
            <label for="license-own-history">{{ t('اختر ترخيصًا سابقًا', 'Choose a previous licence') }}</label>
            <select id="license-own-history" v-model="draft.license" data-license-history :disabled="!canEdit"><option value="">{{ t('اختر ترخيصًا', 'Choose licence') }}</option><option v-for="(row,index) in historicalLicenses" :key="row.license_id" :value="row.license_id">{{ t('ترخيص سابق لهذا النشاط', 'Previous licence for this business') }} {{ index + 1 }} · {{ licenseStatus(row.status) }} · {{ validUntil(row.valid_until) }}</option></select>
            <template v-if="historicalLicense && historicalLicense.resource !== 'business.expenditure_summary'">
                <p>{{ licenseStatus(historicalLicense.status) }} · {{ t('صالح حتى', 'Valid until') }}: {{ validUntil(historicalLicense.valid_until) }}</p>
                <label class="bf-check"><input v-model="draft.withdrawConfirmed" data-license-withdraw-confirm type="checkbox" :disabled="!canEdit || historicalLicense.status === 'withdrawn'">{{ t('أؤكد سحب هذا الترخيص المحدد، مع حفظ تاريخه.', 'I confirm withdrawing this exact licence while retaining its history.') }}</label>
                <button type="button" class="workspace-button" data-license-withdraw :disabled="!canEdit || historicalLicense.status === 'withdrawn' || !draft.withdrawConfirmed" @click="withdraw(historicalLicense)">{{ t('سحب هذا الترخيص', 'Withdraw this licence') }}</button>
            </template>
        </section>
        <details v-if="inventory.operating_mandate.available" class="workspace-disclosure" data-operating-mandate>
            <summary>{{ t('تأهيل صلاحية النشاط لاستعمال البيانات', 'Qualify Business authority for data use') }}</summary>
            <p>{{ t('تصريح مستقل بالمسؤولية أو تفويض العميل، يمنح فقط استعمال البيانات وإدارة ربطها. لا يثبت ملكية قانونية ولا يمنح حق المصدر أو الإعلان.', 'A separate responsible-Business or client-mandate declaration grants only data use and link management. It does not verify legal ownership or grant source or advertising rights.') }}</p>
            <fieldset :disabled="!canEdit">
                <legend>{{ t('تفويض مستقل لهذا النشاط', 'Separate mandate for this business') }}</legend>
                <label for="mandate-capacity">{{ t('صفتي للنشاط', 'My Business capacity') }}</label><select id="mandate-capacity" v-model="draft.mandateCapacity"><option value="responsible_business">{{ t('مسؤول عن النشاط', 'Responsible for the business') }}</option><option value="client_mandate">{{ t('لدي تفويض العميل', 'I hold the client mandate') }}</option></select>
                <label for="mandate-reference">{{ t('مرجع تفويض النشاط أو التصريح', 'Business mandate or declaration reference') }}</label><input id="mandate-reference" v-model="draft.mandateReference" data-mandate-reference autocomplete="off" maxlength="500">
                <label for="mandate-expiry">{{ t('صالح حتى، بتوقيت جهازك', 'Valid until, in your device’s timezone') }}</label><input id="mandate-expiry" v-model="draft.mandateExpires" data-mandate-expiry type="datetime-local">
                <label class="bf-check"><input v-model="draft.mandateConfirmed" data-mandate-confirm type="checkbox">{{ t('أؤكد أنني مسؤول عن النشاط أو أحمل تفويض العميل لهذا الاستعمال المحدد.', 'I confirm that I am responsible for this business or hold the client mandate for this bounded use.') }}</label>
                <button type="button" class="workspace-button" data-license-qualify :disabled="!canEdit || !draft.mandateConfirmed || !draft.mandateReference || !draft.mandateExpires" @click="qualify">{{ t('تأكيد التفويض المستقل للنشاط', 'Confirm separate Business mandate') }}</button>
            </fieldset>
        </details>
        <p v-else-if="!inventory.business_use_options.length" data-license-mandate-unavailable>{{ t('لا تظهر صلاحية مستقلة لاستعمال البيانات أو تأهيل ذاتي للنشاط. يلزم تفويض مؤهل؛ لا توجد هنا آلية مراجعة قانونية تمنحه تلقائيًا.', 'No independent data-use permission or self-qualification path is available for this business. A qualified mandate is required; this screen does not provide automatic legal approval.') }}</p>
    </template>
    <section v-if="materials" data-license-materials>
        <h3>{{ materials.materials.every(row => row.ref.namespace === 'business.expenditure_summary') ? t('ملخص الإنفاق المرخّص', 'Licensed expenditure summary') : t('الإنفاق الأصلي المرخّص', 'Licensed native expenditure') }}</h3>
        <p>{{ t('قيم يصرح بها المصدر؛ ليست إيرادًا أو توفيرًا أو نتيجة تجارية متحققة. غياب القيمة لا يعني صفرًا.', 'Source-asserted values; these are not revenue, savings or verified business outcomes. A missing value does not mean zero.') }}</p>
        <ul><li v-for="item in displayedMaterials" :key="`${item.ref.namespace}:${item.ref.key}:${item.ref.version}`">{{ t('الإنفاق', 'Expenditure') }}:
            <template v-if="item.amount"><bdi data-native-amount>{{ item.amount.value }}</bdi> <bdi v-if="item.amount.currency" data-native-currency>{{ item.amount.currency }}</bdi><span v-else>{{ t('العملة غير معروفة', 'Currency unknown') }}</span></template>
            <span v-else data-native-amount-unknown>{{ t('وحدة الإنفاق غير محسومة', 'Expenditure unit unknown') }}</span>
            · {{ t('التغطية', 'Coverage') }}: <span data-native-coverage>{{ coverage(item.coverage) }}</span>
        </li></ul>
    </section>
</section>
</template>
