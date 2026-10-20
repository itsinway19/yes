<script setup lang="ts">
import { onBeforeUnmount } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import Sources from './Sources.vue';
import { current } from './state';
import { pending } from './commands';
import { confirmDeparture } from '../spa/presentation';
const canLeave = () => !pending.size || confirm('توجد أوامر مصادر غير محسومة. هل تريد المغادرة مع الاحتفاظ بها في هذا المستند؟');
confirmDeparture.value = canLeave;
onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
const beforeUnload = (event: BeforeUnloadEvent) => { if (pending.size) { event.preventDefault(); event.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; window.removeEventListener('beforeunload', beforeUnload); });
</script>
<template>
    <main v-if="current" data-spa-private :data-sources-workspace="current.data.workspace.public_id" class="sources-page business-main">
        <Sources v-bind="current.data" />
    </main>
</template>
<style>
.sources-page{--biz-ink:#182d30;--biz-muted:#53666a;--biz-line:#d9e1de;--biz-accent:var(--ba-primary-ink);color:var(--biz-ink);overflow-wrap:anywhere}.sources-page h1{font-size:30px;font-weight:650;line-height:1.5}.sources-page h2{font-size:21px;font-weight:650;line-height:1.6}.sources-page p{line-height:1.9}.sources-page .biz-lead{color:var(--biz-muted);max-width:700px;margin-top:10px;font-size:15px}.sources-page .biz-eyebrow{font-size:12px;color:var(--biz-accent);font-weight:650;margin-bottom:8px}.sources-page .biz-button{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;background:var(--ba-brand-primary);color:var(--ba-primary-text);border-radius:5px;font-size:14px;font-weight:650;gap:8px;min-height:44px}.sources-page .biz-button:disabled{opacity:.5}.sources-page .biz-link{color:var(--biz-accent);text-decoration:underline;text-underline-offset:4px}.sources-page .biz-form{display:grid;gap:22px;max-width:540px;margin-top:32px}.sources-page .biz-form label{display:grid;gap:8px;font-size:14px;font-weight:600}.sources-page .biz-form input,.sources-page .biz-form select,.sources-page .biz-form textarea{width:100%;min-width:0;background:white;border:1px solid #9caeaa;border-radius:5px;padding:12px;font-size:15px;color:var(--biz-ink)}.sources-page .biz-error{color:#a12c27;font-size:13px}.sources-page .biz-success{color:#14635b;font-size:14px}.sources-page .biz-divider{border-top:1px solid var(--biz-line);margin-top:32px;padding-top:28px}.sources-page :focus-visible{outline:2px solid var(--biz-accent);outline-offset:4px}
</style>
