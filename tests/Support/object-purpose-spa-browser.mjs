import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import Ajv2020 from 'ajv/dist/2020.js';

export async function runObjectPurposeCases({ browser, check, login, meta, get, post, command, fixture, result, run, until, held }) {
    command('restore'); command('restore-view');
    const workspace = fixture.a.workspaces[0].public_id, other = fixture.a.workspaces[1].public_id, foreign = fixture.b.workspaces[0].public_id;
    const base = `/workspaces/${workspace}/object-purposes`;
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.setDefaultTimeout(15000); page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => result.errors.push({ mode: 'purpose', message: error.message }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() }); });
    const ready = () => page.waitForFunction(workspace => document.querySelector(`[data-purpose-workspace="${workspace}"]`) && document.getElementById('application-spa')?.dataset.state === 'ready', workspace);
    const visit = async url => { await page.evaluate(url => __goalsSpa.router.push(url), url); await page.waitForURL('http://localhost:8138' + url); await ready(); };
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const ajv = new Ajv2020({ strict: false, validateFormats: false }); ajv.addSchema({ $id: 'application', components: spec.components });
    const validate = (name, body) => { const validator = ajv.compile({ $ref: `application#/components/schemas/${name}` }); assert.ok(validator(body), JSON.stringify(validator.errors)); };
    const read = async (selected = '', query = '') => { const response = await get(page, base + (selected ? '/' + selected : '') + '/context' + query); assert.equal(response.status, 200); validate('ObjectPurposeResource', response.body); return response.body.data; };
    const confirm = async (transition = false) => { for (const name of ['meaning', 'scope', 'authority', 'identity', 'standing', ...(transition ? ['transition'] : [])]) await page.locator(`[data-purpose="confirm-${name}"]`).check(); };
    const saved = () => page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.startsWith(base));
    let purpose, current, lastCommand;
    try {
        await check('canonical Purpose shell and typed resource retain exact scope and temporal validation', 'purpose', async () => {
            await login(page, 'application'); const before = await meta(page);
            await page.getByRole('link', { name: 'الغرض الإعلاني', exact: true }).click(); await ready();
            assert.equal((await meta(page)).binding.generation, before.binding.generation); const data = await read(); assert.equal(data.projection.state, 'UNKNOWN');
            assert.equal((await get(page, `/workspaces/${foreign}/object-purposes/context`)).status, 403);
            assert.equal((await get(page, base + '/context?known_at=2030-01-01T00:00:00Z')).status, 422);
            assert.equal((await get(page, base + '/00000000-0000-4000-8000-000000000000/context')).status, 404);
            assert.equal(await page.locator('[data-page]').count(), 0); return { sameDocument: true, privateResourceValidated: true, statuses: [200, 403, 404, 422] };
        });
        await check('explicit finite Purpose draft and confirmations survive locale and context traversal without submission', 'purpose', async () => {
            for (const [name, value] of Object.entries({ meaning: 'Synthetic exact-object purpose', source: 'Synthetic Owner statement for this isolated proof only', identity_basis: 'Synthetic finite tuple fixture; not live-provider identity evidence', authority_basis: 'Synthetic responsible Owner fixture', effective_basis: 'Synthetic declaration applies on acceptance', 'account-0': '100', 'object-0': '200' })) await page.locator(`[data-purpose="${name}"]`).fill(value);
            await confirm(); const before = command('inspect');
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'en');
            await page.getByRole('banner').getByRole('link', { name: 'Business context', exact: true }).click(); await page.locator('[data-context-workspace]').waitFor();
            await page.getByRole('banner').getByRole('link', { name: 'Object purpose', exact: true }).click(); await ready();
            assert.equal(await page.locator('[data-purpose="meaning"]').inputValue(), 'Synthetic exact-object purpose'); assert.equal(await page.locator('[data-purpose="confirm-identity"]').isChecked(), true);
            await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar'); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.screenshot({ path: path.join(run, 'purpose-ar-mobile-viewport.png'), fullPage: true });
            assert.equal(command('inspect').purposeRevisionCount, before.purposeRevisionCount); return { locales: ['ar', 'en'], draftRetained: true, confirmationsRetained: true, writes: 0, identityEvidence: 'Explicit synthetic fixture only' };
        });
        await check('Purpose lost post-commit ACK locks replacement and retains the original command through a denied retry', 'purpose', async () => {
            const before = command('inspect'), payloads = []; let lose = true;
            await page.route('**' + base, async route => {
                if (route.request().method() !== 'POST') return route.continue(); payloads.push(route.request().postDataJSON());
                if (!lose) return route.continue(); lose = false; const response = await route.fetch(); assert.equal(response.status(), 201); validate('PurposeAcknowledgement', await response.json()); await route.abort('failed');
            });
            await page.locator('[data-purpose="save"]').click(); await page.locator('[data-purpose="retry-original"]').waitFor();
            const committed = command('inspect'); assert.equal(committed.purposeCount, before.purposeCount + 1); assert.equal(committed.purposeRevisionCount, before.purposeRevisionCount + 1); assert.equal(committed.purposeReceiptCount, before.purposeReceiptCount + 1);
            assert.equal(await page.locator('[data-purpose="meaning"]').isDisabled(), true); assert.equal(await page.locator('[data-purpose="save"]').isDisabled(), true);
            await page.waitForTimeout(200); assert.equal(payloads.length, 1);
            command('deny-view'); const denied = saved(); await page.locator('[data-purpose="retry-original"]').click(); assert.equal((await denied).status(), 403);
            await page.waitForFunction(() => !document.querySelector('[data-purpose="retry-original"]')?.disabled); assert.equal(await page.locator('[data-purpose="meaning"]').isDisabled(), true); assert.deepEqual(payloads[1], payloads[0]);
            command('restore-view'); const acknowledged = saved(); await page.locator('[data-purpose="retry-original"]').click(); const response = await acknowledged; assert.equal(response.status(), 201); const ack = await response.json(); validate('PurposeAcknowledgement', ack); purpose = ack.purpose_id;
            await page.waitForURL('http://localhost:8138' + base + '/' + purpose); await ready(); await page.locator('[data-purpose="operation"]').waitFor();
            assert.equal(payloads.length, 3); assert.deepEqual(payloads[2], payloads[0]); const after = command('inspect'); assert.equal(after.purposeCount, committed.purposeCount); assert.equal(after.purposeRevisionCount, committed.purposeRevisionCount); assert.equal(after.purposeReceiptCount, committed.purposeReceiptCount);
            current = await read(purpose); assert.equal(current.projection.items[0].head_version, 1); await page.unroute('**' + base);
            return { actual201BeforeLoss: true, automaticRetries: 0, deniedRetryPreservesUnknown: true, exactOriginalCommand: true, deliveries: 3, purposes: 1, revisions: 1, receipts: 1 };
        });
        await check('Purpose correction and replacement retain immutable history and explicit reviewed transitions', 'purpose', async () => {
            for (const [operation, meaning] of [['correct', 'Synthetic corrected wording'], ['replace', 'Synthetic replacement use']]) {
                await page.locator('[data-purpose="operation"]').selectOption(operation); await page.locator('[data-purpose="meaning"]').fill(meaning);
                if (operation === 'correct') await page.locator('[data-purpose="reason"]').fill('Synthetic wording-only correction');
                if (operation === 'replace') await page.locator('[data-purpose="object-0"]').fill('201');
                await confirm(true); const response = saved(); await page.locator('[data-purpose="save"]').click(); const acknowledged = await response; assert.equal(acknowledged.status(), 201, await acknowledged.text()); const ack = await acknowledged.json();
                await page.waitForFunction(({meaning,version}) => document.querySelector('[data-purpose="meaning"]')?.value === meaning && document.querySelector('[data-purpose="form"]')?.dataset.purposeVersion === String(version) && !document.querySelector('[data-purpose="save"]')?.matches(':disabled'), {meaning,version:ack.version});
                current = await read(purpose); const item = current.projection.items[0]; assert.equal(item.history.length, operation === 'correct' ? 2 : 3); assert.equal(item.applicable.snapshot.meaning, meaning);
            }
            return { correction: 201, replacement: 201, immutableRevisions: 3 };
        });
        await check('Purpose withdrawal preserves history and exact earlier effective/knowledge reads', 'purpose', async () => {
            const earlier = current.projection.items[0].applicable;
            await page.locator('[data-purpose="operation"]').selectOption('withdraw'); await page.locator('[data-purpose="reason"]').fill('Synthetic explicit withdrawal'); await confirm(true);
            const response = saved(); await page.locator('[data-purpose="save"]').click(); assert.equal((await response).status(), 201);
            await page.waitForFunction(() => document.querySelectorAll('article').length === 4);
            const after = await read(purpose); assert.equal(after.projection.state, 'UNKNOWN'); assert.equal(after.projection.items[0].history.length, 4);
            const instant = value => value.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00');
            const query = '?' + new URLSearchParams({ effective_at: instant(earlier.effective_from), known_at: instant(earlier.accepted_at) });
            await visit(base + '/' + purpose + query); const historical = await read(purpose, query); assert.equal(historical.projection.state, 'DECLARED'); assert.equal(historical.projection.items[0].applicable.revision_id, earlier.revision_id); assert.equal(historical.projection.items[0].history.length, 3);
            await page.reload(); await ready(); assert.equal((await read(purpose, query)).projection.items[0].applicable.revision_id, earlier.revision_id);
            await visit(base + '/' + purpose); return { withdrawal: 201, currentStanding: 'UNKNOWN', retainedRevisions: 4, earlierTemporalRead: earlier.revision_id, nativeDeepLink: true };
        });
        await check('Purpose real CSRF, document, validation and stale-version failures do not add effects', 'purpose', async () => {
            const data = await read(purpose), item = data.projection.items[0], before = command('inspect');
            const body = { command_id: crypto.randomUUID(), purpose_id: purpose, expected_version: 0, operation: 'withdraw', declaration: null, effective_from: null, effective_until: null, supersedes_revision_id: item.history.at(-1).revision_id, affected_revision_ids: item.history.map(row => row.revision_id), correction_reason: null, withdrawal_reason: 'Synthetic stale attempt', confirmations: { meaning: true, scope: true, authority: true, identity: true, standing: true, dtm: false, intent: false, historical: false, transition: true } };
            lastCommand = { ...body, expected_version: item.head_version };
            assert.equal((await post(page, base + '/' + purpose + '/revisions', body)).status, 409);
            assert.equal((await post(page, base, { operation: 'declare' })).status, 422);
            assert.equal((await post(page, base, body, { 'X-Builderad-Document': 'invalid' })).status, 409);
            const identity = await meta(page); const csrf = await context.request.post('http://localhost:8138' + base, { headers: { Accept: 'application/json', 'X-Builderad-Document': identity.receipt, 'X-CSRF-TOKEN': 'invalid' }, data: body, maxRedirects: 0 }); assert.equal(csrf.status(), 419);
            const after = command('inspect'); assert.equal(after.purposeRevisionCount, before.purposeRevisionCount); assert.equal(after.purposeReceiptCount, before.purposeReceiptCount); return { stale: 409, validation: 422, document: 409, csrf: 419, newEffects: 0 };
        });
        await check('superseded Purpose context cannot publish another Workspace', 'purpose', async () => {
            const key = 'late-purpose-context'; let once = true;
            await page.route(`**/workspaces/${other}/object-purposes/context`, async route => { if (once && route.request().headers()['x-builderad-witness'] !== '1') { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); } else await route.continue(); });
            await page.evaluate(url => { void __goalsSpa.router.push(url); }, `/workspaces/${other}/object-purposes`); await until(() => held.has(key));
            await visit(base + '/' + purpose); held.get(key).deliver(); held.delete(key); await page.waitForTimeout(100); await ready(); assert.equal(await page.locator('[data-purpose-workspace]').getAttribute('data-purpose-workspace'), workspace);
            await page.unroute(`**/workspaces/${other}/object-purposes/context`);
        });
        await check('revoked session cannot restore Purpose data or execute its old document', 'purpose', async () => {
            const before = command('inspect'); command('revoke'); await page.evaluate(() => __goalsSpa.resume()); await page.waitForFunction(() => __goalsSpa.status.value === 'closed'); assert.equal(await page.locator('[data-purpose-workspace]').count(), 0);
            assert.ok([401, 409].includes((await get(page, base + '/context')).status));
            const denied = await post(page, base + '/' + purpose + '/revisions', { ...lastCommand, command_id: crypto.randomUUID() });
            assert.ok([401, 409].includes(denied.status)); if (denied.status === 409) assert.equal(denied.body.identityConflict, true);
            assert.equal(command('inspect').purposeRevisionCount, before.purposeRevisionCount);
        });
    } finally { command('restore'); command('restore-view'); await context.close(); }
}
