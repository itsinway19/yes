<script setup lang="ts">
import { useRoute } from 'vue-router';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useBusinessCopy } from '@/localization/businessCopy';
import { locale } from '@/spa/locale';
import { forget, remember, restore, unresolved, submitPurpose, retryPurpose, refreshPurpose } from '@/object-purpose/commands';
import type { PurposeWriter } from '@/object-purpose/commands';
import type { PurposeAcknowledgement, PurposeCommand } from '@/object-purpose/api';
import type { PurposeItem, PurposeObject, PurposeSnapshot, PurposeIntent } from '@/types/object-purpose';
const props = defineProps<{ workspaceId: string; businessMode?: boolean; historyOnly?: boolean; commandWriter?: PurposeWriter; refreshResource?: () => Promise<void>; purpose: PurposeItem | null; contextVersion: number; dtmTransitionVersion: number; currentIntent: { value: PurposeIntent | null; version: number } | null }>();
const { t } = useBusinessCopy(locale);
const emit = defineEmits<{ saved: [reply: PurposeAcknowledgement] }>();
const route = useRoute();
const workspaceKey = props.workspaceId, purposeKey = props.purpose?.purpose_id ?? null;
const pending = computed(() => unresolved(workspaceKey, purposeKey));
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v));
const object = (): PurposeObject => ({ provider: props.businessMode ? '' : 'meta', account_namespace: props.businessMode ? '' : 'meta_ads_account', account_id: '', object_kind: props.businessMode ? '' : 'campaign', object_id: '', incarnation: null });
const last = () => props.purpose?.history.at(-1);
const baseline = ref(clone(props.purpose));
let saved = last()?.snapshot;
// Preserve the instant and PostgreSQL microseconds when reusing a retained boundary.
const retainedInstant = (value: string | null | undefined) => (value ?? '').replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
const intentReference = ref(saved?.intent ? clone(saved.intent) : (props.currentIntent?.value ? { version: props.contextVersion, value: clone(props.currentIntent.value), statement: '' } : null));
const draft = reactive({ meaning: saved?.meaning ?? '', qualifications: saved?.qualifications ?? '', phase: saved?.phase ?? '',
    included: clone(saved?.included_objects ?? [object()]), excluded: clone(saved?.excluded_objects ?? []),
    speaker: saved?.provenance.speaker ?? '', statement_at: saved?.provenance.statement_at ?? '', source: saved?.provenance.source ?? '',
    identity_basis: saved?.provenance.identity_basis ?? '', authority_basis: saved?.provenance.authority_basis ?? '', historical_support: saved?.provenance.historical_support ?? '', effective_basis: saved?.provenance.effective_basis ?? '',
    from: '', until: retainedInstant(last()?.effective_until), condition: saved?.end_condition ?? '', limitations: saved?.limitations ?? '', conflicts: saved?.conflicts.join('\n') ?? '',
    dtmMode: saved?.dtm?.mode ?? 'none', dtmVersion: saved?.dtm?.version ?? props.contextVersion,
    countries: saved?.dtm?.countries.join(', ') ?? '', subset: saved?.dtm?.subset.join(', ') ?? '', dtmStatement: saved?.dtm?.statement ?? '',
    intentEnabled: saved?.intent !== null && saved?.intent !== undefined, intentStatement: saved?.intent?.statement ?? '', operation: props.historyOnly ? 'correct' : 'replace', reason: '' });
