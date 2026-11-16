<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { BriefcaseBusiness, Plus } from '@lucide/vue';
import type { Surface } from './api';
import BusinessPracticeKnowledge from './BusinessPracticeKnowledge.vue';
import BusinessSheet from './BusinessSheet.vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { activityCommand, activityResource, definitiveActivityRejection, retainActivityCommand, settleActivityCommand } from './activityPending';
import { practiceId, readPractices, writePractice, type Practice, type PracticeCommand, type PracticeGeography, type PracticeKind, type PracticeOperation, type PracticeValue } from './activity';

const props = withDefaults(defineProps<{ surface: Surface; refreshing?: boolean; active?: boolean }>(), { refreshing: false, active: true });
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const business = computed(() => props.surface.business.business_public_id);
const resource = computed(() => activityResource(business.value, 'practice'));
const list = ref<Practice[]>([]), history = ref<Practice[]>([]), loading = ref(false), busy = ref(false), notice = ref(''), denied = ref(false);
const editing = ref(false), selected = ref(''), revision = ref(0), operation = ref<PracticeOperation>('create'), writeGrant = ref('');
const editorStep = ref<'offer' | 'coverage'>('offer');
const stepHeading = ref<HTMLElement>();
const listLoaded = ref(false);
const conflict = ref(false);
const correctionReason = ref('');
const historyAvailable = ref(false);
const pending = ref(activityCommand<PracticeCommand>(resource.value));
const emptyGeography = (): PracticeGeography => ({ status: 'unspecified', areas: [] });
const empty = (): PracticeValue => ({ name: '', description: '', kind: 'sale', sectors: [], specializations: [], offering: null,
    commercial_model: null, channels: [], target_markets: emptyGeography(), fulfillment: emptyGeography() });
const draft = reactive(empty()), sectorText = ref(''), specializationText = ref(''), channelText = ref('');
const cityText = reactive({ target_markets: '', fulfillment: '' });
const draftSignature = () => JSON.stringify([draft, sectorText.value, specializationText.value, channelText.value, cityText, operation.value, correctionReason.value]);
let editBaseline = '';
const options = computed(() => props.surface.context_write_options.filter(option => Number.isSafeInteger(Number(option.grant_id))));
const canWrite = computed(() => !denied.value && !props.refreshing && options.value.some(option => option.grant_id === writeGrant.value));
const kinds = computed(() => [
    { value: 'sale', label: t('بيع منتجات', 'Sell products') }, { value: 'installation', label: t('تركيب', 'Installation') },
    { value: 'maintenance', label: t('صيانة', 'Maintenance') }, { value: 'service', label: t('تقديم خدمة', 'Provide a service') },
    { value: 'production', label: t('إنتاج أو تحضير', 'Production or preparation') }, { value: 'brokerage', label: t('وساطة', 'Brokerage') },
    { value: 'rental', label: t('تأجير', 'Rental') }, { value: 'other', label: t('عمل آخر', 'Other work') },
]);
const kindLabel = (kind: PracticeKind) => kinds.value.find(item => item.value === kind)?.label ?? kind;
const operationLabel = (value: PracticeOperation) => ({ create: t('إضافة', 'Added'), update: t('تحديث', 'Updated'), correct: t('تصحيح', 'Corrected'), reclassify: t('إعادة تصنيف', 'Reclassified'), deactivate: t('إيقاف', 'Deactivated') })[value];
const split = (value: string) => [...new Set(value.split(/[,،\n]/).map(value => value.trim()).filter(Boolean))];
const countryName = (code: string) => { const item = props.surface.catalog.countries.find(item => item.code === code); return item ? t(item.name_ar, item.name_en) : code; };
const geographyLabel = (geography: PracticeGeography) => geography.status === 'unspecified' ? t('لم يُصرّح به', 'Not declared')
    : geography.areas.length ? geography.areas.map(area => area.level === 'country' ? countryName(area.ref) : area.ref).join(t('، ', ', ')) : t('لا مناطق مشمولة في التصريح', 'No areas included in the declaration');
