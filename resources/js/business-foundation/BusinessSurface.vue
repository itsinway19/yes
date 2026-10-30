<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, clear, load } from './state';
import { status } from '../spa/presentation';
import { owned, stamp } from '../spa/transport';
import { locale } from '../spa/locale';
import { key } from './drafts';
import BusinessCreate from './BusinessCreate.vue';
import BusinessEditor from './BusinessEditor.vue';
import BusinessFacts from './BusinessFacts.vue';
import BusinessCollaboration from './BusinessCollaboration.vue';
import BusinessDelegation from './BusinessDelegation.vue';
import BusinessDataUse from './BusinessDataUse.vue';
import BusinessPurposes from './BusinessPurposes.vue';
import BusinessHistory from './BusinessHistory.vue';
import BusinessLegacyHistory from './BusinessLegacyHistory.vue';
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
        <template v-else-if="data && 'business' in data">
            <BusinessEditor :key="key(data.business.business_public_id, data.authority_selection)" :surface="data" :refreshing="refreshing" @refresh="refresh" />
            <details class="bf-card" data-business-commercial>
                <summary>{{ t('الاشتراك وإتاحة الخدمات', 'Subscription and service availability') }}</summary>
                <p>{{ t('يمكنك إدارة تصريحات النشاط وفق صلاحياتك. لم تُثبت بعد تغطية اشتراك أو حصة خدمة لهذا النشاط.', 'You can manage business declarations under your rights. Subscription coverage or a service allowance has not yet been established for this Business.') }}</p>
                <p>{{ t('لا ننقل اشتراك مساحة التعاون إلى النشاط تلقائيًا. إدارة المصدر أو الدفع لا يمنحان حق استعمال بيانات العملاء.', 'A collaboration workspace subscription is not automatically transferred to this Business. Source management or payment grants no customer-data access.') }}</p>
                <p>{{ t('الخدمات المشروطة غير مفعّلة دون عقد إتاحة وحصة مؤهل؛ عدم معرفة الحصة لا يعني صفرًا أو استعمالًا غير محدود.', 'Conditioned services remain unavailable without qualified entitlement and capacity. Unknown capacity means neither zero nor unlimited usage.') }}</p>
            </details>
            <BusinessFacts :key="`${key(data.business.business_public_id, data.authority_selection)}:facts`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
            <BusinessPurposes :key="`${key(data.business.business_public_id, data.authority_selection)}:purposes`" :surface="data" :refreshing="refreshing" />
            <BusinessHistory :key="`${key(data.business.business_public_id, data.authority_selection)}:history`" :surface="data" :refreshing="refreshing" />
            <BusinessLegacyHistory :key="`${key(data.business.business_public_id, data.authority_selection)}:legacy-history`" :surface="data" :refreshing="refreshing" />
            <BusinessCollaboration :key="`${key(data.business.business_public_id, data.authority_selection)}:relationships`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
            <BusinessDelegation :key="`${key(data.business.business_public_id, data.authority_selection)}:delegations`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
            <BusinessDataUse :key="`${key(data.business.business_public_id, data.authority_selection)}:data-use`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
            <p v-if="refreshing" role="status" data-business-rechecking>{{ t('جارٍ التحقق من معلومات النشاط الحالية…', 'Checking current business information…') }}</p>
        </template>
        <BusinessCreate v-else-if="data && 'catalog' in data" :catalog="data.catalog" />
    </main>
</template>
