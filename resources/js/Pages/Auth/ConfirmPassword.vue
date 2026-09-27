<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="تأكيد كلمة المرور" />

        <div dir="rtl" class="mb-4 text-sm leading-6 text-gray-600">
            أدخل كلمة مرورك مجددًا لتأكيد هويتك قبل إجراء التغيير الحساس.
        </div>

        <form dir="rtl" @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="كلمة المرور" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 flex justify-end">
                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'جارٍ التحقق…' : 'تأكيد' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
