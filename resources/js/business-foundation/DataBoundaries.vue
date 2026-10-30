<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, load, clear } from './state';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
import { key } from './drafts';
import BoundaryEditor from './BoundaryEditor.vue';

const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const data = computed(() => current.value?.data);
const route = useRoute(), refreshing = ref(false);
let disposed = false, attempt = 0;
onBeforeUnmount(() => { disposed = true; });
async function refresh() {
    const captured = stamp(), path = route.fullPath, serial = ++attempt;
    refreshing.value = true;
    try { await load(route, true); }
    catch { if (!disposed && owned(captured) && route.fullPath === path) { clear(); status.value = 'unavailable'; } }
    finally { if (!disposed && serial === attempt) refreshing.value = false; }
}
</script>

<template>
    <main class="bf-main" data-spa-private data-boundary-surface>
        <nav class="bf-nav"><RouterLink to="/businesses">{{ t('أنشطتي', 'My businesses') }}</RouterLink><RouterLink to="/data-rights">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink></nav>
        <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('الخصوصية وحقوق البيانات', 'Privacy and data rights') }}</p><h1>{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</h1><p>{{ t('إدارة تغطية النشاط لبيانات العميل. ربط النطاق لا يمنح حق قراءة المصدر أو تحليله أو تصديره.', 'Manage which business a client-data boundary covers. Attachment grants no source reading, processing or export authority.') }}</p></div></header>
        <template v-if="data && 'boundaries' in data">
            <section v-if="!data.boundaries.length" class="bf-card" data-boundary-empty><h2>{{ t('لا نطاقات مؤهلة معروضة هنا', 'No qualified boundaries displayed here') }}</h2><p>{{ t('تظهر فقط النطاقات التي تملك حق إدارتها حاليًا. لا نستنتج ملكية بيانات العميل من النشاط أو مساحة التعاون.', 'Only boundaries you currently manage are displayed. Business or workspace membership does not establish client-data ownership.') }}</p></section>
            <ul v-else class="bf-grid" data-boundary-list><li v-for="item in data.boundaries" :key="item.read_grant_id" class="bf-card"><h2>{{ t('نطاق بيانات عميل', 'Client data boundary') }}</h2><code>{{ item.boundary_id }}</code><p>{{ item.state === 'active' ? t('نشط', 'Active') : item.state === 'restricted' ? t('مقيّد', 'Restricted') : t('مؤرشف', 'Archived') }}</p><RouterLink class="bf-link" :to="`/data-rights/${item.boundary_id}?access=${item.read_grant_id}`">{{ t('إدارة التغطية', 'Manage coverage') }}</RouterLink></li></ul>
            <RouterLink v-if="data.next_cursor" class="bf-link" :to="`/data-rights?after=${data.next_cursor}`">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
        </template>
        <BoundaryEditor v-else-if="data && 'boundary' in data" :key="`${key(data.boundary.boundary_id, data.authority_selection)}:boundary`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <p v-if="refreshing" role="status" data-boundary-rechecking>{{ t('جارٍ التحقق من التغطية الحالية…', 'Checking current coverage…') }}</p>
    </main>
</template>
