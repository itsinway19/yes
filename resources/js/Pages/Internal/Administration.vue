<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import InternalLayout from '@/Layouts/InternalLayout.vue';

type Person = {
    public_id: string;
    name: string;
    email: string;
    kind: 'founder' | 'employee';
    status: 'pending' | 'active' | 'revoked';
    attested_at: string | null;
    factor_independence_attested_at: string | null;
    email_verified_at: string | null;
    passkey_count: number;
};
type StaffGrant = { id: number; action: string; reason: string; public_id: string; name: string };

const props = defineProps<{
    people: Person[];
    staffGrants: StaffGrant[];
    grantableActions: string[];
    canAttestFactors: boolean;
    permissions: Record<string, boolean>;
    metaOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    googleOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    tiktokOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    xOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
    snapchatOperatorStatus: { application_configured: boolean; notice_links_configured: boolean } | null;
}>();
const invite = useForm({ name: '', email: '' });
const grant = useForm({ staff: '', action: '', reason: '' });
const busy = ref('');
const actionError = ref('');

function inviteEmployee() {
    invite.post(route('internal.staff.invite'), {
        preserveScroll: true,
        onSuccess: () => invite.reset(),
    });
}

function staffAction(person: Person, action: 'attest' | 'activate' | 'revoke' | 'resend-invitation' | 'attest-factors') {
    actionError.value = '';
    busy.value = `${person.public_id}:${action}`;
    router.post(route(`internal.staff.${action}`, person.public_id), {}, {
        preserveScroll: true,
        onError: () => { actionError.value = 'تعذّر تنفيذ الإجراء. تحقّق من الأهلية والصلاحيات ثم أعد المحاولة.'; },
        onFinish: () => { busy.value = ''; },
    });
}

function issueGrant() {
    grant.post(route('internal.grants.store'), {
        preserveScroll: true,
        onSuccess: () => grant.reset(),
    });
}

function revokeGrant(id: number) {
    actionError.value = '';
    busy.value = `grant:${id}`;
    router.delete(route('internal.grants.destroy', id), {
        preserveScroll: true,
        onError: () => { actionError.value = 'تعذّر سحب المنحة.'; },
        onFinish: () => { busy.value = ''; },
    });
}

const statusLabel: Record<Person['status'], string> = {
    pending: 'بانتظار التفعيل',
    active: 'نشط',
    revoked: 'أُلغيت الأهلية',
};
</script>

