<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import RichTextEditor from './RichTextEditor.vue';
import { deleteLink, deletePage, publishPage, restorePage, restorePageRevision, saveLink, savePage, unpublishPage } from './api';
import { pageEditor, readPageEditor, type PageEditorData } from './state';

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const route = useRoute();
const router = useRouter();
const pageId = computed(() => String(route.params.page));
const editor = pageEditor;
const busy = ref(false);
const failure = ref('');
const expectedRevision = ref(editor.value?.draft?.revision ?? 0);

const form = reactive({
    title_ar: editor.value?.draft?.title_ar ?? '', title_en: editor.value?.draft?.title_en ?? '',
    slug_ar: editor.value?.draft?.slug_ar ?? editor.value?.page.slug_ar ?? '',
    slug_en: editor.value?.draft?.slug_en ?? editor.value?.page.slug_en ?? '',
    excerpt_ar: editor.value?.draft?.excerpt_ar ?? '', excerpt_en: editor.value?.draft?.excerpt_en ?? '',
    body_ar: editor.value?.draft?.body_ar ?? '', body_en: editor.value?.draft?.body_en ?? '',
    seo_title_ar: editor.value?.draft?.seo_title_ar ?? '', seo_title_en: editor.value?.draft?.seo_title_en ?? '',
    seo_description_ar: editor.value?.draft?.seo_description_ar ?? '', seo_description_en: editor.value?.draft?.seo_description_en ?? '',
    position: editor.value?.draft?.position ?? editor.value?.page.position ?? 0,
});

function adopt(value: PageEditorData) {
    pageEditor.value = value;
    expectedRevision.value = value.draft?.revision ?? 0;
    const draft = value.draft;
    Object.assign(form, {
        title_ar: draft?.title_ar ?? '', title_en: draft?.title_en ?? '',
        slug_ar: draft?.slug_ar ?? value.page.slug_ar, slug_en: draft?.slug_en ?? value.page.slug_en,
        excerpt_ar: draft?.excerpt_ar ?? '', excerpt_en: draft?.excerpt_en ?? '',
        body_ar: draft?.body_ar ?? '', body_en: draft?.body_en ?? '',
        seo_title_ar: draft?.seo_title_ar ?? '', seo_title_en: draft?.seo_title_en ?? '',
        seo_description_ar: draft?.seo_description_ar ?? '', seo_description_en: draft?.seo_description_en ?? '',
        position: draft?.position ?? value.page.position,
    });
}

async function save() {
    if (busy.value || !editor.value) return;
    busy.value = true;
    failure.value = '';
    try {
        const result = await savePage(pageId.value, {
            title_ar: form.title_ar.trim(), title_en: form.title_en.trim(),
            slug_ar: form.slug_ar.trim(), slug_en: form.slug_en.trim(),
            excerpt_ar: form.excerpt_ar.trim() || null, excerpt_en: form.excerpt_en.trim() || null,
            body_ar: form.body_ar, body_en: form.body_en,
            seo_title_ar: form.seo_title_ar.trim() || null, seo_title_en: form.seo_title_en.trim() || null,
            seo_description_ar: form.seo_description_ar.trim() || null, seo_description_en: form.seo_description_en.trim() || null,
            position: Number(form.position) || 0, expected_revision: expectedRevision.value,
        });
        adopt(result.page);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ المسودة.', 'The draft could not be saved.');
    } finally {
        busy.value = false;
    }
}

async function publish() {
    if (busy.value || !editor.value?.draft) return;
    if (!confirm(copy('نشر هذه المسودة للجمهور؟', 'Publish this draft to the public site?'))) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await publishPage(pageId.value, editor.value.draft.revision)).page);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر النشر.', 'Publication failed.');
    } finally {
        busy.value = false;
    }
}

async function unpublish() {
    if (busy.value || !editor.value?.published) return;
    if (!confirm(copy('إلغاء نشر الصفحة؟ لن يتمكن الزوار من الوصول إليها.', 'Unpublish this page? Visitors will lose access.'))) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await unpublishPage(pageId.value)).page);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر إلغاء النشر.', 'Unpublication failed.');
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (busy.value) return;
    if (!confirm(copy('حذف الصفحة منطقيًا؟ يمكن استعادتها لاحقًا.', 'Soft-delete this page? It can be restored later.'))) return;
    busy.value = true;
    failure.value = '';
    try {
        await deletePage(pageId.value);
        await router.push('/internal/administration/content/pages');
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر الحذف.', 'Deletion failed.');
    } finally {
        busy.value = false;
    }
}

async function restore() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await restorePage(pageId.value)).page);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر الاستعادة.', 'Restore failed.');
    } finally {
        busy.value = false;
    }
}

