<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { deleteSection, reorderSections, saveSection } from './api';
import { readSections, sections, type SectionsData } from './state';

type Section = SectionsData['sections'][number];
type Placement = 'header' | 'footer';

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const rows = computed(() => sections.value?.sections ?? []);
const showForm = ref(false);
const busy = ref(false);
const failure = ref('');
const form = reactive({ public_id: '', name_ar: '', name_en: '', placement: 'header' as Placement, position: 0, visible: true });
const editing = computed(() => form.public_id !== '');
const placementLabel = (placement: string) => placement === 'header'
    ? copy('رأس الموقع', 'Site header') : copy('الفوتر', 'Footer');

function beginCreate() {
    Object.assign(form, { public_id: '', name_ar: '', name_en: '', placement: 'header', position: 0, visible: true });
    failure.value = '';
    showForm.value = true;
}

function beginEdit(section: Section) {
    Object.assign(form, { public_id: section.public_id, name_ar: section.name_ar, name_en: section.name_en,
        placement: section.placement as Placement, position: section.position, visible: section.visible });
    failure.value = '';
    showForm.value = true;
}

function cancel() {
    showForm.value = false;
    failure.value = '';
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    try {
        await saveSection({
            name_ar: form.name_ar.trim(), name_en: form.name_en.trim(), placement: form.placement,
            position: Number(form.position) || 0, visible: form.visible,
        }, form.public_id || undefined);
        await readSections();
        showForm.value = false;
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ القسم.', 'The section could not be saved.');
    } finally {
        busy.value = false;
    }
}

async function remove(section: Section) {
    if (!confirm(copy('حذف القسم وروابطه؟ لن تُحذف الصفحات الأصلية.', 'Delete this section and its links? The original pages are preserved.'))) return;
    failure.value = '';
    try {
        await deleteSection(section.public_id);
        await readSections();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حذف القسم.', 'The section could not be deleted.');
    }
}

async function move(section: Section, direction: -1 | 1) {
    const peers = rows.value.filter(row => row.placement === section.placement);
    const index = peers.findIndex(row => row.public_id === section.public_id);
    const neighbour = peers[index + direction];
    if (!neighbour) return;
    failure.value = '';
    try {
        await reorderSections([
            { public_id: section.public_id, position: neighbour.position },
            { public_id: neighbour.public_id, position: section.position },
        ]);
        await readSections();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ الترتيب.', 'The order could not be saved.');
    }
}
</script>

<template>
    <OperationsShell>
        <template #header>
            <h1>{{ copy('إدارة الأقسام والروابط', 'Sections and links') }}</h1>
            <p>{{ copy('نظّم أقسام رأس الموقع والفوتر وروابطها. إخفاء رابط أو قسم لا يحذف الصفحات الأصلية.', 'Organize header and footer sections and their links. Hiding a link or section never deletes the original pages.') }}</p>
            <button class="content-action content-action--primary" type="button" data-content-add-section @click="beginCreate">
                + {{ copy('إضافة قسم', 'Add section') }}
            </button>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>

        <form v-if="showForm" class="content-form" data-content-section-form @submit.prevent="submit">
            <h2>{{ editing ? copy('تعديل القسم', 'Edit section') : copy('قسم جديد', 'New section') }}</h2>
            <div class="content-grid">
                <div class="content-field"><label for="section-name-ar">{{ copy('اسم القسم بالعربية', 'Arabic name') }}</label><input id="section-name-ar" v-model="form.name_ar" required maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="section-name-en">{{ copy('اسم القسم بالإنجليزية', 'English name') }}</label><input id="section-name-en" v-model="form.name_en" required maxlength="120" dir="ltr"></div>
                <div class="content-field">
                    <label for="section-placement">{{ copy('مكان الظهور', 'Placement') }}</label>
                    <select id="section-placement" v-model="form.placement">
                        <option value="header">{{ copy('رأس الموقع', 'Site header') }}</option>
                        <option value="footer">{{ copy('الفوتر', 'Footer') }}</option>
                    </select>
                </div>
                <div class="content-field"><label for="section-position">{{ copy('ترتيب الظهور', 'Display order') }}</label><input id="section-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
            </div>
            <label class="content-check"><input v-model="form.visible" type="checkbox"> {{ copy('ظاهر في الموقع', 'Visible on the public site') }}</label>
            <div class="content-actions">
                <button class="content-action content-action--primary" type="submit" :disabled="busy">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ القسم', 'Save section') }}</button>
                <button class="content-action" type="button" :disabled="busy" @click="cancel">{{ copy('إلغاء', 'Cancel') }}</button>
            </div>
        </form>

        <table class="content-table" data-content-sections>
            <thead><tr>
                <th>{{ copy('القسم', 'Section') }}</th><th>{{ copy('الموقع', 'Placement') }}</th><th>{{ copy('الترتيب', 'Order') }}</th>
                <th>{{ copy('الحالة', 'Status') }}</th><th>{{ copy('الروابط', 'Links') }}</th><th>{{ copy('الإجراءات', 'Actions') }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.public_id">
                    <td><strong>{{ locale === 'ar' ? row.name_ar : row.name_en }}</strong></td>
                    <td>{{ placementLabel(row.placement) }}</td>
                    <td dir="ltr">{{ row.position }}</td>
                    <td><span class="content-badge" :data-state="row.visible ? 'published' : 'draft'">{{ row.visible ? copy('ظاهر', 'Visible') : copy('مخفي', 'Hidden') }}</span></td>
                    <td dir="ltr">{{ row.link_count }}</td>
                    <td><div class="content-actions">
                        <RouterLink class="content-action" :to="`/internal/administration/content/sections/${row.public_id}/links`">{{ copy('إدارة الروابط', 'Manage links') }}</RouterLink>
                        <button class="content-action" type="button" @click="beginEdit(row)">{{ copy('تعديل', 'Edit') }}</button>
                        <button class="content-action" type="button" @click="move(row, -1)">↑</button>
                        <button class="content-action" type="button" @click="move(row, 1)">↓</button>
                        <button class="content-action content-action--danger" type="button" @click="remove(row)">{{ copy('حذف', 'Delete') }}</button>
                    </div></td>
                </tr>
                <tr v-if="rows.length === 0"><td colspan="6">{{ copy('لا توجد أقسام بعد. أضف أول قسم.', 'No sections yet. Add the first section.') }}</td></tr>
            </tbody>
        </table>
    </OperationsShell>
</template>
