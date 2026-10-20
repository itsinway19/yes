<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { confirmDeparture } from '../spa/presentation';
import { appearance, createDraft, adoptPublished, publishAppearance, readAppearance } from './state';
import { onClose } from '../spa/transport';
import { colorTokens, validColor } from './colors';
const draft = createDraft(appearance.value!);
const cancellation = new AbortController();
const clearDraft = () => { cancellation.abort(); draft.primary = ''; draft.secondary = ''; };
const closures = onClose(clearDraft);
const publish = () => publishAppearance(draft, cancellation.signal);
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const fields = computed(() => [{ key: 'primary' as const, label: copy('اللون الأساسي', 'Primary color') }, { key: 'secondary' as const, label: copy('اللون الثانوي', 'Secondary color') }]);
const valid = computed(() => validColor(draft.primary) && validColor(draft.secondary));
const locked = computed(() => ['pending', 'unknown', 'stale', 'acknowledged'].includes(draft.outcome));
const dirty = computed(() => !!appearance.value && (draft.primary.toLowerCase() !== appearance.value.primary || draft.secondary.toLowerCase() !== appearance.value.secondary));
const preview = computed(() => colorTokens(validColor(draft.primary) ? draft.primary : appearance.value!.primary, validColor(draft.secondary) ? draft.secondary : appearance.value!.secondary));
const reviewing = ref(false), reviewFailed = ref(false);
async function review() { if (reviewing.value) return; reviewing.value = true; const value = await readAppearance(false, true); reviewing.value = false; draft.reviewed = !!value; reviewFailed.value = !value; }
function replaceDraft() {
    if (!appearance.value || !confirm(copy('استبدال المسودة بالألوان المنشورة حاليًا؟', 'Replace the draft with the currently published colors?'))) return;
    adoptPublished(draft, appearance.value); draft.reviewed = true;
}
function defaults() { if (locked.value || !appearance.value) return; draft.primary = appearance.value.defaults.primary; draft.secondary = appearance.value.defaults.secondary; }
const canLeave = () => draft.outcome === 'acknowledged' || (!dirty.value && !['pending', 'unknown'].includes(draft.outcome)) || confirm(copy('توجد مسودة أو نتيجة نشر غير محسومة. هل تريد المغادرة؟ لن يُعاد النشر تلقائيًا.', 'There is a draft or unresolved publication. Leave? Publication will never replay automatically.'));
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { if (draft.outcome !== 'acknowledged' && (dirty.value || ['pending', 'unknown'].includes(draft.outcome))) { event.preventDefault(); event.returnValue = ''; } }
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { cancellation.abort(); closures.delete(clearDraft); window.removeEventListener('beforeunload', beforeUnload); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; });
</script>

