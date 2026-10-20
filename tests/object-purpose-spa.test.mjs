import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';
import Ajv2020 from 'ajv/dist/2020.js';
import { createApplicationClient } from '../resources/js/api/application.ts';

const state = globalThis.__purposeCommandTest = { status: ref('ready'), revision: 0, calls: [], closures: [] };
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const transport = moduleUrl(`export const status=globalThis.__purposeCommandTest.status,stamp=()=>globalThis.__purposeCommandTest.revision,owned=value=>status.value!=='closed'&&value===globalThis.__purposeCommandTest.revision,onClose=handler=>globalThis.__purposeCommandTest.closures.push(handler);export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body;}}`);
const { TransportError } = await import(transport);
const api = moduleUrl(`export const writePurpose=(workspace,body)=>new Promise((resolve,reject)=>globalThis.__purposeCommandTest.calls.push({workspace,body,resolve,reject}));`);
const reader = moduleUrl('export const read=async()=>({});');
let source = ts.transpileModule(await readFile(new URL('../resources/js/object-purpose/commands.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [from, to] of Object.entries(withPresentationPorts({ vue: import.meta.resolve('vue'), '../spa/transport': transport, './api': api, './state': reader }))) source = source.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`);
const commands = await import(moduleUrl(source));
const command = () => ({ command_id: '11111111-1111-4111-8111-111111111111', purpose_id: null, expected_version: 0, operation: 'declare', declaration: { meaning: 'Synthetic fixture only' }, confirmations: { meaning: true } });
const reply = { purpose_id: '22222222-2222-4222-8222-222222222222', revision_id: '33333333-3333-4333-8333-333333333333', version: 1 };
function reset() { state.revision++; state.status.value = 'ready'; state.calls = []; state.closures.forEach(handler => handler()); }

test('actual Purpose unknown delivery locks replacement commands and retries only the captured original payload', async () => {
    reset(); const original = command(); let acknowledged;
    const pending = commands.submitPurpose('workspace', original, { onSuccess: value => acknowledged = value }); original.declaration.meaning = 'Replacement must not enter the original command';
    state.calls[0].reject(new Error('acknowledgement lost')); await pending;
    assert.equal(commands.unresolved('workspace', null).uncertain, true); assert.equal(commands.unresolved('workspace', null).busy, false);
    await commands.submitPurpose('workspace', { ...command(), command_id: crypto.randomUUID() }, {}); assert.equal(state.calls.length, 1);
    const retry = commands.retryPurpose('workspace', null, { onSuccess: value => acknowledged = value }); assert.equal(state.calls.length, 2); assert.deepEqual(state.calls[1].body, state.calls[0].body); assert.equal(state.calls[1].body.declaration.meaning, 'Synthetic fixture only');
    state.calls[1].resolve(reply); await retry; assert.deepEqual(acknowledged, reply); assert.equal(commands.unresolved('workspace', null), undefined);
});
test('actual Purpose permission denial during original retry cannot erase an earlier unknown outcome', async () => {
    reset(); let pending = commands.submitPurpose('workspace', command(), {}); state.calls[0].reject(new Error('unknown commit')); await pending;
    pending = commands.retryPurpose('workspace', null, {}); state.calls[1].reject(new TransportError(403, { message: 'Permission withdrawn' })); await pending;
    assert.equal(commands.unresolved('workspace', null).uncertain, true); await commands.submitPurpose('workspace', command(), {}); assert.equal(state.calls.length, 2);
    pending = commands.retryPurpose('workspace', null, {}); assert.deepEqual(state.calls[2].body, state.calls[0].body); state.calls[2].resolve(reply); await pending; assert.equal(commands.unresolved('workspace', null), undefined);
});
test('actual Purpose initial definite denials preserve their error contract without an automatic replay', async () => {
    for (const status of [403, 409, 422]) {
        reset(); let observedStatus, errors;
        const pending = commands.submitPurpose('workspace', command(), { onHttpException: response => observedStatus = response.status, onError: value => errors = value });
        state.calls[0].reject(new TransportError(status, { message: 'Denied', ...(status === 422 ? { errors: { declaration: ['Exact declaration required'] } } : {}) })); await pending;
        assert.equal(state.calls.length, 1); assert.equal(observedStatus, status); assert.equal(errors.declaration, status === 422 ? 'Exact declaration required' : 'Denied'); assert.equal(commands.unresolved('workspace', null), undefined);
    }
});
test('actual Purpose suspension cannot publish and retains a potentially committed obsolete request as unknown', async () => {
    reset(); let success = false;
    const pending = commands.submitPurpose('workspace', command(), { onSuccess: () => success = true }); state.revision++; state.status.value = 'checking'; state.calls[0].reject(new Error('Obsolete response')); await pending;
    assert.equal(success, false); assert.equal(commands.unresolved('workspace', null).uncertain, true);
});
test('actual Purpose terminal closure cannot reinsert an unknown command or draft after a late failure', async () => {
    reset(); const pending = commands.submitPurpose('workspace', command(), {}); commands.remember('workspace', null, { version: 0 }); state.status.value = 'closed'; state.closures.forEach(handler => handler()); state.calls[0].reject(new Error('late failure')); await pending;
    commands.remember('workspace', null, { version: 9 }); assert.equal(commands.unresolved('workspace', null), undefined); assert.equal(commands.restore('workspace', null), undefined);
});
test('actual Purpose draft memory separates exact purposes and Workspaces and returns independent copies', () => {
    reset(); commands.remember('workspace', 'purpose-a', { value: ['original'], version: 3 }); const value = commands.restore('workspace', 'purpose-a'); value.value.push('changed');
    assert.deepEqual(commands.restore('workspace', 'purpose-a'), { value: ['original'], version: 3 }); assert.equal(commands.restore('other', 'purpose-a'), undefined); assert.equal(commands.restore('workspace', 'purpose-b'), undefined);
});
test('private Purpose schemas keep the canonical object tuple generic and the enabled HTTP admission Meta-only', async () => {
    const spec = JSON.parse(await readFile(new URL('../contracts/application.openapi.json', import.meta.url), 'utf8'));
    const ajv = new Ajv2020({ strict: false, validateFormats: false }); ajv.addSchema({ $id: 'application', components: spec.components });
    const generic = ajv.compile({ $ref: 'application#/components/schemas/PurposeObject' }), edge = ajv.compile({ $ref: 'application#/components/schemas/MetaPurposeObject' });
    const object = { provider: 'synthetic-non-meta', account_namespace: 'canonical-fixture', account_id: 'account', object_kind: 'offer', object_id: 'object', incarnation: null };
    assert.equal(generic(object), true); assert.equal(edge(object), false);
    assert.equal(edge({ ...object, provider: 'meta', account_namespace: 'meta_ads_account', account_id: '100', object_kind: 'campaign', object_id: '200' }), true);
});
test('generated Purpose client preserves nullable command fields and never retries uncertain delivery', async () => {
    let attempts = 0; const original = { command_id: crypto.randomUUID(), purpose_id: reply.purpose_id, expected_version: 1, operation: 'withdraw', declaration: null, effective_from: null, effective_until: null, supersedes_revision_id: reply.revision_id, affected_revision_ids: [reply.revision_id], correction_reason: null, withdrawal_reason: 'Explicit synthetic withdrawal', confirmations: { meaning: true, scope: true, authority: true, identity: true, standing: true, dtm: false, intent: false, historical: false, transition: true } };
    const client = createApplicationClient('https://builderad.test', async request => { attempts++; assert.deepEqual(await request.json(), original); throw Error('unknown delivery'); });
    await assert.rejects(client.POST('/workspaces/{workspace}/object-purposes/{purpose}/revisions', { params: { path: { workspace: 'workspace', purpose: reply.purpose_id } }, body: original }), /unknown delivery/); assert.equal(attempts, 1);
});
