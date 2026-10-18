<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { startAuthentication, type PublicKeyCredentialRequestOptionsJSON } from '@simplewebauthn/browser';
import { runPasskeyOperation, cancelPasskeyOperation } from '../security/passkeyCeremony';
import { locale } from '../spa/locale';
import { bootstrap, onClose, close, TransportError } from '../spa/transport';
import { api } from '../spa/api';
import { submitDocument } from '../spa/nativeForm';
import { entry } from './state';

const route = useRoute(), configuration = computed(() => entry.value);
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const form = reactive({ name: configuration.value?.old.name ?? '', email: configuration.value?.old.email ?? '', password: '', password_confirmation: '', remember: false });
if (route.path.startsWith('/reset-password/')) form.email = typeof route.query.email === 'string' ? route.query.email : '';
const title = computed(() => ({ login: route.path === '/identity/switch' ? copy('تبديل الهوية', 'Switch identity') : copy('تسجيل الدخول', 'Sign in'), register: copy('إنشاء حساب', 'Create account'), 'forgot-password': copy('استعادة الحساب', 'Recover account'), 'reset-password': copy('تعيين كلمة مرور جديدة', 'Reset password'), 'verify-email': copy('تأكيد البريد الإلكتروني', 'Verify email'), 'confirm-password': copy('تأكيد كلمة المرور', 'Confirm password') })[configuration.value?.kind ?? 'login']);
const submitLabel = computed(() => busy.value ? copy('جارٍ الانتقال…', 'Continuing…') : configuration.value?.kind === 'register' ? copy('إنشاء الحساب', 'Create account') : configuration.value?.kind === 'forgot-password' ? copy('طلب رابط الاستعادة', 'Request recovery link') : configuration.value?.kind === 'reset-password' ? copy('تعيين كلمة المرور', 'Reset password') : copy('دخول', 'Sign in'));
const busy = ref(false), failure = ref(''), operationId = ref<string | null>(null);
let active = true, ceremonyCancellation: AbortController | undefined;
const errors = ref<Record<string, string[]>>(configuration.value && !Array.isArray(configuration.value.errors) ? configuration.value.errors : {});
const recoveryRequired = ref(false);
const wipe = () => { form.name = ''; form.email = ''; form.password = ''; form.password_confirmation = ''; form.remember = false; failure.value = ''; };
const closures = onClose(wipe);
function cancel() { ceremonyCancellation?.abort(); if (operationId.value) cancelPasskeyOperation(operationId.value); }
onBeforeUnmount(() => { active = false; cancel(); wipe(); closures.delete(wipe); });
async function submit() {
    if (busy.value || recoveryRequired.value || !configuration.value) return;
    busy.value = true;
    if (configuration.value.kind === 'forgot-password') { submitDocument('/forgot-password', { email: form.email }); return; }
    if (configuration.value.kind === 'reset-password') { submitDocument('/reset-password', { token: String(route.params.token), email: form.email, password: form.password, password_confirmation: form.password_confirmation }); return; }
    if (configuration.value.kind === 'confirm-password') { submitDocument('/confirm-password', { password: form.password }); return; }
    try {
        const path = route.path as '/login' | '/register' | '/identity/switch';
        const result = path === '/register' ? await api.POST(path, { body: { name: form.name, email: form.email, password: form.password, password_confirmation: form.password_confirmation } })
            : await api.POST(path, { body: { email: form.email, password: form.password, remember: form.remember } });
        if (!result.data) throw Error('Missing authentication acknowledgement');
    } catch (error) {
        if (error instanceof TransportError && [403, 422, 429].includes(error.status)) {
            errors.value = error.body.errors ?? {};
            failure.value = error.body.message ?? copy('رفض الخادم الطلب.', 'The server rejected the request.');
        } else failure.value = copy('نتيجة الطلب غير مؤكدة. افتح مستند دخول جديد؛ لن نعيد إرسال الطلب.', 'The result is unknown. Open a new sign-in document; this request will not be replayed.');
        recoveryRequired.value = true; form.password = ''; form.password_confirmation = '';
    } finally { if (active) busy.value = false; }
}
async function passkey() {
    if (busy.value || recoveryRequired.value) return;
    busy.value = true; failure.value = '';
    ceremonyCancellation = new AbortController();
    const switching = bootstrap.identity.binding.principal > 0;
    let submitted = false;
    try {
        const operation = runPasskeyOperation('login', { options: switching ? '/identity/passkey/options' : '/auth/passkey/options', submit: switching ? '/identity/passkey' : '/auth/passkey' }, async () => {
            const result = await api.GET(switching ? '/identity/passkey/options' : '/auth/passkey/options', { signal: ceremonyCancellation!.signal });
            ceremonyCancellation!.signal.throwIfAborted();
            if (!active || !result.data) throw Error('Ceremony unavailable');
            const credential = await startAuthentication({ optionsJSON: result.data.options as unknown as PublicKeyCredentialRequestOptionsJSON });
            ceremonyCancellation!.signal.throwIfAborted();
            if (active) {
                submitted = true;
                const result = await api.POST(switching ? '/identity/passkey' : '/auth/passkey', { body: { credential: { ...credential, response: { ...credential.response } } } });
                if (!result.data) throw Error('Missing passkey acknowledgement');
            }
        });
        operationId.value = operation.id; await operation.promise;
    } catch { if (active) { recoveryRequired.value = submitted; failure.value = copy('لم يكتمل طلب مفتاح المرور. لم نؤكد تسجيل الدخول؛ يمكنك فتح مستند دخول جديد دون إعادة الطلب تلقائيًا.', 'Passkey sign-in was not confirmed. Open a new sign-in document; no request is replayed automatically.'); } }
    finally { operationId.value = null; if (active) busy.value = false; }
}
</script>

