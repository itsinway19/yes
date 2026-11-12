<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { deleteCategory, saveCategory } from './api';
import { categories, readCategories, type CategoriesData } from './state';

type CategoryRow = CategoriesData['rows'][number];

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const rows = computed(() => categories.value?.rows ?? []);
const showForm = ref(false);
const busy = ref(false);
const failure = ref('');
const form = reactive({ public_id: '', slug_ar: '', slug_en: '', name_ar: '', name_en: '',
    description_ar: '', description_en: '', position: 0, visible: true });
const editing = computed(() => form.public_id !== '');

function beginCreate() {
    Object.assign(form, { public_id: '', slug_ar: '', slug_en: '', name_ar: '', name_en: '',
        description_ar: '', description_en: '', position: 0, visible: true });
    failure.value = '';
    showForm.value = true;
}

function beginEdit(row: CategoryRow) {
    Object.assign(form, { public_id: row.public_id, slug_ar: row.slug_ar, slug_en: row.slug_en,
        name_ar: row.name_ar, name_en: row.name_en, description_ar: row.description_ar ?? '',
        description_en: row.description_en ?? '', position: row.position, visible: row.visible });
    failure.value = '';
    showForm.value = true;
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    try {
        await saveCategory({
            slug_ar: form.slug_ar.trim(), slug_en: form.slug_en.trim(),
            name_ar: form.name_ar.trim(), name_en: form.name_en.trim(),
            description_ar: form.description_ar.trim() || null, description_en: form.description_en.trim() || null,
            position: Number(form.position) || 0, visible: form.visible,
        }, form.public_id || undefined);
        await readCategories();
        showForm.value = false;
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ التصنيف.', 'The category could not be saved.');
    } finally {
        busy.value = false;
    }
}

async function remove(row: CategoryRow) {
    if (row.article_count > 0) {
        failure.value = copy('لا يمكن حذف تصنيف مرتبط بمقالات. أعد تعيين المقالات أولًا.',
            'A category still assigned to articles cannot be deleted. Reassign them first.');
        return;
    }
    if (!confirm(copy('حذف هذا التصنيف؟', 'Delete this category?'))) return;
    failure.value = '';
    try {
        await deleteCategory(row.public_id);
        await readCategories();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حذف التصنيف.', 'The category could not be deleted.');
    }
}
</script>

<template>
    <OperationsShell>
        <template #header>
            <h1>{{ copy('تصنيفات المقالات', 'Article categories') }}</h1>
            <p>{{ copy('تصنيف أساسي واحد اختياري لكل مقالة. لا يمكن حذف تصنيف مرتبط بمقالات.', 'One optional primary category per article. A category assigned to articles cannot be deleted.') }}</p>
            <button class="content-action content-action--primary" type="button" data-content-add-category @click="beginCreate">+ {{ copy('إضافة تصنيف', 'Add category') }}</button>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>

        <form v-if="showForm" class="content-form" data-content-category-form @submit.prevent="submit">
            <h2>{{ editing ? copy('تعديل التصنيف', 'Edit category') : copy('تصنيف جديد', 'New category') }}</h2>
            <div class="content-grid">
                <div class="content-field"><label for="category-name-ar">{{ copy('الاسم بالعربية', 'Arabic name') }}</label><input id="category-name-ar" v-model="form.name_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="category-name-en">{{ copy('الاسم بالإنجليزية', 'English name') }}</label><input id="category-name-en" v-model="form.name_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field"><label for="category-slug-ar">{{ copy('Slug بالعربية', 'Arabic slug') }}</label><input id="category-slug-ar" v-model="form.slug_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="category-slug-en">{{ copy('Slug بالإنجليزية', 'English slug') }}</label><input id="category-slug-en" v-model="form.slug_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field"><label for="category-desc-ar">{{ copy('الوصف بالعربية', 'Arabic description') }}</label><input id="category-desc-ar" v-model="form.description_ar" maxlength="600" dir="rtl"></div>
                <div class="content-field"><label for="category-desc-en">{{ copy('الوصف بالإنجليزية', 'English description') }}</label><input id="category-desc-en" v-model="form.description_en" maxlength="600" dir="ltr"></div>
                <div class="content-field"><label for="category-position">{{ copy('الترتيب', 'Order') }}</label><input id="category-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
            </div>
            <label class="content-check"><input v-model="form.visible" type="checkbox"> {{ copy('ظاهر في الموقع', 'Visible on the public site') }}</label>
            <div class="content-actions">
                <button class="content-action content-action--primary" type="submit" :disabled="busy">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ التصنيف', 'Save category') }}</button>
                <button class="content-action" type="button" :disabled="busy" @click="showForm = false">{{ copy('إلغاء', 'Cancel') }}</button>
            </div>
        </form>

        <table class="content-table" data-content-categories>
            <thead><tr>
                <th>{{ copy('التصنيف', 'Category') }}</th><th>{{ copy('Slug', 'Slug') }}</th><th>{{ copy('الترتيب', 'Order') }}</th>
                <th>{{ copy('الحالة', 'Status') }}</th><th>{{ copy('المقالات', 'Articles') }}</th><th>{{ copy('الإجراءات', 'Actions') }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.public_id">
                    <td><strong>{{ locale === 'ar' ? row.name_ar : row.name_en }}</strong></td>
                    <td dir="ltr">/articles/category/{{ locale === 'ar' ? row.slug_ar : row.slug_en }}</td>
                    <td dir="ltr">{{ row.position }}</td>
                    <td><span class="content-badge" :data-state="row.visible ? 'published' : 'draft'">{{ row.visible ? copy('ظاهر', 'Visible') : copy('مخفي', 'Hidden') }}</span></td>
                    <td dir="ltr">{{ row.article_count }}</td>
                    <td><div class="content-actions">
                        <button class="content-action" type="button" @click="beginEdit(row)">{{ copy('تعديل', 'Edit') }}</button>
                        <button class="content-action content-action--danger" type="button" :disabled="row.article_count > 0" @click="remove(row)">{{ copy('حذف', 'Delete') }}</button>
                    </div></td>
                </tr>
                <tr v-if="rows.length === 0"><td colspan="6">{{ copy('لا توجد تصنيفات بعد.', 'No categories yet.') }}</td></tr>
            </tbody>
        </table>
    </OperationsShell>
</template>
