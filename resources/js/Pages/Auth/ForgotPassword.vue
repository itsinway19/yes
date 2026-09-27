<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="استعادة كلمة المرور" />

        <div dir="rtl" class="mb-4 text-sm leading-6 text-gray-600">
            أدخل بريد حسابك. إذا كان الحساب مؤهلًا، سنجهّز رابطًا مؤقتًا لإعادة تعيين كلمة المرور.
        </div>

        <div
            v-if="status"
            dir="rtl" role="status" class="mb-4 text-sm font-medium text-green-700"
        >
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
                    dir="ltr"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'جارٍ الطلب…' : 'طلب رابط الاستعادة' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
