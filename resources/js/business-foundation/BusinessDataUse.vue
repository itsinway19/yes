<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { dataUseChoices, writeBinding, writeResourceRight, type Surface, type DataUseChoices, type ResourceCommand } from './api';
import { key, dataUseDrafts, type DataUseDraft } from './drafts';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const emit = defineEmits<{ refresh: []; pendingChange: [pending: boolean] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const cacheKey = `${key(props.surface.business.business_public_id, props.surface.authority_selection)}:data-use:1`;
const draft = reactive<DataUseDraft>(dataUseDrafts.get(cacheKey) ?? { selected: '', manager: '', baselineScope: '', baselineRevision: 0, confirmed: false, notice: '', acknowledged: false, blocked: false });
dataUseDrafts.set(cacheKey, draft);
watch(() => Boolean(draft.pending), pending => emit('pendingChange', pending), { immediate: true, flush: 'sync' });
const choices = ref<DataUseChoices['scopes']>([]), next = ref<string | null>(null), loading = ref(false), busy = ref(false);
let serial = 0, disposed = false;
onBeforeUnmount(() => { disposed = true; serial++; });
const managers = computed(() => props.surface.collaboration.options.filter(row => Number.isSafeInteger(Number(row.grant_id))));
const chosen = computed(() => choices.value.find(row => row.grant_id === draft.selected));
const latest = computed(() => props.surface.data_use.bindings.find(row => row.scope_id === chosen.value?.scope_id));
const stale = computed(() => chosen.value && (draft.baselineScope !== chosen.value.scope_id || draft.baselineRevision !== (latest.value?.revision ?? 0)));
const enabled = computed(() => !busy.value && !props.refreshing && !draft.blocked && managers.value.some(row => row.grant_id === draft.manager));
const safeChoice = computed(() => chosen.value && Number.isSafeInteger(chosen.value.grant_revision) && chosen.value.authority_selection.requirements.every(row => Number.isSafeInteger(row.leaf_grant_id) && row.leaf_grant_id > 0));
async function fetchScopes(after?: number) {
    const attempt = ++serial, captured = stamp(); loading.value = true;
    try {
        const value = await dataUseChoices(after);
        if (disposed || !owned(captured) || serial !== attempt) return;
        const realm = props.surface.authority_selection;
        const matching = value.scopes.filter(row => row.authority_selection.realm_kind === realm.realm_kind && row.authority_selection.realm_workspace_public_id === realm.realm_workspace_public_id);
        choices.value = after ? [...choices.value, ...matching] : matching; next.value = value.next_cursor;
    } catch { if (!disposed && owned(captured) && serial === attempt) draft.notice = t('تعذر تأهيل نطاقات الاستعمال. لم نفسّر ذلك كغياب للبيانات أو كإذن بديل.', 'Data-use scopes could not be qualified. No absence of data or replacement permission was inferred.'); }
    finally { if (!disposed && owned(captured) && serial === attempt) loading.value = false; }
}
function review() {
    if (draft.pending || busy.value) return;
    draft.baselineScope = chosen.value?.scope_id ?? ''; draft.baselineRevision = latest.value?.revision ?? 0; draft.confirmed = false;
}
onMounted(() => { if (!draft.manager && managers.value.length === 1) draft.manager = managers.value[0].grant_id; void fetchScopes(); });
watch(() => props.surface, () => {
    // Parent Business admission does not renew independent data-use metadata.
    serial++; choices.value = []; next.value = null; void fetchScopes();
}, { flush: 'sync' });
function binding(effect: 'add' | 'end', scope: string, revision: number): DataUseDraft['pending'] {
    const authority = JSON.parse(JSON.stringify(props.surface.authority_selection));
    authority.requirements = [{ requirement_kind: 'business', leaf_grant_id: Number(draft.manager) }];
    if (effect === 'add') authority.requirements.push(...JSON.parse(JSON.stringify(chosen.value!.authority_selection.requirements)));
    return { kind: 'binding', business: props.surface.business.business_public_id, body: { command_id: crypto.randomUUID(), data_use_scope_id: scope, effect, expected_relation: revision, authority_selection: authority } };
}
async function execute(command: DataUseDraft['pending']) {
    if (!enabled.value || !command) return;
    draft.pending ??= JSON.parse(JSON.stringify(command));
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as NonNullable<DataUseDraft['pending']>, captured = stamp();
    let acknowledgedRevision: number | undefined;
    busy.value = true; draft.notice = ''; draft.acknowledged = false;
    try {
        if (frozen.kind === 'binding') {
            const result = await writeBinding(frozen.business, frozen.body);
            if (result.status !== (frozen.body.effect === 'add' ? 'active' : 'ended') || result.versions.binding < frozen.body.expected_relation) throw Error('Binding receipt identity unavailable');
            acknowledgedRevision = result.versions.binding;
        } else {
            const result = await writeResourceRight(frozen.body);
            if (!('grant_id' in result) || !('grant_id' in frozen.body.payload) || String(result.grant_id) !== frozen.body.payload.grant_id || result.status !== 'revoked') throw Error('Exact surrendered right receipt required');
        }
        if (disposed || !owned(captured)) return;
        if (frozen.kind === 'binding' && acknowledgedRevision !== undefined) {
            draft.baselineScope = frozen.body.data_use_scope_id;
            // The exact command's receipt advances only its own binding baseline;
            // a later independently read revision still requires explicit review.
            draft.baselineRevision = acknowledgedRevision;
        }
        draft.pending = undefined; draft.confirmed = false; draft.acknowledged = true; emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            draft.blocked = true; dataUseDrafts.delete(cacheKey); draft.notice = t('لم يعد الأمر مؤهلًا. لم نفترض نجاحه أو نستبدل سنده.', 'The command is no longer qualified. No success or replacement authority was inferred.'); emit('refresh');
        } else if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; draft.notice = t('رُفضت حقول الأمر. راجع النطاق والفعل قبل طلب جديد.', 'Command fields were rejected. Review scope and action before a new request.');
        } else if (error instanceof TransportError && error.status === 409 && 'error' in error.body && error.body.error === 'business_relation_revision_rejected') {
            draft.pending = undefined; draft.notice = t('تغير إصدار الربط قبل الأثر. راجع الحالة دون إعادة تلقائية.', 'The binding revision changed before effect. Review without automatic retry.'); emit('refresh');
        } else draft.notice = t('النتيجة غير محسومة. احتُفظ بالأمر الأصلي للتحقق الصريح دون تكرار تلقائي.', 'The outcome is unresolved. Its original command is retained for explicit reconciliation without automatic retry.');
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
function add() { if (enabled.value && !draft.pending && !loading.value && chosen.value && safeChoice.value && !stale.value && latest.value?.state !== 'active') void execute(binding('add', chosen.value.scope_id, draft.baselineRevision)); }
function end(row: Surface['data_use']['bindings'][number]) { if (enabled.value && !draft.pending && draft.confirmed && Number.isSafeInteger(row.revision)) void execute(binding('end', row.scope_id, row.revision)); }
function surrender() {
    if (!enabled.value || draft.pending || !draft.confirmed || !chosen.value || !safeChoice.value) return;
    const body: ResourceCommand = { command_id: crypto.randomUUID(), target_kind: 'data_use', target_ref: chosen.value.scope_id, effect: 'revoke',
        payload: { grant_id: chosen.value.grant_id }, expected_revision: chosen.value.grant_revision, authority_selection: JSON.parse(JSON.stringify(chosen.value.authority_selection)) };
    void execute({ kind: 'surrender', body });
}
function operation(value: string) { return ({ retained_read: t('قراءة محفوظة', 'Retained reading'), process: t('معالجة', 'Processing'), combine: t('دمج', 'Combining'), display: t('عرض', 'Display'), reuse: t('إعادة استعمال', 'Reuse'), export: t('تصدير', 'Export'), train: t('تدريب', 'Training'), retain: t('احتفاظ', 'Retention'), delete: t('حذف', 'Deletion'), forward: t('إحالة', 'Forwarding') } as Record<string, string>)[value] ?? value; }
</script>
<template>
    <section v-if="managers.length" class="bf-card bf-data-use" data-business-data-use>
        <h2>{{ t('استعمال بيانات العملاء', 'Client-data use') }}</h2>
        <p>{{ t('يربط النشاط بنطاق استعمال مؤهل قائم، ولا يمنحه حقًا جديدًا على المصدر أو العميل. يستلزم الربط تغطية العميل وسند النشاط وسند الاستعمال، وتُفحص حقوق المصدر والأدلة عند الاستعمال الفعلي.', 'Bind this business to an existing qualified use scope. This creates no source or client-data right. Attachment needs client coverage, Business authority and use authority; source and evidence rights are checked at actual use.') }}</p>
        <label for="bf-data-manager">{{ t('سند إدارة ربط النشاط', 'Business binding authority') }}</label><select id="bf-data-manager" v-model="draft.manager" :disabled="busy || refreshing || !!draft.pending"><option value="">{{ t('اختر سندًا مؤهلًا', 'Select a qualified authority') }}</option><option v-for="row in managers" :key="row.grant_id" :value="row.grant_id">{{ row.grant_id }}</option></select>
        <p v-if="surface.data_use.bindings_more" role="status">{{ t('أحدث 64 ربطًا فقط؛ السجل المعروض جزئي.', 'Latest 64 bindings only; displayed history is partial.') }}</p>
        <template v-if="!refreshing">
            <label class="bf-check"><input v-model="draft.confirmed" type="checkbox" data-data-use-confirm :disabled="!enabled || !!draft.pending">{{ t('أؤكد إنهاء الربط أو التنازل عن الحق المحدد فقط، مع حفظ التاريخ.', 'I confirm ending only the selected binding or surrendering only the selected right, retaining history.') }}</label>
            <p v-if="!surface.data_use.bindings.length" data-data-bindings-empty>{{ t('لا روابط استعمال مسجلة معروضة.', 'No recorded use bindings displayed.') }}</p>
            <article v-for="row in surface.data_use.bindings" :key="row.binding_id" class="bf-issued" :data-data-binding="row.binding_id" :data-binding-scope="row.scope_id" :data-binding-state="row.state"><strong>{{ row.state === 'active' ? t('ربط قائم — ليس إثباتًا لاستمرار حق الاستعمال', 'Current binding — continued use authority is not established') : t('ربط منتهٍ — التاريخ محفوظ', 'Ended binding — history retained') }}</strong><code>{{ row.scope_id }}</code><button v-if="row.state === 'active'" type="button" :disabled="!enabled || !!draft.pending || !draft.confirmed || !Number.isSafeInteger(row.revision)" @click="end(row)">{{ t('إنهاء ربط الاستعمال', 'End use binding') }}</button></article>
            <form class="bf-form" @submit.prevent="add"><h3>{{ t('نطاقات الاستعمال المؤهلة لك', 'Your qualified use scopes') }}</h3>
                <p v-if="loading" role="status">{{ t('جارٍ التأهيل المستقل لحقوق الاستعمال…', 'Independently qualifying use rights…') }}</p>
                <p v-else-if="!choices.length" data-data-use-empty>{{ t('لا نطاق مؤهل معروض الآن. لا نستنتج غياب البيانات أو نعيد حقًا مسحوبًا.', 'No qualified scope is displayed now. No absence of data is inferred and no withdrawn right is revived.') }}</p>
                <label for="bf-data-scope">{{ t('اختر نطاقًا وسند استعمال محددين', 'Select an exact scope and use authority') }}</label><select id="bf-data-scope" v-model="draft.selected" :disabled="!enabled || loading || !!draft.pending" @change="review"><option value="">{{ t('اختر نطاق الاستعمال', 'Select use scope') }}</option><option v-for="row in choices" :key="row.grant_id" :value="row.grant_id">{{ operation(row.operation) }} · {{ row.purpose }} · {{ row.scope_id }} · {{ row.grant_id }}</option></select>
                <div v-if="chosen" data-data-use-details><p>{{ t('الفعل المصرح', 'Allowed operation') }}: {{ operation(chosen.operation) }} · {{ t('الغرض', 'Purpose') }}: {{ chosen.purpose }}</p><p>{{ t('الحقول', 'Fields') }}: {{ chosen.field_keys.join('، ') }}</p><p>{{ t('عدد مراجع السجلات', 'Record references') }}: {{ chosen.record_count }} · {{ t('مرجع المصدر', 'Source reference') }}: <code>{{ chosen.source_ref }}</code></p><p>{{ t('مرجع حد العميل', 'Client boundary reference') }}: <code>{{ chosen.boundary_ref }}</code></p></div>
                <button v-if="next" type="button" :disabled="loading || busy || !!draft.pending" @click="fetchScopes(Number(next))">{{ t('عرض نطاقات إضافية', 'Load more scopes') }}</button>
                <p v-if="stale" role="status">{{ t('تغير إصدار الربط. راجع الحالة قبل أمر جديد.', 'The binding revision changed. Review before a new command.') }} <button type="button" :disabled="!enabled || !!draft.pending" @click="review">{{ t('مراجعة الحالة الحالية', 'Review current state') }}</button></p>
                <button type="submit" :disabled="!enabled || loading || !!draft.pending || !chosen || !safeChoice || !!stale || latest?.state === 'active'">{{ t('طلب ربط النطاق بالنشاط', 'Request scope binding') }}</button>
                <button type="button" data-data-use-surrender :disabled="!enabled || loading || !!draft.pending || !chosen || !safeChoice || !draft.confirmed" @click="surrender">{{ t('التنازل عن سند الاستعمال المحدد', 'Surrender this exact use right') }}</button>
            </form>
        </template>
        <p v-if="draft.acknowledged" role="status" data-data-use-confirmed>{{ t('تأكد إيصال الأمر، وتُقرأ الحالة الحالية بحقوقها المستقلة.', 'Command receipt confirmed; current state is read under its independent authority.') }}</p><p v-if="draft.notice" role="status">{{ draft.notice }}</p><button v-if="draft.pending" type="button" data-data-use-reconcile :disabled="!enabled" @click="execute(draft.pending)">{{ t('التحقق من الأمر الأصلي', 'Reconcile original command') }}</button>
    </section>
</template>
