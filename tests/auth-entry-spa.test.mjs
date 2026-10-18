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

test('internal transport keeps internal renewal/logout and rejects cross-boundary or foreign-nonce authentication replies', async () => {
    const previous = { document: globalThis.document, location: globalThis.location, fetch: globalThis.fetch, BroadcastChannel: globalThis.BroadcastChannel, setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout };
    const binding = { principal: 42, generation: 'internal-document', lineage: 'internal-lineage', epoch: 3, boundary: 'internal' };
    const identity = { receipt: 'document', transition: Buffer.from(JSON.stringify({ binding: { ...binding, nonce: 'internal-nonce' } })).toString('base64url') + '.signature', csrf: 'csrf', leaseMilliseconds: 60000, binding };
    const source = compile(await readFile('resources/js/spa/transport.ts', 'utf8')).replace("from 'vue'", 'from ' + JSON.stringify(import.meta.resolve('vue'))).replace("from '../sessionLease'", 'from ' + JSON.stringify(new URL('../resources/js/sessionLease.ts', import.meta.url).href));
    const events = [], timers = [];
    globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ identity, locale: 'ar' }) }) };
    globalThis.location = { origin: 'https://builderad.test', assign: value => events.push(value), replace: value => events.push(value) };
    globalThis.BroadcastChannel = undefined;
    globalThis.setTimeout = (callback, delay) => { const timer = { callback, delay }; timers.push(timer); return timer; }; globalThis.clearTimeout = () => {};
    let sequence = 0;
    const client = () => import(dataUrl(source + `\nvoid ${++sequence};`));
    try {
        for (const [redirect, nonce, accepted] of [['/internal/security/devices', 'foreign', false], ['/security/devices', 'internal-nonce', false], ['/internal/security/devices', 'internal-nonce', true]]) {
            const transport = await client(); let deliveries = 0;
            globalThis.fetch = async () => { deliveries++; return Response.json({ redirect, transition: { lineage: binding.lineage, boundary: 'internal', epoch: 4, nonce, kind: 'login', status: 'active' } }); };
            if (accepted) await transport.protectedResponse('/internal/identity/passkey', { method: 'POST' }, 'login');
            else await assert.rejects(transport.protectedResponse('/internal/identity/passkey', { method: 'POST' }, 'login'), /Unowned authentication/);
            assert.equal(deliveries, 1); assert.equal(transport.status.value, 'closed'); assert.equal(transport.bootstrap.identity.binding.principal, 42);
        }
        assert.deepEqual(events, ['https://builderad.test/internal/security/devices']);
        const renewal = await client(); timers.length = 0; const calls = [];
        globalThis.fetch = async url => { calls.push(url); return Response.json({ identity }, { headers: { 'X-Builderad-Identity': JSON.stringify(identity) } }); };
        renewal.status.value = 'ready'; renewal.resumeLease(); assert.equal(timers.length, 2);
        assert.ok(timers[1].delay > 0 && timers[1].delay < timers[0].delay); await timers[1].callback(); renewal.close();
        assert.deepEqual(calls, ['/internal/identity/renew']);
        const terminal = await client();
        globalThis.fetch = async url => { calls.push(url); return Response.json({ redirect: '/internal/login', transition: { lineage: binding.lineage, boundary: 'internal', epoch: 4, nonce: 'internal-nonce', kind: 'logout', status: 'closed' } }); };
        await terminal.logout(); assert.equal(terminal.status.value, 'closed'); assert.equal(calls.at(-1), '/internal/logout'); assert.equal(events.at(-1), '/internal/login');
    } finally { Object.assign(globalThis, previous); }
});

for (const [feature, path, stateKey] of [['auth-entry', '/internal/identity/switch', 'entry'], ['staff-enrollment', '/staff/onboarding', 'enrollment']]) {
    test(`${feature} restoration forwards passive intent and requires an exact authoritative witness`, async () => {
        const key = 'entryRestoration' + crypto.randomUUID().replaceAll('-', ''), calls = [];
        const { ref } = await import('vue');
        const visibility = { protected: true, path, context: 'server-witness' };
        globalThis[key] = { status: ref('checking'), resumed: 0, active: true, bootstrap: { identity: { binding: { principal: 42 } } },
            api: { GET: async (url, options) => { calls.push({ url, headers: options.headers }); return { data: options.headers?.['X-Builderad-Witness'] ? { visibility } : { data: { kind: 'login' }, visibility } }; } } };
        const ports = { vue: import.meta.resolve('vue'), '../spa/api': dataUrl(`export const api=globalThis.${key}.api;`),
            '../spa/query': dataUrl('export const query={clear(){}};'),
            '../spa/transport': dataUrl(`const runtime=globalThis.${key}; export const bootstrap=runtime.bootstrap,status=runtime.status; export const stamp=()=>1,owned=()=>runtime.active,resumeLease=()=>runtime.resumed++,onClose=callback=>runtime.close=callback;`) };
        try {
            let source = compile(await readFile(`resources/js/${feature}/state.ts`, 'utf8'));
            for (const [name, target] of Object.entries(ports)) source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target));
            const state = await import(dataUrl(source)); await state.load({ path }, true);
            assert.deepEqual(calls.map(call => call.headers), [{ 'X-Builderad-Passive': '1' }, { 'X-Builderad-Witness': '1' }]);
            assert.equal(globalThis[key].status.value, 'ready'); assert.ok(state[stateKey].value); assert.equal(globalThis[key].resumed, 1);
            globalThis[key].close(); assert.equal(state[stateKey].value, undefined);
        } finally { delete globalThis[key]; }
    });
}
