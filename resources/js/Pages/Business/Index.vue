<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdvertiserLayout from '@/Layouts/AdvertiserLayout.vue';
import { useBusinessCopy } from '@/localization/business';
defineProps<{ workspaces: { public_id: string; name: string; slug: string }[]; reassessableWorkspaces: { public_id: string; name: string; slug: string }[] }>();
const { t } = useBusinessCopy();
const reassessing = ref<string | null>(null);
const reassessmentError = ref('');
function reassessOwnerGrant(workspace: string) {
    reassessmentError.value = ''; reassessing.value = workspace;
    router.post(route('workspaces.reassess-owner-grant', workspace), {}, { preserveScroll: true, onError: () => { reassessmentError.value = t('reassessmentError'); }, onFinish: () => { reassessing.value = null; } });
}
</script>
<template><Head :title="t('businesses')" /><AdvertiserLayout><p class="biz-eyebrow">{{ t('businesses') }}</p><h1>{{ t('choose') }}</h1><p class="biz-lead">{{ t('chooseIntro') }}</p><div class="business-list biz-divider"><Link v-for="workspace in workspaces" :key="workspace.public_id" :href="route('workspaces.show', workspace.public_id)" class="business-row"><span>{{ workspace.name }}</span><span class="biz-link">{{ t('continue') }}</span></Link><p v-if="!workspaces.length" class="biz-lead">{{ t('empty') }}</p></div><section v-if="reassessableWorkspaces.length" class="biz-divider"><h2>{{ t('reassess') }}</h2><p class="biz-lead">{{ t('reassessIntro') }}</p><p v-if="reassessmentError" role="alert" class="biz-error">{{ reassessmentError }}</p><div v-for="workspace in reassessableWorkspaces" :key="workspace.public_id" class="business-row"><span>{{ workspace.name }}</span><button type="button" class="biz-link" :disabled="!!reassessing" @click="reassessOwnerGrant(workspace.public_id)">{{ reassessing === workspace.public_id ? t('reassessing') : t('reassessAction') }}</button></div></section><Link :href="route('business.start') + '?new=1'" class="biz-button mt-8">{{ t('new') }}</Link></AdvertiserLayout></template>
<style scoped>.business-list{max-width:800px}.business-row{display:flex;justify-content:space-between;gap:20px;padding:23px 0;border-bottom:1px solid var(--biz-line);font-size:17px}.business-row>span:first-child{overflow-wrap:anywhere}.business-row .biz-link{font-size:14px;flex-shrink:0}</style>
