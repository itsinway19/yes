<script setup lang="ts">
import { status } from '../spa/presentation';
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router';
import { createOrganization, type CreateOrganization } from './api';
import { current, creationDraft as draft, unresolvedCreation, resetCreationDraft } from './state';
import { bootstrap, owned, stamp, TransportError } from '../spa/transport';
import { applicationDestination } from '../spa/destination';
import { confirmDeparture } from '../spa/presentation';
import { locale } from '../spa/locale';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';

const data = computed(() => current.value && 'creationKey' in current.value.data ? current.value.data : undefined);
const busy = ref(false), errors = ref<Record<string, string[]>>({}), error = ref('');
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const dirty = computed(() => !!draft.name);
const router = useRouter();
const keyChanged = computed(() => draft.creation_key !== data.value?.creationKey);
let alive = true;
const canLeave = () => !busy.value && (!dirty.value || confirm(copy('لديك بيانات غير محفوظة. هل تريد المغادرة؟', 'You have unfinished organization details. Leave this page?')));
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
    if (busy.value || status.value !== 'ready' || (!retry && (unresolvedCreation.value || keyChanged.value))) return;
    const command: CreateOrganization | undefined = retry ? unresolvedCreation.value : {
        name: draft.name, creation_key: draft.creation_key,
    };
    if (!command) return;
    const captured = stamp();
    unresolvedCreation.value = structuredClone(command);
    busy.value = true; errors.value = {}; error.value = '';
    try {
        const result = await createOrganization(command);
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
                ? copy('لم يعد تسجيل الجهة متاحًا. راجع تسجيل الدخول وجهاتك قبل المحاولة.', 'Organization registration is no longer available. Review sign-in and your organizations before trying again.')
                : failure.message;
        }
    } finally { busy.value = false; }
}
</script>

<template>
    <div data-spa-private data-workspaces="create">
        <AdvertiserWorkspaceLayout workspace-context>
        <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><RouterLink to="/workspaces">{{ copy('جهات العمل والفريق', 'Organizations and team') }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ copy('إعداد جهة', 'Set up organization') }}</span></nav>
        <header class="bf-heading"><div><h1>{{ copy('إعداد جهة للفريق والموارد', 'Set up team and resource administration') }}</h1><p>{{ copy('أدخل اسم جهتك لإعداد إدارة فريقها ومواردها المشتركة.', 'Enter your organization’s name to set up administration for its team and shared resources.') }}</p></div></header>
        <p class="workspace-create-note">{{ copy('لا ينشئ التسجيل نشاطًا تجاريًا أو تفويضًا من العملاء. ملفات الأنشطة مستقلة ولها حقوقها الخاصة.', 'Registration creates neither a commercial business nor a client mandate. Business files remain independent with their own rights.') }} <RouterLink to="/businesses/new">{{ copy('إضافة نشاط تجاري', 'Add a business') }}</RouterLink></p>
        <form class="workspace-create" @submit.prevent="save()">
            <fieldset :disabled="busy || !!unresolvedCreation || keyChanged">
                <label>{{ copy('اسم جهة العمل', 'Organization name') }}<input v-model="draft.name" data-workspaces="name" required minlength="2" maxlength="120" autocomplete="organization" /></label>
                <p v-for="(messages, field) in errors" :key="field" role="alert">{{ messages.join(' ') }}</p>
                <p v-if="error" role="alert">{{ error }}</p>
                <button type="submit" class="workspace-action" data-workspaces="save">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('تسجيل الجهة', 'Register organization') }}</button>
            </fieldset>
        </form>
        <section v-if="unresolvedCreation && !busy" role="alert" data-workspaces="uncertain">
            <p>{{ copy('لم نتمكن من تأكيد تسجيل الجهة. راجع جهاتك أو تحقّق من النتيجة قبل بدء طلب جديد.', 'We could not confirm organization registration. Review your organizations or check the outcome before starting a new request.') }}</p>
            <button v-if="!keyChanged" type="button" class="workspace-action" data-workspaces="retry" @click="save(true)">{{ copy('التحقق من النتيجة', 'Check the outcome') }}</button>
            <details><summary>{{ copy('كيف يتم التحقق؟', 'How is the outcome checked?') }}</summary><p>{{ copy('يُرسل الطلب الأصلي نفسه ببياناته ومفتاح إنشائه. لا يُعاد تلقائيًا.', 'The exact original request is sent with its original data and creation key. It is never retried automatically.') }}</p></details>
        </section>
        <p v-if="keyChanged && !unresolvedCreation" role="alert">{{ copy('تغيّر سياق الإنشاء. احتفظ ببياناتك وراجع جهاتك قبل بدء طلب جديد.', 'The creation context changed. Keep your details and review your organizations before starting a new request.') }}</p>
        <RouterLink class="workspace-review" to="/workspaces">{{ copy('العودة إلى الجهات التنظيمية', 'Back to organizations') }}</RouterLink>
        </AdvertiserWorkspaceLayout>
    </div>
</template>

<style scoped>
.workspace-create{max-width:560px;margin:20px 0}.workspace-create fieldset{display:grid;gap:16px;min-width:0;border:0;padding:0}.workspace-create label{display:grid;gap:6px;font-size:13px;font-weight:600}.workspace-create :is(input,select){width:100%;min-width:0;height:38px;border:1px solid var(--ad-line);border-radius:7px;padding:7px 10px;font:inherit;background:var(--ad-paper);color:var(--ad-ink)}.workspace-action{justify-self:start;min-height:38px;padding:7px 14px;border:1px solid var(--ad-accent);border-radius:7px;background:var(--ad-accent);color:var(--ba-primary-text,#fff);font:inherit;font-size:13px;cursor:pointer}.workspace-action:disabled{opacity:.55;cursor:not-allowed}.workspace-create [role=alert]{color:var(--ba-danger,#a12c27)}.workspace-review{display:inline-flex;align-items:center;min-height:38px;margin-top:12px;color:var(--ad-accent);font-size:13px;text-underline-offset:3px}.workspace-create-note{max-width:72ch;font-size:13px;line-height:1.8;color:var(--ad-muted)}.workspace-create-note a{color:var(--ad-accent)}[data-workspaces=uncertain]{max-width:560px;padding:14px 16px;border:1px solid var(--ad-line);border-radius:8px;font-size:13px;line-height:1.8}[data-workspaces=uncertain] p{margin:0 0 12px}[data-workspaces=uncertain] details{margin-top:12px;color:var(--ad-muted)}[data-workspaces=uncertain] summary{cursor:pointer;min-height:32px}.workspace-create :is(input,select,button):focus-visible,.workspace-action:focus-visible,.workspace-review:focus-visible{outline:2px solid var(--ad-accent);outline-offset:3px}@media(max-width:760px){.workspace-create :is(input,select),.workspace-action,.workspace-review,[data-workspaces=uncertain] summary{min-height:44px}.workspace-create{margin:16px 0}}
</style>
