<script setup lang="ts">
import { RouterLink } from 'vue-router';
defineProps<{ to?: string }>();
</script>
<template><component :is="to ? RouterLink : 'a'" v-bind="to ? { to } : { href: '/' }" class="ba-logo" lang="en" dir="ltr" aria-label="BuilderAd"><img :src="'/brand/builderad-mark.svg'" width="36" height="36" alt="" aria-hidden="true"><span>Builder<b>Ad</b></span></component></template>
