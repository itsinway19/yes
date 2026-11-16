<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{ open: boolean; labelledBy: string }>();
defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();

watch([dialog, () => props.open], ([element, open]) => {
    if (!element) return;
    if (open && !element.open) element.showModal();
    if (!open && element.open) element.close();
}, { flush: 'post' });
</script>

<template>
    <dialog ref="dialog" class="business-sheet" :aria-labelledby="labelledBy" @cancel.prevent="$emit('close')">
        <div class="business-sheet-frame"><slot /></div>
    </dialog>
</template>
