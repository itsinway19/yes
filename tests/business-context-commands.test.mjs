import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { nextTick, ref } from 'vue';

const state = globalThis.__contextCommandTest = { revision: 0, status: ref('ready'), locale: ref('ar'), requests: [], reads: [], closures: [], value: { version: 1 } };
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const transpile = code => ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
function rewrite(code, replacements) { for (const [from, to] of Object.entries(withPresentationPorts(replacements))) code = code.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`); return code; }
const transport = moduleUrl(`export const status=globalThis.__contextCommandTest.status,stamp=()=>globalThis.__contextCommandTest.revision,owned=value=>value===globalThis.__contextCommandTest.revision&&status.value!=='closed',onClose=handler=>globalThis.__contextCommandTest.closures.push(handler);export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body;}}`);
const { TransportError } = await import(transport);
const api = moduleUrl(`const send=(kind,workspace,body)=>new Promise((resolve,reject)=>globalThis.__contextCommandTest.requests.push({kind,workspace,body,resolve,reject}));export const updateContext=(workspace,body)=>send('context',workspace,body),updateMarkets=(workspace,body)=>send('markets',workspace,body),updateIntent=(workspace,body)=>send('intent',workspace,body),requestAuthority=(workspace,body)=>send('authority',workspace,body);`);
const reader = moduleUrl(`export async function read(workspace,passive){globalThis.__contextCommandTest.reads.push({workspace,passive});return globalThis.__contextCommandTest.nextRead??globalThis.__contextCommandTest.value;}`);
const commands = await import(moduleUrl(rewrite(transpile(await readFile(new URL('../resources/js/business-context/commands.ts', import.meta.url), 'utf8')), { vue: import.meta.resolve('vue'), '../spa/transport': transport, '../spa/locale': moduleUrl('export const locale=globalThis.__contextCommandTest.locale;'), './state': reader, './api': api })));
const drafts = await import(moduleUrl(rewrite(transpile(await readFile(new URL('../resources/js/business-context/drafts.ts', import.meta.url), 'utf8')), { '../spa/transport': transport })));
function reset() { state.revision++; state.status.value = 'ready'; state.locale.value = 'ar'; state.requests = []; state.reads = []; state.value = { version: 1 }; state.nextRead = undefined; state.closures.forEach(handler => handler()); }
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); };

test('actual context command snapshots only the original form payload and sends it once', async () => {
    reset(); const form = commands.useBusinessForm({ country_codes: ['SA'], version: 0 }, 'markets'); let success = 0;
    const pending = form.submit('workspace', { onSuccess: () => success++ });
    assert.equal(form.processing, true); form.country_codes.push('AE'); await form.submit('workspace');
    assert.equal(state.requests.length, 1); assert.deepEqual(state.requests[0].body, { country_codes: ['SA'], version: 0 });
    state.requests[0].resolve({ redirect: '/workspaces/workspace/business' }); await pending;
    assert.equal(form.processing, false); assert.equal(form.recentlySuccessful, true); assert.equal(success, 1); assert.deepEqual(state.reads, [{ workspace: 'workspace', passive: true }]);
    assert.deepEqual(form.country_codes, ['SA', 'AE']);
});
test('actual context commands preserve validation and permission errors without mutation replay', async () => {
    for (const failure of [new TransportError(422, { errors: { version: ['Review the current revision'] } }), new TransportError(403), new Error('lost acknowledgement')]) {
        reset(); const form = commands.useBusinessForm({ name: 'Draft', business_type_id: 1, version: 3 }, 'context');
        const pending = form.submit('workspace'); state.requests[0].reject(failure); await pending; await flush();
        assert.equal(state.requests.length, 1); assert.equal(state.reads.length, 0); assert.equal(form.name, 'Draft'); assert.equal(form.version, 3); assert.equal(form.recentlySuccessful, false); assert.equal(form.processing, false);
        assert.match(form.errors.version ?? form.errors.value, failure.status === 422 ? /current revision/ : failure.status === 403 ? /إذن/ : /تأكيد الحفظ/);
    }
});
test('actual context commands wait for the post-commit authoritative refresh before reporting success', async () => {
    reset(); let success = false, resolveRead; state.nextRead = new Promise(resolve => resolveRead = resolve);
    const pending = commands.perform('workspace', () => new Promise(resolve => state.requests.push({ resolve })), { onSuccess: () => success = true });
    state.requests[0].resolve(); await flush(); assert.equal(state.reads.length, 1); assert.equal(success, false);
    resolveRead({ version: 2 }); await pending; assert.equal(success, true);
});
test('actual context commands suppress late publication after suspension or terminal closure', async () => {
    for (const terminal of [false, true]) {
        reset(); const form = commands.useBusinessForm({ name: 'Original', business_type_id: 1, version: 2 }, 'context'); let success = false;
        const pending = form.submit('workspace', { onSuccess: () => success = true }); state.revision++; if (terminal) state.status.value = 'closed';
        state.requests[0].resolve({ redirect: '/workspaces/workspace' }); await pending; assert.equal(success, false); assert.equal(form.recentlySuccessful, false); assert.equal(state.reads.length, 0);
    }
});
test('actual context command does not claim success when the post-commit authoritative read fails', async () => {
    reset(); state.value = undefined; const form = commands.useBusinessForm({ decision: 'authorize', reason: 'complete_business_context' }, 'authority');
    const pending = form.submit('workspace'); state.requests[0].resolve({ redirect: '/workspaces/workspace/business' }); await pending;
    assert.equal(form.recentlySuccessful, false); assert.equal(form.processing, false); assert.equal(state.requests.length, 1);
});
test('actual Intent command retains the technical-size boundary callback without overwriting its message', async () => {
    reset(); let message = '', finished = false;
    const pending = commands.saveIntent('workspace', { command: 'clear', value: null, confirmations: { declaration: true }, version: 0 }, { onHttpException: response => { assert.equal(response.status, 413); message = 'technical-size'; }, onError: () => message = 'wrong', onFinish: () => finished = true });
    state.requests[0].reject(new TransportError(413)); await pending; assert.equal(message, 'technical-size'); assert.equal(finished, true); assert.equal(state.requests.length, 1);
});
test('actual context draft memory is Workspace-separated, cloned and terminally cleared', () => {
    reset(); const original = { value: ['SA'], version: 4 }; drafts.remember('first', 'markets', original); original.value.push('AE');
    assert.deepEqual(drafts.restore('first', 'markets'), { value: ['SA'], version: 4 }); assert.equal(drafts.restore('second', 'markets'), undefined);
    const clone = drafts.restore('first', 'markets'); clone.value.push('KW'); assert.deepEqual(drafts.restore('first', 'markets').value, ['SA']);
    state.status.value = 'closed'; state.closures.forEach(handler => handler()); drafts.remember('first', 'markets', original); assert.equal(drafts.restore('first', 'markets'), undefined);
});
