<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import RichTextEditor from './RichTextEditor.vue';
import {
    deleteArticle, publishArticle, restoreArticle, restoreArticleRevision, saveArticle, unpublishArticle,
} from './api';
import { articleEditor, type ArticleEditorData } from './state';

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const route = useRoute();
const router = useRouter();
const articleId = computed(() => String(route.params.article));
const editor = articleEditor;
const busy = ref(false);
const failure = ref('');
const expectedRevision = ref(editor.value?.draft?.revision ?? 0);

const form = reactive({
    title_ar: editor.value?.draft?.title_ar ?? '', title_en: editor.value?.draft?.title_en ?? '',
    slug_ar: editor.value?.draft?.slug_ar ?? editor.value?.article.slug_ar ?? '',
    slug_en: editor.value?.draft?.slug_en ?? editor.value?.article.slug_en ?? '',
    summary_ar: editor.value?.draft?.summary_ar ?? '', summary_en: editor.value?.draft?.summary_en ?? '',
    body_ar: editor.value?.draft?.body_ar ?? '', body_en: editor.value?.draft?.body_en ?? '',
    image_path: editor.value?.draft?.image_path ?? '', image_alt_ar: editor.value?.draft?.image_alt_ar ?? '',
    image_alt_en: editor.value?.draft?.image_alt_en ?? '',
    seo_title_ar: editor.value?.draft?.seo_title_ar ?? '', seo_title_en: editor.value?.draft?.seo_title_en ?? '',
    seo_description_ar: editor.value?.draft?.seo_description_ar ?? '', seo_description_en: editor.value?.draft?.seo_description_en ?? '',
    category_public_id: editor.value?.draft?.category_public_id ?? editor.value?.article.category_public_id ?? '',
    position: editor.value?.draft?.position ?? editor.value?.article.position ?? 0,
});

function adopt(value: ArticleEditorData) {
    articleEditor.value = value;
    expectedRevision.value = value.draft?.revision ?? 0;
    const draft = value.draft;
    Object.assign(form, {
        title_ar: draft?.title_ar ?? '', title_en: draft?.title_en ?? '',
        slug_ar: draft?.slug_ar ?? value.article.slug_ar, slug_en: draft?.slug_en ?? value.article.slug_en,
        summary_ar: draft?.summary_ar ?? '', summary_en: draft?.summary_en ?? '',
        body_ar: draft?.body_ar ?? '', body_en: draft?.body_en ?? '',
        image_path: draft?.image_path ?? '', image_alt_ar: draft?.image_alt_ar ?? '', image_alt_en: draft?.image_alt_en ?? '',
        seo_title_ar: draft?.seo_title_ar ?? '', seo_title_en: draft?.seo_title_en ?? '',
        seo_description_ar: draft?.seo_description_ar ?? '', seo_description_en: draft?.seo_description_en ?? '',
        category_public_id: draft?.category_public_id ?? value.article.category_public_id ?? '',
        position: draft?.position ?? value.article.position,
    });
}

async function save() {
    if (busy.value || !editor.value) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await saveArticle(articleId.value, {
            title_ar: form.title_ar.trim(), title_en: form.title_en.trim(),
            slug_ar: form.slug_ar.trim(), slug_en: form.slug_en.trim(),
            excerpt_ar: null, excerpt_en: null,
            body_ar: form.body_ar, body_en: form.body_en,
            summary_ar: form.summary_ar.trim() || null, summary_en: form.summary_en.trim() || null,
            image_path: form.image_path.trim() || null,
            image_alt_ar: form.image_alt_ar.trim() || null, image_alt_en: form.image_alt_en.trim() || null,
            seo_title_ar: form.seo_title_ar.trim() || null, seo_title_en: form.seo_title_en.trim() || null,
            seo_description_ar: form.seo_description_ar.trim() || null, seo_description_en: form.seo_description_en.trim() || null,
            category_public_id: form.category_public_id || null,
            position: Number(form.position) || 0, expected_revision: expectedRevision.value,
        })).article);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ المسودة.', 'The draft could not be saved.');
    } finally {
        busy.value = false;
    }
}

