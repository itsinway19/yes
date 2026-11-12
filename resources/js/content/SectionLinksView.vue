<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import type { LinkCommand } from './api';
import { deleteLink, reorderLinks, saveLink, updateLink } from './api';
import { links, readLinks, type LinksData } from './state';

type LinkRow = LinksData['links'][number];
type LinkType = 'page' | 'article' | 'internal' | 'external';

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const route = useRoute();
const sectionId = computed(() => String(route.params.section));
const rows = computed(() => links.value?.links ?? []);
const choices = computed(() => links.value?.choices);
const showForm = ref(false);
const busy = ref(false);
const failure = ref('');
const form = reactive({ public_id: '', link_type: 'page' as LinkType, target: '', internal_path: '/',
    external_url: '', label_ar: '', label_en: '', position: 0, visible: true, open_in_new_tab: false });
const editing = computed(() => form.public_id !== '');

const typeLabel = (type: LinkType) => ({
    page: copy('صفحة ثابتة', 'Static page'), article: copy('مقالة', 'Article'),
    internal: copy('رابط داخلي', 'Internal link'), external: copy('رابط خارجي', 'External link'),
})[type];

const targetChoices = computed(() => form.link_type === 'page' ? (choices.value?.pages ?? [])
    : form.link_type === 'article' ? (choices.value?.articles ?? []) : []);

const previewUrl = computed(() => {
    const language = locale.value === 'ar' ? 'url_ar' : 'url_en';
    if (form.link_type === 'internal') return form.internal_path;
    if (form.link_type === 'external') return form.external_url;
    return targetChoices.value.find(choice => choice.public_id === form.target)?.[language] ?? '';
});

function beginCreate() {
    Object.assign(form, { public_id: '', link_type: 'page', target: '', internal_path: '/', external_url: '',
        label_ar: '', label_en: '', position: 0, visible: true, open_in_new_tab: false });
    failure.value = '';
    showForm.value = true;
}

function beginEdit(row: LinkRow) {
    Object.assign(form, { public_id: row.public_id, link_type: row.link_type as LinkType,
        target: row.link_type === 'page' || row.link_type === 'article' ? (row.target ?? '') : '',
        internal_path: row.link_type === 'internal' ? (row.internal_path ?? '/') : '/',
        external_url: row.link_type === 'external' ? (row.external_url ?? '') : '',
        label_ar: row.label_ar ?? '', label_en: row.label_en ?? '', position: row.position,
        visible: row.visible, open_in_new_tab: row.open_in_new_tab });
    failure.value = '';
    showForm.value = true;
}

function cancel() {
    showForm.value = false;
    failure.value = '';
}

function payload(): LinkCommand {
    return {
        link_type: form.link_type,
        target: form.link_type === 'page' || form.link_type === 'article' ? form.target || null : null,
        internal_path: form.link_type === 'internal' ? form.internal_path : null,
        external_url: form.link_type === 'external' ? form.external_url.trim() : null,
        label_ar: form.label_ar.trim() || null, label_en: form.label_en.trim() || null,
        position: Number(form.position) || 0, visible: form.visible, open_in_new_tab: form.open_in_new_tab,
    };
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    try {
        if (form.public_id) {
            await updateLink(form.public_id, { ...payload(), section: sectionId.value });
        } else {
            await saveLink(sectionId.value, payload());
        }
        await readLinks(sectionId.value);
        showForm.value = false;
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ الرابط.', 'The link could not be saved.');
    } finally {
        busy.value = false;
    }
}

async function remove(row: LinkRow) {
    if (!confirm(copy('حذف هذا الرابط من القسم؟', 'Remove this link from the section?'))) return;
    failure.value = '';
    try {
        await deleteLink(row.public_id);
        await readLinks(sectionId.value);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حذف الرابط.', 'The link could not be deleted.');
    }
}

async function move(row: LinkRow, direction: -1 | 1) {
    const index = rows.value.findIndex(item => item.public_id === row.public_id);
    const neighbour = rows.value[index + direction];
    if (!neighbour) return;
    failure.value = '';
    try {
        await reorderLinks(sectionId.value, [
            { public_id: row.public_id, position: neighbour.position },
            { public_id: neighbour.public_id, position: row.position },
        ]);
        await readLinks(sectionId.value);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ الترتيب.', 'The order could not be saved.');
    }
}

function label(row: LinkRow) {
    const language = locale.value === 'ar' ? 'ar' : 'en';
    return row[`label_${language}` as 'label_ar' | 'label_en']
        ?? row[`target_title_${language}` as 'target_title_ar' | 'target_title_en'] ?? '—';
}
</script>

