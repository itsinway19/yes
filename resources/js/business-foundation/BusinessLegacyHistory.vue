<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { legacyHistory, type LegacyHistory, type Surface } from './api';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const opened = ref(false), loading = ref(false), failed = ref(false), page = ref<LegacyHistory>();
const kind = ref<LegacyHistory['kind']>('object_purpose');
let serial = 0, disposed = false;
function clear() { serial++; page.value = undefined; failed.value = false; loading.value = false; }
onBeforeUnmount(() => { disposed = true; clear(); });
watch(() => [props.surface, props.refreshing], () => { clear(); opened.value = false; }, { flush: 'sync' });
watch(status, value => { if (value !== 'ready') { clear(); opened.value = false; } }, { flush: 'sync' });
watch(kind, () => { clear(); if (opened.value) void read(); }, { flush: 'sync' });
const choices = computed(() => [
    { id: 'object_purpose', label: t('الغرض الإعلاني لعناصر محددة', 'Advertising purpose for specific objects') },
    { id: 'context', label: t('اسم النشاط وتصنيفه', 'Business name and type') }, { id: 'markets', label: t('الأسواق المعلنة', 'Declared markets') },
    { id: 'intent', label: t('النية التجارية', 'Commercial intent') }, { id: 'preferences', label: t('تفضيلات الأهداف', 'Goal preferences') },
]);
async function read(after?: string) {
    if (props.refreshing || disposed || status.value !== 'ready') return;
    const attempt = ++serial, captured = stamp(), subject = props.surface, selected = kind.value;
    clearPage(); loading.value = true;
    try {
        const grant = subject.authority_selection.requirements.find(r => r.requirement_kind === 'business')?.leaf_grant_id;
        if (!Number.isSafeInteger(grant)) throw Error('Exact current Business read path required');
        const result = await legacyHistory(subject.business.business_public_id, grant!, selected, after);
        if (disposed || serial !== attempt || !owned(captured) || props.surface !== subject || status.value !== 'ready') return;
        if (result.business_public_id !== subject.business.business_public_id || result.kind !== selected) throw Error('Original history resource mismatch');
        page.value = result;
    } catch { if (!disposed && serial === attempt && owned(captured) && props.surface === subject && status.value === 'ready') failed.value = true; }
    finally { if (!disposed && serial === attempt) loading.value = false; }
}
function clearPage() { page.value = undefined; failed.value = false; }
function toggle(event: Event) {
    opened.value = (event.currentTarget as HTMLDetailsElement).open;
    if (opened.value && !page.value && !loading.value) void read();
    if (!opened.value) clear();
}
function date(value: string) { return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-JO' : 'en-GB'); }
function valueText(value: unknown): string {
    if (value === null || value === undefined) return t('غير مصرح به', 'Not declared');
    if (Array.isArray(value)) return value.map(code => { const c = props.surface.catalog.countries.find(c => c.code === code); return c ? t(c.name_ar, c.name_en) : String(code); }).join('، ') || t('لا أسواق معلنة', 'No markets declared');
    if (typeof value !== 'object') return String(value);
    const v = value as Record<string, unknown>;
    if ('name' in v) { const type = props.surface.catalog.business_types.find(item => item.id === v.business_type_id); return `${v.name} — ${type ? t(type.name_ar, type.name_en) : t('دون تصنيف', 'No type')}`; }
    if ('outcome' in v) return [(v.outcome as { meaning?: string })?.meaning, v.description, v.quantity].filter(Boolean).join(' — ');
    if (Array.isArray(v.goals)) return v.goals.map(id => { const goal = props.surface.catalog.goals.find(g => g.id === id); return goal ? t(goal.name_ar, goal.name_en) : String(id); }).join('، ');
    return t('تصريح أصلي محفوظ', 'Saved original declaration');
}
function operation(value: string) { return ({ declare: t('تصريح أصلي', 'Original declaration'), replace: t('استبدال تصريح', 'Replacement'), correct: t('تصحيح تصريح', 'Correction'), withdraw: t('سحب تصريح', 'Withdrawal') } as Record<string, string>)[value] ?? value; }
</script>
<template>
    <details class="bf-card bf-history" data-business-legacy-history :open="opened" @toggle="toggle">
        <summary>{{ t('السجل السابق المؤهل والأغراض الإعلانية', 'Qualified original history and advertising purposes') }}</summary>
        <template v-if="opened">
            <p>{{ t('يعرض فقط السجلات الأصلية التي ثبت ارتباطها بهذا النشاط وما زال سندها مؤهلًا. ليس سجلًا كاملًا ولا تصريحًا بوجود غرض نافذ الآن؛ قد تبقى مراجعات أو روابط غير مؤهلة خارج العرض.', 'Only original records with a currently qualified mapping to this Business are shown. This is not complete history or a claim of a currently applicable purpose; other revisions or references may remain unqualified.') }}</p>
            <label for="bf-legacy-kind">{{ t('نوع السجل السابق', 'Original history type') }}</label>
            <select id="bf-legacy-kind" v-model="kind" :disabled="refreshing"><option v-for="item in choices" :key="item.id" :value="item.id">{{ item.label }}</option></select>
            <button type="button" class="bf-secondary" :disabled="loading || refreshing" @click="read()">{{ t('قراءة السجل المؤهل', 'Read qualified history') }}</button>
            <p v-if="loading" role="status">{{ t('جارٍ التحقق من السجل وحقوقه…', 'Checking original records and rights…') }}</p>
            <p v-else-if="failed" role="alert" data-legacy-unavailable>{{ t('تعذرت قراءة السجل المؤهل. لم نعتبر التعذر غيابًا للتاريخ.', 'Qualified history is unavailable. Failure was not interpreted as absent history.') }}</p>
            <template v-else-if="page">
                <p v-if="!page.entries.length" data-legacy-empty>{{ t('لا سجلات مؤهلة معروضة في هذه الصفحة. هذا لا يثبت غياب تاريخ أصلي أو غرض إعلاني.', 'No qualified records are displayed on this page. This does not prove absence of original history or an advertising purpose.') }}</p>
                <p v-else>{{ t('الترتيب بمراجع الربط؛ لا نستنتج منه تسلسل النفاذ أو انطباق الغرض حاليًا.', 'Ordered by mapping references; effective order or current purpose applicability is not inferred.') }}</p>
                <ol v-if="page.kind === 'object_purpose'" class="bf-history-list" data-legacy-entries>
                    <li v-for="entry in page.entries" :key="entry.mapping_ref" data-legacy-purpose>
                        <h3>{{ operation(entry.operation) }} · {{ t('المراجعة الأصلية', 'Original revision') }} {{ entry.native_version }}</h3>
                        <p data-legacy-meaning>{{ entry.snapshot.meaning }}</p>
                        <dl><dt>{{ t('حُفظ أصلًا في', 'Originally accepted') }}</dt><dd>{{ date(entry.recorded_at) }}</dd>
                            <dt>{{ t('بدء النفاذ المصرح به', 'Declared effective start') }}</dt><dd>{{ date(entry.effective_from) }}</dd>
                            <dt>{{ t('نهاية النفاذ المصرح بها', 'Declared effective end') }}</dt><dd>{{ entry.effective_until ? date(entry.effective_until) : t('لا موعد نهاية محدد', 'No specified end date') }}</dd>
                            <template v-if="entry.reason"><dt>{{ t('سبب التغيير الأصلي', 'Original change reason') }}</dt><dd>{{ entry.reason }}</dd></template>
                            <template v-if="entry.snapshot.end_condition"><dt>{{ t('شرط الانتهاء المعلن', 'Declared end condition') }}</dt><dd>{{ entry.snapshot.end_condition }}</dd></template>
                            <template v-if="entry.snapshot.limitations"><dt>{{ t('حدود التصريح', 'Declaration limits') }}</dt><dd>{{ entry.snapshot.limitations }}</dd></template>
                        </dl>
                        <details><summary>{{ t('تفاصيل التصريح ومراجعه الأصلية', 'Original declaration details and references') }}</summary>
                            <p>{{ t('المعرّفات محفوظة كما صدرت؛ لا تمنح حقًا على عنصر إعلاني أو مصدر.', 'Original identifiers are preserved; they grant no advertising-object or source authority.') }}</p>
                            <code>{{ entry.purpose_id }} / {{ entry.revision_id }}</code>
                            <p v-if="entry.supersedes_revision_id">{{ t('يراجع التصريح', 'Supersedes revision') }}: <code>{{ entry.supersedes_revision_id }}</code></p>
                            <p v-if="entry.snapshot.qualifications">{{ entry.snapshot.qualifications }}</p>
                            <p>{{ entry.snapshot.provenance.source }} · {{ entry.snapshot.provenance.identity_basis }}</p>
                            <p>{{ t('العناصر المشمولة', 'Included objects') }}</p><ul><li v-for="(item, i) in entry.snapshot.included_objects" :key="i"><code>{{ item.provider }} / {{ item.account_id }} / {{ item.object_kind }} / {{ item.object_id }}</code></li></ul>
                            <p>{{ t('العناصر المستثناة', 'Excluded objects') }}</p><ul><li v-for="(item, i) in entry.snapshot.excluded_objects" :key="i"><code>{{ item.provider }} / {{ item.account_id }} / {{ item.object_kind }} / {{ item.object_id }}</code></li></ul>
                            <p v-if="entry.snapshot.dtm">{{ t('مرجع أسواق أصلي دون إعادة تأهيل حالي', 'Original market reference without current revalidation') }}: {{ entry.snapshot.dtm.version }} · {{ entry.snapshot.dtm.countries.join('، ') }}</p>
                            <p v-if="entry.snapshot.intent">{{ t('مرجع نية أصلي دون إعادة تأهيل حالي', 'Original intent reference without current revalidation') }}: {{ entry.snapshot.intent.version }} · {{ entry.snapshot.intent.value.outcome.meaning }}</p>
                            <ul><li v-for="(conflict, i) in entry.snapshot.conflicts" :key="i">{{ conflict }}</li></ul>
                        </details>
                    </li>
                </ol>
                <ol v-else class="bf-history-list" data-legacy-entries><li v-for="entry in page.entries" :key="entry.mapping_ref">
                    <p>{{ t('الإصدار الأصلي', 'Original version') }} {{ entry.native_version }} · {{ date(entry.recorded_at) }}</p>
                    <dl><dt>{{ t('قبل', 'Before') }}</dt><dd>{{ valueText(entry.before) }}</dd><dt>{{ t('بعد', 'After') }}</dt><dd data-legacy-after>{{ valueText(entry.after) }}</dd></dl>
                </li></ol>
                <button v-if="page.next_cursor" class="bf-secondary" type="button" @click="read(page.next_cursor!)">{{ t('الصفحة التالية من السجل السابق', 'Next original history page') }}</button>
            </template>
        </template>
    </details>
</template>
