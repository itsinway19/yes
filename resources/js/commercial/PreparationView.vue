<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { preparationData, readPreparation, writePreparation, type PreparationCommand } from './preparationState';
import { bootstrap, close, logout, onClose, owned, stamp, TransportError } from '../spa/transport';
import { locale } from '../spa/locale';
import { confirmDeparture } from '../spa/presentation';
import { parsePreparationAttempt } from './preparationAttempt';

const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const data = computed(() => preparationData.value!);
const identity = ref({ intentId: data.value.intentId, intentBinding: data.value.intentBinding });
const form = reactive({ mode: 'direct', country_code: '', denomination_code: '', administrator: '', label: '', reason: '', expires_at: '' });
const operator = reactive({ approval: '', operator: '', reason: '' });
const delegation = reactive({ market_id: '', operator: '', permission: 'read' as 'read' | 'manage', reason: '', expires_at: '' });
const pending = ref<PreparationCommand>();
const busy = ref(false), outcome = ref<'' | 'pending' | 'acknowledged' | 'unknown' | 'rejected'>('');
const error = ref(''), readbackFailed = ref(false), result = ref<unknown>();
const restorationFailed = ref(false), reviewed = ref(false);
const storageKey = `commercial-preparation-spa:v1:${bootstrap.identity.binding.principal}`;
const cancellation = new AbortController(); let active = true;
const locked = computed(() => busy.value || !!pending.value || readbackFailed.value || restorationFailed.value);
const eligibleApprovals = computed(() => data.value.approvals.filter(approval => approval.active && approval.canAuthorize && !approval.consumed));
const operatorPeople = computed(() => data.value.people.filter(person => person.kind !== 'founder'));
function retain(command: PreparationCommand | undefined) {
    try {
        if (command) sessionStorage.setItem(storageKey, JSON.stringify(command)); else sessionStorage.removeItem(storageKey);
        pending.value = command; return true;
    } catch { error.value = copy('تعذر حفظ الطلب الأصلي؛ لم يُرسل أمر جديد.', 'The original request could not be retained; no new command was sent.'); return false; }
}
try {
    const legacyKey = `commercial-market-approval:v2:${bootstrap.identity.binding.principal}`;
    const current = sessionStorage.getItem(storageKey), saved = current ?? sessionStorage.getItem(legacyKey);
    const candidate = parsePreparationAttempt(saved);
    if (candidate) {
        pending.value = candidate; outcome.value = 'unknown';
        if (!current) { if (!retain(candidate)) restorationFailed.value = true; else sessionStorage.removeItem(legacyKey); }
    }
} catch { restorationFailed.value = true; error.value = copy('تعذر قراءة الطلب المحفوظ. احتُفظ به ولم يُستبدل أو يُرسل.', 'The retained request could not be read. It was preserved, not replaced or sent.'); }
const canLeave = () => !pending.value || confirm(copy('يوجد طلب غير محسوم محفوظ. المغادرة لا تعيد إرساله. متابعة؟', 'An unresolved request is retained. Leaving does not replay it. Continue?'));
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { if (pending.value) { event.preventDefault(); event.returnValue = ''; } }
window.addEventListener('beforeunload', beforeUnload);
const clearPrivate = () => { identity.value = { intentId: null, intentBinding: null }; Object.assign(form, { country_code: '', denomination_code: '', administrator: '', label: '', reason: '', expires_at: '' }); Object.assign(operator, { approval: '', operator: '', reason: '' }); Object.assign(delegation, { market_id: '', operator: '', reason: '', expires_at: '' }); pending.value = undefined; result.value = undefined; error.value = ''; };
const closures = onClose(clearPrivate);
onBeforeUnmount(() => { active = false; cancellation.abort(); clearPrivate(); closures.delete(clearPrivate); window.removeEventListener('beforeunload', beforeUnload); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; });
async function review() {
    if (busy.value) return;
    const captured = stamp(), current = await readPreparation(false, true);
    if (!active || !owned(captured)) return;
    readbackFailed.value = !current;
    reviewed.value = !!current;
}
function archiveUncertainIntent() {
    if (busy.value || !reviewed.value || readbackFailed.value || pending.value?.kind !== 'newIntent'
        || !confirm(copy('حفظ المحاولة غير المحسومة للمراجعة وتحميل الهوية الحالية دون افتراض نجاح الإصدار؟', 'Archive the unresolved attempt and load current identity without assuming issuance succeeded?'))) return;
    try {
        sessionStorage.setItem(`${storageKey}:reviewed:${crypto.randomUUID()}`, JSON.stringify({ command: pending.value, outcome: 'unknown' }));
        if (!retain(undefined)) return;
        identity.value = { intentId: data.value.intentId, intentBinding: data.value.intentBinding };
        outcome.value = 'unknown';
    } catch { error.value = copy('تعذر أرشفة المحاولة؛ بقيت غير محسومة.', 'The attempt could not be archived; it remains unresolved.'); }
}
function reloadDraft() {
    if (locked.value || !confirm(copy('التخلص من المسودة وتحميل هوية العرض الحالية؟', 'Discard the draft and load the current displayed identity?'))) return;
    identity.value = { intentId: data.value.intentId, intentBinding: data.value.intentBinding };
    Object.assign(form, { country_code: '', denomination_code: '', administrator: '', label: '', reason: '', expires_at: '' });
}
async function send(command: PreparationCommand, retry = false) {
    if (busy.value || (!retry && locked.value) || (retry && (command !== pending.value || command.kind === 'newIntent'))) return;
    const frozen = JSON.parse(JSON.stringify(command)) as PreparationCommand;
    if (!retain(frozen)) return;
    const captured = stamp(); busy.value = true; outcome.value = 'pending'; error.value = ''; reviewed.value = false;
    try {
        const acknowledgement = await writePreparation(frozen, cancellation.signal);
        if (!active || !owned(captured)) return;
        outcome.value = 'acknowledged'; result.value = acknowledgement; retain(undefined);
        const current = await readPreparation(false, true);
        if (!active || !owned(captured)) return;
        readbackFailed.value = !current;
        if (current && frozen.kind === 'newIntent') {
            identity.value = { intentId: current.intentId, intentBinding: current.intentBinding };
            Object.assign(form, { country_code: '', denomination_code: '', administrator: '', label: '', reason: '', expires_at: '' });
        }
    } catch (failure) {
        if (!active || !owned(captured)) return;
        const status = failure instanceof TransportError ? failure.status : 0;
        outcome.value = !retry && [403, 409, 422].includes(status) ? 'rejected' : 'unknown';
        if (outcome.value === 'rejected') retain(undefined);
        error.value = status === 422 ? copy('صحح الحقول. رفض الخادم هذا الطلب.', 'Correct the fields. The server rejected this request.') : status === 409 ? copy('تعارض؛ راجع الحالة والإيصال دون استبدال الطلب غير المحسوم.', 'Conflict; review state and receipt without replacing the unresolved request.') : copy('لم يُؤكد التسليم. تحديث الحالة لا يثبت تنفيذ الطلب الأصلي.', 'Delivery is unconfirmed. Reading state does not prove execution of the original request.');
        if ([401, 403, 419].includes(status)) close();
    } finally { if (active) busy.value = false; }
}
function prepare() {
    if (!identity.value.intentId || !identity.value.intentBinding) return;
    const base = { intent_id: identity.value.intentId, intent_binding: identity.value.intentBinding, reason: form.reason };
    void send({ kind: 'prepare', body: form.mode === 'direct' ? { ...base, mode: 'direct', country_code: form.country_code, denomination_code: form.denomination_code }
        : { ...base, administrator: form.administrator, label: form.label, expires_at: new Date(form.expires_at).toISOString() } });
}
</script>

