<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Passkeys } from '@laravel/passkeys';
import { cancelPasskeyOperation, runPasskeyOperation } from '@/security/passkeyCeremony';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InternalLayout from '@/Layouts/InternalLayout.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';

type Session = {
    id: number;
    current: boolean;
    description: string;
    last_seen_at: string;
    authenticated_at: string;
};
type Passkey = { id: number; name: string; provider: string | null; created_at: string; last_used_at: string | null };

const props = defineProps<{ sessions: Session[]; passkeys: Passkey[]; internalContext: boolean }>();
const securityRoute = (name: string) => `security.${name}`;
const contextRoute = (name: string) => route(props.internalContext ? `internal.${securityRoute(name)}` : securityRoute(name));

const busy = ref<number | 'others' | 'current' | null>(null);
const error = ref('');
const passkeyName = ref('');
const passkeyBusy = ref(false);
const passkeyError = ref('');
const passkeyReauthRequired = ref(false);
const passkeyLocalhostUrl = ref('');
let operationId: string | null = null;
const cancelPending = () => {
    if (!operationId) return;
    cancelPasskeyOperation(operationId);
    passkeyError.value = 'طُلب إلغاء العملية من المتصفح. ستبقى المحاولات الجديدة متوقفة حتى تنتهي العملية الحالية.';
};
onMounted(() => window.addEventListener('pagehide', cancelPending));
onUnmounted(() => { window.removeEventListener('pagehide', cancelPending); cancelPending(); });

async function addPasskey() {
    if (passkeyBusy.value) return;
    passkeyError.value = '';
    passkeyReauthRequired.value = false;
    passkeyLocalhostUrl.value = '';
    if (!passkeyName.value.trim()) {
        passkeyError.value = 'أدخل اسمًا يميز مفتاح المرور.';
        return;
    }
    passkeyBusy.value = true;
    try {
        const operation = runPasskeyOperation('device-registration',
            { options: contextRoute('passkeys.options'), submit: contextRoute('passkeys.store') },
            routes => Passkeys.register({ name: passkeyName.value.trim(), routes }));
        operationId = operation.id;
        await operation.promise;
        passkeyName.value = '';
        router.reload({ only: ['passkeys'] });
    } catch (error) {
        if (error instanceof Error && error.name === 'UserCancelledError') {
            passkeyError.value = 'لم يُكمل المتصفح إنشاء مفتاح المرور. قد يكون الطلب أُلغي أو انتهت مهلته أو تعذّر تشغيل مزوّد المفاتيح. يمكنك المحاولة بمُوثِّق آخر متاح، مثل الهاتف أو مفتاح أمان.';
        } else if (error instanceof Error && error.message === 'Fresh authentication is required.') {
            passkeyReauthRequired.value = true;
            passkeyError.value = props.internalContext
                ? 'انتهت مدة التوثيق الحديث. سجّل الدخول مجددًا بمفتاح المرور ثم عُد لإضافة المفتاح.'
                : 'انتهت مدة التوثيق الحديث. أكّد كلمة مرورك ثم عُد لإضافة مفتاح المرور.';
        } else if (error instanceof Error && error.name === 'InvalidDomainError' && window.location.hostname === '127.0.0.1') {
            const url = new URL(window.location.href);
            url.hostname = 'localhost';
            passkeyLocalhostUrl.value = url.toString();
            passkeyError.value = 'تتطلب مفاتيح المرور فتح المعاينة المحلية عبر localhost. سجّل الدخول هناك مجددًا ثم أضف المفتاح.';
        } else {
            passkeyError.value = error instanceof Error ? error.message : 'تعذّر إضافة مفتاح المرور.';
        }
    } finally {
        operationId = null;
        passkeyBusy.value = false;
    }
}

function removePasskey(id: number) {
    passkeyError.value = '';
    router.delete(route(props.internalContext ? 'internal.security.passkeys.destroy' : 'security.passkeys.destroy', id), {
        preserveScroll: true,
        onError: () => { passkeyError.value = 'تعذّر حذف مفتاح المرور.'; },
    });
}

function endSession(id: number) {
    error.value = '';
    busy.value = id;
    router.delete(route(props.internalContext ? 'internal.security.sessions.destroy' : 'security.sessions.destroy', id), {
        preserveScroll: true,
        onError: () => { error.value = 'تعذّر إنهاء الجلسة. حاول مرة أخرى.'; },
        onFinish: () => { busy.value = null; },
    });
}

function endOthers() {
    error.value = '';
    busy.value = 'others';
    router.post(contextRoute('sessions.revoke-others'), {}, {
        preserveScroll: true,
        onError: () => { error.value = 'تعذّر إنهاء الجلسات الأخرى.'; },
        onFinish: () => { busy.value = null; },
    });
}

function endCurrent() {
    error.value = '';
    busy.value = 'current';
    router.post(contextRoute('sessions.sign-out-current'), {}, {
        onError: () => { error.value = 'تعذّر تسجيل الخروج من هذا المتصفح.'; },
        onFinish: () => { busy.value = null; },
    });
}

