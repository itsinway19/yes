<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useBusinessCopy } from '@/localization/business';
type Intent = { direction: string; scope: 'business_wide'; description: string | null; outcome: { meaning: string; clarification: string | null; count_unit: string | null }; quantity: string | null; horizon: { deadline_date: string; timezone: string } | null };
const props = defineProps<{ workspaceId: string; currentIntent: { state: string; value: Intent | null; version: number }; canManage: boolean; timezoneOptions: string[] }>();
const { t } = useBusinessCopy();
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v));
const fields = (v: Intent | null) => ({ direction: v?.direction ?? '', description: v?.description ?? '', meaning: v?.outcome.meaning ?? '', clarification: v?.outcome.clarification ?? '', count_unit: v?.outcome.count_unit ?? '', quantity: v?.quantity ?? '', deadline_date: v?.horizon?.deadline_date ?? '', timezone: v?.horizon?.timezone ?? '', needsClarification: v?.outcome.clarification != null });
const baseline = ref(clone(props.currentIntent));
const draft = reactive(fields(baseline.value.value));
const flags = reactive({ declaration: false, business_wide: false, outcome: false, acceptance_clear: false, clarification_required: false, quantity: false, horizon: false });
const editing = ref(false), clearing = ref(false), processing = ref(false), changed = ref(false), reviewed = ref(false);
const errors = ref<Record<string,string>>({});
const signature = () => JSON.stringify(draft);
const dirty = computed(() => signature() !== JSON.stringify(fields(baseline.value.value)));
const resetConfirmations = () => { for (const key of Object.keys(flags) as (keyof typeof flags)[]) flags[key] = false; };
watch(signature, () => { resetConfirmations(); errors.value = {}; });
function adoptCanonical() {
 baseline.value = clone(props.currentIntent); Object.assign(draft, fields(baseline.value.value)); changed.value = false; reviewed.value = false;
}
watch(() => props.currentIntent, () => {
 if (processing.value || dirty.value || clearing.value) { changed.value = true; return; }
 adoptCanonical();
}, { deep: true });
function edit() { editing.value = true; clearing.value = false; }
function cancel() { Object.assign(draft, fields(props.currentIntent.value)); baseline.value = clone(props.currentIntent); resetConfirmations(); editing.value = clearing.value = changed.value = reviewed.value = false; errors.value = {}; }
function review() { router.reload({ onSuccess: () => { reviewed.value = true; changed.value = true; } }); }
function adoptVersion() { baseline.value = clone(props.currentIntent); resetConfirmations(); changed.value = false; reviewed.value = false; errors.value = {}; }
const optional = (s: string) => s.replace(/^[\t\n\r ]+|[\t\n\r ]+$/g, '') || null;
const value = computed<Intent>(() => ({ direction: draft.direction, scope: 'business_wide', description: optional(draft.description), outcome: { meaning: optional(draft.meaning) ?? '', clarification: draft.needsClarification ? optional(draft.clarification) : null, count_unit: optional(draft.count_unit) }, quantity: draft.quantity ? draft.quantity.replace(/^0+/, '') : null, horizon: draft.deadline_date || draft.timezone ? { deadline_date: draft.deadline_date, timezone: draft.timezone } : null }));
const count = (s: string) => Array.from(s).length;
const same = (a: unknown,b: unknown) => JSON.stringify(a, Object.keys(a ?? {}).sort()) === JSON.stringify(b, Object.keys(b ?? {}).sort());
const outcomeChanged = computed(() => !same(value.value.outcome, baseline.value.value?.outcome));
const quantityChanged = computed(() => value.value.quantity !== baseline.value.value?.quantity || outcomeChanged.value);
const horizonChanged = computed(() => !same(value.value.horizon, baseline.value.value?.horizon));
const retainedZone = computed(() => !!draft.timezone && !props.timezoneOptions.includes(draft.timezone) && !horizonChanged.value);
function validation(): string | null {
 for (const [key,limit] of [['description',2000],['meaning',2000],['clarification',2000],['count_unit',120]] as const) if(count(draft[key])>limit) return t('intentTooLong');
 if(!['increase_value','preserve_value','improve_efficiency_preserving_value','validate_business_direction'].includes(draft.direction) || !value.value.outcome.meaning || !flags.business_wide || !flags.declaration) return t('intentConfirmRequired');
 if(draft.needsClarification && !value.value.outcome.clarification) return t('intentConfirmRequired');
 if(outcomeChanged.value && !flags.outcome) return t('intentConfirmRequired');
 if(draft.quantity && (!/^[0-9]{1,120}$/.test(draft.quantity) || !value.value.quantity || !value.value.outcome.count_unit || (quantityChanged.value && !flags.quantity))) return t('intentQuantityError');
 if(value.value.horizon && horizonChanged.value) {
 const date=draft.deadline_date; const [y,m,d]=date.split('-').map(Number); const leap=y%4===0 && (y%100!==0 || y%400===0); const days=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31];
 if(!/^\d{4}-\d{2}-\d{2}$/.test(date) || y<1 || y>9999 || m<1 || m>12 || d<1 || d>days[m-1] || !props.timezoneOptions.includes(draft.timezone) || !flags.horizon) return t('intentHorizonError');
 }
 return null;
}
function save() {
 if(processing.value || !props.canManage) return;
 const problem=clearing.value ? (!flags.declaration ? t('intentConfirmRequired') : null) : validation();
 if(problem) { errors.value={value:problem}; return; }
 const confirmations=clearing.value ? {declaration:true} : { ...flags, acceptance_clear: flags.outcome, clarification_required: draft.needsClarification };
 const command={command:clearing.value?'clear':'replace',value:clearing.value?null:value.value,confirmations,version:baseline.value.version};
 if(new TextEncoder().encode(JSON.stringify(command)).length>81920) { errors.value={value:t('intentTooLong')}; return; }
 const submitted=signature(); const wasClear=clearing.value; let succeeded=false;
 processing.value=true; errors.value={};
 router.post(route('workspaces.business.current-intent.update',props.workspaceId),clone(command),{
 preserveState:true,preserveScroll:true,
 onSuccess:()=> { succeeded=true; if(signature()===submitted && clearing.value===wasClear) { adoptCanonical(); editing.value=clearing.value=false; resetConfirmations(); } else changed.value=true; },
 onHttpException:response=> { if(response.status===413) { errors.value={value:t('intentTooLong')}; return false; } },
 onError:e=> { errors.value=e; if(e.version) changed.value=true; },
 onFinish:()=> { processing.value=false; if(!succeeded && !Object.keys(errors.value).length) errors.value={value:t('intentTransportError')}; },
 });
}
const directions = ['increase_value','preserve_value','improve_efficiency_preserving_value','validate_business_direction'] as const;
const directionLabel = (direction: string) => ({increase_value:t('intentIncrease'),preserve_value:t('intentPreserve'),improve_efficiency_preserving_value:t('intentEfficiency'),validate_business_direction:t('intentValidate')}[direction] ?? direction);
</script>
<template>
 <section data-current-intent class="current-intent biz-divider min-w-0 mt-10" style="overflow-wrap:anywhere">
  <h2>{{ t('intentTitle') }}</h2><p class="biz-lead">{{ t('intentIntro') }}</p>
  <div data-intent="current" aria-live="polite">
   <p v-if="!currentIntent.value">{{ t('intentAbsent') }}</p>
   <dl v-else><dt>{{ t('intentDirection') }}</dt><dd>{{ directionLabel(currentIntent.value.direction) }}</dd><dt>{{ t('intentScope') }}</dt><dd>{{ t('intentBusinessWide') }}</dd><dt>{{ t('intentOutcome') }}</dt><dd>{{ currentIntent.value.outcome.meaning }}</dd><dd v-if="currentIntent.value.outcome.clarification">{{ currentIntent.value.outcome.clarification }}</dd><dd v-if="currentIntent.value.description">{{ currentIntent.value.description }}</dd><dd v-if="currentIntent.value.quantity">{{ currentIntent.value.quantity }} {{ currentIntent.value.outcome.count_unit }}</dd><dd v-else-if="currentIntent.value.outcome.count_unit">{{ currentIntent.value.outcome.count_unit }}</dd><dd v-if="currentIntent.value.horizon">{{ currentIntent.value.horizon.deadline_date }} · {{ currentIntent.value.horizon.timezone }} — {{ t('intentFullDay') }}</dd></dl>
  </div>
  <template v-if="canManage">
   <div v-if="!editing && !clearing" class="flex flex-wrap gap-3"><button type="button" data-intent="edit" class="biz-button" @click="edit">{{ t('intentEdit') }}</button><button v-if="currentIntent.value" type="button" data-intent="clear" class="biz-link" @click="clearing=true; resetConfirmations()">{{ t('intentClear') }}</button></div>
   <form v-else data-intent="form" class="grid gap-4 min-w-0" @submit.prevent="save">
    <div v-if="changed || errors.version" role="alert"><p>{{ t('intentConflict') }}</p><button type="button" data-intent="review" class="biz-link" :disabled="processing" @click="review">{{ t('intentReview') }}</button><button v-if="reviewed" type="button" data-intent="adopt" class="biz-link" :disabled="processing" @click="adoptVersion">{{ t('intentAdopt') }}</button></div>
    <p v-if="clearing">{{ t('intentClearHistory') }}</p>
    <template v-else>
     <label>{{ t('intentDirection') }}<select v-model="draft.direction" data-intent="direction"><option value="" disabled>{{ t('intentChoose') }}</option><option v-for="direction in directions" :key="direction" :value="direction">{{ directionLabel(direction) }}</option></select></label>
     <label><input v-model="flags.business_wide" data-intent="scope" type="checkbox" /> {{ t('intentScopeConfirm') }}</label><p>{{ t('intentNarrowScope') }}</p>
     <label>{{ t('intentDescription') }}<textarea v-model="draft.description" data-intent="description" /><span>{{ count(draft.description) }} / 2000</span></label>
     <label>{{ t('intentOutcome') }}<textarea v-model="draft.meaning" data-intent="meaning" /><span>{{ count(draft.meaning) }} / 2000</span></label>
     <label><input v-model="draft.needsClarification" data-intent="needsClarification" type="checkbox" /> {{ t('intentNeedsClarification') }}</label>
     <label v-if="draft.needsClarification">{{ t('intentClarification') }}<textarea v-model="draft.clarification" data-intent="clarification" /><span>{{ count(draft.clarification) }} / 2000</span></label>
     <label v-if="outcomeChanged"><input v-model="flags.outcome" data-intent="outcome" type="checkbox" /> {{ t('intentOutcomeConfirm') }}</label>
     <details><summary>{{ t('intentOptionalCount') }}</summary><label>{{ t('intentUnit') }}<input v-model="draft.count_unit" data-intent="count_unit" /><span>{{ count(draft.count_unit) }} / 120</span></label><label>{{ t('intentQuantity') }}<input v-model="draft.quantity" data-intent="quantity" type="text" inputmode="numeric" /><span>{{ count(draft.quantity) }} / 120</span></label><label v-if="draft.quantity && quantityChanged"><input v-model="flags.quantity" data-intent="quantityConfirm" type="checkbox" /> {{ t('intentQuantityConfirm') }}</label></details>
     <details><summary>{{ t('intentOptionalHorizon') }}</summary><label>{{ t('intentDate') }}<input v-model="draft.deadline_date" data-intent="deadline_date" type="text" placeholder="YYYY-MM-DD" inputmode="numeric" /></label><label>{{ t('intentTimezone') }}<select v-model="draft.timezone" data-intent="timezone"><option value="">{{ t('intentChoose') }}</option><option v-for="zone in timezoneOptions" :key="zone" :value="zone">{{ zone }}</option><option v-if="retainedZone" :value="draft.timezone" disabled>{{ draft.timezone }} — {{ t('unavailable') }}</option></select></label><p v-if="retainedZone">{{ t('intentRetainedZone') }} {{ draft.deadline_date }} · {{ draft.timezone }}</p><p>{{ t('intentFullDay') }}</p><button v-if="draft.deadline_date || draft.timezone" type="button" data-intent="removeHorizon" class="biz-link" @click="draft.deadline_date=''; draft.timezone=''">{{ t('intentRemoveHorizon') }}</button><label v-if="value.horizon && horizonChanged"><input v-model="flags.horizon" data-intent="horizonConfirm" type="checkbox" /> {{ t('intentHorizonConfirm') }}</label></details>
     <div data-intent="summary" aria-live="polite"><h3>{{ t('intentFinalSummary') }}</h3><p>{{ directionLabel(value.direction) }} · {{ t('intentBusinessWide') }}</p><p>{{ value.description }}</p><p>{{ value.outcome.meaning }} {{ value.outcome.clarification }}</p><p>{{ value.quantity ?? '—' }} {{ value.outcome.count_unit }}</p><p>{{ value.horizon ? `${value.horizon.deadline_date} · ${value.horizon.timezone}` : t('intentNoHorizon') }}</p><p v-if="baseline.value?.quantity && !value.quantity">{{ t('intentRemoveCount') }}</p></div>
    </template>
    <label><input v-model="flags.declaration" data-intent="declaration" type="checkbox" /> {{ clearing ? t('intentClearConfirm') : t('intentDeclarationConfirm') }}</label>
    <p v-for="(message,key) in errors" :key="key" role="alert" class="biz-error">{{ message }}</p>
    <div class="flex flex-wrap gap-3"><button data-intent="save" class="biz-button" :disabled="processing">{{ processing ? t('saving') : t('save') }}</button><button type="button" data-intent="cancel" class="biz-link" :disabled="processing" @click="cancel">{{ t('intentCancel') }}</button></div>
   </form>
  </template>
 </section>
