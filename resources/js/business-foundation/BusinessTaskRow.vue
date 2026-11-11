<script setup lang="ts">
import { ref } from 'vue';
defineProps<{ title: string; description?: string; value?: string; action: string; disabled?: boolean; task?: string }>();
defineEmits<{ select: [] }>();
const button = ref<HTMLButtonElement>();
defineExpose({ focus: () => button.value?.focus() });
</script>
<template>
    <div class="business-task-row">
        <div class="business-task-copy"><h3>{{ title }}</h3><p v-if="value" class="business-task-value">{{ value }}</p><p v-if="description">{{ description }}</p></div>
        <button ref="button" type="button" class="workspace-button" :data-business-task="task" :disabled="disabled" @click="$emit('select')">{{ action }}</button>
    </div>
</template>
