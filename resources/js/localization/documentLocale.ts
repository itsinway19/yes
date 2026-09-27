export function applyDocumentLocale(locale: 'ar' | 'en', root: { lang: string; dir: string }): void {
    root.lang = locale;
    root.dir = locale === 'ar' ? 'rtl' : 'ltr';
}
