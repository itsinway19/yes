<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { key, linkDrafts, type LinkDraft } from './drafts';
import { writeLink, type LinkCommand, type Surface } from './api';

const props = defineProps<{ surface: Surface; refreshing?: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const metadata = computed(() => props.surface.collaboration);
const options = computed(() => metadata.value.options.filter(option => Number.isSafeInteger(Number(option.grant_id))));
const resourceKey = `${key(props.surface.business.business_public_id, props.surface.authority_selection)}:relationships:1`;
const retained = linkDrafts.get(resourceKey);
if (retained && !options.value.some(option => option.grant_id === retained.selected)) linkDrafts.delete(resourceKey);
const draft = reactive<LinkDraft>(linkDrafts.get(resourceKey) ?? { workspace: '', kind: 'collaboration', selected: options.value.length === 1 ? options.value[0].grant_id : '',
    version: 0, linkRef: null, notice: '', confirmed: false, blocked: false });
linkDrafts.set(resourceKey, draft);
const busy = ref(false);
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
const selected = computed(() => options.value.find(option => option.grant_id === draft.selected));
const canManage = computed(() => !draft.blocked && !!selected.value && !props.refreshing && !busy.value);
const latest = computed(() => metadata.value.relationships.find(row => row.workspace_public_id === draft.workspace && row.kind === draft.kind));
const available = computed(() => metadata.value.workspaces.some(workspace => workspace.public_id === draft.workspace));
const stale = computed(() => !!draft.workspace && (draft.version !== (latest.value?.revision ?? 0) || draft.linkRef !== (latest.value?.link_ref ?? null)));
const canAdd = computed(() => canManage.value && available.value && !stale.value && latest.value?.state !== 'active' && (draft.kind !== 'agency' || selected.value?.allows_agency));
function review() {
    if (draft.pending || busy.value) return;
    draft.version = latest.value?.revision ?? 0; draft.linkRef = latest.value?.link_ref ?? null;
    draft.confirmed = false; draft.notice = '';
}
watch(() => [draft.workspace, draft.kind], review, { flush: 'sync' });
watch(() => props.surface, () => {
    if (draft.selected && !options.value.some(option => option.grant_id === draft.selected)) {
        draft.blocked = true; linkDrafts.delete(resourceKey);
        draft.notice = t('لم يعد التفويض المختار مؤهلًا. لم نستنتج نتيجة لطلب سابق ولم نبدّل سنده.', 'The selected delegation is no longer qualified. No prior outcome or replacement authority has been inferred.');
    }
});
function command(effect: 'add' | 'end', workspace: string, kind: 'collaboration' | 'agency', expected: number): LinkCommand {
    return { command_id: crypto.randomUUID(), workspace_public_id: workspace, kind, effect, expected_revision: expected,
        authority_selection: { ...JSON.parse(JSON.stringify(props.surface.authority_selection)), requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(draft.selected) }] } };
}
async function execute(body: LinkCommand) {
    if (!canManage.value) return;
    draft.pending ??= JSON.parse(JSON.stringify(body));
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as LinkCommand, business = props.surface.business.business_public_id, captured = stamp();
    busy.value = true; draft.notice = ''; draft.confirmed = false;
    try {
        const result = await writeLink(business, frozen);
        if (disposed || !owned(captured)) return;
        if (result.business_public_id !== business || result.workspace_public_id !== frozen.workspace_public_id || result.kind !== (frozen.kind ?? 'collaboration')) throw Error('Relationship acknowledgement target mismatch');
        draft.pending = undefined; draft.workspace = ''; draft.confirmed = true;
        emit('refresh');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 403) {
            draft.blocked = true; linkDrafts.delete(resourceKey);
            draft.notice = t('لم يعد هذا السياق يسمح بإدارة العلاقة. لم نمنح حقًا بديلًا أو نفترض نجاح الطلب.', 'This context no longer permits managing the relationship. No alternative right or successful outcome was inferred.'); emit('refresh');
        } else if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; draft.notice = t('لم يُقبل الطلب. راجع نوع العلاقة والمساحة والتفويض.', 'The request was rejected. Review the relationship, workspace and delegation.');
        } else if (error instanceof TransportError && error.status === 409 && 'error' in error.body && error.body.error === 'business_relation_revision_rejected') {
            // Only the typed pre-effect CAS rejection ends this request. Generic 409/identity conflict
            // is not proof of the original command's outcome and retains the exact pending command.
            draft.pending = undefined; draft.notice = t('رفض الخادم إصدار العلاقة قبل إحداث أثر لهذا الطلب. راجع الحالة الحالية قبل طلب جديد.', 'The server rejected the relationship revision before effect. Review current state before a new command.'); emit('refresh');
        } else {
            draft.notice = t('نتيجة الطلب غير مؤكدة. الطلب الأصلي محفوظ ولا يُعاد تلقائيًا.', 'The outcome is unconfirmed. The original command is retained and never automatically retried.');
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
function add() {
    if (draft.pending) { void execute(draft.pending); return; }
    if (canAdd.value) void execute(command('add', draft.workspace, draft.kind, draft.version));
}
function end(row: Surface['collaboration']['relationships'][number]) {
    if (!canManage.value || draft.pending || row.state !== 'active' || row.kind === 'agency' && !selected.value?.allows_agency) return;
    draft.workspace = row.workspace_public_id; draft.kind = row.kind;
    void execute(command('end', row.workspace_public_id, row.kind, row.revision));
}
</script>

<template>
    <section class="bf-card bf-collaboration" data-business-collaboration>
        <h2>{{ t('الفريق والتعاون', 'Team and collaboration') }}</h2>
        <p>{{ t('اربط هذا النشاط بمساحة تديرها، أو أنهِ العلاقة القائمة. الربط لا يمنح أعضاء المساحة حق قراءة بيانات النشاط أو العميل؛ التفويض وحقوق البيانات مستقلان.', 'Link this business to a workspace you manage, or end its current relationship. Linking does not grant members access to business or client data; delegation and data rights remain independent.') }}</p>
        <p v-if="!options.length || draft.blocked" role="status" data-collaboration-readonly>{{ t('لا يوجد تفويض مؤهل معروض لإدارة علاقات هذا النشاط في السياق الحالي.', 'No qualified delegation for managing these relationships is available in this context.') }}</p>
        <template v-else>
            <p v-if="refreshing" role="status">{{ t('جارٍ التحقق من الحالة الحالية للعلاقات…', 'Checking current relationship state…') }}</p>
            <template v-else>
                <p v-if="!metadata.workspaces.length" role="status">{{ t('لا توجد مساحة مؤهلة معروضة للإدارة هنا. تحتاج صلاحية مستقلة على النشاط والمساحة.', 'No qualified workspace is shown here. Independent business and workspace authority is required.') }}</p>
                <p v-if="metadata.workspace_more" role="status">{{ t('تظهر أول 32 مساحة مؤهلة فقط. هذه ليست قائمة كاملة بكل مساحاتك.', 'Only the first 32 qualified workspaces are shown. This is not a complete inventory.') }}</p>
                <div v-if="metadata.relationships.length" class="bf-relationship-list">
                    <h3>{{ t('حالة العلاقات المعروضة', 'Displayed relationship state') }}</h3>
                    <article v-for="row in metadata.relationships" :key="row.link_ref" class="bf-relationship" :data-relationship-state="row.state" :data-relationship-kind="row.kind">
                        <strong>{{ row.workspace_slug }}</strong><span>{{ row.kind === 'agency' ? t('علاقة وكالة', 'Agency relationship') : t('تعاون', 'Collaboration') }}</span>
                        <span>{{ row.state === 'active' ? t('نشطة', 'Active') : t('منتهية — يحتفظ النظام بتاريخها', 'Ended — its history is retained') }}</span>
                        <button v-if="row.state === 'active'" type="button" :disabled="!canManage || !!draft.pending || row.kind === 'agency' && !selected?.allows_agency" @click="end(row)">{{ t('إنهاء العلاقة', 'End relationship') }}</button>
                    </article>
                </div>
            </template>
            <form class="bf-form" data-collaboration-form @submit.prevent="add">
                <fieldset :disabled="busy || refreshing || !!draft.pending || draft.blocked">
                    <label v-if="options.length > 1" for="bf-link-grant">{{ t('تفويض إدارة العلاقة', 'Relationship delegation') }}<select id="bf-link-grant" v-model="draft.selected"><option value="">{{ t('اختر التفويض', 'Choose delegation') }}</option><option v-for="(option, index) in options" :key="option.grant_id" :value="option.grant_id">{{ t('تفويض', 'Delegation') }} {{ index + 1 }}{{ option.allows_agency ? t(' — يسمح بعلاقة وكالة', ' — allows agency relationships') : t(' — تعاون فقط', ' — collaboration only') }}</option></select></label>
                    <label for="bf-link-workspace">{{ t('معرّف مساحة التعاون', 'Collaboration workspace identifier') }}</label><select id="bf-link-workspace" v-model="draft.workspace"><option value="">{{ t('اختر المساحة المؤهلة', 'Choose a qualified workspace') }}</option><option v-for="workspace in metadata.workspaces" :key="workspace.public_id" :value="workspace.public_id">{{ workspace.slug }}</option></select>
                    <label for="bf-link-kind">{{ t('نوع العلاقة', 'Relationship type') }}</label><select id="bf-link-kind" v-model="draft.kind"><option value="collaboration">{{ t('تعاون', 'Collaboration') }}</option><option v-if="selected?.allows_agency" value="agency">{{ t('علاقة وكالة مفوضة', 'Qualified agency relationship') }}</option></select>
                    <p v-if="selected && !selected.allows_agency">{{ t('هذا التفويض لا يكفي لإنشاء علاقة وكالة. لا تتحول المسودة أو ملكية المساحة إلى حق على عميل.', 'This delegation cannot establish agency authority. A draft or workspace ownership does not confer client rights.') }}</p>
                </fieldset>
                <p v-if="stale && !draft.pending && !refreshing" role="alert" data-collaboration-conflict>{{ t('تغيّرت العلاقة منذ اختيارها. لم نبدّل الإصدار الأصلي تلقائيًا.', 'The relationship changed after selection. Its original revision has not been replaced automatically.') }}<button type="button" :disabled="busy" @click="review">{{ t('اعتماد الحالة المعروضة لطلب جديد', 'Use displayed state for a new request') }}</button></p>
                <p v-if="latest?.state === 'active' && !draft.pending && !refreshing">{{ t('العلاقة المختارة نشطة بالفعل. يمكنك إنهاؤها من حالتها أعلاه.', 'This relationship is already active. You may end it above.') }}</p>
                <p v-if="draft.pending && !busy" role="status" data-collaboration-unknown>{{ t('الطلب السابق غير محسوم. يمكن التحقق بالأمر الأصلي إذا بقي حقه مؤهلًا.', 'The previous request is unconfirmed. It can be reconciled with its original command while its authority remains qualified.') }}</p>
                <p v-if="draft.notice" role="status">{{ draft.notice }}</p><p v-if="draft.confirmed" role="status" data-collaboration-confirmed>{{ t('استُلم تأكيد الطلب من الخادم. تعرض الحالة أعلاه نتيجة إعادة التحقق الحالية.', 'The server acknowledged the command. The state above comes from current qualified readback.') }}</p>
                <button class="bf-primary" :disabled="draft.pending ? !canManage : !canAdd">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Reconcile original command') : t('ربط المساحة بالنشاط', 'Link workspace to business') }}</button>
            </form>
        </template>
    </section>
</template>
