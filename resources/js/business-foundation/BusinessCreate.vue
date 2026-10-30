<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { create, type Catalog } from './api';
import { creation } from './drafts';
const props = defineProps<{ catalog: Catalog }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const draft = reactive(creation), busy = ref(false), message = ref('');
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
        message.value = t('أُنشئ النشاط بنجاح.', 'Business created.');
    } catch (error) {
        if (disposed || !owned(captured)) return;
        if (error instanceof TransportError && error.status === 422) {
            draft.pending = undefined; message.value = t('راجع الاسم ونوع النشاط. لم يُقبل هذا الإدخال.', 'Review the name and business type. This input was rejected.');
        } else {
            message.value = t('لم تصل نتيجة مؤكدة. احتفظنا بالطلب الأصلي؛ لا تنشئ طلبًا جديدًا لنفس النشاط.', 'No confirmed outcome arrived. The original command is retained; do not create a new command for this business.');
        }
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('نشاط جديد', 'New business') }}</p><h1>{{ t('عرّف نشاطك التجاري', 'Introduce your business') }}</h1><p>{{ t('ابدأ بالاسم. يمكنك تحديد المجال الآن أو لاحقًا.', 'Start with a name. You can choose its field now or later.') }}</p></div></header>
    <section v-if="draft.receipt" class="bf-card" data-business-created>
        <h2>{{ draft.receipt.name }}</h2><p role="status">{{ t('أُنشئ النشاط. يمكنك الآن فتح معلوماته.', 'Business created. You can now open its details.') }}</p>
        <RouterLink class="bf-primary" :to="`/businesses/${draft.receipt.business_public_id}?access=${draft.receipt.grant_refs['business.context.read']}`">{{ t('فتح النشاط', 'Open business') }}</RouterLink>
        <button class="bf-link" @click="Object.assign(draft, { name: '', type: null, pending: undefined, receipt: undefined })">{{ t('إضافة نشاط آخر', 'Add another business') }}</button>
    </section>
    <form v-else class="bf-card bf-form" @submit.prevent="save">
        <fieldset :disabled="busy || !!draft.pending">
            <label for="bf-create-name">{{ t('اسم النشاط', 'Business name') }}</label><input id="bf-create-name" v-model="draft.name" required minlength="2" maxlength="120" autocomplete="organization" />
            <label for="bf-create-type">{{ t('مجال النشاط — اختياري', 'Business field — optional') }}</label><select id="bf-create-type" v-model="draft.type"><option :value="null">{{ t('أحدده لاحقًا', 'Choose later') }}</option><option v-for="type in props.catalog.business_types.filter(x => x.active)" :key="type.id" :value="type.id">{{ locale === 'ar' ? type.name_ar : type.name_en }}</option></select>
        </fieldset>
        <p v-if="draft.pending" role="status" data-business-unknown>{{ t('الطلب الأصلي محفوظ ونتيجته غير مؤكدة. التحقق التالي يستخدم الطلب نفسه.', 'The original command is retained with an unconfirmed outcome. The next check uses the same command.') }}</p>
        <p v-if="message" role="status">{{ message }}</p>
        <button class="bf-primary" :disabled="busy">{{ busy ? t('جارٍ الإرسال…', 'Sending…') : draft.pending ? t('تحقق بالطلب الأصلي', 'Check using original command') : t('إنشاء النشاط', 'Create business') }}</button>
    </form>
</template>
