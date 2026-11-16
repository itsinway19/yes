<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { confirmDeparture } from '../spa/presentation';
import { appearance, createDraft, adoptPublished, publishAppearance, readAppearance } from './state';
import { onClose, owned, stamp } from '../spa/transport';
import { colorTokens, validColor } from './colors';
const draft = createDraft(appearance.value!);
const cancellation = new AbortController();
const clearDraft = () => { cancellation.abort(); draft.primary = ''; draft.secondary = ''; draft.logo = undefined; draft.favicon = undefined; };
const closures = onClose(clearDraft);
const publish = () => { if (valid.value) void publishAppearance(draft, cancellation.signal); };
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const fields = computed(() => [{ key: 'primary' as const, label: copy('اللون الأساسي', 'Primary color') }, { key: 'secondary' as const, label: copy('اللون الثانوي', 'Secondary color') }]);
const imageErrors = reactive({ logo: '', favicon: '' }), readingImage = ref(false);
const imageFields = computed(() => [{ key: 'logo' as const, label: copy('شعار الموقع', 'Site logo') }, { key: 'favicon' as const, label: copy('أيقونة التبويب', 'Browser tab icon') }]);
const valid = computed(() => validColor(draft.primary) && validColor(draft.secondary) && !readingImage.value && !imageErrors.logo && !imageErrors.favicon);
const locked = computed(() => ['pending', 'unknown', 'stale', 'acknowledged', 'reauthentication'].includes(draft.outcome));
const dirty = computed(() => !!appearance.value && (draft.primary.toLowerCase() !== appearance.value.primary || draft.secondary.toLowerCase() !== appearance.value.secondary || imageFields.value.some(({ key }) => draft[key] !== undefined && (draft[key] !== null || !!appearance.value?.[key]))));
const preview = computed(() => colorTokens(validColor(draft.primary) ? draft.primary : appearance.value!.primary, validColor(draft.secondary) ? draft.secondary : appearance.value!.secondary));
const reviewing = ref(false), reviewFailed = ref(false);
async function review() { if (reviewing.value) return; reviewing.value = true; const value = await readAppearance(false, true); reviewing.value = false; draft.reviewed = !!value; reviewFailed.value = !value; }
function replaceDraft() {
    if (!appearance.value || !confirm(copy('استبدال المسودة بالمظهر المنشور حاليًا؟', 'Replace the draft with the currently published appearance?'))) return;
    adoptPublished(draft, appearance.value); draft.reviewed = true;
    imageErrors.logo = ''; imageErrors.favicon = '';
}
function defaults() { if (locked.value || readingImage.value || !appearance.value) return; draft.primary = appearance.value.defaults.primary; draft.secondary = appearance.value.defaults.secondary; draft.logo = null; draft.favicon = null; imageErrors.logo = ''; imageErrors.favicon = ''; }
const imagePreview = (key: 'logo' | 'favicon') => draft[key] === undefined ? appearance.value?.[key]?.url ?? '/brand/builderad-mark.svg' : draft[key] ?? '/brand/builderad-mark.svg';
function defaultImage(key: 'logo' | 'favicon') { if (locked.value || readingImage.value) return; draft[key] = null; imageErrors[key] = ''; }
async function selectImage(key: 'logo' | 'favicon', event: Event) {
    const input = event.target as HTMLInputElement, file = input.files?.[0]; input.value = '';
    if (!file || locked.value || readingImage.value) return;
    const captured = stamp(); imageErrors[key] = ''; readingImage.value = true;
    try {
        if (file.type !== 'image/png' || file.size > 524288) throw Error('format');
        const buffer = await file.slice(0, 24).arrayBuffer();
        const header = new DataView(buffer);
        if (buffer.byteLength < 24 || header.getUint32(0) !== 0x89504e47 || header.getUint32(4) !== 0x0d0a1a0a) throw Error('format');
        const width = header.getUint32(16), height = header.getUint32(20);
        if (!width || !height || Math.max(width, height) > 2048 || key === 'favicon' && (width !== height || width > 512)) throw Error('size');
        const data = await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsDataURL(file); });
        await new Promise<void>((resolve, reject) => { const image = new Image(); image.onload = () => resolve(); image.onerror = reject; image.src = data; });
        if (!cancellation.signal.aborted && owned(captured)) draft[key] = data;
    } catch {
        if (!cancellation.signal.aborted && owned(captured)) imageErrors[key] = copy('اختر صورة PNG حتى 512 كيلوبايت. الشعار حتى 2048×2048، والأيقونة مربعة حتى 512×512.', 'Choose a PNG up to 512 KB. The logo may be up to 2048×2048; the icon must be square, up to 512×512.');
    } finally { readingImage.value = false; }
}
const canLeave = () => draft.outcome === 'acknowledged' || (!dirty.value && !['pending', 'unknown'].includes(draft.outcome)) || confirm(copy('توجد مسودة أو نتيجة نشر غير محسومة. هل تريد المغادرة؟ لن يُعاد النشر تلقائيًا.', 'There is a draft or unresolved publication. Leave? Publication will never replay automatically.'));
confirmDeparture.value = canLeave; onBeforeRouteLeave(canLeave); onBeforeRouteUpdate(canLeave);
function beforeUnload(event: BeforeUnloadEvent) { if (draft.outcome !== 'acknowledged' && (dirty.value || ['pending', 'unknown'].includes(draft.outcome))) { event.preventDefault(); event.returnValue = ''; } }
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => { cancellation.abort(); closures.delete(clearDraft); window.removeEventListener('beforeunload', beforeUnload); if (confirmDeparture.value === canLeave) confirmDeparture.value = () => true; });
</script>

