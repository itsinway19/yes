<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { ref } from 'vue';
defineProps<{ to?: string }>();
const publishedLogo = typeof document === 'undefined' ? '' : document.querySelector?.('meta[name="builderad-logo"]')?.getAttribute('content') ?? '';
const logo = /^\/brand\/assets\/[0-9a-f]{64}\.png$/.test(publishedLogo) ? publishedLogo : '';
const failed = ref(false);
</script>
<template><component :is="to ? RouterLink : 'a'" v-bind="to ? { to } : { href: '/' }" class="ba-logo" lang="en" dir="ltr" aria-label="BuilderAd"><img v-if="logo && !failed" :src="logo" data-published-brand-logo style="width:auto;height:36px;max-width:180px;object-fit:contain" alt="" aria-hidden="true" @error="failed = true"><template v-else><img :src="'/brand/builderad-mark.svg'" width="36" height="36" alt="" aria-hidden="true"><span>Builder<b>Ad</b></span></template></component></template>
