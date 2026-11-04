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
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import { ArrowLeft, Building2, Plus } from '@lucide/vue';
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
    <div data-spa-private data-business-surface>
        <BusinessWorkspace v-if="data && 'business' in data" :key="key(data.business.business_public_id, data.authority_selection)" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <AdvertiserWorkspaceLayout v-else>
        <template v-if="data && 'items' in data">
            <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('مساحة المعلن', 'Advertiser workspace') }}</p><h1>{{ t('أنشطتي التجارية', 'My businesses') }}</h1><p>{{ t('افتح نشاطًا لمراجعة معلوماته، وتحديد اتجاهه، وإدارة بياناته.', 'Open a business to review its details, set its direction and manage its data.') }}</p></div>
                <RouterLink class="bf-primary" to="/businesses/new"><Plus aria-hidden="true" :size="15" />{{ t('إضافة نشاط', 'Add a business') }}</RouterLink>
            </header>
            <section v-if="!data.items.length" class="bf-card advertiser-empty" data-business-empty>
                <Building2 aria-hidden="true" />
                <h2>{{ data.next_cursor ? t('لا أنشطة في هذه الصفحة', 'No businesses on this page') : t('ابدأ بإضافة نشاطك', 'Start with your business') }}</h2>
                <p>{{ t('أضف اسم نشاطك لتبدأ. يمكنك استكمال مجاله وأسواقه لاحقًا، دون ربط حساب إعلاني.', 'Add its name to get started. You can choose its field and markets later, without connecting an ad account.') }}</p>
                <RouterLink class="bf-primary" to="/businesses/new"><Plus aria-hidden="true" :size="15" />{{ t('إضافة نشاط', 'Add a business') }}</RouterLink>
            </section>
            <ul v-else class="bf-grid" data-business-list>
                <li v-for="item in data.items" :key="item.read_grant_id" class="bf-card">
                    <p class="bf-badge">{{ item.realm_kind === 'internal_team' ? t('إدارة ملف ضمن فريق', 'File administration within a team') : item.realm_kind === 'agency' ? t('عبر تفويض وكالة', 'Through an agency delegation') : t('وصول مباشر', 'Direct access') }}</p>
                    <h2>{{ item.name }}</h2>
                    <p>{{ item.state === 'declared_draft' ? t('معلومات أضافها النشاط', 'Business-provided details') : t('جاهز لفتح مساحة العمل', 'Open its workspace') }}</p>
                    <RouterLink :to="`/businesses/${item.business_public_id}?access=${item.read_grant_id}`" class="bf-link bf-open"><span>{{ t('فتح النشاط', 'Open business') }}</span><ArrowLeft aria-hidden="true" /></RouterLink>
                </li>
            </ul>
            <RouterLink v-if="data.next_cursor" :to="`/businesses?after=${data.next_cursor}`" class="bf-link">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
            <details class="bf-card" data-business-account-reference>
                <summary>{{ t('هل تريد أن يدعوك صاحب نشاط آخر؟', 'Joining another business?') }}</summary>
                <p>{{ t('أرسل معرّف حسابك لصاحب النشاط ليجهّز التفويض المناسب لك.', 'Share your account identifier so the business owner can prepare your delegation.') }}</p>
                <code class="bf-account-id" data-business-account-id>{{ data.actor_public_id }}</code>
                <p>{{ t('مشاركة المعرّف وحدها لا تفتح بيانات النشاط.', 'Sharing the identifier alone does not open business data.') }}</p>
            </details>
        </template>
        <BusinessCreate v-else-if="data && 'catalog' in data" :catalog="data.catalog" />
        </AdvertiserWorkspaceLayout>
    </div>
</template>
