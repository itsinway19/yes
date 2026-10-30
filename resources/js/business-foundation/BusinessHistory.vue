<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { businessHistory, type BusinessHistory, type Surface } from './api';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const opened = ref(false), loading = ref(false), failed = ref(false);
const kind = ref<BusinessHistory['kind']>('context'), page = ref<BusinessHistory>();
let serial = 0, disposed = false;
onBeforeUnmount(() => { disposed = true; serial++; });
function clear() { serial++; page.value = undefined; failed.value = false; loading.value = false; }
watch(() => [props.surface, props.refreshing], () => { clear(); opened.value = false; }, { flush: 'sync' });
watch(status, value => { if (value !== 'ready') { clear(); opened.value = false; } }, { flush: 'sync' });
watch(kind, () => { clear(); if (opened.value) void read(); }, { flush: 'sync' });
const choices = computed(() => [
    { id: 'context', label: t('اسم النشاط وتصنيفه', 'Business name and type') },
    { id: 'markets', label: t('الأسواق المعلنة', 'Declared markets') },
    { id: 'intent', label: t('النية التجارية الحالية', 'Current commercial intent') },
    { id: 'preferences', label: t('تفضيلات الأهداف', 'Goal preferences') },
]);
async function read(after?: string) {
    if (props.refreshing || disposed || status.value !== 'ready') return;
    const attempt = ++serial, captured = stamp(), subject = props.surface, selected = kind.value;
    page.value = undefined; failed.value = false; loading.value = true;
    try {
        const grant = subject.authority_selection.requirements.find(r => r.requirement_kind === 'business')?.leaf_grant_id;
        if (!Number.isSafeInteger(grant)) throw Error('Exact representable Business read path required');
        const result = await businessHistory(subject.business.business_public_id, grant!, selected, after);
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject || status.value !== 'ready') return;
        if (result.business_public_id !== subject.business.business_public_id || result.kind !== selected) throw Error('History resource identity mismatch');
        page.value = result;
    } catch {
        if (!disposed && attempt === serial && owned(captured) && props.surface === subject) failed.value = true;
    } finally { if (!disposed && attempt === serial) loading.value = false; }
}
function toggle(event: Event) {
    opened.value = (event.currentTarget as HTMLDetailsElement).open;
    if (opened.value && !page.value && !loading.value) void read();
    if (!opened.value) clear();
}
function valueText(value: unknown): string {
    if (value === null || value === undefined) return t('غير مصرح به', 'Not declared');
    if (Array.isArray(value)) return value.length ? value.map(code => {
        const item = props.surface.catalog.countries.find(c => c.code === code);
        return item ? t(item.name_ar, item.name_en) : String(code);
    }).join('، ') : t('لا أسواق معلنة', 'No markets declared');
    if (typeof value !== 'object') return String(value);
    const row = value as Record<string, unknown>;
    if ('name' in row) {
        const type = props.surface.catalog.business_types.find(item => item.id === row.business_type_id);
        return `${String(row.name)} — ${type ? t(type.name_ar, type.name_en) : t('دون تصنيف', 'No type')}`;
    }
    if ('outcome' in row) {
        const outcome = row.outcome as { meaning?: string; clarification?: string | null; count_unit?: string | null } | undefined;
        const horizon = row.horizon as { deadline_date: string; timezone: string } | null;
        const direction = ({ increase_value: t('زيادة القيمة', 'Increase value'), preserve_value: t('الحفاظ على القيمة', 'Preserve value'),
            improve_efficiency_preserving_value: t('تحسين الكفاءة مع الحفاظ على القيمة', 'Improve efficiency while preserving value'), validate_business_direction: t('اختبار اتجاه النشاط', 'Validate business direction') } as Record<string, string>)[String(row.direction)] ?? String(row.direction);
        return [direction, row.description, outcome?.meaning, outcome?.clarification,
            row.quantity ? `${t('الكمية', 'Quantity')}: ${row.quantity}` : t('دون كمية محددة', 'No specified quantity'),
            outcome?.count_unit ? `${t('وحدة العد', 'Count unit')}: ${outcome.count_unit}` : null,
            horizon ? `${t('الموعد', 'Deadline')}: ${horizon.deadline_date} (${horizon.timezone})` : t('دون موعد محدد', 'No specified deadline')].filter(Boolean).join('\n');
    }
    if (Array.isArray(row.goals)) {
        const goalLabel = (id: unknown) => { const entry = props.surface.catalog.goals.find(g => g.id === id); return entry ? t(entry.name_ar, entry.name_en) : String(id); };
        const results = (row.results ?? {}) as Record<string, string[]>;
        const labels = row.goals.map(goal => {
            const entry = props.surface.catalog.goals.find(g => g.id === goal);
            const selected = (results[String(goal)] ?? []).map(id => { const r = entry?.results.find(r => r.id === id); return r ? t(r.name_ar, r.name_en) : id; });
            return `${goalLabel(goal)}${selected.length ? `: ${selected.join('، ')}` : ''}`;
        });
        return [labels.join('\n') || t('لا تفضيلات معلنة', 'No preferences declared'), row.priority ? `${t('الأولوية', 'Priority')}: ${goalLabel(row.priority)}` : t('دون أولوية محددة', 'No specified priority')].join('\n');
    }
    return t('تصريح محفوظ', 'Saved declaration');
}
function time(value: string) { return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-JO' : 'en-GB'); }
</script>
<template>
    <details class="bf-card bf-history" data-business-history :open="opened" @toggle="toggle">
        <summary>{{ t('تاريخ تصريحات النشاط', 'Business declaration history') }}</summary>
        <template v-if="opened">
            <p>{{ t('سجل التصريحات المحفوظة في أساس النشاط الجديد، وليس دليلًا على تحقق نتائجها. التصحيح يتم بتصريح جديد من محرر النشاط؛ لا يُمحى التاريخ.', 'Declarations saved in the new Business foundation, not proof of their outcomes. Correct through a new declaration in the Business editor; history is not erased.') }}</p>
            <label for="bf-history-kind">{{ t('نوع التصريح', 'Declaration type') }}</label>
            <select id="bf-history-kind" v-model="kind" :disabled="refreshing"><option v-for="item in choices" :key="item.id" :value="item.id">{{ item.label }}</option></select>
            <button type="button" class="bf-secondary" :disabled="loading || refreshing" @click="read()">{{ t('قراءة أحدث سجل', 'Read latest history') }}</button>
            <p v-if="loading" role="status">{{ t('جارٍ تأهيل قراءة التاريخ…', 'Qualifying history access…') }}</p>
            <p v-else-if="failed" role="alert" data-history-unavailable>{{ t('تعذرت قراءة السجل بحق الوصول الحالي. لم نعتبر ذلك سجلًا فارغًا.', 'History is unavailable under the current access path. This was not interpreted as empty history.') }}</p>
            <template v-else-if="page">
                <p v-if="page.has_imported_baseline" data-history-imported>{{ t('هذا النشاط يبدأ من خط أساس مستورد مؤهل. إصدارات وتاريخ المصدر القديم لا تدخل هذا السجل، ولا تُعاد تسميتها كإصدارات جديدة.', 'This Business starts from a qualified imported baseline. Original source history and versions are not included or relabelled as new revisions.') }}</p>
                <p v-else>{{ t('يعرض هذا السجل انتقالات التصريحات الجديدة فقط، بترتيب إصدارات مالكها.', 'This history contains new declaration transitions only, ordered by their owner revision.') }}</p>
                <p v-if="!page.entries.length" data-history-empty>{{ t('لا انتقالات محفوظة لهذا النوع في الصفحة المطلوبة. هذا لا يثبت غياب سجل قديم أو نتيجة تجارية.', 'No saved transitions for this kind on the requested page. This proves neither absence of legacy history nor a commercial outcome.') }}</p>
                <ol v-else class="bf-history-list" data-history-entries>
                    <li v-for="entry in page.entries" :key="entry.event_id">
                        <p><strong>{{ t('الإصدار', 'Revision') }} {{ entry.version }}</strong> · <time :datetime="entry.recorded_at">{{ time(entry.recorded_at) }}</time></p>
                        <dl><dt>{{ t('قبل', 'Before') }} · {{ entry.before_version }}</dt><dd data-history-before>{{ valueText(entry.before) }}</dd><dt>{{ t('بعد', 'After') }}</dt><dd data-history-after>{{ valueText(entry.after) }}</dd></dl>
                    </li>
                </ol>
                <button v-if="page.next_cursor" class="bf-secondary" type="button" @click="read(page.next_cursor!)">{{ t('الصفحة التالية من السجل', 'Next history page') }}</button>
            </template>
        </template>
    </details>
</template>
