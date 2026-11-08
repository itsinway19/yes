<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type { Surface } from './api';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { activityCommand, activityResource, definitiveActivityRejection, retainActivityCommand, settleActivityCommand } from './activityPending';
import { assessPractice, practiceKnowledge, type Practice, type PracticeArea, type PracticeAssessment, type PracticeAssessmentCommand, type PracticeKnowledgeOption, type PracticeRelation } from './activity';

const props = defineProps<{ surface: Surface; practices: Practice[]; active: boolean; refreshing: boolean }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const business = computed(() => props.surface.business.business_public_id);
const resource = computed(() => activityResource(business.value, 'practice-assessment'));
const opened = ref(false), loading = ref(false), busy = ref(false), denied = ref(false), notice = ref('');
const options = ref<PracticeKnowledgeOption[]>([]), nextCursor = ref<number | null>(null), selectedMaterial = ref(''), selectedPractice = ref('');
const result = ref<PracticeAssessment>(), pending = ref(activityCommand<PracticeAssessmentCommand>(resource.value));
const relation = reactive<PracticeRelation>({ basis: 'unknown', applicability: 'unknown', rationale: '' });
const country = ref(''), offering = ref(''), from = ref(''), until = ref('');
const reusedDeclaration = ref(false), retainedGeography = ref<PracticeArea | null>(null);
const activePractices = computed(() => props.practices.filter(practice => practice.state === 'active'));
const practice = computed(() => activePractices.value.find(practice => practice.practice_id === selectedPractice.value));
const option = computed(() => selectedMaterial.value === '' ? undefined : options.value[Number(selectedMaterial.value)]);
const canAssess = computed(() => props.active && !props.refreshing && !denied.value && !loading.value && !!practice.value && !!option.value);
let serial = 0, disposed = false;
onBeforeUnmount(() => { disposed = true; serial++; });
function clear() { serial++; loading.value = false; options.value = []; nextCursor.value = null; result.value = undefined; selectedMaterial.value = ''; selectedPractice.value = ''; offering.value = ''; country.value = ''; from.value = ''; until.value = ''; reusedDeclaration.value = false; retainedGeography.value = null; Object.assign(relation, { basis: 'unknown', applicability: 'unknown', rationale: '' }); }
watch(() => [props.surface, props.active, props.refreshing], () => {
    clear(); denied.value = false; loading.value = false; busy.value = false; notice.value = ''; pending.value = activityCommand<PracticeAssessmentCommand>(resource.value);
    if (opened.value && props.active && !props.refreshing) void load();
}, { flush: 'sync' });
watch(() => [practice.value?.practice_id, practice.value?.revision], (current, previous) => {
    if (!practice.value && selectedPractice.value) selectedPractice.value = '';
    if (previous[0] && (current[0] === previous[0] && current[1] !== previous[1] || !current[0])) {
        result.value = undefined; selectedMaterial.value = ''; options.value = []; nextCursor.value = null;
        if (!pending.value) reuseDeclaration();
        if (opened.value && props.active && !props.refreshing) void load();
    }
}, { flush: 'sync' });
function localDate(value: string) { const date = new Date(value); return Number.isFinite(date.getTime()) ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, -1) : ''; }
function reuseDeclaration() {
    if (pending.value) return;
    result.value = undefined; reusedDeclaration.value = false; retainedGeography.value = null;
    Object.assign(relation, { basis: 'unknown', applicability: 'unknown', rationale: '' });
    offering.value = practice.value?.value.offering ?? ''; country.value = ''; from.value = ''; until.value = '';
    const saved = option.value?.saved_relations?.find(item => item.practice_id === practice.value?.practice_id);
    if (!saved) return;
    Object.assign(relation, clone(saved.relation)); offering.value = saved.question.offering ?? '';
    retainedGeography.value = clone(saved.question.geography);
    country.value = saved.question.geography ? saved.question.geography.authority === 'iso3166-1' && saved.question.geography.level === 'country' ? saved.question.geography.ref : '__saved__' : '';
    from.value = saved.question.period ? localDate(saved.question.period.from) : ''; until.value = saved.question.period ? localDate(saved.question.period.until) : '';
    reusedDeclaration.value = true;
}
watch([selectedPractice, selectedMaterial], reuseDeclaration, { flush: 'sync' });
watch([selectedMaterial, country, offering, from, until, () => relation.basis, () => relation.applicability, () => relation.rationale], () => { result.value = undefined; }, { flush: 'sync' });
async function load(after = 0) {
    if (!props.active || props.refreshing || busy.value || disposed) return;
    const read = props.surface.authority_selection.requirements.find(item => item.requirement_kind === 'business')?.leaf_grant_id;
    if (!Number.isSafeInteger(read)) return;
    const captured = stamp(), attempt = ++serial, subject = props.surface;
    loading.value = true; result.value = undefined; notice.value = '';
    try {
        const page = await practiceKnowledge(business.value, read!, after);
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject) return;
        if (page.business_public_id !== business.value) throw Error('Knowledge choices subject mismatch');
        options.value = after ? [...options.value, ...page.options] : page.options; nextCursor.value = page.next_cursor; denied.value = false;
    } catch (error) {
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject) return;
        if (error instanceof TransportError && error.status === 403) { clear(); denied.value = true; }
        notice.value = t('تعذّر تأهيل قراءة المعرفة. راجع الوصول الحالي ثم أعد المحاولة.', 'Knowledge could not be read under current permissions. Review current access and try again.');
    } finally { if (!disposed && attempt === serial) loading.value = false; }
}
async function assess() {
    if (busy.value || denied.value || props.refreshing || !props.active || (!pending.value && !canAssess.value)) return;
    if (!pending.value) {
        if (!!from.value !== !!until.value || from.value && new Date(until.value) <= new Date(from.value)) { notice.value = t('حدد بداية الفترة ونهايتها بترتيب صحيح.', 'Choose a valid period start and end.'); return; }
        try { pending.value = retainActivityCommand(resource.value, { command_id: crypto.randomUUID(), practice_id: practice.value!.practice_id,
            expected_revision: practice.value!.revision, material_ref: clone(option.value!.material_ref), purpose: option.value!.purpose,
            relation: clone(relation), question: { geography: country.value === '__saved__' ? clone(retainedGeography.value) : country.value ? { authority: 'iso3166-1', level: 'country', ref: country.value } : null,
                period: from.value ? { from: new Date(from.value).toISOString(), until: new Date(until.value).toISOString() } : null, offering: offering.value.trim() || null },
            read_selection: clone(props.surface.authority_selection), authority_selection: clone(option.value!.authority_selection) }); }
        catch { notice.value = t('تعذّر حفظ بيانات استرجاع الطلب. لم يُرسل تقييم جديد؛ أتح تخزين الجلسة قبل المتابعة.', 'Recovery data could not be retained. No new assessment was sent; enable session storage before continuing.'); return; }
    }
    const captured = stamp(), subject = props.surface, resourceKey = resource.value, command = pending.value.command, uncertain = pending.value.uncertain;
    pending.value.uncertain = true; busy.value = true; notice.value = ''; result.value = undefined;
    try {
        const assessment = await assessPractice(business.value, command);
        if (disposed || !owned(captured) || props.surface !== subject) return;
        if (assessment.business_public_id !== business.value || assessment.practice_id !== command.practice_id
            || ['namespace', 'key', 'version'].some(field => assessment.material_ref[field as keyof typeof assessment.material_ref] !== command.material_ref[field as keyof typeof command.material_ref])) throw Error('Assessment subject mismatch');
        result.value = assessment; settleActivityCommand(resourceKey); pending.value = undefined;
    } catch (error) {
        if (disposed || !owned(captured) || props.surface !== subject) return;
        if (error instanceof TransportError && error.status === 403) { if (pending.value) pending.value.denied = true; clear(); denied.value = true;
            notice.value = t('الحقوق الحالية لا تسمح بكشف النتيجة. حدّث الوصول قبل إعادة التحقق من الطلب الأصلي.', 'Current rights do not permit disclosing the result. Refresh access before reconciling the original request.');
        } else if (error instanceof TransportError && definitiveActivityRejection(error.status, uncertain, (error.body as { error?: string }).error)) {
            settleActivityCommand(resourceKey); pending.value = undefined; notice.value = t('لم يُقبل الطلب. راجع المعلومات المطلوبة.', 'The request was rejected. Review the required information.');
        } else notice.value = t('نتيجة التقييم غير محسومة. أعد التحقق بالطلب نفسه دون تغيير الممارسة أو المادة.', 'The assessment outcome is unresolved. Check the same request without changing the activity or material.');
    } finally { if (!disposed && owned(captured) && props.surface === subject) busy.value = false; }
}
function toggle(event: Event) { opened.value = (event.currentTarget as HTMLDetailsElement).open; if (opened.value && !options.value.length && !loading.value) void load(); }
const assessmentLabel = computed(() => !result.value ? '' : result.value.currentness === 'review_required' || result.value.assessment === 'review_required'
    ? t('تحتاج النتيجة إلى إعادة تأهيل', 'The assessment needs requalification') : result.value.assessment === 'applicable'
        ? t('انطباق ضمن حدود العلاقة المعلنة', 'Applicable within the declared relationship') : result.value.assessment === 'not_applicable'
            ? t('عدم انطباق ضمن السؤال المحدد', 'Not applicable to this question') : t('الانطباق غير محسوم', 'Applicability is inconclusive'));
