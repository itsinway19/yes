import { ref } from 'vue';
import { bootstrap } from './transport';
import { api } from './api';
import { applyDocumentLocale } from '../localization/documentLocale';

export const locale = ref(bootstrap.locale);
export const localeBusy = ref(false);
export async function switchLocale() {
    if (localeBusy.value) return;
    localeBusy.value = true;
    try {
        const result = await api.POST('/locale', { body: { locale: locale.value === 'ar' ? 'en' : 'ar' } });
        if (!result.data) throw Error('Missing locale acknowledgement');
        locale.value = result.data.locale;
        applyDocumentLocale(locale.value, document.documentElement);
    } finally { localeBusy.value = false; }
}
