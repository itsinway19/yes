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
