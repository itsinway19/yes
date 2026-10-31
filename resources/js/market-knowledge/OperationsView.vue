<script setup lang="ts">
import { computed, watchEffect } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { operations, reading, refreshFailed, readOperations, type MarketOperationsQuery } from './state';
const route = useRoute(), router = useRouter();
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
watchEffect(() => { document.title = copy('المعرفة السوقية — BuilderAd', 'Market knowledge — BuilderAd'); });
const query = computed<MarketOperationsQuery>(() => typeof route.query.cursor === 'string' ? { cursor: route.query.cursor } : {});
const refresh = () => { if (!reading.value) void readOperations(query.value, false, true); };
const page = (cursor?: string) => { if (!reading.value) void router.push({ path: '/internal/administration/market-knowledge', query: cursor ? { cursor } : {} }); };
const reviewSources = computed(() => operations.value?.sources.filter(source => source.state !== 'active' || source.rights_window !== 'current') ?? []);
const reviewKnowledge = computed(() => operations.value?.shared_knowledge?.some(item => item.publication_state === 'published' && item.validity !== 'current') ?? false);
const abstainedKnowledge = computed(() => operations.value?.shared_knowledge?.some(item => item.publication_state === 'published' && item.validity === 'current' && item.processing_outcome === 'abstained') ?? false);
const orderedSources = computed(() => [...(operations.value?.sources ?? [])].sort((a, b) => Number(b.state !== 'active' || b.rights_window !== 'current') - Number(a.state !== 'active' || a.rights_window !== 'current')));
const sourceState = (state: string) => state === 'active' ? copy('مصدر مسجل', 'Registered source') : state === 'restricted' ? copy('المصدر مقيّد', 'Source restricted') : copy('سُحب تأهيل المصدر', 'Source qualification revoked');
const windowState = (state: string) => state === 'current' ? copy('نافذة الحق المسجلة سارية', 'Recorded rights window is current') : state === 'not_started' ? copy('لم تبدأ نافذة الحق بعد', 'Rights window has not started') : copy('انتهت نافذة الحق', 'Rights window has expired');
const nextStep = (source: { state: string; rights_window: string }) => source.state !== 'active' ? copy('تحتاج حالة المصدر إلى مراجعة عبر قناة التأهيل المخوّلة قبل استعمال جديد.', 'The source needs review through its authorized qualification channel before new use.') : source.rights_window === 'expired' ? copy('راجع سند الاستعمال مع المسؤول المخوّل. لا تفترض استمرار الحق السابق.', 'Review the use entitlement with the authorized operator. Do not assume the previous right continues.') : source.rights_window === 'not_started' ? copy('لا يُستعمل هذا السند قبل بداية نافذته المؤهلة.', 'This entitlement cannot be used before its qualified window starts.') : copy('تُفحص الحقول والغرض وحداثة الدليل عند كل استعمال مؤهل.', 'Fields, purpose and evidence currentness are checked for each qualified use.');
const methodName = (method: string) => method === 'offer_equivalence:1' ? copy('التحقق من تكافؤ العروض', 'Offer equivalence check') : method === 'qualified_interest_change:1' ? copy('مقارنة تغير الاهتمام', 'Qualified interest change') : method === 'business_feasibility:1' ? copy('ملاءمة النشاط لنطاقه المعلن', 'Fit within the declared business market') : method === 'qualified_price_observation:1' ? copy('رصد السعر المؤهل', 'Qualified price observation') : method === 'qualified_geography_observation:1' ? copy('رصد النطاق الجغرافي المؤهل', 'Qualified geography observation') : copy('طريقة تحليل مسجلة', 'Registered analysis method');
const methodState = (state: string) => state === 'available' ? copy('متاحة محليًا', 'Available locally') : state === 'unavailable' ? copy('غير متاحة حاليًا', 'Currently unavailable') : copy('لم يُتحقق من التوفر', 'Availability not verified');
type SharedSummary = { publication_state: string; validity: string; processing_outcome: string; reason_category: string };
const sharedState = (item: SharedSummary) => {
    if (item.publication_state === 'retention_removed') return copy('أُزيل محتوى المخرج المحفوظ', 'Retained output content was removed');
    if (item.publication_state === 'prepared') return copy('لقطة محفوظة لم تُنشر نتيجتها', 'Saved capture without a published result');
    return copy('منشورة', 'Published');
};
const sharedValidity = (item: SharedSummary) => {
    if (item.publication_state === 'prepared') return copy('لم تُنشر بعد', 'Not published');
    if (item.publication_state === 'retention_removed') return copy('لا يوجد محتوى محفوظ لتقييم صلاحيته', 'No retained output to assess');
    if (item.validity === 'current') return copy('صلاحيتها حالية', 'Currently valid');
    if (item.validity === 'review_required') return copy('تحتاج مراجعة قبل الاعتماد', 'Review before relying on this knowledge');
    if (item.validity === 'unavailable') return copy('تعذر التحقق من صلاحيتها', 'Validity could not be checked');
    return copy('لم يُتحقق من صلاحيتها', 'Validity has not been verified');
};
const sharedOutcome = (item: SharedSummary) => {
    if (item.processing_outcome === 'completed') return copy('اكتملت المعالجة', 'Processing completed');
    if (item.processing_outcome === 'abstained') return copy('لم تتوصل المعالجة إلى استنتاج مؤهل', 'No qualified conclusion was established');
    if (item.processing_outcome === 'unavailable') return copy('تعذر التحقق من نتيجة المعالجة', 'Processing outcome could not be checked');
    return copy('لم يُتحقق من نتيجة المعالجة', 'Processing outcome has not been verified');
};
const sharedReason = (item: SharedSummary) => {
    if (item.reason_category === 'comparison_basis_insufficient') return copy('أساس المقارنة غير كافٍ. راجع أهلية المقارنة مع المشغّل المخوّل دون افتراض سبب أدق.', 'Comparison basis is insufficient. Ask the authorized operator to review comparability without assuming a more specific cause.');
    if (item.reason_category === 'required_information_missing') return copy('معلومات لازمة غير مكتملة. اطلب من المشغّل المخوّل مراجعة ما يلزم استكماله ضمن الحقوق المتاحة.', 'Required information is incomplete. Ask the authorized operator to review what can be completed within the available rights.');
    if (item.reason_category === 'generic_abstention') return copy('لم تسمح الأدلة باستنتاج مؤهل. راجع كفاية الأدلة مع المشغّل المخوّل قبل طلب استعمال جديد.', 'The evidence did not support a qualified conclusion. Review evidence sufficiency with the authorized operator before requesting new use.');
    if (item.reason_category === 'unavailable') return copy('تعذر التحقق من السبب في هذه القراءة. اطلب مراجعة مخوّلة دون تخمينه.', 'The reason could not be checked in this read. Request an authorized review without guessing it.');
    return copy('لا يتوفر سبب مؤهل في هذه القراءة. اطلب مراجعة مخوّلة دون تخمينه.', 'No qualified reason is available in this read. Request an authorized review without guessing it.');
};
const outcomeConsequence = (item: SharedSummary) => {
    if (item.processing_outcome === 'completed') return copy('اكتمال المعالجة لا يثبت منفعة تجارية أو نجاح قرار؛ تُقيّم النتيجة ضمن سياق استعمالها.', 'Completed processing does not establish commercial benefit or a successful decision; assess the finding in its use context.');
    if (item.processing_outcome === 'abstained') return copy('لم يثبت استنتاج مؤهل؛ هذا لا يعني عطلًا في النظام، ولا يتحول إلى نتيجة مؤكدة بسبب سريان صلاحية المعرفة.', 'No qualified conclusion was established; this does not mean an infrastructure failure, and current knowledge validity does not turn it into a definite finding.');
    return copy('لا تعتمد على نتيجة معالجة لم يُتحقق منها. اطلب مراجعتها عبر القناة المخوّلة.', 'Do not rely on an unverified processing outcome. Request review through the authorized channel.');
};
const sharedNext = (item: SharedSummary) => {
    if (item.publication_state === 'retention_removed') return copy('أُزيل محتوى هذه المخرجات مع بقاء أثر التدقيق المسموح. هذا لا يعني حذف بيانات المصدر كله. راجع المسؤول المخوّل عند الحاجة إلى معرفة جديدة.', 'These output payloads were removed while the permitted audit trace remains. This does not mean all source data was deleted. Consult the authorized operator if new knowledge is needed.');
    if (item.publication_state === 'prepared') return copy('حُفظت لقطة مدخلات مؤهلة ولم تُنشر نتيجتها بعد. يراجع المشغّل المخوّل الحقوق وصلاحية المدخلات مجددًا قبل المعالجة والنشر؛ وجود اللقطة لا يثبت جاهزيتها الآن.', 'A qualified input capture was saved, but its result has not been published. The authorized operator must recheck rights and input validity before processing and publication; a saved capture does not establish current readiness.');
    if (item.validity === 'current') return copy('يمكن للمستهلك المؤهل طلب هذه المعرفة ضمن شروطها. يحتاج كل مستهلك إلى حقوقه الحالية وغرضه واستعماله المؤهل بصورة مستقلة.', 'A qualified consumer can request this knowledge within its conditions. Each consumer still needs its own current rights, purpose and qualified use.');
    if (item.validity === 'review_required') return copy('تغير اعتماد مؤثر أو تقادمت معلومات. لا تعتمد على هذه النتيجة باعتبارها حالية؛ اطلب مراجعتها من المشغّل المخوّل قبل استعمال جديد.', 'A relevant dependency changed or evidence became stale. Do not rely on this finding as current; ask the authorized operator to review it before new use.');
    return copy('لا توجد في هذه القراءة صلاحية حالية مثبتة لهذه المعرفة. اطلب من المشغّل المخوّل مراجعة سبب التعذر والحقوق والتبعيات قبل الاعتماد عليها.', 'This read does not establish current validity for this knowledge. Ask the authorized operator to check the unavailable evidence, rights and dependencies before relying on it.');
};
const expiry = (value: string | null) => value ? new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-SA' : 'en-GB') : copy('لا نهاية مسجلة؛ تظل قيود السند واجبة', 'No end recorded; entitlement constraints still apply');
</script>

