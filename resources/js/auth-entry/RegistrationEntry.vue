<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import type { components } from '../api/generated/application';
import BusinessWizard from '../business-foundation/BusinessWizard.vue';
import { emptyWizardAnswers, storeRegistrationBusiness, type WizardAnswers, type WizardCatalog } from '../business-foundation/businessWizard';
import { api } from '../spa/api';
import { locale } from '../spa/locale';
import { close, onClose, owned, scope, stamp, TransportError } from '../spa/transport';
import { authErrors, type AuthErrors } from './errors';

defineProps<{ passwordPolicy: components['schemas']['AuthEntryData']['passwordPolicy'] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const storageKey = 'builderad:registration-guest:v1:' + scope;
type GuestDraft = { answers: WizardAnswers, step: number, finished: boolean, email: string, attemptId?: string };
type Challenge = components['schemas']['RegistrationEmailState'];
const draft = reactive<GuestDraft>(readDraft());
const form = reactive({ email: draft.email, code: '', name: '', password: '', password_confirmation: '' });
const catalog = shallowRef<WizardCatalog>();
const catalogStatus = ref<'loading' | 'unavailable' | 'ready'>('loading');
const challenge = shallowRef<Exclude<Challenge, { state: 'none' }>>();
const restoring = ref(draft.finished && !!draft.email);
const stage = computed(() => !draft.finished ? 'business' : restoring.value ? 'restore' : challenge.value?.state === 'verified' ? 'profile' : challenge.value?.state === 'code_required' ? 'code' : 'email');
const busy = ref(false), showPassword = ref(false);
const uncertain = ref<'' | 'email' | 'account'>('');
const formRoot = ref<HTMLElement>();
const title = ref<HTMLElement>();
const problems = ref<AuthErrors>(authErrors({}, []));
const errors = computed(() => problems.value.fields);
const remaining = ref(0);
const cancellation = new AbortController();
let active = true;
let resendTimer: ReturnType<typeof setTimeout> | undefined;
watch(stage, () => title.value?.focus(), { flush: 'post' });

function readDraft(): GuestDraft {
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null') as GuestDraft | null;
        if (saved && typeof saved.email === 'string' && typeof saved.answers?.name === 'string'
            && typeof saved.finished === 'boolean' && Number.isInteger(saved.step)) return saved;
    } catch { /* The user can still start and will be warned before submitting. */ }
    return { answers: emptyWizardAnswers(), step: 0, finished: false, email: '' };
}

function persistDraft() {
    try { sessionStorage.setItem(storageKey, JSON.stringify(draft)); return true; }
    catch { explain(t('تعذّر حفظ مسودة النشاط في هذا التبويب. أتح حفظ بيانات الموقع ثم حاول المتابعة.', 'The business draft could not be saved in this tab. Allow site storage and try again.')); return false; }
}

function explain(message: string) { problems.value = authErrors({ message }, []); }
function clearField(field: string) { delete problems.value.fields[field]; problems.value.general = ''; }
function knownRejection(error: unknown): error is TransportError {
    return error instanceof TransportError && ([403, 422, 429].includes(error.status)
        || (error.status === 503 && ['password_breach_unavailable', 'platform_maintenance'].includes(error.body.code ?? '')));
}
function reject(error: TransportError, fields: string[]) {
    problems.value = authErrors(error.body, fields, t('تعذّر إكمال الطلب. حاول مجددًا.', 'The request could not be completed. Try again.'));
    const field = Object.keys(errors.value)[0];
    if (field) void nextTick(() => formRoot.value?.querySelector<HTMLElement>('[data-registration-field="' + field + '"]')?.focus());
}

function setCooldown(seconds: number) {
    clearTimeout(resendTimer);
    const deadline = Date.now() + seconds * 1000;
    const tick = () => {
        remaining.value = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (remaining.value) resendTimer = setTimeout(tick, 1000);
    };
    tick();
}

function applyChallenge(value: Challenge, acknowledgedSend = false) {
    // Once identified, a draft resumes only its own attempt within this guest binding.
    if (value.state !== 'none' && value.email === draft.email
        && (acknowledgedSend || !draft.attemptId || value.attempt_id === draft.attemptId)) {
        challenge.value = value;
        draft.attemptId = value.attempt_id;
        persistDraft();
        setCooldown(value.resend_after ?? 0);
    } else {
        challenge.value = undefined;
        clearAccountFields();
        setCooldown(0);
    }
}

