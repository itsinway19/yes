<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRouter, onBeforeRouteLeave } from 'vue-router';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { create, writeFact, type DraftResult } from './api';
import { writePractice } from './activity';
import { creation } from './drafts';
import { clearSetup, emptySetup, prepareSetup, readSetup, setupDeclarations, setupKey, writeSetup } from './businessSetup';
import { wizardAnswersValid, type WizardAnswers, type WizardCatalog } from './businessWizard';
import BusinessWizard from './BusinessWizard.vue';

const props = defineProps<{ catalog: WizardCatalog; registrationAttempt?: string | null }>();
const router = useRouter();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const registeredDraft = props.registrationAttempt ? readSetup(setupKey(props.registrationAttempt)) : undefined;
const attempt = registeredDraft ? props.registrationAttempt : undefined;
const key = setupKey(attempt);
const draft = reactive(registeredDraft ?? readSetup(key) ?? emptySetup(locale.value));
const busy = ref(false), finished = ref(false), opening = ref(false), receipt = ref<DraftResult>();
const uncertain = computed(() => !!draft.uncertain?.length);
const settled = new Set<string>();
const message = ref<'invalid' | 'conflict' | 'denied' | 'unknown' | 'storage' | 'open' | ''>(uncertain.value ? 'unknown' : '');
const notice = computed(() => ({
    invalid: t('لم يُقبل أحد الاختيارات. راجع معلومات نشاطك قبل استكمال الإعداد.', 'A choice was rejected. Review your business details before finishing setup.'),
    conflict: t('تغيّرت معلومات النشاط أو سبق استخدام معرّف الطلب. افتح النشاط لمراجعة حالته الحالية إن كان متاحًا.', 'Business details changed or the request identifier was already used. Open the business to review its current state if available.'),
    denied: t('لم يعد الحفظ متاحًا بصلاحيتك الحالية. راجع الوصول إلى النشاط قبل استكمال الإعداد.', 'Your current access no longer permits this save. Check access to the business before finishing setup.'),
    unknown: t('لم نتأكد من نتيجة الحفظ. تحقّق من نتيجة الطلب المحفوظ قبل إجراء تغييرات أخرى.', 'We could not confirm the save. Check the original request before making further changes.'),
    storage: t('تعذر الاحتفاظ بالطلب في هذا التبويب. أتح التخزين المؤقت للمتصفح لإرسال الطلب بأمان.', 'This tab could not retain the request. Enable browser session storage to submit it safely.'),
    open: t('حُفظ نشاطك. تعذر فتح اللوحة الآن، ويمكنك محاولة فتحها مجددًا.', 'Your business was saved. The dashboard could not open. You can try opening it again.'),
    '': '',
})[message.value]);

let disposed = false;
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (busy.value || uncertain.value) { event.preventDefault(); event.returnValue = ''; }
};
onMounted(() => {
    window.addEventListener?.('beforeunload', beforeUnload);
    if (attempt && !draft.plan && wizardAnswersValid(draft.answers, props.catalog)) void save();
});
onBeforeUnmount(() => { disposed = true; window.removeEventListener?.('beforeunload', beforeUnload); });
onBeforeRouteLeave(() => finished.value || (!busy.value && !uncertain.value)
    || confirm(t('يوجد طلب حفظ لم تُحسم نتيجته. هل تريد المغادرة مع الاحتفاظ بالطلب في هذا التبويب؟', 'A save has an unresolved outcome. Leave while retaining the request in this tab?')));

function persist() {
    try { writeSetup(key, draft); return true; }
    catch { return false; }
}
function updateAnswers(answers: WizardAnswers) {
    draft.answers = answers;
    if (!attempt) draft.language = locale.value;
    message.value = '';
    persist();
}
function updateStep(step: number) {
    draft.step = step;
    persist();
}
const destination = computed(() => receipt.value
    ? `/businesses/${receipt.value.business_public_id}?access=${receipt.value.grant_refs['business.context.read']}` : '/businesses');

async function openDashboard() {
    if (opening.value) return;
    opening.value = true;
    message.value = '';
    try {
        const failure = await router.replace(destination.value);
        if (failure) { if (!disposed) message.value = 'open'; return; }
        try { clearSetup(key); } catch { /* The retained commands still replay exactly. */ }
    } catch { if (!disposed) message.value = 'open'; }
    finally { if (!disposed) opening.value = false; }
}

