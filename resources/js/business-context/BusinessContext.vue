<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router';
import ContextForm from './ContextForm.vue';
import { current } from './state';
import { confirmDeparture } from '../spa/presentation';
import { locale } from '../spa/locale';
import { applicationDestination } from '../spa/destination';
import { bootstrap } from '../spa/transport';
type Editor = { hasUnsaved: () => boolean; pending: () => boolean };
const editor = ref<Editor>();
const router = useRouter();
async function continueTo(destination: string) {
    const target = new URL(applicationDestination(destination, location.origin, bootstrap.identity.binding.boundary));
    const path = target.pathname + target.search + target.hash;
    if (router.resolve(path).matched.length) await router.push(path);
    else location.assign(target.href);
}
const canLeave = () => !editor.value?.pending() && (!editor.value?.hasUnsaved() || confirm(locale.value === 'ar' ? 'لديك مسودات غير محفوظة. هل تريد مغادرة الصفحة؟' : 'You have unsaved drafts. Leave this page?'));
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const beforeUnload = (event: BeforeUnloadEvent) => { if (editor.value?.hasUnsaved() || editor.value?.pending()) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); });
</script>
<template>
    <main v-if="current" class="workspace-page context-page" data-spa-private :data-context-workspace="current.data.workspace.public_id">
        <ContextForm ref="editor" v-bind="current.data" :creation-key="null" @destination="continueTo" />
    </main>
</template>
<style>
.context-page{--biz-ink:#182d30;--biz-muted:#53666a;--biz-line:#d9e1de;--biz-accent:var(--ba-primary-ink);color:var(--biz-ink);overflow-wrap:anywhere}.context-page h1{font-size:30px;font-weight:650;line-height:1.5}.context-page h2{font-size:21px;font-weight:650;line-height:1.6}.context-page p{line-height:1.9}.context-page .biz-lead{color:var(--biz-muted);max-width:700px;margin-top:10px;font-size:15px}.context-page .biz-eyebrow{font-size:12px;color:var(--biz-accent);font-weight:650;margin-bottom:8px}.context-page .biz-button{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;background:var(--ba-brand-primary);color:var(--ba-primary-text);border-radius:5px;font-size:14px;font-weight:650;gap:8px;min-height:44px}.context-page .biz-button:disabled{opacity:.5}.context-page .biz-link{color:var(--biz-accent);text-decoration:underline;text-underline-offset:4px}.context-page .biz-form{display:grid;gap:22px;max-width:540px;margin-top:32px}.context-page .biz-form label{display:grid;gap:8px;font-size:14px;font-weight:600}.context-page .biz-form input,.context-page .biz-form select,.context-page .biz-form textarea{width:100%;min-width:0;background:white;border:1px solid #9caeaa;border-radius:5px;padding:12px;font-size:15px;color:var(--biz-ink)}.context-page .biz-error{color:#a12c27;font-size:13px}.context-page .biz-success{color:#14635b;font-size:14px}.context-page .biz-divider{border-top:1px solid var(--biz-line);margin-top:32px;padding-top:28px}.context-page a:focus-visible,.context-page button:focus-visible,.context-page input:focus-visible,.context-page select:focus-visible,.context-page summary:focus-visible{outline:2px solid var(--biz-accent);outline-offset:4px}.context-page :disabled{cursor:not-allowed}
</style>
