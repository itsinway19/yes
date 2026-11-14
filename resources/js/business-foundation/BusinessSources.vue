<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import BusinessServiceApproval from './BusinessServiceApproval.vue';
import BusinessSourceRegistry from './BusinessSourceRegistry.vue';
import AdvertisingAccounts from '../sources/AdvertisingAccounts.vue';
import BusinessDataLicenses from './BusinessDataLicenses.vue';
import BusinessTaskRow from './BusinessTaskRow.vue';
import { hasPendingDataLicense } from './dataLicenseJourney';
import { pending } from '../sources/commands';
import type { Surface } from './api';
import { locale } from '../spa/locale';

const props = defineProps<{ surface: Surface; active: boolean }>();
defineEmits<{ openAccess: []; refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const task = ref<'accounts' | 'licenses'>(hasPendingDataLicense(props.surface.business.business_public_id) ? 'licenses' : 'accounts');
const licensesVisited = ref(task.value === 'licenses');
const licensesTask = ref<{ focus: () => void }>();
const taskHeading = ref<HTMLElement>();
async function openTask(next: 'licenses') {
    if (next === 'licenses') licensesVisited.value = true;
    task.value = next;
    await nextTick();
    taskHeading.value?.focus();
}
async function closeTask() {
    task.value = 'accounts';
    await nextTick();
    licensesTask.value?.focus();
}
const business = computed(() => props.surface.business.business_public_id);
const beforeUnload = (event: BeforeUnloadEvent) => { if (pending.size) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);

onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
</script>

<template>
    <section class="business-sources" data-business-source-inventory>
        <template v-if="task === 'accounts'">
        <p class="workspace-intro">{{ t('حالة كل منصة والإجراء المتاح لحسابها. افتح التفاصيل لمراجعة الربط والصلاحية.', 'Each platform’s state and available account action. Open its details to review connection and access.') }}</p>
        </template>
        <div v-show="task === 'accounts'"><AdvertisingAccounts :key="business" :business-id="business" :can-manage="true" /></div>
        <BusinessServiceApproval v-if="task === 'accounts'" :surface="surface" @refresh="$emit('refresh')" /><BusinessSourceRegistry v-if="task === 'accounts'" :business-id="business" />
        <section v-if="task === 'accounts'" class="business-task-list">
            <BusinessTaskRow ref="licensesTask" task="source-licenses" :title="t('السماح باستعمال بيانات المصدر', 'Allow source data use')" :description="t('حدد البيانات والغرض والمستفيد والمدة قبل استعمالها في نشاطك.', 'Define the data, purpose, recipient and period before using it in this business.')" :action="t('مراجعة أذونات البيانات', 'Review data permissions')" @select="openTask('licenses')" />
        </section>
        <header v-else class="business-task-editor-heading"><h2 ref="taskHeading" tabindex="-1">{{ t('أذونات استعمال البيانات', 'Data-use permissions') }}</h2><button type="button" class="workspace-button" data-source-task-back @click="closeTask">{{ t('العودة إلى الحسابات', 'Back to accounts') }}</button></header>
        <BusinessDataLicenses v-if="licensesVisited" v-show="task === 'licenses'" :surface="surface" :active="active && task === 'licenses'" @refresh="$emit('refresh')" />
    </section>
</template>
