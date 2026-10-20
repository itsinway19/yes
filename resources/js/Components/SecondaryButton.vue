<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit' | 'reset';
    }>(),
    {
        type: 'button',
    },
);
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-gray-300 ba-secondary-button px-4 py-2 text-xs font-semibold uppercase tracking-widest  shadow-sm transition duration-150 ease-in-out  focus:outline-none focus:ring-2 focus:ring-[var(--ba-primary-ink)] focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
