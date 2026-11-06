<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { Plus } from '@lucide/vue';
import { locale } from '../spa/locale';
import { businessChoiceKey, type BusinessChoice } from './businessChoices';

const props = defineProps<{
    id: string;
    items: BusinessChoice[];
    selected?: BusinessChoice;
    phase: 'loading' | 'ready' | 'error' | 'closed';
    more: boolean;
}>();
const emit = defineEmits<{ select: [choice: BusinessChoice]; reload: []; more: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const choices = computed(() => {
    if (!props.selected) return props.items;
    const identity = businessChoiceKey(props.selected);
    // A just-admitted rename is fresher than a previously read catalog page.
    const listed = props.items.some(item => businessChoiceKey(item) === identity);
    return listed ? props.items.map(item => businessChoiceKey(item) === identity ? props.selected! : item)
        : [props.selected, ...props.items];
});
function label(item: BusinessChoice) {
    const context = item.realm_kind === 'agency' ? t('عبر وكالة', 'Through an agency')
        : item.realm_kind === 'internal_team' ? t('ضمن فريق جهة', 'Through an organization team') : t('وصول مباشر', 'Direct access');
    const collisions = choices.value.filter(other => other.name === item.name && other.realm_kind === item.realm_kind);
    const ordinal = collisions.findIndex(other => businessChoiceKey(other) === businessChoiceKey(item)) + 1;
    return `${item.name} · ${context}${collisions.length > 1 ? ` · ${t('مسار وصول', 'Access path')} ${ordinal}` : ''}`;
}
function select(event: Event) {
    const element = event.target as HTMLSelectElement;
    const choice = choices.value.find(item => businessChoiceKey(item) === element.value);
    element.value = props.selected ? businessChoiceKey(props.selected) : '';
    if (choice) emit('select', choice);
}
</script>
<template>
    <section class="advertiser-business-switcher" data-business-switcher>
        <label :for="id">{{ t('النشاط الحالي', 'Current business') }}</label>
        <select :id="id" data-business-switch :value="selected ? businessChoiceKey(selected) : ''" :disabled="phase === 'closed' || (!choices.length && phase === 'loading')" :aria-label="t('تبديل النشاط', 'Switch business')" @change="select">
            <option v-if="!selected" disabled value="">{{ t('اختر نشاطًا', 'Choose a business') }}</option>
            <option v-for="item in choices" :key="businessChoiceKey(item)" :value="businessChoiceKey(item)">{{ label(item) }}</option>
        </select>
        <p v-if="phase === 'loading'" role="status" data-business-choices-loading>{{ t('جارٍ تحميل الأنشطة المتاحة…', 'Loading available businesses…') }}</p>
        <p v-else-if="phase === 'error'" role="alert" data-business-choices-error>{{ t('تعذّر تحديث قائمة الأنشطة.', 'Could not refresh the business list.') }} <button type="button" @click="emit('reload')">{{ t('إعادة المحاولة', 'Try again') }}</button></p>
        <p v-else-if="phase === 'ready' && !items.length" role="status" data-business-choices-empty>{{ t('لا تظهر أنشطة في هذه القائمة.', 'No businesses appear in this list.') }}</p>
        <button v-if="more && phase === 'ready'" type="button" class="advertiser-choice-more" data-business-choices-more @click="emit('more')">{{ t('تحميل المزيد', 'Load more') }}</button>
        <div class="advertiser-business-actions">
            <RouterLink to="/businesses/new" data-business-create-entry><Plus aria-hidden="true" />{{ t('إضافة نشاط', 'Add a business') }}</RouterLink>
            <RouterLink to="/businesses">{{ t('كل الأنشطة', 'All businesses') }}</RouterLink>
        </div>
    </section>
</template>
