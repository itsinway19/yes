import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import Ajv from 'ajv/dist/2020.js';
import { createApplicationClient } from '../resources/js/api/application.ts';
import { providerOperations } from '../resources/js/sources/providers.ts';
import { spec } from '../scripts/application-openapi.mjs';

const providers = ['meta', 'google', 'tiktok', 'snapchat', 'x'];
const version = { command_id: 'original-command', expected_version: 3 };
const handle = 'a'.repeat(43);
const period = { start_date: '2026-10-01', end_date: '2026-10-02' };
const report = { ...period, command_id: 'original-report', expected_version: 3, expected_generation: 2, deadline: '2026-10-17T20:00:00+00:00' };
const fake = () => {
    const requests = [], result = { data: { acknowledged: true } };
    const client = providerOperations(createApplicationClient('https://builderad.test', async request => {
        requests.push({ url: new URL(request.url), method: request.method, signal: request.signal,
            passive: request.headers.get('X-Builderad-Passive'), body: request.body ? await request.json() : null });
        return Response.json(result.data, { status: request.url.endsWith('/report') && request.method === 'POST' ? 202 : 200 });
    }));
    return { client, requests, result };
};

test('Google offline recovery forwards true without changing the existing Laravel request format', async () => {
    const { client, requests } = fake();
    await client.connectGoogle('workspace', { intent: handle, recover_offline_grant: true });
    assert.equal(requests[0].url.pathname, '/workspaces/workspace/providers/google/connect');
    assert.equal(requests[0].method, 'POST');
    assert.deepEqual(requests[0].body, { intent: handle, recover_offline_grant: true });
    await client.connectGoogle('workspace', { intent: handle });
    assert.deepEqual(requests[1].body, { intent: handle });
});

test('Snapchat renewal retains the real 200 success and 502 action-required response shapes', async () => {
    const access = { version: 3, connection: 'CONNECTED', native_observation: null, axes: {}, effective: { state: 'UNKNOWN', reason: 'privacy_unverified', last_verified_at: null }, reporting: { can_report: false, why_not: 'privacy_unverified' } };
    const success = { source_id: 'source', receipt: { version: 3 }, access };
    const denied = { source_id: 'source', status: 'PROVIDER_ACTION_REQUIRED', reason: 'snapchat_reconnect_required', access: { ...access, connection: 'REAUTHORIZATION_REQUIRED' } };
    const validate = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: '#/components/schemas/SnapchatRenewalOutcome' });
    assert.equal(validate(success), true);
    assert.equal(validate(denied), true);
    const { receipt, ...missingReceipt } = success;
    assert.equal(validate(missingReceipt), false);
    assert.equal(validate({ ...denied, receipt }), false);
    const client = createApplicationClient('https://builderad.test', async request => {
        const command = await request.json();
        return Response.json(command.expected_version === 2 ? success : denied, { status: command.expected_version === 2 ? 200 : 502 });
    });
    const path = '/workspaces/{workspace}/providers/snapchat/{source}/renew';
    const positive = await client.POST(path, { params: { path: { workspace: 'workspace', source: 'source' } }, body: { command_id: 'success', expected_version: 2 } });
    assert.deepEqual(positive.data, success);
    const negative = await client.POST(path, { params: { path: { workspace: 'workspace', source: 'source' } }, body: { command_id: 'denied', expected_version: 3 } });
    assert.deepEqual(negative.error, denied);
    assert.equal(negative.data, undefined);
});

test('every generated provider HTTP operation matches an existing runtime-registered route', async () => {
    const inventory = JSON.parse(await readFile(new URL('../docs/inertia-inventory.json', import.meta.url), 'utf8'));
    const routes = inventory.runtime.goalsDisabled;
    for (const [path, operations] of Object.entries(spec.paths).filter(([path]) => path.includes('/providers/'))) {
        for (const method of Object.keys(operations)) assert.ok(routes.some(route => route.uri === path.slice(1) && route.method.split('|').includes(method.toUpperCase())), `${method} ${path}`);
    }
    for (const provider of providers) {
        assert.ok(spec.paths[`/workspaces/{workspace}/providers/${provider}`]);
        assert.ok(spec.paths[`/workspaces/{workspace}/providers/${provider}/{source}/report`].post.responses[202]);
    }
    assert.equal(spec.paths['/workspaces/{workspace}/providers/meta/{source}/renew'], undefined);
    assert.equal(spec.paths['/providers/meta/callback'], undefined);
});

test('provider snapshots default to passive hydration, retain explicit refresh activity and caller cancellation', async () => {
    const { client, requests } = fake(), caller = new AbortController();
    for (const provider of providers) {
        await client.snapshot(provider, 'workspace', caller.signal);
        await client.snapshot(provider, 'workspace', caller.signal, false);
    }
    caller.abort();
    for (const [index, request] of requests.entries()) {
        assert.equal(request.passive, index % 2 ? null : '1');
        assert.equal(request.signal.aborted, true);
        assert.equal(request.method, 'GET');
        assert.equal(request.url.origin, 'https://builderad.test');
    }
});