</template>
<style scoped>
.current-intent{max-width:700px}.current-intent form{margin-top:22px}.current-intent label{display:grid;gap:8px;font-size:14px;font-weight:600}.current-intent label:has(input[type=checkbox]){display:flex;align-items:flex-start;gap:10px}.current-intent input:not([type=checkbox]),.current-intent select,.current-intent textarea{width:100%;min-width:0;max-width:100%;background:white;border:1px solid #9caeaa;border-radius:5px;padding:12px;font-size:15px;color:var(--biz-ink)}.current-intent textarea{min-height:96px;resize:vertical}.current-intent input[type=checkbox]{flex-shrink:0;margin-top:4px}.current-intent label span{font-size:12px;color:var(--biz-muted);font-weight:400}.current-intent dl{display:grid;gap:6px;margin:18px 0}.current-intent dt{font-weight:600;margin-top:8px}.current-intent details{border-top:1px solid var(--biz-line);padding-top:16px}.current-intent details label{margin-top:14px}.current-intent [data-intent=summary]{background:#f0f5f1;border:1px solid var(--biz-line);padding:16px;border-radius:5px}.current-intent button{max-width:100%;white-space:normal}.current-intent input:focus-visible,.current-intent select:focus-visible,.current-intent textarea:focus-visible{outline:2px solid var(--biz-accent);outline-offset:2px}
</style>
