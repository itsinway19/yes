<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    canChangeEmail: boolean;
    status?: string;
}>();
</script>

<template>
    <Head title="الملف الشخصي" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                الملف الشخصي
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :can-change-email="canChangeEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <section dir="rtl" class="max-w-xl">
                        <h2 class="text-lg font-medium text-gray-900">أمان الحساب</h2>
                        <p class="mt-1 text-sm leading-6 text-gray-600">إدارة كلمة المرور ومفاتيح المرور والجلسات من قسم الأمان.</p>
                        <Link :href="route('security.devices')"
                            class="mt-4 inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            الانتقال إلى الأمان
                        </Link>
                    </section>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
