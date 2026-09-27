<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="تأكيد البريد الإلكتروني" />

        <div dir="rtl" class="mb-4 text-sm leading-6 text-gray-600">
            أكّد بريدك قبل استخدام الحساب. طُلب تجهيز رسالة تحتوي على رابط مؤقت. إن لم تصل، يمكنك طلب رابط جديد.
        </div>

        <div
            dir="rtl" role="status" class="mb-4 text-sm font-medium text-green-700"
            v-if="verificationLinkSent"
        >
            طُلب إرسال رابط تأكيد جديد إلى بريدك.
        </div>

        <form dir="rtl" @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'جارٍ الطلب…' : 'طلب رابط تأكيد جديد' }}
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >تسجيل الخروج</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
