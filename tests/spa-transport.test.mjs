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
async function transport(metadata = identity) {
    globalThis.document = { getElementById: () => ({ textContent: JSON.stringify({ identity: metadata, locale: 'ar' }) }) };
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

test('a pending consumer cancellation reaches the shared fetch without claiming server cancellation', async () => {
    const client = await transport(), caller = new AbortController(); let signal;
    globalThis.fetch = (_url, options) => new Promise((_resolve, reject) => { signal = options.signal; signal.addEventListener('abort', () => reject(new DOMException('Consumer cancelled', 'AbortError')), { once: true }); });
    const pending = client.protectedResponse('/provider-read', { signal: caller.signal }).catch(error => error);
    try { caller.abort(); assert.equal(signal.aborted, true, 'The existing feature AbortSignal must reach the single transport'); }
    finally { client.close(); await pending; }
});

test('an already cancelled consumer starts no request', async () => {
    const client = await transport(), caller = new AbortController(); let attempts = 0; caller.abort();
    globalThis.fetch = async () => { attempts++; return response(200, { private: 'cancelled consumer' }); };
    try { await assert.rejects(client.protectedResponse('/provider-read', { signal: caller.signal }), error => error.name === 'AbortError'); assert.equal(attempts, 0); }
    finally { client.close(); }
});

test('a cancelled consumer cannot publish a successful reply even when fetch ignores cancellation', async () => {
    const client = await transport(), caller = new AbortController(); let deliver;
    globalThis.fetch = () => new Promise(resolve => { deliver = resolve; });
    const pending = client.protectedResponse('/provider-read', { signal: caller.signal });
    caller.abort(); deliver(response(200, { private: 'cancelled consumer' }));
    try { await assert.rejects(pending, error => error.name === 'AbortError'); }
    finally { client.close(); }
});

test('observed identity failures still close the document after consumer cancellation', async () => {
    for (const [status, body, evidence] of [[419, { message: 'CSRF' }, identity], [200, {}, { ...identity, binding: { ...identity.binding, principal: 2 } }]]) {
        const client = await transport(), caller = new AbortController(); let deliver;
        globalThis.fetch = () => new Promise(resolve => { deliver = resolve; });
        const pending = client.protectedResponse('/provider-read', { signal: caller.signal });
        caller.abort(); deliver(response(status, body, evidence));
        await assert.rejects(pending);
        assert.equal(client.status.value, 'closed');
    }
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

test('all signed document binding dimensions must match before private response consumption', async () => {
    for (const key of Object.keys(identity.binding)) {
        const client = await transport();
        const changed = { ...identity, binding: { ...identity.binding, [key]: typeof identity.binding[key] === 'number' ? 99 : 'foreign' } };
        globalThis.fetch = async () => response(200, { private: 'foreign' }, changed);
        await assert.rejects(client.request('/private'), /Document binding changed/);
        assert.equal(client.status.value, 'closed', key);
    }
});

test('document CSRF and receipt own the shared request and ordinary replies cannot extend its lease', async () => {
    const client = await transport();
    let headers;
    globalThis.fetch = async (_url, options) => { headers = options; return response(200, {}, { ...identity, csrf: 'next-csrf', receipt: 'next-receipt', leaseMilliseconds: 999999 }); };
    await client.request('/private');
    assert.equal(headers.credentials, 'same-origin'); assert.equal(headers.redirect, 'manual'); assert.equal(headers.cache, 'no-store');
    assert.equal(headers.headers['X-CSRF-TOKEN'], 'csrf'); assert.equal(headers.headers['X-Builderad-Document'], identity.receipt);
    assert.deepEqual(client.documentEvidence(), { csrf: 'next-csrf', receipt: 'next-receipt', transition: identity.transition });
    client.close();
});

test('one current lease timer survives repeated arming; suspension and terminal closure suppress queued renewal', async context => {
    context.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: 0 });
    const client = await transport(); let calls = 0;
    globalThis.fetch = async () => { calls++; return response(200, { identity }); };
    try {
        client.status.value = 'ready'; client.resumeLease(); client.resumeLease(); client.resumeLease();
        context.mock.timers.tick(41999); assert.equal(calls, 0);
        context.mock.timers.tick(1); await new Promise(setImmediate); assert.equal(calls, 1);
        client.suspend(); context.mock.timers.tick(60000); await new Promise(setImmediate); assert.equal(calls, 1);
        client.status.value = 'ready'; client.resumeLease(); assert.equal(client.status.value, 'unavailable');
        client.close(); context.mock.timers.tick(120000); assert.equal(calls, 1);
    } finally { client.close(); context.mock.timers.reset(); }
});

test('renewal failure stops without polling or automatic replacement and a late renewal cannot reopen closure', async context => {
    context.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: 0 });
    const client = await transport(); let calls = 0, deliver;
    globalThis.fetch = () => { calls++; return new Promise(resolve => { deliver = resolve; }); };
    try {
        client.status.value = 'ready'; client.resumeLease(); context.mock.timers.tick(42000);
        client.close(); deliver(response(200, { identity })); await new Promise(setImmediate);
        context.mock.timers.tick(120000); assert.equal(client.status.value, 'closed'); assert.equal(calls, 1);
    } finally { client.close(); context.mock.timers.reset(); }
});

