import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import Ajv from 'ajv/dist/2020.js';
import { createApplicationClient } from '../resources/js/api/application.ts';
import { sourceOperations } from '../resources/js/sources/client.ts';
import { spec } from '../scripts/application-openapi.mjs';

test('Source operations preserve exact existing paths, verbs, bodies and consumer cancellation', async () => {
    const seen = [], caller = new AbortController();
    const client = sourceOperations(createApplicationClient('https://builderad.test', async request => {
        seen.push({ path: new URL(request.url).pathname, method: request.method,
            body: request.body ? await request.json() : null, passive: request.headers.get('X-Builderad-Passive') });
        caller.signal.addEventListener('abort', () => assert.equal(request.signal.aborted, true), { once: true });
        return Response.json({ acknowledged: true });
    }));
    const scope = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'advertising_analysis', reason: 'owner_explicit_request' };
    const command = { ...scope, command_id: 'c', effect: 'declare_scope', expected_version: 7 };
    await client.read('w', { 'X-Builderad-Passive': '1' }, caller.signal);
    await client.grant('w', { ...scope, action: 'source.manage', decision: 'authorize' }, caller.signal);
    await client.command('w', command, caller.signal);
    await client.recover('w', 'o', caller.signal);
    await client.authorizeService('w', { service: 'service', purpose: 'Explicit scope' }, caller.signal);
    await client.revokeService('w', 'service', caller.signal);
    caller.abort();
    assert.deepEqual(seen.map(value => [value.method, value.path]), [
        ['GET', '/workspaces/w'], ['POST', '/workspaces/w/source-grants'], ['POST', '/workspaces/w/sources'],
        ['POST', '/workspaces/w/source-audit/o/recover'], ['POST', '/workspaces/w/services'], ['DELETE', '/workspaces/w/services/service'],
    ]);
    assert.equal(seen[0].passive, '1');
    assert.deepEqual(seen[2].body, command);
    assert.equal(seen[3].body, null);
    assert.equal(seen[5].body, null);
});

test('Source delivery remains one attempt with the original command after unknown acknowledgement', async () => {
    const attempts = [], command = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'analysis', reason: 'owner_scope_declaration', command_id: 'original', effect: 'declare_scope', expected_version: 2 };
    const client = sourceOperations(createApplicationClient('https://builderad.test', async request => { attempts.push(await request.json()); throw Error('lost acknowledgement'); }));
    await assert.rejects(client.command('w', command), /lost acknowledgement/);
    assert.deepEqual(attempts, [command]);
});

test('Source command contract rejects invented provider evidence and incompatible effect fields', () => {
    const validator = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/SourceCommand' });
    const command = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'analysis', reason: 'owner_scope_declaration', command_id: 'c', effect: 'declare_scope', expected_version: 2 };
    assert.equal(validator(command), true);
    for (const extra of [{ provider_permission: 'VERIFIED' }, { token: 'never' }, { label: 'not for scope declarations' }, { expected_version: -1 }]) assert.equal(validator({ ...command, ...extra }), false);
    assert.equal(validator({ ...command, effect: 'register' }), false);
    assert.equal(validator({ ...command, effect: 'rename', label: 'Corrected label' }), true);
});

test('commercial context is absent as a whole or contains the required non-null market UUID', () => {
    const validate = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/WorkspaceSourcesData/properties/commercial' });
    const commercial = { state: 'UNAVAILABLE', context: null, agreement_state: 'UNKNOWN', amount_state: 'UNKNOWN' };
    assert.equal(validate(commercial), true);
    const context = { scope_id: 'scope', market_id: '9465e77a-c79a-4001-931f-98ed673397d9', market_label: 'Synthetic market', configuration_id: 'configuration', catalog_identity: 'catalog', catalog_version: '1', denomination_code: 'JOD', effective_at: '2026-10-18T00:00:00+03:00' };
    assert.equal(validate({ ...commercial, state: 'CONTEXT_RECORDED', context }), true);
    assert.equal(validate({ ...commercial, context: { ...context, market_id: null } }), false);
    const { market_id, ...missingMarket } = context;
    assert.equal(validate({ ...commercial, context: missingMarket }), false);
});

test('the real generated-client adapter forwards cancellation to the one protected transport', async () => {
    let captured;
    globalThis.__sourceTransport = async (_url, options) => { captured = options; return Response.json({ ok: true }); };
    const compiled = ts.transpileModule(await readFile(new URL('../resources/js/spa/api.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
        .replace("from '../api/application'", `from ${JSON.stringify(new URL('../resources/js/api/application.ts', import.meta.url).href)}`)
        .replace("import { protectedResponse } from './transport';", 'const protectedResponse = globalThis.__sourceTransport;');
    globalThis.location = { origin: 'https://builderad.test' };
    try {
        const { api } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));
        const caller = new AbortController();
        await api.GET('/workspaces/{workspace}', { params: { path: { workspace: 'w' } }, signal: caller.signal });
        caller.abort();
        assert.equal(captured.signal.aborted, true);
        assert.equal(captured.method, 'GET');
    } finally { delete globalThis.__sourceTransport; }
});
