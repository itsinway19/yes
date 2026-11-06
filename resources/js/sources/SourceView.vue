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
            <nav class="organization-sections" :aria-label="copy('إدارة الجهة', 'Organization administration')"><button type="button" :aria-current="organizationView === 'accounts' ? 'page' : undefined" @click="organizationView = 'accounts'">{{ copy('الحسابات والمصادر', 'Accounts and sources') }}</button><button type="button" :aria-current="organizationView === 'team' ? 'page' : undefined" @click="organizationView = 'team'">{{ copy('الفريق', 'Team') }}</button></nav>
            <section v-show="organizationView === 'team'"><h1>{{ copy('الفريق', 'Team') }}</h1><p>{{ copy('أضف أعضاء فريقك، ثم حدّد صلاحيات كل عضو داخل النشاط المعني. تعرض قائمة الأنشطة جميع الأنشطة المتاحة لك، وليست قائمة عملاء هذه الجهة.', 'Add team members, then assign each member’s rights inside the relevant business. The business list shows all businesses available to you, not a client directory for this organization.') }}</p><RouterLink class="workspace-button" to="/businesses">{{ copy('فتح الأنشطة المتاحة', 'Open available businesses') }}</RouterLink></section>
            <OrganizationTeam v-show="organizationView === 'team'" :key="current.data.workspace.public_id" :workspace="current.data.workspace.public_id" />
            <div v-show="organizationView === 'accounts'" class="sources-page"><Sources v-bind="current.data" /></div>
        </AdvertiserWorkspaceLayout>
    </div>
</template>
<style>
.sources-page{--biz-ink:#182d30;--biz-muted:#53666a;--biz-line:#d9e1de;--biz-accent:var(--ba-primary-ink);color:var(--biz-ink);overflow-wrap:anywhere}.sources-page h1{font-size:30px;font-weight:650;line-height:1.5}.sources-page h2{font-size:21px;font-weight:650;line-height:1.6}.sources-page p{line-height:1.9}.sources-page .biz-lead{color:var(--biz-muted);max-width:700px;margin-top:10px;font-size:15px}.sources-page .biz-eyebrow{font-size:12px;color:var(--biz-accent);font-weight:650;margin-bottom:8px}.sources-page .biz-button{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;background:var(--ba-brand-primary);color:var(--ba-primary-text);border-radius:5px;font-size:14px;font-weight:650;gap:8px;min-height:44px}.sources-page .biz-button:disabled{opacity:.5}.sources-page .biz-link{color:var(--biz-accent);text-decoration:underline;text-underline-offset:4px}.sources-page .biz-form{display:grid;gap:22px;max-width:540px;margin-top:32px}.sources-page .biz-form label{display:grid;gap:8px;font-size:14px;font-weight:600}.sources-page .biz-form input,.sources-page .biz-form select,.sources-page .biz-form textarea{width:100%;min-width:0;background:white;border:1px solid #9caeaa;border-radius:5px;padding:12px;font-size:15px;color:var(--biz-ink)}.sources-page .biz-error{color:#a12c27;font-size:13px}.sources-page .biz-success{color:#14635b;font-size:14px}.sources-page .biz-divider{border-top:1px solid var(--biz-line);margin-top:32px;padding-top:28px}.sources-page :focus-visible{outline:2px solid var(--biz-accent);outline-offset:4px}
</style>
