import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
async function fixture(reader) {
    const key = 'commercialPreparation' + crypto.randomUUID().replaceAll('-', '');
    const runtime = { reader, revision: 0, status: ref('checking'), closed: false, callbacks: [] };
    globalThis[key] = runtime;
    const transport = moduleUrl(`const runtime=globalThis.${key}; export const status=runtime.status,stamp=()=>runtime.revision,owned=value=>value===runtime.revision&&!runtime.closed,resumeLease=()=>{},onClose=callback=>runtime.callbacks.push(callback),close=()=>{runtime.closed=true;runtime.status.value='closed';runtime.callbacks.forEach(callback=>callback())}; export class TransportError extends Error{constructor(status){super();this.status=status}}`);
    let source = ts.transpileModule(await readFile(new URL('../resources/js/commercial/preparationState.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries({ vue: import.meta.resolve('vue'), '../spa/api': moduleUrl(`export const api={GET:(...args)=>globalThis.${key}.reader(...args),POST:(...args)=>globalThis.${key}.reader(...args)};`), '../spa/transport': transport })) source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target));
    return { state: await import(moduleUrl(source)), runtime, transport: await import(transport), dispose: () => delete globalThis[key] };
}
const visibility = { protected: true, path: '/internal/administration/commercial-markets', context: 'authoritative-current' };
const resource = intentId => ({ data: { data: { intentId }, visibility } });

test('passive preparation reads preserve exact intent and require a matching current witness', async () => {
    const calls = [], view = await fixture(async (_url, options) => { calls.push(options.headers); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource('original-intent'); });
    try {
        await view.state.readPreparation(true);
        assert.equal(view.state.preparationData.value.intentId, 'original-intent');
        assert.deepEqual(calls, [{ 'X-Builderad-Passive': '1' }, { 'X-Builderad-Witness': '1' }]);
    } finally { view.dispose(); }
});

test('A to B to A lifetime suppresses first A even when its aborted read resolves', async () => {
    let resolveFirst, reads = 0;
    const view = await fixture(async (_url, options) => {
        if (options.headers['X-Builderad-Witness']) return { data: { visibility } };
        if (++reads === 1) return new Promise(resolve => { resolveFirst = resolve; });
        return resource(reads === 2 ? 'B' : 'new-A');
    });
    try {
        const first = view.state.readPreparation();
        view.state.clearPreparation(); await view.state.readPreparation();
        view.state.clearPreparation(); await view.state.readPreparation();
        resolveFirst(resource('obsolete-A')); await first;
        assert.equal(view.state.preparationData.value.intentId, 'new-A');
    } finally { view.dispose(); }
});

test('post-load authority refusal closes and clears private preparation data', async () => {
    let denied = false;
    const view = await fixture(async (_url, options) => { if (denied) throw new view.transport.TransportError(403); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource('private'); });
    try {
        await view.state.readPreparation(); denied = true; await view.state.readPreparation(false, true);
        assert.equal(view.runtime.status.value, 'closed'); assert.equal(view.state.preparationData.value, undefined);
    } finally { view.dispose(); }
});

test('lost write ACK makes one request preserving original composite identities without replay', async () => {
    const calls = [], view = await fixture(async (url, options) => { calls.push({ url, body: structuredClone(options.body) }); throw Error('ACK lost'); });
    const body = Object.freeze({ mode: 'direct', intent_id: 'original-intent', intent_binding: 'original-founder-target-and-child-binding', country_code: 'JO', denomination_code: 'USD', reason: 'Explicit request' });
    try {
        await assert.rejects(view.state.writePreparation({ kind: 'prepare', body }), /ACK lost/);
        assert.deepEqual(calls, [{ url: '/internal/administration/commercial-markets', body }]);
    } finally { view.dispose(); }
});