function clearAccountFields() {
    Object.assign(form, { code: '', name: '', password: '', password_confirmation: '' });
    showPassword.value = false;
}

function retire() {
    active = false;
    cancellation.abort();
    clearTimeout(resendTimer);
    form.email = '';
    clearAccountFields();
    challenge.value = undefined;
    catalog.value = undefined;
}
const closures = onClose(retire);
onBeforeUnmount(() => { retire(); closures.delete(retire); });

async function loadCatalog() {
    const captured = stamp();
    catalogStatus.value = 'loading';
    try {
        const result = await api.GET('/business-registration/catalog', { signal: cancellation.signal });
        if (!result.data) throw Error('Missing business catalog');
        if (active && owned(captured)) {
            catalog.value = result.data.data;
            catalogStatus.value = 'ready';
        }
    } catch {
        if (active && owned(captured)) catalogStatus.value = 'unavailable';
    }
}

async function reconcileEmail() {
    if (busy.value || uncertain.value === 'account') return;
    if (!draft.email) return;
    const captured = stamp();
    busy.value = true;
    problems.value = authErrors({}, []);
    try {
        const result = await api.GET('/register/email', { signal: cancellation.signal });
        if (!result.data) throw Error('Missing registration email state');
        if (!active || !owned(captured)) return;
        applyChallenge(result.data);
        uncertain.value = '';
    } catch {
        if (!active || !owned(captured)) return;
        uncertain.value = 'email';
        explain(t('تعذّر التحقق من حالة رمز البريد. أعد فحص الحالة للمتابعة.', 'The email code status is unavailable. Check the status again to continue.'));
    } finally {
        if (active && owned(captured)) { busy.value = false; restoring.value = false; }
    }
}

function updateAnswers(answers: WizardAnswers) {
    draft.answers = answers;
    persistDraft();
}
function updateStep(step: number) {
    draft.step = step;
    persistDraft();
}
function finishBusiness() {
    draft.finished = true;
    if (!persistDraft()) { draft.finished = false; return; }
    restoring.value = !!draft.email;
    void reconcileEmail();
}
function editBusiness() {
    if (busy.value || uncertain.value) return;
    problems.value = authErrors({}, []);
    draft.finished = false;
    clearAccountFields();
    persistDraft();
    if (!catalog.value) void loadCatalog();
}

async function emailCommand(action: 'send' | 'verify' | 'cancel') {
    if (busy.value || uncertain.value) return;
    const currentAttempt = challenge.value?.attempt_id;
    if (action !== 'send' && !currentAttempt) return;
    if (action === 'send') {
        const email = form.email.trim().toLowerCase();
        if (email !== draft.email) draft.attemptId = undefined;
        draft.email = email;
        form.email = draft.email;
        if (!persistDraft()) return;
    }
    if (action === 'cancel') clearAccountFields();
    busy.value = true;
    problems.value = authErrors({}, []);
    const captured = stamp();
    try {
        const result = action === 'send'
            ? await api.POST('/register/email', { body: { email: draft.email } })
            : action === 'verify'
                ? await api.POST('/register/email/verify', { body: { attempt_id: currentAttempt!, code: form.code } })
                : await api.POST('/register/email/cancel', { body: { attempt_id: currentAttempt! } });
        if (!result.data) throw Error('Missing email command acknowledgement');
        if (!active || !owned(captured)) return;
        form.code = '';
        if (action === 'cancel' && result.data.state === 'none') {
            draft.attemptId = undefined;
            persistDraft();
        }
        applyChallenge(result.data, action === 'send');
    } catch (error) {
        if (!active || !owned(captured)) return;
        if (knownRejection(error)) {
            reject(error, stage.value === 'email' ? ['email'] : stage.value === 'code' ? ['code'] : []);
        } else {
            uncertain.value = 'email';
            explain(t('لم تتأكد نتيجة الطلب. تحقّق من حالة رمز البريد للمتابعة.', 'The request outcome is uncertain. Check the email code status to continue.'));
        }
    } finally {
        if (active && owned(captured)) busy.value = false;
    }
}

