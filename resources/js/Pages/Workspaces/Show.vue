<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SourceRegistryPanel from './SourceRegistryPanel.vue';
import MetaConnectionPanel from './MetaConnectionPanel.vue';
import GoogleConnectionPanel from './GoogleConnectionPanel.vue';
import TikTokConnectionPanel from './TikTokConnectionPanel.vue';
import SnapchatConnectionPanel from './SnapchatConnectionPanel.vue';
import XConnectionPanel from './XConnectionPanel.vue';

const props = defineProps<{
    workspace: { public_id: string; name: string; slug: string };
    canManageServices: boolean;
    canRequestSourceAuthority: boolean;
    sourceCandidateId: string;
    sources: InstanceType<typeof SourceRegistryPanel>['$props']['sources'];
    services: { public_id: string; name: string; purpose: string }[];
    commercial: { state: string; context: { scope_id: string; market_label: string; configuration_id: string; catalog_identity: string; catalog_version: string; denomination_code: string; effective_at: string } | null; agreement_state: string; amount_state: string };
}>();
const form = useForm({ service: '', purpose: '' });
const busy = ref('');
const error = ref('');

function authorizeService() {
    form.post(route('workspaces.services.store', props.workspace.public_id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function revokeService(service: string) {
    error.value = '';
    busy.value = service;
    router.delete(route('workspaces.services.destroy', [props.workspace.public_id, service]), {
        preserveScroll: true,
        onError: () => { error.value = 'تعذّر سحب نطاق الخدمة.'; },
        onFinish: () => { busy.value = ''; },
    });
}
</script>

<template>
    <Head :title="workspace.name" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-semibold text-slate-900">{{ workspace.name }}</h1></template>
        <main dir="rtl" class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <MetaConnectionPanel :key="workspace.public_id" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
            <GoogleConnectionPanel :key="`google-${workspace.public_id}`" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
            <TikTokConnectionPanel :key="`tiktok-${workspace.public_id}`" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
            <XConnectionPanel :key="`x-${workspace.public_id}`" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
            <SnapchatConnectionPanel :key="`snapchat-${workspace.public_id}`" :workspace-id="workspace.public_id" :can-manage="canRequestSourceAuthority" />
            <SourceRegistryPanel :workspace-id="workspace.public_id" :candidate-id="sourceCandidateId" :can-request-authority="canRequestSourceAuthority" :sources="sources" />
            <Link :href="route('workspaces.index')" class="text-sm font-medium text-indigo-700 underline hover:text-indigo-900">العودة إلى مساحات العمل</Link>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="workspace-heading">
                <h2 id="workspace-heading" class="text-xl font-semibold text-slate-900">{{ workspace.name }}</h2>
                <p class="mt-2 text-sm text-slate-600" dir="ltr">{{ workspace.slug }}</p>
                <p class="mt-6 text-sm leading-7 text-slate-700">هذه المساحة متاحة لك بعضوية نشطة ومنحة عرض صريحة. تُطبّق حدود المساحة على بياناتها في قاعدة البيانات.</p>
            </section>
            <section v-if="canManageServices" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="services-heading">
                <h2 id="services-heading" class="text-lg font-semibold text-slate-900">صلاحية الخدمات لهذه المساحة</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">موافقتك تربط الخدمة بهذه المساحة فقط. تحتاج الخدمة أيضًا إلى هوية نشطة ومنحة فعل مستقلة من إدارة Builderad، ويمكنك سحب الربط في أي وقت.</p>
                <form class="mt-5 grid gap-4 sm:grid-cols-[1fr_2fr_auto] sm:items-end" @submit.prevent="authorizeService">
                    <div><label for="service-id" class="block text-sm font-medium text-slate-700">معرّف الخدمة</label><input id="service-id" v-model="form.service" required type="text" autocomplete="off" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="form.errors.service" role="alert" class="mt-1 text-xs text-red-700">{{ form.errors.service }}</p></div>
                    <div><label for="service-purpose" class="block text-sm font-medium text-slate-700">الغرض من الربط</label><input id="service-purpose" v-model="form.purpose" required minlength="10" maxlength="128" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="form.errors.purpose" role="alert" class="mt-1 text-xs text-red-700">{{ form.errors.purpose }}</p></div>
                    <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ form.processing ? 'جارٍ الربط…' : 'السماح بالنطاق' }}</button>
                </form>
                <p v-if="error" role="alert" class="mt-4 text-sm text-red-700">{{ error }}</p>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100">
                    <li v-for="service in services" :key="service.public_id" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="font-medium text-slate-900">{{ service.name }}</p><p class="mt-1 text-xs text-slate-600" dir="ltr">{{ service.public_id }}</p><p class="mt-1 text-sm text-slate-600">{{ service.purpose }}</p></div>
                        <button type="button" :disabled="!!busy" class="self-start text-sm font-medium text-red-700 underline disabled:opacity-50" @click="revokeService(service.public_id)">سحب النطاق</button>
                    </li>
                    <li v-if="!services.length" class="py-4 text-sm text-slate-600">لا توجد خدمات مرتبطة بهذه المساحة.</li>
                </ul>
            </section>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6" aria-labelledby="commercial-context-heading">
                <h2 id="commercial-context-heading" class="text-lg font-semibold">السياق التجاري</h2>
                <p v-if="!commercial.context" class="mt-3 text-sm text-slate-600">السياق التجاري غير متاح ضمن صلاحيتك الحالية. عضوية المساحة لا تمنح عرض السياق التجاري.</p>
                <dl v-else class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt>النطاق التجاري</dt><dd dir="ltr" class="break-all">{{ commercial.context.scope_id }}</dd></div><div><dt>السوق المسند</dt><dd>{{ commercial.context.market_label }}</dd></div><div><dt>إصدار الإعدادات المثبت</dt><dd dir="ltr" class="break-all">{{ commercial.context.configuration_id }}</dd></div><div><dt>سياق العملة المستقل</dt><dd dir="ltr">{{ commercial.context.denomination_code }}</dd></div><div><dt>مرجع Catalog وإصداره</dt><dd dir="ltr" class="break-all">{{ commercial.context.catalog_identity }} / {{ commercial.context.catalog_version }}</dd></div></dl>
                <p v-if="commercial.context" class="mt-4 text-sm text-slate-600">تم تسجيل السياق. الاتفاقية والمبلغ غير مثبتين؛ هذا السجل لا يقرر صلاحية الوصول إلى المنتج.</p>
            </section>
        </main>
    </AuthenticatedLayout>
</template>