<template>
    <Head title="إدارة Builderad الداخلية" />
    <InternalLayout>
        <template #header>
            <h1 class="text-xl font-semibold text-slate-900">إدارة Builderad الداخلية</h1>
        </template>
        <div dir="rtl" class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <Link v-if="props.permissions['service.manage']" :href="route('internal.services.index')" class="mb-5 inline-block text-sm font-medium text-indigo-700 underline">إدارة الخدمات المستقلة</Link>
            <section v-if="metaOperatorStatus" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="meta-operator-title">
                <h2 id="meta-operator-title" class="text-lg font-semibold text-slate-900">إعداد ربط Meta</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">هذه حالة إعداد الخدمة المحلية فقط. لا تثبت موافقة Meta أو اعتماد سياسات البيانات أو اتصال حساب معلن أو جاهزية بياناته للتحليل.</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 p-4"><dt class="font-medium text-slate-800">إعداد التطبيق وعنوان العودة</dt><dd class="mt-1 text-slate-600">{{ metaOperatorStatus.application_configured ? 'مضبوط تقنيًا' : 'غير مكتمل' }}</dd></div>
                    <div class="rounded-xl bg-slate-50 p-4"><dt class="font-medium text-slate-800">صيغة روابط الإشعار والحذف</dt><dd class="mt-1 text-slate-600">{{ metaOperatorStatus.notice_links_configured ? 'صالحة تقنيًا؛ الاعتماد غير مثبت' : 'غير مكتملة' }}</dd></div>
                </dl>
            </section>
            <section v-if="googleOperatorStatus" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="google-operator-title">
                <h2 id="google-operator-title" class="text-lg font-semibold text-slate-900">إعداد ربط Google Ads</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">هذه حالة إعداد الخدمة المحلية فقط. لا تثبت موافقة Google أو اعتماد سياسة الخصوصية أو اتصال حساب معلن أو جاهزية بياناته للتحليل.</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 p-4"><dt class="font-medium text-slate-800">إعداد تطبيق OAuth وعنوان العودة</dt><dd class="mt-1 text-slate-600">{{ googleOperatorStatus.application_configured ? 'مضبوط تقنيًا' : 'غير مكتمل' }}</dd></div>
                    <div class="rounded-xl bg-slate-50 p-4"><dt class="font-medium text-slate-800">رابط الخصوصية ونسخة الإشعار</dt><dd class="mt-1 text-slate-600">{{ googleOperatorStatus.notice_links_configured ? 'صالحة تقنيًا؛ الاعتماد غير مثبت' : 'غير مكتملة' }}</dd></div>
                </dl>
                <p class="mt-4 text-sm leading-6 text-slate-600">تُضبط المفاتيح على الخادم في متغيرات البيئة. لا تعرض هذه اللوحة الأسرار، ويبدأ المعلن الربط من مساحة عمله.</p>
            </section>
            <section v-if="tiktokOperatorStatus" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="tiktok-operator-title">
                <h2 id="tiktok-operator-title" class="text-lg font-semibold text-slate-900">ربط TikTok</h2>
                <p class="mt-3 rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">هذه حالة إعداد الخدمة المحلية فقط. لا تثبت موافقة TikTok أو اعتماد السياسات أو اتصال حساب المعلن.</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="font-semibold">إعداد التطبيق والتفويض وعنوان العودة</dt><dd class="mt-2 text-sm text-slate-700">{{ tiktokOperatorStatus.application_configured ? 'مكتمل محليًا' : 'غير مكتمل' }}</dd></div><div><dt class="font-semibold">صيغة رابط الخصوصية وإصدار الإشعار</dt><dd class="mt-2 text-sm text-slate-700">{{ tiktokOperatorStatus.notice_links_configured ? 'مكتملة محليًا' : 'غير مكتملة' }}</dd></div></dl>
                <p class="mt-4 text-sm leading-7 text-slate-600">توضع مفاتيح TikTok على الخادم في متغيرات البيئة. الربط الفعلي والتحقق من سياسات المنصة مؤجلان.</p>
            </section>
            <section v-if="xOperatorStatus" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="x-operator-title">
                <h2 id="x-operator-title" class="text-lg font-semibold text-slate-900">ربط X</h2>
                <p class="mt-3 rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">هذه حالة إعداد الخدمة المحلية فقط. لا تثبت موافقة X أو اعتماد السياسات أو اتصال حساب المعلن.</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="font-semibold">إعداد تطبيق OAuth وعنوان العودة</dt><dd class="mt-2 text-sm text-slate-700">{{ xOperatorStatus.application_configured ? 'مكتمل محليًا' : 'غير مكتمل' }}</dd></div><div><dt class="font-semibold">صيغة رابط الخصوصية وإصدار الإشعار</dt><dd class="mt-2 text-sm text-slate-700">{{ xOperatorStatus.notice_links_configured ? 'مكتملة محليًا' : 'غير مكتملة' }}</dd></div></dl>
                <p class="mt-4 text-sm leading-7 text-slate-600">توضع مفاتيح X على الخادم: BB2_X_CONSUMER_KEY وBB2_X_CONSUMER_SECRET، مع BB2_X_REDIRECT_URI وBB2_X_PRIVACY_URL وBB2_X_NOTICE_REVISION. الربط الفعلي والتحقق من سياسات المنصة مؤجلان.</p>
            </section>
            <section v-if="snapchatOperatorStatus" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="snapchat-operator-title">
                <h2 id="snapchat-operator-title" class="text-lg font-semibold text-slate-900">ربط Snapchat</h2>
                <p class="mt-3 rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">هذه حالة إعداد الخدمة المحلية فقط. لا تثبت موافقة Snapchat أو اعتماد السياسات أو اتصال حساب المعلن.</p>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="font-semibold">إعداد تطبيق OAuth وعنوان العودة</dt><dd class="mt-2 text-sm text-slate-700">{{ snapchatOperatorStatus.application_configured ? 'مكتمل محليًا' : 'غير مكتمل' }}</dd></div><div><dt class="font-semibold">صيغة رابط الخصوصية وإصدار الإشعار</dt><dd class="mt-2 text-sm text-slate-700">{{ snapchatOperatorStatus.notice_links_configured ? 'مكتملة محليًا' : 'غير مكتملة' }}</dd></div></dl>
                <p class="mt-4 text-sm leading-7 text-slate-600">توضع مفاتيح Snapchat على الخادم في متغيرات البيئة. الربط الفعلي والتحقق من سياسات المنصة مؤجلان.</p>
            </section>
            <section v-if="props.permissions['staff.invite']" class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="invite-title">
                <h2 id="invite-title" class="text-lg font-semibold text-slate-900">دعوة موظف</h2>
                <p class="mt-2 text-sm text-slate-600">تُنشئ الدعوة حسابًا معلّقًا فقط. يبدأ الموظف من رسالة تهيئة كلمة المرور، ثم يثبت بريده ويسجل مفتاح مرور.</p>
                <form class="mt-5 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start" @submit.prevent="inviteEmployee">
                    <div><label for="staff-name" class="block text-sm font-medium text-slate-700">الاسم</label><input id="staff-name" v-model="invite.name" required maxlength="255" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="invite.errors.name" role="alert" class="mt-1 text-sm text-red-700">{{ invite.errors.name }}</p></div>
                    <div><label for="staff-email" class="block text-sm font-medium text-slate-700">البريد</label><input id="staff-email" v-model="invite.email" type="email" required maxlength="255" dir="ltr" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="invite.errors.email" role="alert" class="mt-1 text-sm text-red-700">{{ invite.errors.email }}</p></div>
                    <button type="submit" :disabled="invite.processing" class="mt-6 rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ invite.processing ? 'جارٍ الإرسال…' : 'إرسال الدعوة' }}</button>
                </form>
                <p v-if="invite.recentlySuccessful" role="status" class="mt-3 text-sm text-emerald-700">أُنشئ حساب الموظف وطُلب إرسال رسالة التهيئة.</p>
            </section>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-slate-900">أهلية الفريق الداخلي</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">حالة الأهلية هنا منفصلة عن جلسات الدخول والمنح التنفيذية وصلاحية أي مساحة عمل.</p>
                </div>
                <p v-if="actionError" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ actionError }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[46rem] text-right text-sm">
                        <caption class="sr-only">أعضاء الفريق الداخلي وحالة أهليتهم</caption>
                        <thead class="border-b border-slate-200 bg-slate-50 text-slate-600">
                            <tr><th scope="col" class="px-4 py-3 font-medium">الاسم</th><th scope="col" class="px-4 py-3 font-medium">البريد</th><th scope="col" class="px-4 py-3 font-medium">الفئة</th><th scope="col" class="px-4 py-3 font-medium">الحالة</th><th scope="col" class="px-4 py-3 font-medium">الإجراء</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="person in people" :key="person.public_id">
                                <td class="px-4 py-4 font-medium text-slate-900">{{ person.name }}</td>
                                <td class="px-4 py-4 text-slate-700" dir="ltr">{{ person.email }}</td>
                                <td class="px-4 py-4 text-slate-700">{{ person.kind === 'founder' ? 'المؤسس' : 'موظف' }}</td>
                                <td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ statusLabel[person.status] }}</span><p v-if="person.status === 'pending'" class="mt-2 text-xs text-slate-500">بريد: {{ person.email_verified_at ? 'مؤكد' : 'معلّق' }} · مفاتيح: {{ person.passkey_count }} · أهلية: {{ person.attested_at ? 'مُقرّة' : 'معلّقة' }}</p><p v-else-if="person.status === 'active' && person.kind === 'employee'" class="mt-2 text-xs text-slate-500">مفاتيح: {{ person.passkey_count }} · استقلال العاملين: {{ person.factor_independence_attested_at ? 'مُقرّ' : 'غير مُقرّ' }}</p></td>
                                <td class="px-4 py-4"><div v-if="person.kind === 'employee'" class="flex flex-wrap gap-2">
                                    <button v-if="props.permissions['staff.invite'] && person.status === 'pending'" type="button" :disabled="!!busy" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-800 disabled:opacity-50" @click="staffAction(person, 'resend-invitation')">إعادة رابط التهيئة</button>
                                    <button v-if="props.permissions['staff.attest'] && person.status === 'pending' && !person.attested_at" type="button" :disabled="!person.email_verified_at || !!busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium disabled:opacity-50" @click="staffAction(person, 'attest')">إقرار الأهلية</button>
                                    <button v-if="props.permissions['staff.activate'] && person.status === 'pending'" type="button" :disabled="!person.email_verified_at || !person.attested_at || person.passkey_count < 1 || !!busy" class="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-medium text-emerald-800 disabled:opacity-50" @click="staffAction(person, 'activate')">تفعيل</button>
                                    <button v-if="props.permissions['staff.revoke'] && person.status === 'active'" type="button" :disabled="!!busy" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-medium text-red-700 disabled:opacity-50" @click="staffAction(person, 'revoke')">إلغاء الأهلية</button>
                                    <button v-if="canAttestFactors && props.permissions['grant.manage'] && person.status === 'active' && person.passkey_count >= 2 && !person.factor_independence_attested_at" type="button" :disabled="!!busy" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-xs font-medium text-indigo-800 disabled:opacity-50" @click="staffAction(person, 'attest-factors')">إقرار استقلال المفتاحين</button>
                                </div></td>
                            </tr>
                            <tr v-if="!people.length"><td colspan="5" class="px-4 py-10 text-center text-slate-600">لا توجد سجلات موظفين متاحة.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <section v-if="props.permissions['grant.manage']" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="grants-title">
                <h2 id="grants-title" class="text-lg font-semibold text-slate-900">منح الأفعال الداخلية</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">تُمنح الأفعال لموظف نشط بعينه، مع سبب مسجّل، ولا تنتقل المنحة بين أجيال الحساب أو إلى المؤسس بالدور وحده.</p>
                <form class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_2fr_auto] md:items-end" @submit.prevent="issueGrant">
                    <div><label for="grant-staff" class="block text-sm font-medium text-slate-700">الموظف</label><select id="grant-staff" v-model="grant.staff" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">اختر موظفًا</option><option v-for="person in people.filter(p => p.kind === 'employee' && p.status === 'active')" :key="person.public_id" :value="person.public_id">{{ person.name }}</option></select><p v-if="grant.errors.staff" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.staff }}</p></div>
                    <div><label for="grant-action" class="block text-sm font-medium text-slate-700">الفعل</label><select id="grant-action" v-model="grant.action" required class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">اختر فعلًا</option><option v-for="action in grantableActions" :key="action" :value="action">{{ action }}</option></select><p v-if="grant.errors.action" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.action }}</p></div>
                    <div><label for="grant-reason" class="block text-sm font-medium text-slate-700">السبب</label><input id="grant-reason" v-model="grant.reason" required minlength="10" maxlength="128" class="mt-1 w-full rounded-lg border-slate-300 text-sm" /><p v-if="grant.errors.reason" role="alert" class="mt-1 text-xs text-red-700">{{ grant.errors.reason }}</p></div>
                    <button type="submit" :disabled="grant.processing" class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">{{ grant.processing ? 'جارٍ المنح…' : 'منح الفعل' }}</button>
                </form>
                <ul class="mt-6 divide-y divide-slate-100 border-t border-slate-100">
                    <li v-for="item in staffGrants" :key="item.id" class="flex flex-col gap-2 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <div><span class="font-medium text-slate-900">{{ item.name }}</span><span class="mx-2 text-slate-500">{{ item.action }}</span><p class="mt-1 text-xs text-slate-500">{{ item.reason }}</p></div>
                        <button type="button" :disabled="!!busy" class="self-start text-sm font-medium text-red-700 underline disabled:opacity-50" @click="revokeGrant(item.id)">سحب المنحة</button>
                    </li>
                    <li v-if="!staffGrants.length" class="py-4 text-sm text-slate-600">لا توجد منح بشرية نشطة.</li>
                </ul>
            </section>
        </div>
    </InternalLayout>
</template>
