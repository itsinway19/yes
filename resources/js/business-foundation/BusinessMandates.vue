<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { locale } from '../spa/locale';
import { owned, stamp, TransportError } from '../spa/transport';
import { businessAgencyMandates, withdrawBusinessMandates, type AgencyMandates, type AgencyMandatesCommand, type Surface } from './api';
import { businessMandatesDrafts, key, type BusinessMandatesDraft } from './drafts';

const props = defineProps<{ surface: Surface; active: boolean; refreshing: boolean }>();
const emit = defineEmits<{ refresh: [] }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const business = props.surface.business.business_public_id, basis = JSON.stringify(props.surface.authority_selection), admission = stamp();
const resource = `${key(business, props.surface.authority_selection)}:mandates:1`;
const draft = reactive<BusinessMandatesDraft>(businessMandatesDrafts.get(resource) ?? { agency: '', expected: [], confirmed: false, uncertain: false, blocked: false, outcome: '' });
draft.uncertain ||= !!draft.pending;
if (draft.pending && !draft.outcome) draft.outcome = 'unknown';
businessMandatesDrafts.set(resource, draft);
const projection = ref<AgencyMandates>(), loading = ref(false), busy = ref(false);
let disposed = false, generation = 0;
const currentContext = () => !disposed && owned(admission) && props.active && props.surface.business.business_public_id === business && JSON.stringify(props.surface.authority_selection) === basis;
const manageable = computed(() => props.surface.agency_task?.can_mandate === true);
const access = computed(() => Number(props.surface.authority_selection.requirements.find(row => row.requirement_kind === 'business')?.leaf_grant_id));
const setKey = (values: { grant_id: string; version: number }[]) => JSON.stringify([...values].sort((a, b) => a.grant_id.localeCompare(b.grant_id)));
const current = computed(() => projection.value?.items.find(row => row.agency_business_public_id === draft.agency));
const stale = computed(() => !!draft.agency && !!projection.value && (!current.value?.complete || setKey(current.value.grants) !== setKey(draft.expected)));
const valid = computed(() => currentContext() && manageable.value && !props.refreshing && !loading.value && !busy.value && !draft.blocked
    && !!current.value?.complete && current.value.grants.length > 0 && current.value.grants.length <= 256 && !stale.value && draft.confirmed);
