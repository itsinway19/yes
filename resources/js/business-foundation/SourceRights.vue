<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, load, clear } from './state';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
import { key } from './drafts';
import SourceRightsEditor from './SourceRightsEditor.vue';
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const data = computed(() => current.value?.data);
const route = useRoute(), refreshing = ref(false);
let disposed = false, attempt = 0;
onBeforeUnmount(() => { disposed = true; });
async function refresh() {
    const captured = stamp(), path = route.fullPath, serial = ++attempt; refreshing.value = true;
    try { await load(route, true); }
    catch { if (!disposed && owned(captured) && route.fullPath === path) { clear(); status.value = 'unavailable'; } }
    finally { if (!disposed && serial === attempt) refreshing.value = false; }
}
</script>
<template>
    <main class="bf-main" data-spa-private data-source-rights-surface>
        <nav class="bf-nav"><RouterLink to="/businesses">{{ t('أنشطتي', 'My businesses') }}</RouterLink><RouterLink to="/source-rights">{{ t('إدارة المصادر', 'Manage sources') }}</RouterLink><RouterLink to="/data-rights">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink></nav>
        <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('مصادر البيانات', 'Data sources') }}</p><h1>{{ t('إدارة المصادر', 'Manage sources') }}</h1><p>{{ t('إدارة المصدر وحقوقه التقنية لا تمنح حقًا على بيانات نشاط أو عميل، ولا تثبت جاهزية مزود خارجي.', 'Source administration and technical rights grant no business or client-data authority and do not establish provider readiness.') }}</p></div></header>
        <template v-if="data && 'sources' in data">
            <section v-if="!data.sources.length" class="bf-card" data-source-rights-empty><h2>{{ t('لا مصادر مؤهلة للإدارة معروضة', 'No qualified sources displayed for administration') }}</h2><p>{{ t('تظهر فقط المصادر ذات سند الإدارة الحالي وصاحب المسؤولية المؤهل. غيابها هنا لا يعني غياب بيانات أو نجاح اتصال.', 'Only sources with current management authority and a qualified responsible owner appear. Absence here says nothing about data or connection success.') }}</p></section>
            <ul v-else class="bf-grid" data-source-rights-list><li v-for="item in data.sources" :key="item.access_grant_id" class="bf-card"><h2>{{ item.label }}</h2><p>{{ item.status === 'REVOKED' ? t('متقاعد — التاريخ محفوظ', 'Retired — history retained') : t('تصريح غير متحقق خارجيًا', 'Not externally verified') }}</p><p class="bf-wrap">{{ item.resource }} · {{ item.operation }} · {{ item.purpose }}</p><RouterLink class="bf-link" :to="`/source-rights/${item.source_id}?access=${item.access_grant_id}`">{{ t('فتح إدارة المصدر', 'Open source administration') }}</RouterLink></li></ul>
            <RouterLink v-if="data.next_cursor" class="bf-link" :to="`/source-rights?after=${data.next_cursor}`">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
        </template>
        <SourceRightsEditor v-else-if="data && 'source' in data" :key="key(data.source.source_id, data.authority_selection)" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <p v-if="refreshing" role="status">{{ t('جارٍ التحقق من المصدر وحقوقه…', 'Checking source and rights…') }}</p>
    </main>
</template>