const flags = reactive({ meaning: false, scope: false, authority: false, identity: false, standing: false, dtm: false, intent: false, historical: false, transition: false });
type Flag = keyof typeof flags;
const processing = ref(false), settling = ref(false), changed = ref(false), reviewed = ref(false), errors = ref<Record<string,string>>({});
let commandId: string | null = null;
let savedSuccessfully = false, initialSignature = JSON.stringify(draft);
const reset = () => { for (const key of Object.keys(flags) as Flag[]) flags[key] = false; };
type Draft = { baseline: PurposeItem | null; draft: typeof draft; flags: typeof flags; intentReference: typeof intentReference.value; commandId: string | null; changed: boolean; reviewed: boolean; initialSignature: string; contextVersion: number; dtmTransitionVersion: number; dirty: boolean };
const retained = restore<Draft>(workspaceKey, purposeKey);
if (retained?.dirty) {
    baseline.value = retained.baseline; saved = retained.baseline?.history.at(-1)?.snapshot;
    Object.assign(draft, retained.draft); Object.assign(flags, retained.flags); intentReference.value = retained.intentReference;
    commandId = retained.commandId; changed.value = retained.changed; reviewed.value = retained.reviewed; initialSignature = retained.initialSignature;
    if ((props.purpose?.head_version ?? 0) !== (baseline.value?.head_version ?? 0) || (draft.intentEnabled && retained.contextVersion !== props.contextVersion) || (draft.dtmMode.startsWith('current_') && retained.dtmTransitionVersion !== props.dtmTransitionVersion)) { changed.value = true; reviewed.value = false; reset(); }
}
const dirty = computed(() => JSON.stringify(draft) !== initialSignature || Object.values(flags).some(Boolean));
watch(() => JSON.stringify(draft), () => { reset(); commandId = null; errors.value = {}; savedSuccessfully = false; });
watch(() => props.purpose?.head_version, () => { changed.value = true; reviewed.value = false; reset(); });
watch(() => props.contextVersion, () => {
    if (draft.intentEnabled) { changed.value = true; reviewed.value = false; reset(); }
});
watch(() => props.dtmTransitionVersion, () => {
    if (draft.dtmMode.startsWith('current_')) { changed.value = true; reviewed.value = false; reset(); }
});
const historical = computed(() => (!withdrawing.value && draft.dtmMode === 'historical_attestation') || (!!draft.from && Date.parse(draft.from) < Date.now()));
const withdrawing = computed(() => baseline.value !== null && draft.operation === 'withdraw');
const required = computed<Flag[]>(() => ['meaning', 'scope', 'authority', 'identity', 'standing',
    ...(!withdrawing.value && draft.dtmMode !== 'none' ? ['dtm' as const] : []),
    ...(!withdrawing.value && draft.intentEnabled ? ['intent' as const] : []),
    ...(historical.value ? ['historical' as const] : []), ...(baseline.value ? ['transition' as const] : [])]);
const flagLabels = computed<Record<Flag,string>>(() => ({ meaning: t('purposeConfirmMeaning'), scope: t('purposeConfirmScope'),
    authority: props.businessMode ? (locale.value === 'ar' ? 'أؤكد سلطة تسجيل تصريح النشاط ضمن الحق المحدد؛ لا أدعي بهذا التأكيد ملكية عنصر خارجي أو إذنًا للتصرف فيه.' : 'I confirm authority to record this Business statement within the selected right; this does not assert external object ownership or permission to act on it.') : t('purposeConfirmAuthority'),
    identity: t('purposeConfirmIdentity'), standing: t('purposeConfirmStanding'), dtm: t('purposeConfirmDtm'), intent: t('purposeConfirmIntent'), historical: t('purposeConfirmHistorical'), transition: t('purposeConfirmTransition') }));
