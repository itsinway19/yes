import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import 'vue';

const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
let sequence = 0;
async function load(internalBoundary = false) {
    const runtime = globalThis.__spaLocaleTest = { calls: [], root: {}, reply: { data: { locale: 'en' } } };
    globalThis.document = { documentElement: runtime.root };
    const ports = {
        vue: import.meta.resolve('vue'),
        './transport': url(`export const bootstrap={locale:'ar'},internalBoundary=${internalBoundary};`),
        './api': url(`export const api={POST:async(path,options)=>{const state=globalThis.__spaLocaleTest;state.calls.push({path,options});return state.reply}};`),
        '../localization/documentLocale': new URL('../resources/js/localization/documentLocale.ts', import.meta.url).href,
    };
    let source = ts.transpileModule(await readFile(new URL('../resources/js/spa/locale.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
    for (const [name, target] of Object.entries(withPresentationPorts(ports))) source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(target)}`);
    return { runtime, client: await import(url(source + `\nvoid ${++sequence};`)) };
}

test('actual SPA locale command posts only locale to the current boundary, never drafts or a remount', async () => {
    for (const internal of [false, true]) {
        const { runtime, client } = await load(internal);
        await client.switchLocale();
        assert.deepEqual(runtime.calls, [{ path: internal ? '/internal/locale' : '/locale', options: { body: { locale: 'en' } } }]);
        assert.equal(client.locale.value, 'en');
        assert.deepEqual(runtime.root, { lang: 'en', dir: 'ltr' });
        assert.equal(client.localeBusy.value, false);
    }
});

test('missing locale ACK cannot optimistically change language or replay the command', async () => {
    const { runtime, client } = await load();
    runtime.reply = {};
    await assert.rejects(client.switchLocale(), /Missing locale acknowledgement/);
    assert.equal(client.locale.value, 'ar');
    assert.deepEqual(runtime.root, {});
    assert.equal(runtime.calls.length, 1);
    assert.equal(client.localeBusy.value, false);
});
