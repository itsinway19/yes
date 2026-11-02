<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, clear, load } from './state';
import { status } from '../spa/presentation';
import { owned, stamp } from '../spa/transport';
import { locale } from '../spa/locale';
import { key } from './drafts';
import BusinessCreate from './BusinessCreate.vue';
import BusinessWorkspace from './BusinessWorkspace.vue';
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const data = computed(() => current.value?.data);
const route = useRoute();
let disposed = false;
let refreshAttempt = 0;
const refreshing = ref(false);
onBeforeUnmount(() => { disposed = true; });
async function refresh() {
    const captured = stamp(), path = route.fullPath, attempt = ++refreshAttempt;
    refreshing.value = true;
    try { await load(route, true); }
    catch { if (!disposed && owned(captured) && route.fullPath === path) { clear(); status.value = 'unavailable'; } }
    finally { if (!disposed && refreshAttempt === attempt) refreshing.value = false; }
}
</script>
<template>
    <main class="bf-main" data-spa-private data-business-surface>
        <nav class="bf-nav" :aria-label="t('التنقل بين الأنشطة', 'Business navigation')">
            <RouterLink to="/businesses">{{ t('أنشطتي', 'My businesses') }}</RouterLink>
            <RouterLink to="/workspaces">{{ t('مساحات التعاون', 'Collaboration workspaces') }}</RouterLink>
            <RouterLink to="/data-rights">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink>
            <RouterLink to="/source-rights">{{ t('إدارة المصادر', 'Manage sources') }}</RouterLink>
        </nav>
        <template v-if="data && 'items' in data">
            <header class="bf-heading"><div><p class="bf-eyebrow">BuilderAd</p><h1>{{ t('أنشطتي التجارية', 'My businesses') }}</h1><p>{{ t('اختر النشاط الذي تريد العمل عليه.', 'Choose the business you want to work on.') }}</p></div>
                <RouterLink class="bf-primary" to="/businesses/new">{{ t('إضافة نشاط', 'Add a business') }}</RouterLink>
            </header>
            <details class="bf-card" data-business-account-reference>
                <summary>{{ t('معرّف حسابي لاستقبال تفويض', 'My account identifier for receiving a delegation') }}</summary>
                <code class="bf-account-id" data-business-account-id>{{ data.actor_public_id }}</code>
                <p>{{ t('يمكنك تزويد صاحب التفويض بهذا المعرّف. عرضه لا يمنح حقًا على أي نشاط أو بيانات.', 'You may give this identifier to the delegating person. Displaying it grants no business or data authority.') }}</p>
            </details>
            <section v-if="!data.items.length" class="bf-card" data-business-empty>
                <h2>{{ data.next_cursor ? t('لا أنشطة متاحة في هذه الصفحة', 'No available businesses on this page') : t('لا توجد أنشطة متاحة هنا', 'No businesses available here') }}</h2>
                <p>{{ t('يمكنك إنشاء نشاطك. الأنشطة المسحوبة صلاحيتها لا تظهر ضمن خيارات الوصول.', 'You can create your business. Withdrawn access does not appear among your choices.') }}</p>
            </section>
            <ul v-else class="bf-grid" data-business-list>
                <li v-for="item in data.items" :key="item.read_grant_id" class="bf-card">
                    <p class="bf-badge">{{ item.realm_kind === 'agency' ? t('عبر تفويض وكالة', 'Through an agency delegation') : t('وصول مباشر', 'Direct access') }}</p>
                    <h2>{{ item.name }}</h2>
                    <p>{{ item.state === 'declared_draft' ? t('نشاط معرّف ببياناتك', 'Self-declared business') : t('نشاط متاح', 'Available business') }}</p>
                    <RouterLink :to="`/businesses/${item.business_public_id}?access=${item.read_grant_id}`" class="bf-link">{{ t('فتح النشاط', 'Open business') }}</RouterLink>
                </li>
            </ul>
            <RouterLink v-if="data.next_cursor" :to="`/businesses?after=${data.next_cursor}`" class="bf-link">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
        </template>
        <BusinessWorkspace v-else-if="data && 'business' in data" :key="key(data.business.business_public_id, data.authority_selection)" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <BusinessCreate v-else-if="data && 'catalog' in data" :catalog="data.catalog" />
    </main>
</template>