const nullable = (v: string) => v.trim() === '' ? null : v;
const codes = (v: string) => v.split(',').map(code => code.trim()).filter(Boolean);
async function review() { if (props.refreshResource) { await props.refreshResource(); reviewed.value = true; changed.value = true; } else void refreshPurpose(route, { onSuccess: () => { reviewed.value = true; changed.value = true; } }); }
function adopt() {
    baseline.value = clone(props.purpose);
    changed.value = reviewed.value = false; reset(); commandId = null; errors.value = {};
}
function useCurrentIntentReference() {
    if (!props.currentIntent?.value) return;
    intentReference.value = { version: props.contextVersion, value: clone(props.currentIntent.value), statement: '' };
    reset(); commandId = null;
}
function save() {
    if (props.historyOnly && (!baseline.value || !['correct', 'withdraw'].includes(draft.operation))) return;
    if (processing.value || settling.value || pending.value || changed.value || !required.value.every(key => flags[key])) return;
    if (!withdrawing.value && (!draft.meaning.trim() || !draft.source.trim() || !draft.identity_basis.trim() || !draft.authority_basis.trim() || !draft.effective_basis.trim())) return;
    if (historical.value && !(withdrawing.value ? draft.reason : draft.historical_support).trim()) return;
    if (baseline.value && draft.operation !== 'replace' && !draft.reason.trim()) return;
    commandId ??= crypto.randomUUID();
    const declaration: PurposeSnapshot | null = withdrawing.value ? null : {
        meaning: draft.meaning, qualifications: nullable(draft.qualifications), phase: nullable(draft.phase),
        included_objects: draft.included.map(row => ({...row, incarnation: row.incarnation || null})), excluded_objects: draft.excluded.map(row => ({...row, incarnation: row.incarnation || null})),
        provenance: { speaker: nullable(draft.speaker), statement_at: nullable(draft.statement_at), source: draft.source, identity_basis: draft.identity_basis, authority_basis: draft.authority_basis, historical_support: nullable(draft.historical_support), effective_basis: draft.effective_basis },
        dtm: draft.dtmMode === 'none' ? null : { mode: draft.dtmMode, version: Number(draft.dtmVersion), countries: codes(draft.countries), subset: codes(draft.subset), statement: draft.dtmStatement },
        intent: draft.intentEnabled && intentReference.value ? { version: intentReference.value.version, value: clone(intentReference.value.value), statement: draft.intentStatement } : null,
        end_condition: nullable(draft.condition), limitations: nullable(draft.limitations),
        conflicts: saved && draft.conflicts === saved.conflicts.join('\n') ? clone(saved.conflicts) : (draft.conflicts.trim() ? [draft.conflicts] : []) };
    const command = { command_id: commandId, purpose_id: baseline.value?.purpose_id ?? null, expected_version: baseline.value?.head_version ?? 0,
        operation: baseline.value ? draft.operation : 'declare', declaration, effective_from: nullable(draft.from), effective_until: withdrawing.value ? null : nullable(draft.until),
        supersedes_revision_id: baseline.value?.history.at(-1)?.revision_id ?? null, affected_revision_ids: baseline.value?.history.map(revision => revision.revision_id) ?? [],
        correction_reason: draft.operation === 'correct' && baseline.value ? nullable(draft.reason) : null,
        withdrawal_reason: withdrawing.value ? nullable(draft.reason) : null, confirmations: clone(flags) };
    processing.value = true;
    void submitPurpose(props.workspaceId, clone(command) as PurposeCommand, callbacks(), props.commandWriter);
}
function callbacks() {
    return { onError: (value: Record<string, string>) => { errors.value = value; reset(); },
        onHttpException: (response: { status: number }) => { if ([403,409,422].includes(response.status)) { changed.value = true; reviewed.value = false; reset(); return false; } },
        onNetworkError: () => { errors.value = { declaration: t('purposeStale') }; return false; },
        onSuccess: (reply: PurposeAcknowledgement) => { reset(); commandId = null; savedSuccessfully = true; settling.value = true; forget(workspaceKey, purposeKey); emit('saved', reply); }, onFinish: () => { processing.value = false; },
    };
}
function retry() { if (!pending.value || pending.value.busy) return; processing.value = true; void retryPurpose(workspaceKey, purposeKey, callbacks()); }
defineExpose({ hasUnsaved: () => !savedSuccessfully && (dirty.value || !!pending.value), pending: () => processing.value || !!pending.value?.busy });
onBeforeUnmount(() => { if (!savedSuccessfully) remember(workspaceKey, purposeKey, { baseline: baseline.value, draft, flags, intentReference: intentReference.value, commandId, changed: changed.value, reviewed: reviewed.value, initialSignature, contextVersion: props.contextVersion, dtmTransitionVersion: props.dtmTransitionVersion, dirty: dirty.value || !!pending.value }); });
</script>
<template>
<form data-purpose="form" :data-purpose-version="baseline?.head_version ?? 0" class="biz-form grid gap-4 mt-8" @submit.prevent="save">
    <aside v-if="pending?.uncertain" role="alert"><p>{{ t('purposeUnknownDelivery') }}</p><button type="button" data-purpose="retry-original" class="biz-link" :disabled="pending.busy || processing" @click="retry">{{ t('purposeRetryOriginal') }}</button></aside>
    <fieldset class="purpose-fields grid gap-4" :disabled="processing || settling || !!pending"><legend class="sr-only">{{ t('purposeTitle') }}</legend>
    <p class="biz-lead">{{ t('purposeIntro') }}</p>
    <div v-if="changed" role="alert"><p>{{ t('purposeStale') }}</p><button type="button" data-purpose="review" class="biz-link" :disabled="processing" @click="review">{{ t('purposeReview') }}</button><button v-if="reviewed" type="button" data-purpose="adopt" class="biz-link" :disabled="processing" @click="adopt">{{ t('purposeAdopt') }}</button></div>
    <label v-if="purpose">{{ t('purposeOperation') }}<select v-model="draft.operation" data-purpose="operation"><option v-if="!historyOnly" value="replace">{{ t('purposeReplace') }}</option><option value="correct">{{ t('purposeCorrect') }}</option><option value="withdraw">{{ t('purposeWithdraw') }}</option></select></label>
    <template v-if="!withdrawing">
        <label>{{ t('purposeMeaning') }}<textarea v-model="draft.meaning" data-purpose="meaning" maxlength="4000" required /></label>
        <label>{{ t('purposeQualifications') }}<textarea v-model="draft.qualifications" maxlength="4000" /></label>
        <label>{{ t('purposePhase') }}<input v-model="draft.phase" maxlength="80" /></label>
        <fieldset v-for="(set, setIndex) in [draft.included,draft.excluded]" :key="setIndex"><legend>{{ setIndex === 0 ? t('purposeIncluded') : t('purposeExcluded') }}</legend>
            <div v-for="(row,index) in set" :key="index" class="grid gap-3 border p-3">
                <template v-if="businessMode"><label>المزوّد / Provider<input v-model="row.provider" data-purpose="provider" maxlength="180" required /></label><label>نطاق الحساب / Account namespace<input v-model="row.account_namespace" data-purpose="namespace" maxlength="180" required /></label><label>نوع العنصر / Object kind<input v-model="row.object_kind" data-purpose="kind" maxlength="180" required /></label></template><label v-else>{{ t('purposeKind') }}<select v-model="row.object_kind" :data-purpose="setIndex === 0 ? `kind-${index}` : `excluded-kind-${index}`"><option value="campaign">{{ t('purposeCampaign') }}</option><option value="ad_set">{{ t('purposeAdSet') }}</option></select></label>
                <label>{{ t('purposeAccount') }}<input v-model="row.account_id" :data-purpose="setIndex === 0 ? `account-${index}` : `excluded-account-${index}`" :maxlength="businessMode ? 180 : 40" :inputmode="businessMode ? 'text' : 'numeric'" required /></label>
                <label>{{ t('purposeObject') }}<input v-model="row.object_id" :data-purpose="setIndex === 0 ? `object-${index}` : `excluded-object-${index}`" :maxlength="businessMode ? 180 : 40" :inputmode="businessMode ? 'text' : 'numeric'" required /></label>
                <label>{{ t('purposeIncarnation') }}<input v-model="row.incarnation" maxlength="180" /></label>
                <button type="button" :disabled="setIndex === 0 && set.length === 1" class="biz-link" @click="set.splice(index,1)">{{ t('purposeRemove') }}</button>
            </div><button type="button" :data-purpose="setIndex === 0 ? 'add-included' : 'add-excluded'" class="biz-link" :disabled="set.length >= 50" @click="set.push(object())">{{ t('purposeAdd') }}</button>
        </fieldset>
        <label>{{ t('purposeSpeaker') }}<input v-model="draft.speaker" maxlength="4000" /></label>
        <label>{{ t('purposeStatementAt') }}<input v-model="draft.statement_at" placeholder="2026-10-07T12:00:00Z" /></label>
        <label>{{ t('purposeSource') }}<textarea v-model="draft.source" data-purpose="source" maxlength="4000" required /></label>
        <label>{{ t('purposeIdentityBasis') }}<textarea v-model="draft.identity_basis" data-purpose="identity_basis" maxlength="4000" required /></label>
        <label>{{ t('purposeAuthorityBasis') }}<textarea v-model="draft.authority_basis" data-purpose="authority_basis" maxlength="4000" required /></label>
        <label>{{ t('purposeEffectiveBasis') }}<textarea v-model="draft.effective_basis" data-purpose="effective_basis" maxlength="4000" required /></label>
        <label>{{ t('purposeHistoricalSupport') }}<textarea v-model="draft.historical_support" maxlength="4000" /></label>
        <label>{{ t('purposeEnd') }}<input v-model="draft.until" placeholder="2026-10-07T12:00:00Z" /></label>
        <label>{{ t('purposeCondition') }}<textarea v-model="draft.condition" maxlength="4000" /></label>
        <label>{{ t('purposeLimitations') }}<textarea v-model="draft.limitations" maxlength="4000" /></label>
        <label>{{ t('purposeConflicts') }}<textarea v-model="draft.conflicts" maxlength="4000" /></label>
        <label>{{ t('purposeDtm') }}<select v-model="draft.dtmMode"><option value="none">{{ t('purposeNoRelation') }}</option><option value="historical_attestation">{{ t('purposeHistorical') }}</option><option value="current_alignment">{{ t('purposeAlignment') }}</option><option value="current_dependency">{{ t('purposeDependency') }}</option></select></label>
        <template v-if="draft.dtmMode !== 'none'">
            <label>{{ t('purposeContextVersion') }}<input v-model="draft.dtmVersion" type="number" min="0" /></label>
            <label>{{ t('purposeCountries') }}<input v-model="draft.countries" placeholder="SA, AE" /></label>
            <label>{{ t('purposeSubset') }}<input v-model="draft.subset" /></label>
            <label>{{ t('purposeRelationStatement') }}<textarea v-model="draft.dtmStatement" maxlength="4000" /></label>
        </template>
        <label v-if="intentReference"><input v-model="draft.intentEnabled" type="checkbox" />{{ t('purposeIntent') }}</label><p v-else>{{ t('purposeIntentAbsent') }}</p>
        <div v-if="draft.intentEnabled && intentReference"><p>{{ t('purposeIntentReference') }} · {{ intentReference.version }} · {{ intentReference.value.outcome.meaning }}</p><p>{{ intentReference.value.description }} · {{ intentReference.value.outcome.clarification }} · {{ intentReference.value.quantity }} {{ intentReference.value.outcome.count_unit }}</p><p>{{ intentReference.value.direction }} · {{ intentReference.value.scope }} · {{ intentReference.value.horizon?.deadline_date }} {{ intentReference.value.horizon?.timezone }}</p><button v-if="currentIntent?.value" type="button" class="biz-link" @click="useCurrentIntentReference">{{ t('purposeUseCurrentIntent') }}</button></div><label v-if="draft.intentEnabled">{{ t('purposeRelationStatement') }}<textarea v-model="draft.intentStatement" maxlength="4000" /></label>
    </template>
    <label>{{ t('purposeStart') }}<input v-model="draft.from" placeholder="2026-10-07T12:00:00Z" /></label>
    <label v-if="purpose && draft.operation !== 'replace'">{{ t('purposeReason') }}<textarea v-model="draft.reason" data-purpose="reason" maxlength="4000" required /></label>
    <div v-if="baseline"><p>{{ t('purposeAffected') }}</p><ul><li v-for="revision in baseline.history" :key="revision.revision_id">{{ revision.version }} · {{ revision.revision_id }} · {{ revision.effective_from }}</li></ul></div>
    <label v-for="key in required" :key="key"><input v-model="flags[key]" :data-purpose="`confirm-${key}`" type="checkbox" /> {{ flagLabels[key] }}</label>
    <p v-for="(message,field) in errors" :key="field" role="alert" class="biz-error">{{ message }}</p>
    <button type="submit" data-purpose="save" class="biz-button" :disabled="processing || settling || changed || !!pending">{{ t('purposeSubmit') }}</button>
    </fieldset>
</form>
</template>
