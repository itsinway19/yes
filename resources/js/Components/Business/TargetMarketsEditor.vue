<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { remember, restore } from '@/business-context/drafts';
import { refresh, useBusinessForm } from '@/business-context/commands';
import { useBusinessCopy } from '@/localization/businessCopy';
import { locale } from '@/spa/locale';
type Country = { code: string; name_ar: string; name_en: string };
const props = defineProps<{ workspaceId: string; business: { targetMarketsDeclared: boolean; targetMarkets: Country[]; version: number; canManage: boolean }; countryOptions: Country[]; savedContextVersion?: number }>();
const emit = defineEmits<{ saved: [version: number] }>();
const { t, localized } = useBusinessCopy(locale);
const query = ref('');
const codeKey = (codes: string[]) => [...codes].sort().join(',');
type Draft = { country_codes: string[]; version: number; baselineCodes: string; conflict: string; pending: boolean };
const retained = props.business.canManage ? restore<Draft>(props.workspaceId, 'markets') : undefined;
const saved = retained && (codeKey(retained.country_codes) !== retained.baselineCodes || retained.conflict || retained.pending) ? retained : undefined;
const form = useBusinessForm(saved ? { country_codes: saved.country_codes, version: saved.version } : { country_codes: props.business.targetMarkets.map(country => country.code), version: props.business.version }, 'markets');
if (saved?.pending) form.errors = { value: t('intentTransportError') };
let baselineCodes = saved?.baselineCodes ?? codeKey(form.country_codes);
let saving = false;
const conflict = ref(saved?.conflict ?? '');
watch(() => form.errors.version, message => { if (message) conflict.value = message; });
watch(() => props.business.version, version => {
    // A refreshed canonical revision does not authorize rebasing an older draft.
    if (saving || codeKey(form.country_codes) !== baselineCodes) return;
    form.country_codes = props.business.targetMarkets.map(country => country.code);
    baselineCodes = codeKey(form.country_codes);
    form.version = version;
});
watch(() => props.savedContextVersion, version => {
    if (version !== undefined && codeKey(props.business.targetMarkets.map(country => country.code)) === baselineCodes) form.version = version;
});
function review() {
    void refresh(props.workspaceId, { onSuccess: () => {
        form.version = props.business.version;
        baselineCodes = codeKey(props.business.targetMarkets.map(country => country.code));
    } });
}
const selected = computed(() => form.country_codes.map(code => props.countryOptions.find(country => country.code === code)).filter((country): country is Country => !!country));
const filtered = computed(() => {
    const search = query.value.trim().toLocaleLowerCase();
    return props.countryOptions.filter(country => [country.code, country.name_ar, country.name_en].some(value => value.toLocaleLowerCase().includes(search)));
});
function remove(code: string) { form.country_codes = form.country_codes.filter(value => value !== code); }
function save() {
    if (!props.business.canManage || form.processing) return;
    const submittedCodes = codeKey(form.country_codes);
    const submittedVersion = form.version;
    saving = true;
    void form.submit(props.workspaceId, { onSuccess: () => {
        conflict.value = '';
        if (codeKey(form.country_codes) === submittedCodes) {
            // The redirect can include a later commit: use its values and revision together.
            form.country_codes = props.business.targetMarkets.map(country => country.code);
            baselineCodes = codeKey(form.country_codes);
            form.version = props.business.version;
        } else {
            // A new draft created during the request has not reviewed that newer state.
            form.version = submittedVersion;
        }
        emit('saved', props.business.version);
    }, onFinish: () => { saving = false; } });
}
defineExpose({ hasUnsaved: () => codeKey(form.country_codes) !== baselineCodes, pending: () => form.processing });
onBeforeUnmount(() => { if (props.business.canManage) remember(props.workspaceId, 'markets', { country_codes: form.country_codes, version: form.version, baselineCodes, conflict: conflict.value, pending: form.processing }); });
</script>
<template>
    <section class="target-markets biz-divider" aria-labelledby="target-markets-heading">
        <h2 id="target-markets-heading">{{ t('marketsQuestion') }}</h2>
        <p class="biz-lead">{{ t('marketsIntro') }}</p>
        <p v-if="!business.targetMarketsDeclared" class="markets-unknown">{{ t('marketsUnknown') }}</p>
        <template v-if="business.canManage">
            <form data-target-markets class="markets-form" @submit.prevent="save">
                <label for="market-search">{{ t('marketsSearch') }}</label>
                <input id="market-search" v-model="query" type="search" :placeholder="t('marketsSearchHint')" autocomplete="off" />
                <ul v-if="selected.length" class="markets-selected" :aria-label="t('marketsSelected')">
                    <li v-for="country in selected" :key="country.code"><span>{{ localized(country) }}</span>
                        <button type="button" :disabled="form.processing" :aria-label="`${t('marketsRemove')} ${localized(country)}`" @click="remove(country.code)">×</button>
                    </li>
                </ul>
                <fieldset :disabled="form.processing" class="markets-options">
                    <legend class="sr-only">{{ t('marketsOptions') }}</legend>
                    <label v-for="country in filtered" :key="country.code"><input v-model="form.country_codes" type="checkbox" :value="country.code" />
                        <span>{{ localized(country) }} <bdi dir="ltr">{{ country.code }}</bdi></span>
                    </label>
                    <p v-if="!filtered.length" role="status">{{ t('marketsNoResults') }}</p>
                </fieldset>
                <p v-if="form.errors.country_codes" role="alert" class="biz-error">{{ form.errors.country_codes }}</p>
                <p v-if="form.errors.value" role="alert" class="biz-error">{{ form.errors.value }}</p>
                <div v-if="conflict" role="alert" class="biz-error"><p>{{ conflict }}</p>
                    <button type="button" class="biz-link" @click="review">{{ t('marketsReview') }}</button>
                    <p>{{ t('marketsSummary') }}: <template v-if="business.targetMarketsDeclared"><span v-for="(country, index) in business.targetMarkets" :key="country.code">{{ index ? '، ' : '' }}{{ localized(country) }}</span></template><span v-else>{{ t('marketsUnknown') }}</span></p>
                </div>
                <p v-if="form.recentlySuccessful" role="status" class="biz-success">{{ t('saved') }}</p>
                <div class="markets-actions"><button type="submit" class="biz-button" :disabled="form.processing">{{ form.processing ? t('saving') : t('marketsSave') }}</button>
                    <a :href="`/workspaces/${workspaceId}`" class="biz-link">{{ t('marketsLater') }}</a>
                </div>
            </form>
        </template>
        <ul v-else-if="business.targetMarketsDeclared" class="markets-summary"><li v-for="country in business.targetMarkets" :key="country.code">{{ localized(country) }} <bdi dir="ltr">{{ country.code }}</bdi></li></ul>
    </section>
