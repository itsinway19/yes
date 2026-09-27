<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps<{
    verified: boolean;
    kind: 'founder' | 'employee';
    passkeys: { id: number; name: string; created_at: string }[];
    attested: boolean;
}>();
const name = ref('');
const busy = ref(false);
const error = ref('');
let operationId: string | null = null;
const cancelPending = () => {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    error.value = 'طُلب إلغاء العملية من المتصفح. ستبقى المحاولات الجديدة متوقفة حتى تنتهي العملية الحالية.';
};
onMounted(() => window.addEventListener('pagehide', cancelPending));
onUnmounted(() => { window.removeEventListener('pagehide', cancelPending); cancelPending(); });

async function enroll() {
    if (busy.value) return;
    error.value = '';
    if (!name.value.trim()) return;
    busy.value = true;
    try {
        const operation = runPasskeyOperation('staff-registration', {
            options: route('staff.onboarding.passkeys.options'),
            submit: route('staff.onboarding.passkeys.store'),
        }, routes => Passkeys.register({ name: name.value.trim(), routes }));
        operationId = operation.id;
        await operation.promise;
        name.value = '';
        router.reload({ only: ['passkeys'] });
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : 'تعذّر تسجيل مفتاح المرور.';
    } finally {
        operationId = null;
        busy.value = false;
    }
}
</script>

<template>
    <Head title="تهيئة الحساب الداخلي" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-slate-900">تهيئة الحساب الداخلي</h1></template>
        <main dir="rtl" class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h2 class="text-lg font-semibold text-slate-900">إكمال وسائل الدخول</h2>
                <p class="mt-2 text-sm leading-7 text-slate-600">هذا المسار مخصص للتجهيز فقط. تتطلب الصلاحيات الداخلية إثبات البريد، وإقرار الأهلية، وتفعيل الحساب، ومنح الفعل المطلوب بصورة مستقلة.</p>
                <div v-if="!verified" class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                    تحقّق من بريدك أولًا.
                    <Link :href="route('verification.notice')" class="ms-2 font-medium underline">إرسال رابط التحقق</Link>
                </div>
                <div v-else class="mt-6">
                    <p class="text-sm text-slate-700">المفاتيح المسجلة: {{ passkeys.length }} من {{ kind === 'founder' ? 2 : 1 }} المطلوبة.</p>
                    <p v-if="kind === 'founder'" class="mt-2 text-sm text-slate-600">استخدم وسيلتين مستقلتين أساسيتين واحتياطيتين قبل طلب التفعيل.</p>
                    <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="enroll">
                        <label for="enrollment-key-name" class="sr-only">اسم مفتاح المرور</label>
                        <input id="enrollment-key-name" v-model="name" required maxlength="255" type="text" autocomplete="off" placeholder="اسم مفتاح المرور" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <button type="submit" :disabled="busy || !name.trim()" class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ busy ? 'جارٍ التسجيل…' : 'تسجيل مفتاح مرور' }}</button>
                        <button v-if="busy" type="button" @click="cancelPending" class="rounded px-3 py-2 text-sm text-slate-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">إلغاء المحاولة</button>
                    </form>
                    <p v-if="error" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
                    <ul class="mt-5 divide-y divide-slate-100 border-t border-slate-100">
                        <li v-for="key in passkeys" :key="key.id" class="py-3 text-sm text-slate-700">{{ key.name }}</li>
                    </ul>
                </div>
                <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">{{ attested ? 'أُقرّت أهليتك، وسيظهر الدخول الداخلي بعد التفعيل.' : 'بانتظار إقرار الأهلية من إدارة Builderad.' }}</p>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
