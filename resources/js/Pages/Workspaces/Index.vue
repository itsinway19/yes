<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

type Workspace = { public_id: string; name: string; slug: string };
defineProps<{ workspaces: Workspace[]; reassessableWorkspaces: Workspace[] }>();

const form = useForm({ name: '', slug: '' });
const reassessing = ref<string | null>(null);
const reassessmentError = ref('');
function createWorkspace() {
    form.post(route('workspaces.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function reassessOwnerGrant(workspace: string) {
    reassessmentError.value = '';
    reassessing.value = workspace;
    router.post(route('workspaces.reassess-owner-grant', workspace), {}, {
        preserveScroll: true,
        onError: () => { reassessmentError.value = 'تعذّرت إعادة تقييم صلاحية المالك. تحقّق من تسجيل الدخول الحديث وحالة المساحة.'; },
        onFinish: () => { reassessing.value = null; },
    });
}
</script>

<template>
    <Head title="مساحات العمل" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-slate-900">مساحات العمل</h1></template>
        <div dir="rtl" class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_22rem]">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="workspaces-title">
                <h2 id="workspaces-title" class="text-lg font-semibold text-slate-900">مساحاتك</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">كل مساحة معزولة بعضوية صريحة ومالك مسؤول. لا يتيح اسم المساحة أو رابطها الوصول إليها دون صلاحية.</p>
                <ul v-if="workspaces.length" class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                    <li v-for="workspace in workspaces" :key="workspace.public_id" class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-medium text-slate-900">{{ workspace.name }}</h3><p class="text-sm text-slate-500" dir="ltr">{{ workspace.slug }}</p></div>
                        <Link :href="route('workspaces.show', workspace.public_id)" class="self-start text-sm font-medium text-indigo-700 underline hover:text-indigo-900">عرض المساحة</Link>
                    </li>
                </ul>
                <p v-else class="mt-6 rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">لم تُنشئ مساحة عمل بعد.</p>
            </section>
            <section v-if="reassessableWorkspaces.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm sm:p-8 sm:col-span-2" aria-labelledby="reassess-title">
                <h2 id="reassess-title" class="text-lg font-semibold text-slate-900">إعادة تقييم صلاحية المالك</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">بعد تغيير البريد وتأكيده، راجع مساحاتك القائمة بدخول حديث. لا تُعاد أي منحة سُحبت أو انتهت صلاحيتها.</p>
                <p v-if="reassessmentError" role="alert" class="mt-3 text-sm text-red-700">{{ reassessmentError }}</p>
                <ul class="mt-4 divide-y divide-amber-200">
                    <li v-for="workspace in reassessableWorkspaces" :key="workspace.public_id" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div><strong class="text-slate-900">{{ workspace.name }}</strong><p class="text-xs text-slate-600" dir="ltr">{{ workspace.slug }}</p></div>
                        <button type="button" :disabled="!!reassessing" class="self-start rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" @click="reassessOwnerGrant(workspace.public_id)">{{ reassessing === workspace.public_id ? 'جارٍ التقييم…' : 'إعادة تقييم الصلاحية' }}</button>
                    </li>
                </ul>
            </section>
            <section class="self-start rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="create-title">
                <h2 id="create-title" class="text-lg font-semibold text-slate-900">مساحة جديدة</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">تصبح المالك المسؤول عند إنشاء المساحة.</p>
                <form class="mt-6 space-y-4" @submit.prevent="createWorkspace">
                    <div>
                        <label for="workspace-name" class="block text-sm font-medium text-slate-700">اسم المساحة</label>
                        <input id="workspace-name" v-model="form.name" type="text" required minlength="2" maxlength="120" autocomplete="organization" class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p v-if="form.errors.name" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label for="workspace-slug" class="block text-sm font-medium text-slate-700">معرّف الرابط</label>
                        <input id="workspace-slug" v-model="form.slug" type="text" required minlength="3" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" dir="ltr" autocomplete="off" class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <p class="mt-1 text-xs text-slate-500">أحرف إنجليزية صغيرة وأرقام وشرطات فقط.</p>
                        <p v-if="form.errors.slug" role="alert" class="mt-1 text-sm text-red-700">{{ form.errors.slug }}</p>
                    </div>
                    <button type="submit" :disabled="form.processing" class="w-full rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-700 disabled:opacity-50">{{ form.processing ? 'جارٍ الإنشاء…' : 'إنشاء مساحة العمل' }}</button>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
