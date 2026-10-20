import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
let sequence = 0;
const visibility = { protected: true, workspace: 'workspace' };
const resource = workspace => ({ data: { workspace: { public_id: workspace }, sources: { state: 'NO_VISIBLE_SOURCE_AUTHORITY', items: [] } }, visibility });
async function fixture(send) {
    const calls = [], keys = [], closures = [];
    const context = { revision: 0, status: ref('checking'), resumed: 0, cleared: 0 };
    const runtime = '__sourceState' + ++sequence;
    globalThis[runtime] = {
        read: (...args) => { calls.push(args); return send(...args); }, context, closures,
        query: { cancelQueries: async () => {}, clear: () => { context.cleared++; }, fetchQuery: ({ queryKey, queryFn }) => { keys.push(queryKey); return queryFn({ signal: new AbortController().signal }); } },
    };
    const ports = {
        '../spa/query': moduleUrl(`export const query = globalThis.${runtime}.query;`),
        './api': moduleUrl(`export const sources = { read: (...args) => globalThis.${runtime}.read(...args) };`),
        '../spa/transport': moduleUrl(`const runtime = globalThis.${runtime}; export const stamp = () => runtime.context.revision;
            export const owned = captured => captured === runtime.context.revision && runtime.context.status.value !== 'closed';
            export const status = runtime.context.status; export const scope = 'identity-scope';
            export const resumeLease = () => { runtime.context.resumed++; }; export const onClose = callback => runtime.closures.push(callback);`),
    };
    let compiled = ts.transpileModule(await readFile(new URL('../resources/js/sources/state.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
    for (const [name, target] of Object.entries(withPresentationPorts({ vue: import.meta.resolve('vue'), ...ports }))) compiled = compiled.replaceAll("from '" + name + "'", 'from ' + JSON.stringify(target));
    const state = await import(moduleUrl(compiled));
    return { state, calls, keys, context, close: () => { context.status.value = 'closed'; closures.forEach(callback => callback()); }, dispose: () => { delete globalThis[runtime]; } };
}

test('Source presentation requires its exact workspace and matching authoritative witness before readiness', async () => {
    const view = await fixture(async (_workspace, headers) => headers['X-Builderad-Witness'] ? { visibility } : resource('workspace'));
    try {
        assert.deepEqual(await view.state.read('workspace', true), resource('workspace').data);
        assert.equal(view.context.status.value, 'ready');
        assert.equal(view.context.resumed, 0);
        assert.deepEqual(view.keys, [['identity-scope', 'workspace-sources', 'workspace']]);
        assert.deepEqual(view.calls[0][1], { 'X-Builderad-Passive': '1' });
        assert.equal(view.calls[0][2] instanceof AbortSignal, true);
        assert.deepEqual(view.calls[1][1], { 'X-Builderad-Witness': '1' });
    } finally { view.dispose(); }
});

for (const scenario of ['wrong workspace', 'witness denial', 'witness mismatch', 'read failure']) test(`Source ${scenario} cannot publish or renew presentation`, async () => {
    const view = await fixture(async (_workspace, headers) => {
        if (scenario === 'read failure') throw Error('failed');
        if (headers['X-Builderad-Witness']) return { visibility: scenario === 'witness denial' ? { ...visibility, protected: false } : { ...visibility, workspace: 'other' } };
        return resource(scenario === 'wrong workspace' ? 'other' : 'workspace');
    });
    try { await view.state.read('workspace'); assert.equal(view.state.current.value, undefined); assert.equal(view.context.status.value, 'unavailable'); assert.equal(view.context.resumed, 0); }
    finally { view.dispose(); }
});

for (const terminal of [false, true]) test(`late Source resource cannot cross ${terminal ? 'terminal closure' : 'presentation suspension'}`, async () => {
    let deliver;
    const view = await fixture(() => new Promise(resolve => { deliver = resolve; }));
    try {
        const pending = view.state.read('workspace');
        await Promise.resolve(); await Promise.resolve();
        if (terminal) view.close(); else { view.context.revision++; view.state.clear(); }
        deliver(resource('workspace')); await pending;
        assert.equal(view.state.current.value, undefined);
        assert.equal(view.context.resumed, 0);
        assert.equal(view.calls.length, 1);
        if (terminal) { assert.equal(view.context.status.value, 'closed'); assert.equal(view.context.cleared, 0); /* Cache erasure belongs to the single document-level QueryClient. */ }
    } finally { view.dispose(); }
});
