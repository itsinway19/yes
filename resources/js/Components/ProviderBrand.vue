<script setup lang="ts">
defineProps<{ provider: string; size?: number }>();
// Presentation assets only. Provider identities and supported capabilities remain server-owned.
const assets: Record<string, string> = {
    meta: '/images/integrations/meta_ads.webp',
    google: '/images/integrations/google_ads.webp',
    snapchat: '/images/integrations/snapchat_ads.webp',
    tiktok: '/images/integrations/tiktok_ads.webp',
};
</script>
<template>
    <span class="provider-brand" :style="{ width: `${size ?? 44}px`, height: `${size ?? 44}px` }" aria-hidden="true">
        <img v-if="assets[provider]" :src="assets[provider]" alt="" decoding="async" />
        <span v-else class="provider-brand-fallback">{{ provider === 'x' ? 'X' : provider }}</span>
    </span>
</template>
<style scoped>
.provider-brand{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;background:#fff;border:1px solid #e4e9e7;border-radius:10px;padding:7px;vertical-align:middle}.provider-brand img{width:100%;height:100%;object-fit:contain}.provider-brand-fallback{font-size:20px;font-weight:650;color:#182d30}
</style>