const notice = computed(() => ({
    unavailable: t('تعذر التحقق من تفويضات الأنشطة. أعد التحديث؛ لا يعني هذا عدم وجودها.', 'Business mandate groups could not be checked. Refresh them; this does not mean none exist.'),
    unknown: t('نتيجة السحب غير محسومة. احتفظنا بالطلب الأصلي ومجموعة الحقوق نفسها للتحقق دون استبدالها.', 'The withdrawal outcome is unknown. The original command and exact grant set are retained for checking without replacement.'),
    denied: t('تعذرت متابعة السحب بالصلاحية الحالية. حدّث الوصول؛ لا نستنتج نتيجة طلب سابق غير محسوم.', 'Withdrawal cannot continue under current authority. Refresh access; no prior unknown outcome is inferred.'),
    rejected: t('راجع المجموعة الحالية ثم أكّد سحبها مجددًا. لم نغيّر قائمة الحقوق التي راجعتها تلقائيًا.', 'Review the current group and confirm again. Your reviewed grant set was not replaced automatically.'),
    withdrawn: t('تأكد سحب مجموعة تفويضات تصريحات النشاط المحددة. تتوقف الحقوق التابعة لها، وتبقى الحقوق المستقلة وحقوق المصادر والبيانات منفصلة.', 'Withdrawal of the selected Business declaration mandate group was acknowledged. Its dependent rights stop; independent grants and source/data rights remain separate.'),
    '': '',
})[draft.outcome]);
function conceal() { generation++; projection.value = undefined; loading.value = false; draft.confirmed = false; }
async function load(after?: string) {
    if (!currentContext() || !manageable.value || props.refreshing || loading.value || !Number.isSafeInteger(access.value) || access.value < 1) return;
    const attempt = ++generation, captured = stamp(); loading.value = true;
    if (!after) projection.value = undefined;
    if (!draft.pending && draft.outcome !== 'withdrawn') draft.outcome = '';
    try {
        const result = await businessAgencyMandates(business, access.value, after);
        if (attempt !== generation || !owned(captured) || !currentContext() || !manageable.value || props.refreshing) return;
        if (result.business_public_id !== business || result.items.some(group => group.grants.some(grant => !/^[1-9][0-9]{0,18}$/.test(grant.grant_id)
            || !Number.isSafeInteger(grant.version) || grant.version < 1) || group.complete && group.grants.length > 256)) throw Error('Mandate inventory mismatch');
        const rows = after && projection.value ? [...projection.value.items, ...result.items] : result.items;
        projection.value = { ...result, items: [...new Map(rows.map(row => [row.agency_business_public_id, row])).values()] };
    } catch {
        if (attempt === generation && owned(captured) && currentContext()) { projection.value = undefined; if (!draft.pending) draft.outcome = 'unavailable'; }
    } finally { if (attempt === generation && !disposed) loading.value = false; }
}
watch(() => [props.active, props.refreshing, props.surface], () => { conceal(); if (currentContext() && !props.refreshing) { draft.blocked = false; if (manageable.value) void load(); } }, { immediate: true, flush: 'sync' });
onBeforeUnmount(() => { disposed = true; conceal(); });
function review(group: AgencyMandates['items'][number]) {
    if (!currentContext() || !manageable.value || props.refreshing || loading.value || busy.value || draft.pending || !group.complete || !group.grants.length) return;
    draft.agency = group.agency_business_public_id; draft.expected = group.grants.map(grant => ({ ...grant })); draft.confirmed = false; draft.outcome = '';
}
async function send() {
    if (!currentContext() || props.refreshing || busy.value || draft.blocked) return;
    if (!draft.pending) {
        if (!valid.value) return;
        draft.pending = { command_id: crypto.randomUUID(), authority_selection: JSON.parse(basis), agency_business_public_id: draft.agency,
            expected_grants: JSON.parse(JSON.stringify(draft.expected)), confirmed: true };
    }
    const frozen = JSON.parse(JSON.stringify(draft.pending)) as AgencyMandatesCommand, captured = stamp(), uncertain = draft.uncertain;
    draft.uncertain = true; busy.value = true; draft.confirmed = false; draft.outcome = '';
    try {
        const result = await withdrawBusinessMandates(business, frozen);
        if (!owned(captured) || !currentContext()) return;
        if (result.business_public_id !== business || result.agency_business_public_id !== frozen.agency_business_public_id || result.status !== 'withdrawn'
            || !Number.isSafeInteger(result.versions.authority_epoch) || result.versions.authority_epoch < 0) throw Error('Mandate withdrawal acknowledgement mismatch');
        draft.pending = undefined; draft.uncertain = false; draft.agency = ''; draft.expected = []; draft.outcome = 'withdrawn'; conceal(); emit('refresh');
    } catch (error) {
        if (!owned(captured) || !currentContext()) return;
        if (error instanceof TransportError && [403, 409, 422].includes(error.status)) {
            if (!uncertain && [403, 422].includes(error.status)) { draft.pending = undefined; draft.uncertain = false; }
            draft.blocked = true; conceal(); draft.outcome = error.status === 422 && !uncertain ? 'rejected' : 'denied';
        } else draft.outcome = 'unknown';
    } finally { if (!disposed && owned(captured)) busy.value = false; }
}
</script>
<template>
    <section v-if="currentContext() && (manageable || draft.pending)" class="bf-card" data-business-mandates :aria-busy="loading || busy">
        <h3>{{ t('تفويضات الأنشطة التي منحتها', 'Business mandates you granted') }}</h3>
        <p>{{ t('راجع تفويضات تصريحات نشاطك التي منحتها لنشاط آخر واسحب المجموعة المحددة عند الحاجة. لا نستنتج اسم النشاط الآخر أو بياناته الخاصة من معرّفه.', 'Review the Business declaration mandates you granted to another Business and withdraw the selected group when needed. Its private name or details are not inferred from its identifier.') }}</p>
        <p>{{ t('السحب يوقف الحقوق التابعة لهذه المجموعة فقط. الحقوق المستقلة وحقوق المصادر واستعمال البيانات لا تتغير ضمنيًا.', 'Withdrawal stops rights derived from this group only. Independent rights, Source rights and data-use authority do not change implicitly.') }}</p>
        <p v-if="loading" role="status">{{ t('جارٍ التحقق من المجموعات…', 'Checking groups…') }}</p>
        <template v-if="projection">
            <ul v-if="projection.items.length" class="bf-issued-list" data-mandate-groups>
                <li v-for="group in projection.items" :key="group.agency_business_public_id" class="bf-issued">
                    <strong>{{ t('النشاط المفوَّض', 'Authorized Business') }}</strong><code dir="ltr">{{ group.agency_business_public_id }}</code>
                    <p v-if="!group.complete" role="status">{{ t('المجموعة المعروضة جزئية؛ لا يمكن سحبها جماعيًا. راجع الحقوق الفردية في «تفويضات أخرى وسجل السحب».', 'This group is partial and cannot be withdrawn in bulk. Review individual rights under Other grants and withdrawal history.') }}</p>
                    <button v-else-if="group.grants.length" type="button" class="workspace-button" data-mandate-review :disabled="loading || busy || !!draft.pending || refreshing" @click="review(group)">{{ t('مراجعة سحب هذه المجموعة', 'Review withdrawal of this group') }}</button>
                </li>
            </ul>
            <p v-else>{{ projection.more ? t('لا مجموعات ظاهرة في هذه الصفحة؛ توجد صفحات أخرى.', 'No groups appear on this page; other pages remain.') : t('لا مجموعات تفويض ظاهرة حاليًا.', 'No mandate groups are currently shown.') }}</p>
            <button v-if="projection.next_cursor" type="button" class="workspace-button" data-mandates-more :disabled="loading || busy || refreshing" @click="load(projection.next_cursor)">{{ t('عرض المزيد من الأنشطة المفوَّضة', 'Show more authorized businesses') }}</button>
            <p v-if="projection.more && !projection.next_cursor" role="status">{{ t('القائمة غير مكتملة؛ أعد تحديثها أو راجع الحقوق الفردية.', 'The inventory is incomplete; refresh it or review individual rights.') }}</p>
        </template>
        <section v-if="draft.agency && !draft.pending" class="workspace-task-editor" data-mandate-confirmation>
            <p>{{ t('المجموعة التي اخترتها تخص النشاط', 'Your selected group belongs to Business') }}: <code>{{ draft.agency }}</code></p>
            <p v-if="stale" role="status">{{ t('تغيّرت المجموعة. راجع الصف الحالي مجددًا قبل السحب.', 'The group changed. Review its current row again before withdrawal.') }}</p>
            <label class="bf-check"><input v-model="draft.confirmed" data-mandate-confirm type="checkbox" :disabled="busy || refreshing || loading || draft.blocked" />{{ t('أؤكد سحب مجموعة حقوق تصريحات النشاط المحددة وحقوقها التابعة، مع إبقاء الحقوق المستقلة.', 'Confirm withdrawal of this exact Business declaration group and its derived rights, preserving independent rights.') }}</label>
        </section>
        <p v-if="draft.pending" role="status">{{ t('الطلب الأصلي المحفوظ يخص النشاط', 'The retained original request concerns Business') }}: <code>{{ draft.pending.agency_business_public_id }}</code></p>
        <button v-if="draft.pending || draft.agency" type="button" class="workspace-button" data-mandate-withdraw :disabled="busy || refreshing || draft.blocked || (!draft.pending && !valid)" @click="send">{{ busy ? t('جارٍ التحقق…', 'Checking…') : draft.pending ? t('التحقق بالطلب الأصلي', 'Check original request') : t('تأكيد سحب مجموعة التفويضات', 'Confirm mandate group withdrawal') }}</button>
        <p v-if="notice" role="status">{{ notice }}</p>
        <button v-if="draft.blocked || draft.outcome === 'unavailable'" type="button" class="workspace-button" :disabled="busy || refreshing" @click="emit('refresh')">{{ t('تحديث المجموعات والصلاحية', 'Refresh groups and authority') }}</button>
    </section>
</template>
