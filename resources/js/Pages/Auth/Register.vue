<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { generatePassword } from '@/security/generatePassword';
import { authenticateIdentity } from '@/identityProtocol';

const props = defineProps<{
    passwordPolicy: { min: number; max: number; browserRules: string };
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const serviceError = computed(() => (form.errors as Record<string, string>).service);
const registrationForm = ref<HTMLFormElement | null>(null);
const passwordLength = computed(() => Array.from(form.password).length);
const charactersRemaining = computed(() => Math.max(props.passwordPolicy.min - passwordLength.value, 0));
const minimumLengthMet = computed(() => charactersRemaining.value === 0);
let autofillTimer: number | undefined;
const generationStatus = ref('');
const generatedPassword = ref(false);
const generate = () => {
    try {
        form.password = generatePassword(props.passwordPolicy);
        form.password_confirmation = form.password;
        form.clearErrors('password', 'password_confirmation');
        generatedPassword.value = true;
        generationStatus.value = 'تم إنشاء كلمة مرور من 24 خانة وتعبئة التأكيد. احفظها في مدير كلمات المرور قبل المتابعة.';
    } catch {
        generationStatus.value = 'تعذّر توليد كلمة المرور. يمكنك كتابتها أو استخدام مدير كلمات المرور.';
    }
};
const copyPassword = async () => {
    try {
        await navigator.clipboard.writeText(form.password);
        generationStatus.value = 'نُسخت كلمة المرور. احفظها في مدير كلمات المرور.';
    } catch {
        generationStatus.value = 'تعذّر النسخ. يمكنك تحديد محتوى حقل كلمة المرور ونسخه يدويًا.';
    }
};

const syncAutofilledPassword = () => {
    const input = registrationForm.value?.elements.namedItem('password') as HTMLInputElement | null;
    if (input && input.value !== form.password) form.password = input.value;
};

onMounted(() => {
    syncAutofilledPassword();
    // Password managers may set the DOM value without dispatching an input event.
    autofillTimer = window.setInterval(syncAutofilledPassword, 250);
});
onUnmounted(() => window.clearInterval(autofillTimer));

const submit = async (event: Event) => {
    if (form.processing) return;
    const fields = (event.currentTarget as HTMLFormElement).elements;
    for (const key of ['name', 'email', 'password', 'password_confirmation'] as const) {
        const input = fields.namedItem(key) as HTMLInputElement | null;
        if (input) form[key] = input.value;
    }

    form.clearErrors();
    const length = Array.from(form.password).length;
    if (length < props.passwordPolicy.min) {
        form.setError('password', `استخدم كلمة مرور من ${props.passwordPolicy.min} حرفًا على الأقل. إذا كان الاقتراح أقصر، اختر اقتراحًا أطول.`);
        return;
    }
    if (length > props.passwordPolicy.max) {
        form.setError('password', `يجب ألا تتجاوز كلمة المرور ${props.passwordPolicy.max} حرفًا.`);
        return;
    }
    if (form.password !== form.password_confirmation) {
        form.setError('password_confirmation', 'كلمة المرور وتأكيدها غير متطابقين.');
        return;
    }

    form.processing = true;
    try {
        const result = await authenticateIdentity(route('register'), form.data());
        form.reset('password', 'password_confirmation');
        window.location.assign(result.redirect);
    } catch (error) {
        const failure = error as { errors?: Record<string, string[]>; message?: string };
        if (failure.errors) for (const [field, messages] of Object.entries(failure.errors)) form.setError(field as 'name' | 'email' | 'password' | 'password_confirmation', messages[0]);
        else form.setError('password', failure.message || 'تعذّر إكمال التسجيل.');
    } finally { form.processing = false; }
};
</script>

<template>
    <GuestLayout>
        <Head title="إنشاء حساب معلن" />

        <form ref="registrationForm" dir="rtl" @submit.prevent="submit">
            <InputError role="alert" class="mb-4" :message="serviceError" />
            <div>
                <InputLabel for="name" value="الاسم" />

                <TextInput
                    id="name"
                    name="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="البريد الإلكتروني" />

                <TextInput
                    id="email"
                    name="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="كلمة المرور" />

                <TextInput
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordPolicy.browserRules"
                    :minlength="passwordPolicy.min"
                    :maxlength="passwordPolicy.max * 2"
                    aria-describedby="password-guidance password-length password-error"
                    :aria-invalid="Boolean(form.errors.password)"
                    @input="form.clearErrors('password')"
                />

                <InputError id="password-error" role="alert" class="mt-2" :message="form.errors.password" />
                <!-- HTML counts UTF-16 units; submit and Laravel count Unicode code points. -->
                <div class="mt-2 flex flex-wrap gap-4 text-sm">
                    <button type="button" :disabled="form.processing" @click="generate"
                        class="rounded text-indigo-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">توليد كلمة مرور قوية</button>
                    <button v-if="generatedPassword" type="button" :disabled="!form.password || form.processing" @click="copyPassword"
                        class="rounded text-indigo-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">نسخ كلمة المرور لحفظها</button>
                </div>
                <p role="status" aria-live="polite" class="mt-2 text-xs text-gray-600">{{ generationStatus }}</p>
                <p id="password-guidance" class="mt-1 text-xs leading-5 text-gray-600">
                    كلمة المرور يجب أن تتكون من {{ passwordPolicy.min }} خانة على الأقل. يمكنك استخدام الحروف والأرقام
                    والرموز دون اشتراط مزيج محدد. الحد الأقصى {{ passwordPolicy.max }} خانة.
                </p>
                <div id="password-length" role="status" aria-live="polite" aria-atomic="true"
                    class="mt-2 flex items-center gap-2 text-sm"
                    :class="minimumLengthMet ? 'text-green-700' : 'text-gray-600'">
                    <svg v-if="minimumLengthMet" aria-hidden="true" viewBox="0 0 20 20"
                        class="h-5 w-5 shrink-0 fill-none stroke-current" stroke-width="1.8">
                        <circle cx="10" cy="10" r="8" />
                        <path d="m6 10 2.5 2.5L14 7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span v-if="minimumLengthMet">اكتمل الحد الأدنى للطول. سيُفحص أمان كلمة المرور عند الإرسال.</span>
                    <span v-else>المتبقي للحد الأدنى: {{ charactersRemaining }}</span>
                </div>
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="تأكيد كلمة المرور"
                />

                <TextInput
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                    :minlength="passwordPolicy.min"
                    :maxlength="passwordPolicy.max * 2"
                    :aria-invalid="Boolean(form.errors.password_confirmation)"
                    aria-describedby="password-confirmation-error"
                    @input="form.clearErrors('password_confirmation')"
                />

                <InputError
                    id="password-confirmation-error"
                    role="alert"
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    لديك حساب بالفعل؟
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'جارٍ الإنشاء…' : 'إنشاء حساب معلن' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