<template>
    <OperationsShell>
        <template #header><h1>{{ copy('المظهر العام', 'Global appearance') }}</h1><p>{{ copy('ألوان الموقع وشعاره وأيقونة التبويب. عاين التغيير محليًا ثم انشره للجميع.', 'Site colors, logo and browser tab icon. Preview locally, then publish for everyone.') }}</p></template>
        <section v-if="appearance" class="appearance-settings" data-appearance-settings :data-appearance-outcome="draft.outcome">
            <p class="appearance-note">{{ copy('الألوان المنشورة', 'Published colors') }}: <bdi dir="ltr">{{ appearance.primary }}</bdi> · <bdi dir="ltr">{{ appearance.secondary }}</bdi> <span>— {{ copy('الإصدار', 'Revision') }} {{ appearance.revision }}</span></p>
            <p v-if="draft.outcome === 'unknown'" role="alert">{{ copy('نتيجة النشر غير معروفة. قد يكون التغيير حُفظ. النشر متوقف حتى تراجع الحالة الحالية وتستبدل المسودة صراحةً.', 'Publication outcome is unknown. The change may have saved. Publishing is blocked until you review the current state and explicitly replace the draft.') }}</p>
            <p v-if="draft.outcome === 'stale'" role="alert">{{ copy('تغيّر المظهر منذ فتح المسودة. احتفظنا بمسودتك وإصدارها؛ راجع المنشور قبل استبدالها.', 'Published appearance changed since this draft began. Your draft and its revision are preserved; review the publication before replacing it.') }}</p>
            <p v-if="draft.outcome === 'acknowledged'" role="status">{{ copy('تم النشر. جارٍ إعادة تحميل المظهر…', 'Published. Reloading appearance…') }}</p>
            <p v-if="draft.outcome === 'reauthentication'" role="alert" data-appearance-reauthentication>{{ copy('لم يُنشر التغيير. تتطلب العملية مصادقة حديثة. تبقى المسودة في هذه الصفحة إلى أن تغادرها؛ لن يُعاد النشر تلقائيًا.', 'The change was not published. Fresh authentication is required. The draft stays in this page until you leave; publication will not replay automatically.') }} <a href="/internal/identity/switch" data-appearance-reauthenticate>{{ copy('إعادة المصادقة بمفتاح المرور', 'Reauthenticate with a passkey') }}</a></p>
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
                        <p class="appearance-role">{{ field.key === 'primary' ? copy('للإجراء الرئيسي وتمييز القسم الحالي.', 'For the primary action and current section.') : copy('للعناوين المساندة والإجراءات الثانوية.', 'For supporting labels and secondary actions.') }}</p>
                        <div class="appearance-color-input">
                            <input type="color" :aria-label="copy('اختيار ', 'Choose ') + field.label" :value="validColor(draft[field.key]) ? draft[field.key] : appearance[field.key]" @input="draft[field.key] = ($event.target as HTMLInputElement).value" />
                            <input :id="`appearance-${field.key}`" v-model="draft[field.key]" type="text" dir="ltr" maxlength="7" autocomplete="off" spellcheck="false" :aria-invalid="!validColor(draft[field.key])" :aria-describedby="`appearance-${field.key}-hint`" />
                        </div>
                        <p :id="`appearance-${field.key}-hint`" :class="validColor(draft[field.key]) ? 'appearance-note' : 'admin-error'">{{ copy('صيغة سداسية من ست خانات، مثل #155f53', 'Six-digit hexadecimal, such as #155f53') }}</p>
                    </div>
                </fieldset>
                <fieldset class="appearance-image-fields" :disabled="locked || readingImage">
                    <legend>{{ copy('الشعار وأيقونة التبويب', 'Logo and browser tab icon') }}</legend>
                    <p class="appearance-note">{{ copy('الشعار يستبدل الهوية الظاهرة في رأس الموقع، والأيقونة تظهر في تبويب المتصفح. تُنشر الصور للعامة بعد الحفظ.', 'The logo replaces the identity in the site header. The icon appears in the browser tab. Publication makes the images public.') }}</p>
                    <div class="appearance-image-grid">
                        <div v-for="field in imageFields" :key="field.key" class="appearance-image-field">
                            <label :for="`appearance-${field.key}`">{{ field.label }}</label>
                            <div class="appearance-image-preview"><img :src="imagePreview(field.key)" :alt="field.label" :data-appearance-image-preview="field.key" /></div>
                            <input :id="`appearance-${field.key}`" :data-appearance-image="field.key" type="file" accept="image/png" :aria-invalid="!!imageErrors[field.key]" :aria-describedby="`appearance-${field.key}-help`" @change="selectImage(field.key, $event)" />
                            <p :id="`appearance-${field.key}-help`" class="appearance-note">{{ field.key === 'logo' ? copy('PNG حتى 512 كيلوبايت و2048×2048 بكسل. يفضل شعار بخلفية شفافة.', 'PNG up to 512 KB and 2048×2048 pixels. A transparent background works best.') : copy('PNG مربعة حتى 512 كيلوبايت و512×512 بكسل.', 'Square PNG up to 512 KB and 512×512 pixels.') }}</p>
                            <p v-if="imageErrors[field.key]" role="alert">{{ imageErrors[field.key] }}</p>
                            <button v-if="imageErrors[field.key] || draft[field.key] || appearance[field.key] && draft[field.key] !== null" type="button" class="admin-action" :data-appearance-image-default="field.key" @click="defaultImage(field.key)">{{ copy('استخدام الافتراضي في المسودة', 'Use default in draft') }}</button>
                        </div>
                    </div>
                </fieldset>
                <section id="appearance-preview" class="appearance-preview ba-theme-scope" :style="preview" :aria-label="copy('معاينة محلية', 'Local preview')" data-appearance-preview>
                    <p class="appearance-preview-label">{{ copy('معاينة محلية فقط — لم تُنشر', 'Local preview only — unpublished') }}</p>
                    <nav class="appearance-preview-nav" :aria-label="copy('نموذج التنقل', 'Sample navigation')"><span class="ba-brand-link">BuilderAd</span><a href="#appearance-preview" class="admin-nav-link" aria-current="page" @click.prevent>{{ copy('نظرة عامة', 'Overview') }}</a></nav>
                    <h2>{{ copy('مساحة عملك، بألوانك', 'Your workspace, your colors') }}</h2>
                    <p>{{ copy('تُضبط ألوان النص تلقائيًا لتبقى الأزرار والروابط مقروءة.', 'Text colors adjust automatically to keep buttons and links readable.') }}</p>
                    <article class="appearance-preview-card"><h3>{{ copy('الخطوة التالية — مثال توضيحي', 'Next step — illustrative example') }}</h3><p>{{ copy('حدّد النتيجة التي تريد التركيز عليها لتوضيح خطوتك التالية.', 'Choose the outcome to focus on to clarify your next step.') }}</p></article><div class="appearance-preview-states"><span data-preview-status class="preview-success">{{ copy('مكتمل', 'Complete') }}</span><span data-preview-status class="preview-warning">{{ copy('يحتاج مراجعة', 'Needs review') }}</span><span data-preview-status class="preview-error">{{ copy('تعذر الإكمال', 'Failed') }}</span></div><div class="appearance-actions"><button type="button" class="ba-brand-button">{{ copy('إجراء أساسي', 'Primary action') }}</button><button type="button" class="ba-secondary-button">{{ copy('إجراء ثانوي', 'Secondary action') }}</button><button type="button" class="ba-brand-button" data-preview-disabled disabled>{{ copy('غير متاح', 'Unavailable') }}</button></div>
                </section>
                <p class="appearance-note">{{ copy('استعادة الافتراضي تغيّر المسودة فقط. النشر يطبّق المظهر على الصفحات الجديدة والمُعاد تحميلها؛ حدّث الصفحات المفتوحة لرؤية التغيير.', 'Restore defaults changes only the draft. Publication applies to new and reloaded pages; reload open pages to see the change.') }}</p>
                <div class="appearance-actions"><button class="admin-action admin-action-primary" type="submit" data-appearance-publish :disabled="locked || !valid || !dirty">{{ draft.outcome === 'pending' ? copy('جارٍ النشر…', 'Publishing…') : copy('نشر المظهر للجميع', 'Publish appearance for everyone') }}</button><button class="admin-action" type="button" data-appearance-defaults :disabled="locked || readingImage" @click="defaults">{{ copy('استعادة الافتراضي في المسودة', 'Restore defaults in draft') }}</button></div>
            </form>
        </section>
    </OperationsShell>