async function save() {
    if (busy.value || finished.value || (!draft.plan && !wizardAnswersValid(draft.answers, props.catalog))) return;
    const captured = stamp();
    const active = () => !disposed && owned(captured);
    busy.value = true;
    message.value = '';
    let command: string | undefined;
    let reconciling = false;
    const markPending = (id: string) => {
        command = id;
        reconciling = !!draft.uncertain?.includes(id);
        draft.uncertain = [...new Set([...(draft.uncertain ?? []), id])];
        if (!persist()) {
            if (!reconciling) draft.uncertain = draft.uncertain.filter(item => item !== id);
            message.value = 'storage';
            return false;
        }
        if (receipt.value) creation.set(key, receipt.value.business_public_id);
        return true;
    };
    const settle = (id: string) => {
        settled.add(id);
        draft.uncertain = draft.uncertain?.filter(item => item !== id);
        if (!uncertain.value) creation.delete(key);
        persist();
        command = undefined;
    };
    try {
        if (!draft.plan) {
            const plan = await prepareSetup(draft, props.catalog, attempt);
            if (!active()) return;
            draft.plan = plan;
        }
        const plan = draft.plan;
        if (!receipt.value) {
            if (!markPending(plan.create.command_id)) return;
            const result = await create(plan.create);
            if (!active()) return;
            receipt.value = result;
            draft.created = true;
            settle(plan.create.command_id);
            if (uncertain.value) creation.set(key, result.business_public_id);
        }
        const business = receipt.value.business_public_id;
        const { markets, preferences, practices } = setupDeclarations(plan, receipt.value);
        for (const fact of [markets, preferences]) {
            if (!fact || settled.has(fact.command_id)) continue;
            if (!markPending(fact.command_id)) return;
            const result = await writeFact(business, fact);
            if (!active()) return;
            if (result.business_public_id !== business) throw Error('Business fact acknowledgement subject mismatch');
            settle(fact.command_id);
        }
        for (const practice of practices) {
            if (settled.has(practice.command_id)) continue;
            if (!markPending(practice.command_id)) return;
            const result = await writePractice(business, practice);
            if (!active()) return;
            if (result.business_public_id !== business || result.practice.practice_id !== practice.practice_id) {
                throw Error('Practice acknowledgement subject mismatch');
            }
            settle(practice.command_id);
        }
        finished.value = true;
        busy.value = false;
        await openDashboard();
    } catch (error) {
        if (!active()) return;
        const status = error instanceof TransportError ? error.status : 0;
        if (command && !reconciling && [403, 409, 422].includes(status)) {
            draft.uncertain = draft.uncertain?.filter(item => item !== command);
            if (!uncertain.value) creation.delete(key);
            if (status === 422 && !draft.created) draft.plan = undefined;
            persist();
        }
        if (status === 403) receipt.value = undefined;
        message.value = status === 403 ? 'denied' : status === 409 ? 'conflict'
            : status === 422 && !reconciling ? 'invalid' : 'unknown';
    } finally { if (active()) busy.value = false; }
}
</script>

<template>
    <div class="business-setup-header"><RouterLink class="bf-link" to="/businesses">{{ t('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></div>
    <BusinessWizard v-if="!draft.plan && !busy" :catalog="catalog" :answers="draft.answers" :initial-step="draft.step"
        :action-label="t('إنشاء النشاط وفتح لوحته', 'Create business and open dashboard')"
        @update:answers="updateAnswers" @step="updateStep" @finish="save" />
    <section v-else class="business-setup-progress" :aria-busy="busy" data-business-setup-progress>
        <p class="bf-eyebrow">{{ t('تعريف النشاط', 'Business setup') }}</p>
        <h1>{{ message === 'denied' ? t('استكمال إعداد النشاط', 'Finish business setup') : (receipt?.name ?? draft.answers.name) }}</h1>
        <p v-if="busy" role="status">{{ t('جارٍ حفظ معلومات النشاط…', 'Saving business details…') }}</p>
        <p v-else-if="receipt" data-business-created>{{ finished ? t('حُفظت معلومات النشاط.', 'Your business details were saved.') : t('أُنشئ النشاط. يمكنك استكمال حفظ تفاصيله دون إنشاء نشاط آخر.', 'The business was created. Finish saving its details using the existing business.') }}</p>
        <div v-if="!busy" class="business-setup-actions">
            <button v-if="finished" type="button" class="bf-primary" :disabled="opening" @click="openDashboard">{{ t('فتح لوحة النشاط', 'Open business dashboard') }}</button>
            <template v-else>
                <button type="button" class="bf-primary" data-setup-reconcile @click="save">{{ uncertain ? t('التحقق من النتيجة', 'Check the outcome') : t('استكمال الإعداد', 'Finish setup') }}</button>
                <RouterLink v-if="receipt && !uncertain" class="bf-link" :to="destination">{{ t('فتح النشاط واستكماله لاحقًا', 'Open business and finish later') }}</RouterLink>
            </template>
        </div>
    </section>
    <p v-if="message" class="business-setup-notice" role="alert" :data-business-unknown="uncertain ? '' : undefined">{{ notice }}</p>
</template>

<style scoped>
.business-setup-header{margin:0 0 15px}.business-setup-header a{display:inline-flex;align-items:center;min-height:44px}
.business-setup-progress,.business-setup-notice{max-width:680px;margin-inline:auto;border-radius:12px;border:1px solid var(--ad-line);padding:clamp(17px,3vw,30px);background:var(--ad-paper)}
.business-setup-progress h1{font-size:clamp(24px,3vw,36px);line-height:1.4;margin:3px 0 12px;overflow-wrap:anywhere}
.business-setup-actions{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:16px}.business-setup-actions :is(button,a){min-height:44px}
.business-setup-notice{margin-block:12px 0;border-inline-start:3px solid var(--ad-accent);padding-block:12px}
</style>
