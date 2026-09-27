<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Passkeys } from '@laravel/passkeys';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';
import { passkeyLoginErrorKey } from '@/localization/passkeyError';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps<{ canResetPassword?: boolean; status?: string }>();
const { t } = useI18n({ useScope: 'global' });
const form = useForm({ email: '', password: '', remember: false });
const passkeySupported = ref(false);
const supportChecked = ref(false);
const passkeyBusy = ref(false);
const passkeyErrorKey = ref('');
let operationId: string | null = null;

function cancelPending() {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    passkeyErrorKey.value = 'login.passkeyCancelRequested';
}

onMounted(() => {
    passkeySupported.value = Passkeys.isSupported();
    supportChecked.value = true;
    window.addEventListener('pagehide', cancelPending);
});
onUnmounted(() => {
    window.removeEventListener('pagehide', cancelPending);
    cancelPending();
});

async function signInWithPasskey() {
    if (passkeyBusy.value) return;
    passkeyErrorKey.value = '';
    passkeyBusy.value = true;
    try {
        const operation = runPasskeyOperation('login',
            { options: route('auth.passkey.options'), submit: route('auth.passkey.store') },
            routes => Passkeys.verify({ routes }));
        operationId = operation.id;
        const result = await operation.promise;
        window.location.assign(result.redirect || route('dashboard'));
    } catch (error) {
        passkeyErrorKey.value = passkeyLoginErrorKey(error);
    } finally {
        operationId = null;
        passkeyBusy.value = false;
    }
}

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('login.title')" />
        <LanguageSwitcher route-name="locale.update" :disabled="passkeyBusy || form.processing" />

        <div v-if="status" role="status" class="mb-4 text-sm font-medium text-green-600">{{ status }}</div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" :value="t('login.email')" />
                <TextInput id="email" v-model="form.email" type="email" dir="ltr"
                    :aria-invalid="Boolean(form.errors.email)" aria-describedby="email-error"
                    class="mt-1 block w-full" required autofocus autocomplete="username" />
                <InputError id="email-error" class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" :value="t('login.password')" />
                <TextInput id="password" v-model="form.password" type="password"
                    :aria-invalid="Boolean(form.errors.password)" aria-describedby="password-error"
                    class="mt-1 block w-full" required autocomplete="current-password" />
                <InputError id="password-error" class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    <span class="ms-2 text-sm text-gray-600">{{ t('login.remember') }}</span>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-4">
                <div class="flex items-center gap-4">
                    <Link v-if="canResetPassword" :href="route('password.request')"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        {{ t('login.forgotPassword') }}
                    </Link>
                    <Link :href="route('register')"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        {{ t('login.register') }}
                    </Link>
                </div>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing || passkeyBusy">
                    {{ t(form.processing ? 'login.submitting' : 'login.submit') }}
                </PrimaryButton>
            </div>
        </form>

        <div v-if="supportChecked" class="mt-6 border-t border-gray-200 pt-6">
            <p v-if="passkeyErrorKey" role="alert" class="mb-3 text-sm text-red-700">{{ t(passkeyErrorKey) }}</p>
            <p v-if="!passkeySupported" role="status" class="text-sm text-slate-600">{{ t('login.passkeyUnsupported') }}</p>
            <template v-else>
                <button type="button" :disabled="passkeyBusy || form.processing" @click="signInWithPasskey"
                    class="w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                    {{ t(passkeyBusy ? 'login.passkeyLoading' : 'login.passkey') }}
                </button>
                <button v-if="passkeyBusy" type="button" @click="cancelPending"
                    class="mt-2 w-full rounded px-3 py-2 text-sm text-slate-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    {{ t('login.passkeyCancel') }}
                </button>
            </template>
        </div>
    </GuestLayout>
</template>
