<script setup lang="ts">
import {onMounted,onUpdated} from 'vue';
import {locale} from '../spa/locale';
const sync=()=>document.dispatchEvent(new Event('builderad:theme-controls'));
onMounted(sync);onUpdated(sync);
</script>
<template><button type="button" class="ba-theme-toggle" data-theme-toggle :data-light-label="locale==='ar'?'الوضع النهاري':'Light mode'" :data-dark-label="locale==='ar'?'الوضع الليلي':'Dark mode'" :aria-label="locale==='ar'?'تبديل المظهر':'Switch theme'"><span data-theme-label></span></button></template>
