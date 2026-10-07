<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdvertiserLayout from '@/Layouts/AdvertiserLayout.vue';
import { useBusinessCopy } from '@/localization/business';
import { ref, watch } from 'vue';
import CurrentIntentEditor from '@/Components/Business/CurrentIntentEditor.vue';
import TargetMarketsEditor from '@/Components/Business/TargetMarketsEditor.vue';
type Country = { code: string; name_ar: string; name_en: string };
const props = defineProps<{ workspace: { public_id: string; name: string; business_type_id: number | null; business_context_version: number } | null; types: { id: number; key: string; name_ar: string; name_en: string; active: boolean }[]; canManage: boolean; canRequestAuthority?: boolean; creationKey: string | null; version: number; business?: { targetMarketsDeclared: boolean; targetMarkets: Country[]; version: number; canManage: boolean; currentIntent?: { state: string; value: { direction: string; scope: 'business_wide'; description: string | null; outcome: { meaning: string; clarification: string | null; count_unit: string | null }; quantity: string | null; horizon: { deadline_date: string; timezone: string } | null } | null; version: number } }; timezoneOptions?: string[]; countryOptions?: Country[] }>();
const { t, localized } = useBusinessCopy();
const form = useForm({ name: props.workspace?.name ?? '', business_type_id: props.workspace?.business_type_id ?? '', creation_key: props.creationKey, version: props.version });
const authority = useForm({ decision: 'authorize', reason: 'complete_business_context' });
let baselineName = form.name;
let baselineType = form.business_type_id;
const savedContextVersion = ref<number>();
watch(() => props.version, version => {
    if (form.name !== baselineName || form.business_type_id !== baselineType) return;
    form.name = props.workspace?.name ?? '';
    form.business_type_id = props.workspace?.business_type_id ?? '';
    baselineName = form.name;
    baselineType = form.business_type_id;
    form.version = version;
});
function marketsSaved(version: number) {
    if (props.workspace?.name === baselineName && (props.workspace?.business_type_id ?? '') === baselineType) form.version = version;
}
function save() { form.post(props.workspace ? route('workspaces.business.update', props.workspace.public_id) : route('business.start.store'), {
    preserveScroll: true, onSuccess: () => {
        form.version = props.version;
        baselineName = props.workspace?.name ?? form.name;
        baselineType = props.workspace?.business_type_id ?? form.business_type_id;
        savedContextVersion.value = props.version;
    },
}); }
</script>
<template>
    <Head :title="t('context')" />
    <AdvertiserLayout :business-name="workspace?.name" :workspace-id="workspace?.public_id" :preserve-drafts-on-locale-change="!!workspace">
        <p class="biz-eyebrow">{{ t('context') }}</p>
        <Link v-if="workspace" class="biz-link" :href="route('workspaces.object-purposes.index', workspace.public_id)">{{ t('purposeTitle') }}</Link>
        <template v-if="canManage"><h1>{{ workspace ? t('context') : t('setup') }}</h1><p class="biz-lead">{{ t('setupIntro') }}</p>
            <form class="biz-form" @submit.prevent="save">
                <label>{{ t('name') }}<input v-model="form.name" required minlength="2" maxlength="120" :readonly="!!workspace && !workspace.business_type_id" autocomplete="organization" /><span v-if="form.errors.name" role="alert" class="biz-error">{{ form.errors.name }}</span></label>
                <label>{{ t('type') }}<select v-model="form.business_type_id" required><option disabled value="">{{ t('select') }}</option><option v-for="type in types" :key="type.id" :value="type.id" :disabled="!type.active && type.id !== workspace?.business_type_id">{{ localized(type) }}{{ !type.active ? ` — ${t('unavailable')}` : '' }}</option></select><span v-if="form.errors.business_type_id" role="alert" class="biz-error">{{ form.errors.business_type_id }}</span></label>
                <p v-if="!types.some(type => type.active || type.id === workspace?.business_type_id)" class="biz-lead">{{ t('noTypes') }}</p>
                <p v-for="(message, field) in form.errors" v-show="field !== 'name' && field !== 'business_type_id'" :key="field" role="alert" class="biz-error">{{ message }}</p>
                <p v-if="form.recentlySuccessful" role="status" class="biz-success">{{ t('saved') }}</p><div><button class="biz-button" :disabled="form.processing || !form.business_type_id">{{ form.processing ? t('saving') : t('save') }}</button></div>
            </form>
        </template>
        <template v-else><h1>{{ t('waiting') }}</h1><p class="biz-lead">{{ t('waitingIntro') }}</p><form v-if="canRequestAuthority && workspace" class="biz-form" @submit.prevent="authority.post(route('workspaces.business.authority', workspace.public_id), { preserveScroll: true })"><p class="biz-lead">{{ t('authorityIntro') }}</p><p v-for="(message, field) in authority.errors" :key="field" role="alert" class="biz-error">{{ message }}</p><div><button class="biz-button" :disabled="authority.processing">{{ authority.processing ? t('saving') : t('authorizeContext') }}</button></div></form><Link :href="route('workspaces.index')" class="biz-link inline-block mt-8">{{ t('businesses') }}</Link></template>
        <CurrentIntentEditor v-if="workspace && business?.currentIntent && timezoneOptions" :workspace-id="workspace.public_id" :current-intent="business.currentIntent" :can-manage="business.canManage" :timezone-options="timezoneOptions" />
        <TargetMarketsEditor v-if="workspace && business && countryOptions" :workspace-id="workspace.public_id" :business="business" :country-options="countryOptions" :saved-context-version="savedContextVersion" @saved="marketsSaved" />
    </AdvertiserLayout>
</template>