const countries = (geography: PracticeGeography) => geography.areas.filter(area => area.level === 'country').map(area => area.ref);
function setCountries(geography: PracticeGeography, event: Event) {
    const values = Array.from((event.target as HTMLSelectElement).selectedOptions).map(option => option.value);
    geography.areas = [...geography.areas.filter(area => area.level !== 'country'), ...values.map(ref => ({ authority: 'iso3166-1', level: 'country', ref }))];
}
let serial = 0, disposed = false;
onBeforeUnmount(() => { disposed = true; serial++; });
function conceal() { serial++; loading.value = false; list.value = []; listLoaded.value = false; history.value = []; historyAvailable.value = false; editing.value = false; conflict.value = false; Object.assign(draft, empty()); sectorText.value = ''; specializationText.value = ''; channelText.value = ''; cityText.target_markets = ''; cityText.fulfillment = ''; }
async function load(practice?: string) {
    if (props.refreshing || !props.active || disposed) return;
    const access = props.surface.authority_selection.requirements.find(item => item.requirement_kind === 'business')?.leaf_grant_id;
    if (!Number.isSafeInteger(access)) return;
    const captured = stamp(), attempt = ++serial, subject = props.surface;
    loading.value = true; notice.value = ''; history.value = [];
    try {
        const result = await readPractices(business.value, access!, practice);
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject) return;
        if (result.business_public_id !== business.value) throw Error('Practice list subject mismatch');
        historyAvailable.value = result.history_available === true;
        if (!practice) {
            list.value = result.practices;
            listLoaded.value = true;
        }
        else list.value = list.value.map(item => result.practices.find(current => current.practice_id === item.practice_id) ?? item);
        history.value = historyAvailable.value ? result.history ?? [] : []; denied.value = false;
    } catch (error) {
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject) return;
        if (error instanceof TransportError && error.status === 403) { conceal(); denied.value = true; }
        notice.value = t('تعذّر تأهيل قراءة الممارسات. حدّث الوصول وحاول مجددًا.', 'Practices could not be read under current access. Refresh access and try again.');
    } finally { if (!disposed && attempt === serial) loading.value = false; }
}
watch(() => [props.surface, props.refreshing, props.active], () => {
    conceal(); loading.value = false; busy.value = false; denied.value = false; pending.value = activityCommand<PracticeCommand>(resource.value);
    writeGrant.value = options.value.length === 1 ? options.value[0].grant_id : '';
    if (props.active && !props.refreshing) void load();
}, { immediate: true, flush: 'sync' });
function edit(practice?: Practice) {
    if (busy.value || pending.value || !canWrite.value) return;
    selected.value = practice?.practice_id ?? practiceId(); revision.value = practice?.revision ?? 0;
    operation.value = practice ? 'update' : 'create'; Object.assign(draft, clone(practice?.value ?? empty()));
    conflict.value = false; correctionReason.value = '';
    sectorText.value = draft.sectors.join(t('، ', ', ')); specializationText.value = draft.specializations.join(t('، ', ', ')); channelText.value = draft.channels.join(t('، ', ', '));
    for (const area of ['target_markets', 'fulfillment'] as const) cityText[area] = draft[area].areas.filter(item => item.authority === 'business_declared' && item.level === 'city').map(item => item.ref).join(t('، ', ', '));
    editBaseline = draftSignature();
    editorStep.value = 'offer';
    editing.value = true; history.value = []; notice.value = '';
}
function closeEditor() {
    if (busy.value || pending.value) return;
    if (draftSignature() !== editBaseline && !confirm(t('هل تريد إغلاق النافذة وتجاهل تعديلات هذا العمل؟', 'Close and discard the changes to this activity?'))) return;
    editing.value = false;
}
async function showStep(step: 'offer' | 'coverage') {
    editorStep.value = step;
    await nextTick();
    stepHeading.value?.focus({ preventScroll: true });
    stepHeading.value?.scrollIntoView?.({ block: 'nearest' });
}
function submitEditor() {
    if (busy.value || !canWrite.value || conflict.value && !pending.value) return;
    if (pending.value || editorStep.value === 'coverage') return save();
    if (draft.name.trim().length >= 2) return showStep('coverage');
}
async function save(deactivate = false) {
    if (busy.value || !canWrite.value || conflict.value && !pending.value) return;
    const id = business.value, subject = props.surface, captured = stamp(), resourceKey = resource.value;
    if (!pending.value) {
        if (!selected.value || (!deactivate && !draft.name.trim())) return;
        const value = clone(draft); value.sectors = split(sectorText.value); value.specializations = split(specializationText.value); value.channels = split(channelText.value);
        for (const area of ['target_markets', 'fulfillment'] as const) value[area].areas = value[area].status === 'unspecified' ? [] : [
            ...value[area].areas.filter(item => item.authority !== 'business_declared' || item.level !== 'city'),
            ...split(cityText[area]).map(ref => ({ authority: 'business_declared', level: 'city', ref })),
        ];
        value.offering = value.offering?.trim() || null; value.commercial_model = value.commercial_model?.trim() || null;
        try { pending.value = retainActivityCommand(resourceKey, { command_id: crypto.randomUUID(), practice_id: selected.value,
            operation: deactivate ? 'deactivate' : operation.value, expected_revision: revision.value, value: deactivate ? null : value,
            ...(!deactivate && operation.value === 'correct' ? { correction_reason: correctionReason.value.trim() } : {}),
            authority_selection: { ...clone(subject.authority_selection), requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(writeGrant.value) }] },
            read_selection: clone(subject.authority_selection) }); }
        catch { notice.value = t('تعذّر حفظ بيانات استرجاع الطلب في هذه الجلسة. لم يُرسل طلب جديد؛ أتح التخزين قبل المتابعة.', 'Recovery data could not be retained in this session. No new request was sent; enable session storage before continuing.'); return; }
    }
    const uncertain = pending.value.uncertain; pending.value.uncertain = true; busy.value = true; notice.value = '';
    try {
        const result = await writePractice(id, pending.value.command);
        if (disposed || !owned(captured) || props.surface !== subject) return;
        if (result.business_public_id !== id || result.practice.practice_id !== pending.value.command.practice_id) throw Error('Practice receipt subject mismatch');
        settleActivityCommand(resourceKey); pending.value = undefined; editing.value = false;
        await load();
        notice.value = result.historical_receipt ? t('تم التحقق من الطلب السابق. يعرض السجل نسخته الأصلية؛ راجع الممارسة الحالية قبل المتابعة.', 'The earlier command was reconciled. Its receipt retains the original revision; review the current practice before continuing.') : t('تم حفظ الممارسة بتأكيد من الخادم.', 'Practice saved with server confirmation.');
    } catch (error) {
        if (disposed || !owned(captured) || props.surface !== subject) return;
        if (error instanceof TransportError && error.status === 403) {
            if (pending.value) pending.value.denied = true;
            conceal(); denied.value = true;
            notice.value = t('الوصول الحالي لا يسمح بالعملية أو بكشف نتيجتها. الطلب الأصلي محفوظ؛ تحقّق من صلاحياتك.', 'Current access does not permit the operation or disclosure of its result. The original request is retained; review your permissions.');
        } else if (error instanceof TransportError && definitiveActivityRejection(error.status, uncertain, (error.body as { error?: string }).error)) {
            settleActivityCommand(resourceKey); pending.value = undefined; conflict.value = error.status === 409;
            await load(); notice.value = t('لم يُقبل الطلب. احتفظنا بمسودتك؛ راجع البيانات والإصدار الحالي قبل المحاولة من جديد.', 'The request was rejected. Your draft is retained; review the current data and revision before trying again.');
        } else notice.value = t('نتيجة الحفظ غير محسومة. تحقّق بالطلب الأصلي قبل إنشاء طلب آخر.', 'The save outcome is unresolved. Check using the original request before creating another command.');
    } finally { if (!disposed && owned(captured) && props.surface === subject) busy.value = false; }
}
</script>

