export const messages = {
    ar: {
        language: {
            arabic: 'العربية',
            english: 'English',
            switchToArabic: 'تغيير اللغة إلى العربية',
            switchToEnglish: 'تغيير اللغة إلى الإنجليزية',
        },
        login: {
            title: 'تسجيل الدخول',
            home: 'الصفحة الرئيسية',
            email: 'البريد الإلكتروني',
            password: 'كلمة المرور',
            remember: 'تذكرني',
            forgotPassword: 'نسيت كلمة المرور؟',
            register: 'ليس لديك حساب؟',
            submit: 'تسجيل الدخول',
            submitting: 'جارٍ الدخول…',
            passkey: 'الدخول بمفتاح المرور',
            passkeyLoading: 'جارٍ التحقق…',
            passkeyCancel: 'إلغاء محاولة مفتاح المرور',
            passkeyCancelRequested: 'طُلب إلغاء التحقق. انتظر انتهاء العملية الحالية قبل المحاولة مجددًا.',
            passkeyCancelled: 'لم تكتمل عملية مفتاح المرور. يمكنك المحاولة مجددًا.',
            passkeyUnsupported: 'هذا المتصفح لا يدعم مفاتيح المرور. استخدم متصفحًا يدعمها.',
            passkeySecurityContext: 'لا يمكن استخدام مفتاح المرور في سياق الأمان الحالي. تحقق من عنوان الموقع والاتصال الآمن ثم حاول مجددًا.',
            passkeyFailed: 'تعذّر تسجيل الدخول بمفتاح المرور. حاول مجددًا.',
            internalTitle: 'دخول الإدارة الداخلية',
            internalIntro: 'هذا الدخول مخصص للحسابات الداخلية المفعّلة. استخدم مفتاح المرور المسجّل لحسابك الداخلي.',
            advertiserLogin: 'دخول المعلنين',
        },
    },
    en: {
        language: {
            arabic: 'العربية',
            english: 'English',
            switchToArabic: 'Switch language to Arabic',
            switchToEnglish: 'Switch language to English',
        },
        login: {
            title: 'Sign in',
            home: 'Home',
            email: 'Email address',
            password: 'Password',
            remember: 'Remember me',
            forgotPassword: 'Forgot your password?',
            register: 'Need an account?',
            submit: 'Sign in',
            submitting: 'Signing in…',
            passkey: 'Sign in with a passkey',
            passkeyLoading: 'Verifying…',
            passkeyCancel: 'Cancel passkey attempt',
            passkeyCancelRequested: 'Cancellation requested. Wait for the current operation to finish before trying again.',
            passkeyCancelled: 'The passkey operation was not completed. You can try again.',
            passkeyUnsupported: 'This browser does not support passkeys. Use a browser that supports them.',
            passkeySecurityContext: 'Passkeys cannot be used in this security context. Check the site address and secure connection, then try again.',
            passkeyFailed: 'Could not sign in with a passkey. Please try again.',
            internalTitle: 'Internal staff sign in',
            internalIntro: 'This sign in is for active internal staff accounts. Use the passkey registered to your staff account.',
            advertiserLogin: 'Advertiser sign in',
        },
    },
} as const;

export type Locale = keyof typeof messages;

export function supportedLocale(value: unknown): Locale {
    return value === 'en' ? 'en' : 'ar';
}

export function otherLocale(locale: Locale): Locale {
    return locale === 'ar' ? 'en' : 'ar';
}
