import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const stored = new Map();
globalThis.sessionStorage = {
    get length() { return stored.size; }, key: index => [...stored.keys()][index] ?? null,
    getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key),
};
class HttpError extends Error { constructor(status, body = {}) { super('HTTP ' + status); this.status = status; this.body = body; } }
let sequence = 0;
async function fixture(send, { retain = false, principal = 7, context = 'a'.repeat(64), lifecycle = 1 } = {}) {
    if (!retain) stored.clear();
    const key = '__sourceCommands' + ++sequence;
    const runtime = { calls: [], status: ref('ready'), closures: [], HttpError, binding: { principal, context, lifecycle }, send };
    globalThis[key] = runtime;
    const ports = {
        vue: import.meta.resolve('vue'),
        '../spa/transport': moduleUrl(`const runtime = globalThis.${key};
            export const bootstrap = { identity: { binding: runtime.binding } };
            export const status = runtime.status, TransportError = runtime.HttpError;
            export const onClose = callback => runtime.closures.push(callback);`),
        './api': moduleUrl(`const runtime = globalThis.${key};
            const methods = new Proxy({}, { get: (_, method) => (...args) => { runtime.calls.push({ method, args }); return runtime.send(method, ...args); } });
            export const providers = methods, sources = methods;`),
    };
    let compiled = ts.transpileModule(await readFile(new URL('../resources/js/sources/commands.ts', import.meta.url), 'utf8'),
        { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries(withPresentationPorts(ports))) compiled = compiled.replaceAll("from '" + name + "'", 'from ' + JSON.stringify(target));
    const commands = await import(moduleUrl(compiled));
    return { commands, runtime, close: () => { runtime.status.value = 'closed'; runtime.closures.forEach(callback => callback()); } };
}
const original = () => ({ business: 'business-a', provider: 'google', source: 'source-a', effect: 'disconnect', body: { command_id: 'original-command', expected_version: 3 } });

test('Business unknown delivery survives reload with its exact owner and cannot collide with organizational or sibling commands', async () => {
    const view = await fixture(async () => { throw Error('lost acknowledgement'); });
    const command = { business: 'business-a', provider: 'google', source: 'source-a', effect: 'disconnect', body: { command_id: 'original-business-command', expected_version: 3 } };
    await assert.rejects(view.commands.submit(command));
    assert.equal(view.runtime.calls.length, 1);
    assert.deepEqual(view.runtime.calls[0].args[1], { kind: 'business', publicId: 'business-a' });
    assert.equal(view.commands.commandsFor({ kind: 'business', publicId: 'business-other' }, 'google').length, 0);
    assert.equal(view.commands.commandsFor({ kind: 'business', publicId: 'business-b' }, 'google').length, 0);
    const reloaded = await fixture(async () => ({ receipt: { version: 4 } }), { retain: true });
    assert.equal(reloaded.runtime.calls.length, 0, 'Reload never sends work automatically');
    const pending = reloaded.commands.commandsFor({ kind: 'business', publicId: 'business-a' }, 'google');
    assert.equal(pending.length, 1);
    assert.deepEqual(pending[0].command, command);
    await reloaded.commands.retry(pending[0]);
    assert.deepEqual(reloaded.runtime.calls[0].args.slice(1, 4), [{ kind: 'business', publicId: 'business-a' }, 'source-a', command.body]);
    assert.equal(reloaded.commands.pending.size, 0);
});

test('unknown delivery freezes the exact original Business/source/version and never replays on restore', async () => {
    const view = await fixture(async () => { throw Error('lost acknowledgement'); });
    const command = original(); await assert.rejects(view.commands.submit(command));
    command.body.expected_version = 9;
    assert.equal(view.runtime.calls.length, 1);
    const pending = [...view.commands.pending.values()][0];
    assert.equal(pending.command.body.expected_version, 3);
    assert.throws(() => view.commands.submit(command), /original/);
    assert.equal(view.commands.commandsFor({ kind: 'business', publicId: 'business-b' }, 'google').length, 0);
    const restored = await fixture(async () => ({ receipt: {} }), { retain: true });
    assert.equal(restored.runtime.calls.length, 0);
    await restored.commands.retry([...restored.commands.pending.values()][0]);
    assert.equal(restored.runtime.calls.length, 1);
    assert.deepEqual(restored.runtime.calls[0].args.slice(0, 4), ['google', {kind:'business', publicId:'business-a'}, 'source-a', { command_id: 'original-command', expected_version: 3 }]);
    assert.equal(restored.commands.pending.size, 0);
});

for (const code of [401, 403, 409, 419, 422]) test(`HTTP ${code} does not erase a prior unknown delivery or silently replace it`, async () => {
    const view = await fixture(async () => { throw Error('lost acknowledgement'); });
    await assert.rejects(view.commands.submit(original()));
    view.runtime.send = async () => { throw new HttpError(code); };
    await assert.rejects(view.commands.retry([...view.commands.pending.values()][0]));
    assert.equal(view.commands.pending.size, 1);
    assert.equal(view.runtime.calls.length, 2);
});

test('Snapchat HTTP 502 action-required is thrown unchanged, not promoted to an acknowledged renewal', async () => {
    const failure = new HttpError(502, { status: 'PROVIDER_ACTION_REQUIRED', reason: 'snapchat_credential_denied', access: { connection: 'REAUTHORIZATION_REQUIRED' }, source_id: 'source-a' });
    const view = await fixture(async () => { throw failure; });
    await assert.rejects(view.commands.submit({ ...original(), provider: 'snapchat', effect: 'renew' }), value => value === failure);
    assert.equal(view.commands.pending.size, 0);
    assert.equal(view.runtime.calls[0].method, 'renew');
});

test('Snapchat HTTP 200 success preserves the required receipt', async () => {
    const reply = { receipt: { version: 4 }, access: { version: 4 }, source_id: 'source-a' };
    const view = await fixture(async () => reply);
    assert.equal(await view.commands.submit({ ...original(), provider: 'snapchat', effect: 'renew' }), reply);
    assert.equal(view.commands.pending.size, 0);
});

test('closure hides pending data, ignores late failure and retains only originals for an authorized same-session document', async () => {
    let fail;
    const view = await fixture(() => new Promise((_resolve, reject) => { fail = reject; }));
    view.commands.remember({ kind: 'business', publicId: 'business-a' }, 'report:google:source-a', { command: { command_id: 'original' } });
    view.commands.remember({ kind: 'business', publicId: 'business-a' }, 'report:google:source-a:previous', [{ command: { command_id: 'archived-original' } }]);
    view.commands.remember({ kind: 'business', publicId: 'business-a' }, 'registry', { label: 'private unsent draft' });
    const request = view.commands.submit(original());
    view.close(); fail(Error('late acknowledgement loss')); await assert.rejects(request);
    assert.equal(view.commands.pending.size, 0);
    assert.equal(view.commands.restore({ kind: 'business', publicId: 'business-a' }, 'report:google:source-a'), undefined);
    const restored = await fixture(async () => ({}), { retain: true });
    assert.equal(restored.runtime.calls.length, 0); assert.equal(restored.commands.pending.size, 1);
    assert.deepEqual(restored.commands.restore({ kind: 'business', publicId: 'business-a' }, 'report:google:source-a'), { command: { command_id: 'original' } });
    assert.deepEqual(restored.commands.restore({ kind: 'business', publicId: 'business-a' }, 'report:google:source-a:previous'), [{ command: { command_id: 'archived-original' } }]);
    assert.equal(restored.commands.restore({ kind: 'business', publicId: 'business-a' }, 'registry'), undefined);
    await restored.commands.retry([...restored.commands.pending.values()][0]); assert.equal(restored.runtime.calls.length, 1);
    assert.deepEqual(restored.runtime.calls[0].args.slice(0, 4), ['google', {kind:'business', publicId:'business-a'}, 'source-a', original().body]);
});

for (const identity of [{ principal: 8 }, { lifecycle: 2 }, { context: 'b'.repeat(64) }]) test('a different account/session cannot restore earlier commands or report drafts', async () => {
    const view = await fixture(async () => { throw Error('lost'); });
    await assert.rejects(view.commands.submit(original()));
    view.commands.remember({ kind: 'business', publicId: 'business-a' }, 'report', { command_id: 'original' });
    const changed = await fixture(async () => ({}), { retain: true, ...identity });
    assert.equal(changed.commands.pending.size, 0); assert.equal(changed.commands.restore({ kind: 'business', publicId: 'business-a' }, 'report'), undefined);
    assert.equal(changed.runtime.calls.length, 0);
});


test('retired and mixed source command formats are rejected before delivery or restore',async()=>{
 const v=await fixture(async()=>{throw Error('lost');});
 assert.throws(()=>v.commands.submit({workspace:'old',effect:'audit',source:'source'}),/Retired/);
 assert.throws(()=>v.commands.submit({...original(),workspace:'old'}),/Retired/);
 assert.equal(v.runtime.calls.length,0);
 await assert.rejects(v.commands.submit(original()));
 for(const [key,value]of stored){const payload=JSON.parse(value);payload.pending[0].command.workspace='old';stored.set(key,JSON.stringify(payload));}
 const r=await fixture(async()=>({}),{retain:true});assert.equal(r.commands.pending.size,0);assert.equal(r.runtime.calls.length,0);
});
test('service approval uncertainty retains exact Business, approver path, revisions and purpose',async()=>{
 const v=await fixture(async()=>{throw Error('acknowledgement lost');});
 const command={business:'business-a',effect:'service',body:{command_id:'approval-original',service:'service-a',purpose:'Business metadata only',expected_revision:3,business_revision:7,authority_selection:{realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:51}]}}};
 const originalBody=structuredClone(command.body);await assert.rejects(v.commands.submit(command));command.body.business_revision=9;command.body.authority_selection.requirements[0].leaf_grant_id=99;
 const retained=[...v.commands.pending.values()][0];assert.deepEqual(retained.command.body,originalBody);
 v.runtime.send=async()=>{throw new HttpError(403);};await assert.rejects(v.commands.retry(retained));assert.deepEqual([...v.commands.pending.values()][0].command.body,originalBody);assert.equal(v.runtime.calls.length,2);
 assert.deepEqual(v.runtime.calls[1].args.slice(0,2),['business-a',originalBody]);
});

test('service qualification enrollment never grants access from acknowledgement or replaces an uncertain mandate',async()=>{
 const v=await fixture(async()=>{throw Error('lost');});
 const command={business:'business-a',effect:'service-enrollment',body:{command_id:'enrollment-original',confirmed:true,authority_reference:'Separately declared mandate',expires_at:'2026-12-01T00:00:00Z',mandate_grant_id:'17',authority_selection:{realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:51}]}}};
 const original=structuredClone(command);await assert.rejects(v.commands.submit(command));command.body.mandate_grant_id='99';
 const pending=[...v.commands.pending.values()][0];assert.deepEqual(pending.command,original);assert.equal(v.runtime.calls[0].method,'enrollServiceApproval');
 v.runtime.send=async()=>({data:{business_public_id:'business-a',grant_id:'52',revision:8}});await v.commands.retry(pending);
 assert.deepEqual(v.runtime.calls[1].args.slice(0,2),['business-a',original.body]);assert.equal(v.commands.pending.size,0);
 assert.equal(v.runtime.calls.length,2,'Only explicit enrollment attempts; no approval or administration grant follows automatically');
});
