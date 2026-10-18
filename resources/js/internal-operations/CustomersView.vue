<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import OperationsShell from './OperationsShell.vue';
import { customers, changeCustomerStatus, readCustomers, type CustomerCommand } from './customerState';
import { bootstrap, close, logout, onClose, owned, stamp, TransportError } from '../spa/transport';
import { confirmDeparture } from '../spa/presentation';

type Kind = 'users' | 'workspaces';
type Decision = 'suspend' | 'reactivate';
type Row = { public_id: string; name: string; status: string; revision: number; email?: string; email_verified_at?: string | null; workspace_count?: number; can_reactivate?: boolean; business_type_ar?: string | null; business_type_en?: string | null; owner_name?: string | null; owner_email?: string | null; market_label?: string | null; member_count?: number };
type History = { decision: Decision; reason: string; occurred_at: string; actor_name: string };
type Details = { public_id?: string; name: string; email?: string; workspaces?: { public_id: string; name: string; status: string; role: string }[]; members?: { name: string; email: string; role: string; status: string }[]; history: History[] };
type Payload = CustomerCommand;
const props = computed(() => customers.value! as { filters: { kind: Kind; search: string; status: string; page: number }; rows: Row[]; total: number; details: Details | null; freshAuthentication: boolean });
const router = useRouter(), currentRoute = useRoute();
const cancellation = new AbortController(); let active = true;
const acknowledged = ref(false), refreshFailed = ref(false);
const { t, locale } = useI18n({ useScope: 'global' });
const filters = computed(() => props.value.filters), rows = computed(() => props.value.rows), total = computed(() => props.value.total), details = computed(() => props.value.details), freshAuthentication = computed(() => props.value.freshAuthentication);
const search = ref(props.value.filters.search);
const status = ref(props.value.filters.status);
const navigating = ref(false);
const detailToggleTarget = ref<string | null>(null);
const confirmation = ref<{ row: Row; decision: Decision; kind: Kind } | null>(null);
const reason = ref('');
const busy = ref(false);
const frozen = ref<Payload | null>(null);
const uncertain = ref(false);
const error = ref('');
const success = ref('');
const reasonInput = ref<HTMLInputElement | null>(null);
const result = ref<HTMLDivElement | null>(null);
const draftKey = `builderad.customer-status.v1.${bootstrap.identity.binding.principal}`;
const reauthenticating = ref(false);
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
function saveDraft(): boolean {
    if (!frozen.value || !confirmation.value) return true;
    try {
        sessionStorage.setItem(draftKey, JSON.stringify({ version: 1, payload: frozen.value, name: confirmation.value.row.name }));
        return true;
    } catch { error.value = t('customers.storageError'); return false; }
}
function clearDraft() { try { sessionStorage.removeItem(draftKey); } catch {} }
try {
    const stored = sessionStorage.getItem(draftKey);
    if (stored && stored.length <= 4096) {
        const draft = JSON.parse(stored);
        const payload = draft?.payload;
        const validStatuses = payload?.kind === 'users' ? ['pending', 'current', 'disabled'] : ['active', 'suspended'];
        if (draft.version === 1 && payload && ['users', 'workspaces'].includes(payload.kind) && ['suspend', 'reactivate'].includes(payload.decision)
            && typeof payload.command_id === 'string' && uuid.test(payload.command_id) && typeof payload.target === 'string' && uuid.test(payload.target)
            && typeof payload.reason === 'string' && payload.reason.trim().length > 0 && payload.reason.length <= 128
            && Number.isSafeInteger(payload.expected_revision) && payload.expected_revision >= 0 && validStatuses.includes(payload.expected_status)
            && (payload.decision === 'reactivate' ? payload.expected_status === (payload.kind === 'users' ? 'disabled' : 'suspended') : payload.expected_status !== 'disabled' && payload.expected_status !== 'suspended')
            && typeof draft.name === 'string' && draft.name.length > 0 && draft.name.length <= 255) {
            frozen.value = { command_id: payload.command_id, kind: payload.kind, target: payload.target, decision: payload.decision, reason: payload.reason, expected_status: payload.expected_status as Payload['expected_status'], expected_revision: payload.expected_revision };
            confirmation.value = { kind: payload.kind, decision: payload.decision, row: { public_id: payload.target, name: draft.name, status: payload.expected_status, revision: payload.expected_revision } };
            reason.value = payload.reason; uncertain.value = true; error.value = t('customers.uncertainError');
        }
    }
} catch { error.value = t('customers.storageError'); }
const locked = computed(() => busy.value || uncertain.value || refreshFailed.value);
const pages = computed(() => Math.max(1, Math.ceil(props.value.total / 25)));
const statusChoices = computed(() => props.value.filters.kind === 'users' ? ['pending', 'current', 'disabled'] : ['active', 'suspended', 'archived']);
function statusLabel(value: string) { return t(`customers.status.${value}`, t('customers.unknownStatus')); }
function roleLabel(value: string) { return t(`customers.role.${value}`, t('customers.member')); }
function decisionFor(row: Row): Decision | null {
    if (props.value.filters.kind === 'users') return row.status === 'pending' || row.status === 'current' ? 'suspend' : row.status === 'disabled' && row.can_reactivate ? 'reactivate' : null;
    return row.status === 'active' ? 'suspend' : row.status === 'suspended' ? 'reactivate' : null;
}
function load(kind = props.value.filters.kind, page = 1, selected?: string, applyFilters = false) {
    if (locked.value) return;
    confirmation.value = null;
    error.value = '';
    const changingKind = kind !== props.value.filters.kind;
    if (changingKind) { search.value = ''; status.value = ''; }
    void router.push({ path: '/internal/administration/customers', query: { kind, search: changingKind ? '' : applyFilters ? search.value : props.value.filters.search, status: changingKind ? '' : applyFilters ? status.value : props.value.filters.status, page, ...(selected ? { selected } : {}) } });
}
function toggleDetails(row: Row) {
    if (locked.value || navigating.value) return;
    detailToggleTarget.value = row.public_id;
    load(props.value.filters.kind, props.value.filters.page, props.value.details?.public_id === row.public_id ? undefined : row.public_id);
}
async function confirm(row: Row) {
    const decision = decisionFor(row);
    if (!decision || locked.value) return;
    error.value = ''; success.value = ''; reason.value = ''; frozen.value = null;
    confirmation.value = { row, decision, kind: props.value.filters.kind };
    await nextTick(); reasonInput.value?.focus();
}
async function submit() {
    if (busy.value || !props.value.freshAuthentication || !confirmation.value) return;
    if (!frozen.value) {
        const selected = confirmation.value;
        if (!reason.value.trim()) { reasonInput.value?.focus(); return; }
        frozen.value = { command_id: crypto.randomUUID(), kind: selected.kind, target: selected.row.public_id, decision: selected.decision, reason: reason.value.trim(), expected_status: selected.row.status as Payload['expected_status'], expected_revision: selected.row.revision };
    }
    if (!saveDraft()) return;
    const alreadyUncertain = uncertain.value;
    const captured = stamp();
    busy.value = true; error.value = ''; success.value = ''; acknowledged.value = false;
    try {
        await changeCustomerStatus(frozen.value, cancellation.signal);
        if (!active || !owned(captured)) return;
        acknowledged.value = true;
        success.value = t(`customers.${frozen.value.decision}Success`);
        clearDraft(); frozen.value = null; uncertain.value = false; confirmation.value = null;
        refreshFailed.value = !(await readCustomers(currentRoute.query as Parameters<typeof readCustomers>[0], false, true));
    } catch (failure) {
        if (!active || !owned(captured)) return;
        const code = failure instanceof TransportError ? failure.status : undefined;
        if (code === 403) { close(); return; }
        if (alreadyUncertain && (code === 401 || code === 403 || code === 419)) {
            uncertain.value = true; error.value = t('customers.reauthenticateUncertain');
        } else if (code === 403 || code === 409 || code === 422) {
            error.value = t(code === 403 ? 'customers.authorityError' : code === 409 ? 'customers.conflictError' : 'customers.validationError');
            clearDraft(); frozen.value = null; uncertain.value = false;
            if (code === 409) confirmation.value = null;
        } else {
            uncertain.value = true; error.value = t('customers.uncertainError');
        }
    } finally {
        busy.value = false; await nextTick(); result.value?.focus();
    }
}
async function review() {
    if (busy.value) return;
    refreshFailed.value = !(await readCustomers(currentRoute.query as Parameters<typeof readCustomers>[0], false, true));
}
function signInAgain() {
    if (busy.value || reauthenticating.value || !saveDraft()) return;
    reauthenticating.value = true;
    void logout();
}
const canLeave = () => !locked.value || reauthenticating.value;
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { if (locked.value && !reauthenticating.value) { event.preventDefault(); event.returnValue = ''; } }
window.addEventListener('beforeunload', beforeUnload);
const clearPrivate = () => { confirmation.value = null; frozen.value = null; reason.value = ''; search.value = ''; error.value = ''; success.value = ''; };
const closures = onClose(clearPrivate);
onBeforeUnmount(() => { active = false; cancellation.abort(); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; closures.delete(clearPrivate); window.removeEventListener('beforeunload', beforeUnload); });