<template>
    <section class="bf-practices" data-business-practices :aria-busy="loading || busy">
        <header v-if="listLoaded && list.length" class="bf-practice-toolbar"><h2 class="sr-only">{{ t('العروض المسجّلة', 'Declared offers') }}</h2><button v-if="canWrite && !pending" type="button" class="bf-primary" data-practice-add @click="edit()"><Plus aria-hidden="true" />{{ t('إضافة عمل', 'Add an activity') }}</button></header>
        <label v-if="options.length > 1 && !denied" for="bf-practice-grant">{{ t('تفويض التعديل', 'Editing delegation') }}<select id="bf-practice-grant" v-model="writeGrant" :disabled="busy || !!pending"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, index) in options" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}</option></select></label>
        <p v-if="loading" role="status">{{ t('جارٍ قراءة الأعمال المحفوظة…', 'Reading saved activities…') }}</p>
        <div v-if="listLoaded && !list.length && !denied" class="bf-practice-empty" data-practice-empty>
            <span class="bf-practice-icon" aria-hidden="true"><BriefcaseBusiness /></span>
            <h2>{{ t('ابدأ بتعريف أول منتج أو خدمة', 'Describe your first product or service') }}</h2>
            <p>{{ t('عرّف ما تقدّمه، ثم اختر السوق الذي تستهدفه والمكان الذي تستطيع تقديمه فيه.', 'Describe what you offer, your intended market and where you can deliver.') }}</p>
            <button v-if="canWrite && !pending" type="button" class="bf-primary" data-practice-add @click="edit()"><Plus aria-hidden="true" />{{ t('إضافة أول عمل', 'Add your first activity') }}</button>
        </div>
        <p v-if="!options.length && !denied" class="bf-badge" data-practice-readonly>{{ t('قراءة فقط', 'Read only') }}</p>
        <ul v-if="list.length" class="bf-practice-list">
            <li v-for="practice in list" :key="practice.practice_id" :data-practice-id="practice.practice_id">
                <div class="bf-practice-heading"><span class="bf-practice-icon" aria-hidden="true"><BriefcaseBusiness /></span><div><h3>{{ practice.value.name }}</h3><span class="bf-practice-kind">{{ practice.state === 'active' ? kindLabel(practice.value.kind) : t('متوقفة', 'Inactive') }}</span></div></div>
                <p v-if="practice.value.description">{{ practice.value.description }}</p><p v-if="practice.value.offering">{{ t('ما يُقدم:', 'Offering:') }} {{ practice.value.offering }}</p>
                <dl class="bf-practice-context"><div><dt>{{ t('السوق المستهدف', 'Intended market') }}</dt><dd>{{ geographyLabel(practice.value.target_markets) }}</dd></div><div><dt>{{ t('نطاق تقديم العرض', 'Declared service area') }}</dt><dd>{{ geographyLabel(practice.value.fulfillment) }}</dd></div></dl>
                <div class="bf-practice-actions"><button v-if="canWrite && practice.state === 'active'" type="button" :disabled="busy || !!pending" data-practice-edit @click="edit(practice)">{{ t('تعديل', 'Edit') }}</button><button v-if="historyAvailable" type="button" :disabled="loading || busy" data-practice-history @click="load(practice.practice_id)">{{ t('عرض التغييرات', 'View changes') }}</button></div>
            </li>
        </ul>
        <p v-if="list.length && !historyAvailable && !loading" role="status" data-practice-history-unavailable>{{ t('صلاحية قراءة المعلومات الحالية لا تتيح سجل التغييرات الخاص.', 'Access to current declarations does not release private revision history.') }}</p>
        <section v-if="history.length" data-practice-revisions><h3>{{ t('التغييرات المحفوظة', 'Saved changes') }}</h3><ol><li v-for="entry in history" :key="`${entry.practice_id}:${entry.revision}`">{{ operationLabel(entry.operation) }} — {{ entry.value.name }} · {{ t('نسخة', 'Revision') }} {{ entry.revision }} <time :datetime="entry.recorded_at">{{ new Date(entry.recorded_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') }}</time><p v-if="entry.corrects_revision !== null && entry.correction_reason">{{ t('تصحيح النسخة', 'Correction of revision') }} {{ entry.corrects_revision }}: {{ entry.correction_reason }}</p></li></ol><p>{{ t('كل تغيير يحتفظ بنسخته؛ الإيقاف لا يمحو التقييمات السابقة.', 'Each change retains its revision; deactivation does not erase earlier assessments.') }}</p></section>
        <BusinessSheet :open="editing && !denied" labelled-by="business-activity-sheet-title" @close="closeEditor">
        <form v-if="editing && !denied" class="bf-form" data-practice-form @submit.prevent="submitEditor">
            <h3 id="business-activity-sheet-title" tabindex="-1" autofocus>{{ operation === 'create' ? t('إضافة عمل', 'Add an activity') : t('تعديل العرض', 'Edit offer') }}</h3>
            <ol class="bf-practice-steps" :aria-label="t('خطوات تحرير العمل', 'Activity editing steps')">
                <li :aria-current="editorStep === 'offer' ? 'step' : undefined">{{ t('تعريف العرض', 'Offer details') }}</li>
                <li :aria-current="editorStep === 'coverage' ? 'step' : undefined">{{ t('الأسواق ونطاق الخدمة', 'Markets and coverage') }}</li>
            </ol>
            <h4 ref="stepHeading" tabindex="-1" class="bf-practice-step-heading">{{ editorStep === 'offer' ? t('ما الذي تقدّمه للعملاء؟', 'What do you offer customers?') : t('أين تستهدف العملاء وأين تستطيع خدمتهم؟', 'Where do you target and serve customers?') }}</h4>
            <fieldset v-show="editorStep === 'offer'" :disabled="busy || !!pending || !canWrite">
                <label for="bf-practice-name">{{ t('اسم المنتج أو الخدمة', 'Product or service name') }}</label><input id="bf-practice-name" v-model="draft.name" required minlength="2" maxlength="120" :placeholder="t('مثل: بيع أجهزة تكييف', 'For example: sell air-conditioning equipment')" />
                <label for="bf-practice-kind">{{ t('نوع العمل', 'Activity type') }}</label><select id="bf-practice-kind" v-model="draft.kind"><option v-for="item in kinds" :key="item.value" :value="item.value">{{ item.label }}</option></select>
                <label for="bf-practice-description">{{ t('وصف قصير', 'Short description') }}</label><textarea id="bf-practice-description" v-model="draft.description" maxlength="2000" rows="3" />
                <label for="bf-practice-offering">{{ t('المنتج أو الخدمة المعنية — اختياري', 'Relevant product or service — optional') }}</label><input id="bf-practice-offering" v-model="draft.offering" maxlength="500" />
                <details class="bf-practice-details"><summary>{{ t('القطاع والتخصص وطريقة البيع — اختياري', 'Sector, specialization and sales model — optional') }}</summary><label for="bf-practice-sectors">{{ t('القطاعات', 'Sectors') }}</label><input id="bf-practice-sectors" v-model="sectorText" maxlength="2000" /><label for="bf-practice-specializations">{{ t('التخصصات', 'Specializations') }}</label><input id="bf-practice-specializations" v-model="specializationText" maxlength="2000" /><p>{{ t('افصل بين القيم بفاصلة. هذه أوصاف يصرح بها النشاط، وليست تصنيفًا مثبتًا مستقلًا.', 'Separate entries with commas. These are business declarations, not independently established classifications.') }}</p><label for="bf-practice-model">{{ t('كيف يتم التعامل أو تحقيق الإيراد؟', 'How does the transaction or revenue work?') }}</label><input id="bf-practice-model" v-model="draft.commercial_model" maxlength="500" /><label for="bf-practice-channels">{{ t('قنوات البيع أو التواصل', 'Sales or contact channels') }}</label><input id="bf-practice-channels" v-model="channelText" maxlength="2000" /></details>
            </fieldset>
            <fieldset v-show="editorStep === 'coverage'" :disabled="busy || !!pending || !canWrite">
                <div class="bf-practice-geographies"><section v-for="area in (['target_markets', 'fulfillment'] as const)" :key="area"><h4>{{ area === 'target_markets' ? t('أين تريد الوصول إلى العملاء؟', 'Where do you intend to find customers?') : t('أين تصرح بإمكانية تقديم هذا العمل؟', 'Where do you declare fulfillment for this work?') }}</h4><label :for="`bf-practice-${area}-status`">{{ t('حالة التصريح', 'Declaration status') }}</label><select :id="`bf-practice-${area}-status`" v-model="draft[area].status"><option value="unspecified">{{ t('غير محدد', 'Not specified') }}</option><option value="declared">{{ t('نطاق معلن', 'Declared coverage') }}</option></select><template v-if="draft[area].status === 'declared'"><label :for="`bf-practice-${area}-countries`">{{ t('الدول المشمولة', 'Included countries') }}</label><select :id="`bf-practice-${area}-countries`" multiple :value="countries(draft[area])" size="5" @change="setCountries(draft[area], $event)"><option v-for="country in surface.catalog.countries" :key="country.code" :value="country.code">{{ t(country.name_ar, country.name_en) }}</option></select></template></section></div>
                <details class="bf-practice-details"><summary>{{ t('تحديد مدن بالاسم — اختياري', 'Name specific cities — optional') }}</summary><p>{{ t('أسماء المدن تصريحات منك. تشابه الاسم مع موضوع مصدر آخر لا يثبت تطابق المكان أو التغطية.', 'City names are your declarations. A matching name in another source does not establish geographic identity or coverage.') }}</p><template v-for="area in (['target_markets', 'fulfillment'] as const)" :key="area"><label :for="`bf-practice-${area}-cities`">{{ area === 'target_markets' ? t('مدن السوق المقصود', 'Cities in the intended market') : t('مدن نطاق الوفاء المعلن', 'Cities in declared fulfillment coverage') }}</label><input :id="`bf-practice-${area}-cities`" v-model="cityText[area]" :disabled="draft[area].status !== 'declared'" maxlength="2000" /><p v-if="draft[area].status !== 'declared'">{{ t('اختر «نطاق معلن» أعلاه لتضمين المدن في التصريح.', 'Choose “Declared coverage” above to include cities in this declaration.') }}</p></template></details>
                <p class="bf-practice-limit">{{ t('السوق المقصود لا يثبت القدرة على الوفاء. نطاق الوفاء تصريح منك، وليس تحققًا من القدرة التشغيلية.', 'An intended market does not establish fulfillment capacity. Fulfillment coverage is your declaration, not verified operational capacity.') }}</p>
                <label v-if="operation !== 'create'" for="bf-practice-operation">{{ t('سبب التغيير', 'Reason for this change') }}<select id="bf-practice-operation" v-model="operation"><option value="update">{{ t('تحديث ما أقدمه الآن', 'Update what I provide now') }}</option><option value="correct">{{ t('تصحيح معلومة سابقة', 'Correct an earlier declaration') }}</option><option value="reclassify">{{ t('إعادة تصنيف العمل نفسه', 'Reclassify the same activity') }}</option></select></label>
                <p v-if="operation === 'update'">{{ t('حدّث العرض أو الوصف أو القنوات أو النموذج التجاري أو النطاق، مع التصنيف المصاحب عند الحاجة. لتغيير التصنيف وحده اختر إعادة التصنيف. لتصحيح معلومة سابقة اختر التصحيح وبيّن السبب. العمل المختلف يحتاج ممارسة مستقلة.', 'Update the offering, description, channels, commercial model or coverage, including accompanying classification when needed. For classification alone choose reclassification; for an earlier error choose an explained correction. Different work needs a separate activity.') }}</p>
                <p v-if="operation === 'reclassify'">{{ t('إعادة التصنيف تغيّر القطاع والتخصص فقط، وتحافظ على العمل ووصفه ونطاقه. أضف ممارسة مستقلة للعمل المختلف.', 'Reclassification changes only sectors and specializations, preserving the work, description and coverage. Add a separate activity for different work.') }}</p>
                <label v-if="operation === 'correct'" for="bf-practice-correction-reason">{{ t('ما المعلومة السابقة غير الدقيقة ولماذا؟', 'What was inaccurate in the earlier declaration, and why?') }}<textarea id="bf-practice-correction-reason" v-model="correctionReason" required minlength="3" maxlength="1000" /></label>
            </fieldset>
            <p v-if="conflict" role="alert" data-practice-conflict>{{ t('تغيرت الممارسة منذ بدأت التعديل. مسودتك لم تُنقل إلى الإصدار الجديد.', 'The activity changed after you began editing. Your draft was not moved to the new revision.') }}<button v-if="list.some(item => item.practice_id === selected && item.state === 'active')" type="button" @click="edit(list.find(item => item.practice_id === selected))">{{ t('استبدال المسودة بالمعلومات الحالية', 'Replace draft with current information') }}</button></p>
            <p v-if="notice" role="status" data-practice-notice>{{ notice }}</p>
            <div class="bf-practice-actions">
                <button v-if="editorStep === 'offer'" class="bf-primary" :disabled="busy || !canWrite || conflict || draft.name.trim().length < 2" data-practice-next>{{ t('التالي', 'Next') }}</button>
                <button v-else class="bf-primary" :disabled="busy || !canWrite || conflict && !pending" data-practice-save>{{ busy ? t('جارٍ الإرسال…', 'Sending…') : pending ? t('تحقّق بالطلب الأصلي', 'Check using original request') : t('حفظ العرض', 'Save offer') }}</button>
                <button v-if="editorStep === 'coverage' && !pending" type="button" :disabled="busy" data-practice-back @click="showStep('offer')">{{ t('السابق', 'Back') }}</button>
                <button v-if="!pending" type="button" :disabled="busy" @click="closeEditor">{{ t('إلغاء', 'Cancel') }}</button>
                <button v-if="editorStep === 'coverage' && operation !== 'create' && !pending" type="button" :disabled="busy || !canWrite || conflict" data-practice-deactivate @click="save(true)">{{ t('إيقاف استخدامها', 'Deactivate activity') }}</button>
            </div>
        </form>
        </BusinessSheet>
        <p v-if="pending && !busy" role="status" data-practice-unknown>{{ t('يوجد طلب محفوظ لم تُحسم نتيجته. لا تنشئ طلبًا بديلًا قبل التحقق منه.', 'A retained request has an unresolved outcome. Reconcile it before creating a replacement.') }}<button v-if="canWrite && !editing" type="button" class="bf-primary" @click="save()">{{ t('تحقّق بالطلب الأصلي', 'Check using original request') }}</button></p>
        <p v-if="notice && !editing" role="status" data-practice-notice>{{ notice }}</p><button v-if="denied || !!notice && !editing && !pending" type="button" class="bf-practice-refresh" :disabled="busy || refreshing" @click="emit('refresh')">{{ t('تحديث الوصول والمعلومات', 'Refresh access and information') }}</button>
        <BusinessPracticeKnowledge v-if="!denied" v-show="!listLoaded || list.length > 0" :surface="surface" :practices="list" :active="active" :refreshing="refreshing || loading" />
    </section>
</template>

<style scoped>
.bf-practices { margin-block: 24px; overflow-wrap: anywhere; }
.bf-practice-toolbar { display: flex; justify-content: flex-end; margin-block-end: 16px; }
.bf-practices .bf-primary svg { width: 17px; height: 17px; flex: none; }
.bf-practice-heading { display: flex; align-items: center; gap: 12px; margin-block-end: 16px; }
.bf-practice-heading > div { min-width: 0; }
.bf-practice-heading h3 { margin: 0 0 3px; font-size: 15px; font-weight: 650; line-height: 1.6; }
.bf-practice-icon { display: inline-grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; flex: none; color: var(--ad-secondary, #334b73); background: var(--ad-secondary-soft, #edf2f8); }
.bf-practice-icon svg { width: 21px; height: 21px; }
.bf-practice-empty > .bf-practice-icon { width: 48px; height: 48px; margin-block-end: 14px; }
.bf-practice-kind { color: var(--workspace-muted, #5e7189); font-size: 12px; }
.bf-practice-list { list-style: none; padding: 0; margin-block: 0 24px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 16px; }
.bf-practice-list > li { min-width: 0; padding: 22px; border: 1px solid var(--workspace-line, #e5ebf3); border-radius: 20px; background: var(--workspace-surface, #fff); box-shadow: var(--ad-shadow); }
.bf-practice-list p { font-size: 13px; line-height: 1.8; margin-block: 8px 14px; color: var(--workspace-muted, #5e7189); }
.bf-practice-context { display: grid; gap: 10px; margin-block: 16px; padding: 14px; border-radius: 12px; background: var(--ad-canvas, #f6f8fc); }
.bf-practice-context > div { display: flex; justify-content: space-between; align-items: baseline; gap: 14px; }
.bf-practice-context dt { font-size: 12px; color: var(--workspace-muted, #5e7189); }
.bf-practice-context dd { min-width: 0; flex: 1; margin: 0; font-size: 13px; text-align: end; color: var(--workspace-ink, #192b40); line-height: 1.7; }
.bf-practice-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 9px; margin-block: 16px 0; }
.bf-practice-actions button:not(.bf-primary), .bf-practice-refresh { padding: 9px 14px; min-height: 44px; border: 1px solid var(--workspace-line, #e5ebf3); border-radius: 12px; color: var(--workspace-ink, #192b40); background: var(--workspace-surface, #fff); font-size: 13px; cursor: pointer; }
.bf-practice-list [data-practice-edit] { background: var(--ad-soft, #eef3fc); color: var(--ad-accent, #235fd6); border-color: transparent; font-weight: 650; }
.bf-practice-actions button:hover:not(:disabled), .bf-practice-refresh:hover:not(:disabled) { border-color: var(--ad-accent, #235fd6); }
.bf-practices textarea { display: block; box-sizing: border-box; width: 100%; padding: 12px; border: 1px solid var(--workspace-line, #e5ebf3); border-radius: 12px; background: var(--workspace-surface, #fff); color: inherit; margin-block-end: 22px; }
.bf-practice-details { margin-block: 18px; }
.bf-practices summary { cursor: pointer; min-height: 44px; line-height: 1.7; padding-block: 8px; font-weight: 600; }
.bf-practice-geographies { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
.bf-practice-geographies h4 { font-size: 14px; font-weight: 650; line-height: 1.7; }
.bf-practice-limit { border-inline-start: 3px solid var(--ad-secondary, #334b73); padding-inline: 14px; }
.bf-practices time { display: block; font-size: 12px; unicode-bidi: isolate; }
.bf-practices [data-practice-revisions] ol { padding-inline-start: 22px; line-height: 1.9; }
.bf-practices [data-practice-revisions] li { margin-block: 12px; }
.bf-practices button:disabled { opacity: .5; cursor: default; }
.bf-practices :focus-visible { outline: 3px solid var(--ad-accent, #235fd6); outline-offset: 3px; }
.bf-practices > :deep(.bf-practice-knowledge) { padding: 14px 18px; border: 1px solid var(--workspace-line); border-radius: 16px; background: var(--workspace-surface); }
@media (max-width: 700px) {
    .bf-practice-list { grid-template-columns: minmax(0, 1fr); }
    .bf-practice-list > li { padding: 18px; }
    .bf-practice-geographies { grid-template-columns: minmax(0, 1fr); }
    .bf-practice-actions { align-items: stretch; }
    .bf-practice-actions button { flex: 1 1 auto; }
}
</style>
