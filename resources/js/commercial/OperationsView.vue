<script setup lang="ts">
import OperationsEditor from './OperationsEditor.vue';
import { operationsData } from './operationsState';
</script>

<template><OperationsEditor v-if="operationsData" v-bind="operationsData" /></template>
