<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { internalTeamSurface, writeInternalTeam, type InternalTeamCommand, type InternalTeamSurface, type Surface } from './api';
import { internalTeamDrafts, key, type InternalTeamDraft } from './drafts';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';

const props = defineProps<{ surface: Surface; active: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const draftKey = `${key(props.surface.business.business_public_id, props.surface.authority_selection)}:internal-team:1`;
const draft = reactive<InternalTeamDraft>(internalTeamDrafts.get(draftKey) ?? { workspace: '', recipient: '', confirmed: false, notice: '' });
internalTeamDrafts.set(draftKey, draft);
const data = ref<InternalTeamSurface>(), busy = ref(false), unavailable = ref(false), loading = ref(false);
const teamRead = ref('');
let generation = 0, disposed = false;
const eligible = computed(() => props.surface.collaboration.workspaces);
const selected = computed(() => data.value?.teams.find(team => team.workspace_public_id === draft.workspace));
const canEdit = computed(() => !!data.value?.can_admin && !busy.value && !loading.value && !unavailable.value && props.active);
const uuid = (value: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
async function load() {
    const attempt = ++generation, captured = stamp(); loading.value = true; unavailable.value = false; data.value = undefined;
    try {
        const result = await internalTeamSurface(props.surface.business.business_public_id, props.surface.authority_selection.requirements[0].leaf_grant_id);
        if (disposed || generation !== attempt || !owned(captured) || !props.active) return;
        if (result.business_public_id !== props.surface.business.business_public_id) throw Error('Custody surface mismatch');
        data.value = result;
    } catch { if (!disposed && generation === attempt && owned(captured)) unavailable.value = true; }
    finally { if (!disposed && generation === attempt && owned(captured)) loading.value = false; }
}
watch(() => [props.active, props.surface], () => { generation++; data.value = undefined; if (props.active) void load(); }, { immediate: true });
watch(() => [draft.workspace, draft.recipient], () => { draft.confirmed = false; });
onBeforeUnmount(() => { disposed = true; generation++; });
function prepare(effect: InternalTeamCommand['effect']) {
    if (!canEdit.value || draft.pending || !draft.confirmed || !uuid(draft.workspace)) return;
    if (effect === 'enroll' && (!data.value?.creator_basis || !eligible.value.some(team => team.public_id === draft.workspace))) return;
    if (effect !== 'enroll' && selected.value?.state !== 'active') return;
    if ((effect === 'appoint' || effect === 'remove') && !uuid(draft.recipient)) return;
    const actor = props.surface.delegation.actor_public_id;
    const body: InternalTeamCommand = { command_id: crypto.randomUUID(), workspace_public_id: draft.workspace,
        effect, recipient_public_id: effect === 'end' ? null : effect === 'enroll' ? actor : draft.recipient,
        expected_revision: selected.value?.revision ?? 0, authority_selection: JSON.parse(JSON.stringify(props.surface.authority_selection)) };
    draft.pending = body; void execute();
}
async function execute() {
    if (!draft.pending || busy.value || !canEdit.value) return;
    const command = JSON.parse(JSON.stringify(draft.pending)) as InternalTeamCommand, captured = stamp(), business = props.surface.business.business_public_id;
    busy.value = true; draft.notice = '';
    try {
        const receipt = await writeInternalTeam(business, command);
        if (disposed || !owned(captured)) return;
        if (receipt.business_public_id !== business || receipt.workspace_public_id !== command.workspace_public_id || receipt.recipient_public_id !== command.recipient_public_id) throw Error('Custody acknowledgement mismatch');
        if (command.effect === 'enroll') teamRead.value = receipt.grant_refs['business.context.read'] ?? '';
        draft.pending = undefined; draft.confirmed = false;
        draft.notice = t('تم تأكيد العملية. راجع حالة الفريق الحالية.', 'Operation confirmed. Review the current team state.');
        emit('refresh'); void load();
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && (error.status === 422 || error.status === 409 && 'error' in error.body && error.body.error === 'business_relation_revision_rejected')) {
            draft.pending = undefined; draft.confirmed = false;
            draft.notice = t('رُفض الطلب قبل التنفيذ. حدّث الحالة وراجع اختيارك.', 'The request was rejected before effect. Refresh and review your choice.'); void load();
        } else {
            draft.notice = t('لم تتأكد نتيجة الطلب. احتُفظ بالطلب الأصلي ولا يُعاد تلقائيًا.', 'The outcome is unconfirmed. The original command is retained and is never retried automatically.');
            if (error instanceof TransportError && error.status === 403) { unavailable.value = true; emit('refresh'); }
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>

<template>
    <section class="bf-card" data-internal-client-team>
        <h2>{{ t('فريق إدارة ملف العميل', 'Client file administration team') }}</h2>
        <p>{{ t('شارك إدارة معلومات هذا الملف مع فريق محدد. لا يشمل ذلك تمثيل العميل خارجيًا أو حساباته أو بيانات الاستفسارات والسجلات الخاصة.', 'Share this file’s information management with a specific team. This does not include external client representation, accounts, inquiry data or private records.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من فريق الملف…', 'Checking the file team…') }}</p>
        <p v-if="unavailable" role="alert">{{ t('تعذر تأهيل إدارة الفريق. حدّث الحالة للتحقق من صلاحياتك.', 'Team administration could not be qualified. Refresh to check your authority.') }}</p>
        <button v-if="unavailable" type="button" class="workspace-button" @click="load">{{ t('تحديث الحالة', 'Refresh state') }}</button>
        <template v-if="data">
            <p v-if="!data.can_admin">{{ t('إدارة مسؤولية الملف متاحة للمنشئ المؤهل أو لمسؤول عُيّن صراحةً.', 'File responsibility is managed by its qualified creator or an explicitly appointed custodian.') }}</p>
            <ul><li v-for="team in data.teams" :key="team.link_ref">{{ team.workspace_slug }} — {{ team.state === 'active' ? t('إدارة داخلية مفعّلة', 'Internal administration active') : t('منتهية', 'Ended') }} <button v-if="data.can_admin" type="button" class="workspace-button" :disabled="!!draft.pending" @click="draft.workspace = team.workspace_public_id">{{ t('اختيار الفريق', 'Select team') }}</button></li></ul>
            <form v-if="data.can_admin" class="bf-form" @submit.prevent="prepare('appoint')">
                <fieldset :disabled="!canEdit || !!draft.pending">
                    <label>{{ t('فريق العمل', 'Work team') }}<select v-model="draft.workspace"><option value="">{{ t('اختر الفريق', 'Select a team') }}</option><option v-for="team in data.teams" :key="team.workspace_public_id" :value="team.workspace_public_id">{{ team.workspace_slug }}</option><option v-for="team in eligible.filter(item => !data?.teams.some(row => row.workspace_public_id === item.public_id))" :key="team.public_id" :value="team.public_id">{{ team.slug }}</option></select></label>
                    <label v-if="selected?.state === 'active'">{{ t('معرّف المسؤول الاحتياطي أو البديل', 'Backup or successor account identifier') }}<input v-model.trim="draft.recipient" dir="ltr" autocomplete="off" maxlength="36" /></label>
                    <p>{{ t('عيّن مسؤولًا احتياطيًا قبل مغادرة المنشئ. تعيينه يمنحه مسؤولية مستقلة لإدارة الملف وتوزيع العمل الداخلي. الموظفون العاديون يحصلون على العمليات التي تفوضها لهم فقط من القسم أدناه.', 'Appoint a backup before the creator leaves. Appointment grants independent file administration and internal work assignment. Ordinary staff receive only the operations explicitly delegated below.') }}</p>
                    <label class="bf-confirm"><input v-model="draft.confirmed" type="checkbox" />{{ t('أوافق على العملية المحددة وعلى مشاركة معلومات الملف الحالية ضمن حدود الإدارة الداخلية.', 'I confirm the selected operation and sharing current file information within internal administration.') }}</label>
                    <button v-if="data.creator_basis && selected?.state !== 'active'" type="button" class="workspace-button" :disabled="!draft.confirmed || !draft.workspace" @click="prepare('enroll')">{{ t('تفعيل إدارة الفريق لهذا الملف', 'Enable team administration for this file') }}</button>
                    <template v-if="selected?.state === 'active'"><button type="submit" class="workspace-button" :disabled="!draft.confirmed || !uuid(draft.recipient)">{{ t('تعيين المسؤول صراحةً', 'Appoint custodian explicitly') }}</button><button type="button" class="workspace-button" :disabled="!draft.confirmed || !uuid(draft.recipient)" @click="prepare('remove')">{{ t('سحب مسؤولية هذا الحساب', 'Withdraw this account’s custodianship') }}</button><button type="button" class="workspace-button" :disabled="!draft.confirmed" @click="prepare('end')">{{ t('إنهاء إدارة هذا الفريق للملف', 'End this team’s file administration') }}</button></template>
                </fieldset>
            </form>
        </template>
        <p v-if="draft.notice" role="status">{{ draft.notice }}</p>
        <p v-if="draft.pending" data-internal-team-pending>{{ t('يوجد طلب غير محسوم. تحقّق من نتيجته قبل إنشاء طلب جديد.', 'A command is unresolved. Check its outcome before creating another command.') }} <button type="button" class="workspace-button" :disabled="!canEdit" @click="execute">{{ t('التحقق بالطلب الأصلي', 'Check with original command') }}</button></p>
        <RouterLink v-if="teamRead" :to="`/businesses/${surface.business.business_public_id}?access=${teamRead}`" class="workspace-button" data-open-internal-team>{{ t('فتح الملف ضمن إدارة الفريق لتوزيع الصلاحيات', 'Open the file in team administration to assign rights') }}</RouterLink>
    </section>
</template>
