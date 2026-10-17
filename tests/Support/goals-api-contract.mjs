import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import Ajv2020 from 'ajv/dist/2020.js';
import { createGoalsClient } from '../../resources/js/api/goals.ts';

/** Real HTTP responses through the same generated-path client factory used by SPA. */
export async function runGoalsContractCases({ browser, check, login, meta, fixture, result }) {
    const spec = JSON.parse(await readFile('contracts/goals.openapi.json', 'utf8'));
    const ajv = new Ajv2020({ strict: false, validateFormats: false });
    const root = { $id: 'goals', components: spec.components };
    ajv.addSchema(root);
    const validate = (schema, body) => {
        const check = ajv.compile({ $ref: `goals#/components/schemas/${schema}` });
        assert.ok(check(body), JSON.stringify(check.errors));
    };
    const context = await browser.newContext();
    const page = await context.newPage();
    const client = createGoalsClient('http://localhost:8138', async request => {
        const response = await context.request.fetch(request.url, {
            method: request.method, headers: Object.fromEntries(request.headers),
            data: request.method === 'GET' ? undefined : await request.text(),
            maxRedirects: 0,
        });
        return new Response(await response.body(), { status: response.status(), headers: response.headers() });
    });
    const workspace = fixture.a.workspaces[0].public_id;
    const foreign = fixture.b.workspaces[0].public_id;
    const statuses = [];
    const inspect = (reply, schema, status) => {
        assert.equal(reply.response.status, status);
        const body = reply.data ?? reply.error;
        validate(schema, body); statuses.push(status); return body;
    };
    try {
        await check('generated client actual HTTP 200/401/403/409/419/422 and private contract', 'spa', async () => {
            await page.goto('http://localhost:8138/login');
            let identity = await meta(page);
            const headers = () => ({ Accept: 'application/json', 'X-CSRF-TOKEN': identity.csrf,
                'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition });
            inspect(await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers() }), 'Error', 401);
            await login(page, 'spa'); identity = await meta(page);
            const baseline = inspect(await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers() }), 'Preferences', 200);
            const contextual = await client.GET('/spa/api/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers() });
            const data = inspect(contextual, 'GoalsContext', 200);
            assert.deepEqual(data.preferences, baseline);
            const witness = await client.GET('/spa/api/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: { ...headers(), 'X-Goals-Witness': '1' } });
            inspect(witness, 'GoalsWitness', 200);
            inspect(await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace: foreign } }, headers: headers() }), 'Error', 403);
            const invalid = { value: {}, version: 0 };
            inspect(await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers(), body: invalid }), 'ValidationError', 422);
            inspect(await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: { ...headers(), 'X-CSRF-TOKEN': 'invalid' }, body: invalid }), 'Error', 419);
            const body = { value: { goals: ['sales'], priority: 'sales', results: {} }, version: baseline.version };
            validate('SaveGoals', body);
            const saved = inspect(await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers(), body }), 'Preferences', 200);
            assert.equal(saved.version, baseline.version + 1);
            inspect(await client.POST('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers(), body }), 'Error', 409);
            assert.deepEqual(inspect(await client.GET('/workspaces/{workspace}/goals', { params: { path: { workspace } }, headers: headers() }), 'Preferences', 200), saved);
            // Exercise the actual SPA wrapper too; it verifies the document response evidence.
            assert.deepEqual(await page.evaluate(workspace => __goalsSpa.readGoals(workspace), workspace), saved);
            for (const url of ['/contracts/goals.openapi.json', '/openapi.json', '/docs/api', '/api/documentation']) {
                assert.equal((await context.request.get('http://localhost:8138' + url, { headers: headers() })).status(), 404);
            }
            result.contract = { statuses, runtimeValidation: 'Ajv JSON Schema 2020-12 on actual Laravel responses', internalDocsHTTP: 404 };
            return result.contract;
        });
    } finally { await context.close(); }
}
