<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { goalSignature, setPriority, toggleGoal, toggleResult } from '../Components/Business/goalSelection';
import { factDrafts, key, type FactDraft, type FactFields } from './drafts';
import { writeFact, type FactCommand, type FactKind, type Intent, type Surface } from './api';

const props = defineProps<{ surface: Surface; refreshing?: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v));
const kinds: FactKind[] = ['markets', 'intent', 'preferences'];
const title = (kind: FactKind) => kind === 'markets' ? t('الأسواق المستهدفة', 'Target markets')
    : kind === 'intent' ? t('النية التجارية الحالية', 'Current business intent') : t('أولويات المعلن', 'Advertiser preferences');
const flagNames = ['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity', 'horizon'];
const flags = () => Object.fromEntries(flagNames.map(name => [name, false]));
const original = (kind: FactKind) => kind === 'markets' ? props.surface.business.declared_markets
    : kind === 'intent' ? props.surface.business.current_intent : props.surface.business.preferences;
function fields(): FactFields {
    const b = props.surface.business, i = b.current_intent;
    return { codes: [...b.declared_markets], goals: clone(b.preferences ?? { goals: [], priority: null, results: {} }), clearing: false,
        direction: i?.direction ?? '', description: i?.description ?? '', meaning: i?.outcome.meaning ?? '', clarification: i?.outcome.clarification ?? '',
        countUnit: i?.outcome.count_unit ?? '', quantity: i?.quantity ?? '', date: i?.horizon?.deadline_date ?? '', timezone: i?.horizon?.timezone ?? '' };
}
const resourceKey = key(props.surface.business.business_public_id, props.surface.authority_selection);
const draftKey = (kind: FactKind) => `${resourceKey}:${kind}:1`;
const options = (kind: FactKind) => props.surface.fact_write_options[kind].filter(option => Number.isSafeInteger(Number(option.grant_id)));
function fresh(kind: FactKind): FactDraft {
    const v = props.surface.business.versions;
    return { kind, fields: fields(), baseline: signature(kind, original(kind)), context: v.context, intent: v.intent, preferences: v.preferences,
        selected: options(kind).length === 1 ? options(kind)[0].grant_id : '', flags: flags(), notice: '', confirmed: false, blocked: false };
}
for (const kind of kinds) {
    const saved = factDrafts.get(draftKey(kind));
    if (saved && !options(kind).some(option => option.grant_id === saved.selected)) factDrafts.delete(draftKey(kind));
}
const editors = reactive(Object.fromEntries(kinds.map(kind => [kind, factDrafts.get(draftKey(kind)) ?? fresh(kind)])) as Record<FactKind, FactDraft>);
for (const kind of kinds) factDrafts.set(draftKey(kind), editors[kind]);
const active = ref<FactKind>('markets'), busy = ref(false);
const marketSearch = ref('');
const filteredCountries = computed(() => {
    const search = marketSearch.value.trim().toLocaleLowerCase();
    return props.surface.catalog.countries.filter(country => [country.code, country.name_ar, country.name_en].some(text => text.toLocaleLowerCase().includes(search)));
});
const draft = computed(() => editors[active.value]);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
const optional = (value: string) => value.replace(/^[\t\n\r ]+|[\t\n\r ]+$/g, '') || null;
function value(d: FactDraft): string[] | Intent | null | FactFields['goals'] {
    const f = d.fields;
    if (d.kind === 'markets') return f.codes;
    if (d.kind === 'preferences') return f.goals;
    if (f.clearing) return null;
    if (![f.direction, f.description, f.meaning, f.clarification, f.countUnit, f.quantity, f.date, f.timezone].some(Boolean)) return null;
    return { direction: f.direction as Intent['direction'], scope: 'business_wide', description: optional(f.description),
        outcome: { meaning: optional(f.meaning) ?? '', clarification: optional(f.clarification), count_unit: optional(f.countUnit) }, quantity: optional(f.quantity),
        horizon: f.date || f.timezone ? { deadline_date: f.date, timezone: f.timezone } : null };
}
function signature(kind: FactKind, v: ReturnType<typeof original> | ReturnType<typeof value>): string {
    if (kind === 'markets') return JSON.stringify([...(v as string[])].sort());
    if (kind === 'preferences') return goalSignature(v as FactFields['goals'] ?? { goals: [], priority: null, results: {} });
    if (!v) return 'null';
    const i = v as Intent;
    return JSON.stringify([i.direction, i.scope, i.description, i.outcome.meaning, i.outcome.clarification, i.outcome.count_unit,
        i.quantity, i.horizon?.deadline_date ?? null, i.horizon?.timezone ?? null]);
}
const dirty = (d: FactDraft) => signature(d.kind, value(d)) !== d.baseline;
const changed = (d: FactDraft) => d.kind === 'preferences' ? d.preferences !== props.surface.business.versions.preferences
    : d.context !== props.surface.business.versions.context || d.kind === 'intent' && d.intent !== props.surface.business.versions.intent;
