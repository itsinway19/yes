<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current, load, clear } from './state';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';
import { status } from '../spa/presentation';
import { key } from './drafts';
import BoundaryEditor from './BoundaryEditor.vue';
import AdvertiserWorkspaceLayout from './AdvertiserWorkspaceLayout.vue';
import { ShieldCheck } from '@lucide/vue';

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
    <div data-spa-private data-boundary-surface>
        <AdvertiserWorkspaceLayout>
        <header class="bf-heading">
            <div><p class="bf-eyebrow">{{ t('حقوق البيانات', 'Data permissions') }}</p><h1>{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</h1><p>{{ t('حدد النشاط الذي تخصه بيانات العميل عندما تكون مسؤولًا عن النطاق. تُراجع أذونات قراءة البيانات أو استعمالها بصورة مستقلة.', 'Identify which business a client’s data belongs to when you manage its boundary. Review permission to read or use the data separately.') }}</p></div>
            <RouterLink v-if="data && 'boundary' in data" class="bf-link" to="/data-rights">{{ t('نطاقات بيانات العملاء', 'Client data boundaries') }}</RouterLink>
        </header>
        <template v-if="data && 'boundaries' in data">
            <section v-if="!data.boundaries.length" class="bf-card advertiser-empty" data-boundary-empty><ShieldCheck aria-hidden="true" /><h2>{{ t('لا توجد نطاقات بيانات متاحة لك هنا', 'No data boundaries are available to you here') }}</h2><p>{{ t('ستجد هنا النطاقات التي يمكنك مراجعة تغطيتها. يمكنك العودة إلى نشاطك لمتابعة العمل عليه.', 'Boundaries whose coverage you can review appear here. Return to your business to continue working.') }}</p><RouterLink class="bf-primary" to="/businesses">{{ t('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></section>
            <ul v-else class="bf-grid" data-boundary-list>
                <li v-for="item in data.boundaries" :key="item.read_grant_id" class="bf-card">
                    <h2>{{ t('نطاق بيانات عميل', 'Client data boundary') }} · <bdi>{{ item.boundary_id.slice(-8) }}</bdi></h2>
                    <p>{{ item.state === 'active' ? t('نشط', 'Active') : item.state === 'restricted' ? t('مقيّد', 'Restricted') : t('مؤرشف', 'Archived') }}</p>
                    <RouterLink class="bf-link" :to="`/data-rights/${item.boundary_id}?access=${item.read_grant_id}`">{{ t('مراجعة الأنشطة المرتبطة', 'Review linked businesses') }}</RouterLink>
                    <details class="advertiser-context-note"><summary>{{ t('معرّف النطاق', 'Boundary ID') }}</summary><code class="bf-wrap">{{ item.boundary_id }}</code></details>
                </li>
            </ul>
            <RouterLink v-if="data.next_cursor" class="bf-link" :to="`/data-rights?after=${data.next_cursor}`">{{ t('الصفحة التالية', 'Next page') }}</RouterLink>
        </template>
        <BoundaryEditor v-else-if="data && 'boundary' in data" :key="`${key(data.boundary.boundary_id, data.authority_selection)}:boundary`" :surface="data" :refreshing="refreshing" @refresh="refresh" />
        <p v-if="refreshing" role="status" data-boundary-rechecking>{{ t('جارٍ التحقق من التغطية الحالية…', 'Checking current coverage…') }}</p>
        <details class="advertiser-context-note"><summary>{{ t('ماذا يعني ربط النطاق بنشاط؟', 'What does attaching a boundary to a business mean?') }}</summary><p>{{ t('يحدد الربط النشاط الذي يغطيه نطاق البيانات. لا يمنح وحده قراءة المصدر أو تحليله أو تصديره، ولا تعني عضوية مساحة التعاون ملكية بيانات العميل.', 'Attachment identifies the business covered by a data boundary. It grants no source reading, analysis or export authority, and workspace membership does not establish client-data ownership.') }}</p></details>
        </AdvertiserWorkspaceLayout>
    </div>
</template>
