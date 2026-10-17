import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import 'vue';

const compiled = ts.transpileModule(await readFile(new URL('../resources/js/spa/transport.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    .replace("from 'vue'", `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace("from '../sessionLease'", `from ${JSON.stringify(new URL('../resources/js/sessionLease.ts', import.meta.url).href)}`);

const identity = {
    receipt: 'server-document-receipt', transition: 'server-transition', csrf: 'csrf', leaseMilliseconds: 60000,
    binding: { lineage: 'lineage', epoch: 1, boundary: 'advertiser', credential: 'credential',
        principal: 1, lifecycle: 1, context: 'context', generation: 'generation' },
};
globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ identity, locale: 'ar' }) }) };
globalThis.location = { origin: 'https://builderad.test' };
let sequence = 0;
async function transport() {
    return import('data:text/javascript;base64,' + Buffer.from(compiled + `\n;void ${++sequence};`).toString('base64'));
}
const response = (status, body, evidence = identity) => Response.json(body, {
    status, headers: { 'X-Builderad-Identity': JSON.stringify(evidence) },
});

test('late responses cannot publish after presentation ownership is suspended', async () => {
    const client = await transport();
    let deliver;
    globalThis.fetch = () => new Promise(resolve => { deliver = resolve; });
    const pending = client.protectedResponse('/read');
    client.suspend();
    deliver(response(200, { private: 'old workspace' }));
    await assert.rejects(pending, /Obsolete response/);
    client.close();
});

test('CAS and permission errors preserve their contract while identity/CSRF failures close the document', async () => {
    for (const [status, body, terminal] of [
        [409, { message: 'CAS' }, false], [403, { message: 'forbidden' }, false],
        [422, { errors: { value: ['invalid'] } }, false],
        [401, { message: 'expired' }, true], [419, { message: 'CSRF' }, true],
        [409, { identityConflict: true }, true],
    ]) {
        const client = await transport();
        client.status.value = 'ready';
        globalThis.fetch = async () => response(status, body);
        await assert.rejects(client.protectedResponse('/read'), error => {
            assert.equal(error.status, status);
            assert.deepEqual(error.body, body);
            return true;
        });
        assert.equal(client.status.value, terminal ? 'closed' : 'ready');
        client.close();
    }
});

test('changed identity binding closes presentation and cannot deliver successful private data', async () => {
    const client = await transport();
    globalThis.fetch = async () => response(200, { private: 'other principal' }, {
        ...identity, binding: { ...identity.binding, principal: 2 },
    });
    await assert.rejects(client.protectedResponse('/read'), /Document binding changed/);
    assert.equal(client.status.value, 'closed');
});

test('uncertain mutation delivery is attempted once and reported without replay', async () => {
    const client = await transport();
    let attempts = 0;
    globalThis.fetch = async () => { attempts++; throw Error('lost acknowledgement'); };
    await assert.rejects(client.protectedResponse('/save', { method: 'POST' }), /lost acknowledgement/);
    assert.equal(attempts, 1);
    client.close();
});

test('the shared transport refuses cross-origin requests before sending identity evidence', async () => {
    const client = await transport();
    globalThis.fetch = () => assert.fail('Identity evidence must remain same-origin');
    await assert.rejects(client.protectedResponse('https://other.test/read'), /Application origin required/);
    client.close();
});
