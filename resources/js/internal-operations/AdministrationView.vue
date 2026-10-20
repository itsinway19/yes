<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { useI18n } from 'vue-i18n';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import OperationsShell from './OperationsShell.vue';
import { administration, adminOutcome as outcome, invitation as invite, grantDraft as grant, readAdministration } from './adminState';
import * as commands from './adminApi';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
import { confirmDeparture } from '../spa/presentation';

const props = computed(() => administration.value!);
type Person = NonNullable<typeof administration.value>['people'][number];
const people = computed(() => props.value.people), staffGrants = computed(() => props.value.staffGrants);
const grantableActions = computed(() => props.value.grantableActions), canAttestFactors = computed(() => props.value.canAttestFactors);
const { t, locale } = useI18n({ useScope: 'global' });
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const actionLabelKeys: Record<string, string> = { 'staff.view': 'actionStaffView', 'staff.invite': 'actionStaffInvite', 'staff.attest': 'actionStaffAttest', 'staff.activate': 'actionStaffActivate', 'staff.revoke': 'actionStaffRevoke', 'grant.manage': 'actionGrantManage', 'service.manage': 'actionServiceManage', 'business.manage': 'actionBusinessManage' };
function actionLabel(action: string) { return t(`admin.${actionLabelKeys[action] ?? 'actionUnknown'}`); }
const busy = ref(''), actionError = ref(''), refreshFailed = ref(false);
const inviteDisclosure = ref<HTMLDetailsElement | null>(null), inviteName = ref<HTMLInputElement | null>(null);
async function showInvitation() { if (!inviteDisclosure.value) return; inviteDisclosure.value.open = true; await nextTick(); inviteName.value?.focus(); }
const reviewed = ref(false);
const locked = computed(() => !!busy.value || outcome.value === 'unknown' || refreshFailed.value);
const cancellation = new AbortController(); let active = true;
const canLeave = () => !['pending', 'unknown'].includes(outcome.value) || confirm(copy('يوجد أمر غير محسوم. المغادرة لا تعيد تنفيذه. هل تريد المتابعة؟', 'An operation is unresolved. Leaving never replays it. Continue?'));
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const closures = onClose(clearError);
const beforeUnload = (event: BeforeUnloadEvent) => { if (['pending', 'unknown'].includes(outcome.value)) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { active = false; cancellation.abort(); if (outcome.value === 'pending') outcome.value = 'unknown'; if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; closures.delete(clearError); window.removeEventListener('beforeunload', beforeUnload); });
function clearError() { actionError.value = ''; }
async function review() {
    if (busy.value) return;
    const value = await readAdministration(false, true);
    if (active) { refreshFailed.value = !value; reviewed.value = !!value; if (value && outcome.value === 'unknown') outcome.value = ''; }
}
async function perform(key: string, operation: () => Promise<unknown>, acknowledged?: () => void) {
    if (locked.value) return;
    const captured = stamp(); busy.value = key; actionError.value = ''; reviewed.value = false; outcome.value = 'pending';
    try {
        await operation();
        if (!active || !owned(captured)) return;
        outcome.value = 'acknowledged'; acknowledged?.();
        refreshFailed.value = !(await readAdministration(false, true));
    } catch (error) {
        if (!active || !owned(captured)) return;
        if (error instanceof TransportError && [403, 404, 409, 422, 429].includes(error.status)) {
            outcome.value = 'rejected';
            const errors = Object.fromEntries(Object.entries(error.body.errors ?? {}).map(([field, values]) => [field, values[0]]));
            invite.errors = errors; grant.errors = errors;
            actionError.value = error.body.message ?? copy('رفض الخادم الأمر.', 'The server rejected the command.');
            if (error.status === 403) close();
        } else {
            outcome.value = 'unknown';
            actionError.value = copy('قد يكون الأمر نُفّذ دون وصول الإقرار. لن نعيده تلقائيًا؛ راجع الحالة أولًا.', 'The command may have committed without acknowledgement. It will not replay; review state first.');
        }
    } finally { if (active) busy.value = ''; }
}
async function inviteEmployee() {
    invite.processing = true; invite.errors = {};
    try { await perform('invite', () => commands.inviteEmployee({ name: invite.name, email: invite.email }, cancellation.signal), () => { invite.name = ''; invite.email = ''; invite.recentlySuccessful = true; }); }
    finally { invite.processing = false; }
}
const staffAction = (person: Person, action: Parameters<typeof commands.staffAction>[1]) => perform(person.public_id + ':' + action, () => commands.staffAction(person.public_id, action, cancellation.signal));
async function issueGrant() {
    grant.processing = true; grant.errors = {};
    try { await perform('grant', () => commands.issueGrant({ staff: grant.staff, action: grant.action, reason: grant.reason }, cancellation.signal), () => { grant.staff = ''; grant.action = ''; grant.reason = ''; }); }
    finally { grant.processing = false; }
}
const revokeGrant = (id: number) => perform('grant:' + id, () => commands.revokeGrant(id, cancellation.signal));
const providers = computed(() => [
    { key: 'meta', name: 'Meta', status: props.value.metaOperatorStatus },
    { key: 'google', name: 'Google Ads', status: props.value.googleOperatorStatus },
    { key: 'tiktok', name: 'TikTok', status: props.value.tiktokOperatorStatus },
    { key: 'x', name: 'X', status: props.value.xOperatorStatus },
    { key: 'snapchat', name: 'Snapchat', status: props.value.snapchatOperatorStatus },
].filter(provider => provider.status !== null));
const xEnvironmentNames = ['BB2_X_CONSUMER_KEY', 'BB2_X_CONSUMER_SECRET', 'BB2_X_REDIRECT_URI', 'BB2_X_PRIVACY_URL', 'BB2_X_NOTICE_REVISION'];
</script>

