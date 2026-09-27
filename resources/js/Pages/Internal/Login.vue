<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';

defineProps<{ status?: string }>();
const supported = ref(false);
const busy = ref(false);
const error = ref('');
let operationId: string | null = null;

function cancelPending() {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    error.value = 'طُلب إلغاء التحقق من المتصفح. انتظر انتهاء العملية الحالية.';
}

onMounted(() => {
    supported.value = Passkeys.isSupported();
    window.addEventListener('pagehide', cancelPending);
});
onUnmounted(() => {
    window.removeEventListener('pagehide', cancelPending);
    cancelPending();
});

async function signIn() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const operation = runPasskeyOperation('internal-login', {
            options: route('internal.auth.passkey.options'),
            submit: route('internal.auth.passkey.store'),
        }, routes => Passkeys.verify({ routes }));
        operationId = operation.id;
        const result = await operation.promise as { redirect?: string };
        window.location.assign(result.redirect || route('internal.administration'));
    } catch (failure) {
        error.value = failure instanceof Error && failure.name === 'UserCancelledError'
            ? 'لم يكتمل اختيار مفتاح المرور. اختر مفتاحًا مسجّلًا وأكمل التحقق في المتصفح.'
            : failure instanceof Error ? failure.message : 'تعذّر الدخول بمفتاح المرور.';
    } finally {
        operationId = null;
        busy.value = false;
    }
}
</script>

<template>
    <GuestLayout>
        <Head title="دخول الإدارة الداخلية" />
        <div dir="rtl">
            <h1 class="text-xl font-semibold text-slate-900">دخول الإدارة الداخلية</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">هذا الدخول مخصص للحسابات الداخلية المفعّلة. استخدم مفتاح المرور المسجّل لحسابك الداخلي.</p>
            <p v-if="status" role="status" class="mt-4 text-sm text-green-700">{{ status }}</p>
            <p v-if="error" role="alert" class="mt-4 text-sm text-red-700">{{ error }}</p>
            <p v-if="!supported" role="alert" class="mt-4 text-sm text-red-700">هذا المتصفح لا يدعم مفاتيح المرور. افتح الصفحة عبر متصفح مدعوم على localhost.</p>
            <button type="button" :disabled="busy || !supported" @click="signIn" class="mt-6 w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                {{ busy ? 'جارٍ التحقق…' : 'الدخول بمفتاح المرور' }}
            </button>
            <button v-if="busy" type="button" @click="cancelPending" class="mt-3 w-full rounded px-3 py-2 text-sm underline">إلغاء المحاولة</button>
            <Link :href="route('login')" class="mt-6 block text-center text-sm text-slate-600 underline">دخول المعلنين</Link>
        </div>
    </GuestLayout>
</template>
