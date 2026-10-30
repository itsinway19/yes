<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ObjectPurposeEditor from '../Components/Business/ObjectPurposeEditor.vue';
import { businessPurposes, writeBusinessPurpose, type BusinessPurposes, type Surface, type Selection } from './api';
import type { PurposeItem } from '../types/object-purpose';
import type { PurposeCommand } from '../object-purpose/api';
import type { PurposeWriter } from '../object-purpose/commands';
import { locale } from '../spa/locale';
import { status } from '../spa/presentation';
import { owned, stamp } from '../spa/transport';
import { key } from './drafts';
const props = defineProps<{ surface: Surface; refreshing: boolean }>();
const t = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const opened = ref(false), loading = ref(false), failed = ref(false), editing = ref(false);
const page = ref<BusinessPurposes>(), selected = ref<PurposeItem | null>(null);
let serial = 0, disposed = false;
function clear() { serial++; page.value = undefined; loading.value = false; failed.value = false; editing.value = false; }
watch(() => [props.surface, props.refreshing], () => { clear(); opened.value = false; }, { flush: 'sync' });
watch(status, value => { if (value !== 'ready') { clear(); opened.value = false; } }, { flush: 'sync' });
onBeforeUnmount(() => { disposed = true; clear(); });
const scopeKey = computed(() => key(props.surface.business.business_public_id, props.surface.authority_selection)+':purpose');
const grant = computed(() => props.surface.fact_write_options.purpose[0]?.grant_id);
async function read() {
    if (disposed || props.refreshing || status.value !== 'ready') return;
    const attempt = ++serial, captured = stamp(), subject = props.surface;
    page.value = undefined; failed.value = false; loading.value = true; editing.value = false;
    try {
        const access = subject.authority_selection.requirements.find(r => r.requirement_kind === 'business')?.leaf_grant_id;
        if (!Number.isSafeInteger(access)) throw Error('Exact Business read path required');
        const result = await businessPurposes(subject.business.business_public_id, access!);
        if (disposed || attempt !== serial || !owned(captured) || props.surface !== subject || status.value !== 'ready') return;
        if (result.business_public_id !== subject.business.business_public_id) throw Error('Purpose subject mismatch');
        page.value = result;
    } catch { if (!disposed && attempt === serial && owned(captured)) failed.value = true; }
    finally { if (!disposed && attempt === serial) loading.value = false; }
}
function toggle(event: Event) { opened.value = (event.currentTarget as HTMLDetailsElement).open; if (opened.value) void read(); else clear(); }
// Capture the complete admission context once. Unknown retries retain this same writer.
function writer(): PurposeWriter {
    const business = props.surface.business.business_public_id, expected_context = page.value!.projection.current_context_version;
    const authority_selection: Selection = structuredClone({ ...props.surface.authority_selection, requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(grant.value) }] });
    return async (command: PurposeCommand) => {
        const result = await writeBusinessPurpose(business, { command, expected_context, authority_selection });
        if (result.business_public_id !== business || (command.purpose_id !== null && result.purpose_id !== command.purpose_id)
            || result.version !== command.expected_version + 1 || result.versions.context !== expected_context) throw Error('Purpose acknowledgement identity/version mismatch');
        return { purpose_id: result.purpose_id, revision_id: result.revision_id, version: result.version };
    };
}
const commandWriter = ref<PurposeWriter>();
function edit(item: PurposeItem | null) { if (!grant.value || !page.value) return; selected.value = item; commandWriter.value = writer(); editing.value = true; }
function time(value: string) { return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-JO' : 'en-GB'); }
</script>
<template>
    <details class="bf-card bf-purposes" data-business-purposes :open="opened" @toggle="toggle">
        <summary>{{ t('أغراض العناصر الإعلانية', 'Advertising object purposes') }}</summary>
        <template v-if="opened">
            <p>{{ t('راجع الغرض المصرّح به للعناصر وتوقيت انطباقه، أو صححه بتصريح جديد. التصريح لا يثبت نتيجة إعلانية ولا يمنح حق الوصول إلى بيانات المصدر.', 'Review each object’s declared purpose and effective timing, or correct it through a new declaration. A declaration proves no advertising result and grants no source-data access.') }}</p>
            <button type="button" class="bf-secondary" :disabled="loading || refreshing" @click="read">{{ t('تحديث الأغراض', 'Refresh purposes') }}</button>
            <p v-if="loading" role="status">{{ t('جارٍ التحقق من الأغراض…', 'Checking purposes…') }}</p>
            <p v-else-if="failed" role="alert">{{ t('تعذرت القراءة بالحق الحالي. لم نعتبر ذلك غيابًا للتصريحات.', 'Current access could not qualify this read; declarations were not assumed absent.') }}</p>
            <template v-else-if="page">
                <p>{{ t('يعرض التصريحات الجديدة الخاصة بهذا النشاط. التاريخ الأصلي المستورد متاح في سجله ولا يصبح تصريحًا حاليًا تلقائيًا.', 'Shows this Business’s new declarations. Qualified original history remains in its own history and is not automatically current.') }}</p>
                <p v-if="!page.projection.items.length" data-purpose-empty>{{ t('لا أغراض جديدة مصرّح بها لهذا النشاط.', 'No new purposes declared for this Business.') }}</p>
                <button v-if="grant && !editing" type="button" class="bf-primary" data-purpose-create @click="edit(null)">{{ t('تصريح غرض جديد', 'Declare a purpose') }}</button>
                <p v-else-if="!grant">{{ t('عرض فقط؛ التصريح يحتاج حقًا مستقلًا مؤهلًا.', 'Read only; declaring requires independent qualified authority.') }}</p>
                <article v-for="item in page.projection.items" :key="item.purpose_id" class="bf-card" data-purpose-item>
                    <h3>{{ item.applicable?.snapshot.meaning ?? t('لا تصريح منطبق في الوقت الحالي', 'No declaration applicable now') }}</h3>
                    <p v-if="item.relationship_revalidation_required" role="status">{{ t('تغيرت الأسواق؛ يحتاج الارتباط الحالي إلى إعادة تأكيد.', 'Markets changed; the current relationship needs reconfirmation.') }}</p>
                    <p v-if="item.applicable?.snapshot.end_condition">{{ t('الاستمرار مشروط، ولم يُثبت تحقق الشرط:', 'Continuity is conditional; the condition has not been verified:') }} {{ item.applicable.snapshot.end_condition }}</p>
                    <details><summary>{{ t('التصريحات والتصحيحات المحفوظة', 'Saved declarations and corrections') }}</summary><ol>
                        <li v-for="revision in item.history" :key="revision.revision_id">{{ revision.version }} · {{ time(revision.accepted_at) }} · {{ revision.operation === 'withdraw' ? t('سحب التصريح', 'Withdrawn') : revision.snapshot?.meaning }}<p>{{ revision.reason }}</p></li>
                    </ol></details>
                    <button v-if="grant && !editing" type="button" class="bf-secondary" data-purpose-edit @click="edit(item)">{{ t('مراجعة أو تصحيح أو سحب', 'Review, correct or withdraw') }}</button>
                </article>
                <ObjectPurposeEditor v-if="editing && grant" :key="`${scopeKey}:${selected?.purpose_id ?? 'new'}`" :workspace-id="scopeKey" business-mode :purpose="selected" :context-version="page.projection.current_context_version" :dtm-transition-version="page.projection.latest_dtm_event_context_version" :current-intent="{ value: surface.business.current_intent, version: surface.business.versions.intent }" :command-writer="commandWriter" :refresh-resource="read" @saved="read" />
            </template>
        </template>
    </details>
</template>
<style scoped>
.bf-purposes :deep(form) { display: grid; gap: 1rem; margin-block-start: 1rem; }
.bf-purposes :deep(fieldset) { display: grid; gap: .8rem; min-width: 0; }
.bf-purposes :deep(label) { display: grid; gap: .3rem; overflow-wrap: anywhere; }
.bf-purposes :deep(input:not([type=checkbox])), .bf-purposes :deep(textarea), .bf-purposes :deep(select) { width: 100%; max-width: 100%; border: 1px solid #94a3b8; border-radius: .4rem; padding: .5rem; color: #172033; background: white; }
.bf-purposes :deep(input[type=checkbox]) { appearance: auto; width: 1.1rem; height: 1.1rem; }
</style>
