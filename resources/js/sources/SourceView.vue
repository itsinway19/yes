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
const teamVisited = ref(false);
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
            <nav class="advertiser-breadcrumb" :aria-label="copy('مسار الصفحة', 'Breadcrumb')"><RouterLink to="/businesses">{{ copy('أنشطتي', 'My businesses') }}</RouterLink><span aria-hidden="true">/</span><RouterLink to="/workspaces">{{ copy('الموارد والسجلات السابقة', 'Previous resources and records') }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ current.data.workspace.name }}</span></nav>
            <RouterLink v-if="returning" class="business-return-link" data-business-return :to="businessReturnPath(returning)">{{ copy('العودة إلى مصادر النشاط', 'Return to business sources') }}</RouterLink>
            <aside class="bf-card" data-legacy-resource-notice><h1>{{ copy('سجل موارد سابق', 'Previous resource record') }}</h1><p>{{ copy('هذا السجل ليس نشاطًا تجاريًا أو فريقًا جديدًا. احتُفظ بموارده وأذوناته وتاريخه، ولا تُنقل إلى نشاط آخر دون تأهيل صريح.', 'This record is not a Business or a new team. Its resources, permissions and history are preserved and do not transfer to another Business without explicit qualification.') }}</p><RouterLink class="bf-link" to="/businesses">{{ copy('إدارة أنشطتي وفريقها', 'Manage my businesses and their teams') }}</RouterLink></aside>
            <div class="sources-page"><Sources v-bind="current.data" /></div>
            <details class="bf-card" data-legacy-resource-permissions @toggle="teamVisited = true">
                <summary>{{ copy('أذونات السجل السابق', 'Previous record permissions') }}</summary>
                <p>{{ copy('تُدار هنا فقط العضوية التي تعتمد عليها أذونات هذا السجل القديم. فريق النشاط وتكليف موظفي الوكالة يُداران داخل النشاط المعني.', 'Manage only the membership required by this old record’s permissions here. Business teams and agency employee assignments are managed inside the relevant Business.') }}</p>
                <section v-if="teamVisited">
                <OrganizationTeam :key="current.data.workspace.public_id" :workspace="current.data.workspace.public_id" />
                </section>
            </details>
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