const canEdit = (d: FactDraft) => !props.refreshing && !d.blocked && options(d.kind).some(option => option.grant_id === d.selected);
function adopt(kind: FactKind) {
    if (busy.value || editors[kind].pending) return;
    Object.assign(editors[kind], fresh(kind));
}
watch(() => props.surface, () => {
    for (const kind of kinds) {
        const d = editors[kind];
        if (d.selected && !options(kind).some(option => option.grant_id === d.selected)) {
            factDrafts.delete(draftKey(kind)); Object.assign(d, fresh(kind));
        } else if (!dirty(d) && !d.pending) { const confirmed = d.confirmed; Object.assign(d, fresh(kind)); d.confirmed = confirmed; }
    }
});
for (const kind of kinds) watch(() => signature(kind, value(editors[kind])), () => {
    Object.assign(editors[kind].flags, flags()); editors[kind].confirmed = false; editors[kind].notice = '';
}, { flush: 'sync' });
async function save() {
    const d = draft.value, kind = d.kind;
    if (busy.value || !canEdit(d) || changed(d) && !d.pending) return;
    if (!d.pending) {
        if (kind === 'preferences' && (!d.fields.goals.goals.length || !d.fields.goals.priority || !d.fields.goals.goals.includes(d.fields.goals.priority))) {
            d.notice = t('اختر نتيجة واحدة على الأقل وحدد الأولوية الحالية.', 'Choose at least one outcome and its current priority.'); return;
        }
        if (kind === 'intent' && (!d.flags.declaration || !d.fields.clearing && (!d.flags.business_wide || !d.flags.outcome || !d.flags.acceptance_clear
            || !d.fields.direction || !optional(d.fields.meaning) || !!d.fields.clarification && !d.flags.clarification_required
            || !!d.fields.quantity && !d.flags.quantity || !!(d.fields.date || d.fields.timezone) && !d.flags.horizon))) {
            d.notice = t('راجع النتيجة وأكّد التصريحات المرتبطة بها.', 'Review the outcome and explicitly confirm its declarations.'); return;
        }
        const base = { command_id: crypto.randomUUID(), authority_selection: { ...clone(props.surface.authority_selection),
            requirements: [{ requirement_kind: 'business' as const, leaf_grant_id: Number(d.selected) }] } };
        d.pending = kind === 'markets' ? { ...base, kind, codes: [...d.fields.codes], expected_context: d.context }
            : kind === 'preferences' ? { ...base, kind, value: { ...clone(d.fields.goals), priority: d.fields.goals.priority! }, expected_preference: d.preferences }
                : { ...base, kind, value: value(d) as Intent | null, confirmations: clone(d.flags), expected_context: d.context, expected_intent: d.intent };
    }
    const captured = stamp(), business = props.surface.business.business_public_id, command = clone(d.pending) as FactCommand;
    busy.value = true; d.confirmed = false; d.notice = '';
    try {
        const result = await writeFact(business, command);
        if (disposed || !owned(captured)) return;
        if (result.business_public_id !== business) throw Error('Fact acknowledgement subject mismatch');
        if ('codes' in result) { d.fields.codes = [...result.codes]; d.context = result.versions.context; }
        else if ('preferences' in result.versions && 'value' in result) { d.fields.goals = clone(result.value as FactFields['goals']); d.preferences = result.versions.preferences; }
        else if ('intent' in result.versions && 'value' in result) {
            const i = result.value as Intent | null;
            Object.assign(d.fields, { clearing: i === null, direction: i?.direction ?? '', description: i?.description ?? '', meaning: i?.outcome.meaning ?? '',
                clarification: i?.outcome.clarification ?? '', countUnit: i?.outcome.count_unit ?? '', quantity: i?.quantity ?? '', date: i?.horizon?.deadline_date ?? '', timezone: i?.horizon?.timezone ?? '' });
            d.context = result.versions.context; d.intent = result.versions.intent;
        } else throw Error('Fact acknowledgement shape mismatch');
        d.baseline = signature(kind, value(d));
        d.pending = undefined; d.confirmed = true;
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            d.blocked = true; factDrafts.delete(draftKey(kind)); d.fields = fields(); d.pending = undefined;
            d.notice = t('لم يعد هذا التفويض يسمح بالفعل. تحقّق من الوصول الحالي.', 'This delegation no longer permits this action. Check current access.'); emit('refresh');
        } else if (error instanceof TransportError && error.status === 422) {
            d.pending = undefined; d.notice = t('لم يُقبل الإدخال. راجع الحقول والتأكيدات؛ لم تُحفظ نتيجة.', 'Input was rejected. Review the fields and confirmations; no result was saved.');
        } else {
            d.notice = t('نتيجة الطلب غير مثبتة. نحتفظ بالطلب الأصلي؛ تحقّق به قبل أي طلب جديد.', 'The outcome is unconfirmed. The original command is retained; reconcile it before any new command.');
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
const country = (code: string) => { const c = props.surface.catalog.countries.find(c => c.code === code); return c ? locale.value === 'ar' ? c.name_ar : c.name_en : code; };
const localized = (v: { name_ar: string; name_en: string }) => locale.value === 'ar' ? v.name_ar : v.name_en;
const directions = computed(() => [
    ['increase_value', t('زيادة القيمة التجارية', 'Increase business value')], ['preserve_value', t('الحفاظ على القيمة التجارية', 'Preserve business value')],
    ['improve_efficiency_preserving_value', t('تحسين الكفاءة مع الحفاظ على القيمة', 'Improve efficiency while preserving value')], ['validate_business_direction', t('التحقق من اتجاه النشاط', 'Validate the business direction')],
]);
</script>

<template>
    <section class="bf-card bf-facts bf-facts-compact" data-business-facts>
        <nav class="bf-fact-navigation" :aria-label="t('معلومات النشاط', 'Business information')">
            <button v-for="kind in kinds" :key="kind" type="button" :data-business-fact-tab="kind" :aria-current="active === kind ? 'page' : undefined" :aria-pressed="active === kind" :disabled="busy || refreshing" @click="active = kind">{{ title(kind) }}</button>
        </nav>
        <p v-if="active === 'markets'" class="bf-fact-intro">{{ t('اختر الدول التي تريد خدمة العملاء فيها. يمكنك تعديلها أو تركها دون تحديد.', 'Choose the countries where you want to serve customers. You can change them or leave them unspecified.') }}</p>
        <p v-else-if="active === 'preferences'" class="bf-fact-intro">{{ t('ما النتائج التي تهم نشاطك؟ اختر منها وحدّد أولوية واحدة، ثم النتائج التي تريد متابعتها.', 'Which outcomes matter to your business? Select them, choose one priority and the outcomes you want to follow.') }}</p>
        <div v-else class="bf-intent-explanation"><p>{{ t('النية التجارية هي الاتجاه الذي تريد التركيز عليه الآن، والنتيجة التي ستعرف بها أنك حققته. ليست إعداد حملة إعلانية.', 'Business intent is the direction you want to focus on now and the outcome that tells you it was achieved. It is not an advertising campaign setup.') }}</p><p class="bf-fact-example">{{ t('مثال: زيادة عقود الصيانة المنجزة؛ والنتيجة: عقد أُنجزت خدمته وفق التعريف الذي تحدده. تحديد اتجاه حالي اختياري.', 'Example: increase completed maintenance contracts; the outcome is a contract whose service was completed under your definition. Declaring a current direction is optional.') }}</p></div>
        <p v-if="refreshing" role="status">{{ t('جارٍ تحديث البيانات المحفوظة…', 'Updating saved information…') }}</p>
        <details v-else class="bf-saved-fact" data-business-fact-current>
            <summary>{{ t('المحفوظ حاليًا', 'Currently saved') }}<span v-if="active === 'markets'"> · {{ surface.business.declared_markets.length ? surface.business.declared_markets.map(country).join('، ') : t('لم تُحدد أسواق', 'Markets unspecified') }}</span><span v-else-if="active === 'intent'"> · {{ surface.business.current_intent ? t('يوجد اتجاه محفوظ', 'A direction is saved') : t('دون اتجاه محدد', 'No direction specified') }}</span></summary>
            <p v-if="active === 'markets'">{{ surface.business.declared_markets.length ? surface.business.declared_markets.map(country).join('، ') : t('لم تُحدد أسواق بعد؛ هذا لا يعني غياب العملاء.', 'No markets have been declared; this does not mean there are no customers.') }}</p>
            <template v-else-if="active === 'intent'"><p v-if="!surface.business.current_intent">{{ t('لم تحدّد اتجاه النشاط بعد.', 'You have not set a business direction yet.') }}</p><template v-else><p>{{ directions.find(d => d[0] === surface.business.current_intent?.direction)?.[1] }} — {{ surface.business.current_intent.outcome.meaning }}</p><p>{{ surface.business.current_intent.description }} {{ surface.business.current_intent.outcome.clarification }}</p><p v-if="surface.business.current_intent.quantity">{{ surface.business.current_intent.quantity }} {{ surface.business.current_intent.outcome.count_unit }}</p><p v-if="surface.business.current_intent.horizon">{{ surface.business.current_intent.horizon.deadline_date }} · {{ surface.business.current_intent.horizon.timezone }}</p></template></template>
            <template v-else><p v-if="!surface.business.preferences">{{ t('لم تُحفظ أولويات بعد.', 'No preferences have been saved.') }}</p><ul v-else><li v-for="goal in surface.catalog.goals.filter(g => surface.business.preferences?.goals.includes(g.id))" :key="goal.id">{{ localized(goal) }}<strong v-if="surface.business.preferences.priority === goal.id"> — {{ t('الأولوية', 'Priority') }}</strong><ul><li v-for="result in goal.results.filter(r => surface.business.preferences?.results[goal.id]?.includes(r.id))" :key="result.id">{{ localized(result) }}</li></ul></li></ul></template>
        </details>
        <p v-if="!options(active).length || draft.blocked" class="bf-fact-readonly" role="status">{{ t('يمكنك الاطلاع على المحفوظ. التعديل غير متاح لك هنا.', 'You can view the saved information. Editing is not available to you here.') }}</p>
        <form v-else class="bf-form bf-fact-form" :data-business-fact-form="active" @submit.prevent="save" @invalid.capture="($event.target as HTMLElement).closest('details')?.setAttribute('open', '')">
            <div v-if="options(active).length > 1" class="bf-fact-field bf-fact-authority"><label :for="`bf-${active}-grant`">{{ t('الصلاحية التي تستخدمها للحفظ', 'Permission to use for saving') }}</label><select :id="`bf-${active}-grant`" v-model="draft.selected" :disabled="busy || !!draft.pending"><option value="">{{ t('اختر التفويض', 'Choose a delegation') }}</option><option v-for="(option, index) in options(active)" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}</option></select></div>
            <fieldset :disabled="busy || !!draft.pending || !canEdit(draft)">
                <template v-if="active === 'markets'">
                    <legend class="sr-only">{{ t('الدول المستهدفة', 'Target countries') }}</legend>
                    <div class="bf-fact-field"><label for="bf-market-search">{{ t('ابحث عن دولة', 'Find a country') }}</label><input id="bf-market-search" v-model="marketSearch" type="search" autocomplete="off" :placeholder="t('اسم الدولة أو رمزها', 'Country name or code')" /></div>
                    <ul v-if="draft.fields.codes.length" class="bf-market-selected" :aria-label="t('الدول المختارة', 'Selected countries')"><li v-for="code in draft.fields.codes" :key="code"><span>{{ country(code) }}</span><button type="button" :aria-label="`${t('إزالة', 'Remove')} ${country(code)}`" @click="draft.fields.codes = draft.fields.codes.filter(value => value !== code)">×</button></li></ul>
                    <div id="bf-market-codes" class="bf-market-options" role="group" :aria-label="t('الدول المتاحة للاختيار', 'Available countries')"><label v-for="c in filteredCountries" :key="c.code" class="bf-check"><input v-model="draft.fields.codes" type="checkbox" :value="c.code" :data-fact-country="c.code" /><span>{{ localized(c) }}</span><bdi>{{ c.code }}</bdi></label><p v-if="!filteredCountries.length" role="status">{{ t('لا توجد دولة تطابق البحث. جرّب اسمًا آخر أو رمز الدولة.', 'No country matches. Try another name or its country code.') }}</p></div>
                    <p class="bf-fact-hint">{{ t('يمكن اختيار عدة دول. إلغاء اختيارها كلها يعني أن الأسواق غير محددة حاليًا، وليس أن الطلب معدوم.', 'You can select several countries. Clearing them means markets are currently unspecified, not that demand is zero.') }}</p>
                </template>
                <template v-else-if="active === 'preferences'">
                    <legend class="sr-only">{{ t('النتائج والأولوية', 'Outcomes and priority') }}</legend>
                    <div class="bf-preference-grid"><section v-for="goal in surface.catalog.goals" :key="goal.id" class="bf-preference-card" :class="{ selected: draft.fields.goals.goals.includes(goal.id) }"><label class="bf-check bf-preference-choice"><input type="checkbox" :data-fact-goal="goal.id" :checked="draft.fields.goals.goals.includes(goal.id)" @change="toggleGoal(draft.fields.goals, goal.id)" /><strong>{{ localized(goal) }}</strong></label><template v-if="draft.fields.goals.goals.includes(goal.id)"><label class="bf-check bf-preference-priority"><input type="radio" name="bf-priority" :data-fact-priority="goal.id" :checked="draft.fields.goals.priority === goal.id" @change="setPriority(draft.fields.goals, goal.id)" />{{ t('أولويتي الحالية', 'My current priority') }}</label><div class="bf-preference-results"><p>{{ t('نتائج تهمك — اختياري', 'Outcomes of interest — optional') }}</p><label v-for="r in goal.results" :key="r.id" class="bf-check"><input type="checkbox" :data-fact-result="r.id" :checked="draft.fields.goals.results[goal.id]?.includes(r.id) ?? false" @change="toggleResult(draft.fields.goals, goal.id, r.id)" />{{ localized(r) }}</label></div></template></section></div>
                </template>
                <template v-else>
                    <label class="bf-check bf-intent-clear"><input v-model="draft.fields.clearing" type="checkbox" data-fact-intent-clear />{{ t('إزالة الاتجاه الحالي دون تعيين بديل', 'Clear the current direction without a replacement') }}</label>
                    <template v-if="!draft.fields.clearing">
                        <div class="bf-intent-essential"><div class="bf-fact-field"><label for="bf-intent-direction">{{ t('ما الاتجاه الذي تريده؟', 'Which direction do you want?') }}</label><select id="bf-intent-direction" v-model="draft.fields.direction" required><option value="">{{ t('اختر الاتجاه', 'Choose direction') }}</option><option v-for="d in directions" :key="d[0]" :value="d[0]">{{ d[1] }}</option></select></div><div class="bf-fact-field bf-intent-outcome"><label for="bf-intent-meaning">{{ t('ما النتيجة التي ستعدّها نجاحًا؟', 'Which outcome will count as success?') }}</label><textarea id="bf-intent-meaning" v-model="draft.fields.meaning" maxlength="2000" required :placeholder="t('صف نتيجة يمكن التمييز بين تحققها وعدم تحققها', 'Describe an outcome whose achievement can be recognized')" /></div></div>
                        <details class="bf-fact-disclosure" :open="!!draft.fields.description || !!draft.fields.clarification"><summary>{{ t('وصف أو توضيح إضافي — اختياري', 'Additional description or clarification — optional') }}</summary><p class="bf-fact-hint">{{ t('استخدم الوصف للسياق، والتوضيح لتعريف ما يدخل في النتيجة أو يُستبعد منها.', 'Use the description for context and the clarification to define what the outcome includes or excludes.') }}</p><div class="bf-intent-extra"><div class="bf-fact-field"><label for="bf-intent-description">{{ t('وصف السياق', 'Context description') }}</label><textarea id="bf-intent-description" v-model="draft.fields.description" maxlength="2000" /></div><div class="bf-fact-field"><label for="bf-intent-clarification">{{ t('توضيح معنى النتيجة', 'Outcome clarification') }}</label><textarea id="bf-intent-clarification" v-model="draft.fields.clarification" maxlength="2000" /></div></div></details>
                        <details class="bf-fact-disclosure" :open="!!draft.fields.quantity || !!draft.fields.countUnit || !!draft.fields.date || !!draft.fields.timezone"><summary>{{ t('كمية وموعد — اختياريان', 'Quantity and deadline — optional') }}</summary><div class="bf-intent-extra"><div class="bf-fact-field"><label for="bf-intent-quantity">{{ t('الكمية المطلوبة', 'Target quantity') }}</label><input id="bf-intent-quantity" v-model="draft.fields.quantity" inputmode="numeric" maxlength="120" pattern="[1-9][0-9]*" /></div><div class="bf-fact-field"><label for="bf-intent-unit">{{ t('وحدة العد', 'Count unit') }}</label><input id="bf-intent-unit" v-model="draft.fields.countUnit" maxlength="120" :placeholder="t('مثال: عقد منجز', 'Example: completed contract')" /></div><div class="bf-fact-field"><label for="bf-intent-date">{{ t('تاريخ نهاية اليوم المستهدف', 'Target end-of-day date') }}</label><input id="bf-intent-date" v-model="draft.fields.date" type="date" /></div><div class="bf-fact-field"><label for="bf-intent-timezone">{{ t('المنطقة الزمنية', 'Timezone') }}</label><select id="bf-intent-timezone" v-model="draft.fields.timezone"><option value="">{{ t('دون موعد', 'No deadline') }}</option><option v-for="zone in surface.catalog.intent_timezones" :key="zone" :value="zone">{{ zone }}</option><option v-if="draft.fields.timezone && !surface.catalog.intent_timezones.includes(draft.fields.timezone)" :value="draft.fields.timezone" disabled>{{ draft.fields.timezone }} — {{ t('محفوظ سابقًا', 'Previously retained') }}</option></select></div></div></details>
                    </template>
                </template>
            </fieldset>
            <fieldset v-if="active === 'intent'" class="bf-intent-confirmations" :disabled="busy || !!draft.pending || !canEdit(draft)">
                <legend>{{ t('تأكيد المعنى قبل الحفظ', 'Confirm the meaning before saving') }}</legend>
                <div class="bf-confirmation-grid"><label class="bf-check"><input v-model="draft.flags.declaration" type="checkbox" data-fact-confirm="declaration" />{{ draft.fields.clearing ? t('أؤكد إزالة الاتجاه الحالي دون استنتاج بديل.', 'I confirm clearing the current intent without inferring a replacement.') : t('هذا اتجاه أعلن اختياره، وليس نتيجة استنتجتها المنصة.', 'This is a direction I declare, not a result inferred by the platform.') }}</label>
                <template v-if="!draft.fields.clearing"><label class="bf-check"><input v-model="draft.flags.business_wide" type="checkbox" data-fact-confirm="business_wide" />{{ t('يشمل النشاط كله، وليس عرضًا أو فرعًا فقط.', 'It covers the whole business, not only an offer or branch.') }}</label><label class="bf-check"><input v-model="draft.flags.outcome" type="checkbox" data-fact-confirm="outcome" />{{ t('هذا هو معنى النتيجة التي أريدها.', 'This is the meaning of my desired outcome.') }}</label><label class="bf-check"><input v-model="draft.flags.acceptance_clear" type="checkbox" data-fact-confirm="acceptance_clear" />{{ t('أستطيع تحديد متى تتحقق هذه النتيجة ومتى لا تتحقق.', 'I can distinguish whether this outcome has been achieved.') }}</label><label v-if="draft.fields.clarification" class="bf-check"><input v-model="draft.flags.clarification_required" type="checkbox" data-fact-confirm="clarification_required" />{{ t('التوضيح المكتوب جزء من تعريف النتيجة.', 'The written clarification is part of the outcome definition.') }}</label><label v-if="draft.fields.quantity" class="bf-check"><input v-model="draft.flags.quantity" type="checkbox" data-fact-confirm="quantity" />{{ t('أؤكد الكمية ووحدة عدّها.', 'I confirm the quantity and count unit.') }}</label><label v-if="draft.fields.date || draft.fields.timezone" class="bf-check"><input v-model="draft.flags.horizon" type="checkbox" data-fact-confirm="horizon" />{{ t('أؤكد تاريخ نهاية اليوم ومنطقته الزمنية.', 'I confirm the end-of-day date and timezone.') }}</label></template></div>
            </fieldset>
            <div class="bf-fact-feedback"><p v-if="changed(draft) && !draft.pending && !refreshing" role="alert">{{ t('تغيرت البيانات المحفوظة منذ بدأت التعديل. راجعها قبل الحفظ.', 'Saved information changed after you started editing. Review it before saving.') }}<button type="button" @click="adopt(active)">{{ t('استبدال المسودة بالبيانات الحالية', 'Replace draft with current data') }}</button></p><p v-if="draft.pending && !busy" role="status" data-fact-unknown>{{ t('لم نتمكن من تأكيد الحفظ. التحقق يستخدم الطلب الأصلي دون إنشاء بديل.', 'Saving could not be confirmed. Checking uses the original request without creating a replacement.') }}</p><p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="draft.confirmed" role="status" data-fact-saved>{{ t('تم الحفظ.', 'Saved.') }}</p></div>
            <div class="bf-fact-actions"><button class="bf-primary" :disabled="busy || !canEdit(draft) || changed(draft) && !draft.pending || !dirty(draft) && !draft.pending">{{ busy ? t('جارٍ الحفظ…', 'Saving…') : draft.pending ? t('التحقق من النتيجة', 'Check the outcome') : t('حفظ التغييرات', 'Save changes') }}</button><span>{{ t('الحفظ يحدّث بيانات النشاط المعلنة؛ لا يشغّل إعلانًا.', 'Saving updates declared business information; it does not launch an ad.') }}</span></div>
        </form>
    </section>
</template>
<style scoped>
.bf-facts-compact{max-width:860px;margin:0;padding:0!important;border:0!important;background:transparent!important;box-shadow:none!important;font-size:14px}.bf-fact-navigation{display:flex;gap:5px;overflow:auto;border-block-end:1px solid var(--ad-line,#dce5e0);padding:0;margin-bottom:14px}.bf-fact-navigation button{flex:none;min-height:37px;padding:7px 12px 10px;border:0;border-block-end:2px solid transparent;background:transparent;font-size:12px;line-height:1.7;color:var(--ad-muted,#5c706b);cursor:pointer}.bf-fact-navigation button[aria-current=page]{color:var(--ad-accent,#155f53);border-block-end-color:var(--ad-accent,#155f53);font-weight:650}.bf-fact-intro,.bf-intent-explanation p{font-size:12px!important;line-height:1.85!important;margin:0 0 11px!important;color:var(--ad-muted,#5c706b)!important;max-width:82ch}.bf-intent-explanation{max-width:820px}.bf-intent-explanation .bf-fact-example{padding:9px 12px;border-inline-start:2px solid var(--ad-line,#dce5e0);font-size:12px!important;margin-bottom:14px!important;background:var(--ad-paper,#fff);border-radius:0 6px 6px 0}.bf-saved-fact{font-size:12px;line-height:1.8;padding:8px 12px;border:1px solid var(--ad-line,#dce5e0);border-radius:8px;background:var(--ad-paper,#fff);margin:0 0 16px!important;max-width:820px}.bf-saved-fact summary{cursor:pointer;min-height:28px;line-height:1.9;color:var(--ad-muted,#5c706b)}.bf-saved-fact summary>span{font-weight:400}.bf-saved-fact p{font-size:12px;margin:8px 0!important}.bf-facts-compact .bf-fact-form{max-width:820px;margin:0}.bf-fact-form fieldset{min-width:0;border:0;padding:0;margin:0}.bf-fact-form fieldset:disabled{opacity:.75}.bf-fact-form legend{font-size:12px;line-height:1.8;font-weight:650;padding:0;margin:0 0 9px}.bf-fact-field{min-width:0;display:flex;flex-direction:column;gap:5px}.bf-fact-form .bf-fact-field>label{display:block;font-size:12px;line-height:1.7;font-weight:600;margin:0!important}.bf-fact-form .bf-fact-field :is(input:not([type=checkbox]),select,textarea){margin:0!important;width:100%;min-width:0;min-height:38px;padding:7px 10px;border:1px solid var(--ad-line,#dce5e0);border-radius:7px;background:var(--ad-paper,#fff);color:var(--ad-ink,#182f39);font-size:13px;line-height:1.7}.bf-fact-form .bf-fact-field textarea{min-height:74px;resize:vertical}.bf-fact-form .bf-fact-authority{max-width:400px;margin-bottom:15px}.bf-market-selected{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0!important;padding:0!important;list-style:none!important}.bf-market-selected li{display:flex;align-items:center;gap:5px;max-width:100%;font-size:12px;background:var(--ad-soft,#edf4f0);border:1px solid var(--ad-line,#dce5e0);border-radius:6px;padding-inline-start:9px;color:var(--ad-accent,#155f53)}.bf-market-selected button{width:30px;min-height:30px;padding:0;font-size:18px;line-height:1;background:none;cursor:pointer}.bf-market-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));max-height:176px;overflow:auto;padding:6px;border:1px solid var(--ad-line,#dce5e0);border-radius:8px;background:var(--ad-paper,#fff);margin-top:11px}.bf-fact-form .bf-check{display:flex;align-items:flex-start;gap:8px;margin:0!important;padding:7px 8px;font-weight:400;font-size:12px;line-height:1.8;min-height:34px;border-radius:6px;cursor:pointer}.bf-fact-form .bf-check input{appearance:auto!important;accent-color:var(--ad-accent,#155f53);width:17px!important;height:17px!important;min-height:17px!important;flex:none;padding:0!important;margin:3px 0 0!important;background:revert;border:revert}.bf-market-options .bf-check{align-items:center;min-width:0}.bf-market-options .bf-check>span{flex:1}.bf-market-options .bf-check bdi{font-size:11px;color:var(--ad-muted,#5c706b)}.bf-market-options>p{font-size:12px;grid-column:1/-1;padding:8px}.bf-fact-form .bf-fact-hint{font-size:12px;color:var(--ad-muted,#5c706b);margin:9px 0 0;line-height:1.8}.bf-intent-essential{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:14px;align-items:start;margin-top:13px}.bf-intent-essential .bf-intent-outcome textarea{min-height:78px}.bf-fact-form .bf-intent-clear{border:1px solid var(--ad-line,#dce5e0);background:var(--ad-paper,#fff);padding:9px 11px;border-radius:7px;min-height:37px}.bf-fact-disclosure{margin:15px 0 0!important;padding:0;border:0;border-block-start:1px solid var(--ad-line,#dce5e0)}.bf-fact-disclosure summary{cursor:pointer;font-size:12px;min-height:37px;padding:9px 0;color:var(--ad-accent,#155f53)}.bf-intent-extra{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 14px;padding:11px 0 4px}.bf-fact-form .bf-intent-confirmations{padding:13px 14px;margin-top:16px;border:1px solid var(--ad-line,#dce5e0);border-radius:9px;background:var(--ad-paper,#fff)}.bf-intent-confirmations legend{padding:0 5px;margin:0;font-size:12px}.bf-confirmation-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2px 10px}.bf-confirmation-grid .bf-check{padding:5px 3px;font-size:12px;line-height:1.8}.bf-preference-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.bf-preference-card{min-width:0;border:1px solid var(--ad-line,#dce5e0);border-radius:10px;background:var(--ad-paper,#fff);padding:11px 12px}.bf-preference-card.selected{border-color:var(--ad-accent,#155f53)}.bf-preference-choice strong{font-size:13px;font-weight:650}.bf-preference-results{border-block-start:1px solid var(--ad-line,#dce5e0);padding-top:8px;margin-top:6px}.bf-preference-results>p{font-size:12px!important;line-height:1.7!important;margin:0 0 4px!important;color:var(--ad-muted,#5c706b)}.bf-preference-priority{color:var(--ad-accent,#155f53)}.bf-fact-feedback p{font-size:12px;line-height:1.8;margin:13px 0!important}.bf-fact-feedback [role=alert]{padding:10px 12px;border-radius:7px;background:var(--ad-soft,#edf4f0)}.bf-fact-feedback button{min-height:36px;font-size:12px}.bf-fact-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:17px;padding-top:14px;border-block-start:1px solid var(--ad-line,#dce5e0)}.bf-fact-actions>span{font-size:12px;color:var(--ad-muted,#5c706b)}.bf-fact-actions .bf-primary{min-height:37px;padding:7px 13px;font-size:12px;border-radius:7px}.bf-fact-readonly{font-size:12px;color:var(--ad-muted,#5c706b);margin:14px 0}.bf-fact-form :focus-visible,.bf-fact-navigation button:focus-visible,.bf-saved-fact summary:focus-visible{outline:3px solid var(--ba-logo-secondary,#334b73);outline-offset:3px}
@media(max-width:760px){.bf-fact-navigation{gap:0}.bf-fact-navigation button{min-height:44px;padding-inline:10px}.bf-saved-fact summary,.bf-fact-disclosure summary{min-height:44px}.bf-fact-form .bf-fact-field :is(input:not([type=checkbox]),select){min-height:44px}.bf-fact-form .bf-check{min-height:44px}.bf-market-selected button{min-width:44px;min-height:44px}.bf-fact-actions .bf-primary{min-height:44px}.bf-intent-essential{grid-template-columns:1fr}.bf-confirmation-grid{grid-template-columns:1fr}.bf-fact-actions{align-items:flex-start}.bf-fact-actions>span{flex-basis:100%}}
@media(max-width:480px){.bf-market-options,.bf-preference-grid,.bf-intent-extra{grid-template-columns:1fr}.bf-market-options{max-height:176px}.bf-preference-card{padding:10px}.bf-intent-confirmations{padding:10px!important}}
@media(prefers-reduced-motion:reduce){.bf-facts-compact *{scroll-behavior:auto!important;transition:none!important}}
</style>