async function restoreRevision(revision: number) {
    if (busy.value) return;
    if (!confirm(copy('استعادة هذه المراجعة كمسودة جديدة؟', 'Restore this revision as a new draft?'))) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await restorePageRevision(pageId.value, revision)).page);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر استعادة المراجعة.', 'Revision restore failed.');
    } finally {
        busy.value = false;
    }
}

const sectionChoices = computed(() => editor.value?.sections ?? []);
const placements = computed(() => editor.value?.placements ?? []);
const targetSection = ref('');
const addingSection = ref(false);

async function attachToSection() {
    if (addingSection.value || !targetSection.value) return;
    addingSection.value = true;
    failure.value = '';
    try {
        await saveLink(targetSection.value, {
            link_type: 'page', target: pageId.value, internal_path: null, external_url: null,
            label_ar: null, label_en: null, position: 0, visible: true, open_in_new_tab: false,
        });
        await readPageEditor(pageId.value);
        targetSection.value = '';
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر إضافة الصفحة إلى القسم.', 'The page could not be added to the section.');
    } finally {
        addingSection.value = false;
    }
}

async function detachFromSection(link: string) {
    if (addingSection.value) return;
    addingSection.value = true;
    failure.value = '';
    try {
        await deleteLink(link);
        await readPageEditor(pageId.value);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر إزالة الصفحة من القسم.', 'The page could not be removed from the section.');
    } finally {
        addingSection.value = false;
    }
}

const published = computed(() => editor.value?.published);
const revisions = computed(() => editor.value?.revisions ?? []);
</script>