<template>
    <OperationsShell>
        <template #header><h1>{{ copy('المظهر العام', 'Global appearance') }}</h1><p>{{ copy('لونان موحّدان لجميع المستخدمين. عاين التغيير محليًا ثم انشره.', 'Two shared colors for every user. Preview locally, then publish.') }}</p></template>
        <section v-if="appearance" class="appearance-settings" data-appearance-settings :data-appearance-outcome="draft.outcome">
            <p class="appearance-note">{{ copy('الألوان المنشورة', 'Published colors') }}: <bdi dir="ltr">{{ appearance.primary }}</bdi> · <bdi dir="ltr">{{ appearance.secondary }}</bdi> <span>— {{ copy('الإصدار', 'Revision') }} {{ appearance.revision }}</span></p>
            <p v-if="draft.outcome === 'unknown'" role="alert">{{ copy('نتيجة النشر غير معروفة. قد يكون التغيير حُفظ. النشر متوقف حتى تراجع الحالة الحالية وتستبدل المسودة صراحةً.', 'Publication outcome is unknown. The change may have saved. Publishing is blocked until you review the current state and explicitly replace the draft.') }}</p>
            <p v-if="draft.outcome === 'stale'" role="alert">{{ copy('تغيّرت الألوان منذ فتح المسودة. احتفظنا بمسودتك وإصدارها؛ راجع المنشور قبل استبدالها.', 'Published colors changed since this draft began. Your draft and its revision are preserved; review the publication before replacing it.') }}</p>
            <p v-if="draft.outcome === 'acknowledged'" role="status">{{ copy('تم النشر. جارٍ إعادة تحميل المظهر…', 'Published. Reloading appearance…') }}</p>
            <p v-if="draft.error" role="alert">{{ draft.error }}</p>
            <p v-if="reviewFailed" role="alert">{{ copy('تعذرت قراءة الحالة. لا يزال النشر متوقفًا.', 'Could not read the current state. Publishing remains blocked.') }}</p>
            <template v-if="['unknown', 'stale'].includes(draft.outcome)">
                <button class="admin-action" type="button" :disabled="reviewing" data-appearance-review @click="review">{{ reviewing ? copy('جارٍ القراءة…', 'Reading…') : copy('مراجعة المنشور دون إعادة النشر', 'Review published state without replay') }}</button>
                <p v-if="draft.reviewed" role="status">{{ copy('هذه قراءة حالية وليست إقرارًا بالنشر السابق.', 'This is a current read, not acknowledgement of the previous publication.') }}</p>
                <button v-if="draft.reviewed" class="admin-action" type="button" data-appearance-replace @click="replaceDraft">{{ copy('استبدال المسودة بالمنشور', 'Replace draft with publication') }}</button>
            </template>
            <form @submit.prevent="publish">
                <fieldset :disabled="locked" class="appearance-fields">
                    <legend>{{ copy('ألوان المسودة', 'Draft colors') }}</legend>
                    <div v-for="field in fields" :key="field.key" class="admin-field">
                        <label :for="`appearance-${field.key}`">{{ field.label }}</label>
                        <div class="appearance-color-input">
                            <input type="color" :aria-label="copy('اختيار ', 'Choose ') + field.label" :value="validColor(draft[field.key]) ? draft[field.key] : appearance[field.key]" @input="draft[field.key] = ($event.target as HTMLInputElement).value" />
                            <input :id="`appearance-${field.key}`" v-model="draft[field.key]" type="text" dir="ltr" maxlength="7" autocomplete="off" spellcheck="false" :aria-invalid="!validColor(draft[field.key])" :aria-describedby="`appearance-${field.key}-hint`" />
                        </div>
                        <p :id="`appearance-${field.key}-hint`" :class="validColor(draft[field.key]) ? 'appearance-note' : 'admin-error'">{{ copy('صيغة سداسية من ست خانات، مثل #155f53', 'Six-digit hexadecimal, such as #155f53') }}</p>
                    </div>
                </fieldset>
                <section id="appearance-preview" class="appearance-preview ba-theme-scope" :style="preview" :aria-label="copy('معاينة محلية', 'Local preview')" data-appearance-preview>
                    <p class="appearance-preview-label">{{ copy('معاينة محلية فقط — لم تُنشر', 'Local preview only — unpublished') }}</p>
                    <nav class="appearance-preview-nav" :aria-label="copy('نموذج التنقل', 'Sample navigation')"><span class="ba-brand-link">BuilderAd</span><a href="#appearance-preview" class="admin-nav-link" aria-current="page" @click.prevent>{{ copy('نظرة عامة', 'Overview') }}</a></nav>
                    <h2>{{ copy('مساحة عملك، بألوانك', 'Your workspace, your colors') }}</h2>
                    <p>{{ copy('تُضبط ألوان النص تلقائيًا لتبقى الأزرار والروابط مقروءة.', 'Text colors adjust automatically to keep buttons and links readable.') }}</p>
                    <article class="appearance-preview-card"><h3>{{ copy('نظرة على مساحة العمل', 'Workspace overview') }}</h3><strong>3</strong><p>{{ copy('أنشطة قيد الإعداد — مثال توضيحي', 'Activities in preparation — illustrative example') }}</p></article><div class="appearance-preview-states"><span data-preview-status class="preview-success">{{ copy('مكتمل', 'Complete') }}</span><span data-preview-status class="preview-warning">{{ copy('يحتاج مراجعة', 'Needs review') }}</span><span data-preview-status class="preview-error">{{ copy('تعذر الإكمال', 'Failed') }}</span></div><div class="appearance-actions"><button type="button" class="ba-brand-button">{{ copy('إجراء أساسي', 'Primary action') }}</button><button type="button" class="ba-secondary-button">{{ copy('إجراء ثانوي', 'Secondary action') }}</button><button type="button" class="ba-brand-button" data-preview-disabled disabled>{{ copy('غير متاح', 'Unavailable') }}</button></div>
                </section>
                <p class="appearance-note">{{ copy('استعادة الافتراضي تغيّر المسودة فقط. النشر يطبّق الألوان على الصفحات الجديدة والمُعاد تحميلها؛ الصفحات المفتوحة تحتفظ بألوانها حتى إعادة التحميل.', 'Restore defaults changes only the draft. Publication applies to new and reloaded pages; open pages retain their colors until reloaded.') }}</p>
                <div class="appearance-actions"><button class="admin-action admin-action-primary" type="submit" data-appearance-publish :disabled="locked || !valid || !dirty">{{ draft.outcome === 'pending' ? copy('جارٍ النشر…', 'Publishing…') : copy('نشر المظهر للجميع', 'Publish appearance for everyone') }}</button><button class="admin-action" type="button" data-appearance-defaults :disabled="locked" @click="defaults">{{ copy('استعادة الافتراضي في المسودة', 'Restore defaults in draft') }}</button></div>
            </form>
        </section>
    </OperationsShell>