test('delayed JSON cannot mutate evidence after the document revision changes', async () => {
    const client = await transport(); let deliver, started;
    const entered = new Promise(resolve => { started = resolve; });
    globalThis.fetch = async () => ({ clone: () => ({ headers: new Headers({ 'Content-Type': 'application/json' }), json: () => { started(); return new Promise(resolve => { deliver = resolve; }); } }) });
    const pending = client.protectedResponse('/private'); await entered; client.suspend(); deliver({});
    await assert.rejects(pending, /Obsolete response/); assert.equal(client.documentEvidence().receipt, identity.receipt); client.close();
});

test('logout validates its captured nonce, lineage, next epoch, boundary and destination before native departure', async () => {
    const metadata = { ...identity, transition: Buffer.from(JSON.stringify({ binding: { nonce: 'captured' } })).toString('base64url') + '.signature' };
    const valid = { lineage: 'lineage', boundary: 'advertiser', epoch: 2, nonce: 'captured', kind: 'logout', status: 'closed' };
    for (const invalid of [null, { nonce: 'other' }, { lineage: 'other' }, { epoch: 3 }, { boundary: 'internal' }, { kind: 'login' }, { status: 'active' }, { redirect: '/internal/login' }]) {
        let calls = 0, destination;
        globalThis.location = { origin: 'https://builderad.test', replace: value => { destination = value; } };
        const client = await transport(metadata);
        globalThis.fetch = async () => { calls++; return Response.json({ redirect: invalid?.redirect ?? '/', transition: { ...valid, ...invalid } }); };
        await client.logout();
        assert.equal(client.status.value, 'closed'); assert.equal(calls, 1); assert.equal(destination, invalid ? undefined : '/');
        await assert.rejects(client.request('/private'), /Document is closed/);
    }
});

test('lost authentication ACK never replays credentials or admits a new document in place', async () => {
    const client = await transport(); let calls = 0;
    globalThis.fetch = async () => { calls++; throw Error('lost authentication ACK'); };
    await assert.rejects(client.protectedResponse('/login', { method: 'POST', body: '{"password":"test"}' }, 'login'), /lost authentication ACK/);
    assert.equal(calls, 1); assert.equal(client.status.value, 'checking'); client.close();
});

test('ordinary metadata cannot extend lease expiry and failed renewal stops without polling', async context => {
    context.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: 0 });
    const client = await transport(); let renewals = 0;
    globalThis.fetch = async url => {
        if (url === '/identity/renew') { renewals++; throw Error('Renewal unavailable'); }
        return response(200, {}, { ...identity, leaseMilliseconds: 999999 });
    };
    try {
        await client.request('/private'); client.status.value = 'ready'; client.resumeLease();
        context.mock.timers.tick(42000); await new Promise(setImmediate);
        assert.equal(renewals, 1); assert.equal(client.status.value, 'unavailable');
        context.mock.timers.tick(120000); await new Promise(setImmediate); assert.equal(renewals, 1);
        assert.notEqual(client.status.value, 'ready');
    } finally { client.close(); context.mock.timers.reset(); }
});
