<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message" role="alert">
        <p class="text-sm text-red-600">
            {{ message }}
        </p>
    </div>
</template>
