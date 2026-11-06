<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { businessReturn, businessReturnPath } from './businessReturn';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import Sources from './Sources.vue';
import { current } from './state';
import { pending } from './commands';
import { confirmDeparture } from '../spa/presentation';
import { locale } from '../spa/locale';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';
import OrganizationTeam from '../workspaces/OrganizationTeam.vue';
import { hasPendingTeamCommand } from '../workspaces/team';
const organizationView = ref<'accounts' | 'team'>('accounts');
const teamVisited = ref(false);
function openTeam() {
    teamVisited.value = true;
    organizationView.value = 'team';
}
const returning = computed(() => current.value ? businessReturn(current.value.data.workspace.public_id) : undefined);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const canLeave = () => (!pending.size && !hasPendingTeamCommand()) || confirm(copy('توجد أوامر غير محسومة. هل تريد المغادرة مع الاحتفاظ بها في هذا المستند؟', 'Some commands have unknown outcomes. Leave while retaining them in this document?'));
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const beforeUnload = (event: BeforeUnloadEvent) => { if (pending.size || hasPendingTeamCommand()) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); });
</script>
<template>
    <div v-if="current" data-spa-private :data-sources-workspace="current.data.workspace.public_id">
        <AdvertiserWorkspaceLayout :context="current.data.workspace.name" :workspace-id="current.data.workspace.public_id" workspace-context>
            <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><RouterLink to="/workspaces">{{ copy('جهات العمل والفريق', 'Organizations and team') }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ current.data.workspace.name }}</span></nav>
            <RouterLink v-if="returning" class="business-return-link" data-business-return :to="businessReturnPath(returning)">{{ copy('العودة إلى مصادر النشاط', 'Return to business sources') }}</RouterLink>
            <nav class="organization-sections" :aria-label="copy('إدارة الجهة', 'Organization administration')">
                <button type="button" :aria-current="organizationView === 'accounts' ? 'page' : undefined" @click="organizationView = 'accounts'">{{ copy('الحسابات والمصادر', 'Accounts and sources') }}</button>
                <button type="button" :aria-current="organizationView === 'team' ? 'page' : undefined" @click="openTeam">{{ copy('الفريق', 'Team') }}</button>
            </nav>
            <section v-if="teamVisited" v-show="organizationView === 'team'">
                <header class="bf-heading"><div><h1>{{ copy('الفريق', 'Team') }}</h1><p>{{ copy('راجع أعضاء الجهة هنا. لتوزيع العمل على نشاط معيّن، افتحه من قائمة الأنشطة المتاحة لك.', 'Review organization members here. To assign work on a specific business, open it from your available businesses.') }}</p></div><RouterLink class="bf-link" to="/businesses">{{ copy('فتح الأنشطة المتاحة', 'Open available businesses') }}</RouterLink></header>
                <OrganizationTeam :key="current.data.workspace.public_id" :workspace="current.data.workspace.public_id" />
            </section>
            <div v-show="organizationView === 'accounts'" class="sources-page"><Sources v-bind="current.data" /></div>
        </AdvertiserWorkspaceLayout>
    </div>
</template>
<style>
.sources-page {
    --biz-ink: var(--ad-ink);
    --biz-muted: var(--ad-muted);
    --biz-line: var(--ad-line);
    --biz-accent: var(--ad-accent);
    color: var(--biz-ink);
    overflow-wrap: anywhere;
}
.sources-page h1 { font-size: 24px; font-weight: 650; line-height: 1.5; }
.sources-page h2 { font-size: 17px; font-weight: 650; line-height: 1.6; }
.sources-page p { line-height: 1.85; }
.sources-page .biz-lead { color: var(--biz-muted); max-width: 76ch; margin-block-start: 10px; font-size: 14px; }
.sources-page .biz-eyebrow { font-size: 12px; color: var(--biz-accent); font-weight: 650; margin-block-end: 8px; }
.sources-page .biz-button { display: inline-flex; align-items: center; justify-content: center; padding: 8px 16px; background: var(--ba-brand-primary); color: var(--ba-primary-text); border-radius: 7px; font-size: 14px; font-weight: 650; gap: 8px; min-height: 44px; }
.sources-page .biz-button:disabled { opacity: .5; }
.sources-page .biz-link { color: var(--biz-accent); text-decoration: underline; text-underline-offset: 4px; }
.sources-page .biz-form { display: grid; gap: 16px; max-width: 540px; margin-block-start: 24px; }
.sources-page .biz-form label { display: grid; gap: 6px; font-size: 14px; font-weight: 600; }
.sources-page .biz-form :is(input, select, textarea) { width: 100%; min-width: 0; background: var(--ad-paper); border: 1px solid var(--biz-line); border-radius: 7px; padding: 8px 10px; font-size: 14px; color: var(--biz-ink); }
.sources-page .biz-error { color: #a12c27; font-size: 13px; }
.dark .sources-page .biz-error { color: #ffb3ac; }
.sources-page .biz-success { color: var(--biz-accent); font-size: 14px; }
.sources-page .biz-divider { border-block-start: 1px solid var(--biz-line); margin-block-start: 24px; padding-block-start: 20px; }
.sources-page :focus-visible { outline: 2px solid var(--biz-accent); outline-offset: 4px; }
</style>
