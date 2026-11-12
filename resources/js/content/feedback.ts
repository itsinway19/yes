import { locale } from '../spa/locale';
import { TransportError } from '../spa/transport';

/** Localized command failure. The server message stays technical; the UI explains the recovery. */
export class ContentCommandError extends Error {
    constructor(message: string, readonly reauthenticate?: string, readonly status?: number) {
        super(message);
        this.name = 'ContentCommandError';
    }
}

const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;

export function describeFailure(error: unknown, fallbackAr: string, fallbackEn: string): ContentCommandError {
    if (error instanceof ContentCommandError) return error;
    if (error instanceof TransportError) {
        const body = error.body as { code?: string; reauthenticate?: string; errors?: Record<string, string[]> } | undefined;
        if (error.status === 403 && body?.code === 'fresh_authentication_required') {
            return new ContentCommandError(
                copy('تحتاج هذه العملية إلى مصادقة حديثة بمفتاح المرور. لم يُحفظ أي تغيير. أعد المصادقة ثم احفظ مجددًا.',
                    'This change needs fresh passkey authentication. Nothing was saved. Reauthenticate, then save again.'),
                body.reauthenticate ?? '/internal/identity/switch', error.status);
        }
        if (error.status === 403) {
            return new ContentCommandError(copy('لا تملك صلاحية تنفيذ هذه العملية.', 'You are not allowed to perform this operation.'), undefined, 403);
        }
        if (error.status === 409) {
            return new ContentCommandError(copy('تغيّر هذا السجل في جلسة أخرى. أعد تحميل الصفحة قبل المحاولة.',
                'This record changed in another session. Reload the page before retrying.'), undefined, 409);
        }
        if (error.status === 419) {
            return new ContentCommandError(copy('انتهت صلاحية الجلسة. أعد تحميل الصفحة.', 'Your session expired. Reload the page.'), undefined, 419);
        }
        if (error.status === 422) {
            const labels: Record<string, [string, string]> = {
                slug_ar: ['الرابط العربي', 'Arabic slug'], slug_en: ['الرابط الإنجليزي', 'English slug'],
                title_ar: ['العنوان العربي', 'Arabic title'], title_en: ['العنوان الإنجليزي', 'English title'],
                category_public_id: ['التصنيف', 'Category'], revision: ['المراجعة', 'Revision'],
            };
            const fields = Object.keys(body?.errors ?? {}).slice(0, 4).map(field => {
                const label = labels[field];
                return label ? copy(label[0], label[1]) : field.replaceAll('_', ' ');
            });
            const message = copy('راجع الحقول التالية؛ بقيت مدخلاتك كما هي: ',
                'Review these fields; your input is still here: ');
            return new ContentCommandError(fields.length ? message + fields.join('، ') :
                copy('راجع المدخلات؛ بقيت كما هي.', 'Review your input; it is still here.'), undefined, 422);
        }
        if (error.status === 404) {
            return new ContentCommandError(copy('لم يعد هذا السجل موجودًا.', 'This record no longer exists.'), undefined, 404);
        }
    }
    return new ContentCommandError(copy(fallbackAr, fallbackEn));
}