</template>
<style scoped>
.appearance-image-fields{border:0;padding:16px 0;margin:0}.appearance-image-fields legend{font-size:15px;font-weight:650}.appearance-image-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}.appearance-image-field{min-width:0}.appearance-image-field label{display:block;font-weight:600;margin-block-end:10px}.appearance-image-preview{display:flex;align-items:center;min-height:76px;padding:14px;border:1px solid var(--admin-line);border-radius:12px;margin-block-end:12px;background:var(--admin-surface,#fff)}.appearance-image-preview img{max-width:100%;max-height:52px;object-fit:contain}.appearance-image-field input{max-width:100%;min-height:44px}.appearance-image-field input::file-selector-button{min-height:40px;margin-inline-end:10px;padding:7px 12px;border:1px solid var(--admin-line);border-radius:8px;background:var(--admin-surface,#fff);color:inherit;cursor:pointer}@media(max-width:600px){.appearance-image-grid{grid-template-columns:1fr}}

.appearance-preview-card{border-inline-start:3px solid var(--ba-primary-ink);background:#f7f8fa;padding:14px 18px;margin-block:20px}.appearance-preview-card h3{font-size:14px;font-weight:600}.appearance-role{font-size:13px;color:var(--admin-muted);line-height:1.7;margin:4px 0 10px}.appearance-preview-states{display:flex;flex-wrap:wrap;gap:16px;font-size:13px}.preview-success{color:#155f53}.preview-warning{color:#856022}.preview-error{color:#a13c38}.appearance-settings{max-width:900px;padding-block:8px 40px}.appearance-settings h1{font-size:22px;line-height:1.5}.appearance-note{color:var(--admin-muted);font-size:13px;line-height:1.9;margin-block:12px}.appearance-fields{display:grid;grid-template-columns:1fr 1fr;gap:24px;border:0;padding:20px 0;margin:0}.appearance-fields legend{font-size:15px;font-weight:600;padding:0}.appearance-color-input{display:flex;gap:10px;align-items:center;max-width:300px}.appearance-color-input input[type=color]{padding:3px;width:48px;min-width:48px;height:40px;cursor:pointer}.appearance-color-input input[type=text]{font-family:Consolas,monospace}.appearance-preview{padding:24px;border:1px solid var(--admin-line);border-radius:4px;background:#fff;color:#202b38;margin-block:12px 24px}.appearance-preview-label{font-size:12px;color:#586473;margin:0 0 20px}.appearance-preview-nav{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid #e1e5ea;padding-bottom:14px;margin-bottom:24px;font-weight:600}.appearance-preview h2{font-size:19px;font-weight:600;margin-bottom:8px}.appearance-preview p{line-height:1.8}.appearance-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px}.appearance-settings button:disabled{opacity:.5;cursor:not-allowed}.appearance-settings [role=alert]{margin-block:16px}.appearance-settings :is(input,button):focus-visible{outline:2px solid var(--ba-primary-ink);outline-offset:3px}@media(max-width:600px){.appearance-fields{grid-template-columns:1fr;gap:16px}.appearance-preview{padding:18px}.appearance-actions button{max-width:100%}}
</style>
