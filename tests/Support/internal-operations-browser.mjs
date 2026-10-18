import assert from 'node:assert/strict';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { messages } from '../../resources/js/localization/messages.ts';

export async function runAuthEntryCases({ browser, check, meta, get, post, command, fixture, result, run, until, held }) {
    const selected = process.env.INTERNAL_OPERATIONS_CASES;
    if (selected && !['business', 'services', 'commercial'].includes(selected)) throw Error('Unknown operations proof selection');
    const verify = (name, mode, execute) => !selected || name.startsWith('A real signed') || (selected !== 'commercial' && name.startsWith(selected === 'business' ? 'B business' : 'C ')) ? check(name, mode, execute) : undefined;
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => result.errors.push({ mode: 'operations', message: error.message, stack: error.stack }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
    const ready = selector => page.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
    const settled = () => page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-administration]') && !document.querySelector('[data-administration]').disabled);
    const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
    const authenticator = async () => (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
    let currentAuthenticator = await authenticator();
    async function refresh() { await page.locator('[data-operation-review]').click(); await settled(); }
    async function reauthenticate() {
        await page.goto('http://localhost:8138/internal/identity/switch'); await ready('[data-auth-passkey]');
        await page.locator('[data-auth-passkey]').click(); await ready('[data-administration]');
    }
    async function clickCommand(url, click, method = 'POST') {
        const acknowledgement = page.waitForResponse(response => response.request().method() === method && new URL(response.url()).pathname === url);
        await click(); assert.equal((await acknowledgement).status(), 200); await settled();
    }
    async function invite(name, email) {
        const disclosure = page.locator('details').filter({ has: page.locator('#staff-name') }); if ((await disclosure.getAttribute('open')) === null) await disclosure.locator('summary').click();
        await page.locator('#staff-name').fill(name); await page.locator('#staff-email').fill(email);
    }
    const labels = messages.ar.admin;
    try {
        await verify('A real signed internal operator reaches the unified staff view with no private bootstrap', 'operations', async () => {
            await page.goto('http://localhost:8138/login'); await ready('[data-auth-kind="login"]');
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await ready('[data-spa-private]:not([data-auth-entry])');
            await page.goto('http://localhost:8138/security/devices'); await ready('[data-account-kind="devices"]');
            for (const name of ['Operations first UV key', 'Operations second UV key']) {
                await page.locator('#account-key-name').fill(name); const stored = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/security/passkeys'));
                await page.locator('[data-account-passkey] button[type=submit]').click(); assert.equal((await stored).status(), 201);
                await page.locator('[data-passkey-id]').filter({ hasText: name }).waitFor();
                if (name.includes('first')) { await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: currentAuthenticator }); currentAuthenticator = await authenticator(); }
            }
            command('account-staff'); command('operations-founder');
            await page.goto('http://localhost:8138/internal/login'); await ready('[data-auth-passkey]'); await page.locator('[data-auth-passkey]').click(); await ready('[data-administration]');
            assert.equal((await meta(page)).binding.principal, fixture.a.user); assert.equal((await meta(page)).binding.boundary, 'internal');
            const resource = await get(page, '/internal/administration'); assert.equal(resource.status, 200); assert.equal(resource.body.data.permissions['staff.invite'], true);
            assert.equal(await page.locator('[data-page]').count(), 0);
            assert.equal(await page.locator('#application-bootstrap').textContent().then(value => value.includes('goals-a@example.test')), false);
            return { realUvAuthentication: true, syntheticOperatorIndependence: 'not physical factor acceptance', privateBootstrap: false };
        });
        await verify('A invite, resend, attest, activate, factor attestation, grant/revoke and eligibility revoke use existing Laravel writers', 'operations', async () => {
            await invite('Operations employee', 'operations-employee@example.test');
            await clickCommand('/internal/staff', () => page.getByRole('button', { name: labels.sendInvite, exact: true }).click());
            let target = command('operations-inspect').target; assert.equal(target.lifecycle, 'pending');
            const row = page.locator(`[data-person-id="${target.public_id}"]`);
            await clickCommand(`/internal/staff/${target.public_id}/resend-invitation`, () => row.getByRole('button', { name: labels.resendInvitation, exact: true }).click());
            command('operations-complete-target'); await refresh();
            await clickCommand(`/internal/staff/${target.public_id}/attest`, () => row.getByRole('button', { name: labels.attest, exact: true }).click());
            await clickCommand(`/internal/staff/${target.public_id}/activate`, () => row.getByRole('button', { name: labels.activate, exact: true }).click());
            assert.equal(command('operations-inspect').target.lifecycle, 'current');
            await clickCommand(`/internal/staff/${target.public_id}/attest-factors`, () => row.getByRole('button', { name: labels.attestFactors, exact: true }).click());
            assert.ok(command('operations-inspect').target.personnel.factor_independence_attested_at);
            const grantDisclosure = page.locator('details').filter({ has: page.locator('#grant-staff') }); await grantDisclosure.locator('summary').click();
            await page.locator('#grant-staff').selectOption(target.public_id); await page.locator('#grant-action').selectOption('business.manage'); await page.locator('#grant-reason').fill('Explicit browser operations grant');
            await clickCommand('/internal/grants', () => page.locator('.admin-grant-form button[type=submit]').click());
            const granted = command('operations-inspect').target.grants.find(grant => grant.action === 'business.manage' && grant.status === 'active'); assert.ok(granted);
            await clickCommand(`/internal/grants/${granted.id}`, () => page.locator('.admin-grant-row').filter({ hasText: 'Operations employee' }).getByRole('button').click(), 'DELETE');
            assert.equal(command('operations-inspect').target.grants.find(grant => grant.id === granted.id).status, 'revoked');
            await clickCommand(`/internal/staff/${target.public_id}/revoke`, () => row.getByRole('button', { name: labels.revokeEligibility, exact: true }).click());
            assert.equal(command('operations-inspect').target.lifecycle, 'disabled');
            await page.screenshot({ path: path.join(run, 'operations-staff.png'), fullPage: true });
            return { invitation: 'actual queued isolated mail', allEightCommands: true, serverLifecycle: 'disabled', grantRevoked: true };
        });
        await verify('A lost command ACK is unknown and acknowledged success remains known when readback fails', 'operations', async () => {
            let deliveries = 0;
            await page.route('**/internal/staff', async route => { deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            const before = command('operations-inspect').users; await invite('Unknown operation', 'operations-unknown@example.test'); await page.getByRole('button', { name: labels.sendInvite, exact: true }).click();
            await page.getByText('نتيجة الأمر غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor(); assert.equal(deliveries, 1); assert.equal(command('operations-inspect').users, before + 1);
            assert.equal(await page.locator('[data-administration]').evaluate(element => element.disabled), true);
            assert.equal(await page.locator('#staff-name').isDisabled(), true);
            await page.unroute('**/internal/staff'); await refresh(); assert.equal(deliveries, 1);
            await page.route('**/internal/administration', route => route.request().headers().accept === 'application/json' ? route.abort('failed') : route.continue());
            await invite('Known operation', 'operations-known@example.test'); await page.getByRole('button', { name: labels.sendInvite, exact: true }).click();
            await page.getByText('وصل إقرار نجاح الأمر من الخادم.', { exact: true }).waitFor(); await page.getByText('تعذر تحديث العرض.', { exact: false }).waitFor();
            assert.equal(command('operations-inspect').users, before + 2); assert.equal(await page.getByText('نتيجة الأمر غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).count(), 0);
            await page.unroute('**/internal/administration'); await refresh();
            return { lostAcknowledgementDeliveredOnce: deliveries, knownWriteSurvivesReadbackFailure: true, automaticReplay: false };
        });
        await verify('B customer directory filters, details, account suspension and reactivation use SQL authority and revoke old sessions', 'operations', async () => {
            const labels = messages.ar.customers;
            await page.locator('a[href="/internal/administration/customers"]').click(); await ready('[data-customers]');
            assert.equal(await page.locator('[data-page]').count(), 0);
            await page.locator('#customer-search').fill('goals-b@example.test');
            await page.locator('.customer-filters button[type=submit]').click(); await ready('[data-customers]');
            const row = page.locator('.customer-row').filter({ hasText: 'goals-b@example.test' }); await row.waitFor();
            assert.equal(await page.locator('.customer-row').count(), 1);
            await row.getByRole('button', { name: labels.details, exact: true }).click(); await ready('[data-customers]'); await page.locator('.customer-inline-details').waitFor();
            assert.equal(await page.locator('.customer-detail-list li').count(), 2);
            for (const decision of ['suspend', 'reactivate']) {
                await row.getByRole('button', { name: labels[decision], exact: true }).click(); await page.locator('#customer-reason').fill('Explicit browser customer review');
                const ack = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/internal/administration/customers/status'));
                await page.locator('.customer-confirmation button[type=submit]').click(); assert.equal((await ack).status(), 200); await ready('[data-customers]');
                await page.locator('[data-customer-ack]').waitFor();
                assert.equal(command('operations-inspect').customer.status, decision === 'suspend' ? 'disabled' : 'current');
            }
            assert.equal(command('operations-inspect').customerActiveSessions, 0);
            assert.equal(command('operations-inspect').customerEvents, 2);
            await page.screenshot({ path: path.join(run, 'operations-customers.png'), fullPage: true });
            return { actualSqlCommands: 2, oldSessionsRestored: false, filteredDetails: true };
        });
        await verify('B lost customer ACK survives reload without replay and an explicit identical retry resolves the original SQL command once', 'operations', async () => {
            const labels = messages.ar.customers;
            await page.getByRole('button', { name: labels.workspaces, exact: true }).click(); await ready('[data-customers]');
            const row = page.locator('.customer-row').filter({ hasText: 'نشاط b 1' }); await row.waitFor();
            await row.getByRole('button', { name: labels.suspend, exact: true }).click(); await page.locator('#customer-reason').fill('Lost customer acknowledgement fixture');
            const before = command('operations-inspect').customerEvents; let deliveries = 0;
            await page.route('**/internal/administration/customers/status', async route => { deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            await page.locator('.customer-confirmation button[type=submit]').click(); await page.getByText(labels.uncertainError, { exact: true }).waitFor();
            assert.equal(command('operations-inspect').customerEvents, before + 1); assert.equal(deliveries, 1);
            await page.unroute('**/internal/administration/customers/status'); await page.reload(); await ready('[data-customers]');
            await page.getByText(labels.uncertainError, { exact: true }).waitFor(); assert.equal(command('operations-inspect').customerEvents, before + 1);
            const ack = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/internal/administration/customers/status'));
            await page.locator('.customer-confirmation button[type=submit]').click(); const response = await ack; assert.equal(response.status(), 200); assert.equal((await response.json()).replayed, true);
            await ready('[data-customers]'); await page.locator('[data-customer-ack]').waitFor(); assert.equal(command('operations-inspect').customerEvents, before + 1);
            assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter(key => key.startsWith('builderad.customer-status.v1.')).length), 0);
            return { originalCommandDeliveredOnce: deliveries, noAutomaticReplayOnReload: true, explicitRetryReplayed: true, eventsAdded: 1 };
        });
        await verify('B customer acknowledged write stays known on readback failure and server rejects stale revision and invalid decisions', 'operations', async () => {
            const labels = messages.ar.customers, url = '/internal/administration/customers/status';
            const directory = await get(page, '/internal/administration/customers?kind=workspaces');
            const target = directory.body.data.rows.find(row => row.public_id === fixture.b.workspaces[0].public_id); assert.equal(target.status, 'suspended');
            const body = { command_id: crypto.randomUUID(), kind: 'workspaces', target: target.public_id, decision: 'reactivate', reason: 'Explicit real conflict fixture', expected_status: 'active', expected_revision: 0 };
            assert.equal((await post(page, url, body)).status, 409);
            assert.equal((await post(page, url, { ...body, decision: 'invented' })).status, 422);
            const row = page.locator('.customer-row').filter({ hasText: 'نشاط b 1' }); await row.getByRole('button', { name: labels.reactivate, exact: true }).click();
            await page.locator('#customer-reason').fill('Known command readback failure');
            await page.route('**/internal/administration/customers?*', route => route.request().headers().accept === 'application/json' ? route.abort('failed') : route.continue());
            await page.locator('.customer-confirmation button[type=submit]').click(); await page.locator('[data-customer-ack]').waitFor(); await page.locator('[data-customer-readback]').waitFor();
            assert.equal(await page.getByText(labels.uncertainError, { exact: true }).count(), 0);
            await page.unroute('**/internal/administration/customers?*'); await page.locator('[data-customer-readback] button').click(); await ready('[data-customers]');
            await page.locator('a[href="/internal/administration"]').first().click(); await ready('[data-administration]');
            return { revisionConflict: 409, invalidDecision: 422, knownAcknowledgementNotLost: true };
        });
        await verify('B business catalog creation, versioned update, guidance and semantic prior approval invalidation use the original SQL writer', 'operations', async () => {
            await page.locator('a[href="/internal/business-types"]').click(); await ready('[data-business-types]');
            const create = page.locator('.catalog-create'); await create.locator('summary').click();
            await create.getByLabel('المفتاح الثابت', { exact: true }).fill('browser_operations'); await create.getByLabel('الاسم بالعربية', { exact: true }).fill('تصنيف العمليات'); await create.getByLabel('الاسم بالإنجليزية', { exact: true }).fill('Browser operations');
            const saved = async (url, click) => { const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === url); await click(); assert.equal((await response).status(), 200); await ready('[data-business-types]'); await page.locator('[data-business-ack]').waitFor(); };
            await saved('/internal/business-types', () => create.getByRole('button', { name: 'إضافة النوع', exact: true }).click());
            const article = page.locator('[data-business-type="browser_operations"]'); await article.waitFor();
            let catalog = (await get(page, '/internal/business-types')).body.data, type = catalog.types.find(type => type.key === 'browser_operations');
            const definition = article.locator('details').nth(0); await definition.locator('summary').click(); await definition.getByLabel('الاسم بالإنجليزية', { exact: true }).fill('Updated browser operations');
            await saved(`/internal/business-types/${type.id}`, () => definition.getByRole('button', { name: 'حفظ التعريف', exact: true }).click());
            const guidance = article.locator('details').nth(1); await guidance.locator('summary').click(); await guidance.locator('select').selectOption('google'); await guidance.getByLabel('ترتيب العرض', { exact: true }).fill('2'); await guidance.getByLabel('المبرر بالعربية', { exact: true }).fill('إرشاد موثق للاختبار'); await guidance.getByLabel('المبرر بالإنجليزية', { exact: true }).fill('Documented browser guidance');
            await saved(`/internal/business-types/${type.id}/guidance`, () => guidance.getByRole('button', { name: 'حفظ توجيه المصدر', exact: true }).click());
            const prior = article.locator('details').nth(2); await prior.locator('summary').click(); await prior.locator('select').nth(0).selectOption('google'); await prior.locator('select').nth(1).selectOption('google.search');
            for (const label of ['الأساس الموثّق', 'مجال الانطباق', 'الحدود', 'المبرر الظاهر بالعربية', 'المبرر الظاهر بالإنجليزية', 'المبرر الداخلي']) await prior.getByLabel(label, { exact: true }).fill('Documented browser fixture rationale');
            await prior.getByLabel('موافقة على هذا الاعتبار بعد مراجعته', { exact: true }).check(); await prior.getByLabel('تفعيل', { exact: true }).check();
            await saved(`/internal/business-types/${type.id}/prior`, () => prior.getByRole('button', { name: 'حفظ الاعتبار', exact: true }).click());
            type = (await get(page, '/internal/business-types')).body.data.types.find(type => type.key === 'browser_operations'); assert.equal(type.version, 2); assert.equal(type.source_guidance[0].provider, 'google'); assert.equal(type.promotion_priors[0].approved, true);
            await prior.getByRole('button', { name: 'تعديل / مراجعة', exact: true }).click(); await prior.getByLabel('مجال الانطباق', { exact: true }).fill('Materially changed applicability for isolated proof');
            await saved(`/internal/business-types/${type.id}/prior`, () => prior.getByRole('button', { name: 'حفظ الاعتبار', exact: true }).click());
            type = (await get(page, '/internal/business-types')).body.data.types.find(type => type.key === 'browser_operations'); assert.equal(type.promotion_priors[0].approved, false); assert.equal(type.promotion_priors[0].approved_by, null);
            await page.screenshot({ path: path.join(run, 'operations-business-types.png'), fullPage: true });
            return { createAndUpdate: true, guidance: true, approvalInvalidatedByOriginalSql: true };
        });
        await verify('B business command lost ACK cannot replay and permission revoked after display is enforced by Laravel', 'operations', async () => {
            const create = page.locator('.catalog-create');
            await create.getByLabel('المفتاح الثابت', { exact: true }).fill('browser_unknown'); await create.getByLabel('الاسم بالعربية', { exact: true }).fill('تصنيف غير محسوم'); await create.getByLabel('الاسم بالإنجليزية', { exact: true }).fill('Uncertain browser type');
            let deliveries = 0; await page.route('**/internal/business-types', async route => { if (route.request().method() !== 'POST') return route.continue(); deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            await create.getByRole('button', { name: 'إضافة النوع', exact: true }).click(); await page.locator('[data-business-unknown]').waitFor(); assert.equal(deliveries, 1);
            await page.unroute('**/internal/business-types'); await page.locator('[data-business-review]').click(); await ready('[data-business-types]'); await page.locator('[data-business-type="browser_unknown"]').waitFor(); assert.equal(deliveries, 1);
            await create.getByLabel('المفتاح الثابت', { exact: true }).fill('browser_forbidden'); command('operations-revoke-business');
            const denied = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/internal/business-types'); await create.getByRole('button', { name: 'إضافة النوع', exact: true }).click(); assert.equal((await denied).status(), 403);
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await page.locator('[data-business-types]').count(), 0);
            command('operations-restore-grants'); await reauthenticate();
            return { lostAckDeliveryCount: deliveries, noAutomaticReplay: true, postLoadAuthorityRevocation: 403 };
        });
        await verify('C service issuance, scoped grant/revocation and rotation deliver a secret only once to the admitted SPA document', 'operations', async () => {
            await page.locator('[data-internal-operations] > nav a[href="/internal/services"]').click(); await ready('[data-services]');
            const saved = async (url, click, method = 'POST') => { const response = page.waitForResponse(response => response.request().method() === method && new URL(response.url()).pathname === url); await click(); const ack = await response; assert.equal(ack.status(), 200); await ready('[data-services]'); await page.locator('[data-service-ack]').waitFor(); return ack; };
            await page.locator('#service-name').fill('Operations service'); await saved('/internal/services', () => page.getByRole('button', { name: 'إنشاء الهوية', exact: true }).click());
            let secret = await page.locator('[data-service-credential] code').textContent(); assert.match(secret, /^[A-Za-z0-9_-]{43}$/);
            let service = command('operations-inspect').services.find(service => service.name === 'Operations service'); assert.equal(createHash('sha256').update(secret).digest('hex'), service.credential_hash);
            assert.equal(JSON.stringify((await get(page, '/internal/services')).body).includes(secret), false);
            assert.equal(await page.evaluate(secret => JSON.stringify(history.state).includes(secret) || JSON.stringify({ ...localStorage, ...sessionStorage }).includes(secret) || document.getElementById('application-bootstrap').textContent.includes(secret), secret), false);
            await page.getByRole('button', { name: 'إخفاء الاعتماد', exact: true }).click(); secret = undefined; assert.equal(await page.locator('[data-service-credential]').count(), 0);
            const owner = await browser.newContext(), ownerPage = await owner.newPage();
            try {
                await ownerPage.goto('http://localhost:8138/login'); await ownerPage.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
                const signed = await post(ownerPage, '/login', { email: 'goals-b@example.test', password: 'DisposableGoalsProof42!' }); assert.equal(signed.status, 200);
                await ownerPage.goto('http://localhost:8138/workspaces'); await ownerPage.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
                const ownerDirectory = await get(ownerPage, '/workspaces');
                if (ownerDirectory.body.data.reassessableWorkspaces.some(row => row.public_id === fixture.b.workspaces[0].public_id)) assert.equal((await post(ownerPage, `/workspaces/${fixture.b.workspaces[0].public_id}/reassess-owner-grant`)).status, 200);
                const foreignDocument = await meta(ownerPage), before = command('operations-inspect').services.length;
                assert.equal((await post(page, '/internal/services', { name: 'Foreign document must not issue' }, { 'X-Builderad-Document': foreignDocument.receipt })).status, 409);
                assert.equal(command('operations-inspect').services.length, before);
                assert.equal((await post(ownerPage, `/workspaces/${fixture.b.workspaces[0].public_id}/services`, { service: service.public_id, purpose: 'Explicit isolated operations service scope' })).status, 200);
            } finally { await owner.close(); }
            await page.reload(); await ready('[data-services]'); assert.equal(await page.locator('[data-service-credential]').count(), 0);
            await page.getByRole('button', { name: 'اختيار للمنحة', exact: true }).click(); await page.locator('#grant-reason').fill('Explicit browser service read grant');
            await saved('/internal/service-grants', () => page.getByRole('button', { name: 'إصدار المنحة', exact: true }).click());
            const grant = command('operations-inspect').serviceGrants.find(grant => grant.status === 'active'); assert.ok(grant);
            await saved(`/internal/service-grants/${grant.id}`, () => page.getByRole('button', { name: 'سحب المنحة', exact: true }).click(), 'DELETE'); assert.equal(command('operations-inspect').serviceGrants.find(row => row.id === grant.id).status, 'revoked');
            await saved(`/internal/services/${service.public_id}/rotate`, () => page.locator(`[data-service-id="${service.public_id}"]`).getByRole('button', { name: 'تدوير الاعتماد', exact: true }).click());
            secret = await page.locator('[data-service-credential] code').textContent(); const rotated = command('operations-inspect').services.find(row => row.public_id === service.public_id); assert.equal(rotated.version, 2); assert.notEqual(rotated.credential_hash, service.credential_hash); assert.equal(rotated.credential_hash, createHash('sha256').update(secret).digest('hex'));
            await page.getByRole('button', { name: 'إخفاء الاعتماد', exact: true }).click(); secret = undefined; await page.reload(); await ready('[data-services]'); assert.equal(await page.locator('[data-service-credential]').count(), 0);
            return { oneTimeDelivery: true, hashOnlyDatabase: true, actualOwnerScopeHttp: true, grantRevoked: true, rotationVersion: 2, noSecretReadbackOrStorage: true };
        });
        await verify('C committed service rotation and creation with lost ACK stay unknown without automatic replay or secret recovery', 'operations', async () => {
            let service = command('operations-inspect').services.find(row => row.name === 'Operations service'), rotations = 0;
            await page.route('**/internal/services/*/rotate', async route => { rotations++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            await page.locator(`[data-service-id="${service.public_id}"]`).getByRole('button', { name: 'تدوير الاعتماد', exact: true }).click(); await page.locator('[data-service-unknown]').waitFor();
            let current = command('operations-inspect').services.find(row => row.public_id === service.public_id); assert.equal(current.version, 3); assert.notEqual(current.credential_hash, service.credential_hash); assert.equal(rotations, 1);
            assert.equal(await page.locator('[data-service-credential]').count(), 0); await page.unroute('**/internal/services/*/rotate'); await page.locator('[data-service-review]').click(); await ready('[data-services]'); assert.equal(command('operations-inspect').services.find(row => row.public_id === service.public_id).version, 3);
            const before = command('operations-inspect').services.length; let creates = 0;
            await page.route('**/internal/services', async route => { if (route.request().method() !== 'POST') return route.continue(); creates++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            await page.locator('#service-name').fill('Unknown delivery service'); await page.getByRole('button', { name: 'إنشاء الهوية', exact: true }).click(); await page.locator('[data-service-unknown]').waitFor(); assert.equal(command('operations-inspect').services.length, before + 1); assert.equal(creates, 1);
            await page.unroute('**/internal/services'); await page.locator('[data-service-review]').click(); await ready('[data-services]'); assert.equal(await page.locator('[data-service-credential]').count(), 0); assert.equal(command('operations-inspect').services.length, before + 1);
            const unknown = command('operations-inspect').services.find(row => row.name === 'Unknown delivery service'); const revoked = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith(`/internal/services/${unknown.public_id}/revoke`));
            await page.locator(`[data-service-id="${unknown.public_id}"]`).getByRole('button', { name: 'إلغاء الهوية', exact: true }).click(); assert.equal((await revoked).status(), 200); await ready('[data-services]');
            assert.equal(command('operations-inspect').services.find(row => row.public_id === unknown.public_id).status, 'revoked');
            return { rotationCommittedWithoutDelivery: true, oldCredentialInvalidated: true, createsDeliveredOnce: creates, rotationsDeliveredOnce: rotations, noSecretRecovery: true, explicitRevoke: true };
        });
        await verify('C committed late rotation cannot publish after SPA navigation and commercial Inertia crossings retain document identity boundaries', 'operations', async () => {
            const service = command('operations-inspect').services.find(row => row.name === 'Operations service'), tag = 'operations-late-rotation';
            await page.route('**/internal/services/*/rotate', route => route.continue({ headers: { ...route.request().headers(), 'x-proof-hold': tag } }));
            await page.locator(`[data-service-id="${service.public_id}"]`).getByRole('button', { name: 'تدوير الاعتماد', exact: true }).click(); await until(() => held.has(tag));
            assert.equal(command('operations-inspect').services.find(row => row.public_id === service.public_id).version, service.version + 1);
            await page.locator('a[href="/internal/administration"]').first().click(); await ready('[data-administration]'); held.get(tag).deliver(); held.delete(tag); await page.unroute('**/internal/services/*/rotate');
            assert.equal(await page.locator('[data-service-credential]').count(), 0);
            for (const destination of ['/internal/commercial', '/internal/administration/commercial-markets']) {
                const before = await meta(page); await page.locator(`[data-internal-operations] > nav a[href="${destination}"]`).click(); await page.waitForSelector('[data-page]', { state: 'attached' });
                const legacy = await meta(page); assert.equal(legacy.binding.principal, before.binding.principal); assert.equal(legacy.binding.boundary, 'internal'); assert.notEqual(legacy.binding.generation, before.binding.generation);
                const inertia = await page.locator('[data-page]').evaluate(element => JSON.parse(element.tagName === 'SCRIPT' ? element.textContent : element.getAttribute('data-page'))); assert.equal(inertia.encryptHistory, true); assert.ok(['Internal/Commercial', 'Internal/CommercialMarketPreparation'].includes(inertia.component));
                await page.locator('.admin-nav-link[href$="/internal/administration"]').click(); await ready('[data-administration]'); assert.notEqual((await meta(page)).binding.generation, legacy.binding.generation); assert.equal(await page.locator('[data-service-credential]').count(), 0);
                await page.goBack({ waitUntil: 'commit' }); await page.waitForFunction(() => document.documentElement.dataset.visibility === 'ready');
                assert.equal((await meta(page)).binding.principal, before.binding.principal); assert.equal(await page.locator('[data-service-credential]').count(), 0);
                await page.goForward({ waitUntil: 'commit' }); await ready('[data-administration]');
            }
            await page.locator('[data-internal-operations] > nav a[href="/internal/services"]').click(); await ready('[data-services]'); assert.equal(await page.locator('[data-service-credential]').count(), 0);
            assert.equal(await page.locator('[data-service-ack]').count(), 0);
            await page.screenshot({ path: path.join(run, 'operations-services-no-secret.png'), fullPage: true });
            return { committedLateResponseNotPublished: true, commercialDestinations: 2, nativeDocuments: true, encryptedInertiaHistory: true, chromiumBackForwardOnly: true };
        });
        await verify('C post-load service permission revocation and actual CSRF rejection close private presentation without executing commands', 'operations', async () => {
            let before = command('operations-inspect').services.length; await page.locator('#service-name').fill('Forbidden service'); command('operations-revoke-service');
            let denied = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/internal/services'); await page.getByRole('button', { name: 'إنشاء الهوية', exact: true }).click(); assert.equal((await denied).status(), 403);
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await page.locator('[data-services]').count(), 0); assert.equal(command('operations-inspect').services.length, before);
            command('operations-restore-grants'); await reauthenticate(); await page.locator('[data-internal-operations] > nav a[href="/internal/services"]').click(); await ready('[data-services]'); await page.locator('#service-name').fill('Invalid CSRF service');
            await page.route('**/internal/services', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                const headers = Object.fromEntries(Object.entries(await route.request().allHeaders()).filter(([key]) => !['sec-fetch-site', 'x-csrf-token'].includes(key.toLowerCase())));
                const response = await route.fetch({ headers }); assert.equal(response.status(), 419); await route.fulfill({ response });
            });
            denied = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/internal/services'); await page.getByRole('button', { name: 'إنشاء الهوية', exact: true }).click(); assert.equal((await denied).status(), 419);
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await page.locator('[data-services]').count(), 0); assert.equal(command('operations-inspect').services.length, before);
            await page.unroute('**/internal/services'); await reauthenticate();
            return { permissionAtExecution: 403, csrfAtExecution: 419, unauthorizedWrites: 0 };
        });
        await verify('A revoked permission, stale fresh authentication and revoked session after load cannot execute displayed commands', 'operations', async () => {
            const before = command('operations-inspect').users;
            for (const mode of ['permission', 'freshness', 'session']) {
                await invite('Forbidden operation', `operations-denied-${mode}@example.test`);
                if (mode === 'permission') command('operations-revoke-invite'); else if (mode === 'freshness') command('account-age-auth'); else command('revoke');
                const denied = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/internal/staff'));
                await page.getByRole('button', { name: labels.sendInvite, exact: true }).click(); assert.ok([403, 409].includes((await denied).status()));
                await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
                assert.equal(await page.locator('[data-administration]').count(), 0); assert.equal(command('operations-inspect').users, before);
                command('operations-restore-grants'); await reauthenticate();
            }
            return { permissionAtExecution: true, freshAuthenticationAtExecution: true, revokedSessionAtExecution: true, noUnauthorizedWrites: true };
        });
        if (selected === 'commercial') {
            const { runCommercialCases } = await import('./commercial-spa-browser.mjs');
            await runCommercialCases({ page, ready, check, get, post, command, fixture, held, until });
        }
    } finally { await page.unrouteAll({ behavior: 'ignoreErrors' }); await context.close(); }
}