function normalizeCode(event: Event) {
    const input = event.target as HTMLInputElement;
    form.code = input.value.replace(/[٠-٩۰-۹]/g, digit => String(digit.charCodeAt(0) & 15)).replace(/\D/g, '').slice(0, 6);
    input.value = form.code;
    clearField('code');
}

async function finishAccount() {
    if (busy.value || uncertain.value || challenge.value?.state !== 'verified' || !challenge.value.attempt_id) return;
    const attempt = challenge.value.attempt_id;
    try { storeRegistrationBusiness(attempt, draft.answers, locale.value); }
    catch { explain(t('تعذّر حفظ مسودة نشاطك للانتقال إلى حسابك. أتح حفظ بيانات الموقع ثم حاول مجددًا.', 'Your business draft could not be saved for the account handoff. Allow site storage and try again.')); return; }
    const captured = stamp();
    busy.value = true;
    problems.value = authErrors({}, []);
    try {
        const result = await api.POST('/register', { body: {
            attempt_id: attempt, email: draft.email, name: form.name,
            password: form.password, password_confirmation: form.password_confirmation,
        } });
        if (!result.data) throw Error('Missing registration acknowledgement');
    } catch (error) {
        if (!active || !owned(captured)) return;
        if (knownRejection(error)) {
            reject(error, ['name', 'password', 'password_confirmation', 'email']);
        } else {
            uncertain.value = 'account';
            form.password = ''; form.password_confirmation = '';
            explain(t('لم يتأكد إنشاء الحساب. افتح صفحة الاستكمال للتحقق من حسابك ومسودة النشاط.', 'Account creation could not be confirmed. Open the continuation page to check your account and business draft.'));
        }
    } finally {
        if (active && owned(captured)) busy.value = false;
    }
}

onMounted(() => {
    if (stage.value === 'business') void loadCatalog();
    else void reconcileEmail();
});
</script>