test('all five provider connect/discover/select/disconnect operations retain their exact native protocol', async () => {
    for (const provider of providers) {
        const { client, requests, result } = fake();
        result.data = { authorization_url: 'https://provider.example/oauth' };
        assert.equal((await client.connect(provider, 'workspace', handle)).authorization_url, 'https://provider.example/oauth');
        if (provider === 'google') await client.googlePage('workspace', 'discovery', handle);
        else await client.discover(provider, 'workspace', handle);
        if (provider === 'meta') await client.selectMeta('workspace', handle);
        else await client.select(provider, 'workspace', handle);
        await client.disconnect(provider, 'workspace', 'source', version);
        assert.deepEqual(requests.map(request => [request.method, request.url.pathname]), [
            ['POST', `/workspaces/workspace/providers/${provider}/connect`], ['GET', `/workspaces/workspace/providers/${provider}/discovery/${handle}`],
            ['POST', `/workspaces/workspace/providers/${provider}/select`], ['POST', `/workspaces/workspace/providers/${provider}/source/disconnect`],
        ]);
        assert.deepEqual(requests.map(request => request.body), [{ intent: handle }, null, { selection: handle }, version]);
        assert.ok(requests.every(request => request.url.origin === 'https://builderad.test'));
    }
});

test('only supported renewals, Google hierarchy and X campaign continuations have typed operations', async () => {
    const { client, requests } = fake();
    await client.renew('google', 'workspace', 'source', version);
    await client.renew('snapchat', 'workspace', 'source', version);
    await client.googlePage('workspace', 'hierarchy', handle);
    await client.campaigns('workspace', 'source');
    await client.campaigns('workspace', 'source', handle);
    assert.deepEqual(requests.map(request => request.url.pathname), [
        '/workspaces/workspace/providers/google/source/renew', '/workspaces/workspace/providers/snapchat/source/renew',
        `/workspaces/workspace/providers/google/hierarchy/${handle}`, '/workspaces/workspace/providers/x/source/campaigns', '/workspaces/workspace/providers/x/source/campaigns',
    ]);
    assert.equal(requests[3].url.search, '');
    assert.equal(requests[4].url.searchParams.get('continuation'), handle);
});

test('X status preserves campaign arrays through actual PHP query parsing, separate placement and mode', () => {
    const { client, requests } = fake();
    return client.reportStatus({ workspace: 'workspace', source: 'source' }, { provider: 'x', period: { ...period, campaign_ids: ['campaign1', 'campaign2'], placement: 'SPOTLIGHT', mode: 'ASYNC' } }).then(() => {
        const url = requests[0].url;
        const parsed = JSON.parse(execFileSync(process.env.BUILDERAD_PHP ?? 'C:\\tools\\php85\\php.exe', ['-r', 'parse_str($argv[1], $query); echo json_encode($query,JSON_THROW_ON_ERROR);', url.search.slice(1)], { encoding: 'utf8' }));
        assert.deepEqual(parsed, { ...period, campaign_ids: ['campaign1', 'campaign2'], placement: 'SPOTLIGHT', mode: 'ASYNC' });
    });
});

test('report status and queue retain each provider period and exact original generation/deadline command', async () => {
    for (const provider of providers) {
        const { client, requests } = fake(), scope = { workspace: 'workspace', source: 'source' };
        const extra = provider === 'x' ? { campaign_ids: ['campaign1'], placement: 'TREND', mode: 'SYNC' } : provider === 'meta' ? { mode: 'ASYNC' } : {};
        const command = { ...report, ...extra };
        await client.reportStatus(scope, { provider, period: { ...period, ...extra } });
        await client.report(scope, { provider, command });
        assert.equal(requests[0].method, 'GET');
        assert.equal(requests[1].method, 'POST');
        assert.deepEqual(requests[1].body, command);
        assert.equal(requests[1].url.pathname, `/workspaces/workspace/providers/${provider}/source/report`);
    }
    const attempts = [];
    const client = providerOperations(createApplicationClient('https://builderad.test', async request => { attempts.push(await request.json()); throw Error('unknown delivery'); }));
    await assert.rejects(client.report({ workspace: 'workspace', source: 'source' }, { provider: 'google', command: report }), /unknown delivery/);
    assert.deepEqual(attempts, [report]);
});

test('provider report schemas reject extra fields and retain provider-specific admission boundaries', () => {
    const ajv = new Ajv({ strict: false, validateFormats: false });
    const validate = name => ajv.compile({ ...spec, $ref: `#/components/schemas/${name}` });
    const basic = validate('ProviderReportCommand'), meta = validate('MetaReportCommand'), xReport = validate('XReportCommand');
    assert.equal(basic(report), true);
    assert.equal(basic({ ...report, mode: 'ASYNC' }), false);
    assert.equal(meta({ ...report, mode: 'SYNC' }), true);
    assert.equal(meta({ ...report, mode: 'MADE_UP' }), false);
    const xCommand = { ...report, campaign_ids: ['a', 'b'], placement: 'ALL_ON_TWITTER' };
    assert.equal(xReport(xCommand), true);
    for (const extra of [{ campaign_ids: [] }, { campaign_ids: ['a', 'a'] }, { placement: 'MERGED' }, { token: 'never' }, { expected_version: 0 }, { expected_generation: -1 }]) assert.equal(xReport({ ...xCommand, ...extra }), false);
    const snapshot = validate('ProviderSnapshot');
    const unavailable = { connections: [], availability: 'CONFIGURATION_REQUIRED', connect_intent: null, notice: null };
    assert.equal(snapshot(unavailable), true);
    assert.equal(unavailable.availability, 'CONFIGURATION_REQUIRED');
});
