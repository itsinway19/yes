import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { supportedLocale } from './messages';
const copy = {
    eyebrow: ['هدف النشاط', 'Business goals'], title: ['ما هدفك التجاري الآن؟', 'What is your business goal now?'],
    intro: ['اختر الهدف أو الأهداف التي تريد تحقيقها من إعلاناتك.', 'Choose the goal or goals you want to achieve through your advertising.'],
    edit: ['تعديل', 'Edit'], selected: ['الأهداف المحددة', 'Selected goals'], priority: ['أولوية البداية', 'Starting priority'],
    makePriority: ['تعيين كأولوية البداية', 'Set as starting priority'], remove: ['إلغاء اختيار', 'Remove selection'],
    viewResults: ['عرض النتائج', 'View outcomes'],
    results: ['النتيجة المطلوبة', 'Desired outcome'], optional: ['اختياري؛ يمكنك اختيار عدة نتائج.', 'Optional; you can select several outcomes.'],
    empty: ['لم يتم اختيار أي هدف.', 'No goal selected.'], choosePriority: ['حدد أولوية بداية من الأهداف المحددة قبل المتابعة.', 'Choose a starting priority from your selected goals before continuing.'],
    previous: ['السابق', 'Previous'], continue: ['متابعة', 'Continue'], saving: ['جارٍ الحفظ…', 'Saving…'],
    readonly: ['يمكنك مشاهدة الأهداف المحفوظة؛ تعديلها متاح لأعضاء النشاط المخوّلين فقط.', 'You can view saved goals; only authorized business members can edit them.'],
    unsaved: ['لديك اختيارات غير محفوظة. هل تريد مغادرة الصفحة؟', 'You have unsaved selections. Leave this page?'],
    failed: ['تعذر تأكيد الحفظ. احتفظنا باختياراتك؛ راجع الحالة المحفوظة قبل المحاولة مجددًا.', 'Could not confirm save. Your selections are retained; review saved state before trying again.'],
    denied: ['لم يعد لديك إذن لحفظ هذه الاختيارات. احتفظنا بالمسودة.', 'You no longer have permission to save these selections. The draft is retained.'],
    conflict: ['تغيرت الأهداف المحفوظة. راجع النسخة الحالية قبل إعادة الحفظ؛ مسودتك لم تتغير.', 'Saved goals have changed. Review the current version before saving again; your draft is unchanged.'],
    review: ['مراجعة النسخة المحفوظة', 'Review saved version'], adopt: ['اعتماد المراجعة للحفظ بهذه المسودة', 'Use reviewed revision to save this draft'],
    savedVersion: ['الأهداف المحفوظة حاليًا', 'Currently saved goals'], selectLabel: ['اختيار الهدف', 'Select goal'],
} as const;
export function useGoalsCopy() {
    const page = usePage();
    const locale = computed(() => supportedLocale(page.props.locale));
    const t = (key: keyof typeof copy): string => copy[key][locale.value === 'ar' ? 0 : 1];
    const localized = (value: { name_ar: string; name_en: string }) => locale.value === 'ar' ? value.name_ar : value.name_en;
    return { locale, t, localized };
}
