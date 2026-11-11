import test from 'node:test';
import assert from 'node:assert/strict';
import { createApplicationClient } from '../resources/js/api/application.ts';
import { applicationDestination } from '../resources/js/spa/destination.ts';

test('Business mandate withdrawal exposes bounded paginated groups and preserves the exact group CAS after unknown delivery', async () => {
    const { spec } = await import('../scripts/application-openapi.mjs');
    const path = '/api/businesses/{business}/agency-mandates', business = '11111111-1111-7111-8111-111111111111', agency = '22222222-2222-7222-8222-222222222222';
    assert.ok(spec.paths[path].get.parameters.some(value => value.name === 'after' && value.schema.format === 'uuid'));
    assert.ok(spec.components.schemas.BusinessAgencyMandates.required.includes('next_cursor'));
    const schema = spec.components.schemas.BusinessAgencyMandatesCommand;
    assert.equal(schema.additionalProperties, false); assert.equal(schema.properties.expected_grants.maxItems, 256);
    assert.equal(schema.properties.authority_selection.$ref, '#/components/schemas/BusinessAuthoritySelection');
    const body = { command_id: crypto.randomUUID(), authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] }, agency_business_public_id: agency, expected_grants: [{ grant_id: '81', version: 3 }, { grant_id: '82', version: 4 }], confirmed: true };
    const calls = [];
    const client = createApplicationClient('https://builderad.test', async request => {
        calls.push(request.method);
        const url = new URL(request.url); assert.equal(url.pathname, `/api/businesses/${business}/agency-mandates`);
        if (request.method === 'GET') { assert.equal(url.searchParams.get('after'), agency); assert.equal(url.searchParams.get('access'), '42'); return Response.json({ data: { business_public_id: business, items: [], more: false, next_cursor: null } }); }
        assert.deepEqual(await request.json(), body); throw Error('Withdrawal acknowledgement lost');
    });
    await client.GET(path, { params: { path: { business }, query: { access: 42, after: agency } } });
    await assert.rejects(client.POST(path, { params: { path: { business } }, body }), /acknowledgement lost/);
    assert.deepEqual(calls, ['GET', 'POST'], 'No automatic withdrawal retry or group reconstruction');
});

test('Business agency commands expose finite explicit Business authority and preserve uncertain delivery', async () => {
    const { spec } = await import('../scripts/application-openapi.mjs');
    const operation = spec.paths['/api/businesses/{business}/agency-grants']?.post;
    assert.ok(operation, 'The implemented Business agency writer must have a public typed contract');
    const schema = spec.components.schemas.BusinessAgencyGrantCommand;
    assert.equal(schema.additionalProperties, false);
    assert.deepEqual([...schema.required].sort(), ['command_id', 'authority_selection', 'effect', 'agency_business_public_id',
        'recipient_public_id', 'membership_grant_id', 'action', 'parent_grant_id', 'expires_at', 'confirmed'].sort());
    assert.deepEqual(schema.properties.effect.enum, ['mandate', 'assign']);
    assert.deepEqual(schema.properties.action.enum, ['business.context.read', 'business.context.manage', 'business.intent.declare',
        'business.markets.declare', 'business.preferences.manage']);
    assert.equal(schema.properties.confirmed.const, true);
    assert.equal(schema.properties.expires_at.format, 'date-time');
    assert.equal(schema.properties.workspace_public_id, undefined);
    assert.equal(schema.properties.authority_selection.$ref, '#/components/schemas/BusinessAuthoritySelection');
    assert.match(operation.description, /realm_workspace_public_id JSON null/);
    const discovery = spec.paths['/api/businesses/{business}/agency-options'].get;
    assert.deepEqual(discovery.parameters.filter(parameter => parameter.in === 'query').map(parameter => [parameter.name, parameter.required === true]),
        [['access', true], ['agency', true], ['recipient', false]]);
    const surfaceSchema = spec.components.schemas.BusinessSurface;
    for (const projected of [spec.components.schemas.BusinessChoices.properties.items.items, surfaceSchema,
        surfaceSchema.properties.delegation.properties.options.items, surfaceSchema.properties.delegation.properties.issued.items]) {
        assert.ok(projected.properties.agency_business_public_id);
        assert.equal(projected.required.includes('agency_business_public_id'), false, 'Historical installed projections may omit display metadata');
    }
    const business = '11111111-1111-7111-8111-111111111111';
    const body = { command_id: '22222222-2222-4222-8222-222222222222', effect: 'assign',
        agency_business_public_id: '33333333-3333-7333-8333-333333333333', recipient_public_id: '44444444-4444-7444-8444-444444444444',
        membership_grant_id: 47, action: 'business.context.read', parent_grant_id: 42, expires_at: '2030-01-01T00:00:00Z', confirmed: true,
        authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] } };
    let calls = 0;
    const client = createApplicationClient('https://builderad.test', async request => {
        calls++;
        assert.equal(request.url, `https://builderad.test/api/businesses/${business}/agency-grants`);
        assert.deepEqual(await request.json(), body);
        throw Error('agency acknowledgement lost');
    });
    await assert.rejects(client.POST('/api/businesses/{business}/agency-grants', { params: { path: { business } }, body }), /agency acknowledgement lost/);
    assert.equal(calls, 1);
});

