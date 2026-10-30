import test from 'node:test';
import assert from 'node:assert/strict';
import { createApplicationClient } from '../resources/js/api/application.ts';
import { applicationDestination } from '../resources/js/spa/destination.ts';

test('application creation sends the exact original command once and never retries uncertain delivery', async () => {
    const command = { name: 'A business', business_type_id: 3, creation_key: '11111111-1111-4111-8111-111111111111' };
    let attempts = 0;
    const client = createApplicationClient('https://builderad.test', async request => {
        attempts++;
        assert.equal(request.url, 'https://builderad.test/business/start');
        assert.equal(request.method, 'POST');
        assert.deepEqual(await request.json(), command);
        throw Error('acknowledgement lost after possible commit');
    });
    await assert.rejects(client.POST('/business/start', { body: command }), /acknowledgement lost/);
    assert.equal(attempts, 1);
});

test('native document destinations remain on the application origin and identity boundary', () => {
    assert.equal(applicationDestination('/workspaces/a/goals', 'https://builderad.test', 'advertiser'), 'https://builderad.test/workspaces/a/goals');
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
