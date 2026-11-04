<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import PurposeView from './PurposeView.vue';
import { current, read } from './state';
import { owned, stamp } from '../spa/transport';
import { confirmDeparture } from '../spa/presentation';
import { locale } from '../spa/locale';
import type { PurposeAcknowledgement } from './api';
import AdvertiserWorkspaceLayout from '../business-foundation/AdvertiserWorkspaceLayout.vue';

const router = useRouter(), route = useRoute();
const editor = ref<{ hasUnsaved: () => boolean; pending: () => boolean }>();
const generation = ref(0);
const canLeave = () => !editor.value?.pending() && (!editor.value?.hasUnsaved() || confirm(locale.value === 'ar' ? 'لديك تصريح غير محفوظ أو أمر غير محسوم. هل تريد مغادرة الصفحة؟' : 'A declaration is unsaved or unresolved. Leave this page?'));
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
async function saved(reply: PurposeAcknowledgement) {
    const captured = stamp(); await nextTick(); if (!owned(captured)) return;
    const target = `/workspaces/${encodeURIComponent(String(route.params.workspace))}/object-purposes/${encodeURIComponent(reply.purpose_id)}`;
    if (route.fullPath !== target) await router.push(target);
    else { const latest = await read(route, true); if (owned(captured) && latest) generation.value++; }
}
const beforeUnload = (event: BeforeUnloadEvent) => { if (editor.value?.hasUnsaved() || editor.value?.pending()) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); });
</script>
<template>
    <div v-if="current" data-spa-private :data-purpose-workspace="current.data.workspace.public_id">
        <AdvertiserWorkspaceLayout :context="current.data.workspace.name" :workspace-id="current.data.workspace.public_id" workspace-context>
            <nav class="advertiser-breadcrumb" :aria-label="locale === 'ar' ? 'مسار الصفحة' : 'Breadcrumb'"><RouterLink to="/businesses">{{ locale === 'ar' ? 'أنشطتي' : 'My businesses' }}</RouterLink><span aria-hidden="true">/</span><RouterLink to="/workspaces">{{ locale === 'ar' ? 'مساحات العمل' : 'Workspaces' }}</RouterLink><span aria-hidden="true">/</span><RouterLink :to="`/workspaces/${current.data.workspace.public_id}`">{{ current.data.workspace.name }}</RouterLink><span aria-hidden="true">/</span><span aria-current="page">{{ locale === 'ar' ? 'الأغراض الإعلانية' : 'Advertising purposes' }}</span></nav>
            <div class="purpose-page"><PurposeView ref="editor" :key="`${route.fullPath}:${generation}`" v-bind="current.data" @saved="saved" /></div>
        </AdvertiserWorkspaceLayout>
    </div>
</template>
<style>
.purpose-page{--biz-ink:#182d30;--biz-muted:#53666a;--biz-line:#d9e1de;--biz-accent:var(--ba-primary-ink);color:var(--biz-ink);overflow-wrap:anywhere}.purpose-page h1{font-size:28px;font-weight:650;line-height:1.6}.purpose-page h2{font-size:21px;font-weight:650;margin-top:24px}.purpose-page h3{font-size:18px;font-weight:600}.purpose-page p{line-height:1.9}.purpose-page .biz-form,.purpose-page .purpose-fields{display:grid;gap:18px;min-width:0}.purpose-page .purpose-fields{border:0;padding:0}.purpose-page .biz-form label{display:grid;gap:8px;font-size:14px;font-weight:600;min-width:0}.purpose-page input:not([type=checkbox]),.purpose-page select,.purpose-page textarea{width:100%;min-width:0;border:1px solid #9caeaa;border-radius:5px;padding:12px;background:white}.purpose-page textarea{min-height:90px;resize:vertical}.purpose-page .biz-form label:has(input[type=checkbox]){display:flex;align-items:flex-start;gap:10px}.purpose-page input[type=checkbox]{flex-shrink:0;margin-top:6px}.purpose-page .biz-button{background:var(--ba-brand-primary);color:var(--ba-primary-text);padding:12px 20px;border-radius:5px;min-height:44px;font-weight:650}.purpose-page .biz-link{color:var(--biz-accent);text-decoration:underline;text-underline-offset:4px}.purpose-page .biz-error{color:#a12c27}.purpose-page :disabled{cursor:not-allowed;opacity:.65}.purpose-page a:focus-visible,.purpose-page button:focus-visible,.purpose-page input:focus-visible,.purpose-page select:focus-visible,.purpose-page textarea:focus-visible{outline:2px solid var(--biz-accent);outline-offset:4px}
</style>
