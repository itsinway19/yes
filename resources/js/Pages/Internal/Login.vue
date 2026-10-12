<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { passkeyLoginErrorKey } from '@/localization/passkeyError';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';
import { captureIdentity, identityDestination } from '@/identityProtocol';

defineProps<{ status?: string }>();
const { t } = useI18n({ useScope: 'global' });
const supported = ref(false);
const supportChecked = ref(false);
const busy = ref(false);
const errorKey = ref('');
let operationId: string | null = null;

function cancelPending() {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    errorKey.value = 'login.passkeyCancelRequested';
}

onMounted(() => {
    supported.value = Passkeys.isSupported();
    supportChecked.value = true;
    window.addEventListener('pagehide', cancelPending);
});
onUnmounted(() => {
    window.removeEventListener('pagehide', cancelPending);
    cancelPending();
});

async function signIn() {
    if (busy.value) return;
    busy.value = true;
    errorKey.value = '';
    try {
        const captured = captureIdentity();
        const operation = runPasskeyOperation('internal-login', {
            options: captured.binding.principal ? '/internal/identity/passkey/options' : route('internal.auth.passkey.options'),
            submit: captured.binding.principal ? '/internal/identity/passkey' : route('internal.auth.passkey.store'),
        }, routes => Passkeys.verify({ routes }));
        operationId = operation.id;
        const result = await operation.promise;
        window.location.assign(identityDestination(result, captured));
    } catch (failure) {
        errorKey.value = passkeyLoginErrorKey(failure);
    } finally {
        operationId = null;
        busy.value = false;
    }
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('login.internalTitle')" />
        <LanguageSwitcher route-name="internal.locale.update" :disabled="busy" />
        <div>
            <h1 class="text-xl font-semibold text-slate-900">{{ t('login.internalTitle') }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">{{ t('login.internalIntro') }}</p>
            <p v-if="status" role="status" class="mt-4 text-sm text-green-700">{{ status }}</p>
            <p v-if="errorKey" role="alert" class="mt-4 text-sm text-red-700">{{ t(errorKey) }}</p>
            <p v-if="supportChecked && !supported" role="alert" class="mt-4 text-sm text-red-700">
                {{ t('login.passkeyUnsupported') }}
            </p>
            <button type="button" :disabled="busy || !supported" @click="signIn"
                class="mt-6 w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                {{ t(busy ? 'login.passkeyLoading' : 'login.passkey') }}
            </button>
            <button v-if="busy" type="button" @click="cancelPending" class="mt-3 w-full rounded px-3 py-2 text-sm underline">
                {{ t('login.passkeyCancel') }}
            </button>
            <Link :href="route('login')" class="mt-6 block text-center text-sm text-slate-600 underline">
                {{ t('login.advertiserLogin') }}
            </Link>
        </div>
    </GuestLayout>
</template>
