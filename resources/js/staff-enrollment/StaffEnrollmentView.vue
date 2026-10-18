<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { startRegistration, type PublicKeyCredentialCreationOptionsJSON } from '@simplewebauthn/browser';
import { api } from '../spa/api';
import { onClose, owned, stamp, TransportError } from '../spa/transport';
import { locale } from '../spa/locale';
import { runPasskeyOperation, cancelPasskeyOperation } from '../security/passkeyCeremony';
import { enrollment, load } from './state';

const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const name = ref(''), busy = ref(false), unknown = ref(false), error = ref('');
const cancellation = new AbortController();
let active = true, operationId: string | undefined, ceremonyCancellation: AbortController | undefined;
const wipe = () => { name.value = ''; error.value = ''; };
const closures = onClose(wipe);
function cancel() { ceremonyCancellation?.abort(); if (operationId) cancelPasskeyOperation(operationId); }
onBeforeUnmount(() => { active = false; cancellation.abort(); cancel(); wipe(); closures.delete(wipe); });
async function enroll() {
    if (busy.value || unknown.value || !name.value.trim()) return;
    busy.value = true; error.value = ''; let submitted = false;
    const captured = stamp(); ceremonyCancellation = new AbortController();
    const signal = AbortSignal.any([cancellation.signal, ceremonyCancellation.signal]);
    try {
        const operation = runPasskeyOperation('staff-registration', { options: '/staff/onboarding/passkeys/options', submit: '/staff/onboarding/passkeys' }, async () => {
            const result = await api.GET('/staff/onboarding/passkeys/options', { signal });
            signal.throwIfAborted(); if (!active || !owned(captured) || !result.data) throw Error('Ceremony unavailable');
            const credential = await startRegistration({ optionsJSON: result.data.options as unknown as PublicKeyCredentialCreationOptionsJSON });
            signal.throwIfAborted(); if (!active || !owned(captured)) throw Error('Obsolete ceremony');
            submitted = true;
            const stored = await api.POST('/staff/onboarding/passkeys', { body: { name: name.value.trim(), credential: { ...credential, response: { ...credential.response } } }, signal });
            if (!stored.data) throw Error('Missing enrollment acknowledgement');
        });
        operationId = operation.id; await operation.promise;
        if (!active || !owned(captured)) return;
        name.value = ''; await load();
    } catch (failure) {
        if (!active || !owned(captured)) return;
        unknown.value = submitted && !(failure instanceof TransportError && [403, 409, 422].includes(failure.status));
        error.value = unknown.value ? copy('نتيجة التسجيل غير مؤكدة. لا تعِد العملية؛ راجع الحالة من الخادم.', 'Registration outcome is unknown. Do not repeat it; review server state.')
            : copy('لم يكتمل التسجيل. تبقى شروط البريد والأهلية والمفاتيح مملوكة للخادم.', 'Registration did not complete. Email, eligibility and factor requirements remain server-owned.');
    } finally { operationId = undefined; if (active) busy.value = false; }
}
async function review() { if (busy.value) return; const result = await load(); if (result) { unknown.value = false; error.value = copy('هذه حالة الخادم الحالية وليست إقرارًا بالطلب السابق. لم نعد تنفيذ أي أمر.', 'This is current server state, not acknowledgement of the earlier command. Nothing was replayed.'); } }
</script>

<template>
    <main v-if="enrollment" data-spa-private data-staff-enrollment class="business-main enrollment">
        <h1>{{ copy('تهيئة الحساب الداخلي', 'Internal account enrollment') }}</h1>
        <p>{{ copy('التجهيز لا يمنح جلسة داخلية أو صلاحيات. يلزم تأكيد البريد وإقرار الأهلية وتفعيل الخادم ثم دخول مستقل بمفتاح مرور.', 'Enrollment grants no internal session or permissions. Email, eligibility attestation, server activation and a separate passkey login are required.') }}</p>
        <p v-if="!enrollment.verified"><a href="/verify-email">{{ copy('تأكيد البريد وإعادة إرسال الرابط', 'Verify email or resend link') }}</a></p>
        <template v-else>
            <p>{{ copy('المفاتيح المسجلة:', 'Registered passkeys:') }} {{ enrollment.passkeys.length }} / {{ enrollment.kind === 'founder' ? 2 : 1 }}</p>
            <p v-if="enrollment.kind === 'founder'">{{ copy('المؤسس يحتاج وسيلتين مستقلتين؛ لا يثبت عدد المفاتيح وحده الاستقلال أو الأهلية.', 'Founders require two independent factors; key count alone proves neither independence nor eligibility.') }}</p>
            <form @submit.prevent="enroll"><label for="enrollment-key-name">{{ copy('اسم المفتاح', 'Passkey name') }}</label><input id="enrollment-key-name" v-model="name" required maxlength="255" :disabled="busy || unknown" /><button type="submit" :disabled="busy || unknown || !name.trim()">{{ copy('تسجيل مفتاح مرور', 'Register passkey') }}</button><button v-if="busy" type="button" data-enrollment-cancel @click="cancel">{{ copy('إلغاء عملية المتصفح', 'Cancel browser ceremony') }}</button></form>
            <ul><li v-for="key in enrollment.passkeys" :key="key.id" :data-enrollment-key="key.id">{{ key.name }}</li></ul>
        </template>
        <p v-if="error" role="alert">{{ error }}</p>
        <button type="button" data-enrollment-review :disabled="busy" @click="review">{{ copy('مراجعة الحالة دون إعادة التنفيذ', 'Review state without replay') }}</button>
        <p>{{ enrollment.attested ? copy('أُقرّت الأهلية؛ التفعيل ودخول الإدارة يظلان مستقلين.', 'Eligibility is attested; activation and internal login remain separate.') : copy('بانتظار إقرار الأهلية من الإدارة.', 'Awaiting administrative eligibility attestation.') }}</p>
        <a href="/internal/login">{{ copy('دخول الإدارة بمستند مستقل', 'Internal login in a separate document') }}</a>
    </main>
</template>

<style scoped>
.enrollment{max-width:760px}.enrollment p{line-height:1.8;margin:20px 0}.enrollment form{display:grid;gap:14px;max-width:520px}.enrollment input,.enrollment button{padding:12px;border:1px solid #14635b;border-radius:5px}.enrollment button:disabled{opacity:.5}.enrollment a{text-decoration:underline;display:inline-block;margin:16px 0}.enrollment [role=alert]{color:#a12c27}.enrollment :focus-visible{outline:2px solid #14635b;outline-offset:3px}
</style>