function date(value: string) {
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}
</script>

<template>
    <OperationsShell>
        <template #header><p class="admin-eyebrow">{{ t('admin.operatingWorkspace') }}</p><h1>{{ t('customers.title') }}</h1><p class="admin-heading-description">{{ t('customers.intro') }}</p></template>
        <div data-customers class="admin-administration customer-administration" :aria-busy="busy || navigating">
            <p v-if="acknowledged" role="status" data-customer-ack>{{ locale === 'ar' ? 'وصل إقرار نجاح الأمر من الخادم.' : 'The server acknowledged command success.' }}</p>
            <div v-if="refreshFailed" role="alert" data-customer-readback>{{ locale === 'ar' ? 'تعذر تحديث العرض. إقرار الأمر مستقل عن هذه القراءة.' : 'Readback failed. The command acknowledgement is separate.' }} <button type="button" @click="review">{{ locale === 'ar' ? 'تحديث القراءة فقط' : 'Refresh read only' }}</button></div>
            <nav class="customer-tabs" :aria-label="t('customers.categories')">
                <button v-for="kind in (['users', 'workspaces'] as const)" :key="kind" type="button" class="customer-tab" :aria-current="filters.kind === kind ? 'page' : undefined" :disabled="locked || navigating" @click="load(kind)">{{ t(`customers.${kind}`) }}</button>
            </nav>
            <div v-if="!freshAuthentication || uncertain" class="customer-notice"><p>{{ t(uncertain ? 'customers.reauthenticateUncertain' : 'customers.freshRequired') }}</p><button type="button" class="admin-action" :disabled="busy || reauthenticating" @click="signInAgain">{{ t('customers.login') }}</button></div>
            <div ref="result" tabindex="-1" class="customer-result"><p v-if="error" role="alert" class="admin-error admin-action-error">{{ error }}</p><p v-if="success" role="status" class="admin-success">{{ success }}</p></div>
            <section v-if="confirmation" class="customer-confirmation" aria-labelledby="customer-confirm-title">
                <h2 id="customer-confirm-title">{{ t(`customers.${confirmation.decision}`) }} — {{ confirmation.row.name }}</h2>
                <p class="admin-description">{{ t(confirmation.kind === 'users' ? confirmation.decision === 'suspend' ? 'customers.suspendUserHelp' : 'customers.reactivateUserHelp' : confirmation.decision === 'suspend' ? 'customers.suspendWorkspaceHelp' : 'customers.reactivateWorkspaceHelp') }}</p>
                <form class="admin-form" @submit.prevent="submit">
                    <div class="admin-field"><label for="customer-reason">{{ t('customers.reason') }}</label><input id="customer-reason" ref="reasonInput" v-model="reason" required maxlength="128" :readonly="!!frozen" :disabled="busy" autocomplete="off" /></div>
                    <div class="customer-actions"><button type="submit" class="admin-action" :class="confirmation.decision === 'suspend' ? 'admin-action-danger' : 'admin-action-primary'" :disabled="busy || !freshAuthentication || !reason.trim()">{{ t(busy ? 'customers.saving' : uncertain ? 'customers.retry' : 'customers.confirm') }}</button><button v-if="!uncertain" type="button" class="admin-action" :disabled="busy" @click="confirmation = null">{{ t('customers.cancel') }}</button></div>
                </form>
            </section>
            <form class="customer-filters admin-form" @submit.prevent="load(filters.kind, 1, undefined, true)">
                <div class="admin-field"><label for="customer-search">{{ t(filters.kind === 'users' ? 'customers.searchUsers' : 'customers.searchWorkspaces') }}</label><input id="customer-search" v-model="search" type="search" maxlength="120" :disabled="locked" /></div>
                <div class="admin-field"><label for="customer-status">{{ t('customers.statusFilter') }}</label><select id="customer-status" v-model="status" :disabled="locked"><option value="">{{ t('customers.allStatuses') }}</option><option v-for="choice in statusChoices" :key="choice" :value="choice">{{ statusLabel(choice) }}</option></select></div>
                <button type="submit" class="admin-action admin-action-primary" :disabled="locked || navigating">{{ t(navigating ? 'customers.loading' : 'customers.search') }}</button>
            </form>
            <section class="admin-section" :aria-labelledby="'customer-list-title'">
                <header class="admin-section-heading customer-list-heading"><h2 id="customer-list-title">{{ t(`customers.${filters.kind}`) }} <span class="customer-count">{{ total }}</span></h2><p class="admin-description">{{ t('customers.pageCount', { page: filters.page, pages }) }}</p></header>
                <ul class="customer-list">
                    <li v-for="row in rows" :key="row.public_id" class="customer-row">
                        <div class="customer-identity"><h3>{{ row.name }}</h3><template v-if="filters.kind === 'users'"><bdi class="admin-email" dir="ltr">{{ row.email }}</bdi><p class="customer-meta">{{ t(row.email_verified_at ? 'customers.emailVerified' : 'customers.emailPending') }}</p></template><template v-else><p class="customer-meta">{{ (locale === 'ar' ? row.business_type_ar : row.business_type_en) || t('customers.businessNotSet') }}</p><p class="customer-meta">{{ t('customers.owner') }}: {{ row.owner_name || t('customers.ownerUnavailable') }}</p><p class="customer-meta">{{ t('customers.market') }}: {{ row.market_label || t('customers.marketNotSet') }}</p></template></div>
                        <div class="customer-state"><span class="admin-status" :class="`admin-status-${row.status}`">{{ statusLabel(row.status) }}</span><p class="customer-meta">{{ t(filters.kind === 'users' ? 'customers.workspaceCount' : 'customers.memberCount', { count: filters.kind === 'users' ? row.workspace_count : row.member_count }) }}</p><p v-if="filters.kind === 'users' && row.status === 'disabled' && !row.can_reactivate" class="customer-meta">{{ t('customers.reactivationUnavailable') }}</p></div>
                        <div class="customer-actions"><button :id="`customer-toggle-${row.public_id}`" type="button" class="admin-action" :aria-expanded="details?.public_id === row.public_id" :aria-controls="`customer-details-${row.public_id}`" :disabled="locked || navigating" @click="toggleDetails(row)">{{ t(details?.public_id === row.public_id ? 'customers.closeDetails' : 'customers.details') }}</button><button v-if="decisionFor(row)" type="button" class="admin-action" :class="decisionFor(row) === 'suspend' ? 'admin-action-danger' : ''" :disabled="locked || navigating || !freshAuthentication" @click="confirm(row)">{{ t(`customers.${decisionFor(row)}`) }}</button></div>
                        <section v-if="details?.public_id === row.public_id" :id="`customer-details-${row.public_id}`" class="customer-inline-details" :aria-labelledby="`customer-toggle-${row.public_id}`">
                            <h3 class="customer-subheading">{{ t(filters.kind === 'users' ? 'customers.linkedWorkspaces' : 'customers.members') }}</h3>
                            <ul class="customer-detail-list"><li v-for="space in details.workspaces ?? []" :key="space.public_id"><strong>{{ space.name }}</strong><span>{{ roleLabel(space.role) }} · {{ statusLabel(space.status) }}</span></li><li v-for="(member, index) in details.members ?? []" :key="index"><div><strong>{{ member.name }}</strong><bdi dir="ltr" class="admin-email">{{ member.email }}</bdi></div><span>{{ roleLabel(member.role) }} · {{ statusLabel(member.status) }}</span></li><li v-if="!(details.workspaces?.length || details.members?.length)" class="admin-empty">{{ t('customers.noRelated') }}</li></ul>
                            <h3 class="customer-subheading">{{ t('customers.history') }}</h3>
                            <ol class="customer-history"><li v-for="(entry, index) in details.history" :key="index"><div><strong>{{ t(`customers.${entry.decision}`) }}</strong><time :datetime="entry.occurred_at">{{ date(entry.occurred_at) }}</time></div><p>{{ entry.reason }}</p><span>{{ t('customers.performedBy') }}: {{ entry.actor_name }}</span></li><li v-if="!details.history.length" class="admin-empty">{{ t('customers.noHistory') }}</li></ol>
                        </section>
                    </li>
                    <li v-if="!rows.length" class="admin-empty">{{ t('customers.empty') }}</li>
                </ul>
                <nav v-if="pages > 1" class="customer-pagination" :aria-label="t('customers.pagination')"><button type="button" class="admin-action" :disabled="filters.page <= 1 || locked || navigating" @click="load(filters.kind, filters.page - 1)">{{ t('customers.previous') }}</button><span>{{ t('customers.pageCount', { page: filters.page, pages }) }}</span><button type="button" class="admin-action" :disabled="filters.page >= pages || locked || navigating" @click="load(filters.kind, filters.page + 1)">{{ t('customers.next') }}</button></nav>
            </section>
        </div>
    </OperationsShell>