<template>
    <main data-spa-private data-auth-entry class="business-main auth-entry" :data-auth-kind="configuration?.kind">
        <template v-if="configuration">
            <h1>{{ title }}</h1>
            <p v-if="configuration.status" role="status">{{ configuration.status }}</p>
            <p v-if="failure" role="alert">{{ failure }}</p>
            <a v-if="recoveryRequired" data-auth-recover href="/login" @click="close">{{ copy('فتح مستند دخول جديد دون إعادة الطلب', 'Open a new sign-in document without replay') }}</a>
            <p v-if="errors.credential" role="alert">{{ errors.credential[0] }}</p>
            <section v-if="configuration.kind === 'verify-email'"><p>{{ copy('أرسلنا رابط التحقق. افتحه في هذا المتصفح لإكمال التحقق بواسطة Laravel.', 'Open the verification link in this browser to complete server verification.') }}</p><button data-auth-resend :disabled="busy" @click="busy = true; submitDocument('/email/verification-notification', {})">{{ copy('إعادة إرسال رابط التحقق', 'Resend verification link') }}</button></section>
            <form v-else @submit.prevent="submit" :aria-busy="busy">
                <label v-if="configuration.kind === 'register'" for="name">{{ copy('الاسم', 'Name') }}<input id="name" v-model="form.name" required maxlength="255" autocomplete="name" :disabled="busy" /><span role="alert">{{ errors.name?.[0] }}</span></label>
                <label v-if="configuration.kind !== 'confirm-password'" for="email">{{ copy('البريد الإلكتروني', 'Email') }}<input id="email" v-model="form.email" required type="email" maxlength="255" autocomplete="username" dir="ltr" :disabled="busy" /><span id="email-error" role="alert">{{ errors.email?.[0] }}</span></label>
                <label v-if="configuration.kind !== 'forgot-password'" for="password">{{ copy('كلمة المرور', 'Password') }}<input id="password" v-model="form.password" required type="password" :autocomplete="configuration.kind === 'register' || configuration.kind === 'reset-password' ? 'new-password' : 'current-password'" :minlength="configuration.passwordPolicy?.min" :maxlength="configuration.passwordPolicy?.max ?? undefined" :passwordrules="configuration.passwordPolicy?.browserRules" :disabled="busy" /><span role="alert">{{ errors.password?.[0] }}</span></label>
                <template v-if="configuration.kind === 'register' || configuration.kind === 'reset-password'"><p v-if="configuration.passwordPolicy">{{ copy('الحد الأدنى:', 'Minimum length:') }} {{ configuration.passwordPolicy.min }}</p><label for="password_confirmation">{{ copy('تأكيد كلمة المرور', 'Confirm password') }}<input id="password_confirmation" v-model="form.password_confirmation" required type="password" autocomplete="new-password" :disabled="busy" /></label></template>
                <label v-if="configuration.kind === 'login'" class="remember"><input v-model="form.remember" type="checkbox" :disabled="busy" />{{ copy('تذكرني', 'Remember me') }}</label>
                <button type="submit" :disabled="busy || recoveryRequired">{{ submitLabel }}</button>
            </form>
            <template v-if="configuration.kind === 'login'"><button type="button" data-auth-passkey :disabled="busy || recoveryRequired" @click="passkey">{{ copy('الدخول بمفتاح مرور', 'Sign in with passkey') }}</button><button v-if="operationId" data-auth-cancel @click="cancel">{{ copy('إلغاء عملية المتصفح', 'Cancel browser ceremony') }}</button><a v-if="configuration.canResetPassword" href="/forgot-password">{{ copy('نسيت كلمة المرور', 'Forgot password') }}</a></template>
            <nav><RouterLink v-if="bootstrap.identity.binding.principal === 0" :to="configuration.kind === 'register' ? '/login' : '/register'">{{ configuration.kind === 'register' ? copy('تسجيل الدخول', 'Sign in') : copy('حساب جديد', 'Create account') }}</RouterLink> · <a href="/">{{ copy('الرئيسية', 'Home') }}</a></nav>
            <p>{{ copy('تغييرات الهوية تتم داخل مستند جديد يختاره Laravel. لا تُعد إرسال طلب مجهول النتيجة تلقائيًا.', 'Identity changes use a new server-selected document. Uncertain requests are never replayed automatically.') }}</p>
        </template>
    </main>
</template>

<style scoped>
.auth-entry{max-width:560px}.auth-entry h1{font-size:28px;font-weight:650;margin:24px 0}.auth-entry form{display:grid;gap:18px;margin:24px 0}.auth-entry label{display:grid;gap:8px}.auth-entry input:not([type=checkbox]){width:100%;min-width:0;border:1px solid #9caeaa;border-radius:5px;padding:10px}.auth-entry button{border:1px solid #14635b;border-radius:5px;min-height:44px;padding:10px 16px}.auth-entry button:disabled{opacity:.5}.auth-entry p,.auth-entry nav{margin:18px 0;line-height:1.8}.auth-entry a{text-decoration:underline;color:#14635b;display:inline-block;margin:10px}.auth-entry [role=alert]{color:#a12c27}.auth-entry .remember{display:flex;gap:8px}.auth-entry :focus-visible{outline:2px solid #14635b;outline-offset:3px}
</style>
