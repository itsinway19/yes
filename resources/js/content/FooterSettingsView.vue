<script setup lang="ts">
import { reactive, ref } from 'vue';
import OperationsShell from '../internal-operations/OperationsShell.vue';
import { locale } from '../spa/locale';
import { saveFooter } from './api';
import { footer, readFooter } from './state';

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;
const busy = ref(false);
const failure = ref('');
const saved = ref(false);
const current = footer;
const revision = ref(current.value?.footer.revision ?? 0);
const form = reactive({
    description_ar: current.value?.footer.description_ar ?? '',
    description_en: current.value?.footer.description_en ?? '',
    copyright_ar: current.value?.footer.copyright_ar ?? '',
    copyright_en: current.value?.footer.copyright_en ?? '',
    show_sections: current.value?.footer.show_sections ?? true,
    social_links: (current.value?.footer.social_links ?? []).map(link => ({ ...link })),
});

function addSocial() {
    if (form.social_links.length >= 20) return;
    form.social_links.push({ label_ar: '', label_en: '', url: '' });
}

function removeSocial(index: number) {
    form.social_links.splice(index, 1);
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    failure.value = '';
    saved.value = false;
    try {
        const result = await saveFooter({
            revision: revision.value,
            description_ar: form.description_ar.trim() || null, description_en: form.description_en.trim() || null,
            copyright_ar: form.copyright_ar.trim() || null, copyright_en: form.copyright_en.trim() || null,
            show_sections: form.show_sections,
            social_links: form.social_links
                .filter(link => /^https:\/\//.test(link.url))
                .map(link => ({ label_ar: link.label_ar || null, label_en: link.label_en || null, url: link.url })),
        });
        revision.value = result.footer.footer.revision;
        await readFooter();
        saved.value = true;
    } catch (error) {
        failure.value = error instanceof Error ? error.message : copy('تعذر حفظ إعدادات الفوتر.', 'Footer settings could not be saved.');
    } finally {
        busy.value = false;
    }
}


</script>

<template>
    <OperationsShell>
        <template #header>
            <h1>{{ copy('إعدادات الفوتر', 'Footer settings') }}</h1>
            <p>{{ copy('وصف الشركة والحقوق وروابط التواصل. الأقسام تُدار من شاشة الأقسام والروابط، والشعار من نظام الهوية الحالي.', 'Company description, copyright and social links. Sections are managed in Sections and links; the logo comes from the existing identity system.') }}</p>
        </template>

        <p v-if="failure" class="content-error" role="alert">{{ failure }}</p>
        <p v-if="saved" role="status" class="content-note">{{ copy('حُفظت الإعدادات ونُشرت على الفوتر العام.', 'Settings saved and published to the public footer.') }}</p>

        <form class="content-form" data-content-footer-form @submit.prevent="submit">
            <fieldset :disabled="!footer?.canManage" style="border:0;padding:0;margin:0">
            <h2>{{ copy('محتوى الفوتر', 'Footer content') }} <span class="content-note" dir="ltr">rev {{ revision }}</span></h2>
            <div class="content-grid">
                <div class="content-field"><label for="footer-desc-ar">{{ copy('وصف الشركة بالعربية', 'Arabic company description') }}</label><textarea id="footer-desc-ar" v-model="form.description_ar" maxlength="2000" dir="rtl"></textarea></div>
                <div class="content-field"><label for="footer-desc-en">{{ copy('وصف الشركة بالإنجليزية', 'English company description') }}</label><textarea id="footer-desc-en" v-model="form.description_en" maxlength="2000" dir="ltr"></textarea></div>
                <div class="content-field"><label for="footer-copy-ar">{{ copy('نص الحقوق بالعربية', 'Arabic copyright') }}</label><input id="footer-copy-ar" v-model="form.copyright_ar" maxlength="300" dir="rtl"></div>
                <div class="content-field"><label for="footer-copy-en">{{ copy('نص الحقوق بالإنجليزية', 'English copyright') }}</label><input id="footer-copy-en" v-model="form.copyright_en" maxlength="300" dir="ltr"></div>
            </div>
            <label class="content-check"><input v-model="form.show_sections" type="checkbox"> {{ copy('إظهار أقسام الفوتر', 'Show footer sections') }}</label>

            <fieldset class="content-form" style="margin:0">
                <legend>{{ copy('روابط التواصل الاجتماعي', 'Social links') }}</legend>
                <div v-for="(link, index) in form.social_links" :key="index" class="content-grid content-grid--three">
                    <div class="content-field"><label :for="`social-label-ar-${index}`">{{ copy('التسمية بالعربية', 'Arabic label') }}</label><input :id="`social-label-ar-${index}`" v-model="link.label_ar" maxlength="120" dir="rtl"></div>
                    <div class="content-field"><label :for="`social-label-en-${index}`">{{ copy('التسمية بالإنجليزية', 'English label') }}</label><input :id="`social-label-en-${index}`" v-model="link.label_en" maxlength="120" dir="ltr"></div>
                    <div class="content-field"><label :for="`social-url-${index}`">{{ copy('الرابط (HTTPS)', 'URL (HTTPS)') }}</label><input :id="`social-url-${index}`" v-model="link.url" dir="ltr" placeholder="https://"></div>
                    <div class="content-actions"><button class="content-action content-action--danger" type="button" @click="removeSocial(index)">{{ copy('إزالة', 'Remove') }}</button></div>
                </div>
                <p v-if="form.social_links.length === 0" class="content-note">{{ copy('لا توجد روابط اجتماعية.', 'No social links.') }}</p>
                <button class="content-action" type="button" @click="addSocial">{{ copy('إضافة رابط اجتماعي', 'Add social link') }}</button>
            </fieldset>

            <div class="content-actions">
                <button class="content-action content-action--primary" type="submit" :disabled="busy">{{ busy ? copy('جارٍ الحفظ…', 'Saving…') : copy('حفظ ونشر الفوتر', 'Save and publish footer') }}</button>
                <a class="content-action" href="/" target="_blank" rel="noopener">{{ copy('عرض الموقع', 'View site') }}</a>
            </div>
            </fieldset>
        </form>
    </OperationsShell>
</template>
