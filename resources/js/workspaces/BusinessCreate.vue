<script setup lang="ts">
import { status } from '../spa/presentation';
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router';
import { createBusiness, type CreateBusiness } from './api';
import { current, creationDraft as draft, unresolvedCreation, resetCreationDraft } from './state';
import { bootstrap, owned, stamp, TransportError } from '../spa/transport';
import { applicationDestination } from '../spa/destination';
import { confirmDeparture } from '../spa/presentation';
import { locale } from '../spa/locale';

const data = computed(() => current.value && 'types' in current.value.data ? current.value.data : undefined);
const busy = ref(false), errors = ref<Record<string, string[]>>({}), error = ref('');
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const dirty = computed(() => !!draft.name || draft.business_type_id !== '');
const router = useRouter();
const keyChanged = computed(() => draft.creation_key !== data.value?.creationKey);
let alive = true;
const canLeave = () => !busy.value && (!dirty.value || confirm(copy('لديك بيانات غير محفوظة. هل تريد المغادرة؟', 'You have unfinished business details. Leave this page?')));
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty.value || busy.value) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => {
    alive = false;
    if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true;
    window.removeEventListener('beforeunload', beforeUnload);
});

async function save(retry = false) {
    if (busy.value || status.value !== 'ready' || (!retry && (unresolvedCreation.value || keyChanged.value || !draft.business_type_id))) return;
    const command: CreateBusiness | undefined = retry ? unresolvedCreation.value : {
        name: draft.name, business_type_id: Number(draft.business_type_id), creation_key: draft.creation_key,
    };
    if (!command) return;
    const captured = stamp();
    unresolvedCreation.value = structuredClone(command);
    busy.value = true; errors.value = {}; error.value = '';
    try {
        const result = await createBusiness(command);
        if (!alive || !owned(captured)) return;
        const target = new URL(applicationDestination(result.redirect, location.origin, bootstrap.identity.binding.boundary));
        const path = target.pathname + target.search + target.hash;
        resetCreationDraft(); busy.value = false;
        if (router.resolve(path).matched.length) await router.push(path);
        else location.assign(target.href);
    } catch (failure) {
        if (!alive || !owned(captured)) return;
        if (failure instanceof TransportError && [403, 422].includes(failure.status)) {
            if (!retry) unresolvedCreation.value = undefined;
            errors.value = failure.body.errors ?? {};
            error.value = failure.status === 403
                ? copy('لم يعد الطلب مخوّلًا. راجع تسجيل الدخول وصلاحية الإنشاء وأنشطتك قبل المحاولة.', 'The request is no longer authorized. Review sign-in, creation authority and your businesses before trying again.')
                : failure.message;
        }
    } finally { busy.value = false; }
}
</script>

<template>
    <main class="workspace-page" data-spa-private data-workspaces="create">
        <p class="workspace-eyebrow">{{ copy('سياق النشاط', 'Business context') }}</p>
        <h1>{{ copy('لنفهم نشاطك أولًا', 'Start with your business') }}</h1>
        <p>{{ copy('نوع النشاط يساعدنا على ترتيب مصادر الإعلان وعرض اعتبارات أولية مناسبة. يمكنك تحديثه لاحقًا.', 'Your business type helps organize advertising sources and relevant initial considerations. You can update it later.') }}</p>
        <form class="workspace-create" @submit.prevent="save()">
            <fieldset :disabled="busy || !!unresolvedCreation || keyChanged">
                <label>{{ copy('اسم النشاط', 'Business name') }}<input v-model="draft.name" data-workspaces="name" required minlength="2" maxlength="120" autocomplete="organization" /></label>
                <label>{{ copy('نوع النشاط', 'Business type') }}<select v-model="draft.business_type_id" data-workspaces="type" required>
                    <option disabled value="">{{ copy('اختر نوع النشاط', 'Choose a business type') }}</option>
                    <option v-for="type in data?.types" :key="type.id" :value="type.id" :disabled="!type.active">{{ locale === 'ar' ? type.name_ar : type.name_en }}{{ !type.active ? copy(' — غير متاح للاختيار الجديد', ' — Unavailable for new selection') : '' }}</option>
                </select></label>
                <p v-if="!data?.types.some(type => type.active)">{{ copy('لا توجد أنواع متاحة حاليًا. يمكن العودة عندما تصبح متاحة.', 'No business types are available yet. Return when one becomes available.') }}</p>
                <p v-for="(messages, field) in errors" :key="field" role="alert">{{ messages.join(' ') }}</p>
                <p v-if="error" role="alert">{{ error }}</p>
                <button type="submit" class="workspace-action" data-workspaces="save" :disabled="!draft.business_type_id">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ والمتابعة', 'Save and continue') }}</button>
            </fieldset>
        </form>
        <section v-if="unresolvedCreation && !busy" role="alert" data-workspaces="uncertain">
            <p>{{ copy('لم نتمكن من تأكيد نتيجة الإنشاء. قد يكون النشاط قد أُنشئ بالفعل. راجع أنشطتك؛ لا نعيد الطلب تلقائيًا.', 'Creation could not be confirmed. The business may already exist. Review your businesses; this request is never retried automatically.') }}</p>
            <button v-if="!keyChanged" type="button" class="workspace-action" data-workspaces="retry" @click="save(true)">{{ copy('إعادة الطلب الأصلي نفسه', 'Retry the exact original request') }}</button>
        </section>
        <p v-if="keyChanged && !unresolvedCreation" role="alert">{{ copy('تغيّر سياق الإنشاء. احتفظ ببياناتك وراجع أنشطتك قبل بدء طلب جديد.', 'The creation context changed. Keep your details and review your businesses before starting a new request.') }}</p>
        <RouterLink class="workspace-review" to="/workspaces">{{ copy('راجع أنشطتي', 'Review my businesses') }}</RouterLink>
    </main>
</template>

<style scoped>
.workspace-create{max-width:540px;margin:30px 0}.workspace-create fieldset{display:grid;gap:22px;min-width:0;border:0;padding:0}.workspace-create label{display:grid;gap:8px;font-size:15px;font-weight:600}.workspace-create :is(input,select){width:100%;min-width:0;border:1px solid #9caeaa;border-radius:5px;padding:12px;background:white}.workspace-create .workspace-action{justify-self:start}.workspace-create [role=alert]{color:#a12c27}.workspace-review{display:inline-block;margin-top:24px;color:#14635b;text-decoration:underline}
</style>
