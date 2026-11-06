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
        <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ copy('جهات العمل والفريق', 'Organizations and team') }}</span></nav>
        <header class="bf-heading"><div><p class="bf-eyebrow">{{ copy('التعاون وموارد العمل', 'Collaboration and work resources') }}</p><h1>{{ copy('جهات العمل والفريق', 'Organizations and team') }}</h1><p>{{ copy('أعدّ جهة عمل لإدارة الفريق والموارد المشتركة عند حاجتك إليها. النشاط التجاري مستقل؛ لا تحتاج إلى تسجيل جهة للعمل منفردًا.', 'Set up an organization for team and shared resource administration when needed. A business is independent; individual work needs no organization registration.') }}</p></div><RouterLink v-if="data?.canCreateWorkspace === true" class="bf-primary" data-workspaces-add to="/organizations/new">{{ copy('إعداد جهة للفريق والموارد', 'Set up team and resource administration') }}</RouterLink></header>
        <section v-if="selectedPlatform" class="workspace-source-connection" data-workspace-platform-selection>
            <h2>{{ copy('اختر الجهة التي تدير حساب', 'Choose the organization that manages') }} {{ selectedPlatform.label }}</h2>
            <p>{{ copy('افتح الجهة لمراجعة حساباتها المشتركة وأذوناتها. إذا كان الحساب خاصًا بنشاطك، افتح المصادر داخل النشاط.', 'Open the organization to review its shared accounts and permissions. If the account belongs to your business, open Sources there.') }}</p>
        </section>
        <p v-if="data?.canCreateWorkspace === false" class="workspace-creation-status" data-workspaces-creation-unavailable>{{ copy('إعداد جهة جديدة غير متاح لحسابك حاليًا. يمكنك فتح الجهات المتاحة لك أدناه.', 'Setting up an organization is unavailable to this account. You can open the organizations listed below.') }}</p>
        <section v-if="!data?.workspaces.length" class="bf-card advertiser-empty" data-workspaces-empty><Layers3 aria-hidden="true" /><h2>{{ copy('لا توجد جهات متاحة لك حاليًا', 'No organizations are currently available to you') }}</h2><p>{{ copy('يمكنك متابعة العمل على أنشطتك مباشرة. تظهر هنا الجهات التي يمكنك العمل مع فريقها أو استخدام مواردها المشتركة.', 'You can continue working directly on your businesses. Organizations appear here when you can work with their team or shared resources.') }}</p><RouterLink class="bf-primary" to="/businesses">{{ copy('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></section>
        <ul v-else class="bf-grid" data-workspaces-list><li v-for="workspace in data.workspaces" :key="workspace.public_id" class="bf-card"><p class="bf-badge">{{ copy('جهة عمل', 'Organization') }}</p><h2>{{ workspace.name }}</h2><p>{{ copy('راجع الفريق والحسابات المشتركة المتاحة لك.', 'Review the team and shared accounts available to you.') }}</p><RouterLink :to="connectionDestination(workspace.public_id)" class="bf-link bf-open"><span>{{ selectedPlatform ? `${copy('مراجعة اتصال', 'Review connection')} ${selectedPlatform.label}` : copy('فتح الجهة', 'Open organization') }}</span><ArrowLeft aria-hidden="true" /></RouterLink></li></ul>
        <section v-if="data?.reassessableWorkspaces.length" class="workspace-list">
            <h2>{{ copy('إعادة تقييم صلاحية المالك', 'Reassess owner authority') }}</h2>
            <p>{{ copy('بعد تغيير البريد وتأكيده، راجع مساحاتك القائمة بدخول حديث. لا تُعاد أي منحة سُحبت أو انتهت صلاحيتها.', 'After changing and verifying your email, review existing workspaces with a recent sign-in. Revoked or expired grants are not restored.') }}</p>
            <p v-if="error" role="alert">{{ error }}</p>
            <div v-for="workspace in data.reassessableWorkspaces" :key="workspace.public_id" class="workspace-row">
                <span>{{ workspace.name }}</span>
                <button type="button" :disabled="!!busy" @click="reassess(workspace.public_id)">{{ busy === workspace.public_id ? copy('جارٍ التقييم…', 'Reassessing…') : copy('إعادة تقييم الصلاحية', 'Reassess authority') }}</button>
            </div>
        </section>
        <details class="advertiser-context-note"><summary>{{ copy('متى أحتاج إلى جهة عمل؟', 'When do I need an organization?') }}</summary><p>{{ copy('أعدّ جهة لإدارة موظفين أو حسابات مشتركة. يظل لكل نشاط فريقه وصلاحياته وبياناته. إذا كنت تعمل وحدك، يمكنك البدء مباشرة من أنشطتك.', 'Set up an organization to manage employees or shared accounts. Each business retains its team, permissions and data. If you work independently, start from My businesses.') }}</p></details>
        <RouterLink class="bf-link workspace-secondary-action" to="/businesses/new">{{ copy('هل تحتاج نشاطًا تجاريًا جديدًا؟', 'Need a new business?') }}</RouterLink>
        </AdvertiserWorkspaceLayout>
    </div>
</template>

<style scoped>
.workspace-creation-status{margin:0 0 16px;color:var(--ad-muted);font-size:12px;line-height:1.8}.bf-heading>.bf-primary{display:inline-flex;align-items:center;white-space:nowrap;flex-shrink:0}@media(max-width:760px){.bf-heading{flex-wrap:wrap}}
</style>
