<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { create, type Catalog } from './api';
import { creation } from './drafts';
const props = defineProps<{ catalog: Catalog }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const draft = reactive(creation), busy = ref(false), message = ref<'' | 'created' | 'invalid' | 'unknown'>('');
const notice = computed(() => message.value === 'created' ? t('تم إنشاء النشاط.', 'Business created.') : message.value === 'invalid' ? t('راجع اسم النشاط والمجال ثم حاول مجددًا.', 'Review the name and field, then try again.') : message.value === 'unknown' ? t('لم نتمكن من تأكيد إنشاء النشاط. تحقّق من النتيجة قبل المحاولة مجددًا.', 'We could not confirm creation. Check the outcome before trying again.') : '');
let disposed = false;
onBeforeUnmount(() => { disposed = true; });
async function save() {
    if (busy.value || draft.receipt) return;
    const captured = stamp();
    draft.pending ??= { command_id: crypto.randomUUID(), name: draft.name, business_type_id: draft.type };
    const command = JSON.parse(JSON.stringify(draft.pending));
    busy.value = true; message.value = '';
    try {
        const result = await create(command);
        if (disposed || !owned(captured)) return;
        draft.receipt = result; draft.pending = undefined;
        message.value = 'created';
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; message.value = 'invalid';
        } else {
            message.value = 'unknown';
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('أنشطتي / نشاط جديد', 'My businesses / New business') }}</p><h1>{{ t('إضافة نشاط', 'Add a business') }}</h1><p>{{ t('أدخل اسم نشاطك، وحدّد مجاله إن أردت.', 'Enter your business name and choose its field if you wish.') }}</p></div><RouterLink class="bf-link" to="/businesses">{{ t('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></header>
    <section v-if="draft.receipt" class="bf-card" data-business-created>
        <h2>{{ draft.receipt.name }}</h2><p role="status">{{ t('أُنشئ النشاط. يمكنك الآن فتح معلوماته.', 'Business created. You can now open its details.') }}</p>
        <RouterLink class="bf-primary" :to="`/businesses/${draft.receipt.business_public_id}?access=${draft.receipt.grant_refs['business.context.read']}`">{{ t('فتح النشاط', 'Open business') }}</RouterLink>
        <button class="bf-link" @click="Object.assign(draft, { name: '', type: null, pending: undefined, receipt: undefined })">{{ t('إضافة نشاط آخر', 'Add another business') }}</button>
    </section>
    <form v-else class="bf-card bf-form" @submit.prevent="save">
        <fieldset :disabled="busy || !!draft.pending">
            <label for="bf-create-name">{{ t('اسم النشاط', 'Business name') }}</label><input id="bf-create-name" v-model="draft.name" required minlength="2" maxlength="120" autocomplete="organization" />
            <label for="bf-create-type">{{ t('مجال النشاط — اختياري', 'Business field — optional') }}</label><select id="bf-create-type" v-model="draft.type"><option :value="null">{{ t('سأحدده لاحقًا', 'Choose later') }}</option><option v-for="type in props.catalog.business_types.filter(x => x.active)" :key="type.id" :value="type.id">{{ locale === 'ar' ? type.name_ar : type.name_en }}</option></select>
        </fieldset>
        <p v-if="draft.pending && !busy" role="status" data-business-unknown>{{ t('لم نتمكن من تأكيد إنشاء النشاط. تحقّق من النتيجة قبل المحاولة مجددًا.', 'We could not confirm creation. Check the outcome before trying again.') }}</p>
        <p v-if="notice && !draft.pending" role="status">{{ notice }}</p>
        <details v-if="draft.pending" class="advertiser-context-note"><summary>{{ t('تفاصيل التحقق', 'How the check works') }}</summary><p>{{ t('طلب الإنشاء نفسه محفوظ. التحقق يستخدم هويته وبياناته الأصلية، ولا ينشئ طلبًا بديلًا.', 'The creation request is retained. Checking uses its original identity and values, without creating a replacement request.') }}</p></details>
        <button class="bf-primary" :disabled="busy">{{ busy ? t('جارٍ الحفظ…', 'Saving…') : draft.pending ? t('التحقق من النتيجة', 'Check the outcome') : t('إنشاء النشاط', 'Create business') }}</button>
    </form>
</template>
