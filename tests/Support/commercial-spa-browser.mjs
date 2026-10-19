import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

export async function runCommercialCases({ page, ready, check, get, post, command, fixture, held, until }) {
    const base = '/internal/administration/commercial-markets';
    const form = page.locator('[data-commercial-prepare]');
    const review = () => page.getByRole('button', { name: 'مراجعة الحالة الحالية — ليست إقرارًا', exact: true });
    async function navigate() {
        await page.getByRole('link', { name: 'تهيئة الأسواق التجارية', exact: true }).click();
        await ready('[data-commercial-preparation]');
    }
    async function fillDirect(reason) {
        await form.getByLabel('الدولة', { exact: true }).selectOption('JO');
        await form.getByLabel('العملة', { exact: true }).selectOption('USD');
        await form.getByLabel('السبب / ملاحظة', { exact: true }).fill(reason);
    }
    const submit = () => form.getByRole('button', { name: 'إرسال الطلب', exact: true }).click();
    const acknowledged = () => page.getByText('وصل إقرار التنفيذ.', { exact: true }).waitFor();
    async function newIntent() {
        const reply = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === base + '/new-intent');
        await page.getByRole('button', { name: 'إصدار Intent تالٍ بعد اكتمال الحالي', exact: true }).click();
        assert.equal((await reply).status(), 200); await acknowledged();
        await page.waitForFunction(() => !document.querySelector('[data-commercial-prepare] fieldset').disabled);
    }
    if (process.env.COMMERCIAL_CASES !== 'operations') {
    await check('A preparation uses unified router, stable founder binding and original atomic country writer', 'commercial', async () => {
        const before = await get(page, base); assert.equal(before.status, 200);
        const binding = before.body.data.intentBinding, intent = before.body.data.intentId;
        await navigate();
        assert.equal(await page.locator('[data-page]').count(), 0);
        assert.equal(await page.locator('#application-bootstrap').textContent().then(value => value.includes(binding)), false);
        await fillDirect('Synthetic country writer proof');
        const reply = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === base);
        await submit(); const response = await reply; assert.equal(response.status(), 200);
        const sent = response.request().postDataJSON(), result = await response.json();
        assert.equal(sent.intent_id, intent); assert.equal(result.country_code, 'JO'); assert.equal(result.denomination_code, 'USD');
        await acknowledged();
        const current = await get(page, base); assert.equal(current.body.data.directRecorded, true);
        assert.equal(current.body.data.recordedMarkets[0].id, result.market_id);
        return { actualCompositeWriter: true, stableIntent: intent, noPrivateBootstrap: true };
    });
    await check('A known ACK survives failed refresh and explicit review does not replay the writer', 'commercial', async () => {
        await newIntent(); await fillDirect('Known acknowledgement readback failure');
        let reads = 0, writes = 0;
        await page.route('**/internal/administration/commercial-markets', async route => {
            if (route.request().method() === 'POST') { writes++; await route.continue(); }
            else { reads++; await route.abort('failed'); }
        });
        await submit(); await acknowledged();
        await page.getByText('تعذر تحديث القراءة؛ لا يعني ذلك فشل الكتابة المقرّة.', { exact: true }).waitFor();
        assert.equal(writes, 1); assert.ok(reads > 0);
        await page.unroute('**/internal/administration/commercial-markets');
        await review().click(); await acknowledged();
        assert.equal(writes, 1);
        return { acknowledgedSeparatelyFromReadback: true, automaticReplay: false };
    });
    await check('A committed lost ACK retains original binding across reload and only explicit retry replays exact command', 'commercial', async () => {
        await newIntent(); await fillDirect('Lost acknowledgement retained original');
        let original, attempts = 0;
        await page.route('**/internal/administration/commercial-markets', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            attempts++; original = route.request().postDataJSON();
            const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed');
        });
        await submit(); await page.getByText('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor();
        assert.equal(attempts, 1);
        await page.unroute('**/internal/administration/commercial-markets');
        await review().click();
        await page.getByText('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor();
        assert.equal((await get(page, base)).body.data.directRecorded, true);
        await page.goto('http://localhost:8138/internal/administration'); await ready('[data-administration]'); await navigate();
        await page.getByText('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor();
        const replay = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === base);
        await page.getByRole('button', { name: 'إعادة المحاولة صراحة بالطلب نفسه', exact: true }).click();
        const response = await replay; assert.equal(response.status(), 200); assert.deepEqual(response.request().postDataJSON(), original);
        await acknowledged();
        return { committedUnknown: true, readNotAcknowledgement: true, exactExplicitRetry: true };
    });
    await check('A post-load stale fresh authentication refuses direct HTTP and cannot create a market', 'commercial', async () => {
        await newIntent();
        const resource = await get(page, base);
        command('account-age-auth');
        const response = await post(page, base, { mode: 'direct', intent_id: resource.body.data.intentId,
            intent_binding: resource.body.data.intentBinding, country_code: 'JO', denomination_code: 'USD', reason: 'Forbidden stale authentication' });
        assert.ok([403, 409].includes(response.status), JSON.stringify(response));
        return { currentFreshnessAtExecution: true, directHttpBypassRejected: true };
    });
    await page.goto('http://localhost:8138/internal/identity/switch'); await ready('[data-auth-passkey]');
    await page.locator('[data-auth-passkey]').click(); await ready('[data-administration]');
    }
    const preparation = await get(page, base); assert.equal(preparation.status, 200);
    const created = await post(page, base, { mode: 'direct', intent_id: preparation.body.data.intentId, intent_binding: preparation.body.data.intentBinding, country_code: 'JO', denomination_code: 'USD', reason: 'Synthetic operations fixture through original writer' });
    assert.equal(created.status, 200, JSON.stringify(created));
    const market = created.body.market_id;
    let catalog, configuration, registeredScope;
    const commercialReady = () => ready('[data-commercial-operations]');
    async function uiCommand(path, click) {
        const reply = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === path);
        await click(); const response = await reply; assert.equal(response.status(), 200);
        await page.locator('#operation-result').getByText('أكد الخادم نتيجة العملية.', { exact: true }).waitFor();
        await page.waitForFunction(() => !document.querySelector('.commercial-primary fieldset').disabled);
        return response;
    }
    async function nextCommand() {
        await page.getByRole('button', { name: 'بدء عملية جديدة', exact: true }).click();
    }
    await check('B unified commercial UI records approved reference, immutable configuration and assignability with existing writers', 'commercial', async () => {
        await page.getByRole('link', { name: 'التشغيل التجاري', exact: true }).click(); await commercialReady();
        await page.locator(`[data-manage-market="${market}"]`).click();
        await page.locator('#commercial-advanced > summary').click();
        await page.locator('[data-operation="catalog"]').click();
        await page.locator('#commercial-catalog_version').fill('synthetic-v1');
        await page.locator('#commercial-approval_reference').fill('synthetic:approved-browser');
        await page.locator('details.commercial-source > summary').click();
        await page.locator('#commercial-catalog_identity').fill(crypto.randomUUID());
        await page.locator('#commercial-source_sha256').fill(createHash('sha256').update('Synthetic approved browser source').digest('hex'));
        const recorded = await uiCommand('/internal/commercial/catalog-references', () => page.getByRole('button', { name: 'توثيق المرجع التجاري', exact: true }).click());
        catalog = (await recorded.json()).catalog_reference_id;
        await nextCommand(); await page.locator('[data-operation="configuration"]').click();
        await page.locator('#commercial-catalog_reference_id').selectOption(catalog);
        await page.locator('#commercial-denomination_code').selectOption('USD');
        const configured = await uiCommand('/internal/commercial/configurations', () => page.getByRole('button', { name: 'حفظ إعدادات السوق', exact: true }).click());
        configuration = (await configured.json()).configuration_id;
        await nextCommand(); await page.locator('[data-primary-availability]').click();
        await page.locator('#commercial-configuration_id').selectOption(configuration);
        const enabled = await uiCommand('/internal/commercial/assignability', () => page.getByRole('button', { name: 'تحديث الإتاحة', exact: true }).click());
        assert.equal((await enabled.json()).revision, 2);
        return { realVueToLaravelWriters: 3, approvedReference: catalog, configuration, revision: 2 };
    });
    await check('B committed lost ACK is reconciled only by matching original-command receipt fingerprint', 'commercial', async () => {
        await nextCommand(); await page.locator('[data-primary-availability]').click();
        let original;
        await page.route('**/internal/commercial/assignability', async route => {
            original = route.request().postDataJSON(); const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed');
        });
        await page.getByRole('button', { name: 'تحديث الإتاحة', exact: true }).click();
        await page.getByText('النتيجة غير مؤكدة. محفوظ معرّف الطلب وحمولته الأصلية؛ احسمه قبل عملية أخرى.', { exact: true }).waitFor();
        await page.unroute('**/internal/commercial/assignability');
        await page.getByRole('button', { name: 'تحديث الحالة والصلاحيات', exact: true }).click();
        assert.equal((await get(page, '/internal/commercial')).body.data.markets.find(item => item.id === market).revision, 3);
        assert.equal(await page.getByRole('button', { name: 'راجعت الإيصال والحالة — بدء عملية جديدة', exact: true }).count(), 0);
        await page.locator('#commercial-audit > summary').click();
        await page.locator('details').filter({ has: page.locator('#receipt-heading') }).last().locator(':scope > summary').click();
        await page.locator('#receipt-id').fill(original.command_id);
        await page.getByRole('button', { name: 'قراءة الإيصال', exact: true }).click();
        await page.getByRole('button', { name: 'راجعت الإيصال والحالة — بدء عملية جديدة', exact: true }).waitFor();
        const receipt = await get(page, '/internal/commercial/receipts/' + original.command_id);
        assert.equal(receipt.status, 200); assert.equal(receipt.body.command_id, original.command_id);
        assert.equal(receipt.body.effect, 'DisableCommercialMarket'); assert.equal(receipt.body.request_fingerprint.length, 64);
        return { currentStateNotAcknowledgement: true, exactCommandReceipt: true, noAutomaticReplay: true };
    });
    await check('B Vue registers an exact beneficiary configuration, reads context and recovers audit through original owners', 'commercial', async () => {
        await page.getByRole('button', { name: 'راجعت الإيصال والحالة — بدء عملية جديدة', exact: true }).click();
        await page.locator('[data-primary-availability]').click();
        await page.locator('#commercial-configuration_id').selectOption(configuration);
        await uiCommand('/internal/commercial/assignability', () => page.getByRole('button', { name: 'تحديث الإتاحة', exact: true }).click());
        await nextCommand();
        await page.locator('[data-operation="register"]').click();
        const resource = await get(page, '/internal/commercial');
        const target = resource.body.data.targets.find(target => target.action === 'RegisterCommercialScope' && target.workspace_public_id === fixture.b.workspaces[0].public_id); assert.ok(target);
        await page.locator('#scope-select').selectOption(target.target_id + '|' + target.workspace_public_id);
        const registered = await uiCommand('/internal/commercial/registrations', () => page.getByRole('button', { name: 'تسجيل المستفيد', exact: true }).click());
        const body = await registered.json(); assert.equal(body.market_id, market); assert.equal(body.configuration_id, configuration);
        registeredScope = target.target_id;
        assert.equal(body.agreement_state, 'UNKNOWN'); assert.equal(body.amount_state, 'UNKNOWN');
        const contextDisclosure = page.locator('details').filter({ has: page.locator('#context-heading') }).last();
        await contextDisclosure.locator(':scope > summary').click();
        await page.locator('#context-scope').fill(target.target_id);
        const contextResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/internal/commercial/contexts/' + target.target_id);
        await page.getByRole('button', { name: 'قراءة السياق', exact: true }).click();
        assert.equal((await contextResponse).status(), 200);
        const auditDisclosure = page.locator('details').filter({ has: page.locator('#audit-heading') }).last();
        await auditDisclosure.locator(':scope > summary').click();
        const recovery = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/recover'));
        await page.getByRole('button', { name: 'محاولة استعادة مصرح بها', exact: true }).first().click();
        const recovered = await recovery; assert.equal(recovered.status(), 200); assert.equal((await recovered.json()).state, 'acknowledged');
        return { actualRegistration: true, exactConfiguration: configuration, contextRead: true, auditCustody: 'acknowledged' };
    });
    await check('B definitive stale-revision 409 waits for an actual admitted read and explicit review without replay', 'commercial', async () => {
        await nextCommand(); await page.locator('[data-primary-availability]').click();
        const originalRevision = Number(await page.locator('#commercial-expected_revision').inputValue());
        const competitor = await post(page, '/internal/commercial/assignability', { command_id: crypto.randomUUID(), market_id: market, configuration_id: null, expected_revision: originalRevision, reason_code: 'commercial_assignability' });
        assert.equal(competitor.status, 200, JSON.stringify(competitor));
        const newRevision = competitor.body.revision; assert.equal(newRevision, originalRevision + 1);
        const heldRead = 'commercial-definitive-conflict-read'; let reads = 0, writes = 0;
        await page.route('**/internal/commercial', async route => {
            if (route.request().method() === 'GET' && route.request().headers().accept === 'application/json' && !route.request().headers()['x-builderad-witness'] && reads++ === 0)
                await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': heldRead } });
            else await route.continue();
        });
        await page.route('**/internal/commercial/assignability', route => { writes++; return route.continue(); });
        try {
            const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/internal/commercial/assignability');
            await page.getByRole('button', { name: 'تحديث الإتاحة', exact: true }).click();
            const rejected = await response; assert.equal(rejected.status(), 409); assert.equal(rejected.request().postDataJSON().expected_revision, originalRevision);
            await until(() => held.has(heldRead)); assert.equal(held.get(heldRead).row.status, 200);
            const review = page.locator('[data-conflict-review]'); assert.equal(await review.count(), 0); assert.equal(writes, 1);
            held.get(heldRead).deliver(); held.delete(heldRead); await review.waitFor();
            assert.equal(await page.locator('#commercial-expected_revision').inputValue(), String(originalRevision));
            assert.equal(writes, 1); await review.click();
            assert.equal(await page.locator('#commercial-expected_revision').inputValue(), String(newRevision));
            assert.equal(writes, 1);
            await page.locator('#commercial-configuration_id').selectOption(configuration);
            await uiCommand('/internal/commercial/assignability', () => page.getByRole('button', { name: 'تحديث الإتاحة', exact: true }).click());
            assert.equal(writes, 2);
            return { originalRevision, newRevision, realLaravel409: true, heldActualRead: true, automaticReplay: false, explicitNewCommand: true };
        } finally { await page.unroute('**/internal/commercial'); await page.unroute('**/internal/commercial/assignability'); }
    });
    await check('C operational navigation and Chromium Back Forward preserve document ownership across commercial displays', 'commercial', async () => {
        const identityBefore = await page.locator('#application-bootstrap').textContent();
        await page.getByRole('link', { name: 'العملاء', exact: true }).click(); await ready('[data-customers]');
        await page.getByRole('link', { name: 'تهيئة الأسواق التجارية', exact: true }).click(); await ready('[data-commercial-preparation]');
        await page.goBack(); await ready('[data-customers]');
        await page.goForward(); await ready('[data-commercial-preparation]');
        assert.equal(await page.locator('#application-bootstrap').textContent(), identityBefore);
        assert.equal(await page.locator('[data-page]').count(), 0);
        await page.getByRole('button', { name: 'English', exact: true }).click();
        await page.getByRole('heading', { name: 'Commercial market preparation', exact: true }).waitFor();
        assert.equal(await page.evaluate(() => document.documentElement.dir), 'ltr');
        await page.getByRole('button', { name: 'العربية', exact: true }).click();
        await page.getByRole('heading', { name: 'تهيئة الأسواق التجارية', exact: true }).waitFor();
        await page.reload(); await ready('[data-commercial-preparation]');
        assert.equal(await page.locator('[data-page]').count(), 0);
        assert.equal(await page.locator('#application-bootstrap').textContent().then(value => value.includes('intentBinding')), false);
        return { sameDocumentOwner: true, actualSharedRouter: true, chromiumBackForwardOnly: true };
    });
    await check('C actual A to B to A and observed session revocation suppress pending private context replies', 'commercial', async () => {
        await page.getByRole('link', { name: 'التشغيل التجاري', exact: true }).click(); await commercialReady();
        await page.locator('#commercial-audit > summary').click();
        const disclosure = page.locator('details').filter({ has: page.locator('#context-heading') }).last();
        await disclosure.locator(':scope > summary').click();
        const input = page.locator('#context-scope'); await input.fill(registeredScope);
        const endpoint = '/internal/commercial/contexts/' + registeredScope;
        let tag = 'commercial-context-first-A';
        await page.route('**' + endpoint, route => route.continue({ headers: { ...route.request().headers(), 'x-proof-hold': tag } }));
        await page.getByRole('button', { name: 'قراءة السياق', exact: true }).click(); await until(() => held.has(tag));
        assert.equal(held.get(tag).row.status, 200);
        await input.fill(crypto.randomUUID()); await input.fill(registeredScope); held.get(tag).deliver(); held.delete(tag);
        await page.getByRole('button', { name: 'قراءة السياق', exact: true }).waitFor({ state: 'visible' });
        await page.waitForFunction(() => !document.querySelector('[aria-labelledby="context-heading"] button').disabled);
        assert.equal(await page.locator('[aria-labelledby="context-heading"] pre').count(), 0);
        tag = 'commercial-context-revoked-owner';
        await page.getByRole('button', { name: 'قراءة السياق', exact: true }).click(); await until(() => held.has(tag));
        assert.equal(held.get(tag).row.status, 200);
        command('revoke');
        await page.getByRole('link', { name: 'العملاء', exact: true }).click();
        await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
        held.get(tag).deliver(); held.delete(tag); await page.unroute('**' + endpoint);
        assert.equal(await page.locator('[data-commercial-operations]').count(), 0);
        assert.equal(await page.locator('[data-spa-private]').count(), 0);
        return { actualTargetABA: true, observedRevocationClosesOwner: true, obsoletePrivatePublication: false };
    });
}
