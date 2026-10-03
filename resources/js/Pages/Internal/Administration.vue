<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';

type Person = {
    public_id: string;
    name: string;
    email: string;
    kind: 'founder' | 'employee';
    status: 'pending' | 'active' | 'revoked';
    attested_at: string | null;
    factor_independence_attested_at: string | null;
    email_verified_at: string | null;
    passkey_count: number;
};
type StaffGrant = { id: number; action: string; reason: string; public_id: string; name: string };

const props = defineProps<{
    people: Person[];
    staffGrants: StaffGrant[];
    grantableActions: string[];
    canAttestFactors: boolean;
    permissions: Record<string, boolean>;
    metaOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    googleOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    tiktokOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    xOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    snapchatOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
}>();
const { t, locale } = useI18n({ useScope: 'global' });
const actionLabelKeys: Record<string, string> = {
    'staff.view': 'actionStaffView', 'staff.invite': 'actionStaffInvite',
    'staff.attest': 'actionStaffAttest', 'staff.activate': 'actionStaffActivate',
    'staff.revoke': 'actionStaffRevoke', 'grant.manage': 'actionGrantManage',
    'service.manage': 'actionServiceManage', 'business.manage': 'actionBusinessManage',
};
function actionLabel(action: string) { return t(`admin.${actionLabelKeys[action] ?? 'actionUnknown'}`); }
const invite = useForm({ name: '', email: '' });
const grant = useForm({ staff: '', action: '', reason: '' });
const busy = ref('');
const actionError = ref('');

function inviteEmployee() {
    invite.post(route('internal.staff.invite'), {
        preserveScroll: true,
        onSuccess: () => invite.reset(),
    });
}

function staffAction(person: Person, action: 'attest' | 'activate' | 'revoke' | 'resend-invitation' | 'attest-factors') {
    actionError.value = '';
    busy.value = `${person.public_id}:${action}`;
    router.post(route(`internal.staff.${action}`, person.public_id), {}, {
        preserveScroll: true,
        onError: () => { actionError.value = 'staffActionError'; },
        onFinish: () => { busy.value = ''; },
    });
}

function issueGrant() {
    grant.post(route('internal.grants.store'), {
        preserveScroll: true,
        onSuccess: () => grant.reset(),
    });
}

function revokeGrant(id: number) {
    actionError.value = '';
    busy.value = `grant:${id}`;
    router.delete(route('internal.grants.destroy', id), {
        preserveScroll: true,
        onError: () => { actionError.value = 'grantActionError'; },
        onFinish: () => { busy.value = ''; },
    });
}

const providers = computed(() => [
    { key: 'meta', name: 'Meta', status: props.metaOperatorStatus },
    { key: 'google', name: 'Google Ads', status: props.googleOperatorStatus },
    { key: 'tiktok', name: 'TikTok', status: props.tiktokOperatorStatus },
    { key: 'x', name: 'X', status: props.xOperatorStatus },
    { key: 'snapchat', name: 'Snapchat', status: props.snapchatOperatorStatus },
].filter(provider => provider.status !== null));
const xEnvironmentNames = ['BB2_X_CONSUMER_KEY', 'BB2_X_CONSUMER_SECRET', 'BB2_X_REDIRECT_URI', 'BB2_X_PRIVACY_URL', 'BB2_X_NOTICE_REVISION'];
</script>

<template>
    <Head :title="t('admin.title')" />
    <InternalLayout>
        <template #header>
            <p class="admin-eyebrow">{{ t('admin.operatingWorkspace') }}</p>
            <h1>{{ t('admin.title') }}</h1>
            <p class="admin-heading-description">{{ t('admin.pageIntro') }}</p>
        </template>
        <div class="admin-administration">
            <Link v-if="$page.props.auth.canManageCommercialPreparation" :href="route('internal.commercial-market-preparation.index')" class="admin-action">{{ locale === 'en' ? 'Add a commercial market' : 'إضافة سوق تجاري' }}</Link>
            <p v-if="actionError" role="alert" class="admin-error admin-action-error">{{ t(`admin.${actionError}`) }}</p>
            <section class="admin-section" aria-labelledby="staff-title">
                <header class="admin-section-heading"><div><h2 id="staff-title">{{ t('admin.staffTitle') }}</h2><p class="admin-description">{{ t('admin.staffContext') }}</p></div></header>
                <details v-if="props.permissions['staff.invite']" class="admin-disclosure admin-form-disclosure">
                    <summary>{{ t('admin.inviteTitle') }}<span class="admin-disclosure-hint">{{ t('admin.newEmployee') }}</span></summary>
                    <div class="admin-disclosure-body">
                        <p class="admin-description">{{ t('admin.inviteContext') }}</p>
                        <form class="admin-form admin-invite-form" @submit.prevent="inviteEmployee">
                            <div class="admin-field"><label for="staff-name">{{ t('admin.name') }}</label><input id="staff-name" v-model="invite.name" required maxlength="255" autocomplete="name" :aria-invalid="!!invite.errors.name" :aria-describedby="invite.errors.name ? 'staff-name-error' : undefined" /><p v-if="invite.errors.name" id="staff-name-error" role="alert" class="admin-error">{{ invite.errors.name }}</p></div>
                            <div class="admin-field"><label for="staff-email">{{ t('admin.email') }}</label><input id="staff-email" v-model="invite.email" type="email" required maxlength="255" dir="ltr" autocomplete="email" :aria-invalid="!!invite.errors.email" :aria-describedby="invite.errors.email ? 'staff-email-error' : undefined" /><p v-if="invite.errors.email" id="staff-email-error" role="alert" class="admin-error">{{ invite.errors.email }}</p></div>
                            <button type="submit" :disabled="invite.processing" class="admin-action admin-action-primary">{{ t(invite.processing ? 'admin.sending' : 'admin.sendInvite') }}</button>
                        </form>
                        <p v-if="invite.recentlySuccessful" role="status" class="admin-success">{{ t('admin.inviteSuccess') }}</p>
                    </div>
                </details>
                <div class="admin-entity-head" aria-hidden="true"><span>{{ t('admin.identity') }}</span><span>{{ t('admin.eligibility') }}</span><span>{{ t('admin.authentication') }}</span><span>{{ t('admin.actions') }}</span></div>
                <ul class="admin-entity-list" :aria-label="t('admin.staffList')">
                    <li v-for="person in people" :key="person.public_id" class="admin-entity-row">
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
                <Link v-if="props.permissions['service.manage']" :href="route('internal.services.index')" class="admin-text-link">{{ t('admin.manageServices') }} <span aria-hidden="true">↗</span></Link>
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
            <Link v-else-if="props.permissions['service.manage']" :href="route('internal.services.index')" class="admin-text-link">{{ t('admin.manageServices') }}</Link>
        </div>
    </InternalLayout>
</template>

<style scoped>.admin-provider-name{display:flex;align-items:center;gap:10px}</style>