const basisLabel = (basis: PracticeRelation['basis']) => ({ business_declared: t('علاقة يصرح بها النشاط', 'Business-declared relationship'), unknown: t('علاقة غير معلومة', 'Unknown relationship'), inferred_unverified: t('استنتاج يحتاج تحققًا', 'Inference requiring verification'), conflicting: t('معلومات متعارضة', 'Conflicting information') })[basis];
const coverageLabel = (state: PracticeAssessment['fulfillment']) => state === 'within_declared' ? t('ضمن النطاق المعلن', 'Within declared coverage') : state === 'not_listed_in_declaration' ? t('غير مدرج في التصريح', 'Not listed in the declaration') : t('غير محسوم', 'Unknown');
</script>

<template>
    <details class="bf-practice-knowledge" data-practice-knowledge @toggle="toggle">
        <summary>{{ t('هل تفيد معرفة السوق إحدى ممارساتي؟', 'Does market knowledge help one of my activities?') }}</summary>
        <p>{{ t('اختر العمل المقصود ومعرفة يُسمح لك باستعمالها، ثم وضّح علاقتها به. هذا الفحص لا يثبت وجود مشترين أو ربحية.', 'Choose an activity and knowledge you are permitted to use, then describe their relationship. This check does not establish buyers or profitability.') }}</p>
        <p v-if="!activePractices.length">{{ t('أضف ممارسة نشطة أولًا لتحديد العمل المقصود بالسؤال.', 'Add an active activity first to identify the work this question concerns.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ تأهيل المعرفة المتاحة…', 'Qualifying available knowledge…') }}</p>
        <p v-if="!loading && !options.length && !denied" data-practice-knowledge-empty>{{ t('لا توجد معرفة مؤهلة في هذه الصفحة. يلزم ترخيص صريح للمعلومة وهوية موضوعها؛ لا يعني غيابها انعدام الاهتمام أو العملاء.', 'No qualified knowledge is available on this page. The finding and its subject identity require an explicit licence; absence does not mean no interest or customers.') }}</p>
        <button v-if="nextCursor !== null" type="button" :disabled="loading || busy || !!pending" data-practice-knowledge-more @click="load(nextCursor)">{{ t('فحص الصفحة التالية', 'Check the next page') }}</button>
        <form v-if="options.length && !denied" class="bf-form" data-practice-assessment-form @submit.prevent="assess">
            <fieldset :disabled="busy || loading || !!pending || refreshing">
                <label for="bf-assess-practice">{{ t('أي عمل تقصد؟', 'Which activity do you mean?') }}</label><select id="bf-assess-practice" v-model="selectedPractice" required><option value="">{{ t('اختر ممارسة', 'Choose an activity') }}</option><option v-for="item in activePractices" :key="item.practice_id" :value="item.practice_id">{{ item.value.name }}</option></select>
                <label for="bf-assess-knowledge">{{ t('المعرفة السوقية', 'Market knowledge') }}</label><select id="bf-assess-knowledge" v-model="selectedMaterial" required><option value="">{{ t('اختر معرفة متاحة', 'Choose available knowledge') }}</option><option v-for="(item, index) in options" :key="index" :value="String(index)">{{ item.practice_basis.signals[0]?.subject.label || t('موضوع غير محدد', 'Unspecified subject') }} · {{ index + 1 }}</option></select>
                <template v-if="option"><ul class="bf-knowledge-signals"><li v-for="(signal, index) in option.practice_basis.signals" :key="index"><strong>{{ signal.subject.label }}</strong><span>{{ signal.geography.ref }} · {{ signal.period.from }} — {{ signal.period.until }}</span></li></ul><p>{{ t('هوية الموضوع تخص المصدر الذي أصدرها؛ الاسم وحده لا يثبت علاقتها بعملك.', 'Subject identity belongs to its issuing source; the label alone does not establish its relationship to your activity.') }}</p></template>
                <label for="bf-assess-relation">{{ t('ما أساس العلاقة بين الموضوع وعملك؟', 'What is the basis of the relationship to your activity?') }}</label><select id="bf-assess-relation" v-model="relation.basis"><option value="unknown">{{ t('لا أعرف بعد', 'I do not know yet') }}</option><option value="business_declared">{{ t('أصرّح بالعلاقة بناءً على معرفتي بعملي', 'I declare the relationship based on my knowledge of my work') }}</option><option value="inferred_unverified">{{ t('علاقة أستنتجها وتحتاج تحققًا', 'An inferred relationship requiring verification') }}</option><option value="conflicting">{{ t('لدي معلومات متعارضة', 'I have conflicting information') }}</option></select>
                <label for="bf-assess-applicability">{{ t('كيف تصف الصلة؟', 'How do you describe the relevance?') }}</label><select id="bf-assess-applicability" v-model="relation.applicability"><option value="unknown">{{ t('غير معلومة', 'Unknown') }}</option><option value="related">{{ t('متعلق بالممارسة', 'Related to the activity') }}</option><option value="unrelated">{{ t('غير متعلق بحسب تصريحي', 'Unrelated according to my declaration') }}</option></select>
                <p v-if="reusedDeclaration" role="status" data-practice-reused-relation>{{ t('استعدنا تصريحك السابق لهذا الموضوع والممارسة. راجعه قبل التقييم؛ يبقى تصريحًا منك وليس إثباتًا مستقلًا.', 'Your earlier declaration for this subject and activity was restored. Review it before assessing; it remains your declaration, not independent proof.') }}</p>
                <label for="bf-assess-rationale">{{ t('وضح سببك وحدود معرفتك', 'Explain your reason and the limits of your knowledge') }}</label><textarea id="bf-assess-rationale" v-model="relation.rationale" rows="3" maxlength="1000" />
                <details><summary>{{ t('تحديد السؤال — اختياري', 'Specify the question — optional') }}</summary><label for="bf-assess-offering">{{ t('المنتج أو الخدمة المقصودة', 'Relevant product or service') }}</label><input id="bf-assess-offering" v-model="offering" maxlength="500" /><label for="bf-assess-country">{{ t('الدولة المعنية', 'Relevant country') }}</label><select id="bf-assess-country" v-model="country"><option value="">{{ t('دون تحديد إضافي', 'No additional restriction') }}</option><option v-if="retainedGeography && (retainedGeography.authority !== 'iso3166-1' || retainedGeography.level !== 'country')" value="__saved__">{{ t('المنطقة المحفوظة:', 'Saved area:') }} {{ retainedGeography.ref }}</option><option v-for="item in surface.catalog.countries" :key="item.code" :value="item.code">{{ t(item.name_ar, item.name_en) }}</option></select><div class="bf-assessment-period"><label for="bf-assess-from">{{ t('بداية الفترة — بتوقيت جهازك', 'Period start — device time') }}<input id="bf-assess-from" v-model="from" type="datetime-local" step="0.001" /></label><label for="bf-assess-until">{{ t('نهاية الفترة — بتوقيت جهازك', 'Period end — device time') }}<input id="bf-assess-until" v-model="until" type="datetime-local" step="0.001" /></label></div></details>
            </fieldset><button class="bf-primary" :disabled="busy || (!pending && !canAssess)" data-practice-assess>{{ busy ? t('جارٍ التحقق…', 'Checking…') : pending ? t('تحقّق بالطلب الأصلي', 'Check using original request') : t('فحص الملاءمة', 'Check relevance') }}</button>
        </form>
        <p v-if="pending && !busy" role="status" data-practice-assessment-unknown>{{ t('يوجد تقييم غير محسوم. يُحتفظ بطلبه الأصلي حتى يمكن التحقق منه بالصلاحيات الحالية.', 'An assessment has an unresolved outcome. Its original request is retained for reconciliation under current rights.') }}<button v-if="!denied && !options.length" type="button" :disabled="refreshing" @click="assess">{{ t('تحقّق بالطلب الأصلي', 'Check using original request') }}</button></p>
        <section v-if="result" class="bf-assessment-result" aria-live="polite" data-practice-assessment-result><h3>{{ assessmentLabel }}</h3><p>{{ basisLabel(result.relation_basis) }}. {{ t('العلاقة غير مثبتة مستقلًا.', 'The relationship is not independently verified.') }}</p><p v-if="result.currentness === 'review_required'" role="alert">{{ t('هذه نتيجة تاريخية مرتبطة بنسختها الأصلية. تغيرت تبعيات مؤثرة؛ لا تستعملها كتقييم حالي.', 'This historical result belongs to its original revision. Relevant dependencies changed; do not use it as a current assessment.') }}</p><p v-else-if="result.supports_investigating_reach">{{ t('يوجد ارتفاع مؤهل في مؤشر اهتمام بموضوع مرتبط بالممارسة وفق تصريحك. يستحق فحص إمكانية الوصول؛ لا يثبت وجود مشترين أو فرصة مربحة أو وصولًا متحققًا.', 'A qualified interest indicator rose for a subject related to this activity according to your declaration. Investigating reach is warranted; buyers, profitability and actual reach are not established.') }}</p><p v-else>{{ t('المقدمات الحالية لا تدعم نقل ارتفاع الاهتمام إلى هذه الممارسة تلقائيًا. راجع علاقة الموضوع والسؤال والتغطية.', 'The current premises do not support automatically attributing the interest rise to this activity. Review the subject relationship, question and coverage.') }}</p><dl><div><dt>{{ t('السوق المقصود', 'Intended market') }}</dt><dd>{{ coverageLabel(result.target_market) }}</dd></div><div><dt>{{ t('الوفاء المعلن', 'Declared fulfillment') }}</dt><dd>{{ coverageLabel(result.fulfillment) }}</dd></div></dl><p>{{ t('الوفاء المعلن لا يثبت القدرة التشغيلية، وعدم إدراج منطقة لا يثبت استحالة الخدمة.', 'Declared fulfillment does not establish operational capacity, and an unlisted area does not establish that service is impossible.') }}</p><small>{{ t('نسخة الممارسة المستخدمة', 'Practice revision used') }}: {{ result.practice_revision }}</small></section>
        <p v-if="notice" role="status">{{ notice }}</p><button v-if="denied" type="button" :disabled="busy || refreshing" @click="load()">{{ t('تحديث المعرفة والوصول', 'Refresh knowledge and access') }}</button>
    </details>
