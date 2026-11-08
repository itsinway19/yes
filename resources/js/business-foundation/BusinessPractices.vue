<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type { Surface } from './api';
import BusinessPracticeKnowledge from './BusinessPracticeKnowledge.vue';
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
const conflict = ref(false);
const correctionReason = ref('');
const historyAvailable = ref(false);
const pending = ref(activityCommand<PracticeCommand>(resource.value));
const emptyGeography = (): PracticeGeography => ({ status: 'unspecified', areas: [] });
const empty = (): PracticeValue => ({ name: '', description: '', kind: 'sale', sectors: [], specializations: [], offering: null,
    commercial_model: null, channels: [], target_markets: emptyGeography(), fulfillment: emptyGeography() });
const draft = reactive(empty()), sectorText = ref(''), specializationText = ref(''), channelText = ref('');
const cityText = reactive({ target_markets: '', fulfillment: '' });
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
function conceal() { serial++; loading.value = false; list.value = []; history.value = []; historyAvailable.value = false; editing.value = false; conflict.value = false; Object.assign(draft, empty()); sectorText.value = ''; specializationText.value = ''; channelText.value = ''; cityText.target_markets = ''; cityText.fulfillment = ''; }
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
        if (!practice) list.value = result.practices;
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
    editing.value = true; history.value = []; notice.value = '';
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
    <section class="bf-card bf-practices" data-business-practices :aria-busy="loading || busy">
        <header class="bf-practice-heading"><div><h2>{{ t('ماذا يقدم هذا النشاط؟', 'What does this business offer?') }}</h2><p>{{ t('افصل بين البيع والتركيب والصيانة أو أي أعمال أخرى، حتى تُفهم معلومات كل منها في سياقها.', 'Describe selling, installation, maintenance or other work separately so each is understood in its own context.') }}</p></div><button v-if="canWrite && !editing && !pending" type="button" class="bf-primary" data-practice-add @click="edit()">{{ t('إضافة ممارسة', 'Add an activity') }}</button></header>
        <label v-if="options.length > 1 && !denied" for="bf-practice-grant">{{ t('تفويض التعديل', 'Editing delegation') }}<select id="bf-practice-grant" v-model="writeGrant" :disabled="busy || !!pending"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, index) in options" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}</option></select></label>
        <p v-if="loading" role="status">{{ t('جارٍ قراءة الأعمال المحفوظة…', 'Reading saved activities…') }}</p>
        <p v-if="!loading && !list.length && !denied" data-practice-empty>{{ t('لم تُضف أعمالًا بعد. يمكنك البدء بما تبيعه أو تقدمه، وإكمال التفاصيل لاحقًا.', 'No activities have been added. Start with what you sell or provide and complete the details later.') }}</p>
        <p v-if="!options.length && !denied" class="bf-badge" data-practice-readonly>{{ t('قراءة فقط', 'Read only') }}</p>
        <ul class="bf-practice-list">
            <li v-for="practice in list" :key="practice.practice_id" :data-practice-id="practice.practice_id">
                <div class="bf-practice-heading"><h3>{{ practice.value.name }}</h3><span class="bf-badge">{{ practice.state === 'active' ? kindLabel(practice.value.kind) : t('متوقفة', 'Inactive') }}</span></div>
                <p v-if="practice.value.description">{{ practice.value.description }}</p><p v-if="practice.value.offering">{{ t('ما يُقدم:', 'Offering:') }} {{ practice.value.offering }}</p>
                <dl class="bf-practice-context"><div><dt>{{ t('الأسواق المقصودة', 'Intended markets') }}</dt><dd>{{ geographyLabel(practice.value.target_markets) }}</dd></div><div><dt>{{ t('نطاق الوفاء المعلن', 'Declared fulfillment') }}</dt><dd>{{ geographyLabel(practice.value.fulfillment) }}</dd></div></dl>
                <div class="bf-practice-actions"><button v-if="canWrite && practice.state === 'active'" type="button" :disabled="busy || !!pending" data-practice-edit @click="edit(practice)">{{ t('تعديل', 'Edit') }}</button><button v-if="historyAvailable" type="button" :disabled="loading || busy" data-practice-history @click="load(practice.practice_id)">{{ t('عرض التغييرات', 'View changes') }}</button></div>
            </li>
        </ul>
        <p v-if="list.length && !historyAvailable && !loading" role="status" data-practice-history-unavailable>{{ t('صلاحية قراءة المعلومات الحالية لا تتيح سجل التغييرات الخاص.', 'Access to current declarations does not release private revision history.') }}</p>
        <details v-if="history.length" open data-practice-revisions><summary>{{ t('التغييرات المحفوظة', 'Saved changes') }}</summary><ol><li v-for="entry in history" :key="`${entry.practice_id}:${entry.revision}`">{{ operationLabel(entry.operation) }} — {{ entry.value.name }} · {{ t('نسخة', 'Revision') }} {{ entry.revision }} <time :datetime="entry.recorded_at">{{ new Date(entry.recorded_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') }}</time><p v-if="entry.corrects_revision !== null && entry.correction_reason">{{ t('تصحيح النسخة', 'Correction of revision') }} {{ entry.corrects_revision }}: {{ entry.correction_reason }}</p></li></ol><p>{{ t('كل تغيير يحتفظ بنسخته؛ الإيقاف لا يمحو التقييمات السابقة.', 'Each change retains its revision; deactivation does not erase earlier assessments.') }}</p></details>
        <form v-if="editing && !denied" class="bf-form" data-practice-form @submit.prevent="save()">
            <h3>{{ operation === 'create' ? t('أضف عملًا يقدمه نشاطك', 'Add work your business provides') : t('راجع هذه الممارسة', 'Review this activity') }}</h3>
            <fieldset :disabled="busy || !!pending || !canWrite">
                <label for="bf-practice-name">{{ t('ماذا تفعل أو تقدم؟', 'What do you do or provide?') }}</label><input id="bf-practice-name" v-model="draft.name" required minlength="2" maxlength="120" :placeholder="t('مثل: بيع أجهزة تكييف', 'For example: sell air-conditioning equipment')" />
                <label for="bf-practice-kind">{{ t('كيف تقدم القيمة؟', 'How do you provide value?') }}</label><select id="bf-practice-kind" v-model="draft.kind"><option v-for="item in kinds" :key="item.value" :value="item.value">{{ item.label }}</option></select>
                <label for="bf-practice-description">{{ t('وصف قصير', 'Short description') }}</label><textarea id="bf-practice-description" v-model="draft.description" maxlength="2000" rows="3" />
                <label for="bf-practice-offering">{{ t('المنتج أو الخدمة المعنية — اختياري', 'Relevant product or service — optional') }}</label><input id="bf-practice-offering" v-model="draft.offering" maxlength="500" />
                <details class="bf-practice-details"><summary>{{ t('القطاع والتخصص وطريقة البيع — اختياري', 'Sector, specialization and sales model — optional') }}</summary><label for="bf-practice-sectors">{{ t('القطاعات', 'Sectors') }}</label><input id="bf-practice-sectors" v-model="sectorText" maxlength="2000" /><label for="bf-practice-specializations">{{ t('التخصصات', 'Specializations') }}</label><input id="bf-practice-specializations" v-model="specializationText" maxlength="2000" /><p>{{ t('افصل بين القيم بفاصلة. هذه أوصاف يصرح بها النشاط، وليست تصنيفًا مثبتًا مستقلًا.', 'Separate entries with commas. These are business declarations, not independently established classifications.') }}</p><label for="bf-practice-model">{{ t('كيف يتم التعامل أو تحقيق الإيراد؟', 'How does the transaction or revenue work?') }}</label><input id="bf-practice-model" v-model="draft.commercial_model" maxlength="500" /><label for="bf-practice-channels">{{ t('قنوات البيع أو التواصل', 'Sales or contact channels') }}</label><input id="bf-practice-channels" v-model="channelText" maxlength="2000" /></details>
                <div class="bf-practice-geographies"><section v-for="area in (['target_markets', 'fulfillment'] as const)" :key="area"><h4>{{ area === 'target_markets' ? t('أين تريد الوصول إلى العملاء؟', 'Where do you intend to find customers?') : t('أين تصرح بإمكانية تقديم هذا العمل؟', 'Where do you declare fulfillment for this work?') }}</h4><label :for="`bf-practice-${area}-status`">{{ t('حالة التصريح', 'Declaration status') }}</label><select :id="`bf-practice-${area}-status`" v-model="draft[area].status"><option value="unspecified">{{ t('غير محدد', 'Not specified') }}</option><option value="declared">{{ t('نطاق معلن', 'Declared coverage') }}</option></select><template v-if="draft[area].status === 'declared'"><label :for="`bf-practice-${area}-countries`">{{ t('الدول المشمولة', 'Included countries') }}</label><select :id="`bf-practice-${area}-countries`" multiple :value="countries(draft[area])" size="5" @change="setCountries(draft[area], $event)"><option v-for="country in surface.catalog.countries" :key="country.code" :value="country.code">{{ t(country.name_ar, country.name_en) }}</option></select></template></section></div>
                <details class="bf-practice-details"><summary>{{ t('تحديد مدن بالاسم — اختياري', 'Name specific cities — optional') }}</summary><p>{{ t('أسماء المدن تصريحات منك. تشابه الاسم مع موضوع مصدر آخر لا يثبت تطابق المكان أو التغطية.', 'City names are your declarations. A matching name in another source does not establish geographic identity or coverage.') }}</p><template v-for="area in (['target_markets', 'fulfillment'] as const)" :key="area"><label :for="`bf-practice-${area}-cities`">{{ area === 'target_markets' ? t('مدن السوق المقصود', 'Cities in the intended market') : t('مدن نطاق الوفاء المعلن', 'Cities in declared fulfillment coverage') }}</label><input :id="`bf-practice-${area}-cities`" v-model="cityText[area]" :disabled="draft[area].status !== 'declared'" maxlength="2000" /><p v-if="draft[area].status !== 'declared'">{{ t('اختر «نطاق معلن» أعلاه لتضمين المدن في التصريح.', 'Choose “Declared coverage” above to include cities in this declaration.') }}</p></template></details>
                <p class="bf-practice-limit">{{ t('السوق المقصود لا يثبت القدرة على الوفاء. نطاق الوفاء تصريح منك، وليس تحققًا من القدرة التشغيلية.', 'An intended market does not establish fulfillment capacity. Fulfillment coverage is your declaration, not verified operational capacity.') }}</p>
                <label v-if="operation !== 'create'" for="bf-practice-operation">{{ t('سبب التغيير', 'Reason for this change') }}<select id="bf-practice-operation" v-model="operation"><option value="update">{{ t('تحديث ما أقدمه الآن', 'Update what I provide now') }}</option><option value="correct">{{ t('تصحيح معلومة سابقة', 'Correct an earlier declaration') }}</option><option value="reclassify">{{ t('إعادة تصنيف العمل نفسه', 'Reclassify the same activity') }}</option></select></label>
                <p v-if="operation === 'update'">{{ t('حدّث العرض أو الوصف أو القنوات أو النموذج التجاري أو النطاق، مع التصنيف المصاحب عند الحاجة. لتغيير التصنيف وحده اختر إعادة التصنيف. لتصحيح معلومة سابقة اختر التصحيح وبيّن السبب. العمل المختلف يحتاج ممارسة مستقلة.', 'Update the offering, description, channels, commercial model or coverage, including accompanying classification when needed. For classification alone choose reclassification; for an earlier error choose an explained correction. Different work needs a separate activity.') }}</p>
                <p v-if="operation === 'reclassify'">{{ t('إعادة التصنيف تغيّر القطاع والتخصص فقط، وتحافظ على العمل ووصفه ونطاقه. أضف ممارسة مستقلة للعمل المختلف.', 'Reclassification changes only sectors and specializations, preserving the work, description and coverage. Add a separate activity for different work.') }}</p>
                <label v-if="operation === 'correct'" for="bf-practice-correction-reason">{{ t('ما المعلومة السابقة غير الدقيقة ولماذا؟', 'What was inaccurate in the earlier declaration, and why?') }}<textarea id="bf-practice-correction-reason" v-model="correctionReason" required minlength="3" maxlength="1000" /></label>
            </fieldset>
            <p v-if="conflict" role="alert" data-practice-conflict>{{ t('تغيرت الممارسة منذ بدأت التعديل. مسودتك لم تُنقل إلى الإصدار الجديد.', 'The activity changed after you began editing. Your draft was not moved to the new revision.') }}<button v-if="list.some(item => item.practice_id === selected && item.state === 'active')" type="button" @click="edit(list.find(item => item.practice_id === selected))">{{ t('استبدال المسودة بالمعلومات الحالية', 'Replace draft with current information') }}</button></p>
            <div class="bf-practice-actions"><button class="bf-primary" :disabled="busy || !canWrite || conflict && !pending" data-practice-save>{{ busy ? t('جارٍ الإرسال…', 'Sending…') : pending ? t('تحقّق بالطلب الأصلي', 'Check using original request') : t('حفظ الممارسة', 'Save activity') }}</button><button v-if="!pending" type="button" :disabled="busy" @click="editing = false">{{ t('إلغاء', 'Cancel') }}</button><button v-if="operation !== 'create' && !pending" type="button" :disabled="busy || !canWrite || conflict" data-practice-deactivate @click="save(true)">{{ t('إيقاف استخدامها', 'Deactivate activity') }}</button></div>
        </form>
        <p v-if="pending && !busy" role="status" data-practice-unknown>{{ t('يوجد طلب محفوظ لم تُحسم نتيجته. لا تنشئ طلبًا بديلًا قبل التحقق منه.', 'A retained request has an unresolved outcome. Reconcile it before creating a replacement.') }}<button v-if="canWrite && !editing" type="button" class="bf-primary" @click="save()">{{ t('تحقّق بالطلب الأصلي', 'Check using original request') }}</button></p>
        <p v-if="notice" role="status" data-practice-notice>{{ notice }}</p><button v-if="denied || (!loading && !list.length)" type="button" class="bf-practice-refresh" :disabled="busy || refreshing" @click="emit('refresh')">{{ t('تحديث الوصول والمعلومات', 'Refresh access and information') }}</button>
        <BusinessPracticeKnowledge v-if="!denied" :surface="surface" :practices="list" :active="active" :refreshing="refreshing || loading" />
    </section>
</template>

<style scoped>
.bf-practices{margin-block-start:24px;max-width:860px;overflow-wrap:anywhere}.bf-practice-heading{display:flex;align-items:start;justify-content:space-between;gap:18px}.bf-practice-heading h2,.bf-practice-heading h3{margin-block:0 10px}.bf-practice-heading>div{max-width:580px}.bf-practice-list{list-style:none;padding:0;margin-block:22px}.bf-practice-list>li{padding-block:20px;border-block-start:1px solid var(--color-border,#d9e2ee)}.bf-practice-list h3{font-weight:650;font-size:1.05rem}.bf-practice-context{display:grid;grid-template-columns:1fr 1fr;gap:18px}.bf-practice-context dt{font-size:.85rem;color:var(--color-muted-foreground,#516078)}.bf-practice-context dd{margin:6px 0;line-height:1.7}.bf-practice-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-block:14px}.bf-practice-actions button:not(.bf-primary),.bf-practice-refresh{padding:10px 12px;min-height:44px;border:1px solid var(--color-border,#bbcadd);border-radius:8px;color:var(--color-foreground,#154e63)}.bf-practices textarea{display:block;box-sizing:border-box;width:100%;padding:12px;border:1px solid #bbcadd;border-radius:9px;background:var(--color-background,#fff);color:inherit;margin-block-end:22px}.bf-practice-details{margin-block:18px}.bf-practices summary{cursor:pointer;min-height:44px;line-height:1.7;padding-block:8px;font-weight:600}.bf-practice-geographies{display:grid;grid-template-columns:1fr 1fr;gap:22px}.bf-practice-geographies h4{font-weight:650;line-height:1.7}.bf-practice-limit{border-inline-start:3px solid #86aaa5;padding-inline:14px}.bf-practices time{display:block;font-size:.85rem;unicode-bidi:isolate}.bf-practices [data-practice-revisions] ol{padding-inline-start:22px;line-height:1.9}.bf-practices [data-practice-revisions] li{margin-block:12px}.bf-practices button:disabled{opacity:.5;cursor:default}.bf-practices :focus-visible{outline:3px solid #328aba;outline-offset:3px}@media(max-width:600px){.bf-practice-heading{flex-direction:column}.bf-practice-heading .bf-primary{width:100%}.bf-practice-context,.bf-practice-geographies{grid-template-columns:1fr}.bf-practice-actions{align-items:stretch}.bf-practice-actions button{flex:1 1 100%}}
</style>
