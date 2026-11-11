<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, RouterLink } from 'vue-router';
import { startAuthentication, type PublicKeyCredentialRequestOptionsJSON } from '@simplewebauthn/browser';
import { runPasskeyOperation, cancelPasskeyOperation, passkeyBrowserFailure } from '../security/passkeyCeremony';
import { locale } from '../spa/locale';
import { bootstrap, onClose, close, TransportError, internalBoundary } from '../spa/transport';
import { api } from '../spa/api';
import { submitDocument } from '../spa/nativeForm';
import RegistrationEntry from './RegistrationEntry.vue';
import { entry } from './state';
import { authErrors, type AuthErrors } from './errors';
import './auth-entry.css';

const route = useRoute(), configuration = computed(() => entry.value);
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const form = reactive({ email: configuration.value?.old.email ?? '', password: '', password_confirmation: '', remember: false });
if (route.path.startsWith('/reset-password/')) form.email = typeof route.query.email === 'string' ? route.query.email : '';
const title = computed(() => ({ login: route.path === '/identity/switch' ? copy('تبديل الهوية', 'Switch identity') : copy('تسجيل الدخول', 'Sign in'), register: copy('إنشاء حساب', 'Create account'), 'forgot-password': copy('استعادة الحساب', 'Recover account'), 'reset-password': copy('تعيين كلمة مرور جديدة', 'Reset password'), 'verify-email': copy('تأكيد البريد الإلكتروني', 'Verify email'), 'confirm-password': copy('تأكيد كلمة المرور', 'Confirm password') })[configuration.value?.kind ?? 'login']);
const submitLabel = computed(() => busy.value ? copy('جارٍ الانتقال…', 'Continuing…') : configuration.value?.kind === 'forgot-password' ? copy('طلب رابط الاستعادة', 'Request recovery link') : configuration.value?.kind === 'reset-password' ? copy('تعيين كلمة المرور', 'Reset password') : configuration.value?.kind === 'confirm-password' ? copy('تأكيد كلمة المرور', 'Confirm password') : copy('دخول', 'Sign in'));
const fieldNames = computed(() => {
    switch (configuration.value?.kind) {
        case 'forgot-password': return ['email'];
        case 'confirm-password': return ['password'];
        case 'reset-password': return ['email', 'password', 'password_confirmation'];
        case 'login': return internalBoundary ? ['credential'] : ['email', 'password', 'credential'];
        default: return [];
    }
});
const busy = ref(false), operationId = ref<string | null>(null);
let active = true, ceremonyCancellation: AbortController | undefined;
const problems = ref<AuthErrors>(authErrors({ errors: configuration.value && !Array.isArray(configuration.value.errors) ? configuration.value.errors : {} }, fieldNames.value));
const errors = computed(() => problems.value.fields);
const failure = computed(() => problems.value.general);
const formRoot = ref<HTMLElement>();
const recoveryRequired = ref(false);
const wipe = () => { form.email = ''; form.password = ''; form.password_confirmation = ''; form.remember = false; problems.value = authErrors({}, []); };
const closures = onClose(wipe);
function cancel(reason: 'user' | 'document' = 'user') { ceremonyCancellation?.abort(); if (operationId.value) cancelPasskeyOperation(operationId.value, reason); }
onBeforeUnmount(() => { active = false; cancel('document'); wipe(); closures.delete(wipe); });
function clearField(field: string) {
    delete problems.value.fields[field];
    problems.value.general = '';
}
function reject(error: TransportError, fallback: string, fields = fieldNames.value) {
    problems.value = authErrors(error.body, fields, fallback);
    const field = Object.keys(problems.value.fields)[0];
    if (field) void nextTick(() => formRoot.value?.querySelector<HTMLElement>(`[data-auth-field="${field}"]`)?.focus());
}
function explain(message: string) { problems.value = authErrors({ message }, []); }
async function submit() {
    if (busy.value || recoveryRequired.value || !configuration.value) return;
    busy.value = true;
    problems.value = authErrors({}, []);
    if (configuration.value.kind === 'forgot-password') { submitDocument('/forgot-password', { email: form.email }); return; }
    if (configuration.value.kind === 'reset-password') { submitDocument('/reset-password', { token: String(route.params.token), email: form.email, password: form.password, password_confirmation: form.password_confirmation }); return; }
    if (configuration.value.kind === 'confirm-password') { submitDocument('/confirm-password', { password: form.password }); return; }
    try {
        const path = route.path === '/identity/switch' ? '/identity/switch' : '/login';
        const result = await api.POST(path, { body: { email: form.email, password: form.password, remember: form.remember } });
        if (!result.data) throw Error('Missing authentication acknowledgement');
    } catch (error) {
        if (error instanceof TransportError && [403, 422, 429].includes(error.status)) {
            reject(error, copy('رفض الخادم الطلب.', 'The server rejected the request.'));
        } else explain(copy('لم يتأكد إتمام الدخول. افتح صفحة دخول جديدة للتحقق من حالتك.', 'Sign-in could not be confirmed. Open a new sign-in page to check your status.'));
        recoveryRequired.value = true; form.password = ''; form.password_confirmation = '';
    } finally { if (active) busy.value = false; }
}
async function passkey() {
    if (busy.value || recoveryRequired.value) return;
    busy.value = true; problems.value = authErrors({}, []);
    ceremonyCancellation = new AbortController();
    const switching = bootstrap.identity.binding.principal > 0;
    const optionsPath = internalBoundary ? (switching ? '/internal/identity/passkey/options' : '/internal/auth/passkey/options') : (switching ? '/identity/passkey/options' : '/auth/passkey/options');
    const submitPath = internalBoundary ? (switching ? '/internal/identity/passkey' : '/internal/auth/passkey') : (switching ? '/identity/passkey' : '/auth/passkey');
    let submitted = false;
    try {
        const operation = runPasskeyOperation('login', { options: optionsPath, submit: submitPath }, async (routes, mark) => {
            const probe = new URL(routes.options).searchParams.get('webauthn_probe')!;
            const params = { query: { webauthn_probe: probe } };
            mark('challenge_request_started');
            const result = await api.GET(optionsPath, { params, signal: ceremonyCancellation!.signal });
            mark('challenge_response_received');
            ceremonyCancellation!.signal.throwIfAborted();
            if (!active || !result.data) throw Error('Ceremony unavailable');
            mark('credential_request_started');
            const credential = await startAuthentication({ optionsJSON: result.data.options as unknown as PublicKeyCredentialRequestOptionsJSON });
            mark('credential_request_completed');
            ceremonyCancellation!.signal.throwIfAborted();
            if (active) {
                submitted = true;
                mark('assertion_request_started');
                const result = await api.POST(submitPath, { params, body: { credential: { ...credential, response: { ...credential.response } } } });
                if (!result.data) throw Error('Missing passkey acknowledgement');
            }
        });
        operationId.value = operation.id; await operation.promise;
    } catch (error) {
        if (active) {
            if (error instanceof TransportError) {
                // A submitted transition may invalidate the old session even on known rejection.
                recoveryRequired.value = submitted || ![403, 422, 429].includes(error.status);
                reject(error, copy('رفض الخادم طلب مفتاح المرور.', 'The server rejected passkey sign-in.'), ['credential']);
            } else if (!submitted) {
                const reason = passkeyBrowserFailure(error, ceremonyCancellation?.signal.aborted === true);
                explain(reason === 'cancelled'
                    ? copy('أُلغيت محاولة مفتاح المرور في المتصفح؛ لم يُرسل طلب الدخول.', 'The browser ceremony was cancelled; sign-in was not submitted.')
                    : reason === 'not-allowed'
                    ? copy('لم يكتمل التحقق بمفتاح المرور. حاول مجددًا.', 'Passkey verification did not complete. Try again.')
                    : copy('تعذر إكمال المفتاح في المتصفح؛ لم يُرسل طلب الدخول.', 'The browser ceremony could not complete; sign-in was not submitted.'));
            } else {
                recoveryRequired.value = true;
                explain(copy('نتيجة طلب مفتاح المرور غير مؤكدة. افتح صفحة دخول جديدة للتحقق من حالتك.', 'Passkey sign-in is uncertain. Open a new sign-in page to check your status.'));
            }
        }
    }
    finally { operationId.value = null; if (active) busy.value = false; }
}
</script>