</template>

<style scoped>
.bf-practice-knowledge{margin-block-start:24px;border-block-start:1px solid var(--color-border,#d9e2ee);padding-block-start:18px;overflow-wrap:anywhere}.bf-practice-knowledge summary{cursor:pointer;min-height:44px;padding-block:8px;font-weight:650;line-height:1.8}.bf-practice-knowledge button:not(.bf-primary){border:1px solid var(--color-border,#bbcadd);border-radius:8px;padding:10px 14px;min-height:44px}.bf-practice-knowledge textarea{width:100%;box-sizing:border-box;min-height:96px;border:1px solid #bbcadd;border-radius:9px;padding:12px;background:var(--color-background,#fff);color:inherit}.bf-knowledge-signals{list-style:none;padding:0}.bf-knowledge-signals li{padding:12px;border-inline-start:3px solid #86aaa5;margin-block:10px}.bf-knowledge-signals span{display:block;font-size:.85rem;line-height:1.8;unicode-bidi:isolate}.bf-assessment-period{display:grid;grid-template-columns:1fr 1fr;gap:18px}.bf-assessment-result{margin-block:22px;padding:20px;border:1px solid var(--color-border,#bbcadd);border-inline-start:4px solid #286a67;border-radius:8px}.bf-assessment-result h3{font-size:1.1rem;font-weight:650}.bf-assessment-result dl{display:grid;grid-template-columns:1fr 1fr;gap:18px}.bf-assessment-result dd{margin:6px 0}.bf-practice-knowledge :focus-visible{outline:3px solid #328aba;outline-offset:3px}@media(max-width:600px){.bf-assessment-period,.bf-assessment-result dl{grid-template-columns:1fr}.bf-practice-knowledge button{width:100%}.bf-assessment-result{padding:16px}}
</style>
