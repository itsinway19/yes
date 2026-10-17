<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { current } from './state';
</script>
<template><main v-if="current" style="padding:30px" data-spa="review" data-spa-private><h1>{{ current.workspace.name }}</h1><p>الأهداف المحفوظة</p><pre>{{ current.preferences.value }}</pre><RouterLink :to="`/spa/workspaces/${current.workspace.public_id}/goals`">العودة إلى الأهداف</RouterLink></main></template>
