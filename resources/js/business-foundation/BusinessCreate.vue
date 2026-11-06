<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRouter, onBeforeRouteLeave } from 'vue-router';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { businessSurface, create, writeFact, type Catalog } from './api';
import { creation } from './drafts';
const props = defineProps<{ catalog: Catalog }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const draft = reactive(creation), busy = ref(false), message = ref<''|'invalid'|'conflict'|'denied'|'unknown'|'refreshed'|'unavailable'>(''), blocked = ref(false), search = ref('');
const marketSearch = ref<HTMLInputElement>();
function clearMarketSearch() { search.value = ''; marketSearch.value?.focus(); }
const router = useRouter();
const notice = computed(() => ({invalid:t('راجع المعلومات المحددة ثم حاول الحفظ مجددًا.','Review the selected details, then save again.'),conflict:t('تغيرت معلومات النشاط. حدّثها وراجع الأسواق قبل إعادة الحفظ.','Business details changed. Refresh and review the markets before saving again.'),denied:t('لم يعد الحفظ متاحًا بصلاحيتك الحالية. راجع النشاط أو أكّد تسجيل الدخول.','Saving is no longer available with your current permission. Review the business or confirm sign-in.'),unknown:t('لم نتأكد من نتيجة الحفظ. الطلب الأصلي محفوظ؛ تحقّق من نتيجته قبل تغيير المعلومات.','The save outcome is unknown. The original request is retained; check its result before changing the details.'),refreshed:t('تم تحديث النشاط. راجع الأسواق ثم احفظ اختيارك.','Business refreshed. Review the markets and save your selection.'),unavailable:t('تعذر تحديث النشاط. احتفظ بالمعلومات وراجع صلاحية الوصول.','The business could not be refreshed. Keep your details and review access.'),'':''})[message.value]);
const countries = computed(() => props.catalog.countries.filter(country => [country.name_ar, country.name_en, country.code].some(text => text.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()))));
let disposed = false;
const beforeUnload = (event: BeforeUnloadEvent) => { if (draft.pending || draft.marketPending) { event.preventDefault(); event.returnValue = ''; } };
onMounted(() => { if (typeof window !== 'undefined') window.addEventListener?.('beforeunload', beforeUnload); });
onBeforeUnmount(() => { disposed = true; if (typeof window !== 'undefined') window.removeEventListener?.('beforeunload', beforeUnload); if (draft.receipt && !draft.marketPending) Object.assign(draft, { name: '', type: null, codes: [], pending: undefined, receipt: undefined, marketPending: undefined, marketsSaved: false }); });
onBeforeRouteLeave(() => (!draft.pending && !draft.marketPending) || confirm(t('نتيجة الحفظ غير مؤكدة. هل تريد المغادرة مع الاحتفاظ بالطلب في هذا المستند؟', 'The save outcome is unknown. Leave while retaining the request in this document?')));
const destination = () => draft.receipt ? `/businesses/${draft.receipt.business_public_id}?access=${draft.receipt.grant_refs['business.context.read']}` : '/businesses';
async function save() {
    if (busy.value || blocked.value) return;
    const captured = stamp(); let reconciling = Boolean(draft.pending); busy.value = true; message.value = '';
    try {
        if (!draft.receipt) {
            draft.pending ??= { command_id: crypto.randomUUID(), name: draft.name, business_type_id: draft.type };
            const result = await create(JSON.parse(JSON.stringify(draft.pending)));
            if (disposed || !owned(captured)) return;
            draft.receipt = result; draft.pending = undefined;
        }
        if (!draft.marketsSaved && (draft.codes.length || draft.marketPending)) {
            const receipt = draft.receipt;
            reconciling = Boolean(draft.marketPending);
            draft.marketPending ??= { kind: 'markets', command_id: crypto.randomUUID(), codes: [...draft.codes], expected_context: receipt.versions.context,
                authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(receipt.grant_refs['business.markets.declare']) }] } };
            await writeFact(receipt.business_public_id, JSON.parse(JSON.stringify(draft.marketPending)));
            if (disposed || !owned(captured)) return;
            draft.marketPending = undefined; draft.marketsSaved = true;
        }
        if (!disposed && owned(captured)) await router.push(destination());
    } catch (error) {
        if (disposed || !owned(captured)) return;
        const status = error instanceof TransportError ? error.status : 0;
        if ([403, 409, 422].includes(status) && !reconciling) {
            if (!draft.receipt) draft.pending = undefined;
            else draft.marketPending = undefined;
            blocked.value = status === 403 || status === 409;
            message.value = status === 422 ? 'invalid' : status === 409 ? 'conflict' : 'denied';
        } else message.value = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
async function refresh() {
    if (!draft.receipt || busy.value || draft.marketPending) return;
    const captured = stamp(); busy.value = true;
    try {
        const surface = await businessSurface(draft.receipt.business_public_id, Number(draft.receipt.grant_refs['business.context.read']));
        if (disposed || !owned(captured)) return;
        draft.receipt.versions.context = surface.business.versions.context;
        blocked.value = false; message.value = 'refreshed';
    } catch { if (owned(captured)) message.value = 'unavailable'; }
    finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <header class="bf-heading"><div><p class="bf-eyebrow">{{ t('نشاط جديد', 'New business') }}</p><h1>{{ t('عرّفنا بنشاطك', 'Tell us about your business') }}</h1><p>{{ t('اسم النشاط ومجاله والأسواق التي يخدمها. لا تحتاج إلى ربط حساب إعلاني أو تسجيل جهة.', 'Its name, field and the markets it serves. No advertising connection or organization registration is needed.') }}</p></div><RouterLink class="bf-link" to="/businesses">{{ t('العودة إلى أنشطتي', 'Back to my businesses') }}</RouterLink></header>
    <form class="bf-form business-setup" @submit.prevent="save">
        <p v-if="draft.receipt" role="status" data-business-created>{{ t('أُنشئ النشاط باسم', 'Business created as') }} <strong>{{ draft.receipt.name }}</strong>. {{ t('يمكنك استكمال أسواقه الآن؛ لن يُنشأ نشاط آخر عند إعادة الحفظ.', 'You can finish its markets now; saving again will not create another business.') }}</p>
        <fieldset :disabled="busy || !!draft.pending || !!draft.receipt">
            <label for="bf-create-name">{{ t('اسم النشاط', 'Business name') }}</label><input id="bf-create-name" v-model="draft.name" required minlength="2" maxlength="120" autocomplete="organization" />
            <label for="bf-create-type">{{ t('المجال أو القطاع — اختياري', 'Business field or sector — optional') }}</label><select id="bf-create-type" v-model="draft.type"><option :value="null">{{ t('سأحدده لاحقًا', 'Choose later') }}</option><option v-for="type in props.catalog.business_types.filter(x => x.active)" :key="type.id" :value="type.id">{{ locale === 'ar' ? type.name_ar : type.name_en }}</option></select>
        </fieldset>
        <fieldset :disabled="busy || !!draft.pending || !!draft.marketPending || blocked || draft.marketsSaved">
            <legend>{{ t('أين تخدم عملاءك؟', 'Where do you serve customers?') }}</legend><p>{{ t('اختر سوقًا أو أكثر، أو اتركه لوقت لاحق. هذه أسواق مستهدفة وليست بلد تأسيس النشاط.', 'Choose one or more markets, or leave this for later. These are target markets, not the country of incorporation.') }}</p>
            <label for="bf-create-market-search">{{ t('ابحث عن دولة', 'Search countries') }}</label><input id="bf-create-market-search" ref="marketSearch" v-model="search" type="search" />
            <button v-if="search" type="button" class="bf-link" data-create-market-search-clear @click="clearMarketSearch">{{ t('مسح البحث', 'Clear search') }}</button>
            <p v-if="search.trim() && !countries.length" role="status" data-create-market-search-empty>{{ t('لا توجد دول مطابقة للبحث.', 'No countries match your search.') }}</p>
            <div class="setup-countries"><label v-for="country in countries" :key="country.code"><input v-model="draft.codes" type="checkbox" :value="country.code" />{{ locale === 'ar' ? country.name_ar : country.name_en }}</label></div>
        </fieldset>
        <p v-if="message" role="status" :data-business-unknown="!!draft.pending || !!draft.marketPending ? '' : undefined">{{ notice }}</p>
        <div class="setup-actions"><button type="submit" class="bf-primary" :disabled="busy || blocked">{{ busy ? t('جارٍ الحفظ…', 'Saving…') : draft.pending || draft.marketPending ? t('التحقق من النتيجة', 'Check the outcome') : draft.receipt ? t('حفظ الأسواق وفتح النشاط', 'Save markets and open business') : t('إنشاء النشاط والمتابعة', 'Create business and continue') }}</button><button v-if="blocked && draft.receipt" type="button" class="bf-link" :disabled="busy" @click="refresh">{{ t('تحديث معلومات النشاط', 'Refresh business details') }}</button><RouterLink v-if="draft.receipt && !draft.marketPending" class="bf-link" :to="destination()">{{ t('فتح النشاط واستكماله لاحقًا', 'Open business and finish later') }}</RouterLink></div>
    </form>
</template>
<style scoped>
.business-setup{max-width:720px}.business-setup fieldset{border:0;margin:0 0 24px;padding:0}.business-setup legend{font-size:16px;font-weight:650;padding:0}.business-setup p{font-size:13px;line-height:1.9;color:var(--ad-muted)}.setup-countries{max-height:220px;overflow:auto;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2px 18px;border:1px solid var(--ad-line);border-radius:6px;padding:8px 12px}.setup-countries label{display:flex;align-items:center;gap:9px;min-height:44px;margin:0!important;cursor:pointer}.setup-countries input{flex:none;margin:0!important;width:18px!important}.setup-actions{display:flex;align-items:center;flex-wrap:wrap;gap:14px}.setup-actions :is(button,a){min-height:44px}@media(max-width:480px){.setup-countries{grid-template-columns:1fr}}
</style>