<template>
    <OperationsShell>
        <template #header>
            <h1>{{ copy('المعرفة السوقية', 'Market knowledge') }}</h1>
            <p>{{ copy('تابع تأهيل مصادر السوق وتوفر طرق التحليل، واعرف ما يحتاج مراجعة ضمن صلاحيتك.', 'Follow market-source qualification and analysis-method availability, and see what needs review within your authority.') }}</p>
        </template>
        <div v-if="operations" class="knowledge-operations" data-market-operations :aria-busy="reading">
            <div class="knowledge-toolbar">
                <p>{{ copy('متابعة تشغيلية محدودة؛ لا تمنح حق الاطلاع على معلومات الأنشطة.', 'Limited operational oversight; it does not grant access to business information.') }}</p>
                <button class="admin-action" type="button" :disabled="reading" data-knowledge-refresh @click="refresh">{{ reading ? copy('جارٍ التحقق…', 'Checking…') : copy('تحديث الحالة', 'Refresh status') }}</button>
            </div>
            <p v-if="refreshFailed" class="knowledge-warning" role="alert" data-knowledge-stale>{{ copy('تعذر تحديث الحالة. المعروض لقطة سابقة؛ لا تعتمد عليها باعتبارها حالة حالية. يمكنك إعادة القراءة دون تنفيذ أي تغيير.', 'Status could not be refreshed. This is a previous snapshot; do not rely on it as current. You can read again without making any change.') }}</p>
            <p v-else-if="reading" role="status">{{ copy('جارٍ قراءة الحالة المؤهلة…', 'Reading the qualified status…') }}</p>

            <section class="knowledge-section knowledge-attention" aria-labelledby="knowledge-attention-title">
                <h2 id="knowledge-attention-title">{{ copy('ما يحتاج انتباهك', 'What needs your attention') }}</h2>
                <p v-if="refreshFailed">{{ copy('يلزم التحقق من الحالة قبل الاعتماد على هذه القراءة.', 'Verify current status before relying on this snapshot.') }}</p>
                <p v-else-if="reviewSources.length">{{ copy('توجد في هذه الصفحة مصادر مقيّدة أو خارج نافذة الحق المسجلة. راجع الحالة والخطوة المسموحة أدناه.', 'This page includes sources that are restricted or outside their recorded rights window. Review their status and permitted next step below.') }}</p>
                <p v-else-if="reviewKnowledge">{{ copy('توجد معرفة مشتركة تحتاج مراجعة صلاحيتها. راجع ملخص المعرفة المحفوظة قبل الاعتماد على نتائجها.', 'Some shared knowledge needs a validity review. Check the retained-knowledge summary before relying on its findings.') }}</p>
                <p v-else-if="operations.sources.length">{{ copy('لا تظهر في هذه الصفحة مشكلة في حالة التأهيل أو نافذته. هذا لا يثبت جودة المعلومات أو حداثتها.', 'No qualification-state or rights-window issue is shown on this page. This does not establish information quality or currentness.') }}</p>
                <p v-else>{{ copy('لم تظهر مصادر سوقية في هذه القراءة. لا يعني ذلك انعدام معلومات السوق أو الطلب.', 'No market sources were returned in this read. This does not mean that market information or demand is absent.') }}</p>
                <p v-if="!refreshFailed && abstainedKnowledge" data-knowledge-abstention-attention>{{ copy('توجد معرفة حالية لم تنتج استنتاجًا مؤهلًا. راجع نتيجة المعالجة والسبب العام أدناه؛ صلاحية المعرفة لا تعني أن التحليل حسم السؤال.', 'Some currently valid knowledge did not yield a qualified conclusion. Review its processing outcome and general reason below; current validity does not mean the analysis resolved the question.') }}</p>
            </section>

            <section class="knowledge-section" aria-labelledby="knowledge-sources-title">
                <div class="knowledge-heading"><h2 id="knowledge-sources-title">{{ copy('مصادر المعلومات', 'Information sources') }}</h2><span class="knowledge-caption">{{ copy('حالة التأهيل المسجلة', 'Recorded qualification status') }}</span></div>
                <p class="knowledge-muted">{{ copy('لم يُتحقق من اتصال المصادر المباشر. تسجيل المصدر وسريان حقه لا يثبتان أنه متصل أو أن معلوماته دقيقة.', 'Live source connectivity has not been verified. Registration and a current right do not establish connectivity or data accuracy.') }}</p>
                <ul v-if="orderedSources.length" class="knowledge-sources" aria-labelledby="knowledge-sources-title">
                    <li v-for="source in orderedSources" :key="source.source_ref" class="knowledge-source" :data-source-state="source.state">
                        <div class="knowledge-source-heading"><h3>{{ sourceState(source.state) }}</h3><span class="knowledge-status" :class="{ 'knowledge-status-review': source.state !== 'active' || source.rights_window !== 'current' }">{{ refreshFailed ? copy('حالة محفوظة — تحتاج تحققًا', 'Saved status — verification needed') : windowState(source.rights_window) }}</span></div>
                        <p>{{ refreshFailed ? copy('حدّث القراءة أولًا لمعرفة الخطوة المناسبة.', 'Refresh this read first to determine the appropriate next step.') : nextStep(source) }}</p>
                        <dl class="knowledge-facts"><div><dt>{{ copy('نهاية نافذة الحق المسجلة', 'Recorded rights-window end') }}</dt><dd>{{ expiry(source.valid_until) }}</dd></div></dl>
                        <details class="knowledge-reference"><summary>{{ copy('مرجع المصدر', 'Source reference') }}</summary><bdi dir="ltr">{{ source.source_ref }}</bdi><p>{{ copy('نسخة التأهيل', 'Qualification revision') }} {{ source.custody_revision }}</p></details>
                    </li>
                </ul>
                <p v-else class="knowledge-empty">{{ copy('عند تأهيل مصدر سوقي عبر قناته المخوّلة، يمكن متابعة حالته هنا. هذه الصفحة لا تنشئ مصادر أو تمنح حقوقًا.', 'A market source qualified through its authorized channel can be followed here. This page does not create sources or issue rights.') }}</p>
                <nav v-if="query.cursor || operations.next_cursor" class="knowledge-paging" :aria-label="copy('صفحات مصادر المعرفة', 'Knowledge-source pages')">
                    <button v-if="query.cursor" type="button" class="admin-action" :disabled="reading" @click="page()">{{ copy('العودة إلى البداية', 'Back to first page') }}</button>
                    <button v-if="operations.next_cursor" type="button" class="admin-action" :disabled="reading" @click="page(operations.next_cursor)">{{ copy('المصادر التالية', 'Next sources') }}</button>
                </nav>
            </section>

            <section class="knowledge-section" aria-labelledby="knowledge-methods-title">
                <h2 id="knowledge-methods-title">{{ copy('طرق التحليل المتاحة', 'Analysis-method availability') }}</h2>
                <p class="knowledge-muted">{{ copy('هذا فحص لتوفر الطرق المحلية، وليس سجلًا لنجاح تحليلات الأنشطة أو نشر نتائجها.', 'This checks local method availability; it is not a record of successful business analyses or published results.') }}</p>
                <ul class="knowledge-methods"><li v-for="method in operations.methods" :key="method.method_ref"><span>{{ methodName(method.method_ref) }}</span><span class="knowledge-status">{{ refreshFailed ? copy('يلزم تحديث التحقق', 'Verification needs refresh') : methodState(method.availability) }}</span></li></ul>
                <p v-if="!operations.methods.length">{{ copy('لا تتوفر معلومات عن طرق التحليل في هذه القراءة.', 'No analysis-method information is available in this read.') }}</p>
            </section>


            <section class="knowledge-section" aria-labelledby="knowledge-shared-title" data-shared-knowledge>
                <h2 id="knowledge-shared-title">{{ copy('المعرفة المشتركة المحفوظة', 'Retained shared knowledge') }}</h2>
                <p class="knowledge-muted">{{ copy('ملخص ضمن أحدث 100 لقطة مشتركة محفوظة؛ ليست أعدادًا شاملة لكل التاريخ. تشمل هذه الأعداد معرفة المنصة المشتركة فقط، ولا تشمل معالجة الأنشطة الخاصة.', 'A summary within the latest 100 saved shared captures; these are not all-time totals. These counts cover shared platform knowledge only, never private business processing.') }}</p>
                <ul v-if="operations.shared_knowledge?.length" class="knowledge-sources" aria-labelledby="knowledge-shared-title">
                    <li v-for="item in operations.shared_knowledge" :key="item.method_ref + ':' + item.publication_state + ':' + item.validity + ':' + item.processing_outcome + ':' + item.reason_category" class="knowledge-source" :data-shared-state="refreshFailed ? 'previous_snapshot' : item.publication_state" :data-shared-validity="refreshFailed ? 'previous_snapshot' : item.validity" :data-shared-outcome="!refreshFailed && item.publication_state === 'published' ? item.processing_outcome : undefined" :data-shared-reason="!refreshFailed && item.publication_state === 'published' ? item.reason_category : undefined">
                        <div class="knowledge-source-heading"><h3>{{ methodName(item.method_ref) }}</h3><span class="knowledge-status" :class="{ 'knowledge-status-review': refreshFailed || (item.publication_state === 'published' && item.validity !== 'current') }">{{ refreshFailed ? copy('ملخص سابق — يلزم تحديث التحقق', 'Previous summary — refresh verification') : sharedState(item) }}</span></div>
                        <dl v-if="!refreshFailed" class="knowledge-facts">
                            <div><dt>{{ copy('حالة النشر', 'Publication state') }}</dt><dd>{{ sharedState(item) }}</dd></div>
                            <div><dt>{{ copy('صلاحية المعرفة', 'Knowledge validity') }}</dt><dd>{{ sharedValidity(item) }}</dd></div>
                            <div v-if="item.publication_state === 'published'"><dt>{{ copy('نتيجة المعالجة', 'Processing outcome') }}</dt><dd>{{ sharedOutcome(item) }}</dd></div>
                        </dl>
                        <template v-if="!refreshFailed && item.publication_state === 'published'">
                            <p>{{ outcomeConsequence(item) }}</p>
                            <p v-if="item.processing_outcome === 'abstained'">{{ sharedReason(item) }}</p>
                        </template>
                        <p>{{ refreshFailed ? copy('حدّث القراءة قبل تقييم حالة المعرفة أو اتخاذ خطوة جديدة.', 'Refresh this read before assessing knowledge status or taking a new step.') : sharedNext(item) }}</p>
                        <p class="knowledge-muted">{{ copy('السجلات المشتركة ضمن هذه الحالة والنافذة', 'Shared records in this state and window') }}: <b>{{ item.count }}</b></p>
                    </li>
                </ul>
                <p v-else class="knowledge-empty">{{ copy('لم تُرجع هذه القراءة ملخصات معرفة مشتركة. هذا ليس عدد تحليلات الأنشطة ولا دليلًا على غياب معرفة السوق.', 'No shared knowledge summaries were returned in this read. This is not a count of business analyses or evidence that market knowledge is absent.') }}</p>
            </section>

            <section class="knowledge-section knowledge-boundary" aria-labelledby="knowledge-boundary-title">
                <h2 id="knowledge-boundary-title">{{ copy('حدود هذه المتابعة', 'Scope of this oversight') }}</h2>
                <p>{{ copy('لا تُعرض هنا نتائج الأنشطة الخاصة أو مدخلاتها أو عدد عملياتها. الوصول إليها يحتاج حقوق النشاط والسياق والغرض المؤهل.', 'Private business results, inputs and processing counts are not shown here. Access requires the relevant business, context and purpose rights.') }}</p>
                <p>{{ copy('حداثة الدليل وصلاحية الاستنتاج تُقيّمان عند الاستعمال؛ سريان سند المصدر لا يكفي للحكم عليهما.', 'Evidence currentness and finding validity are evaluated at use; a current source entitlement is not sufficient to establish either.') }}</p>
            </section>
        </div>
    </OperationsShell>