<template>
    <OperationsShell>
        <template #header>
            <p class="admin-eyebrow">{{ t('admin.operatingWorkspace') }}</p>
            <h1>{{ t('admin.title') }}</h1>
            <p class="admin-heading-description">{{ t('admin.pageIntro') }}</p>
            <button v-if="props.permissions['staff.invite']" type="button" data-admin-primary-action class="admin-action admin-action-primary ba-admin-heading-action" :disabled="locked" @click="showInvitation">{{ t('admin.inviteTitle') }}</button>
        </template>
        <div v-if="outcome || reviewed || refreshFailed" class="operation-outcome" role="status" data-operation-outcome>
            <p v-if="outcome === 'acknowledged'">{{ copy('وصل إقرار نجاح الأمر من الخادم.', 'The server acknowledged command success.') }}</p>
            <p v-if="outcome === 'unknown'">{{ copy('نتيجة الأمر غير مؤكدة؛ لا إعادة تلقائية.', 'Command outcome is unknown; no automatic replay.') }}</p>
            <p v-if="reviewed">{{ copy('هذه قراءة حالية وليست إقرارًا بالأمر السابق. بدء أمر جديد يحتاج قرارك الصريح.', 'This is a current read, not acknowledgement of the earlier command. A new command requires your explicit decision.') }}</p>
            <p v-if="refreshFailed" role="alert">{{ copy('تعذر تحديث العرض. لا يعني ذلك فشل الكتابة المقر بها؛ القوائم السابقة ليست إثباتًا لنتيجة الأمر.', 'Readback failed. An acknowledged write remains acknowledged; the previous list does not prove the outcome.') }}</p>
            <button type="button" data-operation-review :disabled="!!busy" @click="review">{{ copy('مراجعة الحالة دون إعادة الأمر', 'Review state without replay') }}</button>
        </div>
        <fieldset :disabled="locked" class="admin-administration" data-administration>
            <a v-if="props.canManageCommercialPreparation" href="/internal/administration/commercial-markets" class="admin-action">{{ locale === 'en' ? 'Add a commercial market' : 'إضافة سوق تجاري' }}</a>
            <p v-if="actionError" role="alert" class="admin-error admin-action-error">{{ t(`admin.${actionError}`) }}</p>
            <section class="admin-section" aria-labelledby="staff-title">
                <header class="admin-section-heading"><div><h2 id="staff-title">{{ t('admin.staffTitle') }}</h2><p class="admin-description">{{ t('admin.staffContext') }}</p></div></header>
                <details v-if="props.permissions['staff.invite']" ref="inviteDisclosure" class="admin-disclosure admin-form-disclosure">
                    <summary>{{ t('admin.inviteTitle') }}<span class="admin-disclosure-hint">{{ t('admin.newEmployee') }}</span></summary>
                    <div class="admin-disclosure-body">
                        <p class="admin-description">{{ t('admin.inviteContext') }}</p>
                        <form class="admin-form admin-invite-form" @submit.prevent="inviteEmployee">
                            <div class="admin-field"><label for="staff-name">{{ t('admin.name') }}</label><input id="staff-name" ref="inviteName" v-model="invite.name" required maxlength="255" autocomplete="name" :aria-invalid="!!invite.errors.name" :aria-describedby="invite.errors.name ? 'staff-name-error' : undefined" /><p v-if="invite.errors.name" id="staff-name-error" role="alert" class="admin-error">{{ invite.errors.name }}</p></div>
                            <div class="admin-field"><label for="staff-email">{{ t('admin.email') }}</label><input id="staff-email" v-model="invite.email" type="email" required maxlength="255" dir="ltr" autocomplete="email" :aria-invalid="!!invite.errors.email" :aria-describedby="invite.errors.email ? 'staff-email-error' : undefined" /><p v-if="invite.errors.email" id="staff-email-error" role="alert" class="admin-error">{{ invite.errors.email }}</p></div>
                            <button type="submit" :disabled="invite.processing" class="admin-action admin-action-primary">{{ t(invite.processing ? 'admin.sending' : 'admin.sendInvite') }}</button>
                        </form>
                        <p v-if="invite.recentlySuccessful" role="status" class="admin-success">{{ t('admin.inviteSuccess') }}</p>
                    </div>
                </details>
                <div class="admin-entity-head" aria-hidden="true"><span>{{ t('admin.identity') }}</span><span>{{ t('admin.eligibility') }}</span><span>{{ t('admin.authentication') }}</span><span>{{ t('admin.actions') }}</span></div>
                <ul class="admin-entity-list" :aria-label="t('admin.staffList')">
                    <li v-for="person in people" :key="person.public_id" :data-person-id="person.public_id" class="admin-entity-row">
                        <div class="admin-identity"><h3>{{ person.name }}</h3><bdi dir="ltr" class="admin-email">{{ person.email }}</bdi><span class="admin-kind">{{ t(person.kind === 'founder' ? 'admin.founder' : 'admin.employee') }}</span><details class="admin-detail"><summary>{{ t('admin.recordId') }}</summary><code dir="ltr">{{ person.public_id }}</code></details></div>
                        <div class="admin-eligibility"><span class="admin-mobile-label">{{ t('admin.eligibility') }}</span><span class="admin-status" :class="`admin-status-${person.status}`">{{ t(`admin.status.${person.status}`) }}</span><p v-if="person.status === 'pending'" class="admin-evidence">{{ t('admin.attestation') }}: {{ t(person.attested_at ? 'admin.attested' : 'admin.pending') }}</p></div>
                        <div class="admin-authentication"><span class="admin-mobile-label">{{ t('admin.authentication') }}</span><template v-if="person.status === 'pending'"><p class="admin-evidence">{{ t('admin.email') }}: <strong>{{ t(person.email_verified_at ? 'admin.verified' : 'admin.pending') }}</strong></p><p class="admin-evidence">{{ t('admin.passkeys') }}: <strong>{{ person.passkey_count }}</strong></p></template><template v-else-if="person.status === 'active' && person.kind === 'employee'"><p class="admin-evidence">{{ t('admin.passkeys') }}: <strong>{{ person.passkey_count }}</strong></p><p class="admin-evidence">{{ t('admin.factorIndependence') }}: <strong>{{ t(person.factor_independence_attested_at ? 'admin.attested' : 'admin.notAttested') }}</strong></p></template></div>
                        <div class="admin-person-actions"><div v-if="person.kind === 'employee'" class="admin-actions">
                            <button v-if="props.permissions['staff.invite'] && person.status === 'pending'" type="button" :disabled="!!busy" class="admin-action" @click="staffAction(person, 'resend-invitation')">{{ t('admin.resendInvitation') }}</button>
                            <button v-if="props.permissions['staff.attest'] && person.status === 'pending' && !person.attested_at" type="button" :disabled="!person.email_verified_at || !!busy" class="admin-action" @click="staffAction(person, 'attest')">{{ t('admin.attest') }}</button>
                            <button v-if="props.permissions['staff.activate'] && person.status === 'pending'" type="button" :disabled="!person.email_verified_at || !person.attested_at || person.passkey_count < 1 || !!busy" class="admin-action admin-action-primary" @click="staffAction(person, 'activate')">{{ t('admin.activate') }}</button>
                            <button v-if="props.permissions['staff.revoke'] && person.status === 'active'" type="button" :disabled="!!busy" class="admin-action admin-action-danger" @click="staffAction(person, 'revoke')">{{ t('admin.revokeEligibility') }}</button>
                            <button v-if="canAttestFactors && props.permissions['grant.manage'] && person.status === 'active' && person.passkey_count >= 2 && !person.factor_independence_attested_at" type="button" :disabled="!!busy" class="admin-action" @click="staffAction(person, 'attest-factors')">{{ t('admin.attestFactors') }}</button>
                        </div></div>
                    </li>
                    <li v-if="!people.length" class="admin-empty">{{ t('admin.noStaff') }}</li>
                </ul>
            </section>
            <section v-if="props.permissions['grant.manage']" class="admin-section" aria-labelledby="grants-title">
                <header class="admin-section-heading"><div><h2 id="grants-title">{{ t('admin.grantsTitle') }}</h2><p class="admin-description">{{ t('admin.grantsContext') }}</p></div></header>
                <details class="admin-disclosure admin-form-disclosure"><summary>{{ t('admin.issueGrant') }}<span class="admin-disclosure-hint">{{ t('admin.namedEmployee') }}</span></summary><div class="admin-disclosure-body">
                    <p class="admin-description">{{ locale === 'ar' ? 'لمراجعة أنواع النشاط، امنح «إدارة أنواع النشاط» لموظف نشط من هذا النموذج. إن لم يوجد موظف نشط، أكمل دعوته وتحقق أهليته أولًا. المنحة لا تُمنح للنفس أو لحساب مؤسس.' : 'To review business types, grant “Manage business types” to an active employee using this form. If none exists, complete their invitation and eligibility first. Grants cannot be issued to yourself or a founder account.' }}</p><form class="admin-form admin-grant-form" @submit.prevent="issueGrant">
                        <div class="admin-field"><label for="grant-staff">{{ t('admin.employee') }}</label><select id="grant-staff" v-model="grant.staff" required :aria-invalid="!!grant.errors.staff" :aria-describedby="grant.errors.staff ? 'grant-staff-error' : undefined"><option value="">{{ t('admin.chooseStaff') }}</option><option v-for="person in people.filter(p => p.kind === 'employee' && p.status === 'active')" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select><p v-if="grant.errors.staff" id="grant-staff-error" role="alert" class="admin-error">{{ grant.errors.staff }}</p></div>
                        <div class="admin-field"><label for="grant-action">{{ t('admin.action') }}</label><select id="grant-action" v-model="grant.action" required :aria-invalid="!!grant.errors.action" :aria-describedby="grant.errors.action ? 'grant-action-error' : undefined"><option value="">{{ t('admin.chooseAction') }}</option><option v-for="action in grantableActions" :key="action" :value="action">{{ actionLabel(action) }}</option></select><p v-if="grant.errors.action" id="grant-action-error" role="alert" class="admin-error">{{ grant.errors.action }}</p></div>
                        <div class="admin-field"><label for="grant-reason">{{ t('admin.reason') }}</label><input id="grant-reason" v-model="grant.reason" required minlength="10" maxlength="128" :aria-invalid="!!grant.errors.reason" :aria-describedby="grant.errors.reason ? 'grant-reason-error' : undefined" /><p v-if="grant.errors.reason" id="grant-reason-error" role="alert" class="admin-error">{{ grant.errors.reason }}</p></div>
                        <button type="submit" :disabled="grant.processing" class="admin-action admin-action-primary">{{ t(grant.processing ? 'admin.granting' : 'admin.issueGrant') }}</button>
                    </form>
                </div></details>
                <ul class="admin-grant-list"><li v-for="item in staffGrants" :key="item.id" class="admin-grant-row"><div><h3>{{ item.name }}</h3><p>{{ actionLabel(item.action) }}</p><p class="admin-grant-reason">{{ item.reason }}</p><details class="admin-detail"><summary>{{ t('admin.recordId') }}</summary><code dir="ltr">{{ item.public_id }}</code><p><code dir="ltr">{{ item.action }}</code></p></details></div><button type="button" :disabled="!!busy" class="admin-action admin-action-danger" @click="revokeGrant(item.id)">{{ t('admin.revokeGrant') }}</button></li><li v-if="!staffGrants.length" class="admin-empty">{{ t('admin.noGrants') }}</li></ul>
            </section>
            <section v-if="providers.length" class="admin-section admin-providers" aria-labelledby="providers-title">
                <header class="admin-section-heading"><div><h2 id="providers-title">{{ t('admin.providersTitle') }}</h2><p class="admin-description">{{ t('admin.providersContext') }}</p></div></header>
                <a v-if="props.permissions['service.manage']" href="/internal/services" class="admin-text-link">{{ t('admin.manageServices') }} <span aria-hidden="true">↗</span></a>
                <div class="admin-provider-head" aria-hidden="true"><span>{{ t('admin.provider') }}</span><span>{{ t('admin.localApplication') }}</span><span>{{ t('admin.localNotices') }}</span></div>
                <details v-for="provider in providers" :key="provider.key" class="admin-provider-row admin-provider-details" :aria-labelledby="`${provider.key}-operator-title`">
                    <summary class="admin-provider-summary">
                        <span :id="`${provider.key}-operator-title`" class="admin-provider-name"><ProviderBrand :provider="provider.key" :size="32" /><bdi dir="ltr">{{ provider.name }}</bdi></span>
                        <span><span class="admin-mobile-label">{{ t('admin.localApplication') }}</span><span class="admin-local-state">{{ t(provider.status!.application_configured ? (['meta', 'google'].includes(provider.key) ? 'admin.technicallyConfigured' : 'admin.locallyComplete') : 'admin.incomplete') }}</span></span>
                        <span><span class="admin-mobile-label">{{ t('admin.localNotices') }}</span><span class="admin-local-state">{{ t(provider.status!.notice_links_configured ? (['meta', 'google'].includes(provider.key) ? 'admin.technicalNotice' : 'admin.locallyComplete') : 'admin.incomplete') }}</span></span>
                        <span class="admin-provider-trigger">{{ t('admin.configurationDetails') }}</span>
                    </summary>
                    <div class="admin-provider-detail-body"><p class="admin-provider-disclaimer">{{ t(`admin.providers.${provider.key}.disclaimer`) }}</p><dl class="admin-provider-definitions"><div><dt>{{ t(`admin.providers.${provider.key}.application`) }}</dt><dd>{{ t(provider.status!.application_configured ? (['meta', 'google'].includes(provider.key) ? 'admin.technicallyConfigured' : 'admin.locallyComplete') : 'admin.incomplete') }}</dd></div><div><dt>{{ t(`admin.providers.${provider.key}.notice`) }}</dt><dd>{{ t(provider.status!.notice_links_configured ? (['meta', 'google'].includes(provider.key) ? 'admin.technicalNotice' : 'admin.locallyComplete') : 'admin.incomplete') }}</dd></div></dl><p v-if="provider.key !== 'meta'" class="admin-description">{{ t(`admin.providers.${provider.key}.environment`) }}</p><ul v-if="provider.key === 'x'" class="admin-environment-names"><li v-for="name in xEnvironmentNames" :key="name"><code dir="ltr">{{ name }}</code></li></ul><p v-if="provider.key === 'x'" class="admin-description">{{ t('admin.providers.x.deferred') }}</p></div>
                </details>
            </section>
            <a v-else-if="props.permissions['service.manage']" href="/internal/services" class="admin-text-link">{{ t('admin.manageServices') }}</a>
        </fieldset>
    </OperationsShell>
</template>

<style scoped>.admin-provider-name{display:flex;align-items:center;gap:10px}</style>
