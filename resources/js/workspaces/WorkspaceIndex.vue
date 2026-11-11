<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { current } from './state';
import { reassessOwnerGrant } from './api';
import { bootstrap, owned, stamp } from '../spa/transport';
import { applicationDestination } from '../spa/destination';
import { locale } from '../spa/locale';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';
import { ArrowLeft, Layers3 } from '@lucide/vue';
import { advertisingPlatforms, selectedAdvertisingPlatform } from '../sources/providerDestination';

const data = computed(() => current.value && 'workspaces' in current.value.data ? current.value.data : undefined);
const busy = ref(''), error = ref('');
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const route = useRoute();
const selectedPlatform = computed(() => advertisingPlatforms.find(platform => platform.id === selectedAdvertisingPlatform(route.query.provider)));
const connectionDestination = (workspace: string) => ({ path: `/workspaces/${encodeURIComponent(workspace)}`, query: selectedPlatform.value ? { provider: selectedPlatform.value.id } : {} });
let alive = true;
onBeforeUnmount(() => { alive = false; });
async function reassess(workspace: string) {
    if (busy.value) return;
    const captured = stamp();
    busy.value = workspace; error.value = '';
    try {
        const result = await reassessOwnerGrant(workspace);
        if (alive && owned(captured)) location.assign(applicationDestination(result.redirect, location.origin, bootstrap.identity.binding.boundary));
    } catch {
        if (alive && owned(captured)) error.value = copy('تعذّرت إعادة تقييم الصلاحية. تحقّق من تسجيل الدخول الحديث وحالة المساحة.', 'Could not reassess authority. Check recent sign-in and workspace status.');
    } finally { busy.value = ''; }
}
</script>

<template>
    <div data-spa-private data-workspaces="index">
        <AdvertiserWorkspaceLayout workspace-context>
        <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ copy('الموارد والسجلات السابقة', 'Previous resources and records') }}</span></nav>
        <header class="bf-heading" data-legacy-resources><div><p class="bf-eyebrow">{{ copy('الوصول إلى الموجود سابقًا', 'Access to existing records') }}</p><h1>{{ copy('الموارد والسجلات السابقة', 'Previous resources and records') }}</h1><p>{{ copy('هذه سجلات قديمة محفوظة بصلاحياتها الأصلية. إدارة نشاطك وفريقك وتفويض الوكالات تبدأ من «أنشطتي». لا تنشئ هذه القائمة جهات أو أنشطة جديدة.', 'These existing records retain their original permissions. Manage your business, team and agency mandates in My businesses. This list creates no new organization or business.') }}</p></div><RouterLink class="bf-primary" to="/businesses">{{ copy('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></header>
        <section v-if="selectedPlatform" class="workspace-source-connection" data-workspace-platform-selection>
            <h2>{{ copy('اختر الجهة التي تدير حساب', 'Choose the organization that manages') }} {{ selectedPlatform.label }}</h2>
            <p>{{ copy('افتح الجهة لمراجعة حساباتها المشتركة وأذوناتها. إذا كان الحساب خاصًا بنشاطك، افتح المصادر داخل النشاط.', 'Open the organization to review its shared accounts and permissions. If the account belongs to your business, open Sources there.') }}</p>
        </section>
        <p class="workspace-creation-status" data-workspaces-creation-unavailable>{{ copy('أُوقف إنشاء جهات عمل مستقلة. للنشاط الفردي أو الوكالة، أنشئ نشاطًا تجاريًا وأدر فريقه من داخله.', 'Separate organization creation has been retired. For an individual business or an agency, create a Business and manage its team there.') }}</p>
        <section v-if="!data?.workspaces.length" class="bf-card advertiser-empty" data-workspaces-empty><Layers3 aria-hidden="true" /><h2>{{ copy('لا توجد سجلات سابقة مؤهلة للعرض', 'No qualified previous records are displayed') }}</h2><p>{{ copy('يمكنك متابعة إدارة نشاطك وفريقك ومصادرك من «أنشطتي». عدم ظهور سجل هنا لا يثبت خلوه من البيانات.', 'Manage your Business, team and sources in My businesses. Absence here does not establish that a record contains no data.') }}</p><RouterLink class="bf-primary" to="/businesses">{{ copy('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></section>
        <ul v-else class="bf-grid" data-workspaces-list><li v-for="workspace in data.workspaces" :key="workspace.public_id" class="bf-card"><p class="bf-badge">{{ copy('سجل سابق', 'Previous record') }}</p><h2>{{ workspace.name }}</h2><p>{{ copy('افتح الموارد والسجلات التي ما زالت مؤهلة لك بصلاحياتها الأصلية.', 'Open resources and records still qualified under their original permissions.') }}</p><RouterLink :to="connectionDestination(workspace.public_id)" class="bf-link bf-open"><span>{{ selectedPlatform ? `${copy('مراجعة اتصال', 'Review connection')} ${selectedPlatform.label}` : copy('فتح السجل', 'Open record') }}</span><ArrowLeft aria-hidden="true" /></RouterLink></li></ul>
        <section v-if="data?.reassessableWorkspaces.length" class="workspace-list">
            <h2>{{ copy('إعادة تقييم صلاحية المالك', 'Reassess owner authority') }}</h2>
            <p>{{ copy('بعد تغيير البريد وتأكيده، راجع مساحاتك القائمة بدخول حديث. لا تُعاد أي منحة سُحبت أو انتهت صلاحيتها.', 'After changing and verifying your email, review existing workspaces with a recent sign-in. Revoked or expired grants are not restored.') }}</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <div v-for="workspace in data.reassessableWorkspaces" :key="workspace.public_id" class="workspace-row">
                <span>{{ workspace.name }}</span>
                <button type="button" :disabled="!!busy" @click="reassess(workspace.public_id)">{{ busy === workspace.public_id ? copy('جارٍ التقييم…', 'Reassessing…') : copy('إعادة تقييم الصلاحية', 'Reassess authority') }}</button>
            </div>
        </section>
        <details class="advertiser-context-note"><summary>{{ copy('لماذا ما زالت هذه السجلات موجودة؟', 'Why are these records retained?') }}</summary><p>{{ copy('قد ترتبط بها مصادر أو سجلات أو أذونات سابقة لم تُنقل بعد بسند واضح. حفظها لا يحولها إلى نشاط تجاري، ولا ينقل ملكيتها أو حقوق بياناتها تلقائيًا.', 'Existing sources, records or permissions may depend on them until a qualified transfer is completed. Keeping them does not create a Business or automatically transfer ownership or data rights.') }}</p></details>
        <RouterLink class="bf-link workspace-secondary-action" to="/businesses/new">{{ copy('هل تحتاج نشاطًا تجاريًا جديدًا؟', 'Need a new business?') }}</RouterLink>
        </AdvertiserWorkspaceLayout>
    </div>
</template>

<style scoped>
.workspace-creation-status{margin:0 0 16px;color:var(--ad-muted);font-size:12px;line-height:1.8}.bf-heading>.bf-primary{display:inline-flex;align-items:center;white-space:nowrap;flex-shrink:0}@media(max-width:760px){.bf-heading{flex-wrap:wrap}}
</style>
