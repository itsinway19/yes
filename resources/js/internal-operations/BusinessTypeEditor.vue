<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, shallowRef, watch } from 'vue';
import ProviderBrand from '@/Components/ProviderBrand.vue';
import type { BusinessCommand } from './businessState';
import { locale as applicationLocale } from '../spa/locale';
import { useBusinessCopy } from '@/localization/businessCopy';
export type GuidanceRow = { provider: string; priority: number; rationale_ar: string; rationale_en: string; active: boolean };
export type PriorRow = { id: number; version: number; provider: string; product: string; basis: string; documented_basis: string; applicability: string; limits: string; rationale_ar: string; rationale_en: string; internal_rationale: string; approved: boolean; active: boolean };
export type BusinessType = { id: number; version: number; key: string; name_ar: string; name_en: string; active: boolean; source_guidance: GuidanceRow[]; promotion_priors: PriorRow[] };
const props = defineProps<{ type: BusinessType; providers: { key: string; label: string }[]; products: { key: string; provider: string; label_ar: string; label_en: string }[]; canManage: boolean }>();
const { locale, localized } = useBusinessCopy(applicationLocale);
const emit = defineEmits<{ save: [command: BusinessCommand, settle?: (type: BusinessType) => void] }>();
const copy = (arabic: string, english: string) => locale.value === 'ar' ? arabic : english;
const typeForm = reactive({ processing: false, recentlySuccessful: false, errors: {} as Record<string, string>,  key: props.type.key, name_ar: props.type.name_ar, name_en: props.type.name_en, active: props.type.active, version: props.type.version });
const guidance = reactive({ processing: false, recentlySuccessful: false, errors: {} as Record<string, string>,  provider: '', priority: 1, rationale_ar: '', rationale_en: '', active: true });
const initialPrior = { id: null as number | null, version: null as number | null, provider: '', product: '', basis: 'ADMIN_APPROVED_HYPOTHESIS', documented_basis: '', applicability: '', limits: '', rationale_ar: '', rationale_en: '', internal_rationale: '', approved: false, active: false };
const prior = reactive({ processing: false, recentlySuccessful: false, errors: {} as Record<string, string>,  ...initialPrior });
function typeFields(type: Pick<BusinessType, 'key' | 'name_ar' | 'name_en' | 'active' | 'version'>) {
    return { key: type.key, name_ar: type.name_ar, name_en: type.name_en, active: type.active, version: type.version };
}
function priorFields(row: typeof initialPrior | PriorRow) {
    const { id, version, provider, product, basis, documented_basis, applicability, limits, rationale_ar, rationale_en, internal_rationale, approved, active } = row;
    return { id, version, provider, product, basis, documented_basis, applicability, limits, rationale_ar, rationale_en, internal_rationale, approved, active };
}
const typeBaseline = shallowRef(typeFields(props.type)), priorBaseline = shallowRef(priorFields(initialPrior));
let typeSubmission: ReturnType<typeof typeFields> | undefined, priorSubmission: ReturnType<typeof priorFields> | undefined;
let displayedType = props.type.id, active = true;
const typeDirty = computed(() => JSON.stringify(typeFields(typeForm)) !== JSON.stringify(typeBaseline.value));
const priorDirty = computed(() => JSON.stringify(priorFields(prior)) !== JSON.stringify(priorBaseline.value));
const typeChanged = computed(() => JSON.stringify(typeFields(props.type)) !== JSON.stringify(typeBaseline.value));
const currentPrior = computed(() => props.type.promotion_priors.find(row => row.id === prior.id));
const priorChanged = computed(() => prior.id !== null && (!currentPrior.value || JSON.stringify(priorFields(currentPrior.value)) !== JSON.stringify(priorBaseline.value)));
function reloadType() {
    typeSubmission = undefined; typeBaseline.value = typeFields(props.type);
    Object.assign(typeForm, typeBaseline.value, { errors: {}, recentlySuccessful: false });
}
function loadPrior(row: typeof initialPrior | PriorRow) {
    priorSubmission = undefined; priorBaseline.value = priorFields(row);
    Object.assign(prior, priorBaseline.value, { errors: {}, recentlySuccessful: false });
}
function reloadPrior() { loadPrior(currentPrior.value ?? initialPrior); }
onBeforeUnmount(() => { active = false; });
const relevantProducts = computed(() => props.products.filter(product => product.provider === prior.provider));
function editGuidance(row: GuidanceRow) { Object.assign(guidance, { provider: row.provider, priority: row.priority, rationale_ar: row.rationale_ar, rationale_en: row.rationale_en, active: row.active }); guidance.errors = {}; }
function editPrior(row: PriorRow) { loadPrior(row); }
function clearPrior() { loadPrior(initialPrior); }
function saveType() {
    const submitted = typeFields(typeForm), typeId = props.type.id; typeSubmission = submitted;
    emit('save', { kind: 'update', type: typeId, body: { ...submitted } }, current => {
        if (!active || props.type.id !== typeId || typeSubmission !== submitted) return;
        typeSubmission = undefined;
        const draft = typeFields(typeForm), server = typeFields(current);
        const edited = (['key', 'name_ar', 'name_en', 'active'] as const).filter(field => draft[field] !== submitted[field]);
        if (edited.some(field => server[field] !== submitted[field] && server[field] !== draft[field])) return;
        typeBaseline.value = server;
        Object.assign(typeForm, server, Object.fromEntries(edited.map(field => [field, draft[field]])));
    });
}
function saveGuidance() { emit('save', { kind: 'guidance', type: props.type.id, body: { provider: guidance.provider as 'meta' | 'google' | 'snapchat' | 'tiktok' | 'x', priority: Number(guidance.priority), rationale_ar: guidance.rationale_ar, rationale_en: guidance.rationale_en, active: guidance.active } }); }
function savePrior() {
    const submitted = priorFields(prior), typeId = props.type.id; priorSubmission = submitted;
    const body = { provider: prior.provider as 'meta' | 'google' | 'snapchat' | 'tiktok' | 'x', product: prior.product as 'google.search', basis: prior.basis as 'ADMIN_APPROVED_HYPOTHESIS' | 'EVIDENCE_SUPPORTED_PRIOR', documented_basis: prior.documented_basis, applicability: prior.applicability, limits: prior.limits, rationale_ar: prior.rationale_ar, rationale_en: prior.rationale_en, internal_rationale: prior.internal_rationale, approved: prior.approved, active: prior.active };
    emit('save', { kind: 'prior', type: typeId, body: prior.id === null ? body : { ...body, id: prior.id, version: prior.version! } }, current => {
        if (!active || props.type.id !== typeId || priorSubmission !== submitted) return;
        priorSubmission = undefined;
        const row = current.promotion_priors.find(row => submitted.id === null ? row.provider === submitted.provider && row.product === submitted.product : row.id === submitted.id);
        if (!row) return;
        const draft = priorFields(prior), server = priorFields(row);
        const edited = (Object.keys(initialPrior) as (keyof typeof initialPrior)[]).filter(field => field !== 'id' && field !== 'version' && draft[field] !== submitted[field]);
        if (edited.some(field => server[field] !== submitted[field] && server[field] !== draft[field])) return;
        priorBaseline.value = server;
        Object.assign(prior, server, Object.fromEntries(edited.map(field => [field, draft[field]])));
    });
}
watch(() => props.type, type => {
    if (type.id !== displayedType) {
        displayedType = type.id; reloadType(); clearPrior();
        Object.assign(guidance, { provider: '', priority: 1, rationale_ar: '', rationale_en: '', active: true, errors: {}, recentlySuccessful: false });
        return;
    }
    if (!typeDirty.value && !typeSubmission) reloadType();
    if (currentPrior.value && !priorDirty.value && !priorSubmission) loadPrior(currentPrior.value);
}, { flush: 'sync' });
</script>
<template>
    <article class="catalog-type" :data-business-type="type.key">
        <p v-if="typeChanged" role="status">{{ copy('وصلت حالة خادمية مختلفة؛ تبقى المسودة ونسختها الأصلية حتى التحميل الصريح.', 'Server state changed; the draft retains its original version until explicitly reloaded.') }}</p>
        <button v-if="canManage && (typeChanged || typeDirty)" type="button" @click="reloadType">{{ copy('التخلص من مسودة التعريف وتحميل الحالة الحالية', 'Discard definition draft and load current state') }}</button>
        <p v-if="priorChanged" role="status">{{ copy('تغير الاعتبار الخادمي؛ لم تُستبدل مسودتك تلقائيًا.', 'The server prior changed; your draft was not replaced automatically.') }}</p>
        <button v-if="canManage && (priorChanged || priorDirty)" type="button" @click="reloadPrior">{{ copy('التخلص من مسودة الاعتبار وتحميل الحالة الحالية', 'Discard prior draft and load current state') }}</button>
        <header><div><h2>{{ localized(type) }}</h2><p dir="ltr">{{ type.key }}</p></div><span class="catalog-state">{{ type.active ? copy('متاح للاختيار', 'Available for selection') : copy('غير متاح للاختيار الجديد', 'Unavailable for new selection') }}</span></header>
        <details><summary>{{ copy('تعريف النوع', 'Type definition') }}</summary><p v-if="!canManage" class="catalog-note">{{ type.name_ar }} / {{ type.name_en }}</p><form v-if="canManage" class="catalog-form" @submit.prevent="saveType"><label>{{ copy('المفتاح', 'Key') }}<input v-model="typeForm.key" dir="ltr" required :disabled="!canManage" /></label><label>{{ copy('الاسم بالعربية', 'Arabic name') }}<input v-model="typeForm.name_ar" dir="rtl" required :disabled="!canManage" /></label><label>{{ copy('الاسم بالإنجليزية', 'English name') }}<input v-model="typeForm.name_en" dir="ltr" required :disabled="!canManage" /></label><label class="catalog-check"><input v-model="typeForm.active" type="checkbox" :disabled="!canManage" />{{ copy('متاح للاختيار الجديد', 'Available for new selection') }}</label><p v-for="(message, field) in typeForm.errors" :key="field" role="alert" class="catalog-error">{{ message }}</p><p v-if="typeForm.recentlySuccessful" role="status">{{ copy('تم الحفظ.', 'Saved.') }}</p><button v-if="canManage" :disabled="typeForm.processing">{{ copy('حفظ التعريف', 'Save definition') }}</button></form></details>
        <details><summary>{{ copy('توجيه المصادر', 'Source guidance') }} · {{ type.source_guidance.length }}</summary><ul class="catalog-records"><li v-for="row in type.source_guidance" :key="row.provider"><div><strong class="catalog-provider"><ProviderBrand :provider="row.provider" :size="32" />{{ providers.find(provider => provider.key === row.provider)?.label ?? row.provider }}</strong><span> · {{ row.priority }} · {{ row.active ? copy('نشط', 'Active') : copy('غير نشط', 'Inactive') }}</span><p>{{ locale === 'ar' ? row.rationale_ar : row.rationale_en }}</p></div><button v-if="canManage" type="button" @click="editGuidance(row)">{{ copy('تعديل', 'Edit') }}</button></li></ul><form v-if="canManage" class="catalog-form" @submit.prevent="saveGuidance"><label>{{ copy('المزوّد', 'Provider') }}<select v-model="guidance.provider" required><option disabled value="">{{ copy('اختر المزوّد', 'Choose provider') }}</option><option v-for="provider in providers" :key="provider.key" :value="provider.key">{{ provider.label }}</option></select></label><label>{{ copy('ترتيب العرض', 'Display priority') }}<input v-model="guidance.priority" type="number" min="1" required /></label><label>{{ copy('المبرر بالعربية', 'Arabic rationale') }}<textarea v-model="guidance.rationale_ar" dir="rtl" required /></label><label>{{ copy('المبرر بالإنجليزية', 'English rationale') }}<textarea v-model="guidance.rationale_en" dir="ltr" required /></label><label class="catalog-check"><input v-model="guidance.active" type="checkbox" />{{ copy('نشط', 'Active') }}</label><p v-for="(message, field) in guidance.errors" :key="field" class="catalog-error" role="alert">{{ message }}</p><p v-if="guidance.recentlySuccessful" role="status">{{ copy('تم الحفظ.', 'Saved.') }}</p><button :disabled="guidance.processing">{{ copy('حفظ توجيه المصدر', 'Save source guidance') }}</button></form></details>
        <details><summary>{{ copy('الاعتبارات الأولية للترويج', 'Promotion priors') }} · {{ type.promotion_priors.length }}</summary><p class="catalog-note">{{ copy('التغييرات في معنى الاعتبار أو مجال انطباقه تُبطل الموافقة السابقة وتحتاج إلى موافقة جديدة. التصحيحات الشكلية وحدها لا تُبطل الموافقة. التفعيل مستقل عن الموافقة.', 'Changes to a prior’s meaning or applicability invalidate prior approval and need renewed approval. Cosmetic corrections alone retain approval. Activation is separate from approval.') }}</p><ul class="catalog-records"><li v-for="row in type.promotion_priors" :key="row.id"><div><strong class="catalog-provider"><ProviderBrand :provider="row.provider" :size="32" />{{ products.find(product => product.key === row.product && product.provider === row.provider) ? localized(products.find(product => product.key === row.product && product.provider === row.provider)) : row.product }}</strong><p>{{ locale === 'ar' ? row.rationale_ar : row.rationale_en }}</p><span>{{ row.approved ? copy('معتمد', 'Approved') : copy('بانتظار الموافقة', 'Awaiting approval') }} · {{ row.active ? copy('نشط', 'Active') : copy('غير نشط', 'Inactive') }}</span></div><button v-if="canManage" type="button" @click="editPrior(row)">{{ copy('تعديل / مراجعة', 'Edit / review') }}</button></li></ul>
            <form v-if="canManage" class="catalog-form" @submit.prevent="savePrior"><h3>{{ prior.id ? copy('مراجعة اعتبار قائم', 'Review existing prior') : copy('اعتبار جديد', 'New prior') }}</h3><button v-if="prior.id" type="button" @click="clearPrior">{{ copy('بدء اعتبار جديد', 'Start a new prior') }}</button><label>{{ copy('المزوّد', 'Provider') }}<select v-model="prior.provider" required @change="prior.product = ''"><option disabled value="">{{ copy('اختر المزوّد', 'Choose provider') }}</option><option v-for="provider in providers" :key="provider.key" :value="provider.key">{{ provider.label }}</option></select></label><label>{{ copy('القناة / المنتج', 'Channel / product') }}<select v-model="prior.product" required><option disabled value="">{{ copy('اختر المنتج', 'Choose product') }}</option><option v-for="product in relevantProducts" :key="product.key" :value="product.key">{{ localized(product) }}</option></select></label><label>{{ copy('أساس الاعتبار', 'Prior basis') }}<select v-model="prior.basis"><option value="ADMIN_APPROVED_HYPOTHESIS">{{ copy('فرضية تعتمدها الإدارة', 'Admin-approved hypothesis') }}</option><option value="EVIDENCE_SUPPORTED_PRIOR">{{ copy('اعتبار تدعمه الأدلة', 'Evidence-supported prior') }}</option></select></label><label>{{ copy('الأساس الموثّق', 'Documented basis') }}<textarea v-model="prior.documented_basis" required /></label><label>{{ copy('مجال الانطباق', 'Applicability') }}<textarea v-model="prior.applicability" required /></label><label>{{ copy('الحدود', 'Limits') }}<textarea v-model="prior.limits" required /></label><label>{{ copy('المبرر الظاهر بالعربية', 'Public Arabic rationale') }}<textarea v-model="prior.rationale_ar" dir="rtl" required /></label><label>{{ copy('المبرر الظاهر بالإنجليزية', 'Public English rationale') }}<textarea v-model="prior.rationale_en" dir="ltr" required /></label><label>{{ copy('المبرر الداخلي', 'Internal rationale') }}<textarea v-model="prior.internal_rationale" dir="auto" required /></label><label class="catalog-check"><input v-model="prior.approved" type="checkbox" />{{ copy('موافقة على هذا الاعتبار بعد مراجعته', 'Approve this prior after review') }}</label><label class="catalog-check"><input v-model="prior.active" type="checkbox" />{{ copy('تفعيل', 'Activate') }}</label><p v-for="(message, field) in prior.errors" :key="field" class="catalog-error" role="alert">{{ message }}</p><p v-if="prior.recentlySuccessful" role="status">{{ copy('تم الحفظ؛ حالة الموافقة أعلاه هي الحالة الحالية.', 'Saved; the approval status above reflects the current state.') }}</p><button :disabled="prior.processing">{{ prior.processing ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ الاعتبار', 'Save prior') }}</button></form>
        </details>
    </article>
</template>

<style scoped>.catalog-provider{display:inline-flex;align-items:center;gap:10px}</style>
