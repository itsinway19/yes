import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import Ajv2020 from 'ajv/dist/2020.js';

export async function runBusinessContextCases({ browser, check, login, meta, get, post, command, fixture, result, run, until, held }) {
    command('restore'); command('restore-view');
    const workspace = fixture.a.workspaces[0].public_id, other = fixture.a.workspaces[1].public_id;
    const target = `/workspaces/${workspace}/business`;
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => result.errors.push({ mode: 'context', message: error.message }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() }); });
    const ready = () => page.waitForFunction(workspace => document.querySelector(`[data-context-workspace="${workspace}"]`) && document.getElementById('application-spa')?.dataset.state === 'ready', workspace);
    const visit = async url => { await page.evaluate(url => __goalsSpa.router.push(url), url); await page.waitForURL('http://localhost:8138' + url); if (url === target) await ready(); };
    const identity = () => page.locator('[data-context="identity"]');
    const name = () => identity().locator('input');
    const market = code => page.locator(`[data-target-markets] input[type="checkbox"][value="${code}"]`);
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const ajv = new Ajv2020({ strict: false, validateFormats: false }); ajv.addSchema({ $id: 'application', components: spec.components });
    const validate = ajv.compile({ $ref: 'application#/components/schemas/BusinessContextResource' });
    const read = async () => { const reply = await get(page, target); assert.equal(reply.status, 200); assert.ok(validate(reply.body), JSON.stringify(validate.errors)); return reply.body.data; };
    try {
        await check('canonical context uses the sole SPA and its typed scoped resource', 'context', async () => {
            await login(page, 'application'); const before = await meta(page);
            await page.getByRole('link', { name: 'سياق النشاط', exact: true }).click(); await ready();
            assert.equal((await meta(page)).binding.generation, before.binding.generation);
            const data = await read(); assert.equal(data.workspace.public_id, workspace); assert.equal(data.countryOptions.length, 249);
            assert.equal(await page.locator('[data-page]').count(), 0);
            assert.equal((await get(page, `/workspaces/${fixture.b.workspaces[0].public_id}/business`)).status, 403);
            return { sameDocument: true, resourceValidated: true, foreignWorkspace: 403 };
        });
        await check('independent context drafts survive locale, Goals traversal and bounded presentation recheck', 'context', async () => {
            await name().fill('Independent name draft'); await market('SA').check();
            await page.locator('[data-intent="edit"]').click(); await page.locator('[data-intent="direction"]').selectOption('increase_value'); await page.locator('[data-intent="meaning"]').fill('Independent intent draft');
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'en');
            assert.equal(await name().inputValue(), 'Independent name draft'); assert.equal(await market('SA').isChecked(), true);
            await page.getByRole('link', { name: 'Goals', exact: true }).click(); await page.locator('[data-goals="form"]').waitFor();
            await page.getByRole('link', { name: 'Business context', exact: true }).click(); await ready();
            assert.equal(await name().inputValue(), 'Independent name draft'); assert.equal(await market('SA').isChecked(), true); assert.equal(await page.locator('[data-intent="meaning"]').inputValue(), 'Independent intent draft');
            await page.evaluate(() => __goalsSpa.resume()); await ready();
            assert.equal(await name().inputValue(), 'Independent name draft'); assert.equal(await market('SA').isChecked(), true); assert.equal(await page.locator('[data-intent="meaning"]').inputValue(), 'Independent intent draft');
            await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar'); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.screenshot({ path: path.join(run, 'context-ar-mobile-viewport.png'), fullPage: true });
            return { independentDrafts: 3, locales: ['ar', 'en'], overflow: 0, recheck: 'Explicit lifecycle-handler probe, not native BFCache/device evidence' };
        });
        await check('context lost ACK retains the original draft and revision until explicit review', 'context', async () => {
            const before = await read(), payloads = []; let lose = true;
            await page.route('**' + target, async route => {
                if (route.request().method() !== 'POST') return route.continue();
                payloads.push(route.request().postDataJSON());
                if (!lose) return route.continue(); lose = false;
                const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed');
            });
            await page.locator('[data-context="save"]').click(); await page.locator('[data-context="review"]').waitFor();
            const committed = await read(); assert.equal(committed.workspace.name, 'Independent name draft'); assert.equal(committed.version, before.version + 1);
            assert.equal(await name().inputValue(), 'Independent name draft'); assert.equal(await market('SA').isChecked(), true); assert.equal(await page.locator('[data-intent="meaning"]').inputValue(), 'Independent intent draft');
            await page.waitForTimeout(200); assert.equal(payloads.length, 1);
            await page.locator('[data-context="review"]').click(); await page.locator('[data-context="adopt"]').waitFor(); await page.locator('[data-context="adopt"]').click();
            assert.equal(payloads.length, 1); assert.equal(await name().inputValue(), committed.workspace.name);
            await page.unroute('**' + target);
            return { serverCommitBeforeLoss: true, automaticRetries: 0, originalVersion: payloads[0].version, canonicalVersion: committed.version, explicitReviewWithoutWrite: true };
        });
        await check('market CAS review preserves its draft and does not silently adopt the Intent revision', 'context', async () => {
            const before = await read();
            const conflict = page.waitForResponse(response => response.url().endsWith('/business/target-markets') && response.request().method() === 'POST');
            await page.locator('[data-target-markets]').getByRole('button', { name: 'حفظ الأسواق', exact: true }).click(); assert.equal((await conflict).status(), 422);
            const reviewed = page.waitForResponse(response => new URL(response.url()).pathname === target && response.request().headers()['x-builderad-witness'] === '1');
            await page.locator('[data-target-markets]').getByRole('button', { name: 'إعادة التحميل والمراجعة', exact: true }).click(); await reviewed;
            assert.equal(await market('SA').isChecked(), true);
            const saved = page.waitForResponse(response => response.url().endsWith('/business/target-markets') && response.request().method() === 'POST');
            await page.locator('[data-target-markets]').getByRole('button', { name: 'حفظ الأسواق', exact: true }).click(); assert.equal((await saved).status(), 200);
            await page.locator('[data-target-markets] .biz-success').waitFor(); const after = await read(); assert.equal(after.version, before.version + 1); assert.ok(after.business.targetMarkets.some(country => country.code === 'SA'));
            assert.equal(await page.locator('[data-intent="meaning"]').inputValue(), 'Independent intent draft');
            return { staleDraft: 422, explicitMarketReview: true, saved: 200, independentIntentDraft: true };
        });
        await check('Intent conflict requires reviewed adoption and fresh confirmations before replace and clear', 'context', async () => {
            for (const field of ['scope', 'outcome', 'declaration']) await page.locator(`[data-intent="${field}"]`).check();
            const stale = page.waitForResponse(response => response.url().endsWith('/business/current-intent') && response.request().method() === 'POST');
            await page.locator('[data-intent="save"]').click(); assert.equal((await stale).status(), 422);
            await page.locator('[data-intent="review"]').click(); await page.locator('[data-intent="adopt"]').waitFor(); await page.locator('[data-intent="adopt"]').click();
            assert.equal(await page.locator('[data-intent="declaration"]').isChecked(), false); assert.equal(await page.locator('[data-intent="meaning"]').inputValue(), 'Independent intent draft');
            for (const field of ['scope', 'outcome', 'declaration']) await page.locator(`[data-intent="${field}"]`).check();
            const saved = page.waitForResponse(response => response.url().endsWith('/business/current-intent') && response.request().method() === 'POST');
            await page.locator('[data-intent="save"]').click(); assert.equal((await saved).status(), 200); await page.locator('[data-intent="edit"]').waitFor();
            assert.equal((await read()).business.currentIntent.value.outcome.meaning, 'Independent intent draft');
            await page.locator('[data-intent="clear"]').click(); await page.locator('[data-intent="declaration"]').check();
            const cleared = page.waitForResponse(response => response.url().endsWith('/business/current-intent') && response.request().method() === 'POST');
            await page.locator('[data-intent="save"]').click(); assert.equal((await cleared).status(), 200); await page.locator('[data-intent="edit"]').waitFor(); assert.equal((await read()).business.currentIntent.state, 'NOT_DECLARED');
            return { stale: 422, reconfirmation: true, replace: 200, clear: 200 };
        });
        await check('successful name/type save preserves the existing Laravel continuation boundary', 'context', async () => {
            const before = await meta(page); await name().fill('Continued context fixture');
            await page.locator('[data-context="save"]').click();
            await page.waitForURL('http://localhost:8138/workspaces/' + workspace);
            await page.waitForFunction(() => document.documentElement.dataset.visibility === 'ready');
            assert.notEqual((await meta(page)).binding.generation, before.binding.generation);
            await page.getByRole('link', { name: 'سياق النشاط', exact: true }).click(); await ready();
            assert.equal((await read()).workspace.name, 'Continued context fixture');
            return { actualCanonicalSave: true, continuation: 'Existing sources destination through a native document until its cutover', roundTrip: true };
        });
        await check('superseded context reads cannot publish a different Workspace', 'context', async () => {
            const key = 'late-business-context'; let once = true;
            await page.route(`**/workspaces/${other}/business`, async route => {
                if (once && route.request().headers()['x-builderad-witness'] !== '1') { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); } else await route.continue();
            });
            await page.evaluate(url => { void __goalsSpa.router.push(url); }, `/workspaces/${other}/business`); await until(() => held.has(key));
            await visit(target); held.get(key).deliver(); held.delete(key); await page.waitForTimeout(100); await ready();
            assert.equal(await page.locator('[data-context-workspace]').getAttribute('data-context-workspace'), workspace); await page.unroute(`**/workspaces/${other}/business`);
        });
        await check('withdrawn manage grant renders context read-only and rejects the real writer', 'context', async () => {
            const before = await read(); command('deny');
            await page.reload(); await ready(); const readonly = await read(); assert.equal(readonly.canManage, false);
            assert.equal(await page.locator('[data-context="business-name"]').innerText(), readonly.workspace.name);
            assert.equal(await page.locator('[data-context="identity"], [data-target-markets], [data-intent="edit"]').count(), 0);
            assert.equal((await post(page, target, { name: 'Denied', business_type_id: before.workspace.business_type_id, version: before.version })).status, 403);
            const after = await read(); assert.deepEqual(after.business, readonly.business); return { readOnly: true, write: 403 };
        });
        await check('revoked session cannot republish a context draft or use the old document', 'context', async () => {
            command('restore'); await page.reload(); await ready(); await name().fill('Must not survive session closure'); command('revoke'); await page.evaluate(() => __goalsSpa.resume());
            await page.waitForFunction(() => __goalsSpa.status.value === 'closed'); assert.equal(await page.locator('[data-context-workspace]').count(), 0);
            assert.ok([401, 409].includes((await get(page, target)).status));
        });
    } finally { command('restore'); await context.close(); }
}
