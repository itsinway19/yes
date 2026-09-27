<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { otherLocale, supportedLocale } from '@/localization/messages';

const props = defineProps<{ routeName: string; disabled?: boolean }>();
const page = usePage();
const { t } = useI18n({ useScope: 'global' });
const locale = computed(() => supportedLocale(page.props.locale));
const targetLocale = computed(() => otherLocale(locale.value));
const switching = ref(false);

function choose() {
    if (props.disabled || switching.value) return;
    const destination = route(props.routeName);
    switching.value = true;
    router.post(destination, { locale: targetLocale.value }, {
        preserveState: false,
        preserveScroll: true,
        onFinish: () => { switching.value = false; },
    });
}
</script>

<template>
    <div class="mb-5 flex justify-end text-sm">
        <button type="button" :disabled="disabled || switching"
            :aria-label="t(targetLocale === 'en' ? 'language.switchToEnglish' : 'language.switchToArabic')"
            class="rounded px-2 py-1 text-slate-700 underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:no-underline"
            @click="choose"><span :lang="targetLocale">{{ t(targetLocale === 'en' ? 'language.english' : 'language.arabic') }}</span></button>
    </div>
</template>
