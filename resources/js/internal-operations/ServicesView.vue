<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import type { components } from '../api/generated/application';
import OperationsShell from './OperationsShell.vue';
import { locale } from '../spa/locale';
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
import { services as resource, serviceOutcome as outcome, readServices } from './serviceState';
import * as commands from './serviceApi';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
import { confirmDeparture } from '../spa/presentation';
const services = computed(() => resource.value!.services), scopes = computed(() => resource.value!.scopes), grants = computed(() => resource.value!.grants);
type Scope = components['schemas']['InternalServicesData']['scopes'][number];
type Credential = components['schemas']['InternalServiceCredential'];
const newCredential = shallowRef<Credential | null>(null);
const create = reactive({ name: '', errors: {} as Record<string, string>, processing: false });
const grant = reactive({ service: '', workspace: '', reason: '', errors: {} as Record<string, string>, processing: false });
const busy = ref(''), error = ref(''), readbackFailed = ref(false), reviewed = ref(false);
const locked = computed(() => !!busy.value || outcome.value === 'unknown' || readbackFailed.value);
const cancellation = new AbortController(); let active = true;
function clearSecret() { newCredential.value = null; }
const canLeave = () => !['pending', 'unknown'].includes(outcome.value) || confirm('يوجد أمر غير محسوم. المغادرة لا تعيد تنفيذه. هل تريد المتابعة؟');
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { clearSecret(); if (['pending', 'unknown'].includes(outcome.value)) { event.preventDefault(); event.returnValue = ''; } }
function visibility() { if (document.visibilityState !== 'visible') clearSecret(); }
window.addEventListener('beforeunload', beforeUnload); window.addEventListener('pagehide', clearSecret); document.addEventListener('visibilitychange', visibility);
const clearPrivate = () => { clearSecret(); create.name = ''; grant.service = ''; grant.workspace = ''; grant.reason = ''; error.value = ''; };
const closures = onClose(clearPrivate);
onBeforeUnmount(() => { active = false; cancellation.abort(); clearPrivate(); if (outcome.value === 'pending') outcome.value = 'unknown'; closures.delete(clearPrivate); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); window.removeEventListener('pagehide', clearSecret); document.removeEventListener('visibilitychange', visibility); });
async function review() {
    if (busy.value) return;
    clearSecret(); const value = await readServices(false, true);
    if (active) { readbackFailed.value = !value; reviewed.value = !!value; if (value && outcome.value === 'unknown') outcome.value = ''; }
}
async function perform(key: string, operation: () => Promise<Credential | components['schemas']['InternalOperationAcknowledgement']>, acknowledged?: () => void) {
    if (locked.value) return;
    clearSecret(); const captured = stamp(); busy.value = key; outcome.value = 'pending'; error.value = ''; reviewed.value = false; create.errors = {}; grant.errors = {};
    try {
        let result: Credential | components['schemas']['InternalOperationAcknowledgement'] | undefined = await operation();
        if (!active || !owned(captured)) { result = undefined; return; }
        outcome.value = 'acknowledged';
        if ('credential' in result) newCredential.value = result;
        result = undefined; acknowledged?.();
        readbackFailed.value = !(await readServices(false, true));
    } catch (failure) {
        if (!active || !owned(captured)) return;
        if (failure instanceof TransportError && [403, 404, 409, 422, 429].includes(failure.status)) {
            outcome.value = 'rejected'; error.value = failure.body.message ?? 'رفض الخادم الأمر.';
            const errors = Object.fromEntries(Object.entries(failure.body.errors ?? {}).map(([field, messages]) => [field, messages[0]])); create.errors = errors; grant.errors = errors;
            if (failure.status === 403) close();
        } else outcome.value = 'unknown';
    } finally { if (active) busy.value = ''; }
}
async function createService() { create.processing = true; try { await perform('create', () => commands.createService({ name: create.name }, cancellation.signal), () => { create.name = ''; }); } finally { create.processing = false; } }
const action = (service: string, kind: 'rotate' | 'revoke') => perform(service, () => kind === 'rotate' ? commands.rotateService(service, cancellation.signal) : commands.revokeService(service, cancellation.signal));
async function issueGrant() { grant.processing = true; try { await perform('grant', () => commands.issueServiceGrant({ service: grant.service, workspace: grant.workspace, reason: grant.reason }, cancellation.signal), () => { grant.service = ''; grant.workspace = ''; grant.reason = ''; }); } finally { grant.processing = false; } }
const revokeGrant = (id: number) => perform('grant:' + id, () => commands.revokeServiceGrant(id, cancellation.signal));
function selectScope(scope: Scope) { grant.service = scope.service_public_id; grant.workspace = scope.workspace_public_id; }
</script>
<template>
    <OperationsShell>
        <template #header>
            <p class="admin-eyebrow">{{ copy('الدليل والخدمات', 'Catalog and services') }}</p>
            <h1>{{ copy('هويات الخدمات', 'Service identities') }}</h1>
            <p>{{ copy('أنشئ هويات مستقلة للخدمات، وأدر اعتمادها ونطاقات الوصول الممنوحة لها.', 'Create independent service identities and manage their credentials and granted access.') }}</p>
        </template>
        <div data-services class="services-page">
            <div v-if="outcome || readbackFailed || reviewed" class="operation-outcome">
                <p v-if="outcome === 'acknowledged'" role="status" data-service-ack>{{ copy('وصل إقرار نجاح الأمر من الخادم.', 'The server acknowledged command success.') }}</p>
                <p v-if="outcome === 'unknown'" role="alert" data-service-unknown>{{ copy('نتيجة الأمر غير مؤكدة. قد يكون السر أُصدر ولم يصل، وقد يكون السر القديم أُبطل بالتدوير. لا استرداد للقيمة ولا إعادة تلقائية. راجع الحالة ثم قرر صراحةً بدء عملية جديدة.', 'The command outcome is unknown. A credential may have been issued without arriving, and rotation may have invalidated the previous credential. Its value cannot be recovered and the command will not replay. Review the state before explicitly starting a new operation.') }}</p>
                <p v-if="readbackFailed" role="alert" data-service-readback>{{ copy('تعذر تحديث القائمة. إقرار نجاح الأمر مستقل عن هذه القراءة.', 'The list could not be refreshed. Command acknowledgement is separate from this read.') }}</p>
                <p v-if="reviewed" role="status">{{ copy('هذه قراءة حالية فقط، وليست إقرارًا بوصول السر السابق.', 'This is a current read, not acknowledgement that the previous credential arrived.') }}</p>
                <button v-if="outcome === 'unknown' || readbackFailed" data-service-review type="button" :disabled="!!busy" @click="review">{{ copy('مراجعة الحالة دون إعادة الأمر', 'Review state without replay') }}</button>
            </div>
            <p v-if="error" role="alert" class="admin-action-error">{{ error }}</p>
            <section v-if="newCredential" data-service-credential class="service-credential" aria-labelledby="credential-title">
                <h2 id="credential-title">{{ copy('اعتماد الخدمة — يظهر مرة واحدة', 'Service credential — shown once') }}</h2>
                <p>{{ copy('احفظ الاعتماد في مخزن أسرار مناسب. لن تُعرض قيمته مجددًا، وتدويره يبطل القيمة السابقة فورًا.', 'Save this credential in a suitable secret store. Its value will not appear again. Rotation immediately invalidates the previous value.') }}</p>
                <p dir="ltr">{{ newCredential.public_id }}</p>
                <code dir="ltr">{{ newCredential.credential }}</code>
                <button type="button" class="admin-action" @click="newCredential = null">{{ copy('إخفاء الاعتماد', 'Hide credential') }}</button>
            </section>
            <fieldset :disabled="locked">
                <section class="admin-section" aria-labelledby="service-create-title">
                    <h2 id="service-create-title">{{ copy('هوية خدمة جديدة', 'New service identity') }}</h2>
                    <p>{{ copy('الهوية وحدها لا تمنح وصولًا إلى أي مساحة. يوافق مالك المساحة على نطاقها، ثم تُصدر الإدارة منحة الفعل.', 'An identity alone grants no workspace access. The workspace owner approves its scope, then administration issues the action grant.') }}</p>
                    <form class="admin-form service-create-form" @submit.prevent="createService">
                        <div class="admin-field"><label for="service-name">{{ copy('اسم الخدمة', 'Service name') }}</label><input id="service-name" v-model="create.name" required minlength="2" maxlength="120" :aria-invalid="!!create.errors.name" :aria-describedby="create.errors.name ? 'service-name-error' : undefined" /><p v-if="create.errors.name" id="service-name-error" role="alert" class="admin-error">{{ create.errors.name }}</p></div>
                        <button type="submit" :disabled="create.processing" class="admin-action admin-action-primary">{{ create.processing ? copy('جارٍ الإنشاء…', 'Creating…') : copy('إنشاء الهوية', 'Create identity') }}</button>
                    </form>
                    <ul class="service-list">
                        <li v-for="service in services" :key="service.public_id" :data-service-id="service.public_id">
                            <div><strong>{{ service.name }}</strong> <span class="admin-status" :class="service.status === 'enabled' ? 'admin-status-active' : 'admin-status-revoked'">{{ service.status === 'enabled' ? copy('نشطة', 'Active') : copy('ملغاة', 'Revoked') }}</span><p dir="ltr">{{ service.public_id }}</p></div>
                            <div v-if="service.status === 'enabled'" class="admin-actions"><button type="button" :disabled="!!busy" class="admin-action" @click="action(service.public_id, 'rotate')">{{ copy('تدوير الاعتماد', 'Rotate credential') }}</button><button type="button" :disabled="!!busy" class="admin-action admin-action-danger" @click="action(service.public_id, 'revoke')">{{ copy('إلغاء الهوية', 'Revoke identity') }}</button></div>
                        </li>
                        <li v-if="!services.length" class="admin-empty">{{ copy('لا توجد خدمات.', 'No services yet.') }}</li>
                    </ul>
                </section>
                <section class="admin-section" aria-labelledby="scopes-title">
                    <h2 id="scopes-title">{{ copy('نطاقات وافق عليها المالكون', 'Owner-approved scopes') }}</h2>
                    <ul class="service-list"><li v-for="scope in scopes" :key="`${scope.service_public_id}:${scope.workspace_public_id}`"><div><strong>{{ scope.service_name }}</strong><p dir="ltr">{{ scope.workspace_public_id }}</p><p class="admin-description">{{ scope.purpose }}</p></div><button type="button" class="admin-action" @click="selectScope(scope)">{{ copy('اختيار للمنحة', 'Select for grant') }}</button></li><li v-if="!scopes.length" class="admin-empty">{{ copy('لم يوافق أي مالك على نطاق خدمة بعد.', 'No owner has approved a service scope yet.') }}</li></ul>
                </section>
                <section class="admin-section" aria-labelledby="service-grants-title">
                    <h2 id="service-grants-title">{{ copy('منحة عرض مساحة للخدمة', 'Workspace view grant') }}</h2>
                    <form class="admin-form service-grant-form" @submit.prevent="issueGrant">
                        <div class="admin-field"><label for="grant-service">{{ copy('الخدمة', 'Service') }}</label><input id="grant-service" v-model="grant.service" required dir="ltr" :aria-invalid="!!grant.errors.service" :aria-describedby="grant.errors.service ? 'service-grant-service-error' : undefined" /><p v-if="grant.errors.service" id="service-grant-service-error" role="alert" class="admin-error">{{ grant.errors.service }}</p></div>
                        <div class="admin-field"><label for="grant-workspace">{{ copy('المساحة', 'Workspace') }}</label><input id="grant-workspace" v-model="grant.workspace" required dir="ltr" :aria-invalid="!!grant.errors.workspace" :aria-describedby="grant.errors.workspace ? 'service-grant-workspace-error' : undefined" /><p v-if="grant.errors.workspace" id="service-grant-workspace-error" role="alert" class="admin-error">{{ grant.errors.workspace }}</p></div>
                        <div class="admin-field"><label for="grant-reason">{{ copy('السبب', 'Reason') }}</label><input id="grant-reason" v-model="grant.reason" required minlength="10" maxlength="128" :aria-invalid="!!grant.errors.reason" :aria-describedby="grant.errors.reason ? 'service-grant-reason-error' : undefined" /><p v-if="grant.errors.reason" id="service-grant-reason-error" role="alert" class="admin-error">{{ grant.errors.reason }}</p></div>
                        <button type="submit" :disabled="grant.processing" class="admin-action admin-action-primary">{{ grant.processing ? copy('جارٍ المنح…', 'Granting…') : copy('إصدار المنحة', 'Issue grant') }}</button>
                    </form>
                    <ul class="service-list"><li v-for="item in grants" :key="item.id"><div><strong>{{ item.service_name }}</strong><p dir="ltr">{{ item.workspace_public_id }}</p><p class="admin-description">{{ item.reason }}</p></div><button type="button" :disabled="!!busy" class="admin-action admin-action-danger" @click="revokeGrant(item.id)">{{ copy('سحب المنحة', 'Revoke grant') }}</button></li><li v-if="!grants.length" class="admin-empty">{{ copy('لا توجد منح خدمة نشطة.', 'No active service grants.') }}</li></ul>
                </section>
            </fieldset>
        </div>
    </OperationsShell>
</template>
