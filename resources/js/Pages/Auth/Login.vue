<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Passkeys } from '@laravel/passkeys';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});
const passkeySupported = ref(false);
const passkeyBusy = ref(false);
const passkeyError = ref('');
let operationId: string | null = null;
const cancelPending = () => {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    passkeyError.value = 'طُلب إلغاء التحقق من المتصفح. ستبقى المحاولات الجديدة متوقفة حتى تنتهي العملية الحالية.';
};

onMounted(() => {
    passkeySupported.value = Passkeys.isSupported();
    window.addEventListener('pagehide', cancelPending);
});
onUnmounted(() => {
    window.removeEventListener('pagehide', cancelPending);
    cancelPending();
});

async function signInWithPasskey() {
    if (passkeyBusy.value) return;
    passkeyError.value = '';
    passkeyBusy.value = true;
    try {
        const operation = runPasskeyOperation('login',
            { options: route('auth.passkey.options'), submit: route('auth.passkey.store') },
            routes => Passkeys.verify({ routes }));
        operationId = operation.id;
        const result = await operation.promise;
        window.location.assign(result.redirect || route('dashboard'));
    } catch (error) {
        passkeyError.value = error instanceof Error && error.name === 'UserCancelledError'
            ? 'لم يكتمل اختيار مفتاح المرور في المتصفح. تأكد من توفر المفتاح المسجّل في هذا الجهاز أو ملف المتصفح، ثم حاول مجددًا.'
            : error instanceof Error ? error.message : 'تعذّر تسجيل الدخول بمفتاح المرور.';
    } finally {
        operationId = null;
        passkeyBusy.value = false;
    }
}

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="تسجيل الدخول" />

        <div v-if="status" dir="rtl" role="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form dir="rtl" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="البريد الإلكتروني" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="كلمة المرور" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600"
                        >تذكرني</span
                    >
                </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-4">
                <div class="flex items-center gap-4">
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        نسيت كلمة المرور؟
                    </Link>
                    <Link
                        :href="route('register')"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        ليس لديك حساب؟
                    </Link>
                </div>

                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'جارٍ الدخول…' : 'تسجيل الدخول' }}
                </PrimaryButton>
            </div>
        </form>
        <div v-if="passkeySupported" class="mt-6 border-t border-gray-200 pt-6" dir="rtl">
            <p v-if="passkeyError" role="alert" class="mb-3 text-sm text-red-700">{{ passkeyError }}</p>
            <button type="button" :disabled="passkeyBusy || form.processing" @click="signInWithPasskey"
                class="w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                {{ passkeyBusy ? 'جارٍ التحقق…' : 'الدخول بمفتاح المرور' }}
            </button>
            <button v-if="passkeyBusy" type="button" @click="cancelPending"
                class="mt-2 w-full rounded px-3 py-2 text-sm text-slate-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">إلغاء محاولة مفتاح المرور</button>
        </div>
    </GuestLayout>
</template>
