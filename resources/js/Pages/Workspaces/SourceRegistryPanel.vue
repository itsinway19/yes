<script setup lang="ts">
import { ref } from 'vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import { router, useForm } from '@inertiajs/vue3';
import SourceCapabilityStatus from './SourceCapabilityStatus.vue';
import DeclareSourceScope from './DeclareSourceScope.vue';

type Scope = { resource: string; operation: string; purpose: string };
type Item = Scope & { id: string; family: string; namespace: string; declared_external_id: string; label: string;
    version: number; status: string; identity_verification: string; axes: Record<string, string>;
    capability: { state: string; reason: string; last_verified_at: string | null }; can_manage: boolean; scope_choices: Scope[];
    next_repair: string; audit_delivery: (Scope & { id: string; command_id: string; receipt_version: number; state: string; attempts: number; can_recover: boolean; recovery_reason: string | null })[] };
type Authority = { source_target_id: string; source_resource: string; source_operation: string; source_purpose: string };
const props = defineProps<{ workspaceId: string; candidateId: string; canRequestAuthority: boolean;
    sources: { state: string; items: Item[]; registration_authorities: Authority[] } }>();
const grant = useForm({ source_id: props.candidateId, resource: '', operation: '', purpose: '', action: 'source.register',
    decision: 'authorize', reason: 'owner_explicit_request', expires_at: '' });
const registration = useForm({ command_id: crypto.randomUUID(), source_id: '', resource: '', operation: '', purpose: '',
    effect: 'register', expected_version: 0, reason: 'owner_declared_identity', family: 'meta', namespace: '', external_id: '', label: '' });
const busy = ref(false);
const failure = ref('');
function requestGrant() {
    grant.post(route('workspaces.sources.grant', props.workspaceId), { preserveScroll: true });
}
function registerSource(a: Authority) {
    Object.assign(registration, { source_id: a.source_target_id, resource: a.source_resource, operation: a.source_operation, purpose: a.source_purpose });
    registration.post(route('workspaces.sources.command', props.workspaceId), {
        preserveScroll: true, onSuccess: () => { registration.command_id = crypto.randomUUID(); },
    });
}
function manage(item: Item, effect: 'rename' | 'revoke') {
    const label = effect === 'rename' ? window.prompt('الاسم المعلن الجديد', item.label) : undefined;
    if (effect === 'rename' && !label) return;
    busy.value = true;
    router.post(route('workspaces.sources.command', props.workspaceId), {
        command_id: crypto.randomUUID(), source_id: item.id, resource: item.resource, operation: item.operation, purpose: item.purpose,
        effect, expected_version: item.version, reason: effect === 'revoke' ? 'owner_source_revocation' : 'owner_label_correction',
        ...(label ? { label } : {}),
    }, { preserveScroll: true, onError: () => { failure.value = 'تعذّر تنفيذ الأمر؛ أعد تحميل الحالة قبل المحاولة.'; },
        onFinish: () => { busy.value = false; } });
}
function recover(obligation: string) {
    if (busy.value) return;
    busy.value = true;
    router.post(route('workspaces.sources.recover', [props.workspaceId, obligation]), {}, {
        preserveScroll: true, onError: () => { failure.value = 'تعذّر تسليم التدقيق؛ تبقى حالة التسليم مستقلة عن التسجيل.'; },
        onFinish: () => { busy.value = false; },
    });
}
</script>