<template>
    <OperationsShell data-commercial-preparation>
        <template #header><h1>{{ copy('تهيئة الأسواق التجارية', 'Commercial market preparation') }}</h1><p>{{ copy('إعدادات BuilderAd التجارية وليست استهداف الحملات. يبقى قرار التنفيذ للخادم.', 'BuilderAd commercial settings, not campaign targeting. The server decides execution authority.') }}</p></template>
        <p v-if="error" role="alert">{{ error }}</p>
        <section v-if="outcome" class="operation-outcome" role="status">
            <p>{{ outcome === 'acknowledged' ? copy('وصل إقرار التنفيذ.', 'Execution acknowledgement received.') : outcome === 'pending' ? copy('جارٍ إرسال الطلب الأصلي…', 'Sending the original request…') : outcome === 'rejected' ? copy('رفض الخادم الطلب.', 'The server rejected the request.') : copy('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', 'Outcome unknown; no automatic replay.') }}</p>
            <p v-if="readbackFailed">{{ copy('تعذر تحديث القراءة؛ لا يعني ذلك فشل الكتابة المقرّة.', 'Readback failed; this does not mean the acknowledged write failed.') }}</p>
            <pre v-if="result">{{ result }}</pre>
            <button :disabled="busy" @click="review">{{ copy('مراجعة الحالة الحالية — ليست إقرارًا', 'Review current state — not an acknowledgement') }}</button>
            <button v-if="pending && pending.kind !== 'newIntent'" :disabled="busy || !data.freshAuthentication" @click="send(pending, true)">{{ copy('إعادة المحاولة صراحة بالطلب نفسه', 'Explicitly retry the same request') }}</button>
            <p v-if="pending?.kind === 'newIntent'">{{ copy('لم يُؤكد إصدار الهوية الجديدة. لا يُعاد الإصدار تلقائيًا؛ راجع الحالة دون افتراض نجاح الطلب.', 'New intent issuance is unconfirmed. It is not replayed automatically; review state without assuming success.') }}</p>
            <button v-if="pending?.kind === 'newIntent' && reviewed" :disabled="busy || readbackFailed" @click="archiveUncertainIntent">{{ copy('أرشفة المحاولة غير المحسومة وتحميل الهوية الحالية', 'Archive unresolved attempt and load current identity') }}</button>
        </section>
        <p v-if="!data.freshAuthentication">{{ copy('يلزم دخول حديث للتنفيذ.', 'Fresh authentication is required to execute.') }} <button :disabled="busy" @click="logout()">{{ copy('تجديد الدخول', 'Renew sign-in') }}</button></p>
        <section v-if="data.canPrepare">
            <h2>{{ copy('إضافة سوق', 'Add market') }}</h2>
            <form data-commercial-prepare @submit.prevent="prepare"><fieldset :disabled="locked">
                <label>{{ copy('الطريقة', 'Workflow') }}<select v-model="form.mode"><option value="direct">{{ copy('مباشرة بصفتي المؤسس', 'Directly as Founder') }}</option><option value="delegated">{{ copy('اعتماد وتفويض مسؤول', 'Approve and delegate administrator') }}</option></select></label>
                <template v-if="form.mode === 'direct'"><label for="preparation-country">{{ copy('الدولة', 'Country') }}</label><select id="preparation-country" v-model="form.country_code" required @change="form.denomination_code = data.countryOptions[form.country_code]?.currency ?? ''"><option value="" /><option v-for="(country, code) in data.countryOptions" :key="code" :value="code">{{ copy(country.ar, country.en) }}</option></select><label for="preparation-currency">{{ copy('العملة', 'Currency') }}</label><select id="preparation-currency" v-model="form.denomination_code" required><option value="" /><option v-for="currency in data.currencyOptions" :key="currency" :value="currency">{{ currency }}</option></select></template>
                <template v-else><label>{{ copy('اسم السوق', 'Market label') }}<input v-model="form.label" required maxlength="120" /></label><label>{{ copy('المسؤول', 'Administrator') }}<select v-model="form.administrator" required><option value="" /><option v-for="person in data.people" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select></label><label>{{ copy('نهاية التفويض', 'Authorization expiry') }}<input v-model="form.expires_at" type="datetime-local" required /></label></template>
                <label>{{ copy('السبب / ملاحظة', 'Reason / note') }}<input v-model="form.reason" :required="form.mode !== 'direct'" maxlength="120" /></label>
                <button :disabled="!data.freshAuthentication" type="submit">{{ copy('إرسال الطلب', 'Submit request') }}</button>
            </fieldset></form>
            <button :disabled="locked" @click="reloadDraft">{{ copy('التخلص من المسودة وتحميل الحالة المعروضة', 'Discard draft and load displayed state') }}</button>
            <button :disabled="locked || !data.freshAuthentication" @click="send({ kind: 'newIntent' })">{{ copy('إصدار Intent تالٍ بعد اكتمال الحالي', 'Issue next intent after current completion') }}</button>
        </section>
        <section v-if="eligibleApprovals.length"><h2>{{ copy('تفويض مشغّل', 'Authorize operator') }}</h2><form @submit.prevent="send({ kind: 'operator', approval: operator.approval, body: { operator: operator.operator, reason: operator.reason } })"><fieldset :disabled="locked"><label>{{ copy('الاعتماد', 'Approval') }}<select v-model="operator.approval" required><option value="" /><option v-for="approval in eligibleApprovals" :key="approval.id" :value="approval.id">{{ approval.label }}</option></select></label><label>{{ copy('المشغّل', 'Operator') }}<select v-model="operator.operator" required><option value="" /><option v-for="person in operatorPeople" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select></label><label>{{ copy('السبب', 'Reason') }}<input v-model="operator.reason" required maxlength="120" /></label><button :disabled="!data.freshAuthentication">{{ copy('تفويض', 'Authorize') }}</button></fieldset></form></section>
        <section v-if="data.canPrepare && data.recordedMarkets.length"><h2>{{ copy('تفويض سوق قائم', 'Delegate existing market') }}</h2><form @submit.prevent="send({ kind: 'delegate', body: { ...delegation, expires_at: new Date(delegation.expires_at).toISOString() } })"><fieldset :disabled="locked"><label>{{ copy('السوق', 'Market') }}<select v-model="delegation.market_id" required><option value="" /><option v-for="market in data.recordedMarkets" :key="market.id" :value="market.id">{{ market.label }}</option></select></label><label>{{ copy('الموظف', 'Employee') }}<select v-model="delegation.operator" required><option value="" /><option v-for="person in data.people.filter(person => person.kind !== 'founder')" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select></label><label>{{ copy('الصلاحية', 'Permission') }}<select v-model="delegation.permission"><option value="read">{{ copy('قراءة', 'Read') }}</option><option value="manage">{{ copy('إدارة', 'Manage') }}</option></select></label><label>{{ copy('السبب', 'Reason') }}<input v-model="delegation.reason" required maxlength="120" /></label><label>{{ copy('نهاية التفويض', 'Authorization expiry') }}<input v-model="delegation.expires_at" type="datetime-local" required /></label><button :disabled="!data.freshAuthentication">{{ copy('تفويض', 'Delegate') }}</button></fieldset></form></section>
        <section><h2>{{ copy('الاعتمادات والحالة — ليست إيصالًا للأمر الأصلي', 'Approvals and state — not an original-command receipt') }}</h2><article v-for="approval in data.approvals" :key="approval.id"><h3>{{ approval.label }}</h3><p>{{ approval.administrator_name }} · {{ approval.reason }} · {{ approval.expires_at }}</p><p>{{ approval.consumed ? copy('سُجل السوق', 'Market recorded') : approval.active ? copy('تفويض نشط', 'Active authorization') : copy('عرض فقط — غير نشط', 'Read only — inactive') }}</p><code>{{ approval.id }} · {{ approval.target_id }}</code></article></section>
    </OperationsShell>
</template>

<style scoped>
section{margin-block:24px}fieldset{display:grid;gap:16px;border:1px solid #d2d8df;padding:20px}label{display:grid;gap:6px}input,select{padding:8px;border:1px solid #aab4bf;border-radius:4px}button{padding:10px;text-decoration:underline}pre,code{white-space:pre-wrap;overflow-wrap:anywhere}
</style>