async function publish() {
    if (busy.value || !editor.value?.draft) return;
    if (!confirm(copy('نشر هذه المسودة للجمهور؟', 'Publish this draft publicly?'))) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await publishArticle(articleId.value, editor.value.draft.revision)).article);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر النشر.', 'Publication failed.');
    } finally {
        busy.value = false;
    }
}

async function unpublish() {
    if (busy.value || !editor.value?.published) return;
    if (!confirm(copy('إلغاء نشر المقالة؟', 'Unpublish this article?'))) return;
    busy.value = true;
    failure.value = '';
    try {
        adopt((await unpublishArticle(articleId.value)).article);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر إلغاء النشر.', 'Unpublication failed.');
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (busy.value) return;
    if (!confirm(copy('حذف المقالة منطقيًا؟', 'Soft-delete this article?'))) return;
    busy.value = true;
    failure.value = '';
    try {
        await deleteArticle(articleId.value);
        await router.push('/internal/administration/content/articles');
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
        adopt((await restoreArticle(articleId.value)).article);
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
        adopt((await restoreArticleRevision(articleId.value, revision)).article);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر استعادة المراجعة.', 'Revision restore failed.');
    } finally {
        busy.value = false;
    }
}

const published = computed(() => editor.value?.published);
const revisions = computed(() => editor.value?.revisions ?? []);
const categories = computed(() => editor.value?.categories ?? []);
</script>

<template>
    <OperationsShell>
        <template #header>
            <p><RouterLink to="/internal/administration/content/articles">← {{ copy('المقالات', 'Articles') }}</RouterLink></p>
            <h1>{{ copy('تحرير المقالة', 'Edit article') }} — {{ locale === 'ar' ? form.title_ar : form.title_en }}</h1>
            <p>{{ copy('كل حفظ ينشئ مراجعة جديدة. النشر يعتمد المراجعة الحالية دون تغيير السجل.', 'Each save creates a new revision. Publishing approves the current revision without rewriting history.') }}</p>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>
        <p v-if="editor?.article.deleted" class="content-error" role="status">{{ copy('هذه المقالة محذوفة منطقيًا.', 'This article is soft-deleted.') }}</p>

        <div class="content-toolbar">
            <button v-if="editor?.canManage" class="content-action content-action--primary" type="button" :disabled="busy" data-content-save @click="save">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ المسودة', 'Save draft') }}</button>
            <button v-if="editor?.canPublish" class="content-action" type="button" :disabled="busy || !editor?.draft" data-content-publish @click="publish">{{ copy('نشر', 'Publish') }}</button>
            <button v-if="editor?.canPublish && published" class="content-action" type="button" :disabled="busy" @click="unpublish">{{ copy('إلغاء النشر', 'Unpublish') }}</button>
            <a class="content-action" :href="`/internal/administration/content/articles/${articleId}/preview`" target="_blank" rel="noopener">{{ copy('معاينة محمية', 'Protected preview') }}</a>
            <a v-if="published" class="content-action" :href="editor?.article.url_ar" target="_blank" rel="noopener">{{ copy('عرض المنشور', 'View published') }}</a>
            <button v-if="editor?.canPublish && !editor?.article.deleted" class="content-action content-action--danger" type="button" :disabled="busy" @click="remove">{{ copy('حذف', 'Delete') }}</button>
            <button v-if="editor?.canManage && editor?.article.deleted" class="content-action" type="button" :disabled="busy" @click="restore">{{ copy('استعادة', 'Restore') }}</button>
            <span class="content-note">{{ copy('المسودة', 'Draft') }}: {{ expectedRevision }} · {{ copy('المنشور', 'Published') }}: {{ published?.revision ?? '—' }}</span>
        </div>

        <form v-if="editor?.canManage" class="content-form" @submit.prevent="save">
            <div class="content-grid">
                <div class="content-field"><label for="article-edit-title-ar">{{ copy('العنوان بالعربية', 'Arabic title') }}</label><input id="article-edit-title-ar" v-model="form.title_ar" required maxlength="200" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-title-en">{{ copy('العنوان بالإنجليزية', 'English title') }}</label><input id="article-edit-title-en" v-model="form.title_en" required maxlength="200" dir="ltr"></div>
                <div class="content-field"><label for="article-edit-slug-ar">{{ copy('Slug بالعربية', 'Arabic slug') }}</label><input id="article-edit-slug-ar" v-model="form.slug_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-slug-en">{{ copy('Slug بالإنجليزية', 'English slug') }}</label><input id="article-edit-slug-en" v-model="form.slug_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field">
                    <label for="article-edit-category">{{ copy('التصنيف', 'Category') }}</label>
                    <select id="article-edit-category" v-model="form.category_public_id">
                        <option value="">{{ copy('بدون تصنيف', 'No category') }}</option>
                        <option v-for="category in categories" :key="category.public_id" :value="category.public_id">{{ locale === 'ar' ? category.name_ar : category.name_en }}</option>
                    </select>
                </div>
                <div class="content-field"><label for="article-edit-position">{{ copy('الترتيب', 'Order') }}</label><input id="article-edit-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
                <div class="content-field"><label for="article-edit-summary-ar">{{ copy('الملخص بالعربية', 'Arabic summary') }}</label><input id="article-edit-summary-ar" v-model="form.summary_ar" maxlength="600" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-summary-en">{{ copy('الملخص بالإنجليزية', 'English summary') }}</label><input id="article-edit-summary-en" v-model="form.summary_en" maxlength="600" dir="ltr"></div>
                <div class="content-field"><label for="article-edit-image">{{ copy('رابط الصورة الرئيسية (HTTPS)', 'Main image URL (HTTPS)') }}</label><input id="article-edit-image" v-model="form.image_path" dir="ltr" placeholder="https://"></div>
                <div class="content-field"><label for="article-edit-image-alt-ar">{{ copy('نص بديل للصورة بالعربية', 'Arabic image alt') }}</label><input id="article-edit-image-alt-ar" v-model="form.image_alt_ar" maxlength="300" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-image-alt-en">{{ copy('نص بديل للصورة بالإنجليزية', 'English image alt') }}</label><input id="article-edit-image-alt-en" v-model="form.image_alt_en" maxlength="300" dir="ltr"></div>
                <div class="content-field"><label for="article-edit-seo-ar">{{ copy('عنوان SEO بالعربية', 'Arabic SEO title') }}</label><input id="article-edit-seo-ar" v-model="form.seo_title_ar" maxlength="200" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-seo-en">{{ copy('عنوان SEO بالإنجليزية', 'English SEO title') }}</label><input id="article-edit-seo-en" v-model="form.seo_title_en" maxlength="200" dir="ltr"></div>
                <div class="content-field"><label for="article-edit-seo-desc-ar">{{ copy('وصف SEO بالعربية', 'Arabic SEO description') }}</label><input id="article-edit-seo-desc-ar" v-model="form.seo_description_ar" maxlength="300" dir="rtl"></div>
                <div class="content-field"><label for="article-edit-seo-desc-en">{{ copy('وصف SEO بالإنجليزية', 'English SEO description') }}</label><input id="article-edit-seo-desc-en" v-model="form.seo_description_en" maxlength="300" dir="ltr"></div>
            </div>
            <RichTextEditor v-model="form.body_ar" :label="copy('المحتوى بالعربية', 'Arabic content')" dir="rtl" />
            <RichTextEditor v-model="form.body_en" :label="copy('المحتوى بالإنجليزية', 'English content')" dir="ltr" />
        </form>

        <section class="content-revisions">
            <h2>{{ copy('المراجعات', 'Revisions') }}</h2>
            <ul>
                <li v-for="revision in revisions" :key="revision.revision">
                    <span dir="ltr">#{{ revision.revision }} · {{ revision.state }} · {{ revision.created_at?.slice(0, 19).replace('T', ' ') }}</span>
                    <button v-if="editor?.canManage" class="content-action" type="button" :disabled="busy" @click="restoreRevision(revision.revision)">{{ copy('استعادة كمسودة', 'Restore as draft') }}</button>
                </li>
                <li v-if="revisions.length === 0">{{ copy('لا توجد مراجعات بعد.', 'No revisions yet.') }}</li>
            </ul>
        </section>
    </OperationsShell>
</template>
