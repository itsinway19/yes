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
    const grant = { ...scope, command_id: 'grant-original', action: 'source.manage', decision: 'authorize' };
    await client.grant('w', grant, caller.signal);
    await client.command('w', command, caller.signal);
    await client.recover('w', 'o', caller.signal);
    const approval={command_id:'approval',service:'service',purpose:'Explicit scope',expected_revision:0,business_revision:1,authority_selection:{realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:7}]}};
    await client.authorizeService('w', approval, caller.signal);
    await client.revokeService('w', 'service', approval, caller.signal);
    caller.abort();
    assert.deepEqual(seen.map(value => [value.method, value.path]), [
        ['GET', '/businesses/w/source-registry'], ['POST', '/businesses/w/source-grants'], ['POST', '/businesses/w/sources'],
        ['POST', '/businesses/w/source-audit/o/recover'], ['POST', '/businesses/w/services'], ['DELETE', '/businesses/w/services/service'],
    ]);
    assert.equal(seen[0].passive, '1');
    assert.deepEqual(seen[1].body, grant);
    assert.deepEqual(seen[2].body, command);
    assert.equal(seen[3].body, null);
    assert.deepEqual(seen[4].body, approval);
    assert.deepEqual(seen[5].body, approval);
});

test('Source delivery remains one attempt with the original command after unknown acknowledgement', async () => {
    const attempts = [], command = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'analysis', reason: 'owner_scope_declaration', command_id: 'original', effect: 'declare_scope', expected_version: 2 };
    const client = sourceOperations(createApplicationClient('https://builderad.test', async request => { attempts.push(await request.json()); throw Error('lost acknowledgement'); }));
    await assert.rejects(client.command('w', command), /lost acknowledgement/);
    assert.deepEqual(attempts, [command]);
});

test('Source authority delivery retains the exact decision id after a lost acknowledgement', async () => {
    const attempts = [], decision = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'analysis', reason: 'owner_explicit_request', command_id: 'grant-original', action: 'source.read', decision: 'revoke', expires_at: null };
    const client = sourceOperations(createApplicationClient('https://builderad.test', async request => { attempts.push(await request.json()); throw Error('lost acknowledgement'); }));
    await assert.rejects(client.grant('b', decision), /lost acknowledgement/);
    assert.deepEqual(attempts, [decision]);
});

test('Source command contract rejects invented provider evidence and incompatible effect fields', () => {
    const validator = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/SourceCommand' });
    const command = { source_id: 's', resource: 'r', operation: 'report.read', purpose: 'analysis', reason: 'owner_scope_declaration', command_id: 'c', effect: 'declare_scope', expected_version: 2 };
    assert.equal(validator(command), true);
    for (const extra of [{ provider_permission: 'VERIFIED' }, { token: 'never' }, { label: 'not for scope declarations' }, { expected_version: -1 }]) assert.equal(validator({ ...command, ...extra }), false);
    assert.equal(validator({ ...command, effect: 'register' }), false);
    assert.equal(validator({ ...command, effect: 'rename', label: 'Corrected label' }), true);
});

test('Source authority contract requires a stable command id and rejects unbound fields', () => {
    const validate = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/SourceAuthorityCommand' });
    const decision = { command_id: '9465e77a-c79a-4001-931f-98ed673397d9', source_id: '9465e77a-c79a-4001-931f-98ed673397d9',
        resource: '123', operation: 'report.read', purpose: 'advertising_analysis', reason: 'owner_explicit_request',
        action: 'source.read', decision: 'revoke', expires_at: null };
    assert.equal(validate(decision), true);
    const { command_id, ...withoutId } = decision;
    assert.equal(validate(withoutId), false);
    assert.equal(validate({ ...decision, workspace_id: 'retired' }), false);
});

test('commercial context is absent as a whole or contains the required non-null market UUID', () => {
    const validate = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/BusinessSurfaceResult/properties/data/properties/commercial' });
    const id = '9465e77a-c79a-4001-931f-98ed673397d9';
    const commercial = { beneficiary: { kind: 'business', public_id: id }, context_state: 'QUALIFIED_BUSINESS_BINDING_UNAVAILABLE',
        context: null, subscription_state: 'UNKNOWN', entitlement_state: 'UNKNOWN', quota_state: 'UNKNOWN',
        payer_state: 'UNKNOWN', coverage_state: 'UNKNOWN', amount_state: 'UNKNOWN', conditioned_feature_admission: 'HOLD',
        usage_reservation: 'NOT_CREATED', foundation_declarations: { entitlement: 'NOT_APPLICABLE', quota: 'NOT_APPLICABLE', billing: 'NOT_APPLICABLE' },
        limitation: 'NO_OPERATING_SUBSCRIPTION_OR_METERING_POLICY' };
    assert.equal(validate(commercial), true);
    const context = { scope_id: id, market_id: id, market_label: 'Synthetic market', configuration_id: id,
        catalog_identity: id, catalog_version: '1', denomination_code: 'JOD', effective_at: '2026-10-18T00:00:00+03:00' };
    const recorded = { ...commercial, context_state: 'CONTEXT_RECORDED', context };
    assert.equal(validate(recorded), true);
    assert.equal(validate({ ...commercial, context }), false);
    assert.equal(validate({ ...recorded, context: null }), false);
    assert.equal(validate({ ...recorded, context: { ...context, market_id: null } }), false);
    const { market_id, ...missingMarket } = context;
    assert.equal(validate({ ...recorded, context: missingMarket }), false);
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
        await api.GET('/businesses/{business}', { params: { path: { business: 'b' } }, signal: caller.signal });
        caller.abort();
        assert.equal(captured.signal.aborted, true);
        assert.equal(captured.method, 'GET');
    } finally { delete globalThis.__sourceTransport; }
});
