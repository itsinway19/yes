<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { createArticle, deleteArticle, restoreArticle } from './api';
import { articles, readArticles, type ArticlesData, type ListQuery } from './state';

type ArticleRow = ArticlesData['rows'][number];

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const route = useRoute();
const router = useRouter();
const rows = computed(() => articles.value?.rows ?? []);
const total = computed(() => articles.value?.total ?? 0);
const search = ref(typeof route.query.search === 'string' ? route.query.search : '');
const status = ref<ListQuery['status']>(
    route.query.status === 'draft' || route.query.status === 'published' || route.query.status === 'deleted'
        ? route.query.status : '');
const showForm = ref(false);
const busy = ref(false);
const failure = ref('');
const form = reactive({ title_ar: '', title_en: '', slug_ar: '', slug_en: '', position: 0 });

function filter() {
    void router.replace({
        query: {
            ...(search.value ? { search: search.value } : {}),
            ...(status.value ? { status: status.value } : {}),
        },
    });
}

function beginCreate() {
    Object.assign(form, { title_ar: '', title_en: '', slug_ar: '', slug_en: '', position: 0 });
    failure.value = '';
    showForm.value = true;
}

async function create() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    try {
        const result = await createArticle({
            title_ar: form.title_ar.trim(), title_en: form.title_en.trim(),
            slug_ar: form.slug_ar.trim(), slug_en: form.slug_en.trim(),
            excerpt_ar: null, excerpt_en: null, body_ar: '', body_en: '',
            seo_title_ar: null, seo_title_en: null, seo_description_ar: null, seo_description_en: null,
            summary_ar: null, summary_en: null, image_path: null, image_alt_ar: null, image_alt_en: null,
            category_public_id: null, position: Number(form.position) || 0,
        });
        showForm.value = false;
        await router.push(`/internal/administration/content/articles/${result.article.article.public_id}`);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر إنشاء المقالة.', 'The article could not be created.');
    } finally {
        busy.value = false;
    }
}

async function remove(row: ArticleRow) {
    if (!confirm(copy('حذف المقالة منطقيًا؟ يمكن استعادتها لاحقًا.', 'Soft-delete this article? It can be restored later.'))) return;
    failure.value = '';
    try {
        await deleteArticle(row.public_id);
        await readArticles({ search: search.value, status: status.value }, false, true);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حذف المقالة.', 'The article could not be deleted.');
    }
}

async function restore(row: ArticleRow) {
    failure.value = '';
    try {
        await restoreArticle(row.public_id);
        await readArticles({ search: search.value, status: status.value }, false, true);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر استعادة المقالة.', 'The article could not be restored.');
    }
}
</script>

<template>
    <OperationsShell>
        <template #header>
            <h1>{{ copy('المقالات', 'Articles') }}</h1>
            <p>{{ copy('أنشئ المقالات واربطها بالتصنيفات. المسودة لا تغيّر النسخة المنشورة حتى تعتمدها.', 'Create articles and link them to categories. A draft never changes the published version until approved.') }}</p>
            <div class="content-actions">
                <button class="content-action content-action--primary" type="button" data-content-add-article @click="beginCreate">+ {{ copy('إضافة مقالة', 'Add article') }}</button>
                <RouterLink class="content-action" to="/internal/administration/content/categories">{{ copy('إدارة التصنيفات', 'Manage categories') }}</RouterLink>
            </div>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>

        <form v-if="showForm" class="content-form" data-content-article-create @submit.prevent="create">
            <h2>{{ copy('مقالة جديدة', 'New article') }}</h2>
            <div class="content-grid">
                <div class="content-field"><label for="article-title-ar">{{ copy('العنوان بالعربية', 'Arabic title') }}</label><input id="article-title-ar" v-model="form.title_ar" required maxlength="200" dir="rtl"></div>
                <div class="content-field"><label for="article-title-en">{{ copy('العنوان بالإنجليزية', 'English title') }}</label><input id="article-title-en" v-model="form.title_en" required maxlength="200" dir="ltr"></div>
                <div class="content-field"><label for="article-slug-ar">{{ copy('Slug بالعربية', 'Arabic slug') }}</label><input id="article-slug-ar" v-model="form.slug_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="article-slug-en">{{ copy('Slug بالإنجليزية', 'English slug') }}</label><input id="article-slug-en" v-model="form.slug_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field"><label for="article-position">{{ copy('الترتيب', 'Order') }}</label><input id="article-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
            </div>
            <div class="content-actions">
                <button class="content-action content-action--primary" type="submit" :disabled="busy">{{ busy ? copy('جارٍ الإنشاء…', 'Creating…') : copy('إنشاء وفتح المحرر', 'Create and open editor') }}</button>
                <button class="content-action" type="button" :disabled="busy" @click="showForm = false">{{ copy('إلغاء', 'Cancel') }}</button>
            </div>
        </form>

        <div class="content-toolbar">
            <input v-model="search" type="search" :placeholder="copy('بحث بالعنوان أو Slug', 'Search title or slug')" :aria-label="copy('بحث', 'Search')" @keyup.enter="filter">
            <select v-model="status" :aria-label="copy('الحالة', 'Status')" @change="filter">
                <option value="">{{ copy('كل الحالات', 'All statuses') }}</option>
                <option value="published">{{ copy('منشور', 'Published') }}</option>
                <option value="draft">{{ copy('مسودة', 'Draft') }}</option>
                <option value="deleted">{{ copy('محذوف', 'Deleted') }}</option>
            </select>
            <button class="content-action" type="button" @click="filter">{{ copy('تصفية', 'Filter') }}</button>
            <span class="content-note">{{ copy('الإجمالي', 'Total') }}: {{ total }}</span>
        </div>

        <table class="content-table" data-content-articles>
            <thead><tr>
                <th>{{ copy('العنوان', 'Title') }}</th><th>{{ copy('التصنيف', 'Category') }}</th>
                <th>{{ copy('الحالة', 'Status') }}</th><th>{{ copy('آخر تعديل', 'Updated') }}</th><th>{{ copy('الإجراءات', 'Actions') }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.public_id">
                    <td><strong>{{ locale === 'ar' ? row.title_ar : row.title_en }}</strong></td>
                    <td>{{ (locale === 'ar' ? row.category_name_ar : row.category_name_en) ?? '—' }}</td>
                    <td><span class="content-badge" :data-state="row.status">{{ row.status === 'published' ? copy('منشور', 'Published') : row.status === 'draft' ? copy('مسودة', 'Draft') : copy('محذوف', 'Deleted') }}</span></td>
                    <td dir="ltr">{{ row.updated_at?.slice(0, 10) }}</td>
                    <td><div class="content-actions">
                        <RouterLink class="content-action" :to="`/internal/administration/content/articles/${row.public_id}`">{{ copy('فتح', 'Open') }}</RouterLink>
                        <button v-if="!row.deleted" class="content-action content-action--danger" type="button" @click="remove(row)">{{ copy('حذف', 'Delete') }}</button>
                        <button v-else class="content-action" type="button" @click="restore(row)">{{ copy('استعادة', 'Restore') }}</button>
                    </div></td>
                </tr>
                <tr v-if="rows.length === 0"><td colspan="5">{{ copy('لا توجد مقالات مطابقة.', 'No matching articles.') }}</td></tr>
            </tbody>
        </table>
    </OperationsShell>
</template>