</template>

<style scoped>
.customer-administration { min-inline-size: 0; }
.customer-tabs { display:flex; gap:24px; border-block-end:1px solid var(--admin-line); margin-block-end:22px; }
.customer-tab { padding:12px 2px; border-block-end:3px solid transparent; font-size:15px; font-weight:600; color:var(--admin-muted); }
.customer-tab[aria-current] { border-color:var(--admin-accent); color:var(--admin-ink); }
.customer-notice { margin-block:18px; padding:12px 16px; background:#fff8e7; color:#604910; font-size:14px; line-height:1.8; }
.customer-notice a { text-decoration:underline; font-weight:600; }
.customer-confirmation { margin-block:20px; padding:20px; border:1px solid #b6c3d2; background:#f6f8fb; }
.customer-confirmation h2 { font-size:18px; font-weight:600; margin-block-end:8px; overflow-wrap:anywhere; }
.customer-confirmation .admin-form { max-inline-size:640px; }
.customer-filters { grid-template-columns:minmax(0,1fr) minmax(160px,.5fr) auto; margin-block:20px 28px; }
.customer-list-heading { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.customer-count { margin-inline-start:8px; color:var(--admin-muted); font-size:14px; font-weight:400; }
.customer-list { list-style:none; padding:0; }
.customer-row { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,.9fr) auto; gap:24px; padding:20px 0; border-block-end:1px solid var(--admin-line); align-items:start; }
.customer-row h3 { font-size:15px; font-weight:600; margin-block-end:5px; overflow-wrap:anywhere; }
.customer-inline-details { grid-column:1/-1; min-inline-size:0; padding:4px 20px 18px; border-inline-start:3px solid var(--admin-accent); background:#f6f8fb; }
.customer-identity,.customer-state { min-inline-size:0; }
.customer-meta { font-size:13px; line-height:1.8; color:var(--admin-muted); margin-block-start:5px; overflow-wrap:anywhere; }
.admin-email { display:block; max-inline-size:100%; overflow-wrap:anywhere; }
.customer-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.customer-pagination { display:flex; align-items:center; justify-content:center; flex-wrap:wrap; gap:16px; padding-block:20px; font-size:13px; }
.customer-subheading { margin-block:20px 8px; font-size:15px; font-weight:600; }
.customer-detail-list,.customer-history { list-style:none; padding:0; }
.customer-detail-list li { padding-block:12px; display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; border-block-end:1px solid var(--admin-line); font-size:14px; overflow-wrap:anywhere; }
.customer-detail-list span,.customer-history span,.customer-history time { font-size:13px; color:var(--admin-muted); }
.customer-history li { padding-block:14px; border-block-end:1px solid var(--admin-line); font-size:14px; }
.customer-history li>div { display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px; }
.customer-history p { margin-block:8px; overflow-wrap:anywhere; }
.customer-administration :is(button,input,select,a):focus-visible { outline:2px solid var(--admin-accent); outline-offset:3px; }
.customer-administration button:disabled { opacity:.55; cursor:not-allowed; }
.customer-result:focus { outline:none; }
@media(max-width:850px) { .customer-row { grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:14px; } .customer-row>.customer-actions { grid-column:1/-1; } }
@media(max-width:580px) { .customer-filters { grid-template-columns:1fr; } .customer-filters>button { margin-block-start:0; } .customer-row { grid-template-columns:1fr; } .customer-row>.customer-actions { grid-column:auto; } .customer-confirmation { padding:16px; } .customer-tabs { gap:18px; } }
</style>