test('application creation sends the exact original command once and never retries uncertain delivery', async () => {
    const command = { name: 'An organization', creation_key: '11111111-1111-4111-8111-111111111111' };
    let attempts = 0;
    const client = createApplicationClient('https://builderad.test', async request => {
        attempts++;
        assert.equal(request.url, 'https://builderad.test/organizations');
        assert.equal(request.method, 'POST');
        assert.deepEqual(await request.json(), command);
        throw Error('acknowledgement lost after possible commit');
    });
    await assert.rejects(client.POST('/organizations', { body: command }), /acknowledgement lost/);
    assert.equal(attempts, 1);
});

test('native document destinations remain on the application origin and identity boundary', () => {
    assert.equal(applicationDestination('/workspaces/a/commercial-history', 'https://builderad.test', 'advertiser'), 'https://builderad.test/workspaces/a/commercial-history');
    for (const target of ['https://other.test/workspaces', '//other.test/path', 'javascript:alert(1)', '/internal', '/internal/security/devices']) {
        assert.throws(() => applicationDestination(target, 'https://builderad.test', 'advertiser'));
    }
    assert.throws(() => applicationDestination('/workspaces', 'https://builderad.test', 'internal'));
});

test('independent Business commands retain the exact subject and selected realm without Workspace inference or unknown-write retry', async () => {
    const business = '11111111-1111-7111-8111-111111111111';
    const body = { command_id: '22222222-2222-4222-8222-222222222222', name: 'Explicit business', business_type_id: null, expected_context: 7,
        authority_selection: { realm_kind: 'agency', realm_workspace_public_id: '33333333-3333-7333-8333-333333333333', requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] } };
    let calls = 0;
    const client = createApplicationClient('https://builderad.test', async request => {
        calls++;
        assert.equal(request.url, `https://builderad.test/api/businesses/${business}/context`);
        assert.equal(request.method, 'PUT');
        assert.deepEqual(await request.json(), body);
        throw Error('unknown outcome');
    });
    await assert.rejects(client.PUT('/api/businesses/{business}/context', { params: { path: { business } }, body }), /unknown outcome/);
    assert.equal(calls, 1);
    assert.equal(body.expected_context, 7);
    assert.equal(body.authority_selection.requirements[0].leaf_grant_id, 42);
});

test('Business fact delivery preserves each selected action, declaration and original version after a lost response', async () => {
    const business = '11111111-1111-7111-8111-111111111111';
    const commands = [
        ['markets', { codes: ['SA', 'JO'], expected_context: 7 }],
        ['intent', { value: null, confirmations: { declaration: true }, expected_context: 8, expected_intent: 3 }],
        ['preferences', { value: { goals: ['sales'], priority: 'sales', results: { sales: ['actual_sales'] } }, expected_preference: 2 }],
    ];
    for (const [kind, fact] of commands) {
        const body = { command_id: '22222222-2222-4222-8222-222222222222', ...fact,
            authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] } };
        let calls = 0;
        const client = createApplicationClient('https://builderad.test', async request => {
            calls++;
            assert.equal(request.url, `https://builderad.test/api/businesses/${business}/${kind}`);
            assert.equal(request.method, 'PUT');
            assert.deepEqual(await request.json(), body);
            throw Error('unknown fact outcome');
        });
        await assert.rejects(client.PUT(`/api/businesses/{business}/${kind}`, { params: { path: { business } }, body }), /unknown fact outcome/);
        assert.equal(calls, 1);
    }
});

test('relationship transport keeps the exact target and monotonic revision and never retries an uncertain end', async () => {
    const business = '11111111-1111-7111-8111-111111111111';
    const body = { command_id: '22222222-2222-4222-8222-222222222222', workspace_public_id: '33333333-3333-7333-8333-333333333333',
        kind: 'agency', effect: 'end', expected_revision: 7, authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null,
            requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] } };
    let calls = 0;
    const client = createApplicationClient('https://builderad.test', async request => {
        calls++; assert.equal(request.url, `https://builderad.test/api/businesses/${business}/workspace-links`);
        assert.deepEqual(await request.json(), body); throw Error('uncertain relationship outcome');
    });
    await assert.rejects(client.POST('/api/businesses/{business}/workspace-links', { params: { path: { business } }, body }), /uncertain relationship outcome/);
    assert.equal(calls, 1);
});

test('a typed pre-effect revision rejection stays distinguishable from a generic unknown command conflict', async () => {
    for (const error of ['business_relation_revision_rejected','business_conflict']) {
        const client = createApplicationClient('https://builderad.test', async () => Response.json({ error }, { status: 409 }));
        const result = await client.POST('/api/businesses/{business}/workspace-links', { params: { path: { business: '11111111-1111-7111-8111-111111111111' } },
            body: { command_id: '22222222-2222-4222-8222-222222222222', workspace_public_id: '33333333-3333-7333-8333-333333333333',
                effect: 'add', expected_revision: 2, authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 42 }] } } });
        assert.equal(result.response.status, 409); assert.equal(result.error.error, error); assert.equal(result.data, undefined);
    }
});
