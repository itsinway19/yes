<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

type Service = { public_id: string; name: string; status: 'enabled' | 'revoked'; version: number };
type Scope = { service_public_id: string; service_name: string; workspace_public_id: string; purpose: string };
type Grant = { id: number; service_name: string; workspace_public_id: string; reason: string };
const props = defineProps<{
    services: Service[];
    scopes: Scope[];
    grants: Grant[];
    newCredential: { public_id: string; credential: string } | null;
}>();
const create = useForm({ name: '' });
const grant = useForm({ service: '', workspace: '', reason: '' });
const busy = ref('');
const error = ref('');
const showSecret = ref(true);

function createService() {
    showSecret.value = true;
    create.post(route('internal.services.create'), { preserveScroll: true, onSuccess: () => create.reset() });
}

function action(service: string, kind: 'rotate' | 'revoke') {
    error.value = '';
    showSecret.value = true;
    busy.value = service;
    router.post(route(`internal.services.${kind}`, service), {}, {
        preserveScroll: true,
        onError: () => { error.value = 'تعذّر تعديل هوية الخدمة.'; },
        onFinish: () => { busy.value = ''; },
    });
}

function issueGrant() {
    grant.post(route('internal.service-grants.create'), { preserveScroll: true, onSuccess: () => grant.reset() });
}

function revokeGrant(id: number) {
    error.value = '';
    busy.value = `grant:${id}`;
    router.delete(route('internal.service-grants.destroy', id), {
        preserveScroll: true,
        onError: () => { error.value = 'تعذّر سحب منحة الخدمة.'; },
        onFinish: () => { busy.value = ''; },
    });
}

function selectScope(scope: Scope) {
    grant.service = scope.service_public_id;
    grant.workspace = scope.workspace_public_id;
}
</script>

<template>
    <Head title="الخدمات المستقلة" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-slate-900">الخدمات المستقلة</h1></template>
        <main dir="rtl" class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <Link :href="route('internal.administration')" class="text-sm font-medium text-indigo-700 underline">إدارة الفريق الداخلي</Link>
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>
            <section v-if="newCredential && showSecret" class="rounded-2xl border border-amber-300 bg-amber-50 p-6" aria-labelledby="credential-title">
                <h2 id="credential-title" class="font-semibold text-amber-950">اعتماد الخدمة — يظهر مرة واحدة</h2>
                <p class="mt-2 text-sm text-amber-900">احفظ الاعتماد في مخزن أسرار مناسب. لن تُعرض قيمته مجددًا، وتدويره يبطل القيمة السابقة فورًا.</p>
                <p class="mt-3 text-xs text-amber-900" dir="ltr">{{ newCredential.public_id }}</p>
                <code class="mt-2 block break-all rounded-lg bg-white p-3 text-sm text-slate-900" dir="ltr">{{ newCredential.credential }}</code>
                <button type="button" class="mt-3 text-sm font-medium text-amber-900 underline" @click="showSecret = false">إخفاء الاعتماد</button>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="service-create-title">
                <h2 id="service-create-title" class="text-lg font-semibold text-slate-900">هوية خدمة جديدة</h2>
                <p class="mt-2 text-sm text-slate-600">الهوية وحدها لا تمنح وصولًا إلى أي مساحة. يوافق مالك المساحة على نطاقها، ثم تُصدر الإدارة منحة الفعل.</p>
                <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="createService">
                    <div class="flex-1"><label for="service-name" class="block text-sm font-medium text-slate-700">اسم الخدمة</label><input id="service-name" v-model="create.name" required minlength="2" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="create.errors.name" role="alert" class="mt-1 text-sm text-red-700">{{ create.errors.name }}</p></div>
                    <button type="submit" :disabled="create.processing" class="self-end rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ create.processing ? 'جارٍ الإنشاء…' : 'إنشاء الهوية' }}</button>
                </form>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100">
                    <li v-for="service in services" :key="service.public_id" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="font-medium text-slate-900">{{ service.name }} <span class="text-xs text-slate-500">{{ service.status === 'enabled' ? 'نشطة' : 'ملغاة' }}</span></p><p class="mt-1 text-xs text-slate-600" dir="ltr">{{ service.public_id }}</p></div>
                        <div v-if="service.status === 'enabled'" class="flex gap-4"><button type="button" :disabled="!!busy" class="text-sm text-indigo-700 underline disabled:opacity-50" @click="action(service.public_id, 'rotate')">تدوير الاعتماد</button><button type="button" :disabled="!!busy" class="text-sm text-red-700 underline disabled:opacity-50" @click="action(service.public_id, 'revoke')">إلغاء الهوية</button></div>
                    </li>
                    <li v-if="!services.length" class="py-4 text-sm text-slate-600">لا توجد خدمات.</li>
                </ul>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="scopes-title">
                <h2 id="scopes-title" class="text-lg font-semibold text-slate-900">نطاقات وافق عليها المالكون</h2>
                <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100"><li v-for="scope in scopes" :key="`${scope.service_public_id}:${scope.workspace_public_id}`" class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ scope.service_name }}</strong><p class="text-xs text-slate-600" dir="ltr">{{ scope.workspace_public_id }}</p><p class="text-slate-600">{{ scope.purpose }}</p></div><button type="button" class="self-start text-indigo-700 underline" @click="selectScope(scope)">اختيار للمنحة</button></li><li v-if="!scopes.length" class="py-4 text-sm text-slate-600">لم يوافق أي مالك على نطاق خدمة بعد.</li></ul>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="service-grants-title">
                <h2 id="service-grants-title" class="text-lg font-semibold text-slate-900">منحة عرض مساحة للخدمة</h2>
                <form class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_2fr_auto] md:items-end" @submit.prevent="issueGrant">
                    <div><label for="grant-service" class="block text-sm font-medium text-slate-700">الخدمة</label><input id="grant-service" v-model="grant.service" required dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.service" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.service }}</p></div>
                    <div><label for="grant-workspace" class="block text-sm font-medium text-slate-700">المساحة</label><input id="grant-workspace" v-model="grant.workspace" required dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.workspace" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.workspace }}</p></div>
                    <div><label for="grant-reason" class="block text-sm font-medium text-slate-700">السبب</label><input id="grant-reason" v-model="grant.reason" required minlength="10" maxlength="128" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.reason" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.reason }}</p></div>
                    <button type="submit" :disabled="grant.processing" class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ grant.processing ? 'جارٍ المنح…' : 'إصدار المنحة' }}</button>
                </form>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100"><li v-for="item in grants" :key="item.id" class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ item.service_name }}</strong><p class="text-xs text-slate-600" dir="ltr">{{ item.workspace_public_id }}</p><p class="text-slate-600">{{ item.reason }}</p></div><button type="button" :disabled="!!busy" class="self-start text-red-700 underline disabled:opacity-50" @click="revokeGrant(item.id)">سحب المنحة</button></li><li v-if="!grants.length" class="py-4 text-sm text-slate-600">لا توجد منح خدمة نشطة.</li></ul>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
