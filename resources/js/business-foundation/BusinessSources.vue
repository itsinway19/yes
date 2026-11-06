<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Link2 } from '@lucide/vue';
import AdvertisingAccounts from '../sources/AdvertisingAccounts.vue';
import { pending } from '../sources/commands';
import { rememberBusinessReturn } from '../sources/businessReturn';
import { businessSources, type BusinessSources as BusinessSourcesResult, type Surface } from './api';
import { locale } from '../spa/locale';
import { owned, stamp } from '../spa/transport';

const props = defineProps<{ surface: Surface; active: boolean }>();
defineEmits<{ openAccess: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const result = ref<BusinessSourcesResult>();
const loading = ref(false);
const unavailable = ref(false);
let generation = 0;
const business = computed(() => props.surface.business.business_public_id);
const access = computed(() => Number(props.surface.authority_selection.requirements.find(item => item.requirement_kind === 'business')?.leaf_grant_id ?? 0));
const workspaces = computed(() => props.surface.collaboration.relationships.filter(item => item.state === 'active'));
function rememberReturn(workspace: string) {
    rememberBusinessReturn({ business: business.value, access: String(access.value), workspace });
}
const beforeUnload = (event: BeforeUnloadEvent) => { if (pending.size) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);

async function load(after?: number) {
    const attempt = ++generation, captured = stamp(), target = business.value;
    loading.value = true;
    unavailable.value = false;
    try {
        const page = await businessSources(target, access.value, after);
        if (attempt !== generation || !owned(captured) || !props.active || target !== business.value) return;
        if (page.business_public_id !== target || page.inventory_kind !== 'manageable_sources' || page.establishes_business_data_use !== false) throw Error('Business source inventory identity changed');
        result.value = after && result.value
            ? { ...page, sources: [...result.value.sources, ...page.sources] }
            : page;
    } catch {
        if (attempt === generation && owned(captured) && target === business.value) unavailable.value = true;
    } finally {
        if (attempt === generation && owned(captured)) loading.value = false;
    }
}
watch(() => [props.active, props.surface], () => {
    generation++;
    result.value = undefined;
    if (props.active) void load();
}, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { generation++; window.removeEventListener('beforeunload', beforeUnload); });
</script>

<template>
    <section class="business-sources" data-business-source-inventory>
        <p class="workspace-intro">{{ t('اربط الحساب الذي تحتاج إليه، ثم راجع ما يُسمح باستعماله من بياناته في هذا النشاط. يمكنك إعداد نشاطك وأسواقه قبل ربط أي حساب.', 'Connect the account you need, then review which of its data this business may use. You can set up your business and markets before connecting an account.') }}</p>
        <h2 class="workspace-subheading">{{ t('الحسابات الإعلانية للنشاط', 'Business advertising accounts') }}</h2>
        <AdvertisingAccounts :key="business" :business-id="business" :can-manage="true" />
        <details class="workspace-disclosure" data-business-source-inventory-details>
        <summary>{{ t('المصادر المشتركة مع جهة أو فريق', 'Sources shared through an organization or team') }}</summary>
        <p class="workspace-intro">{{ t('المصادر المتاحة لإدارتك. استعمال بياناتها في هذا النشاط يحتاج صلاحية مستقلة.', 'Sources available for you to administer. Using their data in this business needs separate permission.') }}</p>
        <p v-if="loading && !result" role="status">{{ t('جارٍ فحص المصادر المتاحة…', 'Checking available sources…') }}</p>
        <div v-if="unavailable" role="alert" class="workspace-source-status">
            <p>{{ t('تعذر التحقق من المصادر الآن. لا يعني هذا عدم وجودها.', 'Sources could not be checked. This does not mean none exist.') }}</p>
            <button type="button" class="workspace-button" @click="load()">{{ t('إعادة المحاولة', 'Try again') }}</button>
        </div>
        <template v-if="result">
            <ul v-if="result.sources.length" class="business-source-list" data-business-sources-list>
                <li v-for="source in result.sources" :key="`${source.source_id}:${source.resource}:${source.operation}:${source.purpose}`" class="business-source-item">
                    <div><strong>{{ source.label }}</strong><span>{{ source.family }} · {{ source.workspace_slug }}</span></div>
                    <span class="business-source-state">{{ source.status === 'REVOKED' ? t('مصدر مسحوب', 'Source revoked') : t('الاتصال غير متحقق هنا', 'Connection not verified here') }}</span>
                    <RouterLink :to="`/workspaces/${encodeURIComponent(source.workspace_public_id)}`" class="workspace-button" @click="rememberReturn(source.workspace_public_id)">{{ t('إدارة المصدر', 'Manage source') }}</RouterLink>
                </li>
            </ul>
            <p v-else data-business-sources-empty>{{ result.next_cursor
                ? t('لم تظهر مصادر مؤهلة في هذه الصفحة. قد توجد مصادر في الصفحات التالية.', 'No qualified sources appeared on this page. More pages may contain sources.')
                : workspaces.length
                ? t('لا تظهر لك مصادر مؤهلة للإدارة لدى الجهات المرتبطة. يمكنك مراجعة حسابات الجهة؛ غيابها هنا لا يثبت خلوها من الحسابات.', 'No manageable sources are visible in linked organizations. Review the organization’s accounts; this does not prove there are no accounts.')
                : t('لا توجد مصادر مشتركة ظاهرة لهذا النشاط. يمكنك ربط حساباته الإعلانية مباشرة من الأعلى.', 'No shared sources are visible for this business. Connect its advertising accounts directly above.') }}</p>
            <button v-if="result.next_cursor && Number.isSafeInteger(Number(result.next_cursor))" type="button" class="workspace-button" :disabled="loading" @click="load(Number(result.next_cursor))">{{ t('عرض المزيد من المصادر', 'Show more sources') }}</button>
        </template>
        <div v-if="workspaces.length" class="business-source-workspaces">
            <h3>{{ t('حسابات الجهات المرتبطة', 'Linked organizations’ accounts') }}</h3>
            <ul><li v-for="item in workspaces" :key="item.link_ref"><Link2 aria-hidden="true" /><span>{{ item.workspace_slug }}</span><RouterLink :to="`/workspaces/${encodeURIComponent(item.workspace_public_id)}`" @click="rememberReturn(item.workspace_public_id)">{{ t('فتح الجهة', 'Open organization') }}</RouterLink></li></ul>
        </div>
        <button v-if="!workspaces.length" type="button" class="workspace-button" @click="$emit('openAccess')">{{ t('مراجعة مشاركة المصادر مع جهة', 'Review organization source sharing') }}</button>
        </details>
    </section>
</template>