</template>

<style scoped>
.knowledge-operations{max-inline-size:1040px;display:grid;gap:24px;color:var(--admin-ink)}
.knowledge-toolbar,.knowledge-heading,.knowledge-source-heading,.knowledge-paging{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.knowledge-toolbar p{max-inline-size:70ch;margin:0;color:var(--admin-muted)}.knowledge-toolbar button{flex:none}
.knowledge-section{background:var(--admin-surface,#fff);border:1px solid var(--admin-line);border-radius:10px;padding:24px;min-inline-size:0}
.knowledge-section h2{font-size:20px;line-height:1.6;margin:0 0 10px;font-weight:650}.knowledge-section p{line-height:1.9;margin-block:10px}.knowledge-section h3{font-size:16px;margin:0;font-weight:650}
.knowledge-attention{border-inline-start:4px solid var(--ba-primary-ink)}.knowledge-warning{padding:16px;border:1px solid var(--admin-warning);border-inline-start-width:4px;line-height:1.9;background:var(--admin-surface,#fff);margin:0}
.knowledge-caption,.knowledge-muted,.knowledge-facts dt{color:var(--admin-muted);font-size:13px}.knowledge-heading h2{margin:0}
.knowledge-sources,.knowledge-methods{list-style:none;margin:20px 0 0;padding:0}.knowledge-source{border-block-start:1px solid var(--admin-line);padding-block:20px}.knowledge-source:last-child{padding-block-end:0}.knowledge-source p{max-inline-size:80ch}
.knowledge-status{font-size:12px;font-weight:600;line-height:1.7;display:inline-block;padding:4px 9px;border:1px solid var(--admin-line);border-radius:5px;background:var(--admin-group);white-space:normal;overflow-wrap:anywhere}.knowledge-status-review{border-color:var(--admin-warning);color:var(--admin-warning)}
.knowledge-facts{margin:14px 0;display:grid;gap:10px}.knowledge-facts dd{margin:4px 0 0;line-height:1.8}.knowledge-reference{font-size:12px;color:var(--admin-muted);margin-block-start:14px}.knowledge-reference summary{cursor:pointer;padding-block:8px;min-block-size:40px}.knowledge-reference bdi{display:block;overflow-wrap:anywhere;user-select:all}.knowledge-reference p{font-size:12px}
.knowledge-methods li{display:flex;gap:16px;align-items:center;justify-content:space-between;padding-block:14px;border-block-start:1px solid var(--admin-line)}.knowledge-methods li>span:first-child{min-inline-size:0}.knowledge-methods .knowledge-status{flex:none}.knowledge-empty{color:var(--admin-muted)}.knowledge-paging{justify-content:flex-start;margin-block-start:20px}.knowledge-boundary p{color:var(--admin-muted)}
@media(max-width:639px){.knowledge-operations{gap:18px}.knowledge-section{padding:18px}.knowledge-source-heading{align-items:flex-start;gap:10px}.knowledge-section h2{font-size:18px}.knowledge-methods li{align-items:flex-start;gap:10px;flex-wrap:wrap}.knowledge-section p{line-height:1.95}.knowledge-toolbar{align-items:flex-start}.knowledge-toolbar button,.knowledge-paging button{min-block-size:44px}.knowledge-status{max-inline-size:100%}}
</style>
