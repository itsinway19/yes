<script setup lang="ts">
import { refresh, useBusinessForm } from './commands';
import { useBusinessCopy } from '@/localization/businessCopy';
import { locale } from '@/spa/locale';
import { onBeforeUnmount, ref, watch } from 'vue';
import { remember, restore } from './drafts';
import { owned, stamp } from '@/spa/transport';
import CurrentIntentEditor from '@/Components/Business/CurrentIntentEditor.vue';
import TargetMarketsEditor from '@/Components/Business/TargetMarketsEditor.vue';
type Country = { code: string; name_ar: string; name_en: string };
const props = defineProps<{ workspace: { public_id: string; name: string; business_type_id: number | null; business_context_version: number } | null; types: { id: number; key: string; name_ar: string; name_en: string; active: boolean }[]; canManage: boolean; canRequestAuthority?: boolean; creationKey: string | null; version: number; business?: { targetMarketsDeclared: boolean; targetMarkets: Country[]; version: number; canManage: boolean; currentIntent?: { state: string; value: { direction: string; scope: 'business_wide'; description: string | null; outcome: { meaning: string; clarification: string | null; count_unit: string | null }; quantity: string | null; horizon: { deadline_date: string; timezone: string } | null } | null; version: number } }; timezoneOptions?: string[]; countryOptions?: Country[] }>();
const { t, localized } = useBusinessCopy(locale);
const emit = defineEmits<{ destination: [destination: string] }>();
type Draft = { form: { name: string; business_type_id: number; version: number }; baselineName: string; baselineType: number; pending: boolean };
const retained = props.canManage && props.workspace ? restore<Draft>(props.workspace.public_id, 'identity') : undefined;
const saved = retained && (retained.form.name !== retained.baselineName || retained.form.business_type_id !== retained.baselineType || retained.pending) ? retained : undefined;
const form = useBusinessForm(saved?.form ?? { name: props.workspace?.name ?? '', business_type_id: props.workspace?.business_type_id ?? 0, version: props.version }, 'context');
if (saved?.pending) form.errors = { value: t('intentTransportError') };
const authority = useBusinessForm({ decision: 'authorize', reason: 'complete_business_context' }, 'authority');
type Editor = { hasUnsaved: () => boolean; pending: () => boolean };
const markets = ref<Editor>(), intent = ref<Editor>();
let baselineName = saved?.baselineName ?? form.name;
let baselineType = saved?.baselineType ?? form.business_type_id;
const savedContextVersion = ref<number>();
const reviewedVersion = ref<number>();
function reviewContext() { if (props.workspace) void refresh(props.workspace.public_id, { onSuccess: () => { reviewedVersion.value = props.version; } }); }
function adoptContext() {
    if (reviewedVersion.value !== props.version || !props.workspace) return;
    form.version = props.version; baselineName = props.workspace.name; baselineType = props.workspace.business_type_id ?? 0;
    reviewedVersion.value = undefined; form.errors = {};
}
watch(() => props.version, version => {
    if (form.name !== baselineName || form.business_type_id !== baselineType) return;
    form.name = props.workspace?.name ?? '';
    form.business_type_id = props.workspace?.business_type_id ?? 0;
    baselineName = form.name;
    baselineType = form.business_type_id;
    form.version = version;
});
function marketsSaved(version: number) {
    if (props.workspace?.name === baselineName && (props.workspace?.business_type_id ?? 0) === baselineType) form.version = version;
}
async function save() {
 if (!props.workspace || !props.canManage || form.processing) return;
 const captured = stamp(); let destination: string | undefined;
 await form.submit(props.workspace.public_id, {
    onSuccess: reply => {
        form.version = props.version;
        baselineName = props.workspace?.name ?? form.name;
        baselineType = props.workspace?.business_type_id ?? form.business_type_id;
        savedContextVersion.value = props.version;
        destination = reply?.redirect;
    },
 });
 if (owned(captured) && form.recentlySuccessful && destination) emit('destination', destination);
}
defineExpose({ hasUnsaved: () => form.name !== baselineName || form.business_type_id !== baselineType || !!markets.value?.hasUnsaved() || !!intent.value?.hasUnsaved(), pending: () => form.processing || authority.processing || !!markets.value?.pending() || !!intent.value?.pending() });
onBeforeUnmount(() => { if (props.canManage && props.workspace) remember(props.workspace.public_id, 'identity', { form: { name: form.name, business_type_id: form.business_type_id, version: form.version }, baselineName, baselineType, pending: form.processing }); });
</script>
<template>
    <section class="context-form">
        <p class="biz-eyebrow">{{ t('context') }}</p>
        <p v-if="workspace" class="biz-lead" data-context="business-name">{{ workspace.name }}</p>
        <a v-if="workspace" class="biz-link" :href="`/workspaces/${workspace.public_id}/object-purposes`">{{ t('purposeTitle') }}</a>
        <template v-if="canManage"><h1>{{ workspace ? t('context') : t('setup') }}</h1><p class="biz-lead">{{ t('setupIntro') }}</p>
            <form class="biz-form" data-context="identity" @submit.prevent="save">
                <label>{{ t('name') }}<input v-model="form.name" required minlength="2" maxlength="120" :readonly="!!workspace && !workspace.business_type_id" autocomplete="organization" /><span v-if="form.errors.name" role="alert" class="biz-error">{{ form.errors.name }}</span></label>
                <label>{{ t('type') }}<select v-model="form.business_type_id" required><option disabled :value="0">{{ t('select') }}</option><option v-for="type in types" :key="type.id" :value="type.id" :disabled="!type.active && type.id !== workspace?.business_type_id">{{ localized(type) }}{{ !type.active ? ` — ${t('unavailable')}` : '' }}</option></select><span v-if="form.errors.business_type_id" role="alert" class="biz-error">{{ form.errors.business_type_id }}</span></label>
                <p v-if="!types.some(type => type.active || type.id === workspace?.business_type_id)" class="biz-lead">{{ t('noTypes') }}</p>
                <p v-for="(message, field) in form.errors" v-show="field !== 'name' && field !== 'business_type_id'" :key="field" role="alert" class="biz-error">{{ message }}</p>
                <aside v-if="Object.keys(form.errors).length || reviewedVersion !== undefined" class="grid gap-3" aria-live="polite">
                    <button type="button" class="biz-link" data-context="review" :disabled="form.processing" @click="reviewContext">{{ t('contextReview') }}</button>
                    <template v-if="reviewedVersion !== undefined"><p>{{ workspace?.name }} · {{ localized(types.find(type => type.id === workspace?.business_type_id)) }} · {{ reviewedVersion }}</p><button type="button" class="biz-link" data-context="adopt" :disabled="form.processing || reviewedVersion !== version" @click="adoptContext">{{ t('contextAdopt') }}</button></template>
                </aside>
                <p v-if="form.recentlySuccessful" role="status" class="biz-success">{{ t('saved') }}</p><div><button type="submit" data-context="save" class="biz-button" :disabled="form.processing || !form.business_type_id">{{ form.processing ? t('saving') : t('save') }}</button></div>
            </form>
        </template>
        <template v-else><h1>{{ t('waiting') }}</h1><p class="biz-lead">{{ t('waitingIntro') }}</p><form v-if="canRequestAuthority && workspace" class="biz-form" @submit.prevent="authority.submit(workspace.public_id)"><p class="biz-lead">{{ t('authorityIntro') }}</p><p v-for="(message, field) in authority.errors" :key="field" role="alert" class="biz-error">{{ message }}</p><div><button class="biz-button" :disabled="authority.processing">{{ authority.processing ? t('saving') : t('authorizeContext') }}</button></div></form><a href="/workspaces" class="biz-link inline-block mt-8">{{ t('businesses') }}</a></template>
        <CurrentIntentEditor v-if="workspace && business?.currentIntent && timezoneOptions" ref="intent" :workspace-id="workspace.public_id" :current-intent="business.currentIntent" :can-manage="business.canManage" :timezone-options="timezoneOptions" />
        <TargetMarketsEditor v-if="workspace && business && countryOptions" ref="markets" :workspace-id="workspace.public_id" :business="business" :country-options="countryOptions" :saved-context-version="savedContextVersion" @saved="marketsSaved" />
    </section>
</template>