</template>
<style scoped>
.appearance-preview-card{border-inline-start:3px solid var(--ba-primary-ink);background:#f7f8fa;padding:14px 18px;margin-block:20px}.appearance-preview-card h3{font-size:14px;font-weight:600}.appearance-preview-card strong{font-size:26px}.appearance-preview-states{display:flex;flex-wrap:wrap;gap:16px;font-size:13px}.preview-success{color:#155f53}.preview-warning{color:#856022}.preview-error{color:#a13c38}.appearance-settings{max-width:900px;padding-block:8px 40px}.appearance-settings h1{font-size:28px}.appearance-note{color:var(--admin-muted);font-size:13px;line-height:1.9;margin-block:12px}.appearance-fields{display:grid;grid-template-columns:1fr 1fr;gap:24px;border:0;padding:20px 0;margin:0}.appearance-fields legend{font-size:18px;font-weight:600;padding:0}.appearance-color-input{display:flex;gap:10px;align-items:center;max-width:300px}.appearance-color-input input[type=color]{padding:3px;width:48px;min-width:48px;height:40px;cursor:pointer}.appearance-color-input input[type=text]{font-family:Consolas,monospace}.appearance-preview{padding:24px;border:1px solid var(--admin-line);border-radius:4px;background:#fff;color:#202b38;margin-block:12px 24px}.appearance-preview-label{font-size:12px;color:#586473;margin:0 0 20px}.appearance-preview-nav{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid #e1e5ea;padding-bottom:14px;margin-bottom:24px;font-weight:600}.appearance-preview h2{font-size:22px;font-weight:600;margin-bottom:8px}.appearance-preview p{line-height:1.8}.appearance-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px}.appearance-settings button:disabled{opacity:.5;cursor:not-allowed}.appearance-settings [role=alert]{margin-block:16px}.appearance-settings :is(input,button):focus-visible{outline:2px solid var(--ba-primary-ink);outline-offset:3px}@media(max-width:600px){.appearance-fields{grid-template-columns:1fr;gap:16px}.appearance-preview{padding:18px}.appearance-actions button{max-width:100%}}
</style>
