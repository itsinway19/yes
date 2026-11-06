<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import Modal from '../Components/Modal.vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { beginDelivery, definitiveRejection, key, lifecycleDrafts, type LifecycleDraft } from './drafts';
import { writeLifecycle, type LifecycleCommand, type Surface } from './api';
import { hasPendingBusinessOperation } from './businessPending';

const props = withDefaults(defineProps<{ surface: Surface; refreshing?: boolean; active?: boolean }>(), { active: true });
const emit = defineEmits<{ refresh: [] }>();
const router = useRouter();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const resource = `${key(props.surface.business.business_public_id, props.surface.authority_selection)}:lifecycle:1`;
const options = computed(() => props.surface.lifecycle.archive_options.filter(option =>
    /^\d+$/.test(option.grant_id) && Number.isSafeInteger(Number(option.grant_id)) && Number(option.grant_id) > 0));
const draft = reactive<LifecycleDraft>(lifecycleDrafts.get(resource) ?? { selected: options.value.length === 1 ? options.value[0].grant_id : '',
    revision: props.surface.lifecycle.revision, blocked: false, outcome: '' });
lifecycleDrafts.set(resource, draft);
draft.uncertain ??= !!draft.pending;
const busy = ref(false), confirming = ref(false), pendingReview = ref(0);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
const pendingGrant = computed(() => draft.pending?.authority_selection.requirements.find(item => item.requirement_kind === 'business')?.leaf_grant_id);
const qualified = computed(() => options.value.some(option => draft.pending ? Number(option.grant_id) === pendingGrant.value : option.grant_id === draft.selected));
const changed = computed(() => draft.revision !== props.surface.lifecycle.revision);
const canSend = computed(() => props.active && !props.refreshing && !busy.value && !draft.blocked && qualified.value && draft.outcome !== 'archived');
function requalify() {
    if (draft.pending && !qualified.value) { draft.selected = ''; draft.blocked = true; }
    else if (!draft.pending && !options.value.some(option => option.grant_id === draft.selected)) {
        draft.selected = options.value.length === 1 ? options.value[0].grant_id : '';
    }
}
requalify();
watch(() => props.surface, requalify, { flush: 'sync' });
watch(() => props.surface, () => { confirming.value = false; });
watch(() => props.active, () => { pendingReview.value++; confirming.value = false; }, { flush: 'sync' });
function pendingWork() { pendingReview.value; return hasPendingBusinessOperation(props.surface.business.business_public_id); }
function adoptLatest() {
    if (busy.value || draft.pending || draft.blocked) return;
    draft.revision = props.surface.lifecycle.revision; draft.outcome = '';
}
function archive() {
    pendingReview.value++;
    if (!canSend.value || draft.pending || changed.value || pendingWork() || !Number.isSafeInteger(draft.revision) || draft.revision < 0) return;
    confirming.value = true;
}
async function confirmArchive() {
    pendingReview.value++;
    if (!confirming.value) return;
    confirming.value = false;
    if (!canSend.value || draft.pending || changed.value || pendingWork() || !Number.isSafeInteger(draft.revision) || draft.revision < 0) return;
    draft.pending = { command_id: crypto.randomUUID(), effect: 'archive', target_ref: null, payload: {}, expected_revision: draft.revision,
        authority_selection: { ...JSON.parse(JSON.stringify(props.surface.authority_selection)),
            requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(draft.selected) }] } };
    await sendOriginal();
}
async function sendOriginal() {
    if (!canSend.value || !draft.pending) return;
    const captured = stamp(), business = props.surface.business.business_public_id;
    const original = JSON.parse(JSON.stringify(draft.pending)) as LifecycleCommand;
    const uncertain = beginDelivery(draft);
    busy.value = true; draft.outcome = '';
    try {
        const result = await writeLifecycle(business, original);
        if (disposed || !owned(captured)) return;
        if (result.business_public_id !== business || !('state' in result) || result.state !== 'archived'
            || result.versions.lifecycle !== original.expected_revision + 1) throw Error('Business archive acknowledgement mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.outcome = 'archived';
        // The former Business read can be denied after archiving. Re-admit the qualified list.
        try { await router.push('/businesses'); }
        catch { /* A navigation failure does not undo the acknowledged archive; the list link remains available. */ }
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            draft.selected = ''; draft.blocked = true; draft.outcome = 'denied'; emit('refresh');
        } else if (definitiveRejection(error, uncertain)
            || (!uncertain && error instanceof TransportError && error.status === 409 && 'error' in error.body && error.body.error === 'business_conflict')) {
            draft.pending = undefined; draft.uncertain = false; draft.outcome = 'rejected'; emit('refresh');
        } else draft.outcome = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section class="workspace-lifecycle" data-business-lifecycle :aria-busy="busy">
        <h2>{{ t('حالة المسودة', 'Draft status') }}</h2>
        <template v-if="draft.outcome === 'archived'">
            <p role="status" data-business-archive-saved>{{ t('أكد الخادم أرشفة المسودة. افتح قائمة الأنشطة لمراجعة وصولك الحالي.', 'The server confirmed archiving. Open the business list to review current access.') }}</p>
        </template>
        <template v-else-if="draft.blocked || !options.length">
            <p role="status" data-business-archive-denied>{{ t('الأرشفة غير متاحة بالوصول الحالي. راجع وصولك من قائمة الأنشطة.', 'Archiving is unavailable under current access. Review your access from the business list.') }}</p>
        </template>
        <template v-else>
            <p>{{ t('أرشف المسودة إذا لم تعد تريد تنفيذ عمليات جديدة عليها. يبقى تاريخها محفوظًا بحقوقه الأصلية.', 'Archive the draft when you no longer want new operations on it. Its history retains its original access rights.') }}</p>
            <label v-if="options.length > 1 && !draft.pending" for="bf-archive-path">{{ t('تفويض الأرشفة', 'Archiving delegation') }}
                <select id="bf-archive-path" v-model="draft.selected" :disabled="busy || refreshing"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, index) in options" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}</option></select>
            </label>
            <p v-if="changed && !draft.pending" role="status" data-business-lifecycle-conflict>{{ t('تغيرت حالة المسودة. راجع الإصدار الحالي قبل تأكيد طلب جديد.', 'Draft status changed. Review the current revision before confirming a new request.') }} <button type="button" class="workspace-button" :disabled="busy || refreshing" data-business-lifecycle-adopt @click="adoptLatest">{{ t('مراجعة الحالة الحالية', 'Review current status') }}</button></p>
            <p v-if="draft.outcome === 'rejected'" role="status" data-business-archive-rejected>{{ t('رُفض الطلب قبل تنفيذه. راجع الحالة الحالية ثم أكّد طلبًا جديدًا عند الحاجة.', 'The request was rejected before execution. Review current status and explicitly confirm a new request if needed.') }}</p>
            <p v-if="!draft.pending && pendingWork()" role="status" data-business-archive-pending-work>{{ t('يوجد طلب آخر غير محسوم لهذا النشاط. تحقّق من نتيجته في القسم الذي بدأت منه قبل الأرشفة.', 'Another request for this business is unresolved. Check its outcome in the section where you started it before archiving.') }}</p>
            <button v-if="!draft.pending" type="button" class="workspace-button workspace-danger" data-business-archive :disabled="!canSend || changed || pendingWork()" @click="archive">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : t('أرشفة المسودة', 'Archive draft') }}</button>
        </template>
        <p v-if="busy" role="status" data-business-archive-processing>{{ t('جارٍ التحقق من طلب الأرشفة…', 'Checking the archive request…') }}</p>
        <div v-else-if="draft.pending" role="status" data-business-archive-unknown>
            <p>{{ t('لم نتمكن من تأكيد نتيجة الأرشفة. الطلب محفوظ؛ تحقّق منه قبل اتخاذ خطوة أخرى.', 'The archive outcome is unconfirmed. Its request is retained; check it before taking another step.') }}</p>
            <button type="button" class="workspace-button" data-business-archive-reconcile :disabled="!canSend" @click="sendOriginal">{{ t('التحقق بالطلب الأصلي', 'Check using original request') }}</button>
        </div>
        <RouterLink v-if="draft.pending || draft.blocked || draft.outcome === 'archived'" class="workspace-button" to="/businesses">{{ t('قائمة الأنشطة', 'Business list') }}</RouterLink>
        <Modal role="alertdialog" :show="confirming" max-width="md" labelled-by="business-archive-title" described-by="business-archive-description" @close="confirming = false">
            <div class="workspace-archive-confirmation" data-business-archive-confirmation :dir="locale === 'ar' ? 'rtl' : 'ltr'">
                <h2 id="business-archive-title">{{ t('أرشفة المسودة', 'Archive draft') }}: {{ surface.business.name }}</h2>
                <p id="business-archive-description">{{ t('ستتوقف العمليات الجديدة على هذه المسودة، ويبقى تاريخها محفوظًا بحقوقه الأصلية. لا تتضمن هذه الخطوة استعادة المسودة.', 'New operations on this draft will stop, and its history retains its original access rights. This step provides no restoration.') }}</p>
                <div class="workspace-archive-actions"><button type="button" class="workspace-button" data-business-archive-cancel autofocus @click="confirming = false">{{ t('إلغاء', 'Cancel') }}</button><button type="button" class="workspace-button workspace-danger" data-business-archive-confirm :disabled="!canSend || changed || pendingWork()" @click="confirmArchive">{{ t('أرشفة المسودة', 'Archive draft') }}</button></div>
            </div>
        </Modal>
    </section>
</template>
