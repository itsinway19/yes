<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        show?: boolean;
        maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
        closeable?: boolean;
        labelledBy?: string;
        describedBy?: string;
    }>(),
    {
        show: false,
        maxWidth: '2xl',
        closeable: true,
    },
);

const emit = defineEmits(['close']);
const dialog = ref();
const showSlot = ref(props.show);
let previousFocus: HTMLElement | null = null;
let closing: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.show,
    async () => {
        clearTimeout(closing);
        if (props.show) {
            previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            document.body.style.overflow = 'hidden';
            showSlot.value = true;

            await nextTick();
            if (props.show) dialog.value?.showModal();
        } else {
            document.body.style.overflow = '';

            closing = setTimeout(() => {
                dialog.value?.close();
                previousFocus?.focus();
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show) {
        e.preventDefault();
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    clearTimeout(closing);
    dialog.value?.close();
    document.removeEventListener('keydown', closeOnEscape);

    document.body.style.overflow = '';
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
        :aria-labelledby="labelledBy"
        :aria-describedby="describedBy"
        @cancel.prevent="close"
    >
        <div
            class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="ba-modal-backdrop fixed inset-0 z-0 transform transition-all"
                    @click="close"
                >
                    <div
                        class="absolute inset-0 bg-gray-500 opacity-75"
                    />
                </div>
            </Transition>

            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div
                    v-show="show"
                    class="ba-modal-panel relative z-10 mb-6 transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:mx-auto sm:w-full"
                    :class="maxWidthClass"
                >
                    <slot v-if="showSlot" />
                </div>
            </Transition>
        </div>
    </dialog>
</template>

<style scoped>
@media (prefers-reduced-motion: reduce) { .ba-modal-backdrop, .ba-modal-panel { transition-duration: 0ms !important; animation: none !important; } }
</style>