<template>
    <OperationsShell>
        <template #header>
            <p><RouterLink to="/internal/administration/content/sections">← {{ copy('الأقسام والروابط', 'Sections and links') }}</RouterLink></p>
            <h1>{{ copy('روابط القسم', 'Section links') }} — {{ links ? (locale === 'ar' ? links.section.name_ar : links.section.name_en) : '' }}</h1>
            <p>{{ copy('اربط صفحات ومقالات وروابط داخلية وخارجية. ترتيب الرابط مستقل عن ترتيب المحتوى.', 'Attach pages, articles, internal and external links. Link order is independent of content order.') }}</p>
            <button class="content-action content-action--primary" type="button" data-content-add-link @click="beginCreate">+ {{ copy('إضافة رابط', 'Add link') }}</button>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>

        <form v-if="showForm" class="content-form" data-content-link-form @submit.prevent="submit">
            <h2>{{ editing ? copy('تعديل الرابط', 'Edit link') : copy('رابط جديد', 'New link') }}</h2>
            <div class="content-grid">
                <div class="content-field">
                    <label for="link-type">{{ copy('نوع الرابط', 'Link type') }}</label>
                    <select id="link-type" v-model="form.link_type">
                        <option value="page">{{ copy('صفحة ثابتة', 'Static page') }}</option>
                        <option value="article">{{ copy('مقالة', 'Article') }}</option>
                        <option value="internal">{{ copy('رابط داخلي', 'Internal link') }}</option>
                        <option value="external">{{ copy('رابط خارجي', 'External link') }}</option>
                    </select>
                </div>
                <div v-if="form.link_type === 'page' || form.link_type === 'article'" class="content-field">
                    <label for="link-target">{{ copy('الوجهة', 'Destination') }}</label>
                    <select id="link-target" v-model="form.target" required>
                        <option value="">{{ copy('اختر', 'Select') }}</option>
                        <option v-for="choice in targetChoices" :key="choice.public_id" :value="choice.public_id">
                            {{ locale === 'ar' ? choice.title_ar : choice.title_en }}{{ choice.published ? '' : ` (${copy('غير منشور', 'unpublished')})` }}
                        </option>
                    </select>
                </div>
                <div v-if="form.link_type === 'internal'" class="content-field">
                    <label for="link-internal">{{ copy('المسار الداخلي', 'Internal path') }}</label>
                    <select id="link-internal" v-model="form.internal_path">
                        <option v-for="path in choices?.internal_paths ?? []" :key="path.path" :value="path.path">{{ locale === 'ar' ? path.label_ar : path.label_en }} — {{ path.path }}</option>
                    </select>
                </div>
                <div v-if="form.link_type === 'external'" class="content-field">
                    <label for="link-external">{{ copy('الرابط الخارجي (HTTPS)', 'External URL (HTTPS)') }}</label>
                    <input id="link-external" v-model="form.external_url" type="url" required dir="ltr" placeholder="https://">
                </div>
                <div class="content-field"><label for="link-label-ar">{{ copy('عنوان مخصص بالعربية', 'Custom Arabic label') }}</label><input id="link-label-ar" v-model="form.label_ar" maxlength="120" dir="rtl"></div>
                <div class="content-field"><label for="link-label-en">{{ copy('عنوان مخصص بالإنجليزية', 'Custom English label') }}</label><input id="link-label-en" v-model="form.label_en" maxlength="120" dir="ltr"></div>
                <div class="content-field"><label for="link-position">{{ copy('ترتيب الظهور', 'Display order') }}</label><input id="link-position" v-model.number="form.position" type="number" min="0" max="1000000" dir="ltr"></div>
            </div>
            <label class="content-check"><input v-model="form.visible" type="checkbox"> {{ copy('ظاهر', 'Visible') }}</label>
            <label class="content-check"><input v-model="form.open_in_new_tab" type="checkbox"> {{ copy('فتح في تبويب جديد', 'Open in a new tab') }}</label>
            <div class="content-preview">
                <strong>{{ copy('معاينة الرابط النهائي', 'Final link preview') }}</strong>
                <a :href="previewUrl || '#'" target="_blank" rel="noopener noreferrer">{{ previewUrl || copy('أكمل بيانات الوجهة', 'Complete the destination') }}</a>
            </div>
            <div class="content-actions">
                <button class="content-action content-action--primary" type="submit" :disabled="busy">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ الرابط', 'Save link') }}</button>
                <button class="content-action" type="button" :disabled="busy" @click="cancel">{{ copy('إلغاء', 'Cancel') }}</button>
            </div>
        </form>

        <table class="content-table" data-content-links>
            <thead><tr>
                <th>{{ copy('العنوان', 'Label') }}</th><th>{{ copy('النوع', 'Type') }}</th><th>{{ copy('الوجهة', 'Destination') }}</th>
                <th>{{ copy('الترتيب', 'Order') }}</th><th>{{ copy('الحالة', 'Status') }}</th><th>{{ copy('الإجراءات', 'Actions') }}</th>
            </tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.public_id">
                    <td><strong>{{ label(row) }}</strong></td>
                    <td>{{ typeLabel(row.link_type as LinkType) }}</td>
                    <td><a :href="row.preview_url ?? '#'" target="_blank" rel="noopener noreferrer" dir="ltr">{{ row.preview_url }}</a>
                        <p v-if="!row.target_published" class="content-note">{{ copy('الهدف غير منشور — لن يظهر للزوار.', 'Target is unpublished — it will not appear publicly.') }}</p>
                    </td>
                    <td dir="ltr">{{ row.position }}</td>
                    <td><span class="content-badge" :data-state="row.visible ? 'published' : 'draft'">{{ row.visible ? copy('ظاهر', 'Visible') : copy('مخفي', 'Hidden') }}</span></td>
                    <td><div class="content-actions">
                        <button class="content-action" type="button" @click="beginEdit(row)">{{ copy('تعديل', 'Edit') }}</button>
                        <button class="content-action" type="button" @click="move(row, -1)">↑</button>
                        <button class="content-action" type="button" @click="move(row, 1)">↓</button>
                        <button class="content-action content-action--danger" type="button" @click="remove(row)">{{ copy('حذف', 'Delete') }}</button>
                    </div></td>
                </tr>
                <tr v-if="rows.length === 0"><td colspan="6">{{ copy('لا توجد روابط في هذا القسم.', 'This section has no links yet.') }}</td></tr>
            </tbody>
        </table>
    </OperationsShell>
</template>
