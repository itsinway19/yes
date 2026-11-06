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
async function fixture(send, { retain = false, principal = 7, context = 'a'.repeat(64) } = {}) {
    if (!retain) stored.clear();
    const key = '__sourceCommands' + ++sequence;
    const runtime = { calls: [], status: ref('ready'), closures: [], HttpError, binding: { principal, context }, send };
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
const original = () => ({ workspace: 'workspace-a', provider: 'google', source: 'source-a', effect: 'disconnect', body: { command_id: 'original-command', expected_version: 3 } });

test('Business unknown delivery survives reload with its exact owner and cannot collide with organizational or sibling commands', async () => {
    const view = await fixture(async () => { throw Error('lost acknowledgement'); });
    const command = { business: 'business-a', provider: 'google', source: 'source-a', effect: 'disconnect', body: { command_id: 'original-business-command', expected_version: 3 } };
    await assert.rejects(view.commands.submit(command));
    assert.equal(view.runtime.calls.length, 1);
    assert.deepEqual(view.runtime.calls[0].args[1], { kind: 'business', publicId: 'business-a' });
    assert.equal(view.commands.commandsFor('business-a', 'google').length, 0);
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

test('unknown delivery freezes the exact original workspace/source/version and never replays on restore', async () => {
    const view = await fixture(async () => { throw Error('lost acknowledgement'); });
    const command = original(); await assert.rejects(view.commands.submit(command));
    command.body.expected_version = 9;
    assert.equal(view.runtime.calls.length, 1);
    const pending = [...view.commands.pending.values()][0];
    assert.equal(pending.command.body.expected_version, 3);
    assert.throws(() => view.commands.submit(command), /original/);
    assert.equal(view.commands.commandsFor('workspace-b', 'google').length, 0);
    const restored = await fixture(async () => ({ receipt: {} }), { retain: true });
    assert.equal(restored.runtime.calls.length, 0);
    await restored.commands.retry([...restored.commands.pending.values()][0]);
    assert.equal(restored.runtime.calls.length, 1);
    assert.deepEqual(restored.runtime.calls[0].args.slice(0, 4), ['google', 'workspace-a', 'source-a', { command_id: 'original-command', expected_version: 3 }]);
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
    view.commands.remember('workspace-a', 'report:google:source-a', { command: { command_id: 'original' } });
    view.commands.remember('workspace-a', 'report:google:source-a:previous', [{ command: { command_id: 'archived-original' } }]);
    view.commands.remember('workspace-a', 'registry', { label: 'private unsent draft' });
    const request = view.commands.submit(original());
    view.close(); fail(Error('late acknowledgement loss')); await assert.rejects(request);
    assert.equal(view.commands.pending.size, 0);
    assert.equal(view.commands.restore('workspace-a', 'report:google:source-a'), undefined);
    const restored = await fixture(async () => ({}), { retain: true });
    assert.equal(restored.runtime.calls.length, 0); assert.equal(restored.commands.pending.size, 1);
    assert.deepEqual(restored.commands.restore('workspace-a', 'report:google:source-a'), { command: { command_id: 'original' } });
    assert.deepEqual(restored.commands.restore('workspace-a', 'report:google:source-a:previous'), [{ command: { command_id: 'archived-original' } }]);
    assert.equal(restored.commands.restore('workspace-a', 'registry'), undefined);
    await restored.commands.retry([...restored.commands.pending.values()][0]); assert.equal(restored.runtime.calls.length, 1);
    assert.deepEqual(restored.runtime.calls[0].args.slice(0, 4), ['google', 'workspace-a', 'source-a', original().body]);
});

for (const identity of [{ principal: 8 }, { context: 'b'.repeat(64) }]) test('a different account/session cannot restore earlier commands or report drafts', async () => {
    const view = await fixture(async () => { throw Error('lost'); });
    await assert.rejects(view.commands.submit(original()));
    view.commands.remember('workspace-a', 'report', { command_id: 'original' });
    const changed = await fixture(async () => ({}), { retain: true, ...identity });
    assert.equal(changed.commands.pending.size, 0); assert.equal(changed.commands.restore('workspace-a', 'report'), undefined);
    assert.equal(changed.runtime.calls.length, 0);
});
