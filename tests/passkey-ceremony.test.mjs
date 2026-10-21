import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Passkeys } from '@laravel/passkeys';
import { cancelPasskeyOperation, runPasskeyOperation, passkeyBrowserFailure } from '../resources/js/security/passkeyCeremony.ts';

const previousWindow = globalThis.window;
const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
globalThis.window = { location: { hostname: 'test.invalid', href: 'http://test.invalid/login' } };
Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { locks: { request: async (_name, _options, callback) => callback({}) } },
});

const routes = { options: '/options', submit: '/submit' };
test('browser NotAllowedError does not prove user cancellation, including the library wrapper', () => {
    const refusal = new DOMException('Platform refused', 'NotAllowedError');
    assert.equal(passkeyBrowserFailure(refusal, false), 'not-allowed');
    const wrapped = new Error('Platform refused', { cause: refusal });
    wrapped.name = 'NotAllowedError';
    assert.equal(passkeyBrowserFailure(wrapped, false), 'not-allowed');
    assert.equal(passkeyBrowserFailure(refusal, true), 'cancelled');
    assert.equal(passkeyBrowserFailure(new DOMException('Aborted externally', 'AbortError'), false), 'unavailable');
    assert.equal(passkeyBrowserFailure(new Error('Unsupported browser'), false), 'unavailable');
});
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

test.after(() => {
    globalThis.window = previousWindow;
    if (previousNavigator) Object.defineProperty(globalThis, 'navigator', previousNavigator);
});

test('one pending ceremony blocks a second, including after cancellation until settlement', async t => {
    const cancel = t.mock.method(Passkeys, 'cancel', () => {});
    const pending = deferred();
    const first = runPasskeyOperation('login', routes, async urls => {
        assert.equal(new URL(urls.options).searchParams.get('webauthn_probe'),
            new URL(urls.submit).searchParams.get('webauthn_probe'));
        return pending.promise;
    });

    assert.throws(() => runPasskeyOperation('device-registration', routes, async () => {}), /جارية/);
    cancelPasskeyOperation(first.id);
    assert.equal(cancel.mock.callCount(), 1);
    assert.throws(() => runPasskeyOperation('login', routes, async () => {}), /جارية/);
    pending.resolve('done');
    assert.equal(await first.promise, 'done');
    assert.equal(await runPasskeyOperation('login', routes, async () => 'next').promise, 'next');
});

test('a rejected ceremony releases its guard', async () => {
    await assert.rejects(runPasskeyOperation('login', routes, async () => {
        throw new Error('browser rejected');
    }).promise, /browser rejected/);
    assert.equal(await runPasskeyOperation('login', routes, async () => 'recovered').promise, 'recovered');
});

test('document disposal retains cancellation safety but is not reported as user cancellation', async t => {
    const phases = [];
    t.mock.method(Passkeys, 'cancel', () => {});
    t.mock.method(console, 'info', message => phases.push(JSON.parse(message.slice('builderad_webauthn '.length)).phase));
    window.location.hostname = 'localhost';
    try {
        const pending = deferred();
        const operation = runPasskeyOperation('login', routes, () => pending.promise);
        await Promise.resolve();
        cancelPasskeyOperation(operation.id, 'document');
        assert.throws(() => runPasskeyOperation('login', routes, async () => {}), /جارية/);
        pending.reject(new DOMException('Document disposed', 'AbortError'));
        await assert.rejects(operation.promise, /Document disposed/);
        assert.ok(phases.includes('document_disposed'));
        assert.ok(phases.includes('disposed'));
        assert.ok(!phases.includes('cancelled'));
    } finally { window.location.hostname = 'test.invalid'; }
});

test('a Web Lock held by another tab prevents a browser operation', async () => {
    globalThis.navigator.locks.request = async (_name, _options, callback) => callback(null);
    let called = false;
    await assert.rejects(runPasskeyOperation('login', routes, async () => {
        called = true;
    }).promise, /تبويب آخر/);
    assert.equal(called, false);
    globalThis.navigator.locks.request = async (_name, _options, callback) => callback({});
});

test('cancellation while waiting for the browser lock never starts WebAuthn', async t => {
    const cancel = t.mock.method(Passkeys, 'cancel', () => {});
    const lock = deferred();
    globalThis.navigator.locks.request = async (_name, _options, callback) => {
        await lock.promise;
        return callback({});
    };
    let called = false;
    const operation = runPasskeyOperation('device-registration', routes, async () => {
        called = true;
    });
    cancelPasskeyOperation(operation.id);
    lock.resolve();
    await assert.rejects(operation.promise, /أُلغيت/);
    assert.equal(called, false);
    assert.equal(cancel.mock.callCount(), 1);
    globalThis.navigator.locks.request = async (_name, _options, callback) => callback({});
});
