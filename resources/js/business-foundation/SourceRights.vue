<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, load, clear } from './state';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
import { key } from './drafts';
import SourceRightsEditor from './SourceRightsEditor.vue';
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import { Link2 } from '@lucide/vue';
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
    <div data-spa-private data-source-rights-surface>
        <AdvertiserWorkspaceLayout>
        <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('المصادر', 'Sources') }}</p><h1>{{ t('حقوق المصادر', 'Source rights') }}</h1><p>{{ t('راجع من يمكنه إدارة المصدر، وما يُسمح له بفعله. لربط حساب إعلاني، افتح المصادر داخل النشاط المعني.', 'Review who can manage a source and what they are allowed to do. To connect an ad account, open Sources in the relevant business.') }}</p></div><RouterLink class="bf-link" to="/data-rights">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink></header>
        <template v-if="data && 'sources' in data">
            <section v-if="!data.sources.length" class="bf-card advertiser-empty" data-source-rights-empty><Link2 aria-hidden="true" /><h2>{{ t('لا توجد مصادر متاحة لك لإدارتها', 'No sources are available for you to manage') }}</h2><p>{{ t('تظهر هنا المصادر التي تملك حق إدارتها. اختر نشاطًا لمراجعة حساباته الإعلانية.', 'Sources you can manage appear here. Choose a business to review its advertising accounts.') }}</p><RouterLink class="bf-primary" to="/businesses">{{ t('فتح أنشطتي', 'Open my businesses') }}</RouterLink></section>
            <ul v-else class="bf-grid" data-source-rights-list><li v-for="item in data.sources" :key="item.access_grant_id" class="bf-card"><h2>{{ item.label }}</h2><p>{{ item.status === 'REVOKED' ? t('انتهت إدارته — السجل محفوظ', 'Administration ended — history retained') : t('تصريح غير متحقق خارجيًا', 'Not externally verified') }}</p><details class="advertiser-context-note"><summary>{{ t('تفاصيل المصدر', 'Source details') }}</summary><p class="bf-wrap">{{ item.resource }} · {{ item.operation }} · {{ item.purpose }}</p></details><RouterLink class="bf-link" :to="`/source-rights/${item.source_id}?access=${item.access_grant_id}`">{{ t('فتح إدارة المصدر', 'Open source administration') }}</RouterLink></li></ul>
            <RouterLink v-if="data.next_cursor" class="bf-link" :to="`/source-rights?after=${data.next_cursor}`">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
        </template>
        <SourceRightsEditor v-else-if="data && 'source' in data" :key="key(data.source.source_id, data.authority_selection)" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <p v-if="refreshing" role="status">{{ t('جارٍ التحقق من المصدر وحقوقه…', 'Checking source and rights…') }}</p>
        <details class="advertiser-context-note"><summary>{{ t('ما الفرق بين إدارة المصدر وربط حساب؟', 'How does source administration differ from connecting an account?') }}</summary><p>{{ t('تراجع هنا صلاحيات المصدر. راجع حالة الاتصال في النشاط أو الجهة التي تدير الحساب. يحتاج استعمال بياناته في أي نشاط إلى إذن مستقل.', 'Review source permissions here. Check the connection in the business or organization that manages the account. Using its data in a business requires separate permission.') }}</p></details>
        </AdvertiserWorkspaceLayout>
    </div>
</template>
