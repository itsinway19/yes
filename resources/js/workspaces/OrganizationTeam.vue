<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { locale } from '../spa/locale';
import { conceal, status } from '../spa/presentation';
import { owned, stamp, TransportError } from '../spa/transport';
import { readTeam, writeTeam, teamDrafts, teamKey, type Team, type TeamCommand, type TeamDraft } from './team';
const props = defineProps<{ workspace: string }>();
const organization = props.workspace, router = useRouter();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const draft = reactive<TeamDraft>(teamDrafts.get(teamKey(organization)) ?? { recipient: '', confirmed: false, blocked: false, notice: '' });
draft.uncertain ??= !!draft.pending;
teamDrafts.set(teamKey(organization), draft);
const notice = computed(() => ({
    '': '',
    updated: t('تم تحديث عضوية الفريق. صلاحيات الأنشطة تُحدد لكل ملف بصورة مستقلة.', 'Team membership updated. Business rights remain explicit per file.'),
    invalid: t('رُفض الطلب دون تنفيذ. صحّح بيانات العضوية وأكّدها قبل إرسال طلب جديد.', 'The request was rejected without execution. Correct the membership details and confirm before submitting a new command.'),
    conflict: t('تغيّرت حالة الفريق. تحقّق من العضوية الحالية قبل تعديل الطلب وتأكيده من جديد.', 'The team state changed. Check current membership before editing and confirming a new command.'),
    denied: t('تعذر اعتماد الطلب. راجع حالة العضوية والصلاحية الحالية قبل أي طلب جديد.', 'The request could not be admitted. Review current membership and authority before any new command.'),
    unknown: t('لم نتأكد من النتيجة. تحقّق بالطلب الأصلي نفسه؛ لن يُرسل تلقائيًا.', 'The outcome is unknown. Check with the exact original command; it is never sent automatically.'),
})[draft.notice]);
const team = ref<Team>(), busy = ref(false), unavailable = ref(false), loading = ref(false);
let serial = 0, disposed = false;
async function load() {
    const attempt = ++serial, captured = stamp(); team.value = undefined; unavailable.value = false;
    if (status.value !== 'ready' || organization !== props.workspace) return;
    loading.value = true;
    try {
        const value = await readTeam(organization);
        if (disposed || attempt !== serial || !owned(captured) || status.value !== 'ready' || organization !== props.workspace) return;
        if (value.workspace_public_id !== organization) throw Error('Organization team target mismatch');
        team.value = value;
    } catch { if (!disposed && attempt === serial && owned(captured)) unavailable.value = true; }
    finally { if (!disposed && attempt === serial && owned(captured)) loading.value = false; }
}
watch(() => [props.workspace, status.value], () => { serial++; team.value = undefined; if (status.value === 'ready' && organization === props.workspace) void load(); }, { immediate: true });
onBeforeUnmount(() => { if (draft.pending) draft.uncertain = true; disposed = true; serial++; team.value = undefined; });
async function send(effect?: TeamCommand['effect'], recipient?: string) {
    if (busy.value || draft.blocked || status.value !== 'ready' || organization !== props.workspace || !team.value) return;
    if (!draft.pending) {
        if (!draft.confirmed || !effect || !recipient) return;
        draft.pending = { command_id: crypto.randomUUID(), effect, recipient_public_id: recipient, expected_revision: team.value.revision };
    }
    const original = JSON.parse(JSON.stringify(draft.pending)) as TeamCommand, captured = stamp();
    const reconciling = !!draft.uncertain;
    draft.uncertain = true;
    busy.value = true; draft.notice = '';
    try {
        const receipt = await writeTeam(organization, original);
        if (disposed || !owned(captured) || organization !== props.workspace) return;
        if (receipt.workspace_public_id !== organization || receipt.recipient_public_id !== original.recipient_public_id || receipt.state !== (original.effect === 'add' ? 'active' : 'revoked')) throw Error('Membership receipt mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.confirmed = false; draft.recipient = '';
        if (original.effect === 'leave') { team.value = undefined; conceal(); await router.push('/workspaces'); return; }
        draft.notice = 'updated';
        await load();
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (reconciling && error instanceof TransportError && [409, 422].includes(error.status)) {
            // Rejection of a replay cannot establish that its earlier attempt never committed.
            draft.notice = 'unknown';
        } else if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; draft.uncertain = false; draft.confirmed = false;
            draft.notice = 'invalid';
        } else if (error instanceof TransportError && error.status === 409) {
            draft.pending = undefined; draft.uncertain = false; draft.confirmed = false;
            draft.notice = 'conflict';
            // load clears the stale team immediately; failed or mismatched reads
            // leave editing unavailable, never reusing the rejected revision.
            await load();
        } else if (error instanceof TransportError && error.status === 403) {
            draft.blocked = true;
            draft.notice = 'denied';
        } else draft.notice = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section class="bf-card organization-team" data-organization-team>
        <h2>{{ t('فريق الجهة', 'Organization team') }}</h2>
        <p>{{ t('أضف الموظف إلى فريق الجهة، ثم افتح النشاط الذي سيعمل عليه وحدّد صلاحياته هناك. العضوية لا تمنح حقًا تجاريًا أو حق استعمال بيانات.', 'Add the employee to the organization team, then open the business they will work on and set their permissions there. Membership gives no access to a business or its data.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من الفريق…', 'Checking the team…') }}</p>
        <p v-if="unavailable" role="alert">{{ t('تعذر التحقق من الفريق. لا نفترض أنه فارغ.', 'The team could not be checked. No empty team is inferred.') }}</p>
        <template v-if="team">
            <form v-if="team.can_manage" @submit.prevent="send('add', draft.recipient)">
                <fieldset :disabled="busy || !!draft.pending || draft.blocked">
                    <label>{{ t('معرّف حساب الموظف المسجل', 'Registered employee account ID') }}<input v-model.trim="draft.recipient" required maxlength="36" autocomplete="off" dir="ltr" aria-describedby="organization-recipient-help" data-organization-recipient /></label>
                    <p id="organization-recipient-help">{{ t('اطلب من الموظف نسخ معرّفه من صفحة «أنشطتي»، ضمن «مرجع الحساب».', 'Ask the employee to copy their ID from Account reference in My businesses.') }}</p>
                    <button type="submit" class="workspace-button" :disabled="!draft.confirmed">{{ t('إضافة الموظف', 'Add employee') }}</button>
                </fieldset>
            </form>
            <label v-if="team.can_manage || team.can_leave"><input v-model="draft.confirmed" type="checkbox" :disabled="busy || !!draft.pending || draft.blocked" data-organization-confirm />{{ t('أؤكد تغيير العضوية المحددة دون منح صلاحيات على ملفات الأنشطة.', 'Confirm this membership change without granting Business-file rights.') }}</label>
            <ul data-organization-members>
                <li v-for="member in team.members" :key="member.public_id">
                    <span>{{ member.role === 'owner' ? t('المالك المسؤول', 'Responsible owner') : t('عضو الفريق', 'Team member') }} · {{ member.state === 'active' ? t('عضوية سارية', 'Active membership') : t('عضوية مسحوبة', 'Withdrawn membership') }}</span>
                    <details><summary>{{ t('مرجع الحساب', 'Account reference') }} · <bdi>{{ member.public_id.slice(-8) }}</bdi></summary><code>{{ member.public_id }}</code></details>
                    <button v-if="team.can_manage && member.role !== 'owner' && member.state === 'active'" type="button" class="workspace-button" :disabled="busy || !!draft.pending || draft.blocked || !draft.confirmed" @click="send('remove', member.public_id)">{{ t('سحب العضوية', 'Withdraw membership') }}</button>
                </li>
            </ul>
            <button v-if="team.can_leave" type="button" class="workspace-button" :disabled="busy || !!draft.pending || draft.blocked || !draft.confirmed" @click="send('leave', team.actor_public_id)">{{ t('مغادرة الجهة', 'Leave organization') }}</button>
        </template>
        <p v-if="draft.notice" role="status">{{ notice }}</p>
        <button v-if="draft.pending" type="button" class="workspace-button" :disabled="busy || draft.blocked || !team" data-organization-retry @click="send()">{{ t('التحقق من النتيجة', 'Check the outcome') }}</button>
        <button v-if="unavailable" type="button" class="workspace-button" @click="load">{{ t('إعادة التحقق من الفريق', 'Check the team again') }}</button>
    </section>
</template>
<style scoped>
.organization-team{margin-bottom:20px;overflow-wrap:anywhere}.organization-team .workspace-button{min-height:38px;padding:7px 12px;border:1px solid var(--ad-line);border-radius:6px;background:var(--ad-paper);color:var(--ad-ink);font:inherit;font-size:13px;cursor:pointer}.organization-team .workspace-button:disabled{opacity:.55;cursor:default}.organization-team p{font-size:13px;line-height:1.8}.organization-team form fieldset{border:0;padding:0;display:grid;gap:10px;max-width:540px}.organization-team label{display:flex;gap:8px;align-items:center;font-size:13px}.organization-team form label{display:grid}.organization-team input:not([type=checkbox]){min-height:40px;width:100%;border:1px solid var(--ad-line);border-radius:6px;background:var(--ad-paper);color:var(--ad-ink);padding:8px;font:inherit}.organization-team ul{list-style:none;padding:0;display:grid;gap:12px}.organization-team li{display:flex;flex-wrap:wrap;align-items:center;gap:12px}.organization-team code{font-size:11px;overflow-wrap:anywhere}.organization-team li span{font-size:12px}.organization-team :focus-visible{outline:2px solid var(--ad-accent);outline-offset:3px}@media(max-width:760px){.organization-team .workspace-button{min-height:44px}}
</style>