function seenAt(value: string) {
    return new Intl.DateTimeFormat('ar', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
</script>

<template>
    <Head title="الأمان" />
    <component :is="internalContext ? InternalLayout : AuthenticatedLayout">
        <template #header>
            <h1 class="text-xl font-semibold text-slate-900">الأمان</h1>
        </template>

        <div dir="rtl" class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">الجلسات النشطة</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            هذه أوصاف تقريبية للمتصفح والنظام، وليست إثباتًا لجهاز مادي أو موقع محدد.
                            إنهاء الجلسة يمنع استخدامها عند الطلب التالي.
                        </p>
                    </div>
                    <button type="button" :disabled="busy !== null || sessions.length < 2"
                        class="shrink-0 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="endOthers">
                        {{ busy === 'others' ? 'جارٍ الإنهاء…' : 'إنهاء كل الجلسات الأخرى' }}
                    </button>
                </div>

                <p v-if="error" role="alert" class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</p>

                <ul class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                    <li v-for="session in sessions" :key="session.id" class="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-900">{{ session.description }}</span>
                                <span v-if="session.current" class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-800">هذه الجلسة</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">آخر نشاط تقريبي: {{ seenAt(session.last_seen_at) }}</p>
                        </div>
                        <button v-if="!session.current" type="button" :disabled="busy !== null"
                            class="self-start rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-50"
                            :aria-label="`إنهاء جلسة ${session.description}`" @click="endSession(session.id)">
                            {{ busy === session.id ? 'جارٍ الإنهاء…' : 'إنهاء الجلسة' }}
                        </button>
                    </li>
                </ul>

                <div class="mt-8 rounded-xl bg-slate-50 p-5">
                    <h3 class="font-semibold text-slate-900">تسجيل الخروج من هذه الجلسة</h3>
                    <p class="mt-1 text-sm text-slate-600">ينهي جلسة هذا المتصفح ويعيدك إلى صفحة الدخول.</p>
                    <button type="button" :disabled="busy !== null"
                        class="mt-4 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:opacity-50"
                        @click="endCurrent">
                        {{ busy === 'current' ? 'جارٍ تسجيل الخروج…' : 'تسجيل الخروج من هذا المتصفح' }}
                    </button>
                </div>
            </div>

            <div v-if="!internalContext" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <UpdatePasswordForm />
            </div>

            <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="passkeys-title">
                <h2 id="passkeys-title" class="text-lg font-semibold text-slate-900">مفاتيح المرور</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">استخدم مفتاح مرور مسجلًا لتسجيل الدخول دون كتابة كلمة المرور. احتفظ بوسيلة دخول أخرى قبل حذف مفتاحك الأخير.</p>
                <p v-if="passkeys.length" class="mt-2 text-sm leading-6 text-slate-600">يظهر مزوّد كل مفتاح معروف أدناه. إذا كان لديك مفتاح من المزوّد الذي تريد استخدامه، جرّب الدخول بالمفتاح المسجّل بدل إنشاء نسخة ثانية.</p>
                <p v-if="passkeyError" role="alert" class="mt-4 text-sm text-red-700">{{ passkeyError }}</p>
                <a v-if="passkeyReauthRequired" :href="route(internalContext ? 'internal.login' : 'password.confirm')"
                    class="mt-2 inline-block text-sm font-medium text-indigo-700 underline hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    {{ internalContext ? 'الدخول مجددًا بمفتاح المرور' : 'تأكيد كلمة المرور' }}
                </a>
                <a v-if="passkeyLocalhostUrl" :href="passkeyLocalhostUrl"
                    class="mt-2 inline-block text-sm font-medium text-indigo-700 underline hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    فتح المعاينة عبر localhost
                </a>
                <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="addPasskey">
                    <label for="passkey-name" class="sr-only">اسم مفتاح المرور</label>
                    <input id="passkey-name" v-model="passkeyName" type="text" maxlength="255" autocomplete="off"
                        placeholder="مثل حاسوب العمل" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" :disabled="passkeyBusy || !passkeyName.trim()"
                        class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-700 disabled:opacity-50">
                        {{ passkeyBusy ? 'جارٍ الإضافة…' : 'إضافة مفتاح مرور' }}
                    </button>
                    <button v-if="passkeyBusy" type="button" @click="cancelPending"
                        class="rounded px-3 py-2 text-sm text-slate-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">إلغاء المحاولة</button>
                </form>
                <ul v-if="passkeys.length" class="mt-6 divide-y divide-slate-200 border-t border-slate-200">
                    <li v-for="passkey in passkeys" :key="passkey.id" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-medium text-slate-900">{{ passkey.name }}</p>
                            <p v-if="passkey.provider" class="text-sm text-slate-600">المزوّد: {{ passkey.provider }}</p>
                            <p class="text-sm text-slate-600">أضيف في {{ seenAt(passkey.created_at) }}</p>
                            <p v-if="passkey.last_used_at" class="text-sm text-slate-600">آخر دخول به: {{ seenAt(passkey.last_used_at) }}</p>
                        </div>
                        <button type="button" class="self-start text-sm font-medium text-red-700 underline hover:text-red-900" @click="removePasskey(passkey.id)">حذف المفتاح</button>
                    </li>
                </ul>
                <p v-else class="mt-5 text-sm text-slate-600">لم تضف مفاتيح مرور بعد.</p>
            </section>
        </div>
    </component>
</template>
