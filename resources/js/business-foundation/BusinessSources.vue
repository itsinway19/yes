<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Link2 } from '@lucide/vue';
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

async function load(after?: number) {
    const attempt = ++generation, captured = stamp(), target = business.value;
    loading.value = true;
    unavailable.value = false;
    try {
        const page = await businessSources(target, access.value, after);
        if (attempt !== generation || !owned(captured) || !props.active || target !== business.value) return;
        if (page.business_public_id !== target) throw Error('Business source target changed');
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
}, { immediate: true });
onBeforeUnmount(() => { generation++; });
</script>

<template>
    <section class="business-sources" data-business-source-inventory>
        <p class="workspace-intro">{{ t('تُدار الاتصالات داخل مساحة العمل. هنا ترى فقط المصادر التي يمكنك إدارتها في مساحة مرتبطة بهذا النشاط. استعمال بياناتها في نتائج النشاط يحتاج حقًا مستقلًا.', 'Connections are managed in a workspace. Here you see only sources you can manage in a workspace linked to this business. Using their data for business results requires separate authority.') }}</p>
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
                    <RouterLink :to="`/workspaces/${encodeURIComponent(source.workspace_public_id)}`" class="workspace-button">{{ t('إدارة المصدر', 'Manage source') }}</RouterLink>
                </li>
            </ul>
            <p v-else data-business-sources-empty>{{ result.next_cursor
                ? t('لم تظهر مصادر مؤهلة في هذه الصفحة. قد توجد مصادر في الصفحات التالية.', 'No qualified sources appeared on this page. More pages may contain sources.')
                : workspaces.length
                ? t('لا تظهر لك مصادر مؤهلة للإدارة في المساحات المرتبطة. يمكنك مراجعة اتصالات المساحة؛ غيابها هنا لا يثبت خلوها من الحسابات.', 'No manageable sources are visible in linked workspaces. Review workspace connections; this does not prove there are no accounts.')
                : t('لا توجد مساحة عمل مرتبطة ومتاحة لك لهذا النشاط. يمكنك إدارة النشاط دون مصدر، أو ربط مساحة تملك إدارتها من إعدادات النشاط.', 'No linked, accessible workspace is available for this business. You can use the business without a source or link a workspace you manage in business settings.') }}</p>
            <button v-if="result.next_cursor && Number.isSafeInteger(Number(result.next_cursor))" type="button" class="workspace-button" :disabled="loading" @click="load(Number(result.next_cursor))">{{ t('عرض المزيد من المصادر', 'Show more sources') }}</button>
        </template>
        <div v-if="workspaces.length" class="business-source-workspaces">
            <h3>{{ t('إدارة اتصالات المساحات المرتبطة', 'Manage linked workspace connections') }}</h3>
            <ul><li v-for="item in workspaces" :key="item.link_ref"><Link2 aria-hidden="true" /><span>{{ item.workspace_slug }}</span><RouterLink :to="`/workspaces/${encodeURIComponent(item.workspace_public_id)}`">{{ t('فتح المساحة', 'Open workspace') }}</RouterLink></li></ul>
        </div>
        <button v-if="!workspaces.length" type="button" class="workspace-button" @click="$emit('openAccess')">{{ t('مراجعة ربط مساحات العمل', 'Review workspace links') }}</button>
    </section>
</template>
