<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { TransportError, owned, stamp } from '../spa/transport';
import { writeResourceRight, type ResourceCommand, type SourceSurface } from './api';
import { key, sourceDrafts, type SourceDraft } from './drafts';
const props = defineProps<{ surface: SourceSurface; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const cacheKey = `${key(props.surface.source.source_id, props.surface.authority_selection)}:source-editor:1`;
const draft = reactive<SourceDraft>(sourceDrafts.get(cacheKey) ?? { label: props.surface.source.label, revision: props.surface.source.revision, action: 'source.read', notice: '', confirmed: false, acknowledged: false, blocked: false });
sourceDrafts.set(cacheKey, draft);
const busy = ref(false); let disposed = false;
onBeforeUnmount(() => { disposed = true; });
const exact = computed(() => props.surface.authority_selection.requirements.every(item => Number.isSafeInteger(item.leaf_grant_id) && item.leaf_grant_id > 0) && Number.isSafeInteger(props.surface.source.revision));
const enabled = computed(() => exact.value && props.surface.can_command && !props.refreshing && !busy.value && !draft.blocked);
const current = computed(() => props.surface.source);
const activeManager = computed(() => String(props.surface.authority_selection.requirements[0].leaf_grant_id));
const actions = ['source.read', 'source.register', 'source.manage', 'source.audit.recover', 'provider.connect', 'provider.read', 'provider.revoke'];
const issueAvailable = computed(() => !props.surface.rights_more && !props.surface.rights.some(row => row.action === draft.action && row.recorded_status === 'active'));
watch(() => props.surface, () => { draft.confirmed = false; }, { flush: 'sync' });
function body(effect: ResourceCommand['effect'], payload: ResourceCommand['payload'], revision: number): ResourceCommand {
    return { command_id: crypto.randomUUID(), target_kind: 'source', target_ref: current.value.source_id, effect, payload, expected_revision: revision, authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) };
}
async function execute(command: ResourceCommand) {
    if (!enabled.value) return;
    draft.pending ??= JSON.parse(JSON.stringify(command));
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as ResourceCommand, captured = stamp();
    busy.value = true; draft.acknowledged = false; draft.notice = '';
    try {
        const result = await writeResourceRight(frozen);
        if (disposed || !owned(captured)) return;
        if (frozen.effect === 'revoke') {
            if (!('grant_id' in result) || !('grant_id' in frozen.payload) || String(result.grant_id) !== frozen.payload.grant_id || result.status !== 'revoked') throw Error('Exact revoked grant acknowledgement required');
        } else if (frozen.effect === 'qualify_issue') {
            if (!('grant_id' in result) || result.status !== 'active') throw Error('Issued grant acknowledgement required');
        } else if (!('source_id' in result) || result.source_id !== frozen.target_ref) throw Error('Exact source acknowledgement required');
        draft.pending = undefined; draft.acknowledged = true; draft.confirmed = false; emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            draft.blocked = true; sourceDrafts.delete(cacheKey); draft.notice = t('لم يعد الأمر مؤهلًا. لم نفترض نجاحه أو نستبدل التفويض.', 'The command is no longer qualified. No success or replacement authority was inferred.'); emit('refresh');
        } else if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; draft.notice = t('رُفضت بيانات الأمر. راجع الحقول قبل طلب جديد.', 'Command fields were rejected. Review them before a new request.');
        } else draft.notice = t('النتيجة غير محسومة أو متعارضة. حُفظ الأمر الأصلي للتحقق، دون إعادة تلقائية أو استنتاج نجاح.', 'The outcome is unresolved or conflicted. The original command is retained for explicit reconciliation, without automatic retry or inferred success.');
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
function rename() { if (draft.pending || draft.revision !== current.value.revision || !draft.label.trim() || draft.label.trim().length > 120) return; void execute(body('rename', { label: draft.label.trim(), reason: 'explicit_source_label' }, draft.revision)); }
function review() { if (draft.pending || busy.value) return; draft.revision = current.value.revision; draft.label = current.value.label; draft.acknowledged = false; }
function adopt() { if (!draft.pending && draft.confirmed) void execute(body('adopt', {}, current.value.revision)); }
function retire() { if (!draft.pending && draft.confirmed) void execute(body('retire', { reason: 'explicit_source_retirement' }, current.value.revision)); }
function declareScope() { if (!draft.pending && draft.confirmed && !current.value.scope_declared) void execute(body('declare_scope', { reason: 'explicit_native_scope' }, current.value.revision)); }
function issue() { if (!draft.pending && issueAvailable.value) void execute(body('qualify_issue', { action: draft.action, expires_at: null, reason: 'explicit_source_right' }, 0)); }
function revoke(row: SourceSurface['rights'][number]) { if (!draft.pending && draft.confirmed && Number.isSafeInteger(row.revision)) void execute(body('revoke', { grant_id: row.grant_id }, row.revision)); }
</script>
<template>
    <section class="bf-card bf-source-rights" data-source-rights-editor>
        <h2>{{ current.label }}</h2><code>{{ current.source_id }}</code>
        <p>{{ current.family }} · {{ current.namespace }}</p><p class="bf-wrap">{{ current.resource }} · {{ current.operation }} · {{ current.purpose }}</p>
        <p>{{ current.adopted ? t('إدارة محمية — المسار القديم متقاعد', 'Protected administration — old writer retired') : t('لم ينتقل إلى الإدارة المحمية بعد', 'Not yet under protected administration') }}</p>
        <p>{{ current.status === 'REVOKED' ? t('المصدر متقاعد. التاريخ محفوظ؛ لا نعتمد صلاحيته للاستعمال.', 'The source is retired. History is retained; usability is not established.') : t('هوية المصدر وقدرة المزود غير متحققتين خارجيًا.', 'Source identity and provider capability are not externally verified.') }}</p>
        <p v-if="!surface.can_command" role="status">{{ t('يلزم تجديد التحقق من هويتك قبل إجراءات الإدارة.', 'Renew authentication before administration commands.') }}</p>
        <p v-if="!exact" role="status">{{ t('تعذر حفظ دقة المعرّفات؛ التعديل متوقف.', 'Identifier precision is unavailable; changes are disabled.') }}</p>
        <template v-if="!refreshing">
            <label class="bf-check"><input v-model="draft.confirmed" type="checkbox" :disabled="!enabled || !!draft.pending" data-source-confirm>{{ t('أؤكد الإجراء المحدد على هذا المصدر وحقوقه فقط.', 'I confirm the selected action on this source and its rights only.') }}</label>
            <button v-if="!current.adopted" type="button" :disabled="!enabled || !draft.confirmed || !!draft.pending" data-source-adopt @click="adopt">{{ t('نقل المصدر إلى الإدارة المحمية', 'Adopt protected source administration') }}</button>
            <template v-else>
                <template v-if="!current.scope_declared && current.status !== 'REVOKED'"><p>{{ t('هذا النطاق مؤهل بسند إدارة محدد، لكنه لم يُصرّح به في سجل المصدر. التصريح لا يثبت الإذن الخارجي أو حق معالجة بيانات عميل.', 'This scope has specific management authority but is not declared in the source registry. Declaration establishes neither external permission nor client-data processing rights.') }}</p><button type="button" data-source-declare-scope :disabled="!enabled || !draft.confirmed || !!draft.pending" @click="declareScope">{{ t('التصريح بالنطاق المؤهل المحدد', 'Declare this qualified scope') }}</button></template>
                <form v-if="current.status !== 'REVOKED' && current.scope_declared" class="bf-form" @submit.prevent="rename">
                    <label for="bf-source-label">{{ t('اسم المصدر المعروض', 'Source display name') }}</label><input id="bf-source-label" v-model="draft.label" maxlength="120" :disabled="!enabled || !!draft.pending">
                    <p v-if="draft.revision !== current.revision" role="status">{{ t('تغير إصدار المصدر. راجع الحالة قبل أمر جديد.', 'The source version changed. Review it before a new command.') }} <button type="button" :disabled="!enabled || !!draft.pending" @click="review">{{ t('مراجعة الحالة الحالية', 'Review current state') }}</button></p>
                    <button type="submit" :disabled="!enabled || !!draft.pending || draft.revision !== current.revision || !draft.label.trim()">{{ t('حفظ اسم المصدر', 'Save source name') }}</button>
                </form>
                <h3>{{ t('حقوقك على النطاق التقني المحدد', 'Your rights on this exact technical scope') }}</h3>
                <p>{{ t('هذه حقوق تقنية مستقلة. لا يتيح إصدارها بيانات عميل أو نشاط، ولا يؤكد تصريح مزود الخدمة.', 'These are independent technical rights. Issuing them provides no client or business data and does not confirm provider permission.') }}</p>
                <p v-if="surface.rights_more" role="status">{{ t('أحدث 64 حقًا فقط؛ القائمة ليست كاملة.', 'Latest 64 rights only; this is not a complete inventory.') }}</p>
                <article v-for="row in surface.rights" :key="row.grant_id" class="bf-issued" :data-source-grant="row.grant_id" :data-source-grant-state="row.recorded_status"><strong>{{ row.action }}</strong><code>{{ row.grant_id }}</code><p>{{ row.recorded_status === 'revoked' ? t('مسحوب — لا يُعاد إحياؤه', 'Revoked — never revived') : t('مسجل كقائم — الصلاحية تُفحص عند الاستعمال', 'Recorded active — eligibility is checked at use') }}</p><p v-if="row.expires_at">{{ t('ينتهي', 'Expires') }}: {{ row.expires_at }}</p><button v-if="row.recorded_status === 'active' && row.grant_id !== activeManager" type="button" :disabled="!enabled || !draft.confirmed || !!draft.pending || !Number.isSafeInteger(row.revision)" @click="revoke(row)">{{ t('سحب هذا الحق', 'Withdraw this right') }}</button></article>
                <form v-if="current.status !== 'REVOKED' && current.scope_declared" class="bf-form" @submit.prevent="issue"><label for="bf-source-action">{{ t('إصدار حق محدد لحسابك المؤهل', 'Issue a specific right to your qualified account') }}</label><select id="bf-source-action" v-model="draft.action" :disabled="!enabled || !!draft.pending"><option v-for="action in actions" :key="action" :value="action">{{ action }}</option></select><p v-if="!issueAvailable" role="status">{{ t('يوجد حق مسجل قائم أو أن القائمة جزئية. راجع الحقوق قبل إصدار مستقل.', 'An active right is recorded or the inventory is partial. Review rights before independent issuance.') }}</p><button type="submit" :disabled="!enabled || !!draft.pending || !issueAvailable">{{ t('إصدار حق مستقل', 'Issue independent right') }}</button></form>
                <button v-if="current.status !== 'REVOKED' && current.scope_declared" type="button" :disabled="!enabled || !draft.confirmed || !!draft.pending" data-source-retire @click="retire">{{ t('تقاعد المصدر مع حفظ التاريخ', 'Retire source and retain history') }}</button>
            </template>
        </template>
        <p v-if="draft.acknowledged" role="status" data-source-rights-confirmed>{{ t('تأكد إيصال الأمر. تُقرأ الحالة الحالية بصلاحياتها المستقلة.', 'Command receipt confirmed. Current state is read under its independent authority.') }}</p>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
        <button v-if="draft.pending" type="button" :disabled="!enabled" data-source-reconcile @click="execute(draft.pending)">{{ t('التحقق من الأمر الأصلي', 'Reconcile original command') }}</button>
    </section>
</template>
