<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { beginDelivery, definitiveRejection, boundaryDrafts, key, type BoundaryDraft } from './drafts';
import { businessChoices, businessSurface, writeResourceRight, type BoundarySurface, type Choices, type Surface, type ResourceCommand } from './api';

const props = defineProps<{ surface: BoundarySurface; refreshing?: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const cacheKey = `${key(props.surface.boundary.boundary_id, props.surface.authority_selection)}:boundary-editor:1`;
const draft = reactive<BoundaryDraft>(boundaryDrafts.get(cacheKey) ?? { choice: '', selected: '', revision: 0, membership: null, confirmed: false, notice: '', blocked: false });
boundaryDrafts.set(cacheKey, draft);
draft.uncertain ??= !!draft.pending;
const choices = ref<Choices['items']>([]), next = ref<string | null>(null), target = ref<Surface>(), loading = ref(false), busy = ref(false);
let disposed = false, targetSerial = 0, choiceSerial = 0;
onBeforeUnmount(() => { disposed = true; });
const chosen = computed(() => choices.value.find(item => item.read_grant_id === draft.choice));
const options = computed(() => target.value?.collaboration.options.filter(item => Number.isSafeInteger(Number(item.grant_id))) ?? []);
const latest = computed(() => props.surface.memberships.find(row => row.business_public_id === chosen.value?.business_public_id && row.branch_public_id === null));
const stale = computed(() => !!chosen.value && (draft.revision !== (latest.value?.revision ?? 0) || draft.membership !== (latest.value?.membership_id ?? null)));
const exactIdentifiers = computed(() => props.surface.authority_selection.requirements.every(item => Number.isSafeInteger(item.leaf_grant_id) && item.leaf_grant_id > 0));
const enabled = computed(() => !busy.value && !props.refreshing && !draft.blocked && exactIdentifiers.value);
const canAttach = computed(() => enabled.value && !loading.value && !stale.value && Number.isSafeInteger(draft.revision) && props.surface.boundary.state === 'active' && latest.value?.state !== 'active'
    && !!chosen.value && !!target.value && target.value.business.business_public_id === chosen.value.business_public_id && options.value.some(o => o.grant_id === draft.selected));
async function fetchChoices(after?: number) {
    if (draft.blocked) return;
    const captured = stamp(), serial = ++choiceSerial; loading.value = true;
    try {
        const result = await businessChoices(after);
        if (disposed || !owned(captured) || serial !== choiceSerial) return;
        const realm = props.surface.authority_selection;
        const matching = result.items.filter(item => item.realm_kind === realm.realm_kind && item.realm_workspace_public_id === realm.realm_workspace_public_id && Number.isSafeInteger(Number(item.read_grant_id)));
        choices.value = after ? [...choices.value, ...matching] : matching; next.value = result.next_cursor;
        if (draft.choice && chosen.value) await choose(false);
    } catch { if (!disposed && owned(captured) && serial === choiceSerial) draft.notice = t('تعذر تحميل خيارات الأنشطة. لم نستنتج عدم وجودها أو نمنح حقًا بديلًا.', 'Business choices could not be loaded. Absence or replacement authority has not been inferred.'); }
    finally { if (!disposed && owned(captured) && serial === choiceSerial) loading.value = false; }
}
function review() {
    if (draft.pending || busy.value) return;
    draft.revision = latest.value?.revision ?? 0; draft.membership = latest.value?.membership_id ?? null; draft.confirmed = false;
}
async function choose(explicit = true) {
    const captured = stamp(), serial = ++targetSerial, item = chosen.value;
    target.value = undefined;
    if (explicit) { draft.selected = ''; review(); }
    if (!item) return;
    loading.value = true;
    try {
        const result = await businessSurface(item.business_public_id, Number(item.read_grant_id));
        if (disposed || !owned(captured) || serial !== targetSerial) return;
        if (result.business.business_public_id !== item.business_public_id) throw Error('Business identity changed');
        target.value = result;
        if (explicit && options.value.length === 1) draft.selected = options.value[0].grant_id;
    } catch { if (!disposed && owned(captured) && serial === targetSerial) draft.notice = t('تعذر تأهيل النشاط المختار. لا يمكن إكمال الربط به هنا.', 'The selected business could not be qualified. Attachment is unavailable here.'); }
    finally { if (!disposed && owned(captured) && serial === targetSerial) loading.value = false; }
}
onMounted(() => { void fetchChoices(); });
watch(() => props.surface, () => {
    // Requalification of boundary administration does not requalify cached Business
    // names or action options. Clear both before the resumed render, then read their
    // independent authorities again. Older in-flight replies cannot repopulate them.
    choiceSerial++; targetSerial++; choices.value = []; next.value = null; target.value = undefined;
    void fetchChoices();
}, { flush: 'sync' });
function command(effect: 'attach_subject' | 'detach_subject', targetRef: string, revision: number): ResourceCommand {
    const selection = JSON.parse(JSON.stringify(props.surface.authority_selection));
    if (effect === 'attach_subject') selection.requirements.push({ requirement_kind: 'business', leaf_grant_id: Number(draft.selected) });
    return { command_id: crypto.randomUUID(), target_kind: 'boundary_subject', target_ref: targetRef, effect,
        payload: effect === 'attach_subject' ? { business_public_id: chosen.value!.business_public_id, branch_id: null } : {}, expected_revision: revision, authority_selection: selection };
}
async function execute(body: ResourceCommand) {
    if (!enabled.value) return;
    draft.pending ??= JSON.parse(JSON.stringify(body));
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as ResourceCommand, captured = stamp();
    const uncertain = beginDelivery(draft);
    busy.value = true; draft.confirmed = false; draft.notice = '';
    try {
        const result = await writeResourceRight(frozen);
        if (disposed || !owned(captured)) return;
        if (!('membership_id' in result) || result.status !== (frozen.effect === 'attach_subject' ? 'active' : 'ended')
            || frozen.effect === 'detach_subject' && result.membership_id !== frozen.target_ref) throw Error('Boundary acknowledgement target mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.choice = ''; draft.selected = ''; target.value = undefined; draft.confirmed = true; emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            choiceSerial++; targetSerial++; choices.value = []; next.value = null; target.value = undefined;
            Object.assign(draft, { choice: '', selected: '', revision: 0, membership: null, confirmed: false, blocked: true });
            draft.notice = t('لم يعد الطلب مؤهلًا. لم نستنتج نجاحه أو نبدّل سنده.', 'The command is no longer qualified. No success or replacement authority was inferred.'); emit('refresh');
        } else if (definitiveRejection(error, uncertain) && error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; draft.uncertain = false; draft.notice = t('لم تقبل بيانات الطلب. راجع النطاق والنشاط والسند.', 'The command fields were rejected. Review boundary, business and authority.');
        } else if (error instanceof TransportError && error.status === 409 && 'error' in error.body && error.body.error === 'business_relation_revision_rejected') {
            draft.pending = undefined; draft.uncertain = false; draft.notice = t('رُفض إصدار التغطية قبل الأثر. راجع الحالة قبل طلب جديد.', 'Coverage revision was rejected before effect. Review current state before a new command.'); emit('refresh');
        } else draft.notice = t('نتيجة الطلب غير محسومة. الأمر الأصلي محفوظ للتحقق دون تكرار تلقائي.', 'The command outcome is unresolved. Its original command is retained without automatic retry.');
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
function attach() { if (draft.pending) { void execute(draft.pending); return; } if (canAttach.value) void execute(command('attach_subject', props.surface.boundary.boundary_id, draft.revision)); }
function detach(row: BoundarySurface['memberships'][number]) { if (enabled.value && !draft.pending && row.state === 'active' && Number.isSafeInteger(row.revision)) void execute(command('detach_subject', row.membership_id, row.revision)); }
</script>

<template>
    <section class="bf-card bf-boundary" data-boundary-editor>
        <h2>{{ t('تغطية نطاق العميل', 'Client boundary coverage') }}</h2><code>{{ surface.boundary.boundary_id }}</code>
        <p v-if="!exactIdentifiers" role="status">{{ t('لا يستطيع هذا العميل إرسال معرّف التفويض بدقته الكاملة. إجراءات التعديل متوقفة.', 'This client cannot preserve the complete authority identifier. Mutation actions are unavailable.') }}</p>
        <p>{{ t('يمكن لصاحب حق إدارة النطاق إنهاء التغطية دون تفويض من النشاط. لا تُحذف بيانات أو سجلات، ولا يُمنح حق مصدر أو تحليل بالربط.', 'A boundary manager may end coverage without business consent. No data or history is deleted, and attachment grants no source or processing right.') }}</p>
        <p v-if="surface.memberships_more" role="status">{{ t('تعرض أحدث 64 تغطية فقط، وليست قائمة كاملة.', 'Only the latest 64 coverage records are displayed; this is not a complete inventory.') }}</p>
        <template v-if="!refreshing">
            <p v-if="!surface.memberships.length" data-boundary-no-memberships>{{ t('لا توجد تغطية مسجلة معروضة.', 'No recorded coverage displayed.') }}</p>
            <article v-for="row in surface.memberships" :key="row.membership_id" class="bf-issued" :data-boundary-membership="row.membership_id" :data-boundary-state="row.state">
                <strong>{{ t('مرجع النشاط', 'Business reference') }}</strong><code>{{ row.business_public_id }}</code>
                <p v-if="row.branch_public_id">{{ t('مرجع الفرع', 'Branch reference') }}: <code>{{ row.branch_public_id }}</code></p>
                <p>{{ row.state === 'active' ? t('تغطية قائمة — الاستعمال يحتاج حقوقه المستقلة', 'Current coverage — use needs independent rights') : t('تغطية منتهية — التاريخ محفوظ', 'Ended coverage — history retained') }}</p>
                <button v-if="row.state === 'active'" type="button" :disabled="!enabled || !!draft.pending || !Number.isSafeInteger(row.revision)" @click="detach(row)">{{ t('إنهاء هذه التغطية', 'End this coverage') }}</button>
            </article>
        </template>
        <p v-if="draft.blocked && draft.pending" role="status">{{ t('الطلب الأصلي محفوظ وغير محسوم. راجع وصولك الحالي قبل المتابعة.', 'The unresolved original request is retained. Review current access before continuing.') }}</p>
        <form v-if="!draft.blocked" class="bf-form" data-boundary-attach @submit.prevent="attach">
            <h3>{{ t('ربط نطاق العميل بنشاط', 'Attach client boundary to a business') }}</h3>
            <p>{{ t('يحتاج الربط حق إدارة هذا النطاق وحق إدارة علاقات النشاط معًا. تعرض أسماء الأنشطة التي تملك حق قراءتها في نطاق التفويض نفسه فقط.', 'Attachment requires both boundary management and business relationship authority. Names are shown only for businesses you may read in the same realm.') }}</p>
            <fieldset :disabled="!enabled || !!draft.pending || loading">
                <label for="bf-boundary-business">{{ t('النشاط المؤهل', 'Qualified business') }}</label><select id="bf-boundary-business" v-model="draft.choice" @change="choose()"><option value="">{{ t('اختر النشاط', 'Choose business') }}</option><option v-for="item in choices" :key="item.read_grant_id" :value="item.read_grant_id">{{ item.name }}</option></select>
                <label v-if="options.length > 1" for="bf-boundary-grant">{{ t('تفويض إدارة علاقات النشاط', 'Business relationship delegation') }}<select id="bf-boundary-grant" v-model="draft.selected"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="option in options" :key="option.grant_id" :value="option.grant_id">{{ option.grant_id }}</option></select></label>
                <p v-if="target && !options.length">{{ t('يمكنك قراءة هذا النشاط لكن لا يوجد تفويض مؤهل معروض لربطه.', 'You may read this business but no qualified attachment delegation is displayed.') }}</p>
                <p v-if="draft.choice && !chosen && !loading">{{ t('النشاط المختار غير متاح في الخيارات الحالية. راجع الاختيار دون استبدال سند طلب سابق.', 'The selected business is absent from current choices. Review the selection without replacing a prior command’s authority.') }}</p>
            </fieldset>
            <p v-if="loading" role="status">{{ t('جارٍ تأهيل خيارات الأنشطة…', 'Qualifying business choices…') }}</p>
            <button v-if="next" type="button" :disabled="!enabled || loading || !!draft.pending || !Number.isSafeInteger(Number(next))" @click="fetchChoices(Number(next))">{{ t('المزيد من خيارات الأنشطة', 'More business choices') }}</button>
            <p v-if="stale && !draft.pending && !refreshing" role="alert" data-boundary-conflict>{{ t('تغيرت التغطية منذ اختيار النشاط. لم نبدل الإصدار تلقائيًا.', 'Coverage changed after selection. Its original revision was not replaced automatically.') }}<button type="button" @click="review">{{ t('مراجعة الحالة لطلب جديد', 'Review state for a new command') }}</button></p>
            <p v-if="draft.pending && !busy" role="status" data-boundary-unknown>{{ t('يوجد طلب غير محسوم؛ تحقق بالأمر الأصلي.', 'A command is unresolved; reconcile its original command.') }}</p>
            <p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="draft.confirmed" role="status" data-boundary-confirmed>{{ t('استُلم تأكيد الأمر. تعرض التغطية بعد إعادة التحقق.', 'The command was acknowledged. Coverage is displayed after qualified readback.') }}</p>
            <button class="bf-primary" :disabled="draft.pending ? !enabled : !canAttach">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Reconcile original command') : t('ربط النطاق بالنشاط', 'Attach boundary to business') }}</button>
        </form>
    </section>
</template>