<template>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6" aria-labelledby="sources-heading">
        <h2 id="sources-heading" class="text-lg font-semibold">المصادر والصلاحيات الفعلية</h2>
        <p class="mt-2 text-sm text-slate-600">التسجيل المحلي يحفظ هوية معلنة غير متحققة. لا يثبت اتصالاً بالمزوّد أو إذناً لقراءة بياناته.</p>
        <form v-if="canRequestAuthority" class="mt-5 grid gap-3 sm:grid-cols-2" @submit.prevent="requestGrant">
            <h3 class="font-medium sm:col-span-2">طلب صلاحية صريحة لمصدر ومورد وعملية وغرض محددين</h3>
            <label>معرّف المصدر<input v-model="grant.source_id" required dir="ltr" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>المورد<input v-model="grant.resource" required maxlength="180" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>العملية (رمز)<input v-model="grant.operation" required dir="ltr" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>الغرض (رمز)<input v-model="grant.purpose" required dir="ltr" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>الفعل<select v-model="grant.action" class="mt-1 w-full rounded border-slate-300"><option>source.register</option><option>source.read</option><option>source.manage</option><option>source.audit.recover</option></select></label>
            <label>القرار<select v-model="grant.decision" class="mt-1 w-full rounded border-slate-300"><option value="authorize">منح الصلاحية المحددة</option><option value="revoke">سحب الصلاحية المحددة</option></select></label>
            <p v-for="(message, field) in grant.errors" :key="field" role="alert" class="text-sm text-red-700">{{ message }}</p>
            <button :disabled="grant.processing" class="rounded bg-indigo-700 px-4 py-2 text-white disabled:opacity-50">تأكيد القرار الصريح</button>
        </form>
        <form v-if="canRequestAuthority && sources.registration_authorities.length" class="mt-6 grid gap-3 sm:grid-cols-2" @submit.prevent>
            <h3 class="font-medium sm:col-span-2">تسجيل الهوية المعلنة</h3>
            <label>العائلة<input v-model="registration.family" required list="source-families" class="mt-1 w-full rounded border-slate-300" /><datalist id="source-families"><option v-for="f in ['meta','google','snapchat','tiktok','x','business']" :key="f">{{ f }}</option></datalist></label>
            <label>نطاق المعرّف<input v-model="registration.namespace" required maxlength="80" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>معرّف الحساب المعلن<input v-model="registration.external_id" required maxlength="180" class="mt-1 w-full rounded border-slate-300" /></label>
            <label>الاسم المعلن<input v-model="registration.label" required maxlength="120" class="mt-1 w-full rounded border-slate-300" /></label>
            <p v-for="(message, field) in registration.errors" :key="field" role="alert" class="text-sm text-red-700">{{ message }}</p>
            <button v-for="a in sources.registration_authorities" :key="a.source_target_id + a.source_operation + a.source_purpose" type="button" :disabled="registration.processing" class="rounded bg-indigo-700 px-4 py-2 text-white disabled:opacity-50" @click="registerSource(a)">تسجيل غير متحقق: {{ a.source_resource }} / {{ a.source_operation }} / {{ a.source_purpose }}</button>
        </form>
        <p v-if="!sources.items.length" class="mt-5 text-sm text-slate-600">لا توجد مصادر قابلة للعرض بصلاحية المصدر الحالية. منح عرض المساحة وحده لا يكفي.</p>
        <article v-for="item in sources.items" :key="item.id + item.resource + item.operation + item.purpose" class="mt-5 border-t border-slate-200 pt-5">
            <h3 class="font-medium flex items-center gap-3"><ProviderBrand v-if="/^(meta|google|snapchat|tiktok|x)$/.test(item.family)" :provider="item.family" :size="32" />{{ item.label }} — {{ item.family }}</h3>
            <p dir="ltr" class="mt-1 break-all text-xs">{{ item.id }}</p>
            <p class="mt-2 text-sm">هوية معلنة غير متحققة: {{ item.namespace }} / {{ item.declared_external_id }}</p>
            <p class="mt-2 text-sm">{{ item.resource }} / {{ item.operation }} / {{ item.purpose }}</p>
            <SourceCapabilityStatus :capability="item.capability" />
            <p class="mt-2 text-sm">الإجراء التالي: {{ item.next_repair === 'source_revoked' ? 'المصدر مسحوب؛ لا يسمح بالاستخدام المحمي' : 'يلزم تحقق المزوّد المستقل قبل الاستخدام' }}</p>
            <dl class="mt-3 grid gap-1 text-xs" dir="ltr"><div v-for="(state, axis) in item.axes" :key="axis">{{ axis }}: {{ state }}</div></dl>
            <div v-if="item.can_manage" class="mt-3 flex gap-4"><button :disabled="busy" class="text-sm text-indigo-700 underline" @click="manage(item, 'rename')">تصحيح الاسم</button><button :disabled="busy" class="text-sm text-red-700 underline" @click="manage(item, 'revoke')">سحب المصدر</button></div>
            <DeclareSourceScope v-if="sources.items.findIndex(s => s.id === item.id) === sources.items.indexOf(item)" :workspace-id="workspaceId" :source-id="item.id" :version="item.version" :choices="item.scope_choices" />
            <ul class="mt-3 text-xs"><li v-for="d in item.audit_delivery" :key="d.id" class="mt-2">تسليم التدقيق: <span dir="ltr">{{ d.state }}</span>
                <p dir="ltr" class="mt-1 break-all">{{ d.resource }} / {{ d.operation }} / {{ d.purpose }} — {{ d.command_id }} (v{{ d.receipt_version }})</p>
                <button v-if="d.can_recover" :disabled="busy" class="ms-3 text-indigo-700 underline" @click="recover(d.id)">إعادة تسليم الالتزام نفسه</button>
                <p v-else-if="d.state !== 'ACKNOWLEDGED'" class="mt-1 text-slate-600">{{ d.recovery_reason === 'fresh_authentication_required' ? 'يلزم تجديد المصادقة لإعادة التسليم.' : d.recovery_reason === 'responsible_owner_required' ? 'إعادة التسليم تتطلب المالك المسؤول.' : 'لا توجد صلاحية استرداد مطابقة لنطاق هذا الالتزام.' }}</p>
            </li></ul>
        </article>
        <p v-if="failure" role="alert" class="mt-4 text-sm text-red-700">{{ failure }}</p>
    </section>
</template>
