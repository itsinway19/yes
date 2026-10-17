import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import Ajv2020 from 'ajv/dist/2020.js';
import { createApplicationClient } from '../../resources/js/api/application.ts';

const appReady = (page, screen) => page.waitForFunction(screen => document.getElementById('application-spa')?.dataset.state === 'ready'
    && document.querySelector(`[data-workspaces="${screen}"]`), screen);

export async function runWorkspaceCases({ browser, check, login, meta, result, fixture, command, run }) {
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const ajv = new Ajv2020({ strict: false, validateFormats: false });
    ajv.addSchema({ $id: 'application', components: spec.components });
    const validate = (schema, body) => {
        const validator = ajv.compile({ $ref: `application#/components/schemas/${schema}` });
        assert.ok(validator(body), JSON.stringify(validator.errors));
    };
    command('restore'); command('restore-view'); command('restore-create');
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    context.on('response', response => {
        if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() });
    });
    const page = await context.newPage();
    page.on('dialog', dialog => dialog.accept());
    const errors = [];
    page.on('pageerror', error => errors.push({ message: error.message, stack: error.stack, url: page.url(), scenario: result.cases.at(-1)?.name }));
    const headers = async () => {
        const identity = await meta(page);
        return { Accept: 'application/json', 'X-CSRF-TOKEN': identity.csrf, 'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition };
    };
    const client = createApplicationClient('http://localhost:8138', async request => {
        const response = await context.request.fetch(request.url, { method: request.method, headers: Object.fromEntries(request.headers),
            data: request.method === 'GET' ? undefined : await request.text(), maxRedirects: 0 });
        return new Response(await response.body(), { status: response.status(), headers: response.headers() });
    });
    const list = async () => {
        const reply = await client.GET('/workspaces', { headers: await headers() });
        assert.equal(reply.response.status, 200); validate('WorkspaceIndexResource', reply.data); return reply.data.data.workspaces;
    };
    let type;
    try {
        await check('legacy navigation crosses once into the sole SPA workspace chooser', 'spa', async () => {
            await login(page, 'application');
            await page.goto('http://localhost:8138/profile');
            await page.waitForFunction(() => document.documentElement.dataset.visibility === 'ready');
            const previous = await meta(page);
            await page.getByRole('link', { name: 'مساحات العمل', exact: true }).click();
            await page.waitForURL('http://localhost:8138/workspaces');
            await appReady(page, 'index');
            const next = await meta(page);
            assert.equal(next.binding.principal, previous.binding.principal);
            assert.notEqual(next.binding.generation, previous.binding.generation);
            assert.equal(await page.locator('[data-page]').count(), 0);
            const rows = await list();
            assert.ok(rows.some(row => row.public_id === fixture.a.workspaces[0].public_id));
            assert.ok(rows.every(row => !fixture.b.workspaces.some(foreign => foreign.public_id === row.public_id)));
            return { previousDocument: previous.binding.generation, nextDocument: next.binding.generation, visibleWorkspaces: rows.length, foreignWorkspaces: 0 };
        });
        await check('application contract actual 200/403/409/419/422 and atomic creation retry', 'spa', async () => {
            const before = await list();
            const read = await client.GET('/business/start', { params: { query: { new: '1' } }, headers: await headers() });
            assert.equal(read.response.status, 200); validate('BusinessCreationResource', read.data);
            type = read.data.data.types.find(type => type.active).id;
            const body = { name: 'API creation ' + Date.now(), business_type_id: type, creation_key: read.data.data.creationKey };
            const invalid = await client.POST('/business/start', { body: { ...body, name: 'x' }, headers: await headers() });
            assert.equal(invalid.response.status, 422); validate('ValidationError', invalid.error);
            const csrf = await client.POST('/business/start', { body, headers: { ...await headers(), 'X-CSRF-TOKEN': 'invalid' } });
            assert.equal(csrf.response.status, 419);
            const stale = await client.POST('/business/start', { body, headers: { ...await headers(), 'X-Builderad-Document': 'invalid' } });
            assert.equal(stale.response.status, 409); assert.equal(stale.error.identityConflict, true);
            const denied = await client.POST('/business/start', { body: { ...body, creation_key: '11111111-1111-4111-8111-111111111111' }, headers: await headers() });
            assert.equal(denied.response.status, 403);
            assert.equal((await list()).length, before.length);
            const first = await client.POST('/business/start', { body, headers: await headers() });
            assert.equal(first.response.status, 200); validate('ApplicationDestination', first.data);
            const repeated = await client.POST('/business/start', { body, headers: await headers() });
            assert.equal(repeated.response.status, 200); assert.deepEqual(repeated.data, first.data);
            const after = await list();
            assert.equal(after.length, before.length + 1);
            assert.equal(after.filter(row => row.name === body.name).length, 1);
            const witness = await client.GET('/workspaces', { headers: { ...await headers(), 'X-Builderad-Witness': '1' } });
            assert.equal(witness.response.status, 200); validate('GoalsWitness', witness.data); assert.equal('data' in witness.data, false);
            assert.equal((await context.request.get('http://localhost:8138/contracts/application.openapi.json', { headers: await headers() })).status(), 404);
            return { statuses: [200, 403, 409, 419, 422], createdWorkspaces: 1, repeatedCommandWorkspaces: 1, internalDocsHTTP: 404 };
        });
        await check('creation draft survives locale change and SPA traversal with narrow RTL/LTR layout', 'spa', async () => {
            await page.reload(); await appReady(page, 'index');
            await page.getByRole('link', { name: 'إضافة نشاط', exact: true }).click(); await appReady(page, 'create');
            await page.locator('[data-workspaces="name"]').fill('Retained creation draft');
            await page.locator('[data-workspaces="type"]').selectOption(String(type));
            await page.locator('[data-application="locale"]').click();
            await page.waitForFunction(() => document.documentElement.lang === 'en');
            assert.equal(await page.locator('[data-workspaces="name"]').inputValue(), 'Retained creation draft');
            await page.getByRole('link', { name: 'Review my businesses', exact: true }).click(); await appReady(page, 'index');
            await page.getByRole('link', { name: 'Add a business', exact: true }).click(); await appReady(page, 'create');
            assert.equal(await page.locator('[data-workspaces="name"]').inputValue(), 'Retained creation draft');
            assert.equal(await page.locator('[data-workspaces="type"]').inputValue(), String(type));
            await page.setViewportSize({ width: 390, height: 844 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0);
            await page.screenshot({ path: path.join(run, 'workspace-creation-mobile-en.png'), fullPage: true });
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0);
            await page.screenshot({ path: path.join(run, 'workspace-creation-mobile-ar.png'), fullPage: true });
            return { preservedDraft: true, width: 390, horizontalOverflow: 0, locales: ['ar', 'en'], realDevice: false };
        });
        await check('lost creation ACK preserves the exact command and explicit retry creates no duplicate', 'spa', async () => {
            const before = await list();
            const name = 'Lost ACK creation ' + Date.now();
            const originalDocument = await meta(page);
            await page.locator('[data-workspaces="name"]').fill(name);
            const payloads = [];
            let lose = true;
            await page.route('**/business/start', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                payloads.push(route.request().postDataJSON());
                if (!lose) return route.continue();
                lose = false;
                const committed = await route.fetch();
                assert.equal(committed.status(), 200);
                validate('ApplicationDestination', await committed.json());
                await route.abort('failed');
            });
            await page.locator('[data-workspaces="save"]').click();
            await page.locator('[data-workspaces="uncertain"]').waitFor({ state: 'visible' });
            assert.equal(payloads.length, 1);
            assert.equal((await list()).filter(row => row.name === name).length, 1);
            assert.equal(await page.locator('[data-workspaces="name"]').inputValue(), name);
            await page.waitForTimeout(300); assert.equal(payloads.length, 1);
            command('deny-create');
            const rejected = page.waitForResponse(response => response.url().endsWith('/business/start') && response.request().method() === 'POST');
            await page.locator('[data-workspaces="retry"]').click();
            assert.equal((await rejected).status(), 403);
            await page.waitForFunction(() => !document.querySelector('[data-workspaces="retry"]')?.disabled);
            assert.equal(await page.locator('[data-workspaces="uncertain"]').isVisible(), true);
            assert.equal(await page.locator('[data-workspaces="name"]').isDisabled(), true);
            assert.deepEqual(payloads[1], payloads[0]);
            command('restore-create');
            await page.locator('[data-workspaces="retry"]').click();
            await page.waitForURL(/\/workspaces\/[^/]+\/goals$/);
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-goals="form"]'));
            assert.equal((await meta(page)).binding.generation, originalDocument.binding.generation);
            assert.equal(payloads.length, 3); assert.deepEqual(payloads[2], payloads[0]);
            const after = await list();
            assert.equal(after.length, before.length + 1);
            assert.equal(after.filter(row => row.name === name).length, 1);
            return { lostAckAfterServerCommit: true, initialDelivery: 1, automaticRetries: 0, explicitRetries: 2, deniedRetryPreservesOriginalUnknown: true, exactOriginalPayload: true, createdWorkspaces: 1, nextJourney: 'Canonical Goals in the same unified SPA document' };
        });
        await check('revoked session cannot restore chooser data or use its old receipt', 'spa', async () => {
            await page.goto('http://localhost:8138/workspaces'); await appReady(page, 'index');
            const original = await headers();
            command('revoke');
            await page.reload(); await page.waitForURL('http://localhost:8138/login');
            const denied = await client.GET('/workspaces', { headers: original });
            assert.ok([401, 409].includes(denied.response.status));
            assert.equal(await page.locator('[data-workspaces="index"]').count(), 0);
            return { oldReceiptStatus: denied.response.status, nativeRecovery: '/login' };
        });
        assert.deepEqual(errors, []);
    } finally { command('restore-create'); await context.close(); }
}
