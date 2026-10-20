import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';
import { createApplicationClient } from '../resources/js/api/application.ts';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
async function compile(file, ports) {
    let source = ts.transpileModule(await readFile(new URL('../resources/js/account/' + file, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
    for (const [name, target] of Object.entries(withPresentationPorts({ vue: import.meta.resolve('vue'), ...ports }))) source = source.replaceAll("from '" + name + "'", 'from ' + JSON.stringify(target));
    return import(moduleUrl(source));
}
test('actual account helpers use the generated client, exact existing verbs and one delivery', async () => {
    const calls = [], key = 'accountApi' + crypto.randomUUID().replaceAll('-', '');
    globalThis[key] = createApplicationClient('https://builderad.test', async request => {
        calls.push({ method: request.method, path: new URL(request.url).pathname, body: request.body ? await request.json() : null });
        return Response.json(request.method === 'POST' && new URL(request.url).pathname === '/security/passkeys' ? { id: 7, name: 'key' } : { saved: true }, { status: request.method === 'POST' && new URL(request.url).pathname === '/security/passkeys' ? 201 : 200 });
    });
    try {
        const api = await compile('api.ts', { '../spa/api': moduleUrl(`export const api = globalThis.${key};`), '../spa/transport': moduleUrl('export const internalBoundary=false;') });
        await api.readProfile(); await api.readDevices(); await api.saveProfile({ name: 'Same', email: 'same@example.test' });
        await api.savePassword({ current_password: 'current', password: 'new', password_confirmation: 'new' }); await api.revokeSession(5); await api.revokeOthers();
        await api.passkeyOptions(); await api.storePasskey({ name: 'key', credential: { id: 'public', rawId: 'public', type: 'public-key', response: {} } }); await api.deletePasskey(7);
        assert.deepEqual(calls.map(call => call.method + ' ' + call.path), ['GET /profile', 'GET /security/devices', 'PATCH /profile', 'PUT /password', 'DELETE /security/sessions/5', 'POST /security/sessions/revoke-others', 'GET /security/passkeys/options', 'POST /security/passkeys', 'DELETE /security/passkeys/7']);
        assert.deepEqual(calls[2].body, { name: 'Same', email: 'same@example.test' });
    } finally { delete globalThis[key]; }
});

test('internal device helpers retain the existing internal verbs without crossing the advertiser boundary', async () => {
    const calls = [], key = 'internalApi' + crypto.randomUUID().replaceAll('-', '');
    globalThis[key] = createApplicationClient('https://builderad.test', async request => {
        calls.push(request.method + ' ' + new URL(request.url).pathname);
        return Response.json({ saved: true });
    });
    try {
        const api = await compile('api.ts', { '../spa/api': moduleUrl(`export const api=globalThis.${key};`), '../spa/transport': moduleUrl('export const internalBoundary=true;') });
        await api.readDevices(); await api.revokeSession(5); await api.revokeOthers(); await api.passkeyOptions();
        await api.storePasskey({ name: 'key', credential: { id: 'public', rawId: 'public', type: 'public-key', response: {} } }); await api.deletePasskey(7);
        assert.deepEqual(calls, ['GET /internal/security/devices', 'DELETE /internal/security/sessions/5', 'POST /internal/security/sessions/revoke-others', 'GET /internal/security/passkeys/options', 'POST /internal/security/passkeys', 'DELETE /internal/security/passkeys/7']);
    } finally { delete globalThis[key]; }
});
test('native email handoff captures current evidence, closes before one native submission and never persists secrets', async () => {
    const key = 'accountNative' + crypto.randomUUID().replaceAll('-', ''), events = [], fields = [];
    const oldDocument = globalThis.document, oldForm = globalThis.HTMLFormElement;
    globalThis[key] = { documentEvidence: () => ({ csrf: 'current-csrf', receipt: 'current-receipt', transition: 'current-transition' }), close: () => events.push('close') };
    globalThis.HTMLFormElement = class { submit() { events.push('submit'); } };
    globalThis.document = { createElement: tag => tag === 'form' ? { append: field => fields.push({ name: field.name, value: field.value }) } : {}, body: { append: () => events.push('append') } };
    try {
        const feature = await compile('nativeEmail.ts', { '../spa/transport': moduleUrl(`export const {close, documentEvidence} = globalThis.${key};`) });
        feature.changeEmail({ name: 'Original', email: 'new@example.test' });
        assert.deepEqual(events, ['append', 'close', 'submit']);
        assert.deepEqual(Object.fromEntries(fields.map(field => [field.name, field.value])), { name: 'Original', email: 'new@example.test', _method: 'PATCH', _token: 'current-csrf', _identity_receipt: 'current-receipt', _identity_transition: 'current-transition' });
    } finally { globalThis.document = oldDocument; globalThis.HTMLFormElement = oldForm; delete globalThis[key]; }
});
async function stateFixture(reader) {
    const key = 'accountState' + crypto.randomUUID().replaceAll('-', ''), closures = [], context = { revision: 0, status: ref('checking'), resumed: 0, cleared: 0 };
    globalThis[key] = { reader, context, closures, query: { cancelQueries: async () => {}, clear: () => context.cleared++, fetchQuery: options => options.queryFn({ signal: new AbortController().signal }) } };
    const state = await compile('state.ts', {
        '../spa/query': moduleUrl(`export const query=globalThis.${key}.query;`),
        './api': moduleUrl(`const runtime=globalThis.${key}; export const readProfile=(...args)=>runtime.reader('/profile',...args); export const readDevices=(...args)=>runtime.reader('/security/devices',...args);`),
        '../spa/transport': moduleUrl(`const runtime=globalThis.${key}; export const scope='scope',status=runtime.context.status; export const stamp=()=>runtime.context.revision; export const owned=value=>value===runtime.context.revision && status.value!=='closed'; export const resumeLease=()=>runtime.context.resumed++; export const onClose=callback=>runtime.closures.push(callback);`),
    });
    return { state, context, close: () => { context.status.value = 'closed'; closures.forEach(callback => callback()); }, dispose: () => delete globalThis[key] };
}
for (const path of ['/profile', '/security/devices']) test(`account ${path} requires an exact matching authoritative witness`, async () => {
    const calls = [], visibility = { protected: true, path, context: 'authorized' };
    const view = await stateFixture(async (url, headers, signal) => { calls.push({ url, headers, signal }); return headers['X-Builderad-Witness'] ? { visibility } : { data: {}, locale: 'ar', visibility }; });
    try {
        await view.state.read(path, true); assert.equal(view.context.status.value, 'ready'); assert.equal(view.context.resumed, 0);
        assert.deepEqual(calls[0].headers, { 'X-Builderad-Passive': '1' }); assert.ok(calls[0].signal instanceof AbortSignal);
        assert.deepEqual(calls[1].headers, { 'X-Builderad-Witness': '1' });
    } finally { view.dispose(); }
});
test('a foreign account path cannot become a ready account presentation', async () => {
    const view = await stateFixture(async () => ({ data: {}, locale: 'ar', visibility: { protected: true, path: '/other', context: 'wrong' } }));
    try { await view.state.read('/profile'); assert.equal(view.context.status.value, 'unavailable'); assert.equal(view.state.profile.value, undefined); } finally { view.dispose(); }
});
for (const terminal of [false, true]) test(`late account resource cannot cross ${terminal ? 'terminal identity closure' : 'presentation suspension'}`, async () => {
    let deliver; const view = await stateFixture(() => new Promise(resolve => { deliver = resolve; }));
    try {
        view.state.outcomes.password = 'unknown'; const work = view.state.read('/profile'); await Promise.resolve(); await Promise.resolve();
        if (terminal) view.close(); else { view.context.revision++; view.state.clear(); }
        deliver({ data: {}, locale: 'ar', visibility: { protected: true, path: '/profile', context: 'old' } }); await work;
        assert.equal(view.state.profile.value, undefined); assert.equal(view.context.resumed, 0);
        if (terminal) { assert.equal(view.context.status.value, 'closed'); assert.equal(view.state.outcomes.password, ''); assert.equal(view.context.cleared, 0); /* Cache erasure belongs to the single document-level QueryClient. */ }
    } finally { view.dispose(); }
});
