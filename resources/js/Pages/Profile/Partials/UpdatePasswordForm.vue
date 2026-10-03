<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const expanded = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const serviceError = computed(() => (form.errors as Record<string, string>).service);

const togglePasswordForm = async () => {
    if (form.processing) return;
    expanded.value = !expanded.value;
    if (expanded.value) {
        await nextTick();
        currentPasswordInput.value?.focus();
    } else {
        // Discard unsaved secrets while retaining validation and submitted feedback.
        form.reset();
    }
};

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section dir="rtl" aria-labelledby="password-title">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="password-title" class="text-lg font-semibold text-slate-900">كلمة المرور</h2>
                <p id="password-help" class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    استخدم كلمة مرور طويلة وفريدة. ستنتهي الجلسات الأخرى بعد تغييرها.
                </p>
            </div>
            <button type="button" :aria-expanded="expanded" aria-controls="password-change-form" :disabled="form.processing"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                @click="togglePasswordForm">
                تغيير كلمة المرور
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="h-4 w-4 transition-transform" :class="{ 'rotate-180': expanded }">
                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        </header>

        <p v-if="form.recentlySuccessful" role="status" class="mt-4 text-sm font-medium text-emerald-700">تغيّرت كلمة المرور.</p>
        <InputError role="alert" :message="serviceError" class="mt-4" />
        <form v-show="expanded" id="password-change-form" aria-labelledby="password-title" aria-describedby="password-help"
            :aria-busy="form.processing" @submit.prevent="updatePassword" class="mt-6 max-w-xl space-y-6 border-t border-slate-200 pt-6">
            <div>
                <InputLabel for="current_password" value="كلمة المرور الحالية" />

                <TextInput
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    required
                    :aria-invalid="Boolean(form.errors.current_password)"
                    :aria-describedby="form.errors.current_password ? 'current-password-error' : undefined"
                />

                <InputError
                    id="current-password-error"
                    :message="form.errors.current_password"
                    class="mt-2"
                />
            </div>

            <div>
                <InputLabel for="password" value="كلمة المرور الجديدة" />

                <TextInput
                    id="password"
                    ref="passwordInput"
                    :aria-invalid="Boolean(form.errors.password)"
                    :aria-describedby="form.errors.password ? 'password-error' : undefined"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    required
                />

                <InputError id="password-error" :message="form.errors.password" class="mt-2" />
            </div>

            <div>
                <InputLabel
                    for="password_confirmation"
                    value="تأكيد كلمة المرور"
                />

                <TextInput
                    id="password_confirmation"
                    :aria-invalid="Boolean(form.errors.password_confirmation)"
                    :aria-describedby="form.errors.password_confirmation ? 'password-confirmation-error' : undefined"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    required
                />

                <InputError
                    id="password-confirmation-error"
                    :message="form.errors.password_confirmation"
                    class="mt-2"
                />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ form.processing ? 'جارٍ الحفظ…' : 'حفظ' }}</PrimaryButton>
            </div>
        </form>
    </section>
</template>