<template>
    <OperationsShell>
        <template #header>
            <p><RouterLink to="/internal/administration/content/pages">← {{ copy('الصفحات الثابتة', 'Static pages') }}</RouterLink></p>
            <h1>{{ copy('تحرير الصفحة', 'Edit page') }} — {{ locale === 'ar' ? form.title_ar : form.title_en }}</h1>
            <p>{{ copy('يُحفظ كل حفظ كمراجعة جديدة. النشر يعتمد المراجعة الحالية دون تغيير سجل المراجعات.', 'Each save creates a new revision. Publishing approves the current revision without rewriting history.') }}</p>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>
        <p v-if="editor?.page.deleted" class="content-error" role="status">{{ copy('هذه الصفحة محذوفة منطقيًا.', 'This page is soft-deleted.') }}</p>

        <div class="content-toolbar">
            <button class="content-action content-action--primary" type="button" :disabled="busy" data-content-save @click="save">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ المسودة', 'Save draft') }}</button>
            <button class="content-action" type="button" :disabled="busy || !editor?.draft" data-content-publish @click="publish">{{ copy('نشر', 'Publish') }}</button>
            <button v-if="published" class="content-action" type="button" :disabled="busy" @click="unpublish">{{ copy('إلغاء النشر', 'Unpublish') }}</button>
            <a class="content-action" :href="`/internal/administration/content/pages/${pageId}/preview`" target="_blank" rel="noopener">{{ copy('معاينة محمية', 'Protected preview') }}</a>
            <a v-if="published" class="content-action" :href="editor?.page.url_ar" target="_blank" rel="noopener">{{ copy('عرض المنشور', 'View published') }}</a>
            <button v-if="!editor?.page.deleted" class="content-action content-action--danger" type="button" :disabled="busy" @click="remove">{{ copy('حذف', 'Delete') }}</button>
            <button v-else class="content-action" type="button" :disabled="busy" @click="restore">{{ copy('استعادة', 'Restore') }}</button>
            <span class="content-note">{{ copy('المسودة', 'Draft') }}: {{ expectedRevision }} · {{ copy('المنشور', 'Published') }}: {{ published?.revision ?? '—' }}</span>
        </div>

        <form class="content-form" @submit.prevent="save">
            <div class="content-grid">
                <div class="content-field"><label for="page-edit-title-ar">{{ copy('العنوان بالعربية', 'Arabic title') }}</label><input id="page-edit-title-ar" v-model="form.title_ar" required maxlength="200" dir="rtl"></div>
                <div class="content-field"><label for="page-edit-title-en">{{ copy('العنوان بالإنجليزية', 'English title') }}</label><input id="page-edit-title-en" v-model="form.title_en" required maxlength="200" dir="ltr"></div>
                <div class="content-field"><label for="page-edit-slug-ar">{{ copy('Slug بالعربية', 'Arabic slug') }}</label><input id="page-edit-slug-ar" v-model="form.slug_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="page-edit-slug-en">{{ copy('Slug بالإنجليزية', 'English slug') }}</label><input id="page-edit-slug-en" v-model="form.slug_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field"><label for="page-edit-excerpt-ar">{{ copy('وصف مختصر بالعربية', 'Arabic excerpt') }}</label><input id="page-edit-excerpt-ar" v-model="form.excerpt_ar" maxlength="600" dir="rtl"></div>
                <div class="content-field"><label for="page-edit-excerpt-en">{{ copy('وصف مختصر بالإنجليزية', 'English excerpt') }}</label><input id="page-edit-excerpt-en" v-model="form.excerpt_en" maxlength="600" dir="ltr"></div>
                <div class="content-field"><label for="page-edit-position">{{ copy('الترتيب', 'Order') }}</label><input id="page-edit-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
                <div class="content-field"><label for="page-edit-seo-ar">{{ copy('عنوان SEO بالعربية', 'Arabic SEO title') }}</label><input id="page-edit-seo-ar" v-model="form.seo_title_ar" maxlength="200" dir="rtl"></div>
                <div class="content-field"><label for="page-edit-seo-en">{{ copy('عنوان SEO بالإنجليزية', 'English SEO title') }}</label><input id="page-edit-seo-en" v-model="form.seo_title_en" maxlength="200" dir="ltr"></div>
                <div class="content-field"><label for="page-edit-seo-desc-ar">{{ copy('وصف SEO بالعربية', 'Arabic SEO description') }}</label><input id="page-edit-seo-desc-ar" v-model="form.seo_description_ar" maxlength="300" dir="rtl"></div>
                <div class="content-field"><label for="page-edit-seo-desc-en">{{ copy('وصف SEO بالإنجليزية', 'English SEO description') }}</label><input id="page-edit-seo-desc-en" v-model="form.seo_description_en" maxlength="300" dir="ltr"></div>
            </div>
            <RichTextEditor v-model="form.body_ar" :label="copy('المحتوى بالعربية', 'Arabic content')" dir="rtl" />
            <RichTextEditor v-model="form.body_en" :label="copy('المحتوى بالإنجليزية', 'English content')" dir="ltr" />
        </form>

        <section class="content-placements">
            <h2>{{ copy('مكان ظهور الصفحة', 'Page placement') }}</h2>
            <p class="content-note">{{ copy('أضف الصفحة إلى قسم في رأس الموقع أو الفوتر. الإضافة لا تنشر الصفحة ولا تحذف ارتباطات أخرى.', 'Attach the page to a header or footer section. Attaching neither publishes the page nor removes other links.') }}</p>
            <div class="content-actions">
                <select id="page-placement-section" v-model="targetSection" :aria-label="copy('القسم', 'Section')">
                    <option value="">{{ copy('اختر قسمًا', 'Choose a section') }}</option>
                    <option v-for="section in sectionChoices" :key="section.public_id" :value="section.public_id">
                        {{ locale === 'ar' ? section.name_ar : section.name_en }} — {{ section.placement === 'header' ? copy('رأس الموقع', 'Header') : copy('الفوتر', 'Footer') }}
                    </option>
                </select>
                <button class="content-action" type="button" :disabled="addingSection || !targetSection" @click="attachToSection">{{ copy('أضف إلى القسم', 'Add to section') }}</button>
            </div>
            <ul>
                <li v-for="placement in placements" :key="placement.link_public_id">
                    <span>{{ locale === 'ar' ? placement.section_name_ar : placement.section_name_en }}</span>
                    <span class="content-note">{{ placement.visible ? copy('ظاهر', 'Visible') : copy('مخفي', 'Hidden') }}</span>
                    <button class="content-action content-action--danger" type="button" :disabled="addingSection" @click="detachFromSection(placement.link_public_id)">{{ copy('إزالة', 'Remove') }}</button>
                </li>
            </ul>
            <p v-if="placements.length === 0" class="content-note">{{ copy('هذه الصفحة غير مرتبطة بأي قسم بعد.', 'This page is not attached to any section yet.') }}</p>
        </section>

        <section class="content-revisions">
            <h2>{{ copy('المراجعات', 'Revisions') }}</h2>
            <ul>
                <li v-for="revision in revisions" :key="revision.revision">
                    <span dir="ltr">#{{ revision.revision }} · {{ revision.state }} · {{ revision.created_at?.slice(0, 19).replace('T', ' ') }}</span>
                    <button class="content-action" type="button" :disabled="busy" @click="restoreRevision(revision.revision)">{{ copy('استعادة كمسودة', 'Restore as draft') }}</button>
                </li>
                <li v-if="revisions.length === 0">{{ copy('لا توجد مراجعات بعد.', 'No revisions yet.') }}</li>
            </ul>
        </section>
    </OperationsShell>
</template>
