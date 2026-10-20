<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import type { components } from '../api/generated/application';
import OperationsShell from './OperationsShell.vue';
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
        <template #header><h1 class="text-xl font-semibold text-slate-900">الخدمات المستقلة</h1></template>
        <main data-services dir="rtl" class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <p v-if="outcome === 'acknowledged'" role="status" data-service-ack>وصل إقرار نجاح الأمر من الخادم.</p>
            <p v-if="outcome === 'unknown'" role="alert" data-service-unknown>نتيجة الأمر غير مؤكدة. قد يكون السر أُصدر ولم يصل، وقد يكون السر القديم أُبطل بالتدوير. لا استرداد للقيمة ولا إعادة تلقائية. راجع الحالة ثم قرر صراحةً بدء عملية جديدة.</p>
            <p v-if="readbackFailed" role="alert" data-service-readback>تعذر تحديث القائمة. إقرار نجاح الأمر مستقل عن هذه القراءة.</p>
            <p v-if="reviewed" role="status">هذه قراءة حالية فقط، وليست إقرارًا بوصول السر السابق.</p>
            <button v-if="outcome === 'unknown' || readbackFailed" data-service-review type="button" :disabled="!!busy" @click="review">مراجعة الحالة دون إعادة الأمر</button>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>
            <section data-service-credential v-if="newCredential" class="rounded-2xl border border-amber-300 bg-amber-50 p-6" aria-labelledby="credential-title">
                <h2 id="credential-title" class="font-semibold text-amber-950">اعتماد الخدمة — يظهر مرة واحدة</h2>
                <p class="mt-2 text-sm text-amber-900">احفظ الاعتماد في مخزن أسرار مناسب. لن تُعرض قيمته مجددًا، وتدويره يبطل القيمة السابقة فورًا.</p>
                <p class="mt-3 text-xs text-amber-900" dir="ltr">{{ newCredential.public_id }}</p>
                <code class="mt-2 block break-all rounded-lg bg-white p-3 text-sm text-slate-900" dir="ltr">{{ newCredential.credential }}</code>
                <button type="button" class="mt-3 text-sm font-medium text-amber-900 underline" @click="newCredential = null">إخفاء الاعتماد</button>
            </section>
            <fieldset :disabled="locked"><section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="service-create-title">
                <h2 id="service-create-title" class="text-lg font-semibold text-slate-900">هوية خدمة جديدة</h2>
                <p class="mt-2 text-sm text-slate-600">الهوية وحدها لا تمنح وصولًا إلى أي مساحة. يوافق مالك المساحة على نطاقها، ثم تُصدر الإدارة منحة الفعل.</p>
                <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="createService">
                    <div class="flex-1"><label for="service-name" class="block text-sm font-medium text-slate-700">اسم الخدمة</label><input id="service-name" v-model="create.name" required minlength="2" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="create.errors.name" role="alert" class="mt-1 text-sm text-red-700">{{ create.errors.name }}</p></div>
                    <button type="submit" :disabled="create.processing" class="self-end rounded-lg ba-brand-button px-4 py-2.5 text-sm font-semibold  disabled:opacity-50">{{ create.processing ? 'جارٍ الإنشاء…' : 'إنشاء الهوية' }}</button>
                </form>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100">
                    <li v-for="service in services" :key="service.public_id" :data-service-id="service.public_id" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="font-medium text-slate-900">{{ service.name }} <span class="text-xs text-slate-500">{{ service.status === 'enabled' ? 'نشطة' : 'ملغاة' }}</span></p><p class="mt-1 text-xs text-slate-600" dir="ltr">{{ service.public_id }}</p></div>
                        <div v-if="service.status === 'enabled'" class="flex gap-4"><button type="button" :disabled="!!busy" class="text-sm text-[var(--ba-primary-ink)] underline disabled:opacity-50" @click="action(service.public_id, 'rotate')">تدوير الاعتماد</button><button type="button" :disabled="!!busy" class="text-sm text-red-700 underline disabled:opacity-50" @click="action(service.public_id, 'revoke')">إلغاء الهوية</button></div>
                    </li>
                    <li v-if="!services.length" class="py-4 text-sm text-slate-600">لا توجد خدمات.</li>
                </ul>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="scopes-title">
                <h2 id="scopes-title" class="text-lg font-semibold text-slate-900">نطاقات وافق عليها المالكون</h2>
                <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100"><li v-for="scope in scopes" :key="`${scope.service_public_id}:${scope.workspace_public_id}`" class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ scope.service_name }}</strong><p class="text-xs text-slate-600" dir="ltr">{{ scope.workspace_public_id }}</p><p class="text-slate-600">{{ scope.purpose }}</p></div><button type="button" class="self-start text-[var(--ba-primary-ink)] underline" @click="selectScope(scope)">اختيار للمنحة</button></li><li v-if="!scopes.length" class="py-4 text-sm text-slate-600">لم يوافق أي مالك على نطاق خدمة بعد.</li></ul>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="service-grants-title">
                <h2 id="service-grants-title" class="text-lg font-semibold text-slate-900">منحة عرض مساحة للخدمة</h2>
                <form class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_2fr_auto] md:items-end" @submit.prevent="issueGrant">
                    <div><label for="grant-service" class="block text-sm font-medium text-slate-700">الخدمة</label><input id="grant-service" v-model="grant.service" required dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.service" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.service }}</p></div>
                    <div><label for="grant-workspace" class="block text-sm font-medium text-slate-700">المساحة</label><input id="grant-workspace" v-model="grant.workspace" required dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.workspace" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.workspace }}</p></div>
                    <div><label for="grant-reason" class="block text-sm font-medium text-slate-700">السبب</label><input id="grant-reason" v-model="grant.reason" required minlength="10" maxlength="128" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.reason" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.reason }}</p></div>
                    <button type="submit" :disabled="grant.processing" class="rounded-lg ba-brand-button px-4 py-2.5 text-sm font-semibold  disabled:opacity-50">{{ grant.processing ? 'جارٍ المنح…' : 'إصدار المنحة' }}</button>
                </form>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100"><li v-for="item in grants" :key="item.id" class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ item.service_name }}</strong><p class="text-xs text-slate-600" dir="ltr">{{ item.workspace_public_id }}</p><p class="text-slate-600">{{ item.reason }}</p></div><button type="button" :disabled="!!busy" class="self-start text-red-700 underline disabled:opacity-50" @click="revokeGrant(item.id)">سحب المنحة</button></li><li v-if="!grants.length" class="py-4 text-sm text-slate-600">لا توجد منح خدمة نشطة.</li></ul>
            </section></fieldset>
        </main>
    </OperationsShell>
</template>