</template>
<style scoped>
.target-markets{max-width:640px;min-width:0}.markets-form{display:grid;gap:14px;margin-top:20px;min-width:0}.markets-form>label{font-weight:600;font-size:14px}.markets-form>input{width:100%;min-width:0;border:1px solid #9caeaa;background:white;border-radius:5px;padding:12px}.markets-selected{display:flex;flex-wrap:wrap;gap:8px;list-style:none;padding:0}.markets-selected li{display:flex;align-items:center;gap:8px;max-width:100%;min-width:0;background:#edf4f0;border:1px solid var(--biz-line);border-radius:6px;padding-inline-start:12px}.markets-selected li>span{overflow-wrap:anywhere}.markets-selected button{flex-shrink:0;min-width:44px;min-height:44px;font-size:22px}.markets-options{max-height:260px;overflow:auto;border:1px solid var(--biz-line);border-radius:5px;background:white;padding:8px;min-width:0}.markets-options label{display:flex;align-items:center;gap:12px;padding:8px;min-height:44px;font-size:14px;cursor:pointer}.markets-options input{flex-shrink:0;width:18px;height:18px}.markets-options span{overflow-wrap:anywhere;min-width:0}.target-markets bdi{font-size:12px;color:var(--biz-muted);margin-inline-start:6px}.markets-actions{display:flex;flex-wrap:wrap;align-items:center;gap:20px}.markets-summary{margin-top:16px;display:flex;flex-wrap:wrap;gap:8px 16px;list-style:none;padding:0}.markets-unknown{color:var(--biz-muted);font-size:14px;margin-top:12px}.markets-form input:focus-visible{outline:2px solid var(--biz-accent);outline-offset:3px}
</style>
