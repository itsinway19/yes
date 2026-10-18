import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import 'vue';

const dataUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;

test('native auth handoff captures current evidence, nests actual WebAuthn form fields and closes before exactly one submit', async () => {
    const previous = { document: globalThis.document, location: globalThis.location, HTMLFormElement: globalThis.HTMLFormElement };
    const events = [], fields = [];
    globalThis.location = { origin: 'https://builderad.test' };
    globalThis.document = { createElement: tag => tag === 'form' ? { append: input => fields.push([input.name, input.value]) } : {}, body: { append: form => { events.push('append'); assert.equal(form.action, '/auth/passkey'); } } };
    globalThis.HTMLFormElement = class { submit() { events.push('submit'); } };
    globalThis.authNativeProof = events;
    const transport = dataUrl(`export const documentEvidence=()=>({csrf:'csrf',receipt:'document',transition:'transition'}); export const close=()=>globalThis.authNativeProof.push('close');`);
    try {
        const source = compile(await readFile('resources/js/spa/nativeForm.ts', 'utf8')).replace("from './transport'", 'from ' + JSON.stringify(transport));
        const native = await import(dataUrl(source));
        native.submitDocument('/auth/passkey', { credential: { id: 'key', type: 'public-key', response: { clientDataJSON: 'signed-client-data', authenticatorData: 'signed-authenticator', userHandle: null } } });
        assert.deepEqual(events, ['append', 'close', 'submit']);
        assert.deepEqual(fields, [['credential[id]', 'key'], ['credential[type]', 'public-key'], ['credential[response][clientDataJSON]', 'signed-client-data'], ['credential[response][authenticatorData]', 'signed-authenticator'], ['_method', 'POST'], ['_token', 'csrf'], ['_identity_receipt', 'document'], ['_identity_transition', 'transition']]);
        assert.throws(() => native.submitDocument('https://foreign.test/login', { password: 'never-send' }), /origin/);
        assert.equal(events.filter(value => value === 'submit').length, 1);
    } finally { Object.assign(globalThis, previous); delete globalThis.authNativeProof; }
});

test('guest availability never schedules authenticated renewal or rebind', async () => {
    const previous = { document: globalThis.document, location: globalThis.location, setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout };
    const timers = [];
    const identity = { receipt: 'guest-document', transition: 'guest-transition', csrf: 'csrf', leaseMilliseconds: 60000, binding: { principal: 0, generation: 'guest' } };
    globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ identity, locale: 'ar', documentState: 'guest' }) }) };
    globalThis.location = { origin: 'https://builderad.test' };
    globalThis.setTimeout = (callback, delay) => { const timer = { callback, delay }; timers.push(timer); return timer; }; globalThis.clearTimeout = () => {};
    try {
        const source = compile(await readFile('resources/js/spa/transport.ts', 'utf8')).replace("from 'vue'", 'from ' + JSON.stringify(import.meta.resolve('vue'))).replace("from '../sessionLease'", 'from ' + JSON.stringify(new URL('../resources/js/sessionLease.ts', import.meta.url).href));
        const transport = await import(dataUrl(source));
        transport.status.value = 'ready'; transport.resumeLease();
        assert.equal(timers.length, 1, 'Only the conservative expiry timer is allowed for guests');
        assert.ok(timers[0].delay > 0); transport.close();
    } finally { Object.assign(globalThis, previous); }
});

test('existing login transition replies require the captured epoch and nonce, then close before native navigation without adopting a principal', async () => {
    const previous = { document: globalThis.document, location: globalThis.location, fetch: globalThis.fetch, BroadcastChannel: globalThis.BroadcastChannel };
    const events = [], binding = { principal: 0, generation: 'guest', lineage: 'lineage', epoch: 0, boundary: 'advertiser' };
    const identity = { receipt: 'document', transition: Buffer.from(JSON.stringify({ binding: { ...binding, nonce: 'nonce' } })).toString('base64url') + '.signature', csrf: 'csrf', leaseMilliseconds: 60000, binding };
    globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ identity, locale: 'ar' }) }) };
    globalThis.location = { origin: 'https://builderad.test', assign: target => events.push(['navigate', target]) };
    globalThis.BroadcastChannel = undefined;
    try {
        const source = compile(await readFile('resources/js/spa/transport.ts', 'utf8')).replace("from 'vue'", 'from ' + JSON.stringify(import.meta.resolve('vue'))).replace("from '../sessionLease'", 'from ' + JSON.stringify(new URL('../resources/js/sessionLease.ts', import.meta.url).href));
        let sequence = 0;
        for (const nonce of ['foreign', 'nonce']) {
            const transport = await import(dataUrl(source + `\nvoid ${++sequence}`));
            transport.onClose(() => events.push(['close']));
            let deliveries = 0;
            globalThis.fetch = async () => { deliveries++; return Response.json({ redirect: '/security/devices', transition: { lineage: 'lineage', boundary: 'advertiser', epoch: 1, nonce, kind: 'login', status: 'active' } }); };
            if (nonce === 'foreign') await assert.rejects(transport.protectedResponse('/login', { method: 'POST' }, 'login'), /Unowned authentication/);
            else await transport.protectedResponse('/login', { method: 'POST' }, 'login');
            assert.equal(deliveries, 1); assert.equal(transport.status.value, 'closed'); assert.equal(transport.bootstrap.identity.binding.principal, 0);
        }
        assert.deepEqual(events, [['close'], ['close'], ['navigate', 'https://builderad.test/security/devices']]);
    } finally { Object.assign(globalThis, previous); }
});
