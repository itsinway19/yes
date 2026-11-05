import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const input = await readFile('resources/js/sources/businessReturn.ts', 'utf8');
const destination = url(ts.transpileModule(await readFile('resources/js/sources/providerDestination.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText);
let serial = 0;
function storage(entries = []) {
    const map = new Map(entries);
    return { get length() { return map.size; }, key: index => [...map.keys()][index], getItem: key => map.get(key) ?? null,
        setItem: (key, value) => map.set(key, value), removeItem: key => map.delete(key), map };
}
async function load(principal, store, lifecycle = 1) {
    globalThis.sessionStorage = store;
    const transport = url(`export const identity=${++serial};export const callbacks=[];export const bootstrap={identity:{binding:{boundary:'advertiser',principal:${principal},lifecycle:${lifecycle},context:'same-browser-session',credential:1}}};export const onClose=f=>callbacks.push(f);`);
    const compiled = ts.transpileModule(input, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
        .replace("'../spa/transport'", JSON.stringify(transport)).replace("'./providerDestination'", JSON.stringify(destination));
    return { module: await import(url(compiled)), transport: await import(transport) };
}
const business = '01a1a220-7dcf-7aa7-851b-4c490880c314', workspace = '01a10d55-979d-78dc-9fd3-e49cf4f649e0';
const target = { business, workspace, access: '52', provider: 'meta' };
test('return survives a full document but only within its current principal, lifecycle and selected administration', async () => {
    const store = storage(); const original = await load(1, store);
    original.module.rememberBusinessReturn(target);
    assert.equal(store.length, 1);
    const callback = await load(1, store);
    assert.deepEqual(callback.module.businessReturn(workspace), target);
    assert.equal(callback.module.businessReturn(business), undefined);
    assert.equal(callback.module.businessReturnPath(target), `/businesses/${business}?access=52&section=sources`);
    const changed = await load(2, store);
    assert.equal(changed.module.businessReturn(workspace), undefined); assert.equal(store.length, 0);
    changed.module.rememberBusinessReturn(target);
    const generation = await load(2, store, 2);
    assert.equal(generation.module.businessReturn(workspace), undefined);
});
test('storage/query values never produce an external redirect, unsafe access or new permission', async () => {
    const store = storage(); const { module, transport } = await load(1, store);
    for (const value of [{ ...target, business: 'https://evil.test' }, { ...target, access: '9007199254740993' }, { ...target, workspace: '../account' }, { ...target, provider: 'unknown' }]) {
        module.rememberBusinessReturn(value); assert.equal(store.length, 0);
    }
    module.rememberBusinessReturn(target);
    for (const callback of transport.callbacks) callback();
    assert.equal(module.businessReturn(workspace), undefined); assert.equal(store.length, 0);
});
test('unavailable storage preserves an in-document return and does not break source administration', async () => {
    const unavailable = { get length() { throw Error('Storage denied'); }, setItem() { throw Error('Storage denied'); }, removeItem() { throw Error('Storage denied'); } };
    const { module } = await load(1, unavailable);
    module.rememberBusinessReturn(target); assert.deepEqual(module.businessReturn(workspace), target);
    delete globalThis.sessionStorage;
});
