import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { InvalidDomainError, NotSupportedError, PasskeyError, PasskeyExistsError, Passkeys, UserCancelledError } from '@laravel/passkeys';
import { createI18n } from 'vue-i18n';
import { applyDocumentLocale } from '../resources/js/localization/documentLocale.ts';
import { messages, otherLocale, supportedLocale } from '../resources/js/localization/messages.ts';
import { passkeyLoginErrorKey } from '../resources/js/localization/passkeyError.ts';

function flatten(value, prefix = '') {
    return Object.entries(value).flatMap(([key, entry]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        return typeof entry === 'string' ? [[path, entry]] : flatten(entry, path);
    });
}

test('both dictionaries have complete, nonempty matching keys used by the login and admin slices', () => {
    const arabic = new Map(flatten(messages.ar));
    const english = new Map(flatten(messages.en));
    assert.deepEqual([...arabic.keys()].sort(), [...english.keys()].sort());
    for (const [key, value] of [...arabic, ...english]) {
        assert.ok(value.trim(), `${key} must not be empty`);
    }

    const files = [
        'resources/js/auth-entry/AuthEntryView.vue',
        'resources/js/staff-enrollment/StaffEnrollmentView.vue',
        'resources/js/Components/LanguageSwitcher.vue',
        'resources/js/Layouts/GuestLayout.vue',
        'resources/js/localization/passkeyError.ts',
        'resources/js/Layouts/InternalLayout.vue',
        'resources/js/internal-operations/AdministrationView.vue',
        'resources/js/internal-operations/CustomersView.vue',
    ];
    for (const file of files) {
        const source = readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');
        for (const [, key] of source.matchAll(/['"]((?:login|language|admin)\.[A-Za-z.]+)['"]/g)) {
            assert.ok(arabic.has(key), `${file} uses missing key ${key}`);
        }
    }
});

test('server locale drives Vue I18n and HTML direction through both transitions', () => {
    const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages });
    const root = { lang: '', dir: '' };
    for (const [serverValue, expected, direction, title] of [
        ['invalid', 'ar', 'rtl', 'تسجيل الدخول'],
        ['en', 'en', 'ltr', 'Sign in'],
        ['ar', 'ar', 'rtl', 'تسجيل الدخول'],
    ]) {
        const locale = supportedLocale(serverValue);
        i18n.global.locale.value = locale;
        applyDocumentLocale(locale, root);
        assert.equal(locale, expected);
        assert.equal(root.lang, expected);
        assert.equal(root.dir, direction);
        assert.equal(i18n.global.t('login.title'), title);
    }
});

test('language toggle offers the other locale with a translated accessible action', () => {
    const i18n = createI18n({ legacy: false, locale: 'ar', fallbackLocale: 'en', messages });
    for (const [current, target, visible, accessible] of [
        ['ar', 'en', 'English', 'تغيير اللغة إلى الإنجليزية'],
        ['en', 'ar', 'العربية', 'Switch language to Arabic'],
    ]) {
        i18n.global.locale.value = current;
        assert.equal(otherLocale(current), target);
        assert.equal(i18n.global.t(`language.${target === 'en' ? 'english' : 'arabic'}`), visible);
        assert.equal(i18n.global.t(`language.${target === 'en' ? 'switchToEnglish' : 'switchToArabic'}`), accessible);
    }
});

async function verifyFailure(fetchResult, supported = true) {
    const originalFetch = globalThis.fetch;
    const originalSupported = Passkeys.isSupported;
    const originalCancel = Passkeys.cancel;
    Passkeys.isSupported = () => supported;
    Passkeys.cancel = () => {};
    globalThis.fetch = async () => {
        if (fetchResult instanceof Response) return fetchResult;
        throw fetchResult;
    };
    try {
        let failure;
        await assert.rejects(
            Passkeys.verify({ routes: { options: '/passkey-test-options', submit: '/passkey-test-submit' } }),
            error => { failure = error; return true; },
        );
        return failure;
    } finally {
        globalThis.fetch = originalFetch;
        Passkeys.isSupported = originalSupported;
        Passkeys.cancel = originalCancel;
    }
}

test('installed passkey errors distinguish unsupported, cancellation and invalid domain', async () => {
    assert.equal(passkeyLoginErrorKey(new UserCancelledError()), 'login.passkeyCancelled');
    assert.equal(passkeyLoginErrorKey(new NotSupportedError()), 'login.passkeyUnsupported');
    assert.equal(passkeyLoginErrorKey(new InvalidDomainError('example.test')), 'login.passkeySecurityContext');
    assert.equal(passkeyLoginErrorKey(new PasskeyExistsError()), 'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey(await verifyFailure(null, false)), 'login.passkeyUnsupported');

    for (const [browserError, expectedType, expectedKey] of [
        [new DOMException('browser details', 'NotAllowedError'), UserCancelledError, 'login.passkeyCancelled'],
        [new DOMException('operation aborted', 'AbortError'), PasskeyError, 'login.passkeyFailed'],
        [new DOMException('security details', 'SecurityError'), PasskeyError, 'login.passkeyFailed'],
        [new DOMException('browser unsupported', 'NotSupportedError'), NotSupportedError, 'login.passkeyUnsupported'],
        [Object.assign(new Error('domain details'), { code: 'ERROR_INVALID_DOMAIN' }), InvalidDomainError, 'login.passkeySecurityContext'],
    ]) {
        const failure = await verifyFailure(browserError);
        assert.ok(failure instanceof expectedType);
        assert.equal(passkeyLoginErrorKey(failure), expectedKey);
    }

    assert.equal(passkeyLoginErrorKey(new DOMException('security details', 'SecurityError')),
        'login.passkeySecurityContext');
    assert.equal(passkeyLoginErrorKey(new DOMException('browser unsupported', 'NotSupportedError')),
        'login.passkeyUnsupported');
    assert.equal(passkeyLoginErrorKey(new DOMException('selection unavailable', 'NotAllowedError')),
        'login.passkeyCancelled');
    assert.equal(passkeyLoginErrorKey(new DOMException('operation aborted', 'AbortError')),
        'login.passkeyCancelled');
});

test('installed verify discards HTTP status and structured validation data', async () => {
    for (const [status, body] of [
        [422, { message: 'This sign-in attempt expired.', errors: { credential: ['This sign-in attempt expired.'] } }],
        [429, { message: 'Too many attempts.' }],
    ]) {
        const failure = await verifyFailure(new Response(JSON.stringify(body), {
            status, headers: { 'Content-Type': 'application/json' },
        }));
        assert.ok(failure instanceof PasskeyError);
        assert.equal(failure.name, 'PasskeyError');
        assert.equal('response' in failure, false);
        assert.equal('status' in failure, false);
        assert.equal('errors' in failure, false);
        assert.equal(passkeyLoginErrorKey(failure), 'login.passkeyFailed');
    }
});

test('malformed and unexpected passkey failures never expose diagnostics', async () => {
    const malformed = await verifyFailure(new Response('not JSON', { status: 422 }));
    assert.equal(passkeyLoginErrorKey(malformed), 'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey(await verifyFailure(new Error('raw transport diagnostic'))),
        'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey(await verifyFailure('unexpected rejection')),
        'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey({ response: { status: 422, data: { message: 'raw diagnostic' } } }),
        'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey({ response: { status: 429 } }), 'login.passkeyFailed');
    assert.equal(passkeyLoginErrorKey(null), 'login.passkeyFailed');
});
