<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { RouterLink, useRoute, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { startRegistration, type PublicKeyCredentialCreationOptionsJSON } from '@simplewebauthn/browser';
import { runPasskeyOperation, cancelPasskeyOperation } from '../security/passkeyCeremony';
import { locale } from '../spa/locale';
import { onClose, owned, stamp, TransportError, logout, internalBoundary } from '../spa/transport';
import { profile, devices, outcomes, read } from './state';
import * as account from './api';
import { changeEmail } from './nativeEmail';
import { confirmDeparture } from '../spa/presentation';

const route = useRoute();
const profileData = computed(() => profile.value?.data), devicesData = computed(() => devices.value?.data);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const form = reactive({ name: profileData.value?.old.name ?? profileData.value?.user.name ?? '', email: profileData.value?.old.email ?? profileData.value?.user.email ?? '' });
const passwords = reactive({ current_password: '', password: '', password_confirmation: '' });
const errors = ref<Record<string, string[]>>(profileData.value && !Array.isArray(profileData.value.errors) ? profileData.value.errors : {});
const busy = ref(''), message = ref(''), failure = ref(''), keyName = ref('');
const cancellation = new AbortController();
let active = true, operationId: string | null = null;
let ceremonyCancellation: AbortController | null = null;
const cleanSecrets = () => { passwords.current_password = ''; passwords.password = ''; passwords.password_confirmation = ''; };
const clearForm = () => { cleanSecrets(); form.name = ''; form.email = ''; keyName.value = ''; errors.value = {}; message.value = ''; failure.value = ''; };
const closures = onClose(clearForm);
function cancel() { ceremonyCancellation?.abort(); if (operationId) cancelPasskeyOperation(operationId); }
const unresolved = () => Object.values(outcomes).some(Boolean);
const canLeave = () => unresolved()
    ? confirm(copy('يوجد طلب حساب غير محسوم. هل تريد المغادرة دون إعادة إرساله؟', 'An account request is unresolved. Leave without replaying it?'))
    : !profileData.value || (form.name === profileData.value.user.name && form.email === profileData.value.user.email)
        || confirm(copy('لديك حقول حساب غير محفوظة. هل تريد التخلص منها عند مغادرة الصفحة؟', 'Discard unsaved account fields when leaving this page?'));
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const beforeUnload = (event: BeforeUnloadEvent) => { if (unresolved()) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => {
    active = false; cancellation.abort(); cancel(); clearForm(); closures.delete(clearForm);
    for (const kind of Object.keys(outcomes) as (keyof typeof outcomes)[]) if (outcomes[kind] === 'pending') outcomes[kind] = 'unknown';
    if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true;
    window.removeEventListener('beforeunload', beforeUnload);
});
const errorFor = (field: string) => errors.value[field]?.[0];
class BrowserCeremonyStopped extends Error {}
async function perform(kind: keyof typeof outcomes, operation: () => Promise<unknown>, saved: string) {
    if (busy.value || outcomes[kind]) return;
    const captured = stamp(); busy.value = kind; failure.value = ''; message.value = ''; errors.value = {};
    outcomes[kind] = 'pending';
    try {
        await operation();
        if (!active || !owned(captured)) return;
        outcomes[kind] = ''; message.value = saved;
        await read(route.path as '/profile' | '/security/devices');
    } catch (error) {
        if (!active || !owned(captured)) return;
        if (error instanceof BrowserCeremonyStopped) {
            outcomes[kind] = ''; failure.value = error.message;
        } else if (error instanceof TransportError && [403, 404, 409, 422].includes(error.status)) {
            outcomes[kind] = ''; errors.value = error.body.errors ?? {};
            failure.value = error.status === 403 ? copy('قد تحتاج تأكيد الدخول أو صلاحية حالية.', 'Current permission or fresh authentication is required.')
                : copy('راجع المدخلات والحالة الحالية؛ لم نؤكد تنفيذ الطلب.', 'Review the input and current state; execution was not confirmed.');
        } else {
            outcomes[kind] = 'unknown'; failure.value = copy('نتيجة الطلب غير مؤكدة. لا تعِد إرساله تلقائيًا؛ راجع الحالة المعتمدة أولًا.', 'The result is unknown. Do not repeat it automatically; review authoritative state first.');
        }
    } finally { cleanSecrets(); if (active) busy.value = ''; }
}
async function saveProfile() {
    if (busy.value || outcomes.profile || !profileData.value) return;
    const body = { name: form.name, email: form.email };
    if (body.email !== profileData.value.user.email) { changeEmail(body); return; }
    await perform('profile', () => account.saveProfile(body, cancellation.signal), copy('حُفظت معلومات الحساب.', 'Account information saved.'));
}
async function savePassword() {
    const body = { ...passwords };
    await perform('password', () => account.savePassword(body, cancellation.signal), copy('تغيّرت كلمة المرور وانتهت الجلسات الأخرى.', 'Password changed; other sessions ended.'));
}
async function review() {
    if (busy.value) return;
    const value = await read(route.path as '/profile' | '/security/devices');
    if (active && value) {
        outcomes.profile = ''; outcomes.devices = '';
        message.value = copy('هذه آخر حالة معتمدة، وليست إقرارًا بالطلب السابق. لم يُعَد إرسال أي أمر.', 'This is current authoritative state, not an acknowledgement of the earlier request. No command was replayed.');
    }
}
async function addPasskey() {
    if (busy.value || outcomes.devices || !keyName.value.trim()) return;
    let submitting = false;
    await perform('devices', async () => {
        ceremonyCancellation = new AbortController();
        const signal = AbortSignal.any([cancellation.signal, ceremonyCancellation.signal]);
        const operation = runPasskeyOperation('device-registration', { options: internalBoundary ? '/internal/security/passkeys/options' : '/security/passkeys/options', submit: internalBoundary ? '/internal/security/passkeys' : '/security/passkeys' }, async () => {
            const options = await account.passkeyOptions(signal);
            signal.throwIfAborted();
            if (!active) throw new DOMException('Unmounted', 'AbortError');
            const credential = await startRegistration({ optionsJSON: options.options as unknown as PublicKeyCredentialCreationOptionsJSON });
            if (!active) throw new DOMException('Unmounted', 'AbortError');
            submitting = true;
            return account.storePasskey({ name: keyName.value.trim(), credential: { ...credential, response: { ...credential.response } } }, signal);
        });
        operationId = operation.id;
        try { return await operation.promise; }
        catch (error) {
            if (!submitting && active) {
                if (!(error instanceof TransportError)) throw new BrowserCeremonyStopped(copy('لم يكتمل إنشاء المفتاح في المتصفح؛ لم يُرسل التسجيل.', 'Browser creation did not complete; registration was not submitted.'));
            }
            throw error;
        } finally { operationId = null; ceremonyCancellation = null; }
    }, copy('سُجّل مفتاح المرور.', 'Passkey registered.'));
}
const seen = (value: string) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
</script>

<template>
    <main data-spa-private data-account-page class="business-main account-page" :data-account-kind="profileData ? 'profile' : 'devices'">
        <nav v-if="internalBoundary"><a href="/internal/administration">{{ copy('الإدارة', 'Administration') }}</a> · <RouterLink to="/internal/identity/switch">{{ copy('تبديل الهوية الداخلية', 'Switch internal identity') }}</RouterLink></nav>
        <nav v-else><RouterLink to="/profile">{{ copy('الحساب', 'Account') }}</RouterLink> · <RouterLink to="/security/devices">{{ copy('الأمان والأجهزة', 'Security and devices') }}</RouterLink></nav>
        <h1>{{ profileData ? copy('معلومات الحساب', 'Account information') : copy('الأمان والأجهزة', 'Security and devices') }}</h1>
        <p v-if="message" role="status">{{ message }}</p><p v-if="failure" role="alert">{{ failure }}</p>
        <p v-if="Object.values(outcomes).includes('unknown')" role="alert">{{ copy('يوجد طلب غير مؤكد. لا يعني فقد الرد أن العملية لم تُنفذ.', 'A request is unresolved. A missing response does not mean it was not executed.') }}</p>
        <button type="button" :disabled="!!busy" data-account-review @click="review">{{ copy('مراجعة الحالة من الخادم', 'Review server state') }}</button>
        <section v-if="profileData">
            <p>{{ copy('تغيير البريد ينهي الجلسات ويتطلب تسجيل دخول جديدًا وتأكيد العنوان الجديد.', 'Changing email ends sessions and requires a new sign-in and verification.') }}</p>
            <form data-account-profile @submit.prevent="saveProfile" :aria-busy="busy === 'profile'">
                <label for="account-name">{{ copy('الاسم', 'Name') }}<input id="account-name" v-model="form.name" required maxlength="255" autocomplete="name" :disabled="!!busy || !!outcomes.profile" /></label><p v-if="errorFor('name')" role="alert">{{ errorFor('name') }}</p>
                <label for="account-email">{{ copy('البريد الإلكتروني', 'Email') }}<input id="account-email" v-model="form.email" required type="email" maxlength="255" autocomplete="username" dir="ltr" :readonly="!profileData.canChangeEmail" :disabled="!!busy || !!outcomes.profile" /></label><p v-if="errorFor('email')" role="alert">{{ errorFor('email') }}</p>
                <p v-if="!profileData.canChangeEmail">{{ copy('تغيير البريد الداخلي يتطلب صلاحية مستقلة.', 'Internal email changes require separate authority.') }}</p>
                <button type="submit" :disabled="!!busy || !!outcomes.profile">{{ copy('حفظ معلومات الحساب', 'Save account information') }}</button>
            </form>
            <p v-if="profileData.mustVerifyEmail && !profileData.user.email_verified_at">{{ copy('البريد غير مؤكد. تابع من مستند التحقق المأذون به.', 'Email is unverified. Continue through the authorized verification document.') }} <a href="/verify-email">{{ copy('تأكيد البريد', 'Verify email') }}</a></p>
            <RouterLink to="/security/devices">{{ copy('تغيير كلمة المرور وإدارة الأمان', 'Change password and manage security') }}</RouterLink>
        </section>
        <template v-if="devicesData">
            <section><h2>{{ copy('الجلسات النشطة', 'Active sessions') }}</h2><p>{{ copy('هذه أوصاف تقريبية، وليست إثباتًا لجهاز أو موقع محدد.', 'These are approximate descriptions, not proof of a physical device or location.') }}</p>
                <button data-account-revoke-others :disabled="!!busy || !!outcomes.devices || devicesData.sessions.length < 2" @click="perform('devices', () => account.revokeOthers(cancellation.signal), copy('انتهت الجلسات الأخرى.', 'Other sessions ended.'))">{{ copy('إنهاء كل الجلسات الأخرى', 'End all other sessions') }}</button>
                <ul><li v-for="session in devicesData.sessions" :key="session.id" :data-session-id="session.id"><span>{{ session.description }} · {{ seen(session.last_seen_at) }}</span><strong v-if="session.current"> {{ copy('هذه الجلسة', 'This session') }}</strong>
                    <button v-else :disabled="!!busy || !!outcomes.devices" data-account-revoke @click="perform('devices', () => account.revokeSession(session.id, cancellation.signal), copy('انتهت الجلسة المحددة.', 'Selected session ended.'))">{{ copy('إنهاء الجلسة', 'End session') }}</button></li></ul>
                <button data-account-logout :disabled="!!busy" @click="logout(internalBoundary ? '/internal/security/sessions/sign-out-this-device' : '/security/sessions/sign-out-this-device')">{{ copy('تسجيل الخروج من هذا الجهاز', 'Sign out on this device') }}</button>
            </section>
            <section v-if="!internalBoundary"><h2>{{ copy('كلمة المرور', 'Password') }}</h2><p>{{ copy('استخدم كلمة مرور طويلة وفريدة. تنتهي الجلسات الأخرى فقط وفق عقد Laravel.', 'Use a long unique password. Laravel ends other sessions while retaining the current session.') }}</p>
                <form data-account-password @submit.prevent="savePassword">
                    <label v-for="field in (['current_password', 'password', 'password_confirmation'] as const)" :key="field" :for="`account-${field}`">{{ field === 'current_password' ? copy('كلمة المرور الحالية', 'Current password') : field === 'password' ? copy('كلمة المرور الجديدة', 'New password') : copy('تأكيد كلمة المرور', 'Confirm password') }}<input :id="`account-${field}`" v-model="passwords[field]" required type="password" :autocomplete="field === 'current_password' ? 'current-password' : 'new-password'" :disabled="!!busy || !!outcomes.password" /><span v-if="errorFor(field)" role="alert">{{ errorFor(field) }}</span></label>
                    <button type="submit" :disabled="!!busy || !!outcomes.password">{{ copy('حفظ كلمة المرور', 'Save password') }}</button>
                </form>
                <a href="/confirm-password">{{ copy('تأكيد الدخول من مستند المصادقة', 'Confirm sign-in in the authentication document') }}</a>
            </section>
            <p v-if="internalBoundary"><a href="/internal/identity/switch">{{ copy('لتحديث المصادقة الداخلية، أعد الدخول بمفتاح المرور.', 'Reauthenticate with an internal passkey for fresh authentication.') }}</a></p>
            <section><h2>{{ copy('مفاتيح المرور', 'Passkeys') }}</h2><form data-account-passkey @submit.prevent="addPasskey"><label for="account-key-name">{{ copy('اسم المفتاح', 'Key name') }}<input id="account-key-name" v-model="keyName" required maxlength="255" :disabled="!!busy || !!outcomes.devices" /></label><button type="submit" :disabled="!!busy || !!outcomes.devices">{{ copy('إضافة مفتاح مرور', 'Add passkey') }}</button><button v-if="operationId" type="button" data-account-cancel @click="cancel">{{ copy('إلغاء عملية المتصفح', 'Cancel browser operation') }}</button></form>
                <ul><li v-for="key in devicesData.passkeys" :key="key.id" :data-passkey-id="key.id">{{ key.name }} · {{ key.provider ?? copy('مُصدّق غير معروف', 'Unknown authenticator') }} · {{ seen(key.created_at) }}<span v-if="key.last_used_at">{{ copy('آخر دخول به:', 'Last used:') }} {{ seen(key.last_used_at) }}</span><button :disabled="!!busy || !!outcomes.devices" data-account-delete-key @click="perform('devices', () => account.deletePasskey(key.id, cancellation.signal), copy('حُذف المفتاح.', 'Passkey deleted.'))">{{ copy('حذف المفتاح', 'Delete passkey') }}</button></li></ul><p v-if="!devicesData.passkeys.length">{{ copy('لم تضف مفاتيح مرور بعد.', 'No passkeys yet.') }}</p>
            </section>
        </template>
    </main>
</template>

<style scoped>
.account-page{max-width:960px;overflow-wrap:anywhere}.account-page h1{font-size:28px;font-weight:650;margin:24px 0}.account-page h2{font-size:21px;font-weight:600}.account-page section{border:1px solid #d9e1de;border-radius:10px;padding:24px;margin:24px 0}.account-page p{line-height:1.8;margin:12px 0}.account-page form{display:grid;gap:16px;max-width:520px;margin:20px 0}.account-page label{display:grid;gap:8px}.account-page input{width:100%;min-width:0;border:1px solid #9caeaa;border-radius:5px;padding:10px}.account-page button{border:1px solid var(--ba-primary-ink);border-radius:5px;padding:10px 16px;min-height:44px;color:var(--ba-primary-ink)}.account-page button:disabled{opacity:.5}.account-page li{padding:16px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:center;border-bottom:1px solid #d9e1de}.account-page a{text-decoration:underline;color:var(--ba-primary-ink)}.account-page [role=alert]{color:#a12c27}.account-page :focus-visible{outline:2px solid var(--ba-primary-ink);outline-offset:3px}
</style>
