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
    <section class="bf-card bf-facts" data-business-facts>
        <h2>{{ t('توجيه النشاط', 'Business direction') }}</h2>
        <p>{{ t('تصريحاتك عن هذا النشاط؛ لا تُستنتج من مزود أو اشتراك، ولا تشغّل حملة إعلانية.', 'Your declarations about this business. They are not inferred from a provider or subscription and do not launch an advertising campaign.') }}</p>
        <nav class="bf-nav" :aria-label="t('حقائق النشاط', 'Business facts')"><button v-for="kind in kinds" :key="kind" type="button" :aria-pressed="active === kind" :disabled="busy || refreshing" @click="active = kind">{{ title(kind) }}</button></nav>
        <h3>{{ title(active) }}</h3>
        <p v-if="refreshing" role="status">{{ t('جارٍ إعادة قراءة التصريح المحفوظ والتحقق من الوصول…', 'Reading back the saved declaration and checking access…') }}</p>
        <div v-else data-business-fact-current>
            <p v-if="active === 'markets'">{{ surface.business.declared_markets.length ? surface.business.declared_markets.map(country).join('، ') : t('لم تُحدد أسواق بعد؛ هذا لا يعني غياب العملاء.', 'No markets have been declared; this does not mean there are no customers.') }}</p>
            <template v-else-if="active === 'intent'"><p v-if="!surface.business.current_intent">{{ t('لم تصرّح بنية حالية.', 'No current intent has been declared.') }}</p><template v-else><p>{{ directions.find(d => d[0] === surface.business.current_intent?.direction)?.[1] }} — {{ surface.business.current_intent.outcome.meaning }}</p><p>{{ surface.business.current_intent.description }} {{ surface.business.current_intent.outcome.clarification }}</p><p v-if="surface.business.current_intent.quantity">{{ surface.business.current_intent.quantity }} {{ surface.business.current_intent.outcome.count_unit }}</p><p v-if="surface.business.current_intent.horizon">{{ surface.business.current_intent.horizon.deadline_date }} · {{ surface.business.current_intent.horizon.timezone }}</p></template></template>
            <template v-else><p v-if="!surface.business.preferences">{{ t('لم تُحفظ تفضيلات بعد.', 'No preferences have been saved.') }}</p><ul v-else><li v-for="goal in surface.catalog.goals.filter(g => surface.business.preferences?.goals.includes(g.id))" :key="goal.id">{{ localized(goal) }}<strong v-if="surface.business.preferences.priority === goal.id"> — {{ t('الأولوية', 'Priority') }}</strong><ul><li v-for="result in goal.results.filter(r => surface.business.preferences?.results[goal.id]?.includes(r.id))" :key="result.id">{{ localized(result) }}</li></ul></li></ul></template>
        </div>
        <p v-if="!options(active).length || draft.blocked" role="status">{{ t('متاح للاطلاع؛ لا يوجد تفويض لهذا التعديل هنا.', 'Available to view; no delegation for this edit is available here.') }}</p>
        <form v-else class="bf-form" :data-business-fact-form="active" @submit.prevent="save">
            <label v-if="options(active).length > 1" :for="`bf-${active}-grant`">{{ t('تفويض التصريح', 'Declaration delegation') }}<select :id="`bf-${active}-grant`" v-model="draft.selected" :disabled="busy || !!draft.pending"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, index) in options(active)" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}</option></select></label>
            <fieldset :disabled="busy || !!draft.pending || !canEdit(draft)">
                <template v-if="active === 'markets'"><legend>{{ t('في أي دول تريد خدمة العملاء؟', 'In which countries do you want to serve customers?') }}</legend><label for="bf-market-codes">{{ t('الدول المستهدفة', 'Target countries') }}</label><select id="bf-market-codes" v-model="draft.fields.codes" multiple size="8"><option v-for="c in surface.catalog.countries" :key="c.code" :value="c.code">{{ localized(c) }}</option></select><p>{{ t('يمكن اختيار أكثر من دولة. التفريغ تصريح بعدم تحديد أسواق حاليًا، وليس قياسًا لحجم السوق.', 'You may select several countries. Clearing means no currently declared markets, not a measurement of market size.') }}</p></template>
                <template v-else-if="active === 'preferences'"><legend>{{ t('ما النتائج التي تهمك؟', 'Which outcomes matter to you?') }}</legend><div v-for="goal in surface.catalog.goals" :key="goal.id" class="bf-goal"><label class="bf-check"><input type="checkbox" :checked="draft.fields.goals.goals.includes(goal.id)" @change="toggleGoal(draft.fields.goals, goal.id)" />{{ localized(goal) }}</label><template v-if="draft.fields.goals.goals.includes(goal.id)"><label class="bf-check"><input type="radio" name="bf-priority" :checked="draft.fields.goals.priority === goal.id" @change="setPriority(draft.fields.goals, goal.id)" />{{ t('الأولوية الحالية', 'Current priority') }}</label><label v-for="r in goal.results" :key="r.id" class="bf-check"><input type="checkbox" :checked="draft.fields.goals.results[goal.id]?.includes(r.id) ?? false" @change="toggleResult(draft.fields.goals, goal.id, r.id)" />{{ localized(r) }}</label></template></div></template>
                <template v-else>
                    <label class="bf-check"><input v-model="draft.fields.clearing" type="checkbox" />{{ t('إزالة النية الحالية بتصريح صريح', 'Explicitly clear the current intent') }}</label>
                    <template v-if="!draft.fields.clearing">
                        <label for="bf-intent-direction">{{ t('الاتجاه التجاري', 'Business direction') }}</label><select id="bf-intent-direction" v-model="draft.fields.direction" required><option value="">{{ t('اختر الاتجاه', 'Choose direction') }}</option><option v-for="d in directions" :key="d[0]" :value="d[0]">{{ d[1] }}</option></select>
                        <label for="bf-intent-meaning">{{ t('ما النتيجة التي تريد تحقيقها؟', 'What outcome do you want to achieve?') }}</label><textarea id="bf-intent-meaning" v-model="draft.fields.meaning" maxlength="2000" required />
                        <label for="bf-intent-description">{{ t('وصف إضافي اختياري', 'Optional description') }}</label><textarea id="bf-intent-description" v-model="draft.fields.description" maxlength="2000" />
                        <label for="bf-intent-clarification">{{ t('توضيح معنى النتيجة، إن لزم', 'Clarify the outcome when needed') }}</label><textarea id="bf-intent-clarification" v-model="draft.fields.clarification" maxlength="2000" />
                        <details><summary>{{ t('كمية وموعد اختياريان', 'Optional quantity and deadline') }}</summary><label for="bf-intent-quantity">{{ t('الكمية المطلوبة', 'Target quantity') }}</label><input id="bf-intent-quantity" v-model="draft.fields.quantity" inputmode="numeric" maxlength="120" pattern="[1-9][0-9]*" /><label for="bf-intent-unit">{{ t('وحدة العد', 'Count unit') }}</label><input id="bf-intent-unit" v-model="draft.fields.countUnit" maxlength="120" /><label for="bf-intent-date">{{ t('تاريخ نهاية اليوم المستهدف', 'Target end-of-day date') }}</label><input id="bf-intent-date" v-model="draft.fields.date" type="date" /><label for="bf-intent-timezone">{{ t('المنطقة الزمنية', 'Timezone') }}</label><select id="bf-intent-timezone" v-model="draft.fields.timezone"><option value="">{{ t('دون موعد', 'No deadline') }}</option><option v-for="zone in surface.catalog.intent_timezones" :key="zone" :value="zone">{{ zone }}</option><option v-if="draft.fields.timezone && !surface.catalog.intent_timezones.includes(draft.fields.timezone)" :value="draft.fields.timezone" disabled>{{ draft.fields.timezone }} — {{ t('محفوظ سابقًا', 'Previously retained') }}</option></select></details>
                    </template>
                </template>
            </fieldset>
            <fieldset v-if="active === 'intent'" :disabled="busy || !!draft.pending || !canEdit(draft)">
                <legend>{{ t('راجع تصريحك', 'Review your declaration') }}</legend>
                <label class="bf-check"><input v-model="draft.flags.declaration" type="checkbox" />{{ draft.fields.clearing ? t('أؤكد إزالة النية الحالية دون استنتاج بديل.', 'I confirm clearing the current intent without inferring a replacement.') : t('هذا تصريح مني عن النية الحالية، وليس حقيقة مستنتجة.', 'This is my declaration of current intent, not an inferred fact.') }}</label>
                <template v-if="!draft.fields.clearing"><label class="bf-check"><input v-model="draft.flags.business_wide" type="checkbox" />{{ t('النية تشمل النشاط كله، لا عرضًا أو فرعًا بعينه.', 'This intent covers the whole business, not a particular offer or branch.') }}</label><label class="bf-check"><input v-model="draft.flags.outcome" type="checkbox" />{{ t('أؤكد معنى النتيجة الموصوفة.', 'I confirm the described outcome.') }}</label><label class="bf-check"><input v-model="draft.flags.acceptance_clear" type="checkbox" />{{ t('أستطيع التمييز بين تحقق هذه النتيجة وعدم تحققها.', 'I can distinguish whether this outcome has been achieved.') }}</label><label v-if="draft.fields.clarification" class="bf-check"><input v-model="draft.flags.clarification_required" type="checkbox" />{{ t('التوضيح جزء من معنى النتيجة.', 'The clarification is part of the outcome meaning.') }}</label><label v-if="draft.fields.quantity" class="bf-check"><input v-model="draft.flags.quantity" type="checkbox" />{{ t('أؤكد الكمية ووحدة عدّها.', 'I confirm the quantity and count unit.') }}</label><label v-if="draft.fields.date || draft.fields.timezone" class="bf-check"><input v-model="draft.flags.horizon" type="checkbox" />{{ t('أؤكد تاريخ نهاية اليوم ومنطقته الزمنية.', 'I confirm the end-of-day date and timezone.') }}</label></template>
            </fieldset>
            <p v-if="changed(draft) && !draft.pending && !refreshing" role="alert">{{ t('تغيّر الإصدار منذ بدأت هذه المسودة. راجع البيانات الحالية قبل التصريح.', 'The version changed after this draft began. Review current data before declaring.') }}<button type="button" @click="adopt(active)">{{ t('استبدال المسودة بالبيانات الحالية', 'Replace draft with current data') }}</button></p>
            <p v-if="draft.pending && !busy" role="status" data-fact-unknown>{{ t('الطلب الأصلي محفوظ ونتيجته لم تُحسم بعد؛ لا يُعاد تلقائيًا.', 'The original command is retained and its outcome is unconfirmed; it is never retried automatically.') }}</p>
            <p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="draft.confirmed" role="status" data-fact-saved>{{ t('تم حفظ التصريح بتأكيد من الخادم.', 'Declaration saved with server acknowledgement.') }}</p>
            <button class="bf-primary" :disabled="busy || !canEdit(draft) || changed(draft) && !draft.pending || !dirty(draft) && !draft.pending">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Reconcile original command') : t('حفظ التصريح', 'Save declaration') }}</button>
        </form>
    </section>
</template>