<template>
    <main v-if="stage === 'business'" data-spa-private data-auth-entry data-auth-kind="register" class="auth-entry auth-entry--business">
        <BusinessWizard v-if="catalog" guest :catalog="catalog" :answers="draft.answers" :initial-step="draft.step"
            :action-label="t('المتابعة إلى البريد الإلكتروني', 'Continue to email')"
            @update:answers="updateAnswers" @step="updateStep" @finish="finishBusiness" />
        <div v-else class="auth-entry-card">
            <h1>{{ t('تعريف النشاط', 'Set up your business') }}</h1>
            <p v-if="catalogStatus === 'loading'" role="status">{{ t('جارٍ تحميل خيارات النشاط…', 'Loading business options…') }}</p>
            <template v-else>
                <p class="auth-entry-alert" role="alert">{{ t('تعذّر تحميل خيارات النشاط.', 'Business options could not be loaded.') }}</p>
                <button type="button" class="auth-entry-secondary" @click="loadCatalog">{{ t('إعادة المحاولة', 'Try again') }}</button>
            </template>
        </div>
        <p v-if="problems.general" class="auth-entry-alert" role="alert">{{ problems.general }}</p>
        <nav class="auth-entry-footer" :aria-label="t('روابط الحساب', 'Account links')">
            <RouterLink to="/login">{{ t('لديك حساب؟ سجّل الدخول', 'Already have an account? Sign in') }}</RouterLink>
            <a href="/">{{ t('الرئيسية', 'Home') }}</a>
        </nav>
    </main>
    <main v-else ref="formRoot" data-spa-private data-auth-entry data-auth-kind="register" :data-registration-stage="stage" class="auth-entry auth-entry--steps">
        <aside class="auth-entry-aside">
            <p class="auth-entry-eyebrow">BuilderAd / {{ t('حساب المعلن', 'Advertiser account') }}</p>
            <h2>{{ draft.answers.name }}</h2>
            <p>{{ t('أكمل حسابك للانتقال إلى نشاطك.', 'Finish setting up your account to open your business.') }}</p>
            <ol class="auth-entry-steps" :aria-label="t('خطوات إنشاء الحساب', 'Account setup steps')">
                <li :aria-current="stage === 'email' ? 'step' : undefined"><span>1</span>{{ t('البريد', 'Email') }}</li>
                <li :aria-current="stage === 'code' ? 'step' : undefined"><span>2</span>{{ t('رمز التحقق', 'Code') }}</li>
                <li :aria-current="stage === 'profile' ? 'step' : undefined"><span>3</span>{{ t('بيانات الحساب', 'Account details') }}</li>
            </ol>
        </aside>
        <section class="auth-entry-card" aria-labelledby="registration-title">
            <p class="auth-entry-eyebrow">{{ t('إنشاء الحساب', 'Create account') }}</p>
            <h1 id="registration-title" ref="title" tabindex="-1">{{ stage === 'restore' ? t('استكمال التسجيل', 'Continue registration') : stage === 'email' ? t('أدخل بريدك الإلكتروني', 'Enter your email') : stage === 'code' ? t('تحقّق من بريدك', 'Check your email') : t('أكمل بيانات حسابك', 'Finish your account') }}</h1>
            <p v-if="stage === 'restore'" class="auth-entry-muted" role="status">{{ t('جارٍ استعادة حالة التسجيل…', 'Restoring registration…') }}</p>
            <p v-else-if="stage === 'email'" class="auth-entry-muted">{{ t('سنرسل رمزًا للتحقق من ملكية البريد قبل إنشاء الحساب.', 'We’ll send a code to verify your email before creating your account.') }}</p>
            <p v-else-if="stage === 'code'" class="auth-entry-muted">{{ t('أدخل الرمز المكوّن من ستة أرقام المرسل إلى', 'Enter the six-digit code sent to') }} <bdi>{{ draft.email }}</bdi>.</p>
            <p v-else class="auth-entry-muted" data-registration-field="email" tabindex="-1" :aria-describedby="errors.email ? 'verified-email-error' : undefined">{{ t('تم التحقق من', 'Verified email') }} <bdi>{{ draft.email }}</bdi>.</p>
            <p v-if="stage === 'profile' && errors.email" id="verified-email-error" class="auth-entry-error">{{ errors.email }}</p>
            <p v-if="problems.general" class="auth-entry-alert" role="alert">{{ problems.general }}</p>
            <div v-if="uncertain === 'account'" class="auth-entry-actions">
                <a data-auth-recover href="/businesses/new" @click="close('handoff')">{{ t('فتح صفحة الاستكمال', 'Open continuation page') }}</a>
            </div>
            <button v-else-if="uncertain === 'email'" type="button" class="auth-entry-secondary" :disabled="busy" @click="reconcileEmail">
                {{ busy ? t('جارٍ التحقق…', 'Checking…') : t('التحقق من حالة الرمز', 'Check code status') }}
            </button>
            <form v-else-if="stage === 'email'" class="auth-entry-form" :aria-busy="busy" @submit.prevent="emailCommand('send')">
                <div class="auth-entry-field">
                    <label for="registration-email">{{ t('البريد الإلكتروني', 'Email') }}</label>
                    <input id="registration-email" v-model="form.email" data-registration-field="email" type="email" dir="ltr" required maxlength="255" autocomplete="email"
                        :disabled="busy" :aria-invalid="!!errors.email" :aria-describedby="errors.email ? 'registration-email-error' : undefined" @input="clearField('email')" />
                    <p v-if="errors.email" id="registration-email-error" class="auth-entry-error">{{ errors.email }}</p>
                </div>
                <button type="submit" class="auth-entry-primary" :disabled="busy || remaining > 0">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : t('إرسال رمز التحقق', 'Send verification code') }}</button>
            </form>
            <form v-else-if="stage === 'code'" class="auth-entry-form" :aria-busy="busy" @submit.prevent="emailCommand('verify')">
                <div class="auth-entry-field">
                    <label for="registration-code">{{ t('رمز التحقق', 'Verification code') }}</label>
                    <input id="registration-code" class="auth-entry-code" data-registration-field="code" :value="form.code" type="text" dir="ltr" inputmode="numeric"
                        required pattern="[0-9]{6}" autocomplete="one-time-code" :disabled="busy" :aria-invalid="!!errors.code"
                        :aria-describedby="errors.code ? 'registration-code-error' : undefined" @input="normalizeCode" />
                    <p v-if="errors.code" id="registration-code-error" class="auth-entry-error">{{ errors.code }}</p>
                </div>
                <button type="submit" class="auth-entry-primary" :disabled="busy">{{ busy ? t('جارٍ العمل…', 'Working…') : t('تأكيد الرمز', 'Verify code') }}</button>
            </form>
            <form v-else-if="stage === 'profile'" class="auth-entry-form" :aria-busy="busy" @submit.prevent="finishAccount">
                <div class="auth-entry-field">
                    <label for="registration-name">{{ t('اسمك', 'Your name') }}</label>
                    <input id="registration-name" v-model="form.name" data-registration-field="name" type="text" required maxlength="255" autocomplete="name"
                        :disabled="busy" :aria-invalid="!!errors.name" :aria-describedby="errors.name ? 'registration-name-error' : undefined" @input="clearField('name')" />
                    <p v-if="errors.name" id="registration-name-error" class="auth-entry-error">{{ errors.name }}</p>
                </div>
                <div class="auth-entry-field">
                    <label for="registration-password">{{ t('كلمة المرور', 'Password') }}</label>
                    <input id="registration-password" v-model="form.password" data-registration-field="password" :type="showPassword ? 'text' : 'password'" required autocomplete="new-password"
                        :minlength="passwordPolicy?.min" :maxlength="passwordPolicy?.max ?? undefined" :passwordrules="passwordPolicy?.browserRules"
                        :disabled="busy" :aria-invalid="!!errors.password" :aria-describedby="errors.password ? 'registration-password-error' : passwordPolicy ? 'registration-password-policy' : undefined" @input="clearField('password')" />
                    <p v-if="errors.password" id="registration-password-error" class="auth-entry-error">{{ errors.password }}</p>
                    <p v-else-if="passwordPolicy" id="registration-password-policy" class="auth-entry-muted">{{ t('استخدم ما لا يقل عن ' + passwordPolicy.min + ' حرفًا.', 'Use at least ' + passwordPolicy.min + ' characters.') }}</p>
                </div>
                <div class="auth-entry-field">
                    <label for="registration-confirmation">{{ t('تأكيد كلمة المرور', 'Confirm password') }}</label>
                    <input id="registration-confirmation" v-model="form.password_confirmation" data-registration-field="password_confirmation" :type="showPassword ? 'text' : 'password'" required autocomplete="new-password"
                        :disabled="busy" :aria-invalid="!!errors.password_confirmation" :aria-describedby="errors.password_confirmation ? 'registration-confirmation-error' : undefined" @input="clearField('password_confirmation')" />
                    <p v-if="errors.password_confirmation" id="registration-confirmation-error" class="auth-entry-error">{{ errors.password_confirmation }}</p>
                </div>
                <label class="auth-entry-remember"><input v-model="showPassword" type="checkbox" :disabled="busy" />{{ t('إظهار كلمة المرور', 'Show passwords') }}</label>
                <button type="submit" class="auth-entry-primary" :disabled="busy">{{ busy ? t('جارٍ إنشاء الحساب…', 'Creating account…') : t('إنشاء الحساب', 'Create account') }}</button>
            </form>
            <div v-if="!uncertain && stage !== 'restore'" class="auth-entry-actions">
                <button v-if="stage === 'code'" type="button" class="auth-entry-text" :disabled="busy || remaining > 0" @click="emailCommand('send')">
                    {{ t('إرسال رمز جديد', 'Send a new code') }}<span v-if="remaining > 0"> ({{ remaining }})</span>
                </button>
                <button v-if="stage !== 'email'" type="button" class="auth-entry-text" :disabled="busy" @click="emailCommand('cancel')">{{ t('تغيير البريد', 'Change email') }}</button>
                <button type="button" class="auth-entry-text" :disabled="busy" @click="editBusiness">{{ t('مراجعة النشاط', 'Review business') }}</button>
            </div>
            <nav class="auth-entry-footer" :aria-label="t('روابط الحساب', 'Account links')">
                <RouterLink to="/login">{{ t('تسجيل الدخول', 'Sign in') }}</RouterLink>
                <a href="/forgot-password">{{ t('استعادة الحساب', 'Recover account') }}</a>
            </nav>
        </section>
    </main>
</template>
