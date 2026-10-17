import test from 'node:test';
import assert from 'node:assert/strict';
import { createGoalsClient } from '../resources/js/api/goals.ts';

test('typed client uses the existing URL and exact version/value JSON without adding credentials', async () => {
    const body = { value: { goals: ['sales'], priority: 'sales', results: {} }, version: 7 };
    let calls = 0;
    const client = createGoalsClient('https://example.test', async request => {
        calls++;
        assert.equal(request.url, 'https://example.test/workspaces/a/goals');
        assert.equal(request.method, 'POST');
        assert.deepEqual(await request.json(), body);
        assert.equal(request.headers.has('X-Builderad-Document'), false);
        assert.equal(request.headers.has('X-CSRF-TOKEN'), false);
        return Response.json({ ...body, version: 8 });
    });
    assert.equal((await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace: 'a' } }, body })).data.version, 8);
    assert.equal(calls, 1);
});
test('uncertain POST delivery is surfaced once and never retried by the generated client', async () => {
    let calls = 0;
    const client = createGoalsClient('https://example.test', async () => { calls++; throw Error('lost acknowledgement'); });
    await assert.rejects(client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace: 'a' } }, body: { value: { goals: ['sales'], priority: 'sales', results: {} }, version: 0 } }), /lost acknowledgement/);
    assert.equal(calls, 1);
});
test('CAS and identity conflicts remain distinguishable original response bodies', async () => {
    for (const body of [{ message: 'CAS' }, { message: 'Identity context changed.', identityConflict: true, context: '/identity/context' }]) {
        const client = createGoalsClient('https://example.test', async () => Response.json(body, { status: 409 }));
        const reply = await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace: 'a' } } });
        assert.equal(reply.response.status, 409);
        assert.deepEqual(reply.error, body);
    }
});