<template>
    <RegistrationEntry v-if="configuration?.kind === 'register'" :password-policy="configuration.passwordPolicy" />
    <main v-else-if="configuration" ref="formRoot" data-spa-private data-auth-entry class="auth-entry auth-entry--compact" :data-auth-kind="configuration.kind">
        <div class="auth-entry-card">
            <header>
                <p class="auth-entry-eyebrow">BuilderAd / {{ copy('الحساب', 'Account') }}</p>
                <h1>{{ title }}</h1>
            </header>
            <p v-if="configuration.status" role="status">{{ configuration.status === 'verification-link-sent' ? copy('أُرسل رابط تحقق جديد إلى بريدك.', 'A new verification link was sent to your email.') : configuration.status }}</p>
            <p v-if="failure" class="auth-entry-alert" role="alert">{{ failure }}</p>
            <section v-if="configuration.kind === 'verify-email'">
                <p>{{ copy('افتح رابط التحقق المرسل إلى بريدك في هذا المتصفح.', 'Open the verification link sent to your email in this browser.') }}</p>
                <button class="auth-entry-primary" data-auth-resend :disabled="busy" @click="busy = true; submitDocument('/email/verification-notification', {})">{{ copy('إعادة إرسال رابط التحقق', 'Resend verification link') }}</button>
            </section>
            <form v-else-if="!internalBoundary" class="auth-entry-form" @submit.prevent="submit" :aria-busy="busy">
                <div v-if="configuration.kind !== 'confirm-password'" class="auth-entry-field">
                    <label for="email">{{ copy('البريد الإلكتروني', 'Email') }}</label>
                    <input id="email" v-model="form.email" data-auth-field="email" required type="email" maxlength="255" autocomplete="username" dir="ltr"
                        :disabled="busy" :aria-invalid="!!errors.email" :aria-describedby="errors.email ? 'email-error' : undefined" @input="clearField('email')" />
                    <p v-if="errors.email" id="email-error" class="auth-entry-error">{{ errors.email }}</p>
                </div>
                <div v-if="configuration.kind !== 'forgot-password'" class="auth-entry-field">
                    <label for="password">{{ copy('كلمة المرور', 'Password') }}</label>
                    <input id="password" v-model="form.password" data-auth-field="password" required type="password" :autocomplete="configuration.kind === 'reset-password' ? 'new-password' : 'current-password'"
                        :minlength="configuration.passwordPolicy?.min" :maxlength="configuration.passwordPolicy?.max ?? undefined" :passwordrules="configuration.passwordPolicy?.browserRules"
                        :disabled="busy" :aria-invalid="!!errors.password" :aria-describedby="errors.password ? 'password-error' : configuration.passwordPolicy ? 'password-policy' : undefined" @input="clearField('password')" />
                    <p v-if="errors.password" id="password-error" class="auth-entry-error">{{ errors.password }}</p>
                    <p v-else-if="configuration.passwordPolicy" id="password-policy" class="auth-entry-muted">{{ copy(`استخدم ${configuration.passwordPolicy.min} حرفًا على الأقل.`, `Use at least ${configuration.passwordPolicy.min} characters.`) }}</p>
                </div>
                <div v-if="configuration.kind === 'reset-password'" class="auth-entry-field">
                    <label for="password_confirmation">{{ copy('تأكيد كلمة المرور', 'Confirm password') }}</label>
                    <input id="password_confirmation" v-model="form.password_confirmation" data-auth-field="password_confirmation" required type="password" autocomplete="new-password"
                        :disabled="busy" :aria-invalid="!!errors.password_confirmation" :aria-describedby="errors.password_confirmation ? 'password-confirmation-error' : undefined" @input="clearField('password_confirmation')" />
                    <p v-if="errors.password_confirmation" id="password-confirmation-error" class="auth-entry-error">{{ errors.password_confirmation }}</p>
                </div>
                <label v-if="configuration.kind === 'login'" class="auth-entry-remember"><input v-model="form.remember" type="checkbox" :disabled="busy" />{{ copy('تذكرني', 'Remember me') }}</label>
                <button class="auth-entry-primary" type="submit" :disabled="busy || recoveryRequired">{{ submitLabel }}</button>
            </form>
            <section v-if="configuration.kind === 'login'" class="auth-entry-alternatives">
                <button type="button" class="auth-entry-secondary" data-auth-passkey data-auth-field="credential" :aria-describedby="errors.credential ? 'credential-error' : undefined"
                    :aria-disabled="busy || recoveryRequired" @click="passkey">{{ copy('الدخول بمفتاح مرور', 'Sign in with passkey') }}</button>
                <p v-if="errors.credential" id="credential-error" class="auth-entry-error">{{ errors.credential }}</p>
                <button v-if="operationId" type="button" class="auth-entry-text" data-auth-cancel @click="cancel()">{{ copy('إلغاء عملية المتصفح', 'Cancel browser ceremony') }}</button>
            </section>
            <a v-if="recoveryRequired" data-auth-recover :href="internalBoundary ? '/internal/login' : '/login'" @click="close('handoff')">{{ copy('فتح صفحة دخول جديدة', 'Open a new sign-in page') }}</a>
            <nav class="auth-entry-footer" :aria-label="copy('روابط الحساب', 'Account links')">
                <a v-if="configuration.canResetPassword" href="/forgot-password">{{ copy('نسيت كلمة المرور', 'Forgot password') }}</a>
                <RouterLink v-if="!internalBoundary && bootstrap.identity.binding.principal === 0" to="/register">{{ copy('حساب جديد', 'Create account') }}</RouterLink>
                <a :href="internalBoundary ? '/internal/security/devices' : '/'">{{ copy('الرئيسية', 'Home') }}</a>
            </nav>
        </div>
    </main>
</template>
